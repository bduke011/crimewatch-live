<?php
if(PHP_SAPI!=='cli')exit;
set_time_limit(0);set_error_handler(function($n,$s){throw new ErrorException($s,0,$n);});ini_set('memory_limit','256M');
$root='/home1/crimewatch/fbi-raw';$out='/home1/crimewatch/public_html/data/fbi';if(!is_dir($out))mkdir($out,0750,true);
$lock=fopen($out.'/build.lock','c');if(!flock($lock,LOCK_EX|LOCK_NB))exit;
$directory=[];foreach(json_decode(file_get_contents('/home1/crimewatch/fbi-directory.json'),true) as $group)foreach($group as $a)$directory[$a['ori']]=$a;
function rows($z,$names,$name){$f=$z->getStream($names[$name]);if(!$f)throw new RuntimeException('Missing '.$name);$head=fgetcsv($f,0,',','"','');$head[0]=ltrim($head[0],"\xef\xbb\xbf");$head=array_map('strtolower',$head);try{while(($r=fgetcsv($f,0,',','"',''))!==false){if($r===[null])continue;if(count($r)!==count($head))throw new RuntimeException('Column mismatch '.$name);yield array_combine($head,$r);}}finally{fclose($f);}}
function fbi_date($s){$s=substr($s,0,10);if(preg_match('/^\d{4}-\d{2}-\d{2}$/',$s))return $s;$d=DateTimeImmutable::createFromFormat('!d-M-y',$s);if(!$d)throw new RuntimeException('Invalid incident date');return $d->format('Y-m-d');}
foreach(range(2025,1997) as $year){if(is_file($out.'/'.$year.'.json'))continue;
 try{
 $z=new ZipArchive();if($z->open($root.'/TX-'.$year.'.zip')!==true)throw new RuntimeException('ZIP not ready');$names=[];for($i=0;$i<$z->numFiles;$i++)$names[strtolower(basename($z->getNameIndex($i)))]=$z->getNameIndex($i);
 $path=$out.'/'.$year.'.sqlite.new';if(is_file($path))unlink($path);$db=new PDO('sqlite:'.$path);$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
 $db->exec('PRAGMA journal_mode=OFF;PRAGMA synchronous=OFF;CREATE TABLE agencies(id INTEGER PRIMARY KEY,ori TEXT,name TEXT,county TEXT);CREATE TABLE incidents(id INTEGER PRIMARY KEY,agency INTEGER,date TEXT,hour TEXT,report INTEGER);CREATE TABLE offenses(incident INTEGER,code TEXT,location TEXT,attempt TEXT);CREATE TABLE offense_types(code TEXT PRIMARY KEY,name TEXT);CREATE TABLE locations(code TEXT PRIMARY KEY,name TEXT);');$db->beginTransaction();
 $q=$db->prepare('INSERT OR REPLACE INTO agencies VALUES(?,?,?,?)');foreach(rows($z,$names,isset($names['agencies.csv'])?'agencies.csv':'cde_agencies.csv') as $a){$d=$directory[$a['ori']]??[];$q->execute([$a['agency_id'],$a['ori'],$d['agency_name']??($a['agency_name']??$a['pub_agency_name']??$a['ucr_agency_name']),$a['county_name']??$d['counties']??'']);}
 $types=[];$q=$db->prepare('INSERT OR REPLACE INTO offense_types VALUES(?,?)');foreach(rows($z,$names,'nibrs_offense_type.csv') as $r){$types[$r['offense_type_id']??$r['offense_code']]=$r['offense_code'];$q->execute([$r['offense_code'],$r['offense_name']]);}
 $locations=[];$q=$db->prepare('INSERT OR REPLACE INTO locations VALUES(?,?)');foreach(rows($z,$names,'nibrs_location_type.csv') as $r){$locations[$r['location_id']]=$r['location_code'];$q->execute([$r['location_code'],$r['location_name']]);}
 $q=$db->prepare('INSERT INTO incidents VALUES(?,?,?,?,?)');foreach(rows($z,$names,'nibrs_incident.csv') as $r)$q->execute([$r['incident_id'],$r['agency_id'],fbi_date($r['incident_date']),$r['incident_hour']??'',(int)in_array(strtolower($r['report_date_flag']??''),['t','true','y','1'])]);
 $q=$db->prepare('INSERT INTO offenses VALUES(?,?,?,?)');foreach(rows($z,$names,'nibrs_offense.csv') as $r)$q->execute([$r['incident_id'],$r['offense_code']??$types[$r['offense_type_id']],$locations[$r['location_id']]??'00',$r['attempt_complete_flag']??'']);$db->commit();
 $db->exec('CREATE INDEX incident_agency_date ON incidents(agency,date DESC,id DESC);CREATE INDEX incident_date ON incidents(date DESC,id DESC);CREATE INDEX offense_incident ON offenses(incident);CREATE INDEX offense_code ON offenses(code,incident);');
 $db->exec("INSERT INTO agencies SELECT DISTINCT i.agency,'Not supplied','FBI agency ' || i.agency || ' (name unavailable)','' FROM incidents i LEFT JOIN agencies a ON i.agency=a.id WHERE a.id IS NULL");
 foreach(['SELECT COUNT(*) FROM offenses o LEFT JOIN incidents i ON o.incident=i.id WHERE i.id IS NULL'] as $check)if($db->query($check)->fetchColumn()!=0)throw new RuntimeException('Unlinked records');
 if($db->query('PRAGMA quick_check')->fetchColumn()!=='ok')throw new RuntimeException('Database integrity failed');
 $agencies=$db->query('SELECT a.*,COUNT(i.id) incidents,MAX(i.date) latest FROM agencies a JOIN incidents i ON i.agency=a.id GROUP BY a.id ORDER BY a.name')->fetchAll(PDO::FETCH_ASSOC);
 $meta=['year'=>$year,'incidents'=>(int)$db->query('SELECT COUNT(*) FROM incidents')->fetchColumn(),'offenses'=>(int)$db->query('SELECT COUNT(*) FROM offenses')->fetchColumn(),'latest'=>$db->query('SELECT MAX(date) FROM incidents')->fetchColumn(),'agencies'=>$agencies,'collectedAt'=>gmdate('c'),'rawBytes'=>filesize($root.'/TX-'.$year.'.zip'),'sha256'=>trim(file_get_contents($root.'/TX-'.$year.'.zip.sha256')),'format'=>'Texas NIBRS annual CSV archive','rawTables'=>$z->numFiles,'databaseBytes'=>filesize($path)];
 $db=null;$z->close();rename($path,$out.'/'.$year.'.sqlite');file_put_contents($out.'/'.$year.'.json',json_encode($meta,JSON_INVALID_UTF8_SUBSTITUTE));echo $year.' '.$meta['incidents']." saved\n";
 }catch(Throwable $e){echo $year.' FAILED '.$e->getMessage()."\n";}
}
$years=[];foreach(range(2026,1997) as $y)if(is_file($out.'/'.$y.'.json')){$m=json_decode(file_get_contents($out.'/'.$y.'.json'),true);unset($m['agencies']);$years[]=$m;}file_put_contents($out.'/catalog.json.new',json_encode(['years'=>$years]));rename($out.'/catalog.json.new',$out.'/catalog.json');
