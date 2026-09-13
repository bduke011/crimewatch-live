<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/core.php';
function ca_jwt(array $config):string {
 $encode=fn($v)=>rtrim(strtr(base64_encode($v),'+/','-_'),'=');
 $data=$encode(json_encode(['alg'=>'ES256','kid'=>$config['keyId']])).'.'.$encode(json_encode(['iss'=>$config['teamId'],'iat'=>time()]));
 if(!openssl_sign($data,$sig,file_get_contents($config['keyPath']),OPENSSL_ALGO_SHA256))throw new RuntimeException('APNs signing failed');
 // OpenSSL returns DER; APNs ES256 requires a fixed-width JOSE signature.
 $offset=2;if(ord($sig[1])&128)$offset=2+(ord($sig[1])&127);$offset++;$len=ord($sig[$offset++]);$r=substr($sig,$offset,$len);$offset+=$len+1;$len=ord($sig[$offset++]);$s=substr($sig,$offset,$len);$raw=str_pad(ltrim($r,"\0"),32,"\0",STR_PAD_LEFT).str_pad(ltrim($s,"\0"),32,"\0",STR_PAD_LEFT);
 if(strlen($raw)!==64)throw new RuntimeException('Invalid APNs signature');return $data.'.'.$encode($raw);
}
function ca_send(array $config,string $token,array $delivery):array {
 $ch=curl_init('https://api.push.apple.com/3/device/'.$token);
 curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$delivery['payload'],CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTP_VERSION=>CURL_HTTP_VERSION_2_0,CURLOPT_CONNECTTIMEOUT=>4,CURLOPT_TIMEOUT=>8,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_HTTPHEADER=>['authorization: bearer '.ca_jwt($config),'apns-topic: live.crimewatch.app','apns-push-type: alert','apns-priority: 10','apns-expiration: '.(time()+3600),'apns-collapse-id: '.$delivery['id'],'content-type: application/json']]);
 $body=curl_exec($ch);$status=curl_getinfo($ch,CURLINFO_HTTP_CODE);$reason=json_decode($body?:'{}',true)['reason']??'';return [$status,$reason];
}
if(realpath($_SERVER['SCRIPT_FILENAME']??'')!==__FILE__)return;
umask(0077);$lock=fopen(__DIR__.'/worker.lock','c');if(!flock($lock,LOCK_EX|LOCK_NB))exit;
try{
 $db=ca_db();$now=time();ca_scan($db,ca_archive(),$now);
 if(!is_file(__DIR__.'/config.json')){echo "Archive baseline checked. APNs configuration pending.\n";exit;}$config=json_decode(file_get_contents(__DIR__.'/config.json'),true,32,JSON_THROW_ON_ERROR);
 $sent=0;$failed=0;
 foreach($db->query('SELECT * FROM devices WHERE enabled=1')->fetchAll() as $d){
 // Hold a short transaction around each send so disabling waits for any in-flight send and cancels everything queued.
 $db->beginTransaction();try{
 $q=$db->prepare('SELECT * FROM devices WHERE id=?');$q->execute([$d['id']]);$d=$q->fetch();if(!$d||!$d['enabled']){$db->commit();continue;}
 $pending=$db->prepare('SELECT * FROM deliveries WHERE device=? ORDER BY created LIMIT 1');$pending->execute([$d['id']]);$delivery=$pending->fetch();
 if(!$delivery){$plans=ca_plan($db,$d,$now);if(!$plans){$db->commit();continue;}$plan=$plans[0];$delivery=['id'=>bin2hex(random_bytes(16)),'device'=>$d['id'],'kind'=>$plan['kind'],'cursor'=>$plan['cursor'],'day'=>$plan['day'],'payload'=>json_encode(ca_payload($plan),JSON_THROW_ON_ERROR),'created'=>$now];$db->prepare('INSERT INTO deliveries VALUES(:id,:device,:kind,:cursor,:day,:payload,:created)')->execute($delivery);}
 $db->commit();
 // Persist retry identifier before sending. Recheck settings under the same database lock as the send.
 $db->beginTransaction();$pending->execute([$d['id']]);$delivery=$pending->fetch();$q->execute([$d['id']]);$fresh=$q->fetch();if(!$delivery||!$fresh||!$fresh['enabled']){$db->commit();continue;}
 [$status,$reason]=ca_send($config,$fresh['token'],$delivery);
 if($status===200){$kind=$delivery['kind'];$sql=match($kind){'nearby'=>'near_cursor=?,near_sent=?','daily'=>'daily_cursor=?,daily_day=?','updates'=>'update_cursor=?,update_sent=?'};$db->prepare("UPDATE devices SET $sql WHERE id=?")->execute([$delivery['cursor'],$kind==='daily'?$delivery['day']:$now,$d['id']]);$db->prepare('DELETE FROM deliveries WHERE id=?')->execute([$delivery['id']]);$sent++;}
 elseif($status===410||($status===400&&in_array($reason,['BadDeviceToken','DeviceTokenNotForTopic'],true))){$db->prepare("UPDATE devices SET enabled=0,settings='{}',watched='{}' WHERE id=?")->execute([$d['id']]);$db->prepare('DELETE FROM deliveries WHERE device=?')->execute([$d['id']]);}
 else{$failed++;error_log('CrimeWatch APNs response '.$status.' '.$reason);}
 $db->commit();
 }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
 }
 $db->prepare('DELETE FROM events WHERE created<?')->execute([$now-32*86400]);$db->prepare('DELETE FROM limits WHERE window<?')->execute([$now-86400]);
 // Retain disabled credentials for 30 days to support retries; erase abandoned subscriptions after 180 days.
 $db->prepare('DELETE FROM devices WHERE (enabled=0 AND touched<?) OR touched<?')->execute([$now-30*86400,$now-180*86400]);$db->exec('DELETE FROM deliveries WHERE device NOT IN(SELECT id FROM devices)');
 echo gmdate('c')." sent=$sent failures=$failed\n";
}catch(Throwable $e){error_log('CrimeWatch alert worker failed: '.get_class($e).' '.$e->getMessage());exit(1);}
