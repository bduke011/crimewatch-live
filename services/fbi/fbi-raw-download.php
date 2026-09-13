<?php
if(PHP_SAPI!=='cli')exit;
$root='/home1/crimewatch/fbi-raw';if(!is_dir($root))mkdir($root,0750,true);
$lock=fopen($root.'/download.lock','c');if(!flock($lock,LOCK_EX|LOCK_NB))exit;
function geturl($url){$c=curl_init($url);curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_TIMEOUT=>60]);$s=curl_exec($c);if(curl_getinfo($c,CURLINFO_HTTP_CODE)!==200)throw new RuntimeException('Download lookup failed');return $s;}
foreach(range(2025,1997) as $y){$file=$root.'/TX-'.$y.'.zip';if(is_file($file)&&is_file($file.'.sha256'))continue;
try{$key='nibrs/incident/'.$y.'/TX-'.$y.'.zip';$signed=json_decode(geturl('https://cde.ucr.cjis.gov/LATEST/s3/signedurl?key='.rawurlencode($key)),true);$f=fopen($file.'.part','wb');$c=curl_init($signed[$key]);curl_setopt_array($c,[CURLOPT_FILE=>$f,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_TIMEOUT=>600]);$ok=curl_exec($c);$status=curl_getinfo($c,CURLINFO_HTTP_CODE);fclose($f);if(!$ok||$status!==200)throw new RuntimeException('Download failed');$z=new ZipArchive();if($z->open($file.'.part',ZipArchive::CHECKCONS)!==true)throw new RuntimeException('Invalid ZIP');$z->close();rename($file.'.part',$file);file_put_contents($file.'.sha256',hash_file('sha256',$file));echo "$y saved\n";}catch(Throwable $e){echo "$y: ".$e->getMessage()."\n";}}
file_put_contents($root.'/status.json',json_encode(['files'=>count(glob($root.'/*.zip.sha256')),'updatedAt'=>gmdate('c')]));
