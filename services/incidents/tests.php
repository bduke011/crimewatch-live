<?php
declare(strict_types=1);
$temporary=sys_get_temp_dir().'/crimewatch-incidents-'.bin2hex(random_bytes(8));
mkdir($temporary,0700,true);
putenv('CRIMEWATCH_DATA_DIR='.$temporary);
require __DIR__.'/../../public/collector.php';
function check(bool $condition,string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function snapshot(array $rows,string $at,string $start='2026-08-22',string $end='2026-09-21'): array {
    return ['incidents'=>$rows,'fetchedAt'=>$at,'range'=>['start'=>$start,'end'=>$end],'stale'=>false];
}
function row(string $id): array {
    return ['id'=>$id,'date'=>'2026-09-19','time'=>'12:00','offense'=>'TEST INCIDENT','offenseCode'=>'','category'=>'other','location'=>'Test location','lat'=>30.79,'lng'=>-94.9];
}
try {
    // A genuinely empty first collection is still valid.
    nw_archive_snapshot(snapshot([],'2026-09-20T00:00:00+00:00'));
    $good=snapshot([row('one'),row('two')],'2026-09-20T05:00:00+00:00');
    nw_archive_snapshot($good);
    $db=nw_database();
    foreach([false,true] as $historical) {
        $rejected=false;
        try {nw_archive_snapshot(snapshot([],'2026-09-21T05:00:00+00:00'),$historical);}
        catch(RuntimeException $e) {$rejected=str_contains($e->getMessage(),'Empty incident response');}
        check($rejected,'Empty overlapping current/historical collection must be rejected.');
        check((int)$db->query("SELECT COUNT(*) FROM incidents WHERE source_state='listed'")->fetchColumn()===2,'Rejection must preserve listed records.');
        check($db->query("SELECT value FROM collection_state WHERE name='fetchedAt'")->fetchColumn()===$good['fetchedAt'],'Rejection must preserve last successful time.');
        check((int)$db->query('SELECT COUNT(*) FROM collections')->fetchColumn()===2,'Rejected collection must not claim source coverage.');
    }
    // Valid nonempty updates still detect individually missing records.
    $good=snapshot([row('one')],'2026-09-20T12:00:00+00:00');
    nw_archive_snapshot($good);
    check($db->query("SELECT source_state FROM incidents WHERE id='two'")->fetchColumn()==='not_listed','Ordinary source disappearance must remain visible in archive metadata.');
    nw_archive_snapshot(snapshot([],'2026-09-21T05:00:00+00:00','2025-01-01','2025-01-31'),true);
    // During retry throttling, a stale successful cache remains searchable and marked stale.
    file_put_contents($temporary.'/incidents.json',json_encode($good));
    touch($temporary.'/last-attempt');
    $cached=nw_cached();
    check($cached['stale']===true && count($cached['incidents'])===1,'Keep the last successful cache visible during collection failure.');
    check($cached['fetchedAt']===$good['fetchedAt'],'Do not relabel old reports as freshly verified.');
    check(json_decode(file_get_contents($temporary.'/incidents.json'),true)===$good,'Fallback must not replace the successful cache.');
    // Recovering an already-poisoned empty cache must never mark it successful again.
    $bad=snapshot([],gmdate('c'));
    file_put_contents($temporary.'/incidents.json',json_encode($bad));
    $rejected=false;
    try {nw_cached();}catch(RuntimeException $e){$rejected=true;}
    check($rejected,'Previously cached false-empty responses must not be accepted.');
    check($db->query("SELECT source_state FROM incidents WHERE id='one'")->fetchColumn()==='listed','Rejected cache must not hide saved incidents.');
    echo "PASS: empty-response protection, historical ranges, source removals, stale cache, poisoned-cache rejection\n";
} finally {
    // Remove only this test's randomly created temporary files (SQLite may be open on Windows).
    foreach(glob($temporary.'/*') as $file) @unlink($file);
    @rmdir($temporary);
}
