<?php
/** Disposable synthetic namespace only; never a customer database. */
ob_start();require dirname(__DIR__).'/hr/native-support.php';hr239_bootstrap();$f=hr239_data();global $wpdb;
$actor=(int)$f['admin_id'];$seller=(int)$f['users']['seller_a']['id'];wp_set_current_user($actor);
$plugin=sn_bootstrap_plugin_instance();$call=static function(string $name,...$args)use($plugin){$m=new ReflectionMethod($plugin,$name);$m->setAccessible(true);return $m->invoke($plugin,...$args);};
$policy=['view'=>static fn($i)=>current_user_can('manage_options'),'approve'=>static fn()=>current_user_can('manage_options'),'reject'=>static fn()=>current_user_can('manage_options'),'totals'=>static fn($i)=>$call('sn_invoice_payment_totals',$i),'finalize'=>static fn($id,$no)=>$call('sn_finalize_current_payment_stage',$id,'card','finance_receipt_review','',get_current_user_id(),'approved',$no)];$review=new SN_Receipt_Review($policy);
$it=$wpdb->prefix.'sn_invoices';$st=$wpdb->prefix.'sn_invoice_payment_stages';$pt=$wpdb->prefix.'sn_payments';
$fixture=static function(array $changes=[],float $pending=970000)use($wpdb,$it,$st,$pt,$seller):array{
 $token=bin2hex(random_bytes(24));$row=array_replace(['invoice_code'=>'F244-'.bin2hex(random_bytes(5)),'seller_id'=>$seller,'issued_by_user_id'=>$seller,'customer_name'=>'Synthetic capacity','customer_phone'=>'00000000000','product_price'=>5970000,'final_total'=>5970000,'payment_total_amount'=>5970000,'paid_total_amount'=>5000000,'remaining_amount'=>970000,'current_due_amount'=>970000,'current_payment_stage'=>1,'payment_plan'=>'full','status'=>'pending_financial_approval','payment_status'=>'pending_financial_approval','invoice_status'=>'pending_financial_approval','payment_workflow_status'=>'awaiting_financial_approval','access_token'=>$token,'created_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')],$changes);
 hr239_ok($wpdb->insert($it,$row)!==false,'synthetic financial invoice');$id=(int)$wpdb->insert_id;
 hr239_ok($wpdb->insert($st,['invoice_id'=>$id,'stage_no'=>1,'stage_type'=>'full','requested_amount'=>970000,'status'=>'pending_financial_approval'])!==false,'synthetic reduced stage');$stage=(int)$wpdb->insert_id;
 foreach([['approved',5000000],['pending',$pending]]as[$status,$amount]){hr239_ok($wpdb->insert($pt,['invoice_id'=>$id,'payment_stage_no'=>1,'amount'=>$amount,'status'=>$status,'authority'=>'','pay_method'=>'card','payment_source'=>'seller_manual','uploaded_by_type'=>'seller','uploaded_by_user_id'=>$seller,'proof_data'=>wp_json_encode(['entry_mode'=>'info','manual_transfer_type'=>'card','_review'=>['revision'=>1]])])!==false,'synthetic independent receipt');$payment=(int)$wpdb->insert_id;}
 return compact('id','stage','payment','token');
};
$decide=static fn($x,$op='approve',$extra=[])=>$review->handle(array_replace(['invoice_id'=>$x['id'],'payment_id'=>$x['payment'],'operation'=>$op,'revision'=>1,'reason'=>$op==='reject'?'Synthetic rejection':''],$extra));
$one=$fixture();$result=$decide($one);hr239_ok(!empty($result['success']),'full stage approves remaining receipt despite earlier net-capacity corruption');
$invoice=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$it} WHERE id=%d",$one['id']));$stage=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$st} WHERE id=%d",$one['stage']));
hr239_ok((float)$invoice->paid_total_amount===5970000.0&&(float)$invoice->remaining_amount===0.0&&$stage->status==='approved'&&(float)$stage->requested_amount===5970000.0,'full stage settles exactly gross capacity once');
hr239_ok($invoice->access_token===$one['token'],'capacity recovery preserves original invoice token');
hr239_ok((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}sn_payment_review_events WHERE invoice_id=%d AND action='stage_capacity_reconciled'",$one['id']))===1,'capacity recovery has one audit event');
hr239_ok(!empty($decide($one,'approve',['revision'=>2])['success']),'repeat approval is idempotent');
hr239_ok((float)$wpdb->get_var($wpdb->prepare("SELECT paid_total_amount FROM {$it} WHERE id=%d",$one['id']))===5970000.0,'repeated decision cannot double credit invoice');
$rejected=$fixture();hr239_ok(!empty($decide($rejected,'reject')['success']),'remaining receipt can be rejected after a partial approval');
$invoice=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$it} WHERE id=%d",$rejected['id']));hr239_ok((float)$invoice->paid_total_amount===5000000.0&&(float)$invoice->remaining_amount===970000.0&&$invoice->status==='rejected','rejection preserves earlier approved money and real balance');
$ambiguous=$fixture(['payment_plan'=>'partial']);hr239_ok(empty($decide($ambiguous)['success']),'ambiguous partial capacity is not automatically increased');
hr239_ok((float)$wpdb->get_var($wpdb->prepare("SELECT requested_amount FROM {$st} WHERE id=%d",$ambiguous['stage']))===970000.0,'ambiguous stage stays unchanged');
$over=$fixture([],1000000);hr239_ok(empty($decide($over)['success']),'real overpayment remains blocked');
$own=$fixture();$wpdb->update($pt,['uploaded_by_user_id'=>$actor],['id'=>$own['payment']]);hr239_ok(empty($decide($own)['success']),'uploader cannot approve own receipt or recover its capacity');
$stale=$fixture();hr239_ok(empty($decide($stale,'approve',['revision'=>9])['success']),'stale revision cannot modify capacity');
wp_set_current_user($seller);hr239_ok(empty($decide($stale)['success']),'non-finance seller cannot change receipt capacity');wp_set_current_user($actor);
$rollback=$fixture();$fail=static function($sql)use($wpdb){if(str_starts_with($sql,'INSERT INTO `'.$wpdb->prefix.'sn_payment_review_events`'))throw new RuntimeException('Synthetic audit write failure');return$sql;};add_filter('query',$fail,999);
try{$failed=$decide($rollback);}finally{remove_filter('query',$fail,999);}hr239_ok(empty($failed['success']),'failed audit prevents financial decision');
hr239_ok((float)$wpdb->get_var($wpdb->prepare("SELECT requested_amount FROM {$st} WHERE id=%d",$rollback['stage']))===970000.0&&$wpdb->get_var($wpdb->prepare("SELECT status FROM {$pt} WHERE id=%d",$rollback['payment']))==='pending','audit failure rolls back both capacity and receipt');
finance244_evidence('capacity',['audit_rollback'=>true,'approval_rejection'=>true,'overpayment_blocked'=>true]);echo 'TOTAL '.$GLOBALS['hr239_checks'].PHP_EOL;ob_end_flush();
