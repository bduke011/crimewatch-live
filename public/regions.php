<?php
declare(strict_types=1);
function cw_regions():array{return [
 'polk'=>['label'=>'Polk County','coverage'=>'County incident reports. Publication may lag by up to 72 hours.','center'=>[30.79,-94.90]],
 'shsu'=>['label'=>'Huntsville — SHSU','coverage'=>'SHSU campus-related reports only; not all Huntsville. Locations without verified coordinates appear in the list.','center'=>[30.714,-95.548]],
 'houston'=>['label'=>'Houston','coverage'=>'Recent and historical offense entries; one incident may contain several offenses. Publication delays and gaps vary. Locations shown at approximate block level; time is hour only.','center'=>[29.76,-95.37]],
 'shsu-conroe'=>['label'=>'Conroe — SHSU','coverage'=>'SHSU Conroe campus only; not citywide. An empty log is not proof of no recent incidents.','center'=>[30.266,-95.46]],
 'shsu-woodlands'=>['label'=>'The Woodlands — SHSU','coverage'=>'SHSU Woodlands Center only; not all The Woodlands.','center'=>[30.21,-95.47]],
 'sfa'=>['label'=>'Nacogdoches — SFA','coverage'=>'University campus crime log summaries only. Unverified locations remain list-only.','center'=>[31.62,-94.65]],
 'forney'=>['label'=>'Forney','coverage'=>'Published calls for service, not confirmed crimes. Map locations are rounded by the publisher to roughly 500 meters; some records have no location. Coverage follows the public map area.','center'=>[32.74,-96.45]],
 'nolan'=>['label'=>'Nolan County','coverage'=>'Sheriff incident map reports; not all agencies in the county. Locations are approximate. Missing or out-of-area coordinates remain list-only.','center'=>[32.30,-100.40]],
 'kaufman'=>['label'=>'Kaufman County','coverage'=>'County incident map reports; not every agency in the county.','center'=>[32.59,-96.30]],
 ];}
function cw_public_regions():array{
 $db=nw_database();$out=[];
 foreach(cw_regions() as $id=>$r){$q=$db->prepare('SELECT COUNT(*) AS total,MAX(date) AS latest FROM incidents WHERE agency=?');$q->execute([$id]);$stats=$q->fetch();$q=$db->prepare('SELECT checked_at,success_at,status FROM regional_state WHERE agency=?');$q->execute([$id]);$st=$q->fetch()?:[];
 $out[]=['id'=>$id,'label'=>$r['label'],'coverage'=>$r['coverage'],'center'=>$r['center'],'total'=>(int)$stats['total'],'latest'=>$stats['latest'],'status'=>$st['status']??($id==='polk'?'available':'pending'),'checkedAt'=>$st['checked_at']??null];}return $out;
}
function cw_snapshot(string $agency):array{
 $db=nw_database();if($agency==='all'){$at=$db->query('SELECT MAX(success_at) FROM regional_state')->fetchColumn();return ['fetchedAt'=>$at?:gmdate('c'),'stale'=>false,'range'=>['start'=>'2019-01-01','end'=>date('Y-m-d')]];}
 $q=$db->prepare('SELECT success_at,status FROM regional_state WHERE agency=?');$q->execute([$agency]);$s=$q->fetch()?:[];
 $today=(new DateTimeImmutable('today',new DateTimeZone('America/Chicago')))->format('Y-m-d');
 return ['fetchedAt'=>$s['success_at']??gmdate('c'),'stale'=>($s['status']??'pending')!=='ok','range'=>['start'=>substr($today,0,4).'-01-01','end'=>$today]];
}
function cw_http(string $url,int $limit=25000000):string{
 $ch=curl_init($url);$body='';curl_setopt_array($ch,[CURLOPT_FOLLOWLOCATION=>true,CURLOPT_MAXREDIRS=>3,CURLOPT_CONNECTTIMEOUT=>12,CURLOPT_TIMEOUT=>90,CURLOPT_USERAGENT=>'CrimeWatch/1.1 public incident archive',CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_WRITEFUNCTION=>function($ch,$chunk)use(&$body,$limit){if(strlen($body)+strlen($chunk)>$limit)return 0;$body.=$chunk;return strlen($chunk);}]);
 $ok=curl_exec($ch);$status=curl_getinfo($ch,CURLINFO_HTTP_CODE);if(!$ok||$status!==200)throw new RuntimeException('Remote response '.$status);return $body;
}
function cw_dom(string $html):DOMXPath{
 $d=new DOMDocument();libxml_use_internal_errors(true);$d->loadHTML('<?xml encoding="utf-8" ?>'.$html,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);return new DOMXPath($d);
}
function cw_clean(string $text):string{return trim(preg_replace('/\s+/u',' ',str_replace("\xc2\xa0",' ',$text)));}
function cw_row(string $agency,string $key,string $date,string $time,string $offense,string $location,?float $lat=null,?float $lng=null,array $details=[]):array{
 if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)||!checkdate((int)substr($date,5,2),(int)substr($date,8,2),(int)substr($date,0,4))||$offense==='')throw new RuntimeException('Invalid record fields');
 if($lat===null||$lng===null||$lat<25||$lat>37||$lng< -107||$lng> -93){$lat=null;$lng=null;}
 return ['id'=>substr(hash('sha256',$agency.'|'.$key),0,20),'date'=>$date,'time'=>$time,'offense'=>$offense,'offenseCode'=>'','category'=>nw_category($offense),'location'=>$location,'lat'=>$lat===null?null:round($lat,3),'lng'=>$lng===null?null:round($lng,3),'details'=>$details];
}
function cw_store(string $agency,iterable $rows,string $at):array{
 $db=nw_database();$count=0;$latest=null;
 $sql="INSERT INTO incidents(id,date,time,offense,offense_code,category,location,lat,lng,first_seen,last_seen,changed_at,content_hash,source_state,agency,details) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,'listed',?,?) ON CONFLICT(id) DO UPDATE SET date=excluded.date,time=excluded.time,offense=excluded.offense,offense_code=excluded.offense_code,category=excluded.category,location=excluded.location,lat=excluded.lat,lng=excluded.lng,last_seen=excluded.last_seen,changed_at=CASE WHEN incidents.content_hash<>excluded.content_hash THEN excluded.changed_at ELSE incidents.changed_at END,content_hash=excluded.content_hash,details=excluded.details";
 $up=$db->prepare($sql);$ver=$db->prepare('INSERT OR IGNORE INTO incident_versions VALUES(?,?,?,?)');
 $db->beginTransaction();try{foreach($rows as $r){$payload=json_encode($r,JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE);$hash=hash('sha256',$payload);$up->execute([$r['id'],$r['date'],$r['time'],$r['offense'],$r['offenseCode']??'',$r['category'],$r['location'],$r['lat'],$r['lng'],$at,$at,$at,$hash,$agency,json_encode($r['details']??[],JSON_THROW_ON_ERROR)]);$ver->execute([$r['id'],$hash,$at,$payload]);$count++;$latest=max($latest??'',$r['date']);}$db->commit();}catch(Throwable $e){$db->rollBack();throw $e;}return ['count'=>$count,'latest'=>$latest];
}
function cw_collect_one(string $id,bool $force=false,?int $year=null):array{
 require_once __DIR__.'/regional-collectors.php';$db=nw_database();$lock=fopen(nw_data_directory().'/region-'.$id.'.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))return ['status'=>'busy'];
 try{
  $q=$db->prepare('SELECT * FROM regional_state WHERE agency=?');$q->execute([$id]);$state=$q->fetch()?:[];
  if(!$force&&strtotime($state['checked_at']??'1970-01-01')>=nw_latest_collection_slot()->getTimestamp())return ['status'=>$state['status'],'cached'=>true];
  $at=gmdate('c');
  try{
   $data=cw_source_rows($id,$year);$result=cw_store($id,$data,$at);
   $q=$db->prepare("INSERT INTO regional_state(agency,checked_at,success_at,status,count,latest) VALUES(?,?,?,'ok',?,?) ON CONFLICT(agency) DO UPDATE SET checked_at=excluded.checked_at,success_at=excluded.success_at,status='ok',count=excluded.count,latest=excluded.latest");$q->execute([$id,$at,$at,$result['count'],$result['latest']]);return ['status'=>'ok']+$result;
  }catch(Throwable $e){$q=$db->prepare("INSERT INTO regional_state(agency,checked_at,status) VALUES(?,?,'unavailable') ON CONFLICT(agency) DO UPDATE SET checked_at=excluded.checked_at,status='unavailable'");$q->execute([$id,$at]);error_log('CrimeWatch '.$id.': '.$e->getMessage());return ['status'=>'unavailable','error'=>$e->getMessage()];}
 }finally{flock($lock,LOCK_UN);fclose($lock);}
}
function cw_collect_regions():void{foreach(array_keys(cw_regions()) as $id)if($id!=='polk')echo json_encode([$id=>cw_collect_one($id)]).PHP_EOL;}
function cw_history(string $id,string $from,string $to):array{
 if(in_array($id,['kaufman','nolan','forney'],true)&&$from!==''&&$to!==''){require_once __DIR__.'/regional-collectors.php';return cw_kaufman_history($from,$to,$id);}
 if($id==='houston'&&$from!==''&&$to!==''){
  $year=(int)substr($from,0,4);$end=min((int)date('Y'),(int)substr($to,0,4));if($year<2019)return ['status'=>'local','message'=>'Saved results shown. Automatic Houston history starts in 2019.'];
  $db=nw_database();for(;$year<=$end;$year++){$q=$db->prepare('SELECT checked_at FROM regional_imports WHERE agency=? AND period=?');$q->execute(['houston',(string)$year]);$checked=$q->fetchColumn();if(!$checked){$r=cw_collect_one('houston',true,$year);return ['status'=>$r['status']==='ok'?'collected':'unavailable','message'=>$r['status']==='ok'?"Saved Houston $year records. Checking the remaining years…":'Additional history is temporarily unavailable; saved reports are shown.'];}}
 }
 return ['status'=>'local','message'=>'Searching the permanent archive for this area. Available published records are collected on the scheduled runs; coverage varies.'];
}
