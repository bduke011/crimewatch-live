<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: public,max-age=300');header('X-Content-Type-Options: nosniff');ini_set('memory_limit','256M');
try{
 $dir=__DIR__.'/data/fbi';$catalog=json_decode(file_get_contents($dir.'/collections.json'),true,512,JSON_THROW_ON_ERROR);
 if(($_GET['action']??'')==='catalog'){echo json_encode($catalog,JSON_THROW_ON_ERROR);exit;}
 $table=(string)($_GET['table']??$catalog[0]['table']);$m=null;foreach($catalog as $item)if($item['table']===$table)$m=$item;if(!$m){http_response_code(400);throw new RuntimeException('Unknown collection');}
 $d=json_decode(gzdecode(base64_decode(file_get_contents($dir.'/'.$m['file'].'.b64'),true)),true,512,JSON_THROW_ON_ERROR);$cols=$d['columns'];$yi=array_search('data_year',$cols,true);if($yi===false)$yi=array_search('year',$cols,true);
 $year=(string)($_GET['year']??'');$query=strtolower(substr((string)($_GET['q']??''),0,100));$rows=[];
 foreach($d['rows'] as $r){if($year!==''&&(string)$r[$yi]!==$year)continue;if($query!==''&&!str_contains(strtolower(implode(' ',array_map(fn($v)=>(string)$v,$r))),$query))continue;$rows[]=$r;}
 usort($rows,fn($a,$b)=>(int)$b[$yi]<=>(int)$a[$yi]);$total=count($rows);$page=max(1,min((int)($_GET['page']??1),max(1,(int)ceil($total/50))));
 echo json_encode(['meta'=>$m,'columns'=>$cols,'rows'=>array_slice($rows,($page-1)*50,50),'total'=>$total,'page'=>$page,'pages'=>(int)ceil($total/50)],JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE);
}catch(Throwable $e){header('Cache-Control: no-store');if(http_response_code()===200)http_response_code(503);error_log('FBI collections: '.$e->getMessage());echo json_encode(['error'=>'This collection is temporarily unavailable.']);}
