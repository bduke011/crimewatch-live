<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
try {
 if(!in_array($_SERVER['REQUEST_METHOD']??'GET',['GET','HEAD'],true)){http_response_code(405);throw new InvalidArgumentException('GET required');}
 foreach(['name','page'] as $k)if(isset($_GET[$k])&&!is_string($_GET[$k]))throw new InvalidArgumentException('Invalid search');
 $name=trim($_GET['name']??'');if(strlen($name)>200||!preg_match('//u',$name))throw new InvalidArgumentException('Invalid name');
 $tokens=preg_split('/[^\p{L}\p{N}]+/u',$name,-1,PREG_SPLIT_NO_EMPTY);
 if(!$tokens||count($tokens)>12||strlen(implode('',$tokens))<2)throw new InvalidArgumentException('Enter at least two letters of a name');
 $page=filter_var($_GET['page']??'1',FILTER_VALIDATE_INT);if($page===false||$page<1)throw new InvalidArgumentException('Invalid page');
 $dir=getenv('CW_JAIL_DIR')?:dirname(__DIR__).'/jail-service/data';if(!is_file($dir.'/jail.sqlite'))throw new RuntimeException('Database unavailable');
 $db=new PDO('sqlite:'.$dir.'/jail.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);$db->exec('PRAGMA query_only=ON');$db->exec('PRAGMA busy_timeout=10000');
 $db->sqliteCreateFunction('cw_namekey',function($v){$t=preg_split('/[^\p{L}\p{N}]+/u',strtolower((string)$v),-1,PREG_SPLIT_NO_EMPTY);sort($t,SORT_STRING);return implode(' ',$t);},1);
 $visible="c.review_state='unverified' AND NOT EXISTS(SELECT 1 FROM court_suppressions s WHERE s.lookup_id=c.id) AND NOT EXISTS(SELECT 1 FROM roster r JOIN takedowns t ON t.bid=r.bid WHERE cw_namekey(r.name)=c.name_key) AND NOT EXISTS(SELECT 1 FROM bookings b JOIN takedowns t ON t.bid=b.bid WHERE cw_namekey(b.name)=c.name_key)";
 $where=[$visible];$args=[];
 foreach($tokens as $token){$where[]="c.name LIKE ? ESCAPE '\\'";$args[]='%'.str_replace(['\\','%','_'],['\\\\','\\%','\\_'],$token).'%';}
 $from=' FROM court_lookups c WHERE '.implode(' AND ',$where);
 $st=$db->prepare('SELECT COUNT(*)'.$from);$st->execute($args);$total=(int)$st->fetchColumn();$pages=max(1,(int)ceil($total/20));$page=min($page,$pages);
 $st=$db->prepare('SELECT c.id,c.name,c.booked,c.cases_total,c.references_json,c.observed_date,c.imported_at,c.source'.$from.' ORDER BY c.observed_date DESC,c.name,c.id LIMIT 20 OFFSET '.(($page-1)*20));$st->execute($args);$rows=$st->fetchAll();
 $dates=$db->prepare('SELECT DISTINCT r.admit_date FROM roster r WHERE r.in_custody=1 AND cw_namekey(r.name)=cw_namekey(?) ORDER BY r.admit_date DESC');
 foreach($rows as &$r){$r['references']=json_decode($r['references_json'],true,512,JSON_THROW_ON_ERROR);unset($r['references_json']);$r['source']='court';$r['key']='court:'.$r['id'];$dates->execute([$r['name']]);$r['roster_dates']=$dates->fetchAll(PDO::FETCH_COLUMN);$r['booking_comparison']=!$r['roster_dates']?'not_listed':(in_array($r['booked'],$r['roster_dates'],true)?'same_date':'different_date');}unset($r);
 // Older deployments can serve summary records until the private importer creates this table.
 $hasCases=(bool)$db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='court_case_sets'")->fetchColumn();
 $caseSet=$hasCases?$db->prepare('SELECT cases_json,record_complete,observed_date FROM court_case_sets WHERE lookup_id=?'):null;
 $hiddenNames=$db->query('SELECT cw_namekey(r.name) FROM roster r JOIN takedowns t ON t.bid=r.bid UNION SELECT cw_namekey(b.name) FROM bookings b JOIN takedowns t ON t.bid=b.bid')->fetchAll(PDO::FETCH_COLUMN);
 $nameKey=$db->prepare('SELECT cw_namekey(?)');
 foreach($rows as &$r){
  $r['cases']=null;
  if(!$caseSet)continue;
  $caseSet->execute([$r['id']]);$set=$caseSet->fetch();if(!$set)continue;
  $r['cases']=[];
  foreach(json_decode($set['cases_json'],true,512,JSON_THROW_ON_ERROR) as $case){
   $nameKey->execute([$case['party_name']]);if(in_array($nameKey->fetchColumn(),$hiddenNames,true))continue;
   $r['cases'][]=$case;
  }
  $r['record_complete']=(bool)$set['record_complete'];$r['case_export_date']=$set['observed_date'];
  // Return only references that are visible under the current suppression rules.
  $r['references']=array_column($r['cases'],'case_number');$r['cases_total']=count($r['cases']);
 }unset($r);
 $coverage=$db->query('SELECT COUNT(*) lookups,MIN(c.observed_date) earliest,MAX(c.observed_date) latest FROM court_lookups c WHERE '.$visible)->fetch();
 echo json_encode(['source'=>'court','query'=>$name,'records'=>$rows,'total'=>$total,'page'=>$page,'pages'=>$pages,'coverage'=>$coverage,'warnings'=>['Imported portal records; review identifiers. Disposed does not specify the outcome or establish a conviction.'],'sourceLabel'=>'Polk County Tyler portal — owner-supplied export'],JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE);
}catch(Throwable $e){if($e instanceof InvalidArgumentException){if(http_response_code()!==405)http_response_code(400);$message=$e->getMessage();}else{http_response_code(503);error_log('Court API '.$e->getMessage());$message='Saved court lookups are temporarily unavailable. No search conclusion can be drawn.';}echo json_encode(['error'=>$message]);}
