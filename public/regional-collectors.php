<?php
declare(strict_types=1);
function cw_source_rows(string $id,?int $year=null):iterable{
 if($id==='forney'){yield from cw_forney(date('Y-m-d',time()-30*86400),date('Y-m-d'));return;}
 if($id==='houston'){if($year===null)yield from cw_houston_recent();else yield from cw_houston($year);return;}
 if(str_starts_with($id,'shsu')){yield from cw_shsu($id);return;}
 if(in_array($id,['kaufman','nolan'],true)){yield from cw_kaufman_window(date('Y-m-d',time()-30*86400),date('Y-m-d'),$id);return;}
 if($id==='sfa'){yield from cw_sfa();return;}
 throw new RuntimeException('Collector unavailable');
}
function cw_shsu(string $id):iterable{
 $suffix=['shsu'=>'','shsu-conroe'=>'conroe-campus','shsu-woodlands'=>'woodlands-center'][$id];
 $x=cw_dom(cw_http('https://www.shsu.edu/offices-departments/university-police-department/daily-crime-log/'.$suffix));
 $tables=$x->query('//table[.//th[contains(.,"Classification")]]');if(!$tables->length)throw new RuntimeException('Crime log table missing');
 foreach($x->query('.//tbody/tr',$tables->item(0)) as $tr){$cells=[];foreach($x->query('./th|./td',$tr) as $td)$cells[]=cw_clean($td->textContent);
  if(count($cells)!==6)throw new RuntimeException('Crime log columns changed');
  if($cells[1]==='-'||$cells[1]===''||stripos($cells[0],'No Crimes')!==false)continue;
  if(!preg_match('~(\d{1,2}/\d{1,2}/\d{4})\s+(\d{1,2}:\d{2})~',$cells[3],$m))throw new RuntimeException('Crime log date missing');
  $d=DateTimeImmutable::createFromFormat('!n/j/Y G:i',$m[1].' '.$m[2]);if(!$d)throw new RuntimeException('Crime log date invalid');
  $city=$id==='shsu'?'Huntsville':($id==='shsu-conroe'?'Conroe':'The Woodlands');
  yield cw_row($id,$cells[1].'|'.$cells[0].'|'.$d->format('Y-m-d H:i'),$d->format('Y-m-d'),$d->format('H:i'),$cells[0],$cells[4].', '.$city,null,null,['disposition'=>$cells[5],'reportedAt'=>$cells[2],'scope'=>'University campus-related report']);
 }
}
function cw_houston_recent():iterable{
 $base='https://mycity2.houstontx.gov/pubgis02/rest/services/HPD/NIBRS_Recent_Crime_All_Cases/MapServer/0/query?';
 $query=function(array $p)use($base):array{$r=json_decode(cw_http($base.http_build_query(['f'=>'json']+$p)),true,512,JSON_THROW_ON_ERROR);if(isset($r['error']))throw new RuntimeException('Houston map query failed');return $r;};
 $snapshot=$query(['where'=>'1=1','returnIdsOnly'=>'true']);$ids=$snapshot['objectIds']??null;if(!is_array($ids)||!count($ids))throw new RuntimeException('Houston map snapshot missing');
 foreach(array_chunk($ids,100) as $batch){
  $r=$query(['objectIds'=>implode(',',$batch),'outFields'=>'*','returnGeometry'=>'true','outSR'=>4326]);
  if(!isset($r['features'])||count($r['features'])!==count($batch)||!empty($r['exceededTransferLimit']))throw new RuntimeException('Houston map snapshot incomplete');
  foreach($r['features'] as $f){$a=$f['attributes'];$g=$f['geometry']??[];
   if(!$g)$g=cw_plus_code_point((string)($a['OLC']??''));
   if(empty($a['USER_Incident'])||empty($a['USER_RMSOccurrenceDate'])||empty($a['USER_NIBRSClass']))throw new RuntimeException('Houston map fields changed');
   $date=gmdate('Y-m-d',(int)($a['USER_RMSOccurrenceDate']/1000));$h=(string)($a['USER_RMSOccurrenceHour']??'');
   $location=cw_clean(implode(' ',[$a['USER_BlockRange']??'',$a['USER_StreetName']??'',$a['USER_StreetType']??'',$a['USER_Suffix']??'']));
   $row=cw_row('houston',$a['USER_Incident'].'|'.$a['USER_NIBRSClass'],$date,ctype_digit($h)&&(int)$h<24?sprintf('%02d:00',(int)$h):'Not published',$a['USER_NIBRSDescription']??'Unclassified report',($location?:'Location not published').', Houston',isset($g['y'])?(float)$g['y']:null,isset($g['x'])?(float)$g['x']:null,['premise'=>$a['USER_Premise']??'','beat'=>$a['USER_Beat']??'','timePrecision'=>'Hour only','locationPrecision'=>'Publisher map area; approximate and not address-verified','recordType'=>'Published recent offense entry']);
   $row['offenseCode']=$a['USER_NIBRSClass'];yield $row;
  }
 }
}
function cw_plus_code_point(string $code):array{
 // Decode a full ten-digit Open Location Code supplied by the publisher.
 if(!preg_match('/^[23456789CFGHJMPQRVWX]{8}\+[23456789CFGHJMPQRVWX]{2}$/',$code))return [];
 $digits=str_replace('+','',$code);$alphabet='23456789CFGHJMPQRVWX';$lat=-90.0;$lng=-180.0;$size=20.0;
 for($i=0;$i<10;$i+=2){$lat+=strpos($alphabet,$digits[$i])*$size;$lng+=strpos($alphabet,$digits[$i+1])*$size;if($i<8)$size/=20;}
 $lat+=$size/2;$lng+=$size/2;if($lat>90||$lng>180)return [];
 return ['x'=>$lng,'y'=>$lat];
}
function cw_houston(int $year):iterable{
 if($year<2019||$year>(int)date('Y'))throw new RuntimeException('Unsupported Houston year');
 $body=cw_http('https://www.houstontx.gov/police/cs/xls/NIBRSPublicView'.$year.'.csv',90000000);
 $dbSize=is_file(nw_data_directory().'/crimewatch.sqlite')?filesize(nw_data_directory().'/crimewatch.sqlite'):0;
 $hash=hash('sha256',$body);$db=nw_database();$q=$db->prepare('SELECT fingerprint FROM regional_imports WHERE agency=? AND period=?');$q->execute(['houston',(string)$year]);if($q->fetchColumn()===$hash)return;
 if($dbSize+strlen($body)*10>240000000)throw new RuntimeException('Archive storage reserve reached; hosting storage must be expanded before this import.');
 $f=fopen('php://temp/maxmemory:2097152','w+');fwrite($f,$body);unset($body);rewind($f);$header=fgetcsv($f,0,',','"','');$header[0]=ltrim($header[0],"\xef\xbb\xbf");
 foreach(['Incident','Occurrence Date','NIBRS Description','Map Latitude','Map Longitude'] as $required)if(!in_array($required,$header,true))throw new RuntimeException('Houston columns changed');
 try{while(($cells=fgetcsv($f,0,',','"',''))!==false){if($cells===[null])continue;if(count($cells)!==count($header))throw new RuntimeException('Houston row mismatch');$r=array_combine($header,$cells);
  $n=preg_match('/^\d+$/',$r['Street Number'])?(string)(100*(int)floor((int)$r['Street Number']/100)).' block':$r['Street Number'];
  $location=cw_clean(implode(' ',[$n,$r['Street Name'],$r['Street Type'],$r['Street Suffix']])).', Houston';
  $lat=is_numeric($r['Map Latitude'])?round((float)$r['Map Latitude'],3):null;$lng=is_numeric($r['Map Longitude'])?round((float)$r['Map Longitude'],3):null;
  $hour=ctype_digit($r['Occurrence Hour'])&&(int)$r['Occurrence Hour']<24?sprintf('%02d:00',(int)$r['Occurrence Hour']):'Not published';
  $row=cw_row('houston',$r['Incident'].'|'.$r['NIBRS Class'],$r['Occurrence Date'],$hour,$r['NIBRS Description'],$location,$lat,$lng,['premise'=>$r['Premise'],'beat'=>$r['Beat'],'offenseCount'=>(int)$r['Offense Count'],'timePrecision'=>'Hour only','locationPrecision'=>'Approximate block']);$row['offenseCode']=$r['NIBRS Class'];yield $row;
 }}finally{fclose($f);}
 $q=$db->prepare('INSERT INTO regional_imports VALUES(?,?,?,?) ON CONFLICT(agency,period) DO UPDATE SET fingerprint=excluded.fingerprint,checked_at=excluded.checked_at');$q->execute(['houston',(string)$year,$hash,gmdate('c')]);
}
function cw_sfa():iterable{
 $x=cw_dom(cw_http('https://www.sfasu.edu/police/public-records'));$head=$x->query('//h3[contains(.,"Campus crime logs")]')->item(0);if(!$head)throw new RuntimeException('Campus log heading missing');
 $started=false;$stop=false;
 foreach($x->query('//h3|//h4|//li') as $node){$text=cw_clean($node->textContent);if($node===$head||$text==='Campus crime logs'){$started=true;continue;}if(!$started)continue;if($text==='Archives'||str_contains($text,'Campus fire logs'))break;
  if($node->nodeName!=='li'||stripos($text,'No reports')!==false)continue;
  if(!preg_match('~\bOn (\d{1,2}/\d{1,2}/\d{4}),?\s*(.*)~is',$text,$m))continue;
  $date=DateTimeImmutable::createFromFormat('!n/j/Y',$m[1]);if(!$date)throw new RuntimeException('SFA date invalid');
  $narrative='On '.$m[1].', '.$m[2];$offense='Campus incident';foreach(['drug paraphernalia'=>'Drug paraphernalia','sexual assault'=>'Sexual assault','theft'=>'Theft','burglary'=>'Burglary','graffiti'=>'Graffiti','hit and run'=>'Hit and run','assault'=>'Assault'] as $needle=>$label)if(stripos($narrative,$needle)!==false){$offense=$label;break;}
  yield cw_row('sfa',hash('sha256',$narrative),$date->format('Y-m-d'),'Not published',$offense,'SFA campus, Nacogdoches — see details',null,null,['summary'=>$narrative,'datePrecision'=>'Log report date','scope'=>'University campus crime log']);
 }
}

function cw_kaufman_window(string $start,string $end,string $agency='kaufman'):iterable{
 $agencyId=['kaufman'=>'KaufmanCoTX','nolan'=>'NolanCoTX'][$agency]??throw new InvalidArgumentException('Unsupported incident agency');
 $snapshot=nw_collect($start,$end,$agencyId);
 if(count($snapshot['incidents'])>=200){
  if($start===$end)throw new RuntimeException('Daily feed may be truncated; collection is incomplete');
  $a=new DateTimeImmutable($start);$b=new DateTimeImmutable($end);$mid=$a->modify('+'.intdiv($a->diff($b)->days,2).' days');
  yield from cw_kaufman_window($start,$mid->format('Y-m-d'),$agency);yield from cw_kaufman_window($mid->modify('+1 day')->format('Y-m-d'),$end,$agency);return;
 }
 yield from $snapshot['incidents'];
}
function cw_kaufman_history(string $from,string $to,string $agency='kaufman'):array{
 require_once __DIR__.'/regional-collectors.php';$db=nw_database();$end=min($to,date('Y-m-d'));if($from>$end)return ['status'=>'local','message'=>'Future dates cannot be collected.'];
 $start=new DateTimeImmutable($from);if($start->diff(new DateTimeImmutable($end))->days>365)return ['status'=>'local','message'=>'Choose one year or less to collect additional history.'];
 while($start->format('Y-m-d')<=$end){$stop=min($end,$start->modify('+6 days')->format('Y-m-d'));$key=$start->format('Y-m-d').'/'.$stop;$q=$db->prepare('SELECT checked_at FROM regional_imports WHERE agency=? AND period=?');$q->execute([$agency,$key]);
 if(!$q->fetchColumn()){$lock=fopen(nw_data_directory().'/region-'.$agency.'.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))return ['status'=>'busy','message'=>'Collection in progress; saved reports are available.'];try{$r=cw_store($agency,$agency==='forney'?cw_forney($start->format('Y-m-d'),$stop):cw_kaufman_window($start->format('Y-m-d'),$stop,$agency),gmdate('c'));$q=$db->prepare('INSERT OR REPLACE INTO regional_imports VALUES(?,?,?,?)');$q->execute([$agency,$key,'complete',gmdate('c')]);return ['status'=>'collected','message'=>'Saved additional history; checking remaining dates…'];}catch(Throwable $e){return ['status'=>'unavailable','message'=>'Additional history could not be collected. Saved records remain available.'];}finally{flock($lock,LOCK_UN);fclose($lock);}}
 $start=(new DateTimeImmutable($stop))->modify('+1 day');}
 return ['status'=>'complete','message'=>'Requested dates checked. All collected reports remain saved.'];
}

function cw_forney(string $start,string $end):iterable{
 $params=['categories'=>'1:1759=1-8,10-18,20-21,23-25,27,29,31-33,35,42,46,48-49,51,53-56,58,62-63,65,67-74,76','start_date'=>$start,'end_date'=>$end,'offset'=>0,'limit'=>100,'zoom'=>10,'lat1'=>'32.814183','lat2'=>'32.638455','lng1'=>'-96.371337','lng2'=>'-96.521631'];
 $base='https://forneypdtx-transparency.connect.socrata.com/api/tickets/';
 $rows=[];$seen=[];$done=false;
 for($page=0;$page<100;$page++){$params['offset']=$page*100;$data=json_decode(cw_http($base.'details.json?'.http_build_query($params)),true,512,JSON_THROW_ON_ERROR);$batch=$data['api_data']['records']??null;
 if(!is_array($batch))throw new RuntimeException('Forney response changed');
 foreach($batch as $r)$seen[$r['ticket_id']]=$r;
 if(count($batch)<100){$done=true;break;}}
 if(!$done)throw new RuntimeException('Forney paging limit reached');$rows=array_values($seen);$params['offset']=0;
 $other=json_decode(cw_http($base.'other_pins_tickets.json?'.http_build_query($params)),true,512,JSON_THROW_ON_ERROR);if(!is_array($other)||!array_is_list($other)||count($other)>=1000)throw new RuntimeException('Forney unlocated response incomplete');
 foreach(array_merge($rows,$other) as $r){if(($r['category']??'')!=='TX1290100 - Forney Police Department'||empty($r['ticket_id'])||empty($r['ticket_created_at']))throw new RuntimeException('Invalid Forney record');
  $date=substr($r['ticket_created_at'],0,10);if($date<$start||$date>$end)throw new RuntimeException('Forney date mismatch');$geo=$r['location']['coordinates']??[];
  $lat=isset($geo[1])?round((float)$geo[1]/0.005)*0.005:null;$lng=isset($geo[0])?round((float)$geo[0]/0.005)*0.005:null;
  yield cw_row('forney',$r['ticket_id'],$date,substr($r['ticket_created_at'],11,5),$r['sub_category']??'Call type not published',$lat===null?'Location not published, Forney':'Approximate map area, Forney',$lat,$lng,['recordType'=>'Call for service','locationPrecision'=>'Public map grid; approximately 500 meters']);
 }
}

