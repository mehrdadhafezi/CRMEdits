<?php
/** Real wallet/commission regression for unpaid reissues and frozen sale policy. */
ob_start();require __DIR__.'/native-support.php';hr239_bootstrap();$f=hr239_data();global $wpdb;
wp_set_current_user($f['admin_id']);$seller=$f['users']['seller_a']['id'];$other=$f['users']['seller_b']['id'];
$matrix=$wpdb->prefix.'sn_payment_commission_matrix';$tx=$wpdb->prefix.'sn_wallet_transactions';$wallet=$wpdb->prefix.'sn_wallets';$invoices=$wpdb->prefix.'sn_invoices';
$engine=SN_Purpose_Commission::instance();$engine->maybe_upgrade();
$rates=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$matrix} WHERE payment_purpose=%s",'product_sale'),ARRAY_A);
$source=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$invoices} WHERE seller_id=%d AND status='pre_invoice' AND payment_total_amount=120000 AND customer_name=%s ORDER BY id DESC LIMIT 1",$seller,'مشتری کاملاً ساختگی'),ARRAY_A);
hr239_ok((bool)$source,'unpaid real invoice exists for commission regression');
$unpaid=$wpdb->get_col($wpdb->prepare("SELECT id FROM {$invoices} WHERE customer_phone=%s AND customer_name=%s AND payment_total_amount=120000 AND status='pre_invoice'",$source['customer_phone'],'مشتری کاملاً ساختگی'));
hr239_ok(count($unpaid)===6,'all six ordinary and exception reissues remain present');
foreach($unpaid as $id){$engine->reconcile_invoice((int)$id);}
$ids=implode(',',array_map('intval',$unpaid));
hr239_ok((int)$wpdb->get_var("SELECT COUNT(*) FROM {$tx} WHERE invoice_id IN ({$ids})")===0,'unpaid reissues cannot create commission or wallet credits');
$balance=static fn()=>(float)$wpdb->get_var($wpdb->prepare("SELECT balance FROM {$wallet} WHERE user_id=%d AND wallet_type='seller'",$seller));
$before_balance=$balance();$id=0;
try{
    $wpdb->query($wpdb->prepare("UPDATE {$matrix} SET rate_percent=0 WHERE payment_purpose=%s",'product_sale'));
    hr239_ok($wpdb->replace($matrix,['payment_purpose'=>'product_sale','role_key'=>'seller','rate_percent'=>10])!==false,'synthetic ten percent sale rate installed');
    unset($source['id']);$source['invoice_code']='H9-L-'.bin2hex(random_bytes(5));$source['access_token']=bin2hex(random_bytes(24));
    $source['customer_name']='آزمون مستقل پورسانت';$source['customer_phone']='09'.random_int(100000000,999999999);
    foreach(['product_price','final_total','payment_total_amount'] as $field){$source[$field]=100000;}
    $source['paid_total_amount']=0;$source['remaining_amount']=100000;$source['payment_workflow_status']='awaiting_payment';$source['payment_purpose']='product_sale';
    if(array_key_exists('original_seller_id',$source)){$source['original_seller_id']=$seller;}$source['seller_id']=$seller;
    $inserted=$wpdb->insert($invoices,$source);
    if($inserted===false){preg_match("/(?:Unknown column |column |Field )'([A-Za-z0-9_]+)'/",$wpdb->last_error,$field);throw new RuntimeException('FAIL synthetic ledger insert field '.($field[1]??'unclassified'));}
    hr239_ok(true,'independent synthetic ledger invoice created');$id=(int)$wpdb->insert_id;
    hr239_ok($engine->snapshot_sale($id),'sale policy snapshot captures current rate and recipient');
    $snapshot=static fn()=>$wpdb->get_var($wpdb->prepare("SELECT snapshot_json FROM {$wpdb->prefix}sn_payment_review_events WHERE invoice_id=%d AND action='sale_policy'",$id));
    $frozen=$snapshot();$policy=json_decode($frozen,true,512,JSON_THROW_ON_ERROR);
    hr239_ok((int)$policy['recipients']['seller']===$seller&&(float)$policy['rates']['product_sale']['seller']===10.0,'sale snapshot freezes the intended seller and ten percent rate');
    hr239_ok($engine->snapshot_sale($id)&&$snapshot()===$frozen,'snapshot retry preserves original sale history');
    $wpdb->update($matrix,['rate_percent'=>25],['payment_purpose'=>'product_sale','role_key'=>'seller']);
    $changes=['seller_id'=>$other,'status'=>'approved','invoice_status'=>'approved','payment_status'=>'approved','payment_workflow_status'=>'completed','remaining_amount'=>0,'paid_total_amount'=>100000];
    if(array_key_exists('original_seller_id',$source)){$changes['original_seller_id']=$other;}
    hr239_ok($wpdb->update($invoices,$changes,['id'=>$id])!==false,'synthetic paid invoice and later live recipient change prepared');
    foreach([1=>40000,2=>60000] as $number=>$amount){hr239_ok($wpdb->insert($wpdb->prefix.'sn_invoice_payment_stages',['invoice_id'=>$id,'stage_no'=>$number,'requested_amount'=>$amount,'status'=>'paid'])!==false,'synthetic approved payment stage '.$number);}
    $report=$engine->reconcile_invoice($id);
    $rows=static fn()=>$wpdb->get_results($wpdb->prepare("SELECT * FROM {$tx} WHERE invoice_id=%d ORDER BY id",$id),ARRAY_A);
    $credits=$rows();
    hr239_ok($report['created']===2&&count($credits)===2,'two paid stages release exactly two commission credits');
    hr239_ok(array_unique(array_map('intval',array_column($credits,'user_id')))===[$seller],'later seller change cannot redirect the frozen sale recipient');
    hr239_ok(array_map('floatval',array_column($credits,'amount'))===[4000.0,6000.0]&&abs($balance()-$before_balance-10000)<0.005,'frozen original rate determines stage amounts and wallet balance');
    $history=wp_json_encode($rows());$engine->reconcile_invoice($id);
    hr239_ok(wp_json_encode($rows())===$history&&abs($balance()-$before_balance-10000)<0.005,'commission replay never double credits either stage');
    $manager=$f['users']['manager_a']['id'];
    foreach(['allow','deny','inherit'] as $mode){hr239_ok(hr239_save($f,$manager,$mode)['success'],'HR reissue mode changed without financial permission '.$mode);}
    hr239_ok(wp_json_encode($rows())===$history&&$snapshot()===$frozen&&abs($balance()-$before_balance-10000)<0.005,'HR permission changes preserve wallet, commission history and frozen sale policy');
    $engine->on_invoice_financial_reopened($id,null,'synthetic reopen');
    hr239_ok(count($rows())===3&&abs($balance()-$before_balance-4000)<0.005,'reopening reverses only the latest six thousand stage');
    $engine->on_invoice_financial_rejected($id,null,'cancel synthetic invoice');
    hr239_ok(count($rows())===4&&abs($balance()-$before_balance)<0.005,'full cancellation reverses remaining credits without deleting history');
    $history=wp_json_encode($rows());$engine->on_invoice_financial_rejected($id,null,'cancel repeated synthetic invoice');
    hr239_ok(wp_json_encode($rows())===$history&&abs($balance()-$before_balance)<0.005,'cancellation replay cannot double debit');
    $all=$rows();$credit_ids=array_map('intval',array_column(array_filter($all,static fn($r)=>$r['direction']==='credit'),'id'));
    $reversed=[];foreach($all as $row){if($row['direction']==='debit'){$meta=json_decode($row['meta'],true);$reversed[]=(int)$meta['original_credit_transaction_id'];}}
    sort($credit_ids);sort($reversed);hr239_ok($credit_ids===$reversed&&count(array_unique($reversed))===2,'every reversal references one preserved original credit');
    hr239_ok($snapshot()===$frozen,'financial replay and reversals preserve immutable sale policy');
}finally{
    $wpdb->query($wpdb->prepare("DELETE FROM {$matrix} WHERE payment_purpose=%s",'product_sale'));
    foreach($rates as $rate){$wpdb->insert($matrix,$rate);}
}
$summary=hr239_source_fingerprint()+['version'=>SN_VERSION,'passed'=>true,'synthetic'=>true,'assertions'=>$GLOBALS['hr239_checks'],'wordpress'=>$GLOBALS['wp_version'],'mysql'=>$wpdb->db_version(),'production_touched'=>false];
file_put_contents($GLOBALS['hr239_output'].'/hr239-ledger-summary.json',wp_json_encode($summary,JSON_PRETTY_PRINT));echo 'TOTAL '.$GLOBALS['hr239_checks'].PHP_EOL;ob_end_flush();
