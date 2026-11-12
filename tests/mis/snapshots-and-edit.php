<?php
require __DIR__.'/bootstrap.php';
add_filter('query',static fn($sql)=>preg_replace('/(?<![A-Za-z0-9_])wp_sn_/', $GLOBALS['wpdb']->prefix.'sn_', $sql));
function pass_mis($ok,$message){if(!$ok)throw new RuntimeException('FAIL '.$message);echo 'PASS '.$message.PHP_EOL;}
$mis=new SN_MIS_Service($wpdb,1);$db2=sn_mis_test_new_connection();$db2->set_prefix('wp_');
$before=(int)$wpdb->get_var('SELECT COUNT(*) FROM wp_sn_mis_data_rows');
$mis->read_snapshot(function()use($wpdb,$db2,$before){
 pass_mis((int)$wpdb->get_var('SELECT COUNT(*) FROM wp_sn_mis_data_rows')===$before,'export snapshot initial count');
 $db2->insert('wp_sn_mis_import_batches',['batch_code'=>'SNAP-'.bin2hex(random_bytes(3)),'title'=>'snapshot']);$batch=(int)$db2->insert_id;
 $db2->insert('wp_sn_mis_data_rows',['batch_id'=>$batch,'normalized_phone'=>'09389999991','customer_phone'=>'09389999991','row_status'=>'valid']);
 pass_mis((int)$wpdb->get_var('SELECT COUNT(*) FROM wp_sn_mis_data_rows')===$before,'export snapshot stays consistent while a writer commits on another connection');
});
pass_mis((int)$wpdb->get_var('SELECT COUNT(*) FROM wp_sn_mis_data_rows')===$before+1,'writer is visible after the read snapshot ends');$db2->close();
$wpdb->insert('wp_sn_mis_import_batches',['batch_code'=>'EDIT-'.bin2hex(random_bytes(3)),'title'=>'invalid correction']);$batch=(int)$wpdb->insert_id;
$wpdb->insert('wp_sn_mis_data_rows',['batch_id'=>$batch,'normalized_phone'=>'bad','customer_phone'=>'bad','row_status'=>'invalid']);$id=(int)$wpdb->insert_id;
$mis->edit($id,['normalized_phone'=>'09389999992','first_name'=>'اصلاح','last_name'=>'آزمایشی'],'correct invalid source');
$r=$mis->query(['batch_id'=>$batch],1)['rows'][0];pass_mis($r['logical_state']==='RAW' && $r['row_status']==='valid' && $r['normalized_phone']==='09389999992','unused invalid identity can be corrected with immutable before/after history');
$mis->safe_archive($batch,'archive');$r=$mis->query(['batch_id'=>$batch],1)['rows'][0];pass_mis($r['logical_state']==='LOCKED','archived free records cannot be allocated');
