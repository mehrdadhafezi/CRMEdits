<?php
if (PHP_SAPI!=='cli') { http_response_code(403); exit; }
if (getenv('SN_MIS_TEST_ALLOW_RESET')!=='YES') { fwrite(STDERR,"Disposable *_test database and SN_MIS_TEST_ALLOW_RESET=YES required.\n"); exit(2); }
foreach(['core.php','roles-and-render.php','large-and-races.php','conversion-and-history.php','snapshots-and-edit.php','advanced-plans.php','jobs-and-cache.php','phone-invoice-and-indexes.php','setup-and-management.php','ux-and-reporting.php'] as $script) {
    $process=proc_open([PHP_BINARY,__DIR__.'/'.$script],[0=>STDIN,1=>['pipe','w'],2=>['redirect',1]],$pipes);
    if (!is_resource($process)) { exit(1); }
    $output=stream_get_contents($pipes[1]); fclose($pipes[1]);
    fwrite(STDOUT,(string)$output);
    if (proc_close($process)!==0) { exit(1); }
}
