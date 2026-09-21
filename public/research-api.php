<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
try {
 if(!in_array($_SERVER['REQUEST_METHOD']??'GET',['GET','HEAD'],true)){http_response_code(405);throw new InvalidArgumentException('GET required');}
 foreach(['name','source','page','record'] as $key)if(isset($_GET[$key])&&!is_string($_GET[$key]))throw new InvalidArgumentException('Invalid search');
 $source=$_GET['source']??'';if(!in_array($source,['archive','roster'],true))throw new InvalidArgumentException('Choose a supported source');
 $name=trim($_GET['name']??'');if(strlen($name)>200||!preg_match('//u',$name))throw new InvalidArgumentException('Name is too long or invalid');
 $tokens=preg_split('/[^\p{L}\p{N}]+/u',$name,-1,PREG_SPLIT_NO_EMPTY);
 $record=$_GET['record']??'';
 if($record!==''&&!preg_match($source==='archive'?'/^[a-f0-9]{24}$/':'/^[A-Za-z0-9%]{1,64}$/',$record))throw new InvalidArgumentException('Invalid record');
 if($record===''&&(!$tokens||count($tokens)>12||strlen(implode('',$tokens))<2))throw new InvalidArgumentException('Enter at least two letters of a name');
 $page=filter_var($_GET['page']??'1',FILTER_VALIDATE_INT);if($page===false||$page<1)throw new InvalidArgumentException('Invalid page');
 $dir=getenv('CW_JAIL_DIR')?:dirname(__DIR__).'/jail-service/data';if(!is_file($dir.'/jail.sqlite'))throw new RuntimeException('Archive not ready');
 $db=new PDO('sqlite:'.$dir.'/jail.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);$db->exec('PRAGMA query_only=ON');$db->exec('PRAGMA busy_timeout=10000');
 $where=['NOT EXISTS(SELECT 1 FROM takedowns t WHERE t.bid=r.bid)'];$args=[];
 if($record!==''){$where[]=$source==='archive'?'r.id=?':'r.bid=?';$args[]=$record;}
 else foreach($tokens as $token){$where[]="r.name LIKE ? ESCAPE '\\'";$args[]='%'.str_replace(['\\','%','_'],['\\\\','\\%','\\_'],$token).'%';}
 if($source==='roster')$where[]='r.in_custody=1';
 $from=' FROM '.($source==='roster'?'roster':'bookings').' r WHERE '.implode(' AND ',$where);
 $st=$db->prepare('SELECT COUNT(*)'.$from);$st->execute($args);$total=(int)$st->fetchColumn();$pages=max(1,(int)ceil($total/20));$page=min($page,$pages);
 $columns=$source==='roster'?'r.bid AS id,r.name,r.age,r.admit_date AS booked,r.locality,r.details,r.last_detail':'r.id,r.name,r.age,r.booked,r.released,r.locality,r.details,r.report_date';
 $order=$source==='roster'?'r.admit_date DESC,r.admit_time DESC,r.bid':'r.booked DESC,r.id';
 $st=$db->prepare('SELECT '.$columns.$from.' ORDER BY '.$order.' LIMIT 20 OFFSET '.(($page-1)*20));$st->execute($args);$rows=$st->fetchAll();
 foreach($rows as &$r){$details=json_decode($r['details']?:'[]',true,512,JSON_THROW_ON_ERROR);$r['charges']=[];
  if($source==='roster'){foreach($details['charges']??[] as $c)$r['charges'][]=['description'=>$c['description']??'','agency'=>$c['arrestingAgency']??'','bond'=>$c['bond']??'','bondType'=>$c['bondType']??'','courtDate'=>$c['courtDate']??'','court'=>$c['courtType']??'','reference'=>$c['docket']??''];}
  else{foreach($details as $a)foreach($a['charges']??[] as $c)$r['charges'][]=['description'=>$c['description']??'','agency'=>$a['agency']??'','reference'=>$c['warrantNumber']??'','referenceType'=>$c['warrantType']??'','jurisdiction'=>$c['jurisdiction']??''];}
  unset($r['details']);$r['source']=$source;$r['key']=$source.':'.$r['id'];
 }unset($r);
 $statusPath=$dir.($source==='roster'?'/roster-status.json':'/status.json');$run=is_file($statusPath)?json_decode(file_get_contents($statusPath),true):[];$warnings=[];
 if($source==='archive'&&!empty($run['failedReports']))$warnings[]='Some published reports have not been imported successfully.';
 if($source==='roster'&&($run['complete']??false)!==true)$warnings[]='The last roster collection was incomplete or its completeness is unknown.';
 if($source==='roster'&&!empty($run['errors']))$warnings[]='Some source details could not be refreshed.';
 if(empty($run['checkedAt'])||strtotime($run['checkedAt'])===false||time()-strtotime($run['checkedAt'])>86400)$warnings[]='The source update time is unknown or more than 24 hours old.';
 $coverage=$source==='archive'?$db->query('SELECT MIN(r.booked) earliest,MAX(r.booked) latest FROM bookings r WHERE NOT EXISTS(SELECT 1 FROM takedowns t WHERE t.bid=r.bid)')->fetch():null;
 echo json_encode(['source'=>$source,'query'=>$name,'records'=>$rows,'total'=>$total,'page'=>$page,'pages'=>$pages,'checkedAt'=>$run['checkedAt']??null,'coverage'=>$coverage,'warnings'=>$warnings],JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE);
}catch(Throwable $e){if($e instanceof InvalidArgumentException){if(http_response_code()!==405)http_response_code(400);$message=$e->getMessage();}else{http_response_code(503);error_log('Research API '.$e->getMessage());$message='This source is temporarily unavailable. No search conclusion can be drawn.';}echo json_encode(['error'=>$message]);}
