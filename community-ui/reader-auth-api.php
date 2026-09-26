<?php
declare(strict_types=1);
require __DIR__.'/member-lib.php';
header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
function ra_db(): PDO {
    $db=cw_member_db();
    $db->exec('CREATE TABLE IF NOT EXISTS reader_passwords(reader_id INTEGER PRIMARY KEY,hash TEXT NOT NULL);CREATE TABLE IF NOT EXISTS reader_password_codes(challenge TEXT PRIMARY KEY,email TEXT NOT NULL,hash TEXT NOT NULL,password_hash TEXT NOT NULL,expires INTEGER NOT NULL,attempts INTEGER NOT NULL DEFAULT 0)');
    return $db;
}
function ra_password(array $d): string {
    $p=$d['password']??null;
    if(!is_string($p)||strlen($p)<12||strlen($p)>128)throw new InvalidArgumentException('Use a password of 12 to 128 characters.');
    return $p;
}
function ra_issue(PDO $db,int $id,bool $native): array {
    $token=bin2hex(random_bytes(32));
    $db->prepare('INSERT INTO member_tokens VALUES(?,?,?,?)')->execute([hash('sha256',$token),$id,time()+180*86400,time()]);
    $db->prepare('UPDATE readers SET login_at=? WHERE id=?')->execute([gmdate('c'),$id]);
    $st=$db->prepare('SELECT id,email,name,area FROM readers WHERE id=?');$st->execute([$id]);
    $result=['ok'=>true,'reader'=>$st->fetch(),'hasPassword'=>true];
    if($native)$result['token']=$token;
    else{cw_reader_cookie($token);$_COOKIE['CWREADER']=$token;session_regenerate_id(true);unset($_SESSION['reader'],$_SESSION['reader_version'],$_SESSION['reader_active']);$_SESSION['csrf']=bin2hex(random_bytes(32));$result['csrf']=$_SESSION['csrf'];}
    return $result;
}
try {
    $db=ra_db();$action=$_GET['action']??'session';$method=$_SERVER['REQUEST_METHOD']??'GET';
    if($method==='GET'&&$action==='session'){
        cw_session();$r=cw_member();$has=false;
        if($r){$st=$db->prepare('SELECT 1 FROM reader_passwords WHERE reader_id=?');$st->execute([$r['id']]);$has=(bool)$st->fetchColumn();}
        cw_json(['reader'=>$r,'hasPassword'=>$has,'csrf'=>$_SESSION['csrf']]);
    }
    if($method!=='POST')cw_json(['error'=>'Method not allowed'],405);
    if(stripos($_SERVER['CONTENT_TYPE']??'','application/json')!==0)cw_json(['error'=>'JSON required'],415);
    $d=cw_input();$native=($d['native']??false)===true;
    // Native responses do not set browser cookies; browser mutations require CSRF.
    if(!$native)cw_csrf();
    if($action==='login'){
        cw_limit('password-login',20,900);
        $email=strtolower(cw_text($d,'email',160,true));$password=$d['password']??'';
        if(!is_string($password)||strlen($password)>128)throw new InvalidArgumentException('Check your email and password.');
        cw_limit('password-address-'.hash_hmac('sha256',$email,cw_config()['secret']),10,900);
        $st=$db->prepare('SELECT r.id,p.hash FROM readers r JOIN reader_passwords p ON p.reader_id=r.id WHERE r.email=? AND r.verified_at IS NOT NULL');$st->execute([$email]);$row=$st->fetch();
        $dummy='$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
        $valid=password_verify(hash('sha256',$password),$row['hash']??$dummy);
        if(!$row||!$valid)cw_json(['error'=>'Email or password is incorrect. If you previously used email codes, choose Set or reset password.'],401);
        if(password_needs_rehash($row['hash'],PASSWORD_DEFAULT))$db->prepare('UPDATE reader_passwords SET hash=? WHERE reader_id=?')->execute([password_hash(hash('sha256',$password),PASSWORD_DEFAULT),$row['id']]);
        cw_json(ra_issue($db,(int)$row['id'],$native));
    }
    if($action==='request-password'){
        cw_limit('password-email',5,3600);
        $email=strtolower(cw_text($d,'email',160,true));$password=ra_password($d);
        if(!filter_var($email,FILTER_VALIDATE_EMAIL)||($d['agree']??false)!==true)throw new InvalidArgumentException('Enter your email and acknowledge the privacy notice.');
        cw_limit('password-mail-'.hash_hmac('sha256',$email,cw_config()['secret']),3,3600);
        $challenge=bin2hex(random_bytes(32));$code=(string)random_int(10000000,99999999);
        $db->prepare('INSERT INTO reader_password_codes(challenge,email,hash,password_hash,expires) VALUES(?,?,?,?,?)')->execute([$challenge,$email,hash_hmac('sha256',$challenge.$code,cw_config()['secret']),password_hash(hash('sha256',$password),PASSWORD_DEFAULT),time()+600]);
        $body="Your CrimeWatch email verification code is: $code\n\nEnter it within 10 minutes to confirm the password you just chose. This creates your reader account if new, or sets/resets your existing account password. Future sign-ins use your email and password. Never share this code. If you did not request a password, ignore this email; nothing changes unless the code is entered.\n\nCrimeWatch\ninfo@crimewatch.live";
        $ok=getenv('CW_ADS_TEST')==='1'?file_put_contents(cw_config()['dir'].'/test-password-code.json',json_encode(['challenge'=>$challenge,'code'=>$code,'email'=>$email]))!==false:mail($email,'Verify your CrimeWatch email and password',$body,['From'=>'CrimeWatch <info@crimewatch.live>','Content-Type'=>'text/plain; charset=UTF-8'],'-finfo@crimewatch.live');
        if(!$ok){$db->prepare('DELETE FROM reader_password_codes WHERE challenge=?')->execute([$challenge]);cw_json(['error'=>'Unable to send the verification email. Please try later.'],503);}
        $db->prepare('DELETE FROM reader_password_codes WHERE expires<?')->execute([time()-86400]);
        cw_json(['ok'=>true,'challenge'=>$challenge]);
    }
    if($action==='confirm-password'){
        cw_limit('password-confirm',20,900);$challenge=cw_text($d,'challenge',64,true);$code=cw_text($d,'code',8,true);
        $db->exec('BEGIN IMMEDIATE');$st=$db->prepare('SELECT * FROM reader_password_codes WHERE challenge=? AND expires>? AND attempts<5');$st->execute([$challenge,time()]);$c=$st->fetch();
        if(!$c){$db->exec('ROLLBACK');cw_json(['error'=>'Code expired or already used. Request a new code.'],403);}
        if(!hash_equals($c['hash'],hash_hmac('sha256',$challenge.$code,cw_config()['secret']))){$db->prepare('UPDATE reader_password_codes SET attempts=attempts+1 WHERE challenge=?')->execute([$challenge]);$db->exec('COMMIT');cw_json(['error'=>'Incorrect code. Check your email.'],403);}
        $now=gmdate('c');$db->prepare('INSERT OR IGNORE INTO readers(email,created_at) VALUES(?,?)')->execute([$c['email'],$now]);
        $db->prepare('UPDATE readers SET verified_at=COALESCE(verified_at,?),version=version+1 WHERE email=?')->execute([$now,$c['email']]);
        $st=$db->prepare('SELECT id FROM readers WHERE email=?');$st->execute([$c['email']]);$id=(int)$st->fetchColumn();
        $db->prepare('INSERT INTO reader_passwords VALUES(?,?) ON CONFLICT(reader_id) DO UPDATE SET hash=excluded.hash')->execute([$id,$c['password_hash']]);
        $db->prepare('DELETE FROM member_tokens WHERE reader_id=?')->execute([$id]);
        foreach(['reader_password_codes','reader_tokens','member_codes'] as $table)$db->prepare("DELETE FROM $table WHERE email=?")->execute([$c['email']]);
        $db->exec('COMMIT');cw_json(ra_issue($db,$id,$native));
    }
    if($action==='logout'){
        $token=$native?substr($_SERVER['HTTP_AUTHORIZATION']??$_SERVER['REDIRECT_HTTP_AUTHORIZATION']??'',7):($_COOKIE['CWREADER']??'');
        if(is_string($token))$db->prepare('DELETE FROM member_tokens WHERE hash=?')->execute([hash('sha256',$token)]);
        if(!$native){cw_reader_cookie('',true);unset($_SESSION['reader'],$_SESSION['reader_version'],$_SESSION['reader_active']);session_regenerate_id(true);}
        cw_json(['ok'=>true]);
    }
    cw_json(['error'=>'Unknown action'],404);
}catch(InvalidArgumentException $e){cw_json(['error'=>$e->getMessage()],400);}catch(Throwable $e){if(isset($db)){try{$db->exec('ROLLBACK');}catch(Throwable $ignored){}}error_log('Reader password auth: '.get_class($e));cw_json(['error'=>'Account service temporarily unavailable.'],503);}
