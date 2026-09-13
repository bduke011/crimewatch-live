<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: public, max-age=300');header('X-Content-Type-Options: nosniff');
try {
 $dir=__DIR__.'/data/fbi';$catalog=['years'=>[]];foreach(range(2026,1997) as $y){if(!is_file($dir.'/'.$y.'.json'))continue;$m=json_decode(file_get_contents($dir.'/'.$y.'.json'),true,512,JSON_THROW_ON_ERROR);unset($m['agencies']);$catalog['years'][]=$m;}if(!$catalog['years'])throw new RuntimeException('Archive not ready');
 if(($_GET['action']??'')==='catalog'){echo json_encode($catalog,JSON_THROW_ON_ERROR);exit;}
 $year=filter_var($_GET['year']??2026,FILTER_VALIDATE_INT);$years=array_column($catalog['years'],'year');if(!in_array($year,$years,true)){http_response_code(400);throw new InvalidArgumentException('Choose an available year.');}
 $meta=json_decode(file_get_contents($dir.'/'.$year.'.json'),true,512,JSON_THROW_ON_ERROR);
 if(($_GET['action']??'')==='agencies'){echo json_encode($meta,JSON_THROW_ON_ERROR);exit;}
 $db=new PDO('sqlite:'.$dir.'/'.$year.'.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);$db->exec('PRAGMA query_only=ON');
 $where=[];$args=[];$agency=(string)($_GET['agency']??'');$code=substr((string)($_GET['offense']??''),0,3);
 if($agency!==''){$where[]='i.agency=?';$args[]=(int)$agency;}
 if($code!==''){$where[]='EXISTS(SELECT 1 FROM offenses f WHERE f.incident=i.id AND f.code=?)';$args[]=$code;}
 $sql=$where?' WHERE '.implode(' AND ',$where):'';
 $q=$db->prepare('SELECT count(*) FROM incidents i'.$sql);$q->execute($args);$total=(int)$q->fetchColumn();$page=max(1,min(1000000,(int)($_GET['page']??1)));$page=min($page,max(1,(int)ceil($total/50)));$offset=($page-1)*50;
 $q=$db->prepare('SELECT i.*,a.name agencyName,a.ori,a.county FROM incidents i JOIN agencies a ON a.id=i.agency'.$sql.' ORDER BY i.date DESC,i.id DESC LIMIT 50 OFFSET '.$offset);$q->execute($args);$rows=$q->fetchAll();
 $off=$db->prepare('SELECT o.code,COALESCE(t.name,o.code) name,COALESCE(l.name,\'Not specified\') location,o.attempt FROM offenses o LEFT JOIN offense_types t ON t.code=o.code LEFT JOIN locations l ON l.code=o.location WHERE o.incident=?');
 foreach($rows as &$r){$off->execute([$r['id']]);$r['offenses']=$off->fetchAll();}unset($r);
 echo json_encode(['year'=>$year,'total'=>$total,'page'=>$page,'pages'=>(int)ceil($total/50),'incidents'=>$rows,'offenseTypes'=>$db->query('SELECT * FROM offense_types ORDER BY name')->fetchAll()],JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE);
}catch(Throwable $e){header('Cache-Control: no-store');if(http_response_code()===200)http_response_code(503);error_log('FBI search: '.$e->getMessage());echo json_encode(['error'=>$e instanceof InvalidArgumentException?$e->getMessage():'FBI archive is temporarily unavailable. Please try again.']);}
