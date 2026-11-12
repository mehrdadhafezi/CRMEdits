<?php
require __DIR__.'/bootstrap.php';
add_filter('query',static fn($sql)=>preg_replace('/(?<![A-Za-z0-9_])wp_sn_/', $GLOBALS['wpdb']->prefix.'sn_', $sql));
function assert_mis($condition,$name){if(!$condition){throw new RuntimeException('FAIL '.$name);}echo 'PASS '.$name.PHP_EOL;}
function table_count($table){global $wpdb;return (int)$wpdb->get_var('SELECT COUNT(*) FROM '.$wpdb->prefix.$table);}
// Use a dedicated disposable database only.
foreach(['sn_mis_import_runs','sn_mis_row_index','sn_mis_index_dirty','sn_mis_jobs','sn_mis_job_rows','sn_mis_events','sn_mis_claims','sn_mis_record_meta','sn_distribution_item_logs','sn_distribution_items','sn_mis_distribution_plan_items','sn_mis_distribution_plans','sn_mis_lead_pool','sn_mis_data_rows','sn_mis_import_batches','sn_leads','sn_invoices'] as $t){$wpdb->query('DELETE FROM '.$wpdb->prefix.$t);}
$wpdb->insert('wp_sn_mis_import_batches',['batch_code'=>'TC-01','title'=>'Synthetic acceptance']);$batch=(int)$wpdb->insert_id;
$records=[];for($i=0;$i<500;$i++){$phone='0912'.str_pad((string)$i,7,'0',STR_PAD_LEFT);$records[]=['customer_phone'=>$phone,'normalized_phone'=>$phone,'customer_name'=>'آزمایشی '.$i,'row_status'=>'valid','source_code'=>(string)$i,'first_name'=>'آزمایشی','last_name'=>(string)$i,'source_datetime_raw'=>'1405/08/01 12:10'];}
$mis=new SN_MIS_Service($wpdb,1);$r=$mis->import_rows($batch,$records,'synthetic.csv',false);assert_mis(table_count('sn_mis_data_rows')===0 && $r['valid']===500,'preview has no operational writes');
$r=$mis->import_rows($batch,$records,'synthetic.csv',true);assert_mis($r['imported']===500,'500 import commit');
$ids=array_column($mis->query(['batch_id'=>$batch],1000)['rows'],'id');
$r=$mis->assign($ids,101,['key'=>'branch_a','label'=>'شعبه یک'],'TC-01');assert_mis($r['assigned']===500 && $mis->stats()['MANAGER_HELD']===500 && $mis->stats()['CONSUMED']===0,'TC-01 initial assignment is not consumption');
$before_rows=$wpdb->get_results('SELECT * FROM wp_sn_mis_data_rows ORDER BY id',ARRAY_A);$before_items=$wpdb->get_results('SELECT * FROM wp_sn_distribution_items ORDER BY id',ARRAY_A);
$r=$mis->import_rows($batch,$records,'repeat.csv',true);assert_mis($r['unchanged']===500 && $before_rows===$wpdb->get_results('SELECT * FROM wp_sn_mis_data_rows ORDER BY id',ARRAY_A) && $before_items===$wpdb->get_results('SELECT * FROM wp_sn_distribution_items ORDER BY id',ARRAY_A),'reimport preserves ids manager and distribution');
$items=$wpdb->get_results('SELECT * FROM wp_sn_distribution_items ORDER BY id LIMIT 200',ARRAY_A);foreach($items as $item){$wpdb->update('wp_sn_distribution_items',['current_owner_user_id'=>102,'current_owner_position'=>'supervisor','status'=>'assigned_to_role','current_step_order'=>1],['id'=>$item['id']]);$wpdb->insert('wp_sn_distribution_item_logs',['item_id'=>$item['id'],'action'=>'assigned_to_role','from_user_id'=>101,'to_user_id'=>102,'from_position'=>'sales_manager','to_position'=>'supervisor']);$mis->observe_distribution((int)$item['id'],'assigned_to_role',102,'supervisor','TC-02');}
SN_MIS_Read_Model::flush($wpdb,true);$s=$mis->stats();assert_mis($s['CONSUMED']===200 && $s['MANAGER_HELD']===300,'TC-02 partial consumption');
$r=$mis->return_rows($ids,'TC-03');assert_mis($r['returned']===300 && $r['blocked']===200 && $mis->stats()['RAW']===300,'TC-03 only 300 unused records returned');
$raw=array_column($mis->query(['state'=>'RAW'],1000)['rows'],'id');$r=$mis->assign($raw,202,['key'=>'branch_b','label'=>'شعبه دو'],'TC-04');assert_mis($r['assigned']===300,'TC-04 reassignment');
$h=$mis->history_stats();assert_mis((int)$h['totals']['assignments']===800 && (int)$h['totals']['unique_assigned']===500,'TC-10 800 assignment events 500 unique numbers');
$branches=$mis->history_stats(['branch'=>'branch_a']);assert_mis((int)$branches['totals']['assignments']===500,'TC-05 historical branch snapshot');
$id=(int)$raw[0];$item_id=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM wp_sn_distribution_items WHERE source_type='mis_data_row' AND source_id=%d",$id));$wpdb->update('wp_sn_distribution_items',['seller_contact_status'=>'no_answer','seller_updated_at'=>current_time('mysql')],['id'=>$item_id]);$r=$mis->return_rows([$id],'TC-06');assert_mis($r['blocked']===1 && $r['returned']===0,'TC-06 operational status blocks return without invoice');
$next=(int)$raw[1];$item=(int)$wpdb->get_var($wpdb->prepare('SELECT id FROM wp_sn_distribution_items WHERE source_id=%d AND source_type=\'mis_data_row\'',$next));$wpdb->insert('wp_sn_invoices',['lead_id'=>900000000+$item,'status'=>'cancelled']);$r=$mis->return_rows([$next],'TC-09');assert_mis($r['blocked']===1,'TC-09 virtual invoice blocks even when cancelled');
$next=(int)$raw[2];$phone=$wpdb->get_var($wpdb->prepare('SELECT normalized_phone FROM wp_sn_mis_data_rows WHERE id=%d',$next));$wpdb->insert('wp_sn_leads',['phone'=>$phone]);$r=$mis->return_rows([$next],'TC-09 lead');assert_mis($r['blocked']===1,'TC-09 real lead locks row');
$next=(int)$raw[3];$before=table_count('sn_mis_events');$fail_insert=static function($sql){return str_contains($sql,'INSERT INTO `'.$GLOBALS['wpdb']->prefix.'sn_mis_events`')?'INSERT INTO missing_mis_test_table VALUES (1)':$sql;};add_filter('query',$fail_insert);try{$mis->return_rows([$next],'fail event');throw new RuntimeException('missing failure');}catch(Throwable $e){assert_mis($e->getMessage()!=='missing failure','failed event insert raises error');}remove_filter('query',$fail_insert);assert_mis((int)$wpdb->get_var($wpdb->prepare('SELECT assigned_manager_user_id FROM wp_sn_mis_data_rows WHERE id=%d',$next))===202 && table_count('sn_mis_events')===$before,'SQL failure rolls back ownership and history');
$next=(int)$raw[4];$history=$mis->record_history($next);try{$mis->edit($next,['source_code'=>'changed'],'identity');throw new RuntimeException('missing failure');}catch(Throwable $e){assert_mis($e->getMessage()!=='missing failure','identity edit blocked on held data');}
$mis->edit($next,['first_name'=>'ویرایش','last_name'=>'آزمایشی'],'name correction');assert_mis(count($mis->record_history($next))===count($history)+1,'controlled edit appends before/after history');
// Legacy insufficient history stays locked and is never inferred RAW.
$wpdb->insert('wp_sn_mis_data_rows',['batch_id'=>$batch,'customer_phone'=>'09350000001','normalized_phone'=>'09350000001','assigned_manager_user_id'=>101,'row_status'=>'valid']);$legacy=(int)$wpdb->insert_id;$r=$mis->return_rows([$legacy],'legacy');assert_mis($r['blocked']===1,'unknown historical assignment is fail-closed');
// Mixed pool/direct linkage must share the same consumed guard, with a valid SQL expression.
$wpdb->insert('wp_sn_mis_lead_pool',['source_row_id'=>$next,'batch_id'=>$batch,'assigned_manager_user_id'=>202,'pool_status'=>'ready']);$pool=(int)$wpdb->insert_id;$wpdb->insert('wp_sn_distribution_items',['source_type'=>'mis_pool','source_id'=>$pool,'pool_item_id'=>$pool,'batch_id'=>$batch,'current_owner_user_id'=>202,'current_owner_position'=>'sales_manager','status'=>'assigned_to_role']);$r=$mis->return_rows([$next],'mixed duplicate');assert_mis($r['blocked']===1,'pool plus direct item blocks ambiguous return');
// Advisory lock contention on a second real embedded connection.
$db2=sn_mis_test_new_connection();$db2->set_prefix('wp_');$lock='sn_crm_write_'.substr(hash('sha256',(string)$wpdb->get_var('SELECT DATABASE()').$wpdb->prefix),0,40);$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock));$blocked=(int)$db2->get_var($db2->prepare('SELECT GET_LOCK(%s,0)',$lock));assert_mis($blocked===0,'TC-07 competing connection cannot acquire assignment lock');$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));$db2->close();
sn_mis_test_write('core-test-summary.json',json_encode(['stats'=>$mis->stats(),'history'=>$mis->history_stats()['totals']],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));

// A consumed marker is permanent even if legacy current fields or item logs are later reset.
$consumed_item=$items[0];
$wpdb->update('wp_sn_distribution_items',['current_owner_user_id'=>101,'current_owner_position'=>'sales_manager','current_step_order'=>0,'status'=>'assigned_to_role','final_seller_user_id'=>null,'seller_contact_status'=>null,'seller_updated_at'=>null,'seller_notes'=>null,'seller_sale_probability'=>0],['id'=>$consumed_item['id']]);
$wpdb->delete('wp_sn_distribution_item_logs',['item_id'=>$consumed_item['id']]);
$mono=$mis->return_rows([(int)$consumed_item['source_id']],'monotonic history guard');
assert_mis($mono['returned']===0 && $mono['blocked']===1,'immutable consumed event survives a reset of legacy fields and logs');
// Reconcile historical duplicate identities without deleting or silently repairing either old row.
$wpdb->insert('wp_sn_mis_import_batches',['batch_code'=>'DUP-'.bin2hex(random_bytes(3)),'title'=>'old duplicate fixture']);$duplicate_batch=(int)$wpdb->insert_id;$duplicates=[];
foreach([1,2] as $number) {$wpdb->insert('wp_sn_mis_data_rows',['batch_id'=>$duplicate_batch,'customer_phone'=>'09989999991','normalized_phone'=>'09989999991','row_status'=>'valid']);$duplicates[]=(int)$wpdb->insert_id;}
(new SN_MIS_Read_Model($wpdb))->refresh_ids($duplicates);SN_MIS_Read_Model::bump();$stats=$mis->stats(['batch_id'=>$duplicate_batch]);assert_mis($stats['RAW']===1 && $stats['LOCKED']===1,'duplicate old identities advertise only one assignable canonical record');
$attempt=$mis->assign([$duplicates[1]],404,['key'=>'branch_b','label'=>'شعبه دو'],'duplicate identity');assert_mis($attempt['assigned']===0 && $attempt['blocked']===1,'a duplicate historical row cannot create another owner');

$wpdb->insert('wp_sn_mis_data_rows',['batch_id'=>99999999,'customer_phone'=>'09989999992','normalized_phone'=>'09989999992','row_status'=>'valid']);$orphan=(int)$wpdb->insert_id;
$orphan_report=$mis->query(['search'=>'09989999992'],10);
assert_mis($orphan_report['total']===1 && $orphan_report['rows'][0]['logical_state']==='LOCKED','orphan legacy rows remain visible in reports and locked');
$attempt=$mis->assign([$orphan],404,['key'=>'branch_b','label'=>'شعبه دو'],'orphan source');assert_mis($attempt['assigned']===0 && $attempt['blocked']===1,'a missing batch header cannot be bypassed by posting a row id');
