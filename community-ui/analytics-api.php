<?php
declare(strict_types=1);
require __DIR__.'/audience-lib.php';header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
try{
 $action=$_GET['action']??'token';$method=$_SERVER['REQUEST_METHOD']??'GET';$db=cw_audience_db();
 if($method==='GET'&&in_array($action,['summary','readers','export'],true)){
  cw_admin();$days=(int)($_GET['days']??30);if(!in_array($days,[7,30,90],true))throw new InvalidArgumentException('Choose 7, 30, or 90 days.');
  if($action==='readers'){$offset=max(0,min(10000000,(int)($_GET['offset']??0)));$st=$db->prepare('SELECT email,name,area,created_at,verified_at,login_at FROM readers ORDER BY id DESC LIMIT 50 OFFSET ?');$st->bindValue(1,$offset,PDO::PARAM_INT);$st->execute();cw_json(['readers'=>$st->fetchAll(),'total'=>(int)$db->query('SELECT COUNT(*) FROM readers')->fetchColumn(),'offset'=>$offset]);}
  $s=cw_analytics_summary($days);if($action==='summary')cw_json($s);
  header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="crimewatch-audience-'.$s['from'].'-to-'.$s['to'].'.csv"');$out=fopen('php://output','w');
  $line=static function(array $r)use($out){fputcsv($out,array_map(static fn($v)=>is_string($v)&&preg_match('/^[=+@\-\t\r]/',$v)?"'".$v:$v,$r),',','"','');};
  $line(['Local CrimeWatch audience report']);$line(['Period (Central Time)',$s['from'],$s['to']]);$line(['Tracking began (UTC)',$s['started']]);$line(['Verified reader accounts (current)',$s['registered']]);$line(['New verified accounts in period',$s['newRegistered']]);$line(['Method','First-party measurements; approximate daily visitors are not monthly unique people. Opt-outs, blockers and bots affect totals. No historical backfill.']);$line(['Ad views','At least 50% visible for one second; one per ad/placement/community/page visit.']);$line(['Clicks','On-site business-profile opens; not calls, leads or sales.']);$line([]);$line(['Date','Community','Metric','Placement','Ad ID','Count']);foreach($s['rows'] as $row)$line(array_values($row));$line([]);$line(['Date','Approximate daily visitors']);foreach($s['visitors'] as $row)$line(array_values($row));fclose($out);exit;
 }
 // Ignore opted-out traffic, known automated clients, and signed-in owner activity.
 $ignored=($_SERVER['HTTP_DNT']??'')==='1'||($_SERVER['HTTP_SEC_GPC']??'')==='1'||preg_match('/bot|crawler|spider|headless|preview/i',$_SERVER['HTTP_USER_AGENT']??'');
 if(isset($_COOKIE['CWLOCALADMIN'])){cw_session();if(($_SESSION['admin']??null)===1)$ignored=true;}
 if($ignored)cw_json(['ignored'=>true]);
 if(isset($_SERVER['HTTP_ORIGIN'])&&!in_array($_SERVER['HTTP_ORIGIN'],['https://local.crimewatch.live','https://crimewatch.live',...(getenv('CW_ADS_TEST')==='1'?['http://127.0.0.1:8921']:[])],true))cw_json(['error'=>'Invalid origin'],403);
 if(isset($_SERVER['HTTP_SEC_FETCH_SITE'])&&!in_array($_SERVER['HTTP_SEC_FETCH_SITE'],['same-origin','none'],true))cw_json(['error'=>'Invalid origin'],403);
 if($method==='GET'&&$action==='token'){cw_limit('analytics-token',80,600);$payload=time().'.'.bin2hex(random_bytes(16));cw_json(['token'=>$payload.'.'.hash_hmac('sha256',$payload,cw_config()['secret'])]);}
 if($method!=='POST'||$action!=='event')cw_json(['error'=>'Method not allowed'],405);
 $d=cw_input();$token=cw_text($d,'token',160,true);$p=explode('.',$token);if(count($p)!==3||!ctype_digit($p[0])||!preg_match('/^[a-f0-9]{32}$/',$p[1])||(int)$p[0]>time()+30||time()-(int)$p[0]>7200||!hash_equals(hash_hmac('sha256',$p[0].'.'.$p[1],cw_config()['secret']),$p[2]))cw_json(['error'=>'Expired tracking token'],403);
 cw_limit('analytics-event',300,600);$event=cw_text($d,'event',30,true);$area=cw_text($d,'area',30,true);$slot=cw_text($d,'slot',20);$id=(int)($d['ad_id']??0);
 if(!cw_area($area,true)||!in_array($event,['page_view','map_view','arrests_view','ad_impression','ad_click','house_impression','house_click'],true))throw new InvalidArgumentException('Invalid event.');
 if(str_starts_with($event,'ad_')||str_starts_with($event,'house_')){if(!in_array($slot,['top','arrests','footer'],true))throw new InvalidArgumentException('Invalid placement.');if(str_starts_with($event,'ad_')){$matches=array_filter(cw_public_ads(),fn($a)=>(int)$a['id']===$id&&$a['slot']===$slot&&($a['area']==='all'||$a['area']===$area));if(!$matches)throw new InvalidArgumentException('Inactive ad.');}else{$id=0;}}else{$slot='';$id=0;}
 $day=(new DateTimeImmutable('now',new DateTimeZone('America/Chicago')))->format('Y-m-d');$visitor=hash_hmac('sha256',$day.'|'.($_SERVER['REMOTE_ADDR']??'').'|'.substr($_SERVER['HTTP_USER_AGENT']??'',0,300),cw_config()['secret']);$visit=hash('sha256',$p[1]);
 $db->beginTransaction();$st=$db->prepare('INSERT OR IGNORE INTO analytics_seen VALUES(?,?,?,?,?,?)');$st->execute([$day,$visit,$event,$area,$slot,$id]);if($st->rowCount()){$db->prepare('INSERT INTO analytics_counts(day,area,event,slot,ad_id,value) VALUES(?,?,?,?,?,1) ON CONFLICT(day,area,event,slot,ad_id) DO UPDATE SET value=value+1')->execute([$day,$area,$event,$slot,$id]);$db->prepare('INSERT OR IGNORE INTO analytics_visitors VALUES(?,?,?)')->execute([$day,$visitor,$area]);}$db->commit();
 // Short-lived identifiers never contain account IDs, emails, searches or report details.
 $cut=(new DateTimeImmutable('-89 days',new DateTimeZone('America/Chicago')))->format('Y-m-d');foreach(['analytics_seen','analytics_visitors','analytics_counts'] as $table)$db->prepare('DELETE FROM '.$table.' WHERE day<?')->execute([$cut]);cw_json(['ok'=>true]);
}catch(InvalidArgumentException $e){cw_json(['error'=>$e->getMessage()],400);}catch(Throwable $e){error_log('CW audience metrics: '.$e->getMessage());cw_json(['error'=>'Service temporarily unavailable'],503);}
