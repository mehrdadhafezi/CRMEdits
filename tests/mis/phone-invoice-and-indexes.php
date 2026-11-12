<?php
require __DIR__.'/bootstrap.php';
function phone_pass($ok,$label){if(!$ok)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label.PHP_EOL;}
$p=$wpdb->prefix;$mis=new SN_MIS_Service($wpdb,1);
// Recreate the actual missing production index rather than relying on the synthetic fixture's index.
$indexes=$wpdb->get_results('SHOW INDEX FROM '.$p.'sn_invoices',ARRAY_A);$drop=[];foreach($indexes as $idx)if((int)$idx['Seq_in_index']===1 && $idx['Column_name']==='lead_id')$drop[$idx['Key_name']]=true;
foreach(array_keys($drop) as $key)$wpdb->query('ALTER TABLE '.$p.'sn_invoices DROP INDEX `'.str_replace('`','``',$key).'`');
$before=$wpdb->get_results('SELECT * FROM '.$p.'sn_invoices ORDER BY id',ARRAY_A);$mis->install();$indexes=$wpdb->get_results('SHOW INDEX FROM '.$p.'sn_invoices',ARRAY_A);$found=false;foreach($indexes as $idx)if((int)$idx['Seq_in_index']===1 && $idx['Column_name']==='lead_id')$found=true;
phone_pass($found && $before===$wpdb->get_results('SELECT * FROM '.$p.'sn_invoices ORDER BY id',ARRAY_A),'setup adds the missing invoice lead lookup index without rewriting invoice bytes');
if(!$wpdb->get_var("SHOW COLUMNS FROM {$p}sn_invoices LIKE 'customer_phone'"))$wpdb->query("ALTER TABLE {$p}sn_invoices ADD customer_phone varchar(50) NOT NULL DEFAULT '', ADD KEY customer_phone(customer_phone)");
$wpdb->insert($p.'sn_mis_import_batches',['batch_code'=>'PHONE-'.bin2hex(random_bytes(3)),'title'=>'legacy formats']);$batch=(int)$wpdb->insert_id;$phones=['0997'.random_int(1000000,1999999),'0997'.random_int(2000000,2999999),'0997'.random_int(3000000,3999999)];$rows=[];foreach($phones as $phone)$rows[]=['customer_phone'=>$phone,'normalized_phone'=>$phone,'row_status'=>'valid','customer_name'=>'legacy formats'];$mis->import_rows($batch,$rows,'phone.csv',true);$ids=array_column($mis->query(['batch_id'=>$batch],10)['rows'],'id');$mis->assign($ids,101,['key'=>'phone','label'=>'legacy phone'],'phone fixture');
$wpdb->insert($p.'sn_invoices',['lead_id'=>0,'customer_phone'=>'+98'.substr($phones[0],1),'status'=>'cancelled']);$wpdb->insert($p.'sn_leads',['phone'=>'0098'.substr($phones[1],1),'status'=>'new']);
$r=$mis->return_rows([$ids[0]],'direct invoice');phone_pass($r['returned']===0 && $r['blocked']===1,'legacy international-format invoice phone blocks return even without a linked lead');
$r=$mis->return_rows([$ids[1]],'formatted lead');phone_pass($r['returned']===0 && $r['blocked']===1,'legacy international-format lead phone blocks return');
$wpdb->update($p.'sn_mis_claims',['owner_user_id'=>909],['row_id'=>$ids[2]]);$r=$mis->return_rows([$ids[2]],'claim conflict');phone_pass($r['returned']===0 && $r['blocked']===1,'conflicting legacy claim owner is fail-closed');
