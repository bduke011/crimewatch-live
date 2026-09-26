<?php
declare(strict_types=1);
require __DIR__.'/member-lib.php';header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
try{
 $action=$_GET['action']??'session';$method=$_SERVER['REQUEST_METHOD']??'GET';if(!is_string($action))cw_json(['error'=>'Invalid request'],400);$db=cw_audience_db();
 if($method==='GET'&&$action==='session'){cw_session();cw_json(['reader'=>cw_reader(),'canViewReports'=>(bool)cw_reader()||cw_owner_signed(),'csrf'=>$_SESSION['csrf']]);}
 if($method!=='POST')cw_json(['error'=>'Method not allowed'],405);cw_csrf();$d=cw_input();
 if($action==='request-link'){
  cw_limit('reader-link',5,3600);if(!empty($d['website']))cw_json(['ok'=>true]);
  $email=strtolower(cw_text($d,'email',160,true));if(!filter_var($email,FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('Enter a valid email address.');if(($d['agree']??false)!==true)throw new InvalidArgumentException('Please acknowledge the account and privacy notice.');
  // Per-address throttling also limits requests arriving through different IPs.
  $scope='reader-email-'.hash_hmac('sha256',$email,cw_config()['secret']);cw_limit($scope,3,3600);
  $st=$db->prepare('SELECT COUNT(*) FROM reader_tokens WHERE email=? AND expires>?');$st->execute([$email,time()-2400]);if((int)$st->fetchColumn()>=3)cw_json(['ok'=>true]);
  $token=bin2hex(random_bytes(32));$hash=hash('sha256',$token);$db->prepare('INSERT INTO reader_tokens VALUES(?,?,?)')->execute([$hash,$email,time()+1200]);
  // Never overwrite an existing profile from an unsigned request.
  $db->prepare('INSERT OR IGNORE INTO readers(email,created_at) VALUES(?,?)')->execute([$email,gmdate('c')]);
  if(!cw_mail_link($email,$token)){$db->prepare('DELETE FROM reader_tokens WHERE hash=?')->execute([$hash]);cw_json(['error'=>'We could not send the email. Please try again later.'],503);}
  $db->prepare('DELETE FROM reader_tokens WHERE expires<?')->execute([time()-86400]);$db->prepare('DELETE FROM readers WHERE verified_at IS NULL AND created_at<?')->execute([gmdate('c',time()-7*86400)]);cw_json(['ok'=>true]);
 }
 if($action==='verify'){
  cw_limit('reader-verify',20,900);$token=cw_text($d,'token',128,true);if(!preg_match('/^[a-f0-9]{64}$/',$token))throw new InvalidArgumentException('This link is invalid. Request a new one.');
  $db->exec('BEGIN IMMEDIATE');$st=$db->prepare('SELECT email FROM reader_tokens WHERE hash=? AND expires>?');$st->execute([hash('sha256',$token),time()]);$email=$st->fetchColumn();
  if(!$email){$db->exec('ROLLBACK');cw_json(['error'=>'This link has expired or was already used. Request a new sign-in link.'],403);}
  $now=gmdate('c');$db->prepare('UPDATE readers SET verified_at=COALESCE(verified_at,?),login_at=? WHERE email=?')->execute([$now,$now,$email]);$db->prepare('DELETE FROM reader_tokens WHERE email=?')->execute([$email]);$st=$db->prepare('SELECT id,version FROM readers WHERE email=?');$st->execute([$email]);$r=$st->fetch();$db->exec('COMMIT');
  session_regenerate_id(true);$_SESSION['reader']=(int)$r['id'];$_SESSION['reader_version']=(int)$r['version'];$_SESSION['reader_active']=time();$_SESSION['csrf']=bin2hex(random_bytes(32));cw_json(['ok'=>true,'reader'=>cw_reader(),'csrf'=>$_SESSION['csrf']]);
 }
 $reader=cw_reader_required();
 if($action==='save'){$name=cw_text($d,'name',80);$area=cw_text($d,'area',30,true);if(!cw_area($area))throw new InvalidArgumentException('Choose a community.');$db->prepare('UPDATE readers SET name=?,area=? WHERE id=?')->execute([$name,$area,$reader['id']]);cw_json(['ok'=>true,'reader'=>cw_reader()]);}
 if($action==='logout'){if(isset($_COOKIE['CWREADER'])){$db->prepare('DELETE FROM member_tokens WHERE hash=?')->execute([hash('sha256',$_COOKIE['CWREADER'])]);cw_reader_cookie('',true);}unset($_SESSION['reader'],$_SESSION['reader_version'],$_SESSION['reader_active']);session_regenerate_id(true);cw_json(['ok'=>true]);}
 if($action==='delete'){if(($d['confirm']??'')!=='DELETE')throw new InvalidArgumentException('Type DELETE to confirm.');$db=cw_member_db();$db->beginTransaction();cw_forget_reader((int)$reader['id']);$db->prepare('DELETE FROM member_codes WHERE email=?')->execute([$reader['email']]);$db->prepare('DELETE FROM reader_tokens WHERE email=?')->execute([$reader['email']]);$db->prepare('DELETE FROM readers WHERE id=?')->execute([$reader['id']]);$db->commit();unset($_SESSION['reader'],$_SESSION['reader_version'],$_SESSION['reader_active']);session_regenerate_id(true);cw_json(['ok'=>true]);}
 cw_json(['error'=>'Unknown action'],404);
}catch(InvalidArgumentException $e){cw_json(['error'=>$e->getMessage()],400);}catch(Throwable $e){error_log('CW reader account: '.$e->getMessage());cw_json(['error'=>'Service temporarily unavailable. Please try again.'],503);}
