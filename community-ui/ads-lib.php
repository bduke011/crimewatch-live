<?php
declare(strict_types=1);
function cw_config(): array {
 static $config;
 if($config===null){$path=getenv('CW_ADS_DIR')?:'/home1/crimewatch/local-admin';$file=$path.'/config.json';if(!is_file($file))throw new RuntimeException('Advertising service not configured');$config=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);$config['dir']=$path;}
 return $config;
}
function cw_db(): PDO {
 static $db;if($db)return $db;$c=cw_config();
 $db=new PDO('sqlite:'.$c['dir'].'/advertising.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);$db->exec('PRAGMA busy_timeout=5000');
 $db->exec("CREATE TABLE IF NOT EXISTS admins(id INTEGER PRIMARY KEY CHECK(id=1),username TEXT UNIQUE NOT NULL,password_hash TEXT NOT NULL,version INTEGER NOT NULL DEFAULT 1);CREATE TABLE IF NOT EXISTS ads(id INTEGER PRIMARY KEY AUTOINCREMENT,business TEXT NOT NULL,category TEXT NOT NULL,headline TEXT NOT NULL,body TEXT NOT NULL,phone TEXT NOT NULL DEFAULT '',email TEXT NOT NULL DEFAULT '',image TEXT NOT NULL DEFAULT '',slot TEXT NOT NULL,area TEXT NOT NULL,status TEXT NOT NULL,start_date TEXT NOT NULL DEFAULT '',end_date TEXT NOT NULL DEFAULT '',monthly_rate INTEGER NOT NULL DEFAULT 0,notes TEXT NOT NULL DEFAULT '',updated_at TEXT NOT NULL);CREATE TABLE IF NOT EXISTS inquiries(id INTEGER PRIMARY KEY AUTOINCREMENT,business TEXT NOT NULL,name TEXT NOT NULL,email TEXT NOT NULL,phone TEXT NOT NULL,slot TEXT NOT NULL,message TEXT NOT NULL,status TEXT NOT NULL DEFAULT 'new',created_at TEXT NOT NULL);CREATE TABLE IF NOT EXISTS settings(key TEXT PRIMARY KEY,value TEXT NOT NULL);CREATE TABLE IF NOT EXISTS attempts(scope TEXT NOT NULL,ip TEXT NOT NULL,created INTEGER NOT NULL);CREATE INDEX IF NOT EXISTS attempt_lookup ON attempts(scope,ip,created);CREATE TABLE IF NOT EXISTS audit(id INTEGER PRIMARY KEY AUTOINCREMENT,action TEXT NOT NULL,subject TEXT NOT NULL,created_at TEXT NOT NULL);");
 $st=$db->prepare('INSERT OR IGNORE INTO settings VALUES(?,?)');$st->execute(['slots',json_encode(['top'=>true,'arrests'=>true,'footer'=>false])]);return $db;
}
function cw_session(): void {
 if(session_status()===PHP_SESSION_ACTIVE)return;
 ini_set('session.use_strict_mode','1');ini_set('session.use_only_cookies','1');session_name('CWLOCALADMIN');
 $path=rtrim(str_replace('\\','/',dirname($_SERVER['SCRIPT_NAME']??'/')),'/').'/';
 session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>getenv('CW_ADS_TEST')!=='1','httponly'=>true,'samesite'=>'Strict']);session_start();
 if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(32));
}
function cw_json(array $value,int $status=200): never {http_response_code($status);header('Content-Type: application/json; charset=utf-8');echo json_encode($value,JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE);exit;}
function cw_input(): array {if((int)($_SERVER['CONTENT_LENGTH']??0)>30000)cw_json(['error'=>'Request too large'],413);$raw=json_decode(file_get_contents('php://input'),true);if(!is_array($raw))cw_json(['error'=>'Invalid request'],400);return $raw;}
function cw_text(array $data,string $key,int $max,bool $required=false): string {
 $v=$data[$key]??'';if(!is_string($v))throw new InvalidArgumentException('Invalid '.$key);$v=trim($v);if(strlen($v)>$max||($required&&$v===''))throw new InvalidArgumentException('Check '.$key.' (maximum '.$max.' characters).');return $v;
}
function cw_csrf(): void {cw_session();$token=$_SERVER['HTTP_X_CSRF_TOKEN']??'';if(!is_string($token)||!hash_equals($_SESSION['csrf'],$token))cw_json(['error'=>'Session expired. Refresh and try again.'],403);}
function cw_admin(): array {
 cw_session();$db=cw_db();$admin=$db->query('SELECT id,username,version FROM admins WHERE id=1')->fetch();
 if(!$admin||($_SESSION['admin']??null)!==1||($_SESSION['version']??null)!==(int)$admin['version']||time()-($_SESSION['active']??0)>3600){unset($_SESSION['admin'],$_SESSION['version']);cw_json(['error'=>'Please sign in.'],401);}
 $_SESSION['active']=time();return $admin;
}
function cw_limit(string $scope,int $max,int $seconds): void {
 $db=cw_db();$ip=hash_hmac('sha256',$_SERVER['REMOTE_ADDR']??'unknown',cw_config()['secret']);$db->prepare('DELETE FROM attempts WHERE created<?')->execute([time()-86400]);
 $db->exec('BEGIN IMMEDIATE');try{$st=$db->prepare('SELECT COUNT(*) FROM attempts WHERE scope=? AND ip=? AND created>?');$st->execute([$scope,$ip,time()-$seconds]);if((int)$st->fetchColumn()>=$max){$db->exec('ROLLBACK');header('Retry-After: '.$seconds);cw_json(['error'=>'Too many attempts. Please try again later.'],429);}$db->prepare('INSERT INTO attempts VALUES(?,?,?)')->execute([$scope,$ip,time()]);$db->exec('COMMIT');}catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
}
function cw_audit(string $action,string $subject=''): void {cw_db()->prepare('INSERT INTO audit(action,subject,created_at) VALUES(?,?,?)')->execute([$action,$subject,gmdate('c')]);}
function cw_slots(): array {return json_decode(cw_db()->query("SELECT value FROM settings WHERE key='slots'")->fetchColumn(),true);}
function cw_public_ads(): array {
 $today=(new DateTimeImmutable('now',new DateTimeZone('America/Chicago')))->format('Y-m-d');$st=cw_db()->prepare("SELECT id,business,category,headline,body,phone,email,image,slot,area FROM ads WHERE status='active' AND (start_date='' OR start_date<=?) AND (end_date='' OR end_date>=?) ORDER BY id");$st->execute([$today,$today]);$slots=cw_slots();return array_values(array_filter($st->fetchAll(),fn($r)=>!empty($slots[$r['slot']])));
}
function cw_validate_ad(array $d): array {
 $r=[];foreach(['business'=>100,'category'=>30,'headline'=>100,'body'=>1600,'phone'=>40,'email'=>160,'slot'=>20,'area'=>30,'status'=>20,'start_date'=>10,'end_date'=>10,'notes'=>2000] as $k=>$n)$r[$k]=cw_text($d,$k,$n,in_array($k,['business','headline','body','slot','area','status','category'],true));
 foreach(['category'=>['Attorney','Bail bonds','Local business'],'slot'=>['top','arrests','footer'],'area'=>['all','polk','shsu','houston','shsu-conroe','shsu-woodlands','sfa','forney','nolan','kaufman'],'status'=>['draft','active','paused']] as $key=>$allowed)if(!in_array($r[$key],$allowed,true))throw new InvalidArgumentException('Invalid '.$key);
 if($r['email']!==''&&!filter_var($r['email'],FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('Enter a valid email.');
 if($r['phone']!==''&&!preg_match('/^[0-9+(). xX-]{5,40}$/',$r['phone']))throw new InvalidArgumentException('Enter a valid phone number.');
 foreach(['start_date','end_date'] as $k)if($r[$k]!==''){$date=DateTimeImmutable::createFromFormat('!Y-m-d',$r[$k]);if(!$date||$date->format('Y-m-d')!==$r[$k])throw new InvalidArgumentException('Invalid schedule date.');}
 if($r['start_date']!==''&&$r['end_date']!==''&&$r['end_date']<$r['start_date'])throw new InvalidArgumentException('End date must be on or after start date.');
 $price=$d['monthly_rate']??0;if(!is_numeric($price)||(float)$price<0||(float)$price>100000)throw new InvalidArgumentException('Invalid monthly rate.');$r['monthly_rate']=(int)round((float)$price*100);return $r;
}
