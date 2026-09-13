<?php
declare(strict_types=1);
require_once __DIR__.'/regions.php';

function nw_data_directory(): string {
    return getenv('CRIMEWATCH_DATA_DIR') ?: __DIR__.'/data';
}
function nw_database(): PDO {
    static $db=null;
    if ($db instanceof PDO) return $db;
    $directory=nw_data_directory();
    if (!is_dir($directory) && !mkdir($directory,0750,true)) throw new RuntimeException('Archive directory unavailable.');
    $db=new PDO('sqlite:'.$directory.'/crimewatch.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $db->exec('PRAGMA busy_timeout=5000');
    $db->exec('PRAGMA journal_mode=WAL');
    $version=(int)$db->query('PRAGMA user_version')->fetchColumn();
    if ($version===0) {
        $db->beginTransaction();
        try {
            $db->exec('CREATE TABLE IF NOT EXISTS incidents (id TEXT PRIMARY KEY, date TEXT NOT NULL, time TEXT NOT NULL, offense TEXT NOT NULL, offense_code TEXT NOT NULL, category TEXT NOT NULL, location TEXT NOT NULL, lat REAL, lng REAL, first_seen TEXT NOT NULL, last_seen TEXT NOT NULL, changed_at TEXT NOT NULL, content_hash TEXT NOT NULL, source_state TEXT NOT NULL)');
            $db->exec('CREATE INDEX IF NOT EXISTS idx_incidents_date_time ON incidents(date DESC,time DESC,id)');
            $db->exec('CREATE INDEX IF NOT EXISTS idx_incidents_category_date ON incidents(category,date DESC,time DESC)');
            $db->exec('CREATE TABLE IF NOT EXISTS collection_state (name TEXT PRIMARY KEY, value TEXT NOT NULL)');
            $db->exec('PRAGMA user_version=1');
            $db->commit();$db->exec('PRAGMA optimize');
        } catch(Throwable $e) {$db->rollBack();throw $e;}
    } elseif($version>3) throw new RuntimeException('Unsupported archive schema.');
    if($version<2) {
        $db->beginTransaction();
        try {
            $db->exec('CREATE TABLE IF NOT EXISTS incident_versions (incident_id TEXT NOT NULL,content_hash TEXT NOT NULL,observed_at TEXT NOT NULL,payload TEXT NOT NULL,PRIMARY KEY(incident_id,content_hash))');
            $db->exec('CREATE TABLE IF NOT EXISTS collections (range_start TEXT NOT NULL,range_end TEXT NOT NULL,fetched_at TEXT NOT NULL,kind TEXT NOT NULL,PRIMARY KEY(range_start,range_end,fetched_at))');
            $db->exec('CREATE INDEX IF NOT EXISTS idx_collections_range ON collections(range_start,range_end,fetched_at)');
            $db->exec('PRAGMA user_version=2');$db->commit();
        }catch(Throwable $e){$db->rollBack();throw $e;}
    }
    if($version<3) {
        $db->beginTransaction();
        try {
            $db->exec("ALTER TABLE incidents ADD COLUMN agency TEXT NOT NULL DEFAULT 'polk'");
            $db->exec("ALTER TABLE incidents ADD COLUMN details TEXT NOT NULL DEFAULT '{}'");
            $db->exec('CREATE INDEX idx_incidents_agency_date ON incidents(agency,date DESC,time DESC,id)');
            $db->exec('CREATE TABLE regional_state(agency TEXT PRIMARY KEY, checked_at TEXT, success_at TEXT, status TEXT, count INTEGER, latest TEXT, fingerprint TEXT)');
            $db->exec('CREATE TABLE regional_imports(agency TEXT,period TEXT,fingerprint TEXT,checked_at TEXT,PRIMARY KEY(agency,period))');
            $db->exec('PRAGMA user_version=3');$db->commit();
        }catch(Throwable $e){$db->rollBack();throw $e;}
    }
    return $db;
}
function nw_archive_snapshot(array $snapshot, bool $historical=false): void {
    $db=nw_database();
    $at=$snapshot['fetchedAt'];
    $last=$db->query("SELECT value FROM collection_state WHERE name='fetchedAt'")->fetchColumn();
    if (!$historical && $last && strcmp($last,$at)>=0) return;
    $db->beginTransaction();
    try {
        // Older records stay searchable. Disappearance inside the window is distinguished from age-out.
        if(!$historical) {
            $aged=$db->prepare("UPDATE incidents SET source_state='aged_out' WHERE agency='polk' AND date < ? AND source_state='listed'");
            $aged->execute([$snapshot['range']['start']]);
        }
        $missing=$db->prepare("UPDATE incidents SET source_state='not_listed' WHERE agency='polk' AND date BETWEEN ? AND ?");
        $missing->execute([$snapshot['range']['start'],$snapshot['range']['end']]);
        $insert=$db->prepare("INSERT INTO incidents (id,date,time,offense,offense_code,category,location,lat,lng,first_seen,last_seen,changed_at,content_hash,source_state) VALUES (:id,:date,:time,:offense,:offense_code,:category,:location,:lat,:lng,:first_seen,:last_seen,:changed_at,:content_hash,'listed') ON CONFLICT(id) DO UPDATE SET date=excluded.date,time=excluded.time,offense=excluded.offense,offense_code=excluded.offense_code,category=excluded.category,location=excluded.location,lat=excluded.lat,lng=excluded.lng,last_seen=excluded.last_seen,changed_at=CASE WHEN incidents.content_hash<>excluded.content_hash THEN excluded.changed_at ELSE incidents.changed_at END,content_hash=excluded.content_hash,source_state='listed'");
        $versionInsert=$db->prepare('INSERT OR IGNORE INTO incident_versions(incident_id,content_hash,observed_at,payload) VALUES (?,?,?,?)');
        foreach($snapshot['incidents'] as $row) {
            $payload=json_encode($row,JSON_THROW_ON_ERROR);$hash=hash('sha256',$payload);
            $versionInsert->execute([$row['id'],$hash,$at,$payload]);
            $insert->execute(['id'=>$row['id'],'date'=>$row['date'],'time'=>$row['time'],'offense'=>$row['offense'],'offense_code'=>$row['offenseCode'] ?? '','category'=>$row['category'],'location'=>$row['location'],'lat'=>$row['lat'],'lng'=>$row['lng'],'first_seen'=>$at,'last_seen'=>$at,'changed_at'=>$at,'content_hash'=>hash('sha256',json_encode($row,JSON_THROW_ON_ERROR))]);
        }
        if($historical) {
            $rangeStart=$db->query("SELECT value FROM collection_state WHERE name='rangeStart'")->fetchColumn();
            if($rangeStart){$aged=$db->prepare("UPDATE incidents SET source_state='aged_out' WHERE agency='polk' AND date < ? AND source_state='listed'");$aged->execute([$rangeStart]);}
        }
        $meta=$db->prepare('INSERT INTO collection_state(name,value) VALUES (?,?) ON CONFLICT(name) DO UPDATE SET value=excluded.value');
        if(!$historical){$meta->execute(['fetchedAt',$at]);$meta->execute(['rangeStart',$snapshot['range']['start']]);$meta->execute(['rangeEnd',$snapshot['range']['end']]);}
        $collection=$db->prepare('INSERT OR IGNORE INTO collections(range_start,range_end,fetched_at,kind) VALUES (?,?,?,?)');$collection->execute([$snapshot['range']['start'],$snapshot['range']['end'],$at,$historical?'historical':'current']);
        $db->commit();
    } catch(Throwable $e) {$db->rollBack();throw $e;}
}
function nw_query_archive(array $input, array $snapshot): array {
    $period=$input['period'] ?? '30';$category=$input['category'] ?? 'all';$query=$input['q'] ?? '';$offset=$input['offset'] ?? '0';
    foreach([$period,$category,$query,$offset,$input['from'] ?? '',$input['to'] ?? ''] as $value) if(!is_string($value)) throw new InvalidArgumentException('Invalid query.');
    if(!in_array($period,['7','14','30','archive'],true)||!in_array($category,['all','property','person','drugs','other'],true)||strlen($query)>200||!preg_match('/^\d{1,7}$/',$offset)) throw new InvalidArgumentException('Invalid filters.');
    $agency=$input['agency'] ?? 'polk';
    if(!is_string($agency)||($agency!=='all'&&!isset(cw_regions()[$agency])))throw new InvalidArgumentException('Invalid area.');
    $where=[];$params=[];
    if($agency!=='all'){$where[]='agency=?';$params[]=$agency;}
    if($agency!=='polk')$snapshot=cw_snapshot($agency);
    if($period!=='archive') {
        $end=(new DateTimeImmutable('today',new DateTimeZone('America/Chicago')))->format('Y-m-d');$begin=(new DateTimeImmutable($end))->modify('-'.(int)$period.' days')->format('Y-m-d');
        $where[]="date BETWEEN ? AND ? AND source_state<>'not_listed'";$params[]=$begin;$params[]=$end;
    } else {
        $from=$input['from'] ?? '';$to=$input['to'] ?? '';
        foreach([$from,$to] as $date) if($date!==''&&(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)||!($d=DateTimeImmutable::createFromFormat('!Y-m-d',$date))||$d->format('Y-m-d')!==$date)) throw new InvalidArgumentException('Invalid date.');
        if($from!==''&&$to!==''&&$from>$to) throw new InvalidArgumentException('Start date must be before end date.');
        if($from!=='') {$where[]='date>=?';$params[]=$from;}if($to!=='') {$where[]='date<=?';$params[]=$to;}
    }
    if($category!=='all') {$where[]='category=?';$params[]=$category;}
    $query=trim($query);
    if($query!=='') {
        $escaped=str_replace(['\\','%','_'],['\\\\','\\%','\\_'],$query);
        $where[]="(offense LIKE ? ESCAPE '\' OR location LIKE ? ESCAPE '\')";$params[]='%'.$escaped.'%';$params[]='%'.$escaped.'%';
    }
    if(isset($input['mapLat'])||isset($input['mapLng'])||isset($input['mapPrecision'])){
        $lat=$input['mapLat']??null;$lng=$input['mapLng']??null;$precision=$input['mapPrecision']??null;
        if(!is_string($lat)||!is_string($lng)||!is_string($precision)||!in_array($precision,['2','3'],true)||!is_numeric($lat)||!is_numeric($lng)||(float)$lat<25||(float)$lat>37||(float)$lng< -107||(float)$lng> -93)throw new InvalidArgumentException('Invalid map selection.');
        $where[]='ROUND(lat,'.(int)$precision.')=CAST(? AS REAL) AND ROUND(lng,'.(int)$precision.')=CAST(? AS REAL)';$params[]=$lat;$params[]=$lng;
    }
    $clause=$where ? ' WHERE '.implode(' AND ',$where) : '';
    $db=nw_database();
    $summary=$db->prepare("SELECT COUNT(*) AS total,COALESCE(SUM(category='property'),0) AS property,COALESCE(SUM(category='person'),0) AS person,MAX(date) AS latest FROM incidents".$clause);
    $summary->execute($params);$stats=$summary->fetch();
    $limit=200;$page=(int)$offset;
    $records=$db->prepare('SELECT agency,details,id,date,time,offense,offense_code AS offenseCode,category,location,lat,lng,first_seen AS firstSeen,last_seen AS lastSeen,changed_at AS changedAt,source_state AS sourceState FROM incidents'.$clause.' ORDER BY date DESC,time DESC,id LIMIT '.$limit.' OFFSET '.$page);
    $records->execute($params);$rows=$records->fetchAll();
    foreach($rows as &$r) {$r['details']=json_decode($r['details'],true);$r['agencyLabel']=cw_regions()[$r['agency']]['label'] ?? $r['agency'];$r['lat']=$r['lat']===null?null:(float)$r['lat'];$r['lng']=$r['lng']===null?null:(float)$r['lng'];}unset($r);
    // Map coverage uses the entire filtered result, independent of list pagination.
    $precision=(int)$stats['total']>200?2:3;
    $mapWhere=$clause.($clause?' AND ':' WHERE ').'lat IS NOT NULL AND lng IS NOT NULL';
    $mapQuery=$db->prepare("SELECT ROUND(lat,".$precision.") AS lat,ROUND(lng,".$precision.") AS lng,COUNT(*) AS count,SUM(category='property') AS property,SUM(category='person') AS person,SUM(category='drugs') AS drugs,SUM(category='other') AS other,MIN(id) AS id FROM incidents".$mapWhere.' GROUP BY ROUND(lat,'.$precision.'),ROUND(lng,'.$precision.')');
    $mapQuery->execute($params);$points=$mapQuery->fetchAll();$mapped=0;
    foreach($points as &$point){$point['lat']=(float)$point['lat'];$point['lng']=(float)$point['lng'];foreach(['count','property','person','drugs','other'] as $key)$point[$key]=(int)$point[$key];$mapped+=$point['count'];}unset($point);
    $archive=$db->query('SELECT COUNT(*) AS total,MIN(date) AS earliestReport,MIN(first_seen) AS startedAt FROM incidents')->fetch();
    return ['map'=>['precision'=>$precision,'points'=>$points,'mapped'=>$mapped,'unmapped'=>(int)$stats['total']-$mapped],'incidents'=>$rows,'fetchedAt'=>$snapshot['fetchedAt'],'range'=>$snapshot['range'],'stale'=>$snapshot['stale'],'summary'=>['total'=>(int)$stats['total'],'property'=>(int)$stats['property'],'person'=>(int)$stats['person'],'latest'=>$stats['latest']],'page'=>['offset'=>$page,'limit'=>$limit,'hasMore'=>$page+count($rows)<(int)$stats['total']],'archive'=>$archive,'regions'=>cw_public_regions(),'coverage'=>cw_regions()[$agency]['coverage'] ?? 'Participating areas only. Coverage and publication timing vary.'];
}
function nw_backup_daily(): string {
    $db=nw_database();$directory=nw_data_directory().'/backups';
    if(!is_dir($directory)&&!mkdir($directory,0750,true)) throw new RuntimeException('Backup directory unavailable.');
    $date=(new DateTimeImmutable('now',new DateTimeZone('America/Chicago')))->format('Y-m-d');
    $path=$directory.'/archive-'.$date.'.sqlite';
    if(!is_file($path)) {
        $lock=fopen($directory.'/backup.lock','c');
        if(!$lock)throw new RuntimeException('Backup lock unavailable.');
        if(flock($lock,LOCK_EX|LOCK_NB)) {
            try {if(!is_file($path)) {$temporary=$path.'.tmp';if(is_file($temporary))unlink($temporary);$db->exec('VACUUM INTO '.$db->quote($temporary));if(!rename($temporary,$path))throw new RuntimeException('Backup could not be finalized.');}}
            finally {flock($lock,LOCK_UN);fclose($lock);}
        } else fclose($lock);
    }
    // Rotate only redundant daily backup copies. The primary archive and all collected versions have no age-based deletion.
    $backups=glob($directory.'/archive-????-??-??.sqlite');rsort($backups,SORT_STRING);
    foreach(array_slice($backups,2) as $oldBackup)if(is_file($oldBackup))unlink($oldBackup);
    return $path;
}
