<?php
/** Only the guarded disposable synthetic namespace; no provider requests. */
ob_start();require dirname(__DIR__).'/hr/native-support.php';hr239_bootstrap();$f=hr239_data();global $wpdb;
$admin=(int)$f['admin_id'];$seller=(int)$f['users']['seller_a']['id'];$other=(int)$f['users']['seller_b']['id'];wp_set_current_user($admin);
$plugin=sn_bootstrap_plugin_instance();$dot=SN_Dot_Flow::instance();
$matrix=$wpdb->prefix.'sn_payment_commission_matrix';$saved_rates=$wpdb->get_results("SELECT * FROM {$matrix} WHERE payment_purpose='conversion_purchase'",ARRAY_A);
register_shutdown_function(static function()use($wpdb,$matrix,$saved_rates){$wpdb->query("DELETE FROM {$matrix} WHERE payment_purpose='conversion_purchase'");foreach($saved_rates as $row){$wpdb->insert($matrix,$row);}});
$wpdb->query("UPDATE {$matrix} SET rate_percent=0 WHERE payment_purpose='conversion_purchase'");
hr239_ok($wpdb->replace($matrix,['payment_purpose'=>'conversion_purchase','role_key'=>'seller','rate_percent'=>10])!==false,'synthetic conversion commission rate');
$call=static function($object,string $name,...$args){$m=new ReflectionMethod($object,$name);$m->setAccessible(true);return $m->invoke($object,...$args);};
$product=new WC_Product_Simple();$product->set_name('Synthetic trace purchase');$product->set_status('publish');$product->set_regular_price('5000000');$product->set_virtual(true);$pid=$product->save();update_post_meta($pid,'_sn_enabled','1');
$projects=SN_Projects::instance();$pt=$call($projects,'tables');
hr239_ok($wpdb->insert($pt['subscriptions'],['subscription_product_id'=>$pid,'is_active'=>1])!==false,'synthetic subscription product mapped');
$cache=new ReflectionProperty($projects,'subscription_ids_cache');$cache->setAccessible(true);$cache->setValue($projects,null);
$phone='09'.random_int(100000000,999999999);$now=current_time('mysql');$it=$wpdb->prefix.'sn_invoices';$st=$wpdb->prefix.'sn_invoice_payment_stages';$pay=$wpdb->prefix.'sn_payments';$t=$call($dot,'tables');
hr239_ok($wpdb->insert($it,['invoice_code'=>'T-S-'.bin2hex(random_bytes(4)),'seller_id'=>$seller,'issued_by_user_id'=>$seller,'customer_name'=>'Synthetic trace only','customer_phone'=>$phone,'customer_address'=>'Synthetic address','product_id'=>$pid,'product_price'=>5000000,'final_total'=>5000000,'status'=>'approved','access_token'=>bin2hex(random_bytes(24)),'created_at'=>$now,'updated_at'=>$now])!==false,'independent synthetic source invoice');$source=(int)$wpdb->insert_id;
hr239_ok($wpdb->insert($t['cases'],['source_invoice_id'=>$source,'source_product_id'=>$pid,'seller_id'=>$seller,'supervisor_id'=>$f['users']['supervisor_a']['id'],'converter_id'=>$admin,'customer_name'=>'Synthetic trace only','customer_phone'=>$phone,'selected_option_key'=>'trace_offer','selected_option_product_id'=>$pid,'selected_option_title'=>'Synthetic trace purchase','selected_option_price'=>5000000,'options_snapshot_json'=>wp_json_encode([['key'=>'trace_offer','product_id'=>$pid,'price'=>5000000,'active'=>1]]),'status'=>'option_selected','remaining_amount'=>5000000,'created_at'=>$now,'updated_at'=>$now])!==false,'synthetic conversion purchase');$case_id=(int)$wpdb->insert_id;
$case=static fn()=>$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['cases']} WHERE id=%d",$case_id));
$first=$call($dot,'create_conversion_invoice',$case(),'deposit',2000000.0,$admin,'Synthetic address');
if(is_wp_error($first)){throw new RuntimeException('FAIL first creation '.$first->get_error_code());}
hr239_ok(!empty($first['invoice']),'first installment creates invoice');$id=(int)$first['invoice']->id;$code=(string)$first['invoice']->invoice_code;$token=(string)$first['invoice']->access_token;
$invoice=static fn()=>$wpdb->get_row($wpdb->prepare("SELECT * FROM {$it} WHERE id=%d",$id));
hr239_ok((float)$invoice()->payment_total_amount===5000000.0&&(float)$invoice()->current_due_amount===2000000.0&&$invoice()->payment_plan==='partial','invoice stores entire purchase, first stage only its due');
hr239_ok($dot->is_staged_conversion_invoice($id),'new ledger marker recorded');
$policy=['view'=>static fn()=>current_user_can('manage_options'),'approve'=>static fn()=>current_user_can('manage_options'),'reject'=>static fn()=>current_user_can('manage_options'),'totals'=>static fn($row)=>$call($plugin,'sn_invoice_payment_totals',$row),'finalize'=>static fn($i,$no)=>$call($plugin,'sn_finalize_current_payment_stage',$i,'card','finance_receipt_review','',get_current_user_id(),'approved',$no)];$review=new SN_Receipt_Review($policy);
$receipt=static function(int $number,float $amount)use($wpdb,$id,$pay,$seller){hr239_ok($wpdb->insert($pay,['invoice_id'=>$id,'payment_stage_no'=>$number,'amount'=>$amount,'status'=>'pending','authority'=>'','pay_method'=>'card','payment_source'=>'seller_manual','uploaded_by_type'=>'seller','uploaded_by_user_id'=>$seller,'proof_data'=>wp_json_encode(['entry_mode'=>'info','manual_transfer_type'=>'card','_review'=>['revision'=>1]])])!==false,'independent receipt recorded');return(int)$wpdb->insert_id;};
$approve=static function(int $payment_id)use($review,$id){$r=$review->handle(['invoice_id'=>$id,'payment_id'=>$payment_id,'operation'=>'approve','revision'=>1]);hr239_ok(!empty($r['success']),'actual financial receipt approval');};
$p1=$receipt(1,750000);$p2=$receipt(1,1250000);$approve($p1);
hr239_ok((float)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(amount),0) FROM {$wpdb->prefix}sn_wallet_transactions WHERE invoice_id=%d AND direction='credit' AND user_id=%d",$id,$seller))===75000.0&&(float)$invoice()->remaining_amount>0,'first approved receipt releases only its own commission before stage or invoice completion');
hr239_ok((float)$case()->paid_amount===750000.0&&$case()->status!=='completed','case reflects one approved receipt without claiming purchase completion');
$view=$review->read($invoice());$approved_row=array_values(array_filter($view['payments'],static fn($row)=>(int)$row['id']===$p1))[0];hr239_ok(!empty($approved_row['actions']['retry_commission']),'Finance has a guarded commission retry action on an approved partial receipt');
wp_set_current_user($seller);$seller_view=$review->read($invoice());$seller_row=array_values(array_filter($seller_view['payments'],static fn($row)=>(int)$row['id']===$p1))[0];hr239_ok(empty($seller_row['actions']['retry_commission']),'seller cannot receive the commission retry action');wp_set_current_user($admin);
$wpdb->update($matrix,['rate_percent'=>25],['payment_purpose'=>'conversion_purchase','role_key'=>'seller']);
$wallet_table=$wpdb->prefix.'sn_wallet_transactions';$wallet_failure=static fn($sql)=>preg_match('/^INSERT\s+INTO\s+`?'.preg_quote($wallet_table,'/').'`?\s/i',$sql)?'SELECT trace242_missing FROM trace242_missing_table':$sql;
add_filter('query',$wallet_failure);try{$saved=$review->handle(['invoice_id'=>$id,'payment_id'=>$p2,'operation'=>'approve','revision'=>1]);}finally{remove_filter('query',$wallet_failure);}
hr239_ok(empty($saved['success'])&&!empty($saved['decision_saved'])&&!empty($saved['commission_pending'])&&(string)$wpdb->get_var($wpdb->prepare("SELECT status FROM {$pay} WHERE id=%d",$p2))==='approved','wallet failure preserves approved receipt with explicit retry-needed response');
hr239_ok((float)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(amount),0) FROM {$wallet_table} WHERE invoice_id=%d AND direction='credit' AND user_id=%d",$id,$seller))===75000.0,'failed commission transaction preserves prior credit without partial extra money');
$retry=$review->handle(['invoice_id'=>$id,'payment_id'=>$p2,'operation'=>'approve','revision'=>2]);hr239_ok(!empty($retry['success']),'retry of saved receipt posts missing frozen-rate commission and finalizes stage');
hr239_ok((float)$invoice()->paid_total_amount===2000000.0&&(float)$case()->paid_amount===2000000.0,'first two receipts aggregate once into same invoice and case');
$before=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$it}");
$state=static fn()=>wp_json_encode([(array)$invoice(),(array)$case(),$wpdb->get_results($wpdb->prepare("SELECT * FROM {$st} WHERE invoice_id=%d ORDER BY id",$id),ARRAY_A),$wpdb->get_results($wpdb->prepare("SELECT * FROM {$t['payments']} WHERE invoice_id=%d ORDER BY id",$id),ARRAY_A)]);
$before_state=$state();$fail=static fn($sql)=>preg_match('/^UPDATE\s+`?'.preg_quote($t['payments'],'/').'`?\s/i',$sql)?'SELECT trace242_missing FROM trace242_missing_table':$sql;
add_filter('query',$fail);try{$failed=$call($dot,'create_conversion_invoice',$case(),'balance',3000000.0,$admin,'Synthetic address');}finally{remove_filter('query',$fail);}
hr239_ok(is_wp_error($failed)&&$state()===$before_state,'adapter SQL failure rolls back invoice and new stage together');
wp_set_current_user($other);$denied=$plugin->issue_dot_conversion_payment_stage($case_id,$id,1,$other,'remaining',0);
hr239_ok(empty($denied['success']),'unassigned seller cannot issue conversion stage');wp_set_current_user($admin);
$second=$call($dot,'create_conversion_invoice',$case(),'balance',3000000.0,$admin,'Synthetic address');
if(is_wp_error($second)){throw new RuntimeException('FAIL next stage '.$second->get_error_code());}
hr239_ok((int)$second['invoice']->id===$id&&(string)$second['invoice']->invoice_code===$code&&(string)$second['invoice']->access_token===$token,'second installment preserves invoice id, code and token');
hr239_ok((int)$wpdb->get_var("SELECT COUNT(*) FROM {$it}")===$before&&(int)$invoice()->current_payment_stage===2,'next installment creates stage two and no new invoice');
$stale=$plugin->issue_dot_conversion_payment_stage($case_id,$id,1,$admin,'remaining',0);hr239_ok(empty($stale['success']),'stale stage submission cannot duplicate a stage');
$p3=$receipt(2,3000000);$approve($p3);
hr239_ok((float)$invoice()->paid_total_amount===5000000.0&&(float)$invoice()->remaining_amount===0.0&&(float)$case()->paid_amount===5000000.0&&$case()->status==='completed','all stages settle one purchase exactly once');
$dot->on_invoice_paid($id,$invoice());$dot->on_invoice_paid($id,$invoice());
hr239_ok((float)$case()->paid_amount===5000000.0,'replayed completion cannot add receipts twice');
$credits=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_wallet_transactions WHERE invoice_id=%d AND direction='credit' AND user_id=%d ORDER BY id",$id,$seller),ARRAY_A);
hr239_ok(count($credits)===3&&array_map('floatval',array_column($credits,'amount'))===[75000.0,125000.0,300000.0],'conversion commission follows each approved receipt and sums to the one invoice amount');
$frozen=wp_json_encode($credits);SN_Purpose_Commission::instance()->reconcile_invoice($id);
hr239_ok(wp_json_encode($wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_wallet_transactions WHERE invoice_id=%d AND direction='credit' AND user_id=%d ORDER BY id",$id,$seller),ARRAY_A))===$frozen,'conversion commission replay cannot double credit stages');
$missing_stage=static fn($sql)=>str_starts_with($sql,'SELECT p.id,p.payment_stage_no,p.amount,s.id AS canonical_stage_id')?str_replace('s.id AS canonical_stage_id','NULL AS canonical_stage_id',$sql):$sql;
add_filter('query',$missing_stage);try{$blocked=SN_Purpose_Commission::instance()->reconcile_invoice($id);}finally{remove_filter('query',$missing_stage);}
hr239_ok(!empty($blocked['blocked'])&&wp_json_encode($wpdb->get_results($wpdb->prepare("SELECT * FROM {$wallet_table} WHERE invoice_id=%d AND direction='credit' AND user_id=%d ORDER BY id",$id,$seller),ARRAY_A))===$frozen,'missing canonical stage blocks commission without inventing a mapping or changing credits');
$rows=SN_Payment_Transactions::rows($invoice());$refs=array_column($rows,'transaction_code');
hr239_ok(count($rows)===3&&count(array_unique($refs))===3&&$refs===['PAY-'.$p1,'PAY-'.$p2,'PAY-'.$p3],'each real receipt has a different stable transaction reference');
hr239_ok(array_unique(array_column($rows,'stage_count'))===[2]&&$rows[0]['stage_reference']===$rows[1]['stage_reference']&&$rows[2]['stage_reference']!==$rows[0]['stage_reference'],'three transactions remain attributable to their two real stages');
$overview=$call($dot,'staged_conversion_overview_rows',$case(),[]);hr239_ok(count($overview)===2,'Dot overview expands canonical stage history instead of one adapter');
wp_set_current_user($admin);
$tx=[];foreach([$p1=>1,$p2=>1,$p3=>2]as$payment_id=>$number){$tx[]=['trackId'=>(string)$payment_id,'orderId'=>$code,'amount'=>100,'status'=>'1','_source'=>'local','_invoice'=>array_merge((array)$invoice(),['payment_row_id'=>$payment_id,'trace_stage_no'=>$number])];}
$export=$call($plugin,'sn_zibal_build_finance_export_rows',$tx);hr239_ok(array_column($export,28)===$refs&&array_column($export,29)===['1','1','2'],'gateway export keeps each payment identity even when invoice code repeats');
$remote=$call($plugin,'sn_zibal_build_finance_export_rows',[['trackId'=>'999999','orderId'=>$code,'amount'=>1,'status'=>'1']]);hr239_ok($remote[0][28]===''&&$remote[0][29]===''&&$remote[0][31]==='2','unmatched provider row never borrows another receipt or current stage');
$filters=['seller_id'=>$seller,'q'=>$code,'status'=>'','date_from'=>'','date_to'=>'','pay_method'=>'','product_type'=>''];
$csv=$call($plugin,'sn_export_report_rows','payments',$filters,100);
hr239_ok(count($csv)===3&&array_reverse(array_column($csv,'transaction_code'))===$refs,'payment CSV groups the three receipts by their actual invoice code');
$filters['q']='PAY-'.$p2;$csv=$call($plugin,'sn_export_report_rows','payments',$filters,100);hr239_ok(count($csv)===1&&$csv[0]['transaction_code']==='PAY-'.$p2,'payment CSV can find one exact internal transaction');
$filters['seller_id']=$other;hr239_ok($call($plugin,'sn_export_report_rows','payments',$filters,100)===[],'seller filter cannot attach an unrelated payment to export');
$filters['seller_id']=$seller;$filters['q']="' OR 1=1 --";hr239_ok($call($plugin,'sn_export_report_rows','payments',$filters,100)===[],'invoice search treats SQL syntax as literal text');
hr239_ok($call($plugin,'sn_zibal_export_safe_csv_cell','=1+2')==="'=1+2",'CSV formula protection retained');
hr239_ok($wpdb->insert($pay,['invoice_id'=>0,'payment_stage_no'=>1,'amount'=>1,'status'=>'pending','pay_method'=>'card','payment_source'=>'synthetic_orphan'])!==false,'synthetic unmatched payment recorded');$orphan_id=(int)$wpdb->insert_id;
$all_filters=array_merge($filters,['seller_id'=>0,'q'=>'']);$all=$call($plugin,'sn_export_report_rows','payments',$all_filters,5000);$unmatched=array_values(array_filter($all,static fn($row)=>(int)$row['payment_id']===$orphan_id));
hr239_ok(count($unmatched)===1&&$unmatched[0]['transaction_code']==='PAY-'.$orphan_id&&$unmatched[0]['stage_reference']==='','unfiltered admin export preserves unmatched payments without inventing a stage');
// A separate purchase receives a distinct invoice; its verified provider fixture never calls a bank.
$source_row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$it} WHERE id=%d",$source),ARRAY_A);unset($source_row['id']);$source_row['invoice_code']='T-G-'.bin2hex(random_bytes(4));$source_row['access_token']=bin2hex(random_bytes(24));
hr239_ok($wpdb->insert($it,$source_row)!==false,'separate synthetic purchase source');$source2=(int)$wpdb->insert_id;
$case_row=(array)$case();unset($case_row['id']);$case_row['source_invoice_id']=$source2;$case_row['paid_amount']=0;$case_row['remaining_amount']=5000000;$case_row['deposit_amount']=0;$case_row['status']='option_selected';$case_row['completed_at']=null;
hr239_ok($wpdb->insert($t['cases'],$case_row)!==false,'separate synthetic purchase case');$case2=(int)$wpdb->insert_id;
$newcase=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['cases']} WHERE id=%d",$case2));$full=$call($dot,'create_conversion_invoice',$newcase,'full',5000000.0,$admin,'Synthetic address');
hr239_ok(!is_wp_error($full)&&(int)$full['invoice']->id!==$id&&(string)$full['invoice']->invoice_code!==$code,'independent purchase receives its own invoice code');$online_id=(int)$full['invoice']->id;
$bank='synthetic-'.bin2hex(random_bytes(4));hr239_ok($wpdb->insert($pay,['invoice_id'=>$online_id,'payment_stage_no'=>1,'amount'=>5000000,'status'=>'verified','authority'=>'zibal:'.random_int(10000000,99999999),'payment_source'=>'zibal','pay_method'=>'online','ref_id'=>$bank])!==false,'verified provider fixture without network');$online_payment=(int)$wpdb->insert_id;
$wrong=$call($plugin,'sn_finalize_current_payment_stage',$online_id,'online','zibal','wrong-reference',0,'paid',1);hr239_ok(empty($wrong['success'])&&(string)$wpdb->get_var($wpdb->prepare("SELECT status FROM {$pay} WHERE id=%d",$online_payment))==='verified','mismatched bank reference cannot approve the stage');
$paid=$call($plugin,'sn_finalize_current_payment_stage',$online_id,'online','zibal',$bank,0,'paid',1);
hr239_ok(!empty($paid['success'])&&(string)$wpdb->get_var($wpdb->prepare("SELECT status FROM {$pay} WHERE id=%d",$online_payment))==='paid'&&(float)$wpdb->get_var($wpdb->prepare("SELECT paid_amount FROM {$t['cases']} WHERE id=%d",$case2))===5000000.0,'matching verified gateway receipt settles only its own purchase');
// Existing cancellation/reversal rules, not the future service-status feature.
$wpdb->update($it,['status'=>'cancelled','invoice_status'=>'cancelled','payment_status'=>'cancelled','payment_workflow_status'=>'archived'],['id'=>$id]);
$engine=SN_Purpose_Commission::instance();$engine->on_invoice_financial_rejected($id,$invoice(),'لغو توسط مالی: synthetic reversal');
$net=static fn()=>(float)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(CASE WHEN direction='credit' THEN amount ELSE -amount END),0) FROM {$wallet_table} WHERE invoice_id=%d AND user_id=%d",$id,$seller));
hr239_ok($net()===0.0&&(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wallet_table} WHERE invoice_id=%d AND direction='debit' AND user_id=%d",$id,$seller))===3,'existing full cancellation reverses the three receipt commissions without deleting their history');
$after_cancel=wp_json_encode($wpdb->get_results($wpdb->prepare("SELECT * FROM {$wallet_table} WHERE invoice_id=%d ORDER BY id",$id),ARRAY_A));
$engine->on_invoice_financial_rejected($id,$invoice(),'لغو توسط مالی: repeated synthetic reversal');$engine->reconcile_invoice($id);
hr239_ok($net()===0.0&&wp_json_encode($wpdb->get_results($wpdb->prepare("SELECT * FROM {$wallet_table} WHERE invoice_id=%d ORDER BY id",$id),ARRAY_A))===$after_cancel,'cancellation replay cannot debit again or recreate commission on a cancelled invoice');
$summary=['passed'=>true,'assertions'=>$GLOBALS['hr239_checks'],'php'=>PHP_VERSION,'wordpress'=>$GLOBALS['wp_version'],'mysql'=>$wpdb->db_version(),'version'=>SN_VERSION,'source_version'=>SN_VERSION,'core_sha256'=>hash_file('sha256',SN_PLUGIN_DIR.'includes/class-sn-plugin.php'),'commission_sha256'=>hash_file('sha256',SN_PLUGIN_DIR.'includes/class-sn-purpose-commission.php'),'synthetic'=>true,'production_touched'=>false];
file_put_contents($GLOBALS['hr239_output'].'/trace242-native.json',wp_json_encode($summary,JSON_PRETTY_PRINT));echo 'SUMMARY '.wp_json_encode($summary).PHP_EOL;
echo 'TOTAL '.$GLOBALS['hr239_checks'].PHP_EOL;ob_end_flush();
