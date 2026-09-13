<?php
declare(strict_types=1);
ini_set('display_errors','0');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=60');
if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET',['GET','HEAD'],true)) {http_response_code(405);header('Allow: GET, HEAD');echo json_encode(['error'=>'Method not allowed']);exit;}
try {
    require_once __DIR__.'/collector.php';
    $agency=$_GET['agency'] ?? 'polk';
    $snapshot=$agency==='polk'?nw_cached():cw_snapshot(is_string($agency)?$agency:'invalid');
    $data=nw_query_archive($_GET,$snapshot);
    if(($_GET['period'] ?? '')==='archive') {
        $history=($agency==='polk')?nw_history_backfill($_GET['from'] ?? '',$_GET['to'] ?? ''):cw_history($agency,$_GET['from'] ?? '',$_GET['to'] ?? '');
        if($history['status']==='collected') $data=nw_query_archive($_GET,$snapshot);
        $data['history']=$history;
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'HEAD') echo json_encode($data,JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR);
} catch (InvalidArgumentException $e) {
    http_response_code(400);header('Cache-Control: no-store');echo json_encode(['error'=>$e->getMessage()]);
} catch (Throwable $e) {
    error_log('CrimeWatch API: '.$e->getMessage());http_response_code(503);header('Cache-Control: no-store');echo json_encode(['error'=>'Public reports are temporarily unavailable. Please try again.']);
}
