<?php
require __DIR__.'/bootstrap.php';
function pass_p2($ok,$name){if(!$ok)throw new RuntimeException('FAIL '.$name);echo 'PASS '.$name.PHP_EOL;}
$mis=new SN_MIS_Service($wpdb,1);$jobs=new SN_MIS_Jobs($wpdb,1);$model=new SN_MIS_Read_Model($wpdb);$p=$wpdb->prefix;
$wpdb->insert($p.'sn_mis_import_batches',['batch_code'=>'JOBS-'.bin2hex(random_bytes(3)),'title'=>'P2 fixture']);$batch=(int)$wpdb->insert_id;
$records=[];$random=random_int(1000,9999);for($i=0;$i<251;$i++){$phone='0998'.$random.str_pad((string)$i,3,'0',STR_PAD_LEFT);$records[]=['customer_phone'=>$phone,'normalized_phone'=>$phone,'customer_name'=>'=formula','source_code'=>'=1+1','row_status'=>'valid'];}
$mis->import_rows($batch,$records,'jobs.csv',true);$ids=array_column($mis->query(['batch_id'=>$batch],300)['rows'],'id');
// Freeze membership now; a later new row must not appear, a changed value is explicitly captured during the window.
$id=$jobs->start('export',['batch_id'=>$batch],false);
$extra='0999000'.random_int(1000,9999);$mis->import_rows($batch,[['customer_phone'=>$extra,'normalized_phone'=>$extra,'customer_name'=>'later','row_status'=>'valid']],'later.csv',true);
$format=static fn($r,$history)=>[$r['id'],$r['source_code'],$r['normalized_phone'],$r['customer_name'],$r['logical_state']];
$r=$jobs->step($id,$format);pass_p2((int)$r['processed']===100 && $r['status']==='running','large export checkpoints after a bounded first step');
// Simulate lost DB checkpoint after file append; retry must truncate back to last saved byte offset.
if(property_exists($wpdb,'fail_pattern')) {
    $wpdb->fail_pattern='UPDATE `'.$p.'sn_mis_jobs`';try{$jobs->step($id,$format);throw new RuntimeException('missing failure');}catch(Throwable $e){pass_p2($e->getMessage()!=='missing failure','failed job checkpoint surfaces a retryable error');}$wpdb->fail_pattern='';
}
while(($r=$jobs->step($id,$format))['status']!=='ready'){}
$path=$jobs->download($id);$zip=new ZipArchive();$zip->open($path);$xml=$zip->getFromName('xl/worksheets/sheet1.xml');$zip->close();
pass_p2(substr_count($xml,'<row r=')===252 && !str_contains($xml,$extra),'resumed export has frozen membership without duplicate or missing rows');
pass_p2(str_contains($xml,'=1+1') && !str_contains($xml,'<f>') && str_contains($xml,$records[0]['normalized_phone']),'asynchronous XLSX preserves leading zeroes and inert formulas');
try{(new SN_MIS_Jobs($wpdb,999999))->download($id);throw new RuntimeException('owner bypass');}catch(Throwable $e){pass_p2($e->getMessage()!=='owner bypass','another user cannot read an export or job');}
$jobs->cancel($id);pass_p2(!is_file($path),'cancelling removes the private export file');
// Projection is explicitly not authoritative for returning a number.
$target=(int)$ids[0];$mis->assign([$target],101,['key'=>'p2','label'=>'P2'],'cache fixture');$stats=$mis->stats(['batch_id'=>$batch]);
$di=(int)$wpdb->get_var($wpdb->prepare('SELECT id FROM '.$p."sn_distribution_items WHERE source_type='mis_data_row' AND source_id=%d",$target));$wpdb->insert($p.'sn_invoices',['lead_id'=>900000000+$di,'status'=>'cancelled']);
$back=$mis->return_rows([$target],'stale cache');pass_p2($stats['MANAGER_HELD']===1 && $back['returned']===0 && $back['blocked']===1,'cached held count never authorizes return after a new invoice');
$wpdb->query('DELETE FROM '.$p.'sn_mis_row_index WHERE row_id IN ('.implode(',',array_map('intval',$ids)).')');SN_MIS_Read_Model::bump();
pass_p2($mis->stats(['batch_id'=>$batch])['PENDING']>=251,'unindexed legacy records are explicitly pending rather than advertised as RAW');
if(property_exists($wpdb,'fail_pattern')) {
    $wpdb->fail_pattern='INSERT INTO '.$p.'sn_mis_index_dirty';$assigned=$mis->assign([(int)$ids[1]],101,['key'=>'p2','label'=>'P2'],'cache outage');$wpdb->fail_pattern='';
    pass_p2($assigned['assigned']===1 && get_option('sn_mis_index_needs_rebuild',0) && $mis->stats(['batch_id'=>$batch])['PENDING']===252,'derived cache failure preserves a committed assignment and marks statistics pending');
}
$job=$jobs->start('index');
if(property_exists($wpdb,'fail_pattern')) {
    $wpdb->fail_pattern='INSERT INTO '.$p.'sn_mis_row_index';try{$jobs->step($job);throw new RuntimeException('missing failure');}catch(Throwable $e){pass_p2($e->getMessage()!=='missing failure' && (int)$jobs->get($job)['cursor_id']===0,'failed index checkpoint never advances the resumable cursor');}$wpdb->fail_pattern='';
}
$original=[];foreach(['sn_mis_data_rows','sn_distribution_items','sn_distribution_item_logs','sn_mis_events'] as $table)$original[$table]=$wpdb->get_results('SELECT * FROM '.$p.$table.' ORDER BY id',ARRAY_A);
$max=0;do{$r=$jobs->step($job);if(++$max>1000)throw new RuntimeException('index stuck');}while($r['status']!=='ready');
pass_p2($model->coverage()['pending']===0,'resumable index preparation covers legacy and new source rows');
$audit=$jobs->start('audit');do{$r=$jobs->step($audit);}while($r['status']!=='ready');$result=json_decode($r['result_json'],true);
pass_p2(($result['counts']['invoice']??0)>=1 && count($result['samples']['invoice']??[])<=20,'read-only audit reports invoice evidence and capped internal-ID samples');
$unchanged=true;foreach($original as $table=>$rows)$unchanged=$unchanged && $rows===$wpdb->get_results('SELECT * FROM '.$p.$table.' ORDER BY id',ARRAY_A);
pass_p2($unchanged,'index preparation and audit preserve every source row item log and event byte');
// Include the tools page in the real renderer, with saved preferences and named reports.
update_user_meta(1,'sn_mis_preferences_v3',['page_size'=>25,'theme'=>'dark','density'=>'compact','chart_days'=>7,'refresh'=>0,'columns'=>['phone','state','actions']]);
update_user_meta(1,'sn_mis_named_reports_v3',['named'=>['name'=>'آزمون شخصی','filters'=>['batch_id'=>$batch,'report_mode'=>'events']]]);
$plugin=new SN_Plugin();$_GET=['sn_mis_page'=>'tools'];$html=$plugin->render_mis_workspace();pass_p2(str_contains($html,'data-job-api') && str_contains($html,'sn-mis-theme-dark') && !str_contains($html,'is-error'),'tools page renders resumable jobs and personal display preferences');
$_GET=['sn_mis_page'=>'reports','saved_report'=>'named'];$html=$plugin->render_mis_workspace();pass_p2(str_contains($html,'آزمون شخصی') && str_contains($html,'رویدادهای تاریخی') && str_contains($html,'start_export'),'named report restores event mode and asynchronous export controls');
$_GET=[];delete_user_meta(1,'sn_mis_preferences_v3');delete_user_meta(1,'sn_mis_named_reports_v3');
sn_mis_test_write('jobs-and-cache-summary.json',['assertions'=>13,'index'=>$model->coverage(),'audit'=>$result]);
