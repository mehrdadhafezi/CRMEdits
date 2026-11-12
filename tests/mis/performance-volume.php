<?php
require __DIR__.'/bootstrap.php';
function perf_assert($ok,$label) { if(!$ok)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label.PHP_EOL; }
$p=$wpdb->prefix;if(property_exists($wpdb,'capture_queries')) { $wpdb->capture_queries=false;$wpdb->test_queries=[]; }
if(!getenv('SN_MIS_PERF_REUSE')) {
foreach(['mis_job_rows','mis_jobs','mis_index_dirty','mis_row_index','mis_record_meta','mis_claims','mis_events','distribution_item_logs','distribution_items','mis_distribution_plan_items','mis_distribution_plans','mis_lead_pool','mis_data_rows','mis_import_batches','leads','invoices'] as $t)$wpdb->query('DELETE FROM '.$p.'sn_'.$t);
$wpdb->insert($p.'sn_mis_import_batches',['batch_code'=>'PERF-100K-'.bin2hex(random_bytes(3)),'title'=>'100000 synthetic records','import_status'=>'imported']);$batch=(int)$wpdb->insert_id;
$start=microtime(true);
for($offset=0;$offset<100000;$offset+=1000) {
    $values=[];for($i=$offset;$i<$offset+1000;$i++) { $phone='099'.str_pad((string)$i,8,'0',STR_PAD_LEFT);$manager=$i<50000?'101':'NULL';$values[]="({$batch},".($i+1).",'Synthetic {$i}','{$phone}','{$phone}','valid',{$manager},'2026-10-01 10:00:00')"; }
    $ok=$wpdb->query('INSERT INTO '.$p.'sn_mis_data_rows (batch_id,`row_number`,customer_name,customer_phone,normalized_phone,row_status,assigned_manager_user_id,created_at) VALUES '.implode(',',$values));if($ok===false)throw new RuntimeException($wpdb->last_error); 
}
$first=(int)$wpdb->get_var('SELECT MIN(id) FROM '.$p.'sn_mis_data_rows');
$mis->checked($wpdb->query("INSERT INTO {$p}sn_distribution_items (source_type,source_id,batch_id,current_owner_user_id,current_owner_position,status) SELECT 'mis_data_row',id,batch_id,101,'sales_manager','assigned_to_role' FROM {$p}sn_mis_data_rows WHERE assigned_manager_user_id=101"));
$mis->checked($wpdb->query("INSERT INTO {$p}sn_distribution_items (source_type,source_id,batch_id,current_owner_user_id,current_owner_position,status) SELECT 'unrelated_test',id,batch_id,101,'seller','assigned_to_role' FROM {$p}sn_mis_data_rows LIMIT 50000"));
$mis->checked($wpdb->query("INSERT INTO {$p}sn_distribution_item_logs (item_id,action,actor_user_id,to_user_id,to_position,created_at) SELECT id,'mis_manager_assignment',1,101,'sales_manager','2026-10-01 10:00:00' FROM {$p}sn_distribution_items WHERE source_type='mis_data_row'"));
$mis->checked($wpdb->query("INSERT INTO {$p}sn_distribution_item_logs (item_id,action,actor_user_id,to_user_id,to_position,created_at) SELECT id,'test_unrelated',1,101,'seller','2026-10-01 10:00:00' FROM {$p}sn_distribution_items WHERE source_type='unrelated_test'"));
$mis->checked($wpdb->query("INSERT INTO {$p}sn_mis_events (row_id,batch_id,operation_id,event_type,new_manager_id,branch_key,branch_label,campaign_code,actor_user_id,created_at) SELECT id,batch_id,'perf-fixture','assigned',101,'perf','Synthetic','perf',1,'2026-10-01 10:00:00' FROM {$p}sn_mis_data_rows WHERE assigned_manager_user_id=101"));
// 100k unrelated invoices plus 20k virtual invoices: the correlated RC1 predicate scanned the former for each source row.
$mis->checked($wpdb->query("INSERT INTO {$p}sn_invoices (lead_id,status) SELECT 700000000+id,'draft' FROM {$p}sn_mis_data_rows"));
$mis->checked($wpdb->query("INSERT INTO {$p}sn_invoices (lead_id,status) SELECT 900000000+id,'cancelled' FROM {$p}sn_distribution_items WHERE source_type='mis_data_row' ORDER BY id LIMIT 20000"));
$mis->checked($wpdb->query("INSERT INTO {$p}sn_leads (phone,status) SELECT normalized_phone,'new' FROM {$p}sn_mis_data_rows WHERE assigned_manager_user_id IS NULL ORDER BY id LIMIT 10000"));
} else { $batch=(int)$wpdb->get_var('SELECT MAX(id) FROM '.$p.'sn_mis_import_batches');$start=microtime(true); }
$fixture_seconds=microtime(true)-$start;$model=new SN_MIS_Read_Model($wpdb);$wpdb->query('DELETE FROM '.$p.'sn_mis_jobs');$wpdb->query('DELETE FROM '.$p.'sn_mis_job_rows');$jobs=new SN_MIS_Jobs($wpdb,1);$model=new SN_MIS_Read_Model($wpdb);$plugin=new SN_Plugin();
function source_checksums(){global $wpdb,$p;$out=[];foreach(['mis_data_rows','mis_import_batches','distribution_items','distribution_item_logs','mis_events','leads','invoices'] as $name){$row=$wpdb->get_row('CHECKSUM TABLE '.$p.'sn_'.$name.' EXTENDED',ARRAY_A);$out[$name]=$row['Checksum'];}return $out;}
if(getenv('SN_MIS_PERF_NO_LOOKUP_INDEX')==='YES') {
    foreach(['invoices'=>'lead_id','mis_distribution_plan_items'=>'source_row_id'] as $table=>$column) {
        $keys=[];foreach((array)$wpdb->get_results('SHOW INDEX FROM '.$p.'sn_'.$table,ARRAY_A) as $idx)if((int)$idx['Seq_in_index']===1 && $idx['Column_name']===$column)$keys[$idx['Key_name']]=true;
        foreach(array_keys($keys) as $key)$mis->checked($wpdb->query('ALTER TABLE '.$p.'sn_'.$table.' DROP INDEX `'.str_replace('`','``',$key).'`'));
    }
    $setup=(new SN_MIS_Setup($wpdb))->step();perf_assert($setup['ready'],'web setup succeeds on the volume fixture without adding operational indexes');
}
delete_transient('sn_mis_coverage_'.substr(hash('sha256',$p),0,24));
$before=source_checksums();$timings=[];
foreach(['dashboard','upload','return','reports','tools','cases','plans','rules','branches','health'] as $page) {
    SN_MIS_Read_Model::bump();$_GET=['sn_mis_page'=>$page];$start=microtime(true);$html=$plugin->render_mis_workspace();$elapsed=microtime(true)-$start;$timings['before_index'][$page]=round($elapsed,4);
    perf_assert(!str_contains($html,'is-error') && $elapsed<5,'unindexed '.$page.' renders under five seconds with 100k rows and 120k invoices');
}
$start=microtime(true);$index=$jobs->start('index');$steps=0;$max_step=0;
do{$tick=microtime(true);$r=$jobs->step($index);$max_step=max($max_step,microtime(true)-$tick);$steps++;}while($r['status']!=='ready');
$timings['index']=['seconds'=>round(microtime(true)-$start,3),'steps'=>$steps,'max_step_seconds'=>round($max_step,4),'pending'=>$model->coverage()['pending']];
perf_assert($model->coverage()['pending']===0 && $max_step<5,'100k-row index build is resumable and every bounded step finishes under five seconds');
$stats=$mis->stats();perf_assert($stats['RAW']===40000 && $stats['MANAGER_HELD']===30000 && $stats['CONSUMED']===30000,'bulk evidence classifier matches 40k raw 30k held and 30k consumed');
foreach(['dashboard','upload','return','reports','tools','cases','plans','rules','branches','health'] as $page) {SN_MIS_Read_Model::bump();$_GET=['sn_mis_page'=>$page];$start=microtime(true);$html=$plugin->render_mis_workspace();$elapsed=microtime(true)-$start;$timings['after_index'][$page]=round($elapsed,4);perf_assert(!str_contains($html,'is-error') && $elapsed<5,'indexed '.$page.' renders under five seconds with 100k rows and 120k invoices');}
// An 11111-row async export over the large data set tests actual repeated file resume/ZIP finalization.
$job=$jobs->start('export',['search'=>'Synthetic 1'],false);$initial=$jobs->get($job);$format=static fn($row,$history)=>[$row['id'],$row['normalized_phone'],$row['logical_state']];$max_step=0;$steps=0;$start=microtime(true);
do{$tick=microtime(true);$r=$jobs->step($job,$format);$max_step=max($max_step,microtime(true)-$tick);$steps++;}while($r['status']!=='ready');
$timings['async_export']=['seconds'=>round(microtime(true)-$start,3),'rows'=>(int)$initial['total'],'steps'=>$steps,'max_step_seconds'=>round($max_step,4)];$path=$jobs->download($job);$z=new ZipArchive();$z->open($path);$xml=$z->getFromName('xl/worksheets/sheet1.xml');$z->close();
perf_assert((int)$initial['total']===11111 && substr_count($xml,'<row r=')===11112 && $max_step<5,'11111-row async XLSX completes without loss and each step stays under five seconds');
$jobs->cancel($job);perf_assert($before===source_checksums(),'volume rendering indexing and export preserve source table checksums');
sn_mis_test_write('volume-performance.json',['fixture'=>['rows'=>100000,'distribution_items'=>100000,'item_logs'=>100000,'events'=>50000,'leads'=>10000,'invoices'=>120000,'creation_seconds'=>round($fixture_seconds,3)],'timings'=>$timings,'stats'=>$stats,'source_checksums_unchanged'=>true,'peak_memory_bytes'=>memory_get_peak_usage(true)]);
echo json_encode($timings,JSON_UNESCAPED_UNICODE).PHP_EOL;
