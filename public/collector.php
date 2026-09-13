<?php
declare(strict_types=1);
require_once __DIR__.'/archive.php';
// No credentials are required: only the public Citizen Connect map is read.
function nw_category(string $offense): string {
    $groups = [
        'person' => '/ASSAULT|ROBBERY|STALK|HOMICIDE|MURDER|KIDNAP|UNLAWFUL RESTRAINT|TERRORISTIC THREAT|INJURY CHILD|DEADLY CONDUCT/',
        'property' => '/THEFT|BURGL|CRIMINAL MISCHIEF|VANDAL|ARSON|CREDIT CARD|FRAUD|GRAFFITI|UNAUTHORIZED USE OF A VEHICLE|STOLEN PROPERTY|TRESPASS/',
        'drugs' => '/POSS CS|POSS MARI|DRUG|INTOX|DWI|ALCOHOL|DEL CS|OVERDOSE/'
    ];
    foreach ($groups as $category => $pattern) if (preg_match($pattern, strtoupper($offense))) return $category;
    return 'other';
}
function nw_parse(string $body, string $start, string $end, string $agencyId='PolkCoTX'): array {
    if (stripos($body, '<!DOCTYPE') !== false || stripos($body, '<!ENTITY') !== false) throw new RuntimeException('Unexpected XML declarations.');
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NONET);
    if ($xml === false || $xml->getName() !== 'markers') throw new RuntimeException('Invalid source response.');
    $records = [];
    foreach ($xml->marker as $marker) {
        $r = []; foreach ($marker->attributes() as $key => $value) $r[$key] = trim((string)$value);
        if (($r['AgencyID'] ?? '') !== $agencyId) throw new RuntimeException('Unexpected agency.');
        $date = DateTimeImmutable::createFromFormat('!m/d/Y', $r['IncidentDate'] ?? '');
        if (!$date || $date->format('m/d/Y') !== ($r['IncidentDate'] ?? '') || empty($r['IncidentNumber'])) throw new RuntimeException('Incomplete source record.');
        $r['Offense']=($r['Offense'] ?? '')!==''?$r['Offense']:'Incident type not published';
        $iso = $date->format('Y-m-d');
        if ($iso < $start || $iso > $end) throw new RuntimeException('Source date filter mismatch.');
        $id = substr(hash('sha256', $r['AgencyID'].'|'.$r['IncidentNumber'].'|'.($r['SequenceNumber'] ?? '1')), 0, 20);
        $lat = is_numeric($r['LocationLatitude'] ?? '') ? (float)$r['LocationLatitude'] : null;
        $lng = is_numeric($r['LocationLongitude'] ?? '') ? (float)$r['LocationLongitude'] : null;
        // Exclude missing/invalid coordinates from the map, but keep the report in the list.
        // Nolan uses a different regional envelope; implausible source points remain list-only.
        $bounds = $agencyId==='NolanCoTX' ? [31.9,32.7,-100.9,-99.9] : [29,33,-97,-93];
        if ($lat === null || $lng === null || $lat < $bounds[0] || $lat > $bounds[1] || $lng < $bounds[2] || $lng > $bounds[3]) { $lat = null; $lng = null; }
        $location = $r['Location'] ?? 'Location not published';
        // Remove source property labels that may include a person's name before the street address.
        if (strpos($location, ' - ') !== false) $location = substr($location, strrpos($location, ' - ') + 3);
        $records[$id] = ['id'=>$id,'date'=>$iso,'time'=>preg_match('/^\d{2}:\d{2}$/',$r['IncidentTime'] ?? '') ? $r['IncidentTime'] : 'Not published','offense'=>$r['Offense'],'offenseCode'=>$r['OffenseCode'] ?? '','category'=>nw_category($r['Offense']),'location'=>$location,'lat'=>$lat === null ? null : round($lat,3),'lng'=>$lng === null ? null : round($lng,3),'source'=>$r];
    }
    return array_values($records);
}
function nw_collect(?string $from=null, ?string $to=null,string $agencyId='PolkCoTX'): array {
    if (!extension_loaded('curl') || !extension_loaded('SimpleXML')) throw new RuntimeException('cURL and SimpleXML are required.');
    $today = new DateTimeImmutable('today',new DateTimeZone('America/Chicago'));
    $start = $from ?? $today->modify('-30 days')->format('Y-m-d'); $end = $to ?? $today->format('Y-m-d');
    $ch = curl_init();
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>18,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_COOKIEFILE=>'',CURLOPT_FOLLOWLOCATION=>false,CURLOPT_USERAGENT=>'CrimeWatch-PublicIncidentExplorer/1.0',CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS]);
    $request = function(string $url, ?array $post=null) use ($ch): string {
        curl_setopt($ch,CURLOPT_URL,$url);
        if ($post !== null) {curl_setopt($ch,CURLOPT_POST,true);curl_setopt($ch,CURLOPT_POSTFIELDS,http_build_query($post));} else curl_setopt($ch,CURLOPT_HTTPGET,true);
        $body = curl_exec($ch); $status = curl_getinfo($ch,CURLINFO_HTTP_CODE);
        if ($body === false || $status !== 200 || strlen($body) > 8000000) throw new RuntimeException('Public source did not return a valid response.');
        return $body;
    };
    try {
        $base='https://cc.southernsoftware.com/incidentpointmap/';
        $request($base.'index.php?AgencyID='.rawurlencode($agencyId));
        $request($base.'index.php',$from===null?['begindate'=>'','enddate'=>'','customRadioInline1'=>'previous30days']:['begindate'=>(new DateTimeImmutable($start))->format('m/d/Y'),'enddate'=>(new DateTimeImmutable($end))->format('m/d/Y'),'customRadioInline1'=>'customDateRange']);
        $rows=nw_parse($request($base.'google_getincidentpoints.php'),$start,$end,$agencyId);
    } finally {$ch = null;}
    return ['incidents'=>$rows,'fetchedAt'=>gmdate('c'),'range'=>['start'=>$start,'end'=>$end],'stale'=>false];
}
function nw_cached(): array {
    $directory=nw_data_directory();
    if (!is_dir($directory) && !mkdir($directory,0750,true)) throw new RuntimeException('Cache directory unavailable.');
    $file=$directory.'/incidents.json';
    $read=function() use ($file) {return is_file($file) ? json_decode((string)file_get_contents($file),true) : null;};
    $data=$read();$slot=nw_latest_collection_slot();
    if ($data && (strtotime($data['fetchedAt'] ?? '') ?: 0)>=$slot->getTimestamp()) {nw_archive_snapshot($data);return $data;}
    $lock=fopen($directory.'/refresh.lock','c');
    if (!$lock) throw new RuntimeException('Refresh lock unavailable.');
    if (flock($lock,LOCK_EX|LOCK_NB)) {
        try {
            $newer=$read();
            if ($newer && (strtotime($newer['fetchedAt'] ?? '') ?: 0)>=$slot->getTimestamp()) {nw_archive_snapshot($newer);return $newer;}
            $retryFile=$directory.'/last-attempt';
            if (!is_file($retryFile) || time()-filemtime($retryFile)>=600) {
                touch($retryFile);
                try {
                    $fresh=nw_collect();
                    nw_archive_snapshot($fresh);
                    $temporary=$file.'.tmp';
                    $json=json_encode($fresh,JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR);
                    if (file_put_contents($temporary,$json,LOCK_EX)===false || !rename($temporary,$file)) throw new RuntimeException('Unable to save cache.');
                    return $fresh;
                } catch (Throwable $e) {error_log('CrimeWatch collection failed: '.$e->getMessage());}
            }
        } finally {flock($lock,LOCK_UN);fclose($lock);}
    } else fclose($lock);
    if (!$data) throw new RuntimeException('No incident data is available yet.');
    nw_archive_snapshot($data);$data['stale']=true;return $data;
}
function nw_latest_collection_slot(?DateTimeImmutable $now=null): DateTimeImmutable {
    $zone=new DateTimeZone('America/Chicago');$now=($now ?? new DateTimeImmutable('now',$zone))->setTimezone($zone);
    $hour=(int)$now->format('G');$scheduledHour=$hour>=12?12:($hour>=5?5:0);
    return $now->setTime($scheduledHour,0,0);
}
function nw_history_backfill(string $from,string $to): array {
    if($from===''||$to==='') return ['status'=>'local','message'=>'Choose both dates to retrieve history not already saved.'];
    $start=new DateTimeImmutable($from);$end=new DateTimeImmutable($to);$today=new DateTimeImmutable('today',new DateTimeZone('America/Chicago'));
    if($end>$today) $end=$today;
    if($start>$end) return ['status'=>'local','message'=>'Only saved results are shown. Future dates cannot be collected.'];
    if($start->diff($end)->days>365) return ['status'=>'local','message'=>'Searching saved history. Choose a range of one year or less to collect missing records from the source.'];
    $db=nw_database();
    $coverage=$db->prepare('SELECT range_start,range_end FROM collections WHERE fetched_at>=? AND range_end>=? AND range_start<=? ORDER BY range_start,range_end');
    $coverage->execute([gmdate('c',time()-7*86400),$from,$end->format('Y-m-d')]);
    $cursor=$start;$gapEnd=$end;
    foreach($coverage->fetchAll() as $range) {
        if($range['range_start']>$cursor->format('Y-m-d')) {$gapEnd=(new DateTimeImmutable($range['range_start']))->modify('-1 day');break;}
        if($range['range_end']>=$cursor->format('Y-m-d')) $cursor=(new DateTimeImmutable($range['range_end']))->modify('+1 day');
        if($cursor>$end) return ['status'=>'complete','message'=>'Requested dates have been checked against the public source. Saved records are retained indefinitely.'];
    }
    $batchEnd=$cursor->modify('+30 days');if($batchEnd>$gapEnd)$batchEnd=$gapEnd;if($batchEnd>$end)$batchEnd=$end;
    $lock=fopen(nw_data_directory().'/refresh.lock','c');
    if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)){if($lock)fclose($lock);return ['status'=>'busy','message'=>'Another collection is running. Saved results are available; try Refresh shortly.'];}
    try {
        $historical=nw_collect($cursor->format('Y-m-d'),$batchEnd->format('Y-m-d'));
        nw_archive_snapshot($historical,true);
        return ['status'=>'collected','message'=>'Saved '.count($historical['incidents']).' source records for '.$cursor->format('M j, Y').'–'.$batchEnd->format('M j, Y').'. Checking the rest of your date range…','range'=>$historical['range']];
    } catch(Throwable $e) {error_log('CrimeWatch historical collection: '.$e->getMessage());return ['status'=>'unavailable','message'=>'Could not retrieve additional source history. Showing saved results; try Refresh later.'];}
    finally {flock($lock,LOCK_UN);fclose($lock);}
}
if (PHP_SAPI==='cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '')===__FILE__) {
    $failed=false;try{$data=nw_cached();echo json_encode(['polk'=>['records'=>count($data['incidents']),'fetchedAt'=>$data['fetchedAt'],'stale'=>$data['stale']]]).PHP_EOL;$failed=$data['stale'];}catch(Throwable $e){$failed=true;fwrite(STDERR,$e->getMessage().PHP_EOL);}
    try{cw_collect_regions();nw_backup_daily();}catch(Throwable $e){$failed=true;fwrite(STDERR,$e->getMessage().PHP_EOL);}exit($failed?1:0);
}
