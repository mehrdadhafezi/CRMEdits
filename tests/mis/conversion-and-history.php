<?php
require __DIR__.'/bootstrap.php';
add_filter('query',static fn($sql)=>preg_replace('/(?<![A-Za-z0-9_])wp_sn_/', $GLOBALS['wpdb']->prefix.'sn_', $sql));
$mis=new SN_MIS_Service($wpdb,1);
$wpdb->insert('wp_sn_mis_import_batches',['batch_code'=>'CONV-'.bin2hex(random_bytes(4)),'title'=>'conversion']);$batch=(int)$wpdb->insert_id;
$phone='093600000'.random_int(10,99);$mis->import_rows($batch,[['customer_phone'=>$phone,'normalized_phone'=>$phone,'customer_name'=>'conversion','row_status'=>'valid']],'conversion.csv',true);$id=(int)$mis->query(['batch_id'=>$batch],1)['rows'][0]['id'];$mis->assign([$id],101,['key'=>'branch_a','label'=>'شعبه یک'],'conversion');
$di=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM wp_sn_distribution_items WHERE source_type='mis_data_row' AND source_id=%d",$id));$wpdb->insert('wp_sn_invoices',['lead_id'=>900000000+$di,'status'=>'paid']);$wpdb->insert('wp_sn_invoices',['lead_id'=>900000000+$di,'status'=>'draft']);
$r=$mis->conversion_summary($batch);if($r['matching_invoices']!==2 || $r['converted_unique_rows']!==1 || (float)$r['conversion_percentage']!==100.0)throw new RuntimeException('conversion');echo 'PASS virtual invoices counted once per invoice, conversion counts one row and remains 100%'.PHP_EOL;
$tables=['sn_mis_data_rows','sn_distribution_items','sn_distribution_item_logs','sn_mis_distribution_plan_items','sn_mis_events'];$before=[];foreach($tables as $table){$before[$table]=$wpdb->get_results('SELECT * FROM wp_'.$table.' ORDER BY id',ARRAY_A);}$mis->safe_archive($batch,'archive safe');foreach($tables as $table){if($before[$table]!==$wpdb->get_results('SELECT * FROM wp_'.$table.' ORDER BY id',ARRAY_A))throw new RuntimeException('archive changed '.$table);}echo 'PASS archival preserves all row/item/event/plan bytes'.PHP_EOL;
