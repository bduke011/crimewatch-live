<?php
if(PHP_SAPI!=='cli')exit;
$home='/home1/crimewatch';$out=$home.'/public_html/data/fbi';$lock=fopen($home.'/fbi-2026-setup.lock','c');if(!flock($lock,LOCK_EX|LOCK_NB))exit;
if(is_file($home.'/fbi-2026.json')&&!is_file($out.'/2026.json')){
 $gz=$home.'/fbi-2026.sqlite.gz';$f=fopen($gz,'wb');for($i=0;$i<6;$i++){if(!is_file($home.'/fbi-2026-'.$i.'.b64')){fclose($f);exit;}fwrite($f,base64_decode(file_get_contents($home.'/fbi-2026-'.$i.'.b64'),true));}fclose($f);
 if(hash_file('sha256',$gz)!=='3084c304ea142e51ebaf8ac4df53ba4a3d9449c356afa484a032ec6ea584b067')throw new RuntimeException('Checksum mismatch');$in=gzopen($gz,'rb');$f=fopen($out.'/2026.sqlite.new','wb');while(!gzeof($in))fwrite($f,gzread($in,1048576));gzclose($in);fclose($f);$db=new PDO('sqlite:'.$out.'/2026.sqlite.new');if($db->query('PRAGMA quick_check')->fetchColumn()!=='ok')throw new RuntimeException('Invalid database');$db=null;rename($out.'/2026.sqlite.new',$out.'/2026.sqlite');copy($home.'/fbi-2026.json',$out.'/2026.json');for($i=0;$i<6;$i++)unlink($home.'/fbi-2026-'.$i.'.b64');unlink($gz);echo "2026 search installed\n";
}
$raw=$home.'/fbi-raw/TX-2026-master.txt.gz';if(!is_file($raw)){
 $key='master_files/nibrs/nibrs-2026.zip';$c=curl_init('https://cde.ucr.cjis.gov/LATEST/s3/signedurl?key='.rawurlencode($key));curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>60]);$urls=json_decode(curl_exec($c),true);$zip=$home.'/fbi-raw/nibrs-2026-national.zip';if(!is_file($zip)){$f=fopen($zip.'.part','wb');$c=curl_init($urls[$key]);curl_setopt_array($c,[CURLOPT_FILE=>$f,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_TIMEOUT=>600]);$ok=curl_exec($c);$status=curl_getinfo($c,CURLINFO_HTTP_CODE);fclose($f);if(!$ok||$status!==200)throw new RuntimeException('National download failed');rename($zip.'.part',$zip);}
 $z=new ZipArchive();if($z->open($zip)!==true)throw new RuntimeException('Invalid master ZIP');$in=$z->getStream($z->getNameIndex(0));$f=gzopen($raw.'.part','wb6');$n=0;while(($line=fgets($in))!==false){if(substr($line,4,2)==='TX'){gzwrite($f,$line);$n++;}}fclose($in);gzclose($f);$z->close();if($n!==3475814)throw new RuntimeException('Texas segment count differs from verified snapshot');rename($raw.'.part',$raw);file_put_contents($raw.'.sha256',hash_file('sha256',$raw));echo "Texas 2026 raw saved: $n segments\n";
}

