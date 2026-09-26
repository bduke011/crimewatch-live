<?php
declare(strict_types=1);
require __DIR__.'/ads-lib.php';header('X-Content-Type-Options: nosniff');header('Cache-Control: no-store');
$name=$_GET['file']??'';if(!is_string($name)||!preg_match('/^[a-f0-9]{48}\.(jpg|png|webp)$/',$name)){http_response_code(404);exit;}
try{$allowed=false;foreach(cw_public_ads() as $ad)if($ad['image']===$name)$allowed=true;if(!$allowed)cw_admin();$path=cw_config()['dir'].'/images/'.$name;if(!is_file($path)){http_response_code(404);exit;}header('Content-Type: '.['jpg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp'][pathinfo($name,PATHINFO_EXTENSION)]);readfile($path);}catch(Throwable $e){http_response_code(503);}
