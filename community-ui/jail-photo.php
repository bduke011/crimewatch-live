<?php
declare(strict_types=1);
require_once __DIR__.'/member-lib.php';cw_report_access();
if(!isset($_GET['photo'])){require '/home1/crimewatch/public_html/jail-photo.php';exit;}
header('X-Content-Type-Options: nosniff');header('Cache-Control: no-store');
if(!is_string($_GET['photo'])||!preg_match('/^[a-f0-9]{64}$/',$_GET['photo'])){http_response_code(400);exit;}
try{
 $dir='/home1/crimewatch/jail-service/data';
 $db=new PDO('sqlite:'.$dir.'/jail.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$db->exec('PRAGMA query_only=ON');$db->exec('PRAGMA busy_timeout=10000');
 $st=$db->prepare('SELECT p.file FROM report_photos p JOIN reports r ON r.url=p.source_url AND r.hash=p.pdf_hash LEFT JOIN bookings b ON b.id=p.booking_id WHERE p.token=? AND NOT EXISTS(SELECT 1 FROM report_photo_takedowns t WHERE t.booking_id=p.booking_id) AND NOT EXISTS(SELECT 1 FROM takedowns t WHERE t.bid=b.bid)');$st->execute([$_GET['photo']]);$file=$st->fetchColumn();
 if(!$file||!preg_match('/^[a-f0-9]{64}\.jpg$/',$file)){http_response_code(404);exit;}
 $path=$dir.'/report-photos/'.$file;if(!is_file($path)){http_response_code(404);exit;}
 header('Content-Type: image/jpeg');header('Content-Length: '.filesize($path));if(($_SERVER['REQUEST_METHOD']??'GET')!=='HEAD')readfile($path);
}catch(Throwable $e){error_log('Report photo: '.$e->getMessage());http_response_code(503);}
