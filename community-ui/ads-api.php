<?php
declare(strict_types=1);
require __DIR__.'/ads-lib.php';
header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
try{
 $method=$_SERVER['REQUEST_METHOD']??'GET';$action=$_GET['action']??'feed';if(!is_string($action))cw_json(['error'=>'Invalid request'],400);
 if($method==='GET'&&$action==='feed')cw_json(['ads'=>cw_public_ads(),'slots'=>cw_slots()]);
 if($method==='GET'&&$action==='inquiry-token'){cw_session();cw_json(['csrf'=>$_SESSION['csrf']]);}
 if($method==='GET'&&$action==='session'){
  cw_session();$a=cw_db()->query('SELECT username,version FROM admins WHERE id=1')->fetch();$signed=$a&&($_SESSION['admin']??null)===1&&($_SESSION['version']??null)===(int)$a['version']&&time()-($_SESSION['active']??0)<=3600;
  cw_json(['configured'=>(bool)$a,'signedIn'=>$signed,'username'=>$signed?$a['username']:null,'csrf'=>$_SESSION['csrf']]);
 }
 if($method==='GET'&&$action==='dashboard'){cw_admin();cw_json(['ads'=>cw_db()->query('SELECT * FROM ads ORDER BY id DESC')->fetchAll(),'inquiries'=>cw_db()->query('SELECT * FROM inquiries ORDER BY id DESC LIMIT 200')->fetchAll(),'slots'=>cw_slots()]);}
 if($method!=='POST')cw_json(['error'=>'Method not allowed'],405);
 cw_csrf();
 if($action==='upload'){
  cw_admin();$f=$_FILES['image']??null;if(!$f||$f['error']!==UPLOAD_ERR_OK||$f['size']>4000000)throw new InvalidArgumentException('Choose a JPG, PNG, or WebP image under 4 MB.');
  $mime=(new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);$ext=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$mime]??null;$size=@getimagesize($f['tmp_name']);
  if(!$ext||!$size||$size[0]>6000||$size[1]>6000)throw new InvalidArgumentException('Unsupported image. Maximum dimensions are 6000 × 6000.');
  $name=bin2hex(random_bytes(24)).'.'.$ext;$dir=cw_config()['dir'].'/images';if(!is_dir($dir))mkdir($dir,0700);if(!move_uploaded_file($f['tmp_name'],$dir.'/'.$name))throw new RuntimeException('Upload failed');chmod($dir.'/'.$name,0600);cw_audit('upload-image',$name);cw_json(['image'=>$name]);
 }
 $d=cw_input();
 if($action==='setup'){
  cw_limit('setup',8,900);$token=cw_text($d,'token',128,true);$db=cw_db();$db->exec('BEGIN IMMEDIATE');
  try{if($db->query('SELECT COUNT(*) FROM admins')->fetchColumn()||!hash_equals(cw_config()['setup_hash'],hash('sha256',$token))){$db->exec('ROLLBACK');cw_json(['error'=>'Setup link is invalid or has already been used.'],403);}
   $username=cw_text($d,'username',80,true);$password=cw_text($d,'password',200,true);if(strlen($password)<14)throw new InvalidArgumentException('Use a password or passphrase with at least 14 characters.');
   $db->prepare('INSERT INTO admins(id,username,password_hash) VALUES(1,?,?)')->execute([$username,password_hash($password,PASSWORD_DEFAULT)]);$db->exec('COMMIT');
  }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
  session_regenerate_id(true);$_SESSION['admin']=1;$_SESSION['version']=1;$_SESSION['active']=time();$_SESSION['csrf']=bin2hex(random_bytes(32));cw_audit('admin-setup');cw_json(['ok'=>true,'csrf'=>$_SESSION['csrf']]);
 }
 if($action==='login'){
  cw_limit('login',6,900);$user=cw_text($d,'username',80,true);$pass=cw_text($d,'password',200,true);$a=cw_db()->query('SELECT * FROM admins WHERE id=1')->fetch();
  if(!$a||!hash_equals($a['username'],$user)||!password_verify($pass,$a['password_hash']))cw_json(['error'=>'Username or password is incorrect.'],401);
  session_regenerate_id(true);$_SESSION['admin']=1;$_SESSION['version']=(int)$a['version'];$_SESSION['active']=time();$_SESSION['csrf']=bin2hex(random_bytes(32));cw_audit('login');cw_json(['ok'=>true,'csrf'=>$_SESSION['csrf']]);
 }
 if($action==='inquiry'){
  cw_limit('inquiry',5,3600);if(!empty($d['website']))cw_json(['ok'=>true]);
  $values=[];foreach(['business'=>100,'name'=>100,'email'=>160,'phone'=>40,'slot'=>20,'message'=>2000] as $k=>$n)$values[$k]=cw_text($d,$k,$n,in_array($k,['business','name','email','message'],true));
  if(!filter_var($values['email'],FILTER_VALIDATE_EMAIL)||!in_array($values['slot'],['top','arrests','footer','unsure'],true))throw new InvalidArgumentException('Check your email and placement.');
  cw_db()->prepare('INSERT INTO inquiries(business,name,email,phone,slot,message,created_at) VALUES(?,?,?,?,?,?,?)')->execute([...array_values($values),gmdate('c')]);cw_json(['ok'=>true]);
 }
 cw_admin();
 if($action==='logout'){$_SESSION=[];session_destroy();cw_json(['ok'=>true]);}
 if($action==='password'){
  cw_limit('password',5,900);$old=cw_text($d,'current',200,true);$pass=cw_text($d,'password',200,true);$hash=cw_db()->query('SELECT password_hash FROM admins WHERE id=1')->fetchColumn();if(!password_verify($old,$hash))cw_json(['error'=>'Current password is incorrect.'],403);if(strlen($pass)<14)throw new InvalidArgumentException('Use at least 14 characters.');cw_db()->prepare('UPDATE admins SET password_hash=?,version=version+1 WHERE id=1')->execute([password_hash($pass,PASSWORD_DEFAULT)]);$_SESSION['version']++;session_regenerate_id(true);cw_audit('password-change');cw_json(['ok'=>true]);
 }
 if($action==='save'){
  $r=cw_validate_ad($d);$id=filter_var($d['id']??0,FILTER_VALIDATE_INT);if($id===false||$id<0)throw new InvalidArgumentException('Invalid ad.');
  $image=cw_text($d,'image',60);if($image!==''&&(!preg_match('/^[a-f0-9]{48}\.(jpg|png|webp)$/',$image)||!is_file(cw_config()['dir'].'/images/'.$image)))throw new InvalidArgumentException('Upload the ad image again.');
  $r['image']=$image;$r['updated_at']=gmdate('c');$columns=array_keys($r);
  if($id){$exists=cw_db()->prepare('SELECT id FROM ads WHERE id=?');$exists->execute([$id]);if(!$exists->fetchColumn())cw_json(['error'=>'Ad not found'],404);$sql='UPDATE ads SET '.implode(',',array_map(fn($k)=>$k.'=?',$columns)).' WHERE id=?';$args=[...array_values($r),$id];}else{$sql='INSERT INTO ads('.implode(',',$columns).') VALUES('.implode(',',array_fill(0,count($columns),'?')).')';$args=array_values($r);}
  cw_db()->prepare($sql)->execute($args);$id=$id?:((int)cw_db()->lastInsertId());cw_audit('save-ad',(string)$id);cw_json(['ok'=>true,'id'=>$id]);
 }
 if($action==='status'){$id=filter_var($d['id']??0,FILTER_VALIDATE_INT);$status=$d['status']??'';if(!$id||!in_array($status,['active','paused','draft'],true))throw new InvalidArgumentException('Invalid ad status.');cw_db()->prepare('UPDATE ads SET status=?,updated_at=? WHERE id=?')->execute([$status,gmdate('c'),$id]);cw_audit('ad-status',"$id:$status");cw_json(['ok'=>true]);}
 if($action==='slots'){$slots=[];foreach(['top','arrests','footer'] as $key){if(!isset($d[$key])||!is_bool($d[$key]))throw new InvalidArgumentException('Invalid placement settings.');$slots[$key]=$d[$key];}cw_db()->prepare("UPDATE settings SET value=? WHERE key='slots'")->execute([json_encode($slots)]);cw_audit('slots');cw_json(['ok'=>true]);}
 if($action==='inquiry-status'){$id=filter_var($d['id']??0,FILTER_VALIDATE_INT);$status=$d['status']??'';if(!$id||!in_array($status,['new','contacted','closed'],true))throw new InvalidArgumentException('Invalid inquiry status.');cw_db()->prepare('UPDATE inquiries SET status=? WHERE id=?')->execute([$status,$id]);cw_audit('inquiry-status',"$id:$status");cw_json(['ok'=>true]);}
 cw_json(['error'=>'Unknown action'],404);
}catch(InvalidArgumentException $e){cw_json(['error'=>$e->getMessage()],400);}catch(Throwable $e){error_log('CW advertising: '.$e->getMessage());cw_json(['error'=>'Service temporarily unavailable. Please try again.'],503);}
