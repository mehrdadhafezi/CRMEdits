<?php
/** Concurrent requests only on the explicitly guarded synthetic invoice created below. */
ob_start();require dirname(__DIR__).'/hr/native-support.php';hr239_bootstrap();$f=hr239_data();global $wpdb;
$plugin=sn_bootstrap_plugin_instance();$dot=SN_Dot_Flow::instance();$admin=(int)$f['admin_id'];wp_set_current_user($admin);
$call=static function($obj,string $method,...$args){$r=new ReflectionMethod($obj,$method);$r->setAccessible(true);return$r->invoke($obj,...$args);};$t=$call($dot,'tables');$it=$wpdb->prefix.'sn_invoices';$file=$GLOBALS['hr239_root'].'/trace242-race.json';
$op=getenv('SN_TRACE_RACE_OP');
if($op==='prepare'){
 $case=$wpdb->get_row("SELECT c.* FROM {$t['cases']} c INNER JOIN {$t['links']} l ON l.case_id=c.id WHERE l.payment_kind='staged_receipt' AND c.status='completed' ORDER BY c.id DESC LIMIT 1",ARRAY_A);
 if(!$case)throw new RuntimeException('Synthetic completed trace fixture required');
 $source=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$it} WHERE id=%d",$case['source_invoice_id']),ARRAY_A);unset($source['id']);$source['invoice_code']='T-R-'.bin2hex(random_bytes(4));$source['access_token']=bin2hex(random_bytes(24));
 hr239_ok($wpdb->insert($it,$source)!==false,'race synthetic source');$source_id=(int)$wpdb->insert_id;
 unset($case['id']);$case['source_invoice_id']=$source_id;$case['status']='option_selected';$case['paid_amount']=0;$case['remaining_amount']=5000000;$case['completed_at']=null;$case['deposit_amount']=0;
 hr239_ok($wpdb->insert($t['cases'],$case)!==false,'race synthetic case');$case_id=(int)$wpdb->insert_id;
 $matrix=$wpdb->prefix.'sn_payment_commission_matrix';$rates=$wpdb->get_results("SELECT * FROM {$matrix} WHERE payment_purpose='conversion_purchase'",ARRAY_A);
 try{$wpdb->query("UPDATE {$matrix} SET rate_percent=0 WHERE payment_purpose='conversion_purchase'");$wpdb->replace($matrix,['payment_purpose'=>'conversion_purchase','role_key'=>'seller','rate_percent'=>10]);
 $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['cases']} WHERE id=%d",$case_id));$first=$call($dot,'create_conversion_invoice',$row,'deposit',2000000.0,$admin,'Synthetic address');
 }finally{$wpdb->query("DELETE FROM {$matrix} WHERE payment_purpose='conversion_purchase'");foreach($rates as $rate){$wpdb->insert($matrix,$rate);}}
 hr239_ok(!is_wp_error($first),'race first installment issued');$id=(int)$first['invoice']->id;$ref='trace-race-'.bin2hex(random_bytes(4));
 hr239_ok($wpdb->insert($wpdb->prefix.'sn_payments',['invoice_id'=>$id,'payment_stage_no'=>1,'amount'=>2000000,'status'=>'verified','authority'=>'zibal:'.random_int(10000000,99999999),'pay_method'=>'online','payment_source'=>'zibal','ref_id'=>$ref])!==false,'race verified synthetic receipt');
 $wallet=$wpdb->prefix.'sn_wallet_transactions';$fail=static fn($sql)=>preg_match('/^INSERT\s+INTO\s+`?'.preg_quote($wallet,'/').'`?\s/i',$sql)?'SELECT trace242_missing FROM trace242_missing_table':$sql;
 add_filter('query',$fail);try{$paid=$call($plugin,'sn_finalize_current_payment_stage',$id,'online','zibal',$ref,0,'paid',1);}finally{remove_filter('query',$fail);}hr239_ok(!empty($paid['success']),'race first installment approved');
 hr239_ok((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wallet} WHERE invoice_id=%d",$id))===0,'race commission starts from safely failed wallet posting');
 hr239_private('trace242-race.json',['invoice_id'=>$id,'case_id'=>$case_id,'seller_id'=>(int)$case['seller_id'],'code'=>(string)$first['invoice']->invoice_code,'invoice_count'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$it}")]);
 ob_end_clean();echo json_encode(['prepared'=>true]);
}elseif($op==='commission'){
 $r=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);$result=SN_Purpose_Commission::instance()->reconcile_invoice((int)$r['invoice_id']);
 ob_end_clean();echo json_encode(['created'=>(int)$result['created'],'blocked'=>!empty($result['blocked'])]);
}elseif($op==='issue'){
 $r=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
 $result=$plugin->issue_dot_conversion_payment_stage((int)$r['case_id'],(int)$r['invoice_id'],1,$admin,'remaining',0);
 ob_end_clean();echo json_encode(['success'=>!empty($result['success'])]);
}elseif($op==='verify'){
 $r=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);$id=(int)$r['invoice_id'];$invoice=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$it} WHERE id=%d",$id));
 hr239_ok($invoice&&(string)$invoice->invoice_code===$r['code']&&(int)$invoice->current_payment_stage===2,'concurrent stage requests preserve invoice code and advance once');
 hr239_ok((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}sn_invoice_payment_stages WHERE invoice_id=%d",$id))===2,'concurrent requests create exactly one additional stage');
 hr239_ok((int)$wpdb->get_var("SELECT COUNT(*) FROM {$it}")===$r['invoice_count'],'concurrent requests create no extra invoice');
 hr239_ok((float)$invoice->paid_total_amount===2000000.0&&(float)$invoice->current_due_amount===3000000.0,'concurrent issuance preserves paid money and remaining due');
 $credits=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_wallet_transactions WHERE invoice_id=%d AND user_id=%d AND direction='credit'",$id,(int)$r['seller_id']),ARRAY_A);
 hr239_ok(count($credits)===1&&(float)$credits[0]['amount']===200000.0,'concurrent commission retries create exactly one proportional receipt credit');
 hr239_ok(!empty(json_decode($credits[0]['meta'],true)['payment_id']),'commission credit retains its real payment identity');
 echo 'TOTAL '.$GLOBALS['hr239_checks'].PHP_EOL;ob_end_flush();
}else{throw new RuntimeException('Explicit synthetic race operation required');}
