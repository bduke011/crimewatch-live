<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: public, max-age=60');header('X-Content-Type-Options: nosniff');
try{
 if(!in_array($_SERVER['REQUEST_METHOD']??'GET',['GET','HEAD'],true)){http_response_code(405);throw new InvalidArgumentException('GET required');}
 $dir=getenv('CW_JAIL_DIR')?:dirname(__DIR__).'/jail-service/data';if(!is_file($dir.'/jail.sqlite'))throw new RuntimeException('Archive not ready');
 $db=new PDO('sqlite:'.$dir.'/jail.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);$db->exec('PRAGMA query_only=ON');$db->exec('PRAGMA busy_timeout=10000');
 $where=[];$args=[];
 foreach(['q','from','to','status','page','id'] as $key)if(isset($_GET[$key])&&!is_string($_GET[$key]))throw new InvalidArgumentException('Invalid filter');
 $q=trim($_GET['q']??'');if(strlen($q)>200)throw new InvalidArgumentException('Search is too long');
 if($q!==''){$where[]="search_text LIKE ? ESCAPE '\\'";$args[]='%'.str_replace(['\\','%','_'],['\\\\','\\%','\\_'],$q).'%';}
 foreach(['from'=>'>=','to'=>'<='] as $key=>$op){$v=$_GET[$key]??'';if($v==='')continue;$d=DateTimeImmutable::createFromFormat('!Y-m-d',$v);if(!$d||$d->format('Y-m-d')!==$v)throw new InvalidArgumentException('Invalid date');$where[]='booked'.$op.'?';$args[]=$v;}
 if(($_GET['from']??'')!==''&&($_GET['to']??'')!==''&&$_GET['from']>$_GET['to'])throw new InvalidArgumentException('Start date must be before end date');
 $status=$_GET['status']??'all';if(!in_array($status,['all','released','unknown'],true))throw new InvalidArgumentException('Invalid status');if($status!=='all')$where[]=$status==='released'?'released IS NOT NULL':'released IS NULL';
 if(isset($_GET['id'])){if(!preg_match('/^[a-f0-9]{24}$/',$_GET['id']))throw new InvalidArgumentException('Invalid record');$where[]='id=?';$args[]=$_GET['id'];}
 $clause=$where?' WHERE '.implode(' AND ',$where):'';$st=$db->prepare('SELECT COUNT(*) FROM bookings'.$clause);$st->execute($args);$total=(int)$st->fetchColumn();
 $page=filter_var($_GET['page']??'1',FILTER_VALIDATE_INT);if($page===false||$page<1)throw new InvalidArgumentException('Invalid page');$page=min($page,max(1,(int)ceil($total/24)));
 $st=$db->prepare('SELECT id,name,age,booked,released,locality,details,report_date FROM bookings'.$clause.' ORDER BY booked DESC,name,id LIMIT 24 OFFSET '.(($page-1)*24));$st->execute($args);$rows=$st->fetchAll();foreach($rows as &$r){$r['arrests']=json_decode($r['details'],true,512,JSON_THROW_ON_ERROR);unset($r['details']);}unset($r);
 $stats=$db->query('SELECT COUNT(*) total,MIN(booked) earliest,MAX(booked) latest FROM bookings')->fetch();$run=is_file($dir.'/status.json')?json_decode(file_get_contents($dir.'/status.json'),true):[];
 echo json_encode(['records'=>$rows,'total'=>$total,'page'=>$page,'pages'=>max(1,(int)ceil($total/24)),'archive'=>$stats,'checkedAt'=>$run['checkedAt']??null,'failedReports'=>$run['failedReports']??0],JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE);
}catch(Throwable $e){if($e instanceof InvalidArgumentException){if(http_response_code()!==405)http_response_code(400);$msg=$e->getMessage();}else{http_response_code(503);error_log('Jail API '.$e->getMessage());$msg='Booking archive is temporarily unavailable. Please try again.';}echo json_encode(['error'=>$msg]);}
