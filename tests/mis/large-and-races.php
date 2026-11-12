<?php
require __DIR__.'/bootstrap.php';
add_filter('query',static fn($sql)=>preg_replace('/(?<![A-Za-z0-9_])wp_sn_/', $GLOBALS['wpdb']->prefix.'sn_', $sql));
function ensure_mis($condition,$name){if(!$condition)throw new RuntimeException('FAIL '.$name);echo 'PASS '.$name.PHP_EOL;}
$mis=new SN_MIS_Service($wpdb,1);
$wpdb->query("DELETE FROM wp_sn_mis_import_batches WHERE batch_code='LARGE-FIXTURE'");
$wpdb->insert('wp_sn_mis_import_batches',['batch_code'=>'LARGE-FIXTURE','title'=>'large synthetic']);$batch=(int)$wpdb->insert_id;
$rows=[];for($i=0;$i<5101;$i++){$phone='0901'.str_pad((string)$i,7,'0',STR_PAD_LEFT);$rows[]=['customer_phone'=>$phone,'normalized_phone'=>$phone,'customer_name'=>'large '.$i,'row_status'=>'valid'];}
$start=microtime(true);$r=$mis->import_rows($batch,$rows,'large-5101.csv',true);ensure_mis($r['imported']===5101 && $mis->query(['batch_id'=>$batch],1)['total']===5101,'5101-row import completes without the old 5000-row truncation');
$last=$mis->query(['batch_id'=>$batch,'state'=>'RAW','selection'=>'newest'],1)['rows'][0];$id=(int)$last['id'];
$mis->assign([$id],303,['key'=>'branch_a','label'=>'شعبه یک'],'late row');$result=$mis->query(['batch_id'=>$batch,'manager_id'=>303],50);ensure_mis($result['total']===1 && (int)$result['rows'][0]['id']===$id,'SQL manager filter finds a row after the first 1200');
$second=sn_mis_test_new_connection();$second->set_prefix('wp_');$mis2=new SN_MIS_Service($second,2);
$preview=$mis->query(['batch_id'=>$batch,'state'=>'RAW','selection'=>'newest'],1)['rows'][0];$race_id=(int)$preview['id'];
$lock='sn_crm_write_'.substr(hash('sha256',(string)$wpdb->get_var('SELECT DATABASE()').$wpdb->prefix),0,40);$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock));
try{$mis2->assign([$race_id],404,['key'=>'branch_b','label'=>'شعبه دو'],'competing request');ensure_mis(false,'lock failure');}catch(Throwable $e){ensure_mis(str_contains($e->getMessage(),'عملیات دیگری'),'TC-07 full assign operation is rejected while the other connection holds the lock');}
$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));$a=$mis->assign([$race_id],303,['key'=>'branch_a','label'=>'شعبه یک'],'first');$b=$mis2->assign([$race_id],404,['key'=>'branch_b','label'=>'شعبه دو'],'stale preview');ensure_mis($a['assigned']===1 && $b['assigned']===0 && $b['blocked']===1,'TC-07 same preview cannot assign to two managers');
$return_preview=$mis->query(['batch_id'=>$batch,'manager_id'=>303],50);$di=(int)$second->get_var($second->prepare("SELECT id FROM wp_sn_distribution_items WHERE source_type='mis_data_row' AND source_id=%d",$race_id));
$second->update('wp_sn_distribution_items',['current_owner_user_id'=>405,'current_owner_position'=>'supervisor','current_step_order'=>1],['id'=>$di]);$second->insert('wp_sn_distribution_item_logs',['item_id'=>$di,'action'=>'assigned_to_role','from_user_id'=>303,'to_user_id'=>405,'from_position'=>'sales_manager','to_position'=>'supervisor']);
$r=$mis->return_rows([$race_id],'stale return preview');ensure_mis($r['returned']===0 && $r['blocked']===1,'TC-08 consumption after return preview wins; consumed row never becomes RAW');$second->close();
$writer=new SN_MIS_XLSX(['id','phone']);$after=0;$written=0;do{$part=$mis->query(['batch_id'=>$batch,'__skip_count'=>true],500,0,false,$after);foreach($part['rows'] as $row){$writer->add([$row['id'],$row['normalized_phone']]);$after=(int)$row['id'];$written++;}}while(count($part['rows'])===500);$path=$writer->finish();sn_mis_test_write('large-export.xlsx',file_get_contents($path));unlink($path);ensure_mis($written===5101,'all 5101 filtered rows exported across pages');
$before=$wpdb->get_results('SELECT * FROM wp_sn_mis_data_rows ORDER BY id',ARRAY_A);$mis->install();$mis->install();ensure_mis($before===$wpdb->get_results('SELECT * FROM wp_sn_mis_data_rows ORDER BY id',ARRAY_A),'additive setup is idempotent and preserves old row bytes');
$wpdb->query('ALTER TABLE wp_sn_mis_distribution_logs ENGINE=MyISAM');try{$mis->return_rows([$id],'engine guard');ensure_mis(false,'engine guard');}catch(Throwable $e){ensure_mis(str_contains($e->getMessage(),'InnoDB'),'unsafe storage engine is rejected without mutation');}$wpdb->query('ALTER TABLE wp_sn_mis_distribution_logs ENGINE=InnoDB');
sn_mis_test_write('performance.json',json_encode(['large_rows'=>5101,'elapsed_seconds'=>round(microtime(true)-$start,3),'peak_memory_bytes'=>memory_get_peak_usage(true)],JSON_PRETTY_PRINT));
