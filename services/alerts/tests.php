<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit;
require __DIR__.'/core.php';
function check($yes,$message){if(!$yes)throw new RuntimeException($message);}
$temp=sys_get_temp_dir().'/crimewatch-alert-test-'.bin2hex(random_bytes(8));putenv('CRIMEWATCH_ALERT_DATA='.$temp);
$db=ca_db();$a=new PDO('sqlite::memory:');$a->exec('CREATE TABLE incidents(id TEXT,agency TEXT,date TEXT,time TEXT,offense TEXT,category TEXT,location TEXT,lat REAL,lng REAL,details TEXT)');
$now=1800000000;$date=gmdate('Y-m-d',$now);$insert=$a->prepare('INSERT INTO incidents VALUES(?,?,?,?,?,?,?,?,?,?)');$row=['old','polk',$date,'12:00','THEFT','property','Approximate street',30.79,-94.90,'{}'];$insert->execute($row);
ca_scan($db,$a,$now);check(ca_seq($db)===0,'First scan must baseline, not send old incidents');
$row[0]='new';$insert->execute($row);ca_scan($db,$a,$now+1);check(ca_seq($db)===1,'New report event');ca_scan($db,$a,$now+2);check(ca_seq($db)===1,'Repeated scan must not duplicate');
$row[0]='historical';$row[2]='2001-01-01';$insert->execute($row);ca_scan($db,$a,$now+3);check(ca_seq($db)===1,'Old historical imports must not alert');
$a->exec("UPDATE incidents SET offense='BURGLARY' WHERE id='old'");ca_scan($db,$a,$now+4);check(ca_seq($db)===2,'Changed published content event');
$s=['enabled'=>true,'nearby'=>true,'daily'=>true,'updates'=>true,'agency'=>'polk','center'=>[30.79,-94.90],'radius'=>5,'categories'=>['property'],'hour'=>0,'timezone'=>'America/Chicago'];check(ca_settings($s)===$s,'Valid settings');
$r=['agency'=>'polk','category'=>'property','lat'=>30.79,'lng'=>-94.90];check(ca_matches($r,$s),'Radius includes center');$r['lat']=null;check(!ca_matches($r,$s),'Unknown coordinates excluded');$r['lat']=32;check(!ca_matches($r,$s),'Outside radius excluded');$r['lat']=30.79;$r['category']='person';check(!ca_matches($r,$s),'Category filter');
$d=['enabled'=>1,'settings'=>json_encode($s),'watched'=>'{"old":0}','created'=>$now,'near_cursor'=>0,'daily_cursor'=>0,'update_cursor'=>0,'near_sent'=>0,'update_sent'=>0,'daily_day'=>''];$p=ca_plan($db,$d,$now+5);check(count($p)===3,'All three enabled alert types');check($p[0]['count']===1&&$p[1]['count']===1&&$p[2]['count']===1,'Counts match filters and watched ids');
$d['enabled']=0;check(ca_plan($db,$d,$now+5)===[],'Master off stops every type');$d['enabled']=1;$d['near_sent']=$now;$d['update_sent']=$now;$d['daily_day']=(new DateTimeImmutable('@'.($now+5)))->setTimezone(new DateTimeZone($s['timezone']))->format('Y-m-d');check(ca_plan($db,$d,$now+5)===[],'Rate limiting and one daily summary');
$d['near_sent']=0;$d['update_sent']=0;$d['near_cursor']=2;$d['daily_cursor']=2;$d['update_cursor']=2;check(ca_plan($db,$d,$now+5)===[],'Acknowledged events cannot re-alert');
$d['update_cursor']=0;$d['watched']='{}';check(ca_plan($db,$d,$now+5)===[],'Removed reports stop update alerts');
$d['watched']='{"old":2}';check(ca_plan($db,$d,$now+5)===[],'Newly watched report skips earlier changes');
try{$bad=$s;$bad['radius']=500;ca_settings($bad);throw new RuntimeException('Bad radius accepted');}catch(InvalidArgumentException $e){}
try{$bad=$s;$bad['timezone']='bad-zone';ca_settings($bad);throw new RuntimeException('Bad timezone accepted');}catch(InvalidArgumentException $e){}
foreach(['nearby','daily','updates'] as $kind){$payload=ca_payload(['kind'=>$kind,'count'=>3]);check(!str_contains(json_encode($payload),'Approximate street'),'Lock screen must not include incident details');}
$db=null;foreach(glob($temp.'/*') as $f)unlink($f);rmdir($temp);echo "PASS: baseline, deduplication, historical suppression, radius, category, updates, off switch, frequency, daily timing, cursors, unwatch, validation, lock-screen privacy.\n";
