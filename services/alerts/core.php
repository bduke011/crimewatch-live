<?php
declare(strict_types=1);
function ca_db(): PDO {
 static $db;
 if($db)return $db;
 $dir=getenv('CRIMEWATCH_ALERT_DATA')?:__DIR__.'/data';
 if(!is_dir($dir))mkdir($dir,0700,true);
 $db=new PDO('sqlite:'.$dir.'/alerts.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
 $db->exec('PRAGMA busy_timeout=15000; PRAGMA journal_mode=WAL;');
 $db->exec('CREATE TABLE IF NOT EXISTS devices(id TEXT PRIMARY KEY,secret TEXT NOT NULL,token TEXT UNIQUE NOT NULL,settings TEXT NOT NULL,watched TEXT NOT NULL,enabled INTEGER NOT NULL,created INTEGER NOT NULL,touched INTEGER NOT NULL,near_cursor INTEGER NOT NULL,daily_cursor INTEGER NOT NULL,update_cursor INTEGER NOT NULL,near_sent INTEGER NOT NULL DEFAULT 0,update_sent INTEGER NOT NULL DEFAULT 0,daily_day TEXT NOT NULL DEFAULT "");
 CREATE TABLE IF NOT EXISTS known(id TEXT PRIMARY KEY,hash TEXT NOT NULL);
 CREATE TABLE IF NOT EXISTS events(seq INTEGER PRIMARY KEY AUTOINCREMENT,kind TEXT NOT NULL,incident TEXT NOT NULL,created INTEGER NOT NULL);
 CREATE INDEX IF NOT EXISTS events_created ON events(created);
 CREATE TABLE IF NOT EXISTS meta(name TEXT PRIMARY KEY,value TEXT);
 CREATE TABLE IF NOT EXISTS limits(ip TEXT PRIMARY KEY,window INTEGER NOT NULL,count INTEGER NOT NULL);
 CREATE TABLE IF NOT EXISTS deliveries(id TEXT PRIMARY KEY,device TEXT NOT NULL,kind TEXT NOT NULL,cursor INTEGER NOT NULL,day TEXT NOT NULL,payload TEXT NOT NULL,created INTEGER NOT NULL);');
 return $db;
}
function ca_archive(): PDO {return new PDO('sqlite:'.(getenv('CRIMEWATCH_ARCHIVE')?:dirname(__DIR__).'/public_html/data/crimewatch.sqlite'),null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);}
function ca_settings(array $s):array {
 foreach(['enabled','nearby','daily','updates'] as $k)if(!isset($s[$k])||!is_bool($s[$k]))throw new InvalidArgumentException('Invalid alert switches.');
 if(!is_string($s['agency']??null)||!preg_match('/^[a-z0-9-]{1,50}$/',$s['agency']))throw new InvalidArgumentException('Invalid area.');
 $c=$s['center']??null;if(!is_array($c)||count($c)!==2||!is_numeric($c[0])||!is_numeric($c[1])||$c[0]<25||$c[0]>37||$c[1]< -107||$c[1]> -93)throw new InvalidArgumentException('Invalid center.');
 if(!in_array($s['radius']??null,[1,3,5,10,25],true))throw new InvalidArgumentException('Invalid radius.');
 if(!is_array($s['categories']??null)||count($s['categories'])>4||array_diff($s['categories'],['person','property','drugs','other']))throw new InvalidArgumentException('Invalid categories.');
 if(!is_int($s['hour']??null)||$s['hour']<0||$s['hour']>23||!is_string($s['timezone']??null)||!in_array($s['timezone'],DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC),true))throw new InvalidArgumentException('Invalid summary time.');
 if($s['enabled']&&(!$s['nearby']&&!$s['daily']&&!$s['updates']||($s['nearby']||$s['daily'])&&!count($s['categories'])))throw new InvalidArgumentException('Choose alert types and categories.');
 return array_intersect_key($s,array_flip(['enabled','nearby','daily','updates','agency','center','radius','categories','hour','timezone']));
}
function ca_matches(array $r,array $s):bool {
 if($r['agency']!==$s['agency']||!in_array($r['category'],$s['categories'],true)||$r['lat']===null||$r['lng']===null)return false;
 $a=deg2rad((float)$r['lat']);$b=deg2rad((float)$s['center'][0]);$dl=deg2rad((float)$r['lng']-(float)$s['center'][1]);
 $h=sin(($a-$b)/2)**2+cos($a)*cos($b)*sin($dl/2)**2;
 return 3958.7613*2*asin(sqrt(min(1,$h)))<=(float)$s['radius'];
}
function ca_public(array $r):array {return array_intersect_key($r,array_flip(['id','agency','date','time','offense','category','location','lat','lng','details']));}
function ca_seq(PDO $db):int{return (int)$db->query('SELECT COALESCE(MAX(seq),0) FROM events')->fetchColumn();}
function ca_scan(PDO $db,PDO $archive,int $now):void {
 $first=!$db->query("SELECT value FROM meta WHERE name='baseline'")->fetchColumn();
 $find=$db->prepare('SELECT hash FROM known WHERE id=?');$save=$db->prepare('INSERT INTO known VALUES(?,?) ON CONFLICT(id) DO UPDATE SET hash=excluded.hash');$event=$db->prepare('INSERT INTO events(kind,incident,created) VALUES(?,?,?)');
 $db->beginTransaction();try{
 foreach($archive->query('SELECT id,agency,date,time,offense,category,location,lat,lng,details FROM incidents') as $r){$payload=json_encode(ca_public($r),JSON_THROW_ON_ERROR);$hash=hash('sha256',$payload);$find->execute([$r['id']]);$old=$find->fetchColumn();if($old===$hash)continue;
 if(!$first&&($old!==false||$r['date']>=gmdate('Y-m-d',$now-30*86400)))$event->execute([$old===false?'new':'update',$payload,$now]);$save->execute([$r['id'],$hash]);
 }
 $db->exec("INSERT OR REPLACE INTO meta VALUES('baseline','1')");$db->commit();}catch(Throwable $e){$db->rollBack();throw $e;}
}
function ca_plan(PDO $db,array $d,int $now):array {
 $s=json_decode($d['settings'],true);if(!$d['enabled']||!$s['enabled'])return[];
 $watch=json_decode($d['watched'],true);$near=[];$daily=[];$updates=[];$max=ca_seq($db);
 $q=$db->prepare('SELECT * FROM events WHERE seq>? AND created>=? ORDER BY seq');$q->execute([min($d['near_cursor'],$d['daily_cursor'],$d['update_cursor']),$d['created']]);
 foreach($q as $e){$r=json_decode($e['incident'],true);if($e['kind']==='new'&&ca_matches($r,$s)){if($e['seq']>$d['near_cursor'])$near[$r['id']]=true;if($e['seq']>$d['daily_cursor'])$daily[$r['id']]=true;}
 if($e['kind']==='update'&&$e['seq']>$d['update_cursor']&&isset($watch[$r['id']])&&$e['seq']>$watch[$r['id']])$updates[$r['id']]=true;
 }
 $plans=[];$local=(new DateTimeImmutable('@'.$now))->setTimezone(new DateTimeZone($s['timezone']));$day=$local->format('Y-m-d');
 if($s['nearby']&&count($near)&&$now-(int)$d['near_sent']>=3600)$plans[]=['kind'=>'nearby','count'=>count($near),'cursor'=>$max,'day'=>''];
 if($s['daily']&&(int)$local->format('G')>=$s['hour']&&$d['daily_day']!==$day)$plans[]=['kind'=>'daily','count'=>count($daily),'cursor'=>$max,'day'=>$day];
 if($s['updates']&&count($updates)&&$now-(int)$d['update_sent']>=3600)$plans[]=['kind'=>'updates','count'=>count($updates),'cursor'=>$max,'day'=>''];
 return $plans;
}
function ca_payload(array $plan):array {
 $n=$plan['count'];$body=match($plan['kind']){'nearby'=>"$n new ".($n===1?'incident':'incidents')." reported in your saved area.",'daily'=>"$n newly collected ".($n===1?'report matches':'reports match')." your area since your last summary.",'updates'=>"Published details changed for $n saved ".($n===1?'report.':'reports.')};
 return ['aps'=>['alert'=>['title'=>$plan['kind']==='daily'?'Your neighborhood summary':($plan['kind']==='updates'?'Saved report updates':'CrimeWatch nearby'),'body'=>$body],'sound'=>'default','thread-id'=>'crimewatch-'.$plan['kind']],'kind'=>$plan['kind']];
}
