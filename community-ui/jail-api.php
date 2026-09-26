<?php
declare(strict_types=1);
require_once __DIR__.'/member-lib.php';cw_report_access();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
try {
 if(!in_array($_SERVER['REQUEST_METHOD']??'GET',['GET','HEAD'],true)){http_response_code(405);throw new InvalidArgumentException('GET required');}
 $dir='/home1/crimewatch/jail-service/data';
 $db=new PDO('sqlite:'.$dir.'/jail.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
 $db->exec('PRAGMA query_only=ON');$db->exec('PRAGMA busy_timeout=10000');
 $archive=$db->query('SELECT MIN(report_date) earliest,MAX(report_date) latest,COUNT(*) total FROM reports')->fetch();
 $date=$_GET['report']??'latest';
 if(!is_string($date))throw new InvalidArgumentException('Invalid report date');
 if($date==='latest')$date=$archive['latest']??'';
 $parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$date);
 if(!$parsed||$parsed->format('Y-m-d')!==$date)throw new InvalidArgumentException('Invalid report date');
 $st=$db->prepare('SELECT report_date,period_start,period_end,count,checked FROM reports WHERE report_date=? ORDER BY url');$st->execute([$date]);$sources=$st->fetchAll();
 $st=$db->prepare('SELECT rb.payload,b.bid,p.token FROM report_bookings rb JOIN reports r ON r.url=rb.source_url LEFT JOIN bookings b ON b.id=rb.booking_id LEFT JOIN report_photos p ON p.source_url=r.url AND p.booking_id=rb.booking_id AND p.pdf_hash=r.hash WHERE r.report_date=? ORDER BY rb.booking_id');$st->execute([$date]);
 $suppressed=$db->prepare('SELECT 1 FROM report_photo_takedowns WHERE booking_id=? UNION ALL SELECT 1 FROM takedowns WHERE bid=? LIMIT 1');
 $photo=$db->prepare('SELECT r.bid FROM roster r LEFT JOIN takedowns t ON t.bid=r.bid WHERE r.bid=? AND r.photo IS NOT NULL AND t.bid IS NULL');
 $rows=[];
 foreach($st as $entry){$r=json_decode($entry['payload'],true,512,JSON_THROW_ON_ERROR);$r['report_date']=$date;$r['photo_bid']=null;$r['photo_token']=null;$suppressed->execute([$r['id'],$entry['bid']]);if($suppressed->fetchColumn()===false){$r['photo_token']=$entry['token'];if($entry['bid']){$photo->execute([$entry['bid']]);$bid=$photo->fetchColumn();if($bid!==false)$r['photo_bid']=$bid;}}$rows[$r['id']]=$r;}
 $rows=array_values($rows);usort($rows,fn($a,$b)=>strcmp($a['name'],$b['name']));
 $run=is_file($dir.'/status.json')?json_decode(file_get_contents($dir.'/status.json'),true):[];
 echo json_encode(['records'=>$rows,'total'=>count($rows),'page'=>1,'pages'=>1,'archive'=>$archive,'reportDate'=>$date,'sources'=>$sources,'checkedAt'=>$run['checkedAt']??null,'failedReports'=>$run['failedReports']??0],JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE);
}catch(Throwable $e){if($e instanceof InvalidArgumentException){if(http_response_code()!==405)http_response_code(400);$message=$e->getMessage();}else{http_response_code(503);error_log('Local daily report: '.$e->getMessage());$message='Published report archive is temporarily unavailable. Please try again.';}echo json_encode(['error'=>$message]);}
