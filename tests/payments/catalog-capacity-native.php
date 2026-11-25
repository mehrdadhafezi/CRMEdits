<?php
/** Fresh empty schema copies inside the guarded disposable synthetic database. */
ob_start();require dirname(__DIR__).'/hr/native-support.php';hr239_bootstrap();global $wpdb;
$original=$wpdb->prefix;$prefix=$original.'c244_'.bin2hex(random_bytes(3)).'_';
foreach(['sn_invoices','sn_invoice_payment_stages','sn_payments','sn_dot_cases','options']as$s){hr239_ok($wpdb->query("CREATE TABLE {$prefix}{$s} LIKE {$original}{$s}")!==false,'fresh synthetic catalog table '.$s);}
$wpdb->set_prefix($prefix);try{
 $add=static function(bool $paid,bool $history)use($wpdb){
  $i=['invoice_code'=>'CAT244-'.bin2hex(random_bytes(4)),'customer_name'=>'Synthetic catalog','customer_phone'=>'00000000000','product_price'=>7000000,'final_total'=>5970000,'payment_total_amount'=>5970000,'paid_total_amount'=>$paid?5000000:0,'remaining_amount'=>$paid?970000:5970000,'current_due_amount'=>$paid?970000:5970000,'current_payment_stage'=>1,'catalog_discounts_snapshot'=>'[]','discount_total'=>1030000,'status'=>'pending_financial_approval'];
  hr239_ok($wpdb->insert($wpdb->prefix.'sn_invoices',$i)!==false,'synthetic discounted catalog invoice');$id=(int)$wpdb->insert_id;
  hr239_ok($wpdb->insert($wpdb->prefix.'sn_invoice_payment_stages',['invoice_id'=>$id,'stage_no'=>1,'stage_type'=>'full','status'=>'pending_financial_approval','requested_amount'=>7000000])!==false,'synthetic original gross stage');
  if($history)hr239_ok($wpdb->insert($wpdb->prefix.'sn_payments',['invoice_id'=>$id,'payment_stage_no'=>1,'amount'=>$paid?5000000:5970000,'status'=>$paid?'approved':'pending','authority'=>'','proof_data'=>'{}'])!==false,'synthetic immutable receipt history');return$id;
 };
 $unpaid=$add(false,false);$approved=$add(true,true);$pending=$add(false,true);$stored=$add(true,false);
 hr239_ok(SN_Sales_Catalog::instance()->ensure_schema(),'catalog migration executes on isolated fresh schema');
 $capacity=static fn($id)=>(float)$wpdb->get_var($wpdb->prepare("SELECT requested_amount FROM {$wpdb->prefix}sn_invoice_payment_stages WHERE invoice_id=%d",$id));
 hr239_ok($capacity($unpaid)===5970000.0,'unpaid history-free gross stage still normalizes to discounted price');
 hr239_ok($capacity($approved)===7000000.0,'approved receipt prevents migration from shrinking stage to net balance');
 hr239_ok($capacity($pending)===7000000.0,'pending receipt history is not rewritten by normalization');
 hr239_ok($capacity($stored)===7000000.0,'stored paid amount also prevents stage normalization without receipt history');
}finally{$wpdb->set_prefix($original);}
finance244_evidence('catalog',['receipt_history_preserved'=>true]);echo 'TOTAL '.$GLOBALS['hr239_checks'].PHP_EOL;ob_end_flush();
