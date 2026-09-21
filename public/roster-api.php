<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: public, max-age=120');header('X-Content-Type-Options: nosniff');
try{
 if(!in_array($_SERVER['REQUEST_METHOD']??'GET',['GET','HEAD'],true)){http_response_code(405);throw new InvalidArgumentException('GET required');}
 $dir=getenv('CW_JAIL_DIR')?:dirname(__DIR__).'/jail-service/data';if(!is_file($dir.'/jail.sqlite'))throw new RuntimeException('Roster not ready');
 $db=new PDO('sqlite:'.$dir.'/jail.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);$db->exec('PRAGMA query_only=ON');$db->exec('PRAGMA busy_timeout=10000');
 foreach(['q','sort','page','bid','days'] as $key)if(isset($_GET[$key])&&!is_string($_GET[$key]))throw new InvalidArgumentException('Invalid filter');
 $where=['r.in_custody=1','t.bid IS NULL'];$args=[];
 $q=trim($_GET['q']??'');if(strlen($q)>200)throw new InvalidArgumentException('Search is too long');
 if($q!==''){$where[]="COALESCE(r.search_text,r.name) LIKE ? ESCAPE '\\'";$args[]='%'.str_replace(['\\','%','_'],['\\\\','\\%','\\_'],$q).'%';}
 $days=$_GET['days']??'';if($days!==''){$d=filter_var($days,FILTER_VALIDATE_INT);if($d===false||$d<1||$d>3650)throw new InvalidArgumentException('Invalid day range');$where[]='r.admit_date>=?';$args[]=(new DateTimeImmutable('now',new DateTimeZone('America/Chicago')))->modify('-'.($d-1).' days')->format('Y-m-d');}
 if(isset($_GET['bid'])){if(!preg_match('/^[A-Za-z0-9%]{1,64}$/',$_GET['bid']))throw new InvalidArgumentException('Invalid record');$where[]='r.bid=?';$args[]=$_GET['bid'];}
 $sort=$_GET['sort']??'recent';$order=['recent'=>'r.admit_date DESC, r.admit_time DESC, r.name','name'=>'r.name, r.admit_date DESC','longest'=>'r.admit_date ASC, r.name'][$sort]??null;if($order===null)throw new InvalidArgumentException('Invalid sort');
 $from=' FROM roster r LEFT JOIN takedowns t ON t.bid=r.bid WHERE '.implode(' AND ',$where);
 $st=$db->prepare('SELECT COUNT(*)'.$from);$st->execute($args);$total=(int)$st->fetchColumn();
 $per=36;$page=filter_var($_GET['page']??'1',FILTER_VALIDATE_INT);if($page===false||$page<1)throw new InvalidArgumentException('Invalid page');$pages=max(1,(int)ceil($total/$per));$page=min($page,$pages);
 $st=$db->prepare('SELECT r.bid,r.name,r.age,r.admit_date,r.admit_time,r.locality,r.photo IS NOT NULL AS has_photo,r.details,r.last_detail,r.first_seen'.$from.' ORDER BY '.$order.' LIMIT '.$per.' OFFSET '.(($page-1)*$per));$st->execute($args);$rows=$st->fetchAll();
 foreach($rows as &$r){$d=$r['details']?json_decode($r['details'],true):null;$r['charges']=$d['charges']??[];$r['has_photo']=(bool)$r['has_photo'];unset($r['details']);}unset($r);
 $stats=$db->query('SELECT COUNT(*) total, SUM(photo IS NOT NULL) with_photo, MIN(admit_date) earliest FROM roster r WHERE in_custody=1 AND NOT EXISTS(SELECT 1 FROM takedowns t WHERE t.bid=r.bid)')->fetch();
 $run=is_file($dir.'/roster-status.json')?json_decode(file_get_contents($dir.'/roster-status.json'),true):[];
 echo json_encode(['records'=>$rows,'total'=>$total,'page'=>$page,'pages'=>$pages,'roster'=>$stats,'checkedAt'=>$run['checkedAt']??null,'complete'=>$run['complete']??null],JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE);
}catch(Throwable $e){if($e instanceof InvalidArgumentException){if(http_response_code()!==405)http_response_code(400);$msg=$e->getMessage();}else{http_response_code(503);error_log('Roster API '.$e->getMessage());$msg='The roster is temporarily unavailable. Please try again.';}echo json_encode(['error'=>$msg]);}
