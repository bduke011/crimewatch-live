<?php
declare(strict_types=1);
require_once __DIR__.'/ads-lib.php';
function cw_audience_db(): PDO {
 $db=cw_db();$db->exec("CREATE TABLE IF NOT EXISTS readers(id INTEGER PRIMARY KEY AUTOINCREMENT,email TEXT NOT NULL UNIQUE,name TEXT NOT NULL DEFAULT '',area TEXT NOT NULL DEFAULT 'polk',created_at TEXT NOT NULL,verified_at TEXT,login_at TEXT,version INTEGER NOT NULL DEFAULT 1);CREATE TABLE IF NOT EXISTS reader_tokens(hash TEXT PRIMARY KEY,email TEXT NOT NULL,expires INTEGER NOT NULL);CREATE INDEX IF NOT EXISTS reader_token_email ON reader_tokens(email);CREATE TABLE IF NOT EXISTS analytics_counts(day TEXT NOT NULL,area TEXT NOT NULL,event TEXT NOT NULL,slot TEXT NOT NULL DEFAULT '',ad_id INTEGER NOT NULL DEFAULT 0,value INTEGER NOT NULL DEFAULT 0,PRIMARY KEY(day,area,event,slot,ad_id));CREATE TABLE IF NOT EXISTS analytics_visitors(day TEXT NOT NULL,visitor TEXT NOT NULL,area TEXT NOT NULL,PRIMARY KEY(day,visitor,area));CREATE TABLE IF NOT EXISTS analytics_seen(day TEXT NOT NULL,visit TEXT NOT NULL,event TEXT NOT NULL,area TEXT NOT NULL,slot TEXT NOT NULL,ad_id INTEGER NOT NULL,PRIMARY KEY(day,visit,event,area,slot,ad_id));");
 $db->prepare('INSERT OR IGNORE INTO settings VALUES(?,?)')->execute(['audience_started',gmdate('c')]);return $db;
}
function cw_area(string $area,bool $all=false): bool {return in_array($area,array_merge($all?['all']:[],['polk','shsu','houston','shsu-conroe','shsu-woodlands','sfa','forney','nolan','kaufman']),true);}
function cw_area_label(string $area): string {return ['polk'=>'Polk County','shsu'=>'Huntsville — SHSU','houston'=>'Houston','shsu-conroe'=>'Conroe — SHSU','shsu-woodlands'=>'The Woodlands — SHSU','sfa'=>'Nacogdoches — SFA','forney'=>'Forney','nolan'=>'Nolan County','kaufman'=>'Kaufman County'][$area]??$area;}
function cw_reader(): ?array {
 if(function_exists('cw_cookie_reader')){$persistent=cw_cookie_reader();if($persistent)return $persistent;}
 cw_session();if(empty($_SESSION['reader'])||time()-($_SESSION['reader_active']??0)>86400)return null;
 $st=cw_audience_db()->prepare('SELECT id,email,name,area,created_at,verified_at,version FROM readers WHERE id=? AND verified_at IS NOT NULL');$st->execute([$_SESSION['reader']]);$r=$st->fetch();if(!$r||(int)$r['version']!==($_SESSION['reader_version']??0))return null;$_SESSION['reader_active']=time();unset($r['version']);return $r;
}
function cw_reader_required(): array {$r=cw_reader();if(!$r)cw_json(['error'=>'Please sign in to your reader account.'],401);return $r;}
function cw_mail_link(string $email,string $token): bool {
 $url='https://crimewatch.live/local/account.php#verify='.$token;
 $body="Your Local CrimeWatch sign-in link:\n\n$url\n\nThis link expires in 20 minutes and works once. Open it only if you requested it. Do not forward it.\n\nSigning in verifies your email and creates your reader account if you are new. A free verified account is required to read reports. We do not share your contact details with advertisers.\n\nIf you did not request this email, you can ignore it.\nLocal CrimeWatch\ninfo@crimewatch.live\n";
 if(getenv('CW_ADS_TEST')==='1'){file_put_contents(cw_config()['dir'].'/test-mail.json',json_encode(['email'=>$email,'token'=>$token]));return true;}
 return mail($email,'Your Local CrimeWatch sign-in link',$body,['From'=>'Local CrimeWatch <info@crimewatch.live>','Reply-To'=>'info@crimewatch.live','Content-Type'=>'text/plain; charset=UTF-8'],'-finfo@crimewatch.live');
}
function cw_analytics_summary(int $days): array {
 $db=cw_audience_db();$today=new DateTimeImmutable('today',new DateTimeZone('America/Chicago'));$from=$today->modify('-'.($days-1).' days')->format('Y-m-d');$to=$today->format('Y-m-d');
 $st=$db->prepare('SELECT day,area,event,slot,ad_id,value FROM analytics_counts WHERE day>=? AND day<=? ORDER BY day');$st->execute([$from,$to]);$rows=$st->fetchAll();
 $st=$db->prepare('SELECT day,COUNT(DISTINCT visitor) visitors FROM analytics_visitors WHERE day>=? AND day<=? GROUP BY day');$st->execute([$from,$to]);$visitors=$st->fetchAll();
 $st=$db->prepare('SELECT area,COUNT(*) visitor_days FROM analytics_visitors WHERE day>=? AND day<=? GROUP BY area');$st->execute([$from,$to]);$areas=$st->fetchAll();
 $registered=(int)$db->query('SELECT COUNT(*) FROM readers WHERE verified_at IS NOT NULL')->fetchColumn();$pending=(int)$db->query('SELECT COUNT(*) FROM readers WHERE verified_at IS NULL')->fetchColumn();
 $start=$today->modify('-'.($days-1).' days')->setTime(0,0)->setTimezone(new DateTimeZone('UTC'))->format('c');
 $st=$db->prepare('SELECT COUNT(*) FROM readers WHERE verified_at>=?');$st->execute([$start]);$new=(int)$st->fetchColumn();
 $st=$db->prepare('SELECT COUNT(*) FROM readers WHERE verified_at IS NOT NULL AND login_at>=?');$st->execute([$start]);$signed=(int)$st->fetchColumn();
 $started=$db->query("SELECT value FROM settings WHERE key='audience_started'")->fetchColumn();$first=(new DateTimeImmutable($started))->setTimezone(new DateTimeZone('America/Chicago'))->setTime(0,0);$rangeStart=new DateTimeImmutable($from,new DateTimeZone('America/Chicago'));if($first<$rangeStart)$first=$rangeStart;$trackedDays=max(1,(int)$first->diff($today)->format('%a')+1);
 return ['trackedDays'=>$trackedDays,'from'=>$from,'to'=>$to,'started'=>$db->query("SELECT value FROM settings WHERE key='audience_started'")->fetchColumn(),'rows'=>$rows,'visitors'=>$visitors,'areas'=>$areas,'registered'=>$registered,'pending'=>$pending,'newRegistered'=>$new,'signedIn'=>$signed,'adNames'=>$db->query('SELECT id,business FROM ads')->fetchAll()];
}
