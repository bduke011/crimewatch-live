<?php
declare(strict_types=1);
require __DIR__.'/ads-lib.php';
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
try {
    cw_admin();
    $db = new PDO('sqlite:'.cw_config()['dir'].'/pdf-jobs.sqlite', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $db->exec('PRAGMA busy_timeout=5000');
    $db->exec('CREATE TABLE IF NOT EXISTS jobs(id INTEGER PRIMARY KEY,action TEXT NOT NULL,state TEXT NOT NULL,created INTEGER NOT NULL,started INTEGER,finished INTEGER,result TEXT)');
    $action = $_GET['action'] ?? 'status';
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        cw_csrf();
        if (!in_array($action, ['check','import'], true)) cw_json(['error'=>'Unknown action'],400);
        $db->exec('BEGIN IMMEDIATE');
        $busy=$db->query("SELECT id FROM jobs WHERE state IN ('queued','running') LIMIT 1")->fetch();
        if (!$busy) {
            if ($action==='import') {
                $check=$db->query("SELECT * FROM jobs ORDER BY id DESC LIMIT 1")->fetch();
                $result=$check ? json_decode($check['result']??'{}',true) : [];
                if (!$check || $check['action']!=='check' || !in_array($check['state'],['complete','partial'],true) || empty($result['available']) || $check['finished']<time()-1800) {
                    $db->exec('ROLLBACK');cw_json(['error'=>'Check for new PDFs first. Checks are valid for 30 minutes.'],409);
                }
            }
            $last=(int)$db->query('SELECT COALESCE(MAX(created),0) FROM jobs')->fetchColumn();
            if ($action==='check' && $last>time()-30) {$db->exec('ROLLBACK');cw_json(['error'=>'Please wait 30 seconds between checks.'],429);}
            $db->prepare("INSERT INTO jobs(action,state,created) VALUES(?,'queued',?)")->execute([$action,time()]);
        }
        $db->exec('COMMIT');
        cw_audit('pdf-'.$action, $busy ? 'already queued' : (string)$db->lastInsertId());
    } elseif ($_SERVER['REQUEST_METHOD']!=='GET' || $action!=='status') cw_json(['error'=>'Method not allowed'],405);
    $job=$db->query('SELECT * FROM jobs ORDER BY id DESC LIMIT 1')->fetch() ?: null;
    if ($job) $job['result']=json_decode($job['result']??'{}',true);
    $statusFile=(getenv('CW_JAIL_DIR')?:'/home1/crimewatch/jail-service/data').'/status.json';
    $status=is_file($statusFile)?json_decode(file_get_contents($statusFile),true):[];
    cw_json(['job'=>$job,'lastImportCheck'=>$status['checkedAt']??null]);
} catch(Throwable $e) {
    if(isset($db)) {try{$db->exec('ROLLBACK');}catch(Throwable $ignored){}}
    error_log('CrimeWatch PDF admin: '.get_class($e));
    cw_json(['error'=>'Report controls are temporarily unavailable. Please try again.'],503);
}
