<?php
/** Existing Finance/reporting behavior with real MySQL in the guarded HR test site. */
ob_start();require __DIR__.'/native-support.php';hr239_bootstrap();$f=hr239_data();global $wpdb;
$seller=$f['users']['seller_a']['id'];$other=$f['users']['seller_b']['id'];$plugin=sn_bootstrap_plugin_instance();
wp_set_current_user($f['admin_id']);
$call=static function(string $name,...$args)use($plugin){$method=new ReflectionMethod(SN_Plugin::class,$name);$method->setAccessible(true);return $method->invoke($plugin,...$args);};
$hash=static function()use($wpdb):string{$rows=[];foreach(['sn_invoices','sn_invoice_payment_stages','sn_payments'] as $table){$rows[$table]=$wpdb->get_results("SELECT * FROM {$wpdb->prefix}{$table} ORDER BY id",ARRAY_A);}return hash('sha256',wp_json_encode($rows));};
$batch='H9-'.bin2hex(random_bytes(3));$invoices=[];$tokens=[];$payments=[];
$report_product=(int)wp_insert_post(['post_type'=>'product','post_status'=>'publish','post_title'=>'محصول ساختگی تفکیک آزمون مالی']);hr239_ok($report_product>0,'isolated report product separates repeated fixture runs');
$invoice=static function(string $key,array $extra=[])use($wpdb,$seller,$batch,$report_product,&$invoices,&$tokens):int{
    $row=array_replace(['invoice_code'=>$batch.'-'.$key,'seller_id'=>$seller,'issued_by_user_id'=>$seller,'customer_name'=>'فاکتور مالی ساختگی','customer_phone'=>'00000000000','product_id'=>$report_product,'product_price'=>1000000,'final_total'=>1000000,'status'=>'pending_financial_approval','invoice_status'=>'pending_financial_approval','payment_status'=>'pending_financial_approval','payment_plan'=>'partial','payment_total_amount'=>1000000,'paid_total_amount'=>0,'remaining_amount'=>1000000,'current_due_amount'=>1000000,'current_payment_stage'=>1,'payment_workflow_status'=>'awaiting_financial_approval','pay_method'=>'card','payment_source'=>'seller_manual','access_token'=>bin2hex(random_bytes(32)),'created_at'=>'2026-11-01 10:00:00','updated_at'=>'2026-11-01 10:00:00'],$extra);
    hr239_ok($wpdb->insert($wpdb->prefix.'sn_invoices',$row)!==false,'native Finance synthetic invoice '.$key);$id=(int)$wpdb->insert_id;$invoices[$key]=$id;$tokens[]=$row['access_token'];
    hr239_ok($wpdb->insert($wpdb->prefix.'sn_invoice_payment_stages',['invoice_id'=>$id,'stage_no'=>1,'stage_type'=>'partial','requested_amount'=>1000000,'status'=>'pending_financial_approval'])!==false,'native Finance synthetic stage '.$key);return $id;
};
$payment=static function(string $key,int $invoice_id,float $amount,string $status='pending')use($wpdb,$seller,&$payments):int{
    hr239_ok($wpdb->insert($wpdb->prefix.'sn_payments',['invoice_id'=>$invoice_id,'payment_stage_no'=>1,'amount'=>$amount,'status'=>$status,'authority'=>'','pay_method'=>'card','payment_source'=>'seller_manual','uploaded_by_type'=>'seller','uploaded_by_user_id'=>$seller,'proof_data'=>wp_json_encode(['entry_mode'=>'info','manual_transfer_type'=>'card','_review'=>['revision'=>1]])])!==false,'native Finance synthetic receipt '.$key);return $payments[$key]=(int)$wpdb->insert_id;
};
$partial=$invoice('partial',['paid_total_amount'=>300000,'remaining_amount'=>700000,'current_due_amount'=>700000]);$payment('partial_paid',$partial,300000,'approved');$payment('partial_pending',$partial,700000);
$invoice('final',['status'=>'approved','invoice_status'=>'approved','product_price'=>1200000,'final_total'=>1200000,'payment_total_amount'=>1200000,'paid_total_amount'=>1200000,'remaining_amount'=>0,'current_due_amount'=>0,'payment_workflow_status'=>'completed']);
$invoice('foreign',['seller_id'=>$other,'issued_by_user_id'=>$other,'status'=>'approved','invoice_status'=>'approved','payment_status'=>'approved','paid_total_amount'=>999000,'remaining_amount'=>1000]);
$invoice('cancelled',['status'=>'cancelled','invoice_status'=>'cancelled','payment_status'=>'cancelled','paid_total_amount'=>100000,'remaining_amount'=>900000]);
foreach(['decide','failure'] as $key){$id=$invoice($key);$payment($key.'_first',$id,300000);$payment($key.'_last',$id,700000);}
$sql=$call('sn_invoice_received_amount_sql','i');$rows=$wpdb->get_results($wpdb->prepare("SELECT i.*,({$sql}) native_received FROM {$wpdb->prefix}sn_invoices i WHERE invoice_code LIKE %s",$wpdb->esc_like($batch).'%'));
hr239_ok(!$wpdb->last_error&&count($rows)===6,'native Finance aggregate executes on MySQL');
foreach($rows as $row){hr239_ok(abs((float)$row->native_received-$call('sn_invoice_received_amount',$row))<0.005,'native SQL and PHP received-money parity');}
$filters=['seller_id'=>$seller,'q'=>$batch.'-partial','pay_method'=>'','product_type'=>'','date_from'=>'','date_to'=>''];$report=$call('sn_successful_payment_report_stats',$filters);
hr239_ok((int)$report['payment_count']===1&&(float)$report['total_amount']===300000.0,'native partial approved receipt is counted once');$filters['q']=$batch.'-final';$report=$call('sn_successful_payment_report_stats',$filters);
hr239_ok((int)$report['payment_count']===1&&(float)$report['total_amount']===1200000.0,'native final invoice fallback counted once despite stale pending alias');
$uid=wp_insert_user(['user_login'=>'hr239_fin_'.bin2hex(random_bytes(4)),'user_pass'=>bin2hex(random_bytes(24)),'user_email'=>'finance-'.bin2hex(random_bytes(4)).'@example.invalid','role'=>'subscriber']);hr239_ok(!is_wp_error($uid),'isolated Finance actor created');$finance=get_user_by('id',$uid);foreach(['sn_view_finance','sn_approve_payment','sn_reject_payment'] as $cap){$finance->add_cap($cap);}
$policy=['view'=>static fn()=>$call('sn_can_view_finance'),'approve'=>static fn()=>$call('sn_can_approve_payment'),'reject'=>static fn()=>$call('sn_can_reject_payment'),'totals'=>static fn($row)=>$call('sn_invoice_payment_totals',$row),'finalize'=>static fn($id,$no)=>$call('sn_finalize_current_payment_stage',$id,'card','finance_receipt_review','',get_current_user_id(),'approved',$no)];$review=new SN_Receipt_Review($policy);
wp_set_current_user($seller);$before=$hash();$denied=$review->handle(['invoice_id'=>$invoices['decide'],'payment_id'=>$payments['decide_first'],'operation'=>'approve','revision'=>1]);hr239_ok(empty($denied['success'])&&$hash()===$before,'seller reissue policy never grants Finance approval permission');
wp_set_current_user($uid);
foreach(['decide_first','decide_last'] as $key){$r=$review->handle(['invoice_id'=>$invoices['decide'],'payment_id'=>$payments[$key],'operation'=>'approve','revision'=>1]);hr239_ok(!empty($r['success']),'actual receipt approval '.$key);}
$settled=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_invoices WHERE id=%d",$invoices['decide']));
hr239_ok(in_array($settled->status,['approved','paid'],true)&&(float)$settled->remaining_amount===0.0&&(float)$settled->paid_total_amount===1000000.0,'actual receipt approvals fully settle exactly one invoice');
$before=$hash();$wallet=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}sn_wallet_transactions");$again=$review->handle(['invoice_id'=>$invoices['decide'],'payment_id'=>$payments['decide_last'],'operation'=>'approve','revision'=>2]);
hr239_ok(!empty($again['already'])&&$hash()===$before&&(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}sn_wallet_transactions")===$wallet,'repeated receipt approval preserves financial rows and wallet');
wp_set_current_user($f['admin_id']);
$filters['q']=$batch.'-decide';$report=$call('sn_successful_payment_report_stats',$filters);hr239_ok((int)$report['payment_count']===2&&(float)$report['total_amount']===1000000.0,'completed two-receipt ledger excludes duplicate invoice fallback');
$table=$wpdb->prefix.'sn_payment_review_events';$fail=static fn($query)=>preg_match('/^INSERT\s+INTO\s+`?'.preg_quote($table,'/').'`?\s/i',$query)?'SELECT hr239_missing FROM hr239_missing_table':$query;
add_filter('query',$fail);try{$failed=$review->handle(['invoice_id'=>$invoices['failure'],'payment_id'=>$payments['failure_first'],'operation'=>'approve','revision'=>1]);}finally{remove_filter('query',$fail);}
hr239_ok(empty($failed['success'])&&$hash()===$before,'native Finance audit SQL failure rolls back full decision');
$before=$hash();$period=SN_Report_Service::filters(['date_from'=>'۱۴۰۵/۰۸/۱۰','date_to'=>'۱۴۰۵/۰۸/۱۰','product_id'=>$report_product]);$service=new SN_Report_Service($wpdb,[$seller]);$report=$service->query('invoices',$period,1,2);
hr239_ok(!$wpdb->last_error&&$report['total']===5&&(float)$report['amount']===5200000.0,'native reporting preserves installed Jalali date and scoped totals');
hr239_ok(count($report['rows'])===2&&count($service->query('invoices',$period,3,2)['rows'])===1,'native scoped report pagination stays correct');
hr239_ok($service->query('invoices',array_replace($period,['seller_id'=>$other]))['total']===0,'native forged seller filter cannot expand report scope');
hr239_ok((new SN_Report_Service($wpdb,[]))->query('invoices',$period)['total']===0,'native empty report scope stays empty');
$executive=new SN_Report_Executive($wpdb,[$seller]);$executive_period=SN_Report_Executive::filters(['date_from'=>'۱۴۰۵/۰۸/۱۰','date_to'=>'۱۴۰۵/۰۸/۱۰','sn_report_search'=>$batch]);$result=$executive->query('invoices_register',$executive_period,1,2);
hr239_ok($result['available']&&$result['total']===5&&(float)$result['amount']===5200000.0&&count($result['rows'])===2,'native executive date range totals and pagination preserved');
foreach($tokens as $token){hr239_ok(!str_contains(wp_json_encode($result),$token),'native executive report excludes public invoice token');}
$bad=$executive->query('invoices_register',SN_Report_Executive::filters(['date_from'=>'invalid']));hr239_ok(!$bad['available']&&$bad['rows']===[],'invalid executive period cannot become unfiltered data');
hr239_ok($hash()===$before,'native reports preserve every financial row');
$summary=hr239_source_fingerprint()+['version'=>SN_VERSION,'assertions'=>$GLOBALS['hr239_checks'],'passed'=>true,'synthetic'=>true,'production_touched'=>false,'wordpress'=>$GLOBALS['wp_version'],'php'=>PHP_VERSION,'mysql'=>$wpdb->db_version()];file_put_contents($GLOBALS['hr239_output'].'/hr239-finance-summary.json',wp_json_encode($summary,JSON_PRETTY_PRINT));echo 'TOTAL '.$GLOBALS['hr239_checks'].PHP_EOL;ob_end_flush();
