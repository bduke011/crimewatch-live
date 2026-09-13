<?php
declare(strict_types=1);
require_once __DIR__.'/core.php';
umask(0077);
header('Content-Type: application/json');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
try{
 if(!in_array($_SERVER['REQUEST_METHOD'],['POST','GET'],true)){http_response_code(405);exit;}
 if((int)($_SERVER['CONTENT_LENGTH']??0)>20000){http_response_code(413);exit;}
 $db=ca_db();$now=time();$id=$_SERVER['HTTP_X_CRIMEWATCH_DEVICE']??'';$secret=($_SERVER['HTTP_X_CRIMEWATCH_SECRET']??'');$d=null;
 if($id){$q=$db->prepare('SELECT * FROM devices WHERE id=?');$q->execute([$id]);$d=$q->fetch();if(!$d||!hash_equals($d['secret'],hash('sha256',$secret))){http_response_code(401);echo json_encode(['error'=>'Invalid device']);exit;}}
 if($_SERVER['REQUEST_METHOD']==='GET'){
 if(!$d){http_response_code(401);exit;}$reports=[];
 if($d['enabled']){$archive=ca_archive();$q=$archive->prepare('SELECT id,agency,date,time,offense,category,location,lat,lng,details,changed_at AS changedAt FROM incidents WHERE id=?');foreach(array_keys(json_decode($d['watched'],true)) as $rid){$q->execute([$rid]);if($r=$q->fetch())$reports[]=$r;}}
 echo json_encode(['reports'=>$reports],JSON_THROW_ON_ERROR);exit;
 }
 $body=file_get_contents('php://input',false,null,0,20001);if(strlen($body)>20000)throw new InvalidArgumentException('Request too large.');$input=json_decode($body,true,32,JSON_THROW_ON_ERROR);
 if(!is_array($input))throw new InvalidArgumentException('Invalid request.');
 $db->beginTransaction();
 if(($input['action']??'')==='location'){
 if(!$d){$db->rollBack();http_response_code(401);exit;}
 $q=$db->prepare('SELECT * FROM devices WHERE id=?');$q->execute([$id]);$d=$q->fetch();$s=json_decode($d['settings'],true);
 if(!$d['enabled']||($s['locationMode']??'fixed')!=='current'){$db->rollBack();http_response_code(409);exit;}
 $incoming=ca_settings(array_merge($s,['center'=>$input['center']??null,'locationUpdatedAt'=>$input['locationUpdatedAt']??0]));
 if(ca_fresh($incoming,$now)&&$incoming['locationUpdatedAt']>=($s['locationUpdatedAt']??0)){
 $db->prepare('UPDATE devices SET settings=?,touched=? WHERE id=?')->execute([json_encode($incoming),$now,$id]);
 if($incoming['center']!==$s['center'])$db->prepare("DELETE FROM deliveries WHERE device=? AND kind IN('nearby','daily')")->execute([$id]);
 }$db->commit();echo '{"ok":true}';exit;
 }
 if(($input['action']??'')==='disable'){
 if(!$d){http_response_code(401);$db->rollBack();exit;}
 $q=$db->prepare("UPDATE devices SET enabled=0,settings='{}',watched='{}',touched=? WHERE id=?");$q->execute([$now,$id]);$db->prepare('DELETE FROM deliveries WHERE device=?')->execute([$id]);$db->commit();echo '{"ok":true}';exit;
 }
 if(($input['action']??'')!=='save')throw new InvalidArgumentException('Unknown action.');
 $s=ca_settings($input['settings']??[]);$token=$input['token']??'';if(!is_string($token)||!preg_match('/^[a-fA-F0-9]{32,512}$/',$token))throw new InvalidArgumentException('Invalid notification token.');$token=strtolower($token);
 if($s['enabled']&&!is_file(__DIR__.'/config.json')){$db->rollBack();http_response_code(503);echo '{"error":"Notification delivery is not configured yet"}';exit;}
 $ids=$input['watched']??[];if(!is_array($ids)||count($ids)>100)throw new InvalidArgumentException('Too many watched reports.');foreach($ids as $rid)if(!is_string($rid)||!preg_match('/^[a-zA-Z0-9_-]{1,100}$/',$rid))throw new InvalidArgumentException('Invalid watched report.');
 $identity=null;$seq=ca_seq($db);
 if(!$d){
 $ip=hash('sha256',($_SERVER['REMOTE_ADDR']??'unknown').gmdate('Y-m-d'));$q=$db->prepare('SELECT count FROM limits WHERE ip=?');$q->execute([$ip]);if((int)$q->fetchColumn()>=20){$db->rollBack();http_response_code(429);exit;}$db->prepare('INSERT INTO limits VALUES(?,?,1) ON CONFLICT(ip) DO UPDATE SET count=count+1')->execute([$ip,$now]);
 $id=bin2hex(random_bytes(16));$secret=bin2hex(random_bytes(32));$identity=['id'=>$id,'secret'=>$secret];$d=['watched'=>'{}','settings'=>'{}','enabled'=>0];
 // A token is bound to its original device credential. Never allow an unauthenticated caller to take it over.
 $q=$db->prepare('INSERT INTO devices(id,secret,token,settings,watched,enabled,created,touched,near_cursor,daily_cursor,update_cursor) VALUES(?,?,?,\'{}\',\'{}\',0,?,?,?,?,?)');$q->execute([$id,hash('sha256',$secret),$token,$now,$now,$seq,$seq,$seq]);
 }
 $watch=json_decode($d['watched'],true)?:[];$watched=[];if($s['updates'])foreach($ids as $rid)$watched[$rid]=$watch[$rid]??$seq;
 $old=json_decode($d['settings'],true);
 if(($old['locationMode']??'fixed')==='current'&&$s['locationMode']==='current'&&($old['locationUpdatedAt']??0)>$s['locationUpdatedAt']){$s['center']=$old['center'];$s['locationUpdatedAt']=$old['locationUpdatedAt'];}
 $oldCompare=$old;$newCompare=$s;if($s['locationMode']==='current'&&($old['locationMode']??'fixed')==='current'){unset($oldCompare['center'],$oldCompare['locationUpdatedAt'],$newCompare['center'],$newCompare['locationUpdatedAt']);}
 $changed=$oldCompare!==$newCompare||!$d['enabled'];
 if($s['locationMode']==='current'&&($old['center']??null)!==$s['center'])$db->prepare("DELETE FROM deliveries WHERE device=? AND kind IN('nearby','daily')")->execute([$id]);
 $q=$db->prepare('UPDATE devices SET token=?,settings=?,watched=?,enabled=?,touched=? WHERE id=?');$q->execute([$token,json_encode($s,JSON_THROW_ON_ERROR),json_encode((object)$watched),$s['enabled']?1:0,$now,$id]);
 if($changed){$local=(new DateTimeImmutable('@'.$now))->setTimezone(new DateTimeZone($s['timezone']));$day=(int)$local->format('G')>=$s['hour']?$local->format('Y-m-d'):'';$db->prepare('UPDATE devices SET near_cursor=?,daily_cursor=?,update_cursor=?,daily_day=? WHERE id=?')->execute([$seq,$seq,$seq,$day,$id]);$db->prepare('DELETE FROM deliveries WHERE device=?')->execute([$id]);}
 $db->commit();echo json_encode(['ok'=>true,'identity'=>$identity],JSON_THROW_ON_ERROR);
}catch(InvalidArgumentException|JsonException|TypeError $e){if(isset($db)&&$db->inTransaction())$db->rollBack();http_response_code(400);echo '{"error":"Invalid alert settings"}';}
catch(Throwable $e){if(isset($db)&&$db->inTransaction())$db->rollBack();error_log('CrimeWatch alerts API failed: '.get_class($e));http_response_code(503);echo '{"error":"Alerts temporarily unavailable"}';}
