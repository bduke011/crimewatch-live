<?php
declare(strict_types=1);
// Serves a booking photo from the private jail-service directory. Honors takedowns.
$bid=$_GET['bid']??'';
if(!is_string($bid)||!preg_match('/^[A-Za-z0-9%]{1,64}$/',$bid)){http_response_code(400);exit;}
try{
 $dir=getenv('CW_JAIL_DIR')?:dirname(__DIR__).'/jail-service/data';
 $db=new PDO('sqlite:'.$dir.'/jail.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$db->exec('PRAGMA query_only=ON');$db->exec('PRAGMA busy_timeout=10000');
 $st=$db->prepare('SELECT r.photo FROM roster r LEFT JOIN takedowns t ON t.bid=r.bid WHERE r.bid=? AND t.bid IS NULL');$st->execute([$bid]);$photo=$st->fetchColumn();
 if(!$photo||!preg_match('/^[a-f0-9]{24}\.(jpg|png)$/',$photo)){http_response_code(404);exit;}
 $path=realpath($dir.'/photos/'.$photo);$base=realpath($dir.'/photos');
 if(!$path||!$base||strpos($path,$base.DIRECTORY_SEPARATOR)!==0||!is_file($path)){http_response_code(404);exit;}
 $etag='"'.md5_file($path).'"';
 if(($_SERVER['HTTP_IF_NONE_MATCH']??'')===$etag){http_response_code(304);exit;}
 header('Content-Type: '.(str_ends_with($photo,'.png')?'image/png':'image/jpeg'));
 header('Cache-Control: public, max-age=86400');header('ETag: '.$etag);header('X-Content-Type-Options: nosniff');
 header('Content-Length: '.(string)filesize($path));
 if(($_SERVER['REQUEST_METHOD']??'GET')!=='HEAD')readfile($path);
}catch(Throwable $e){error_log('Jail photo '.$e->getMessage());http_response_code(503);}
