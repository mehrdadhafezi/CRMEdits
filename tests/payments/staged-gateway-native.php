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
$product=new WC_Product_Simple();$product->set_name('Synthetic trace purchase');$product->set_status('publish');$product->set_regular_price('10000000');$product->set_virtual(true);$pid=$product->save();update_post_meta($pid,'_sn_enabled','1');
$projects=SN_Projects::instance();$pt=$call($projects,'tables');
hr239_ok($wpdb->insert($pt['subscriptions'],['subscription_product_id'=>$pid,'is_active'=>1])!==false,'synthetic subscription product mapped');
$cache=new ReflectionProperty($projects,'subscription_ids_cache');$cache->setAccessible(true);$cache->setValue($projects,null);
$phone='09'.random_int(100000000,999999999);$now=current_time('mysql');$it=$wpdb->prefix.'sn_invoices';$st=$wpdb->prefix.'sn_invoice_payment_stages';$pay=$wpdb->prefix.'sn_payments';$t=$call($dot,'tables');
hr239_ok($wpdb->insert($it,['invoice_code'=>'T-S-'.bin2hex(random_bytes(4)),'seller_id'=>$seller,'issued_by_user_id'=>$seller,'customer_name'=>'Synthetic trace only','customer_phone'=>$phone,'customer_address'=>'Synthetic address','product_id'=>$pid,'product_price'=>10000000,'final_total'=>10000000,'status'=>'approved','access_token'=>bin2hex(random_bytes(24)),'created_at'=>$now,'updated_at'=>$now])!==false,'independent synthetic source invoice');$source=(int)$wpdb->insert_id;
hr239_ok($wpdb->insert($t['cases'],['source_invoice_id'=>$source,'source_product_id'=>$pid,'seller_id'=>$seller,'supervisor_id'=>$f['users']['supervisor_a']['id'],'converter_id'=>$admin,'customer_name'=>'Synthetic trace only','customer_phone'=>$phone,'selected_option_key'=>'trace_offer','selected_option_product_id'=>$pid,'selected_option_title'=>'Synthetic trace purchase','selected_option_price'=>10000000,'options_snapshot_json'=>wp_json_encode([['key'=>'trace_offer','product_id'=>$pid,'price'=>10000000,'active'=>1]]),'status'=>'option_selected','remaining_amount'=>10000000,'created_at'=>$now,'updated_at'=>$now])!==false,'synthetic conversion purchase');$case_id=(int)$wpdb->insert_id;
$case=static fn()=>$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['cases']} WHERE id=%d",$case_id));
$first=$call($dot,'create_conversion_invoice',$case(),'deposit',5000000.0,$admin,'Synthetic address');
if(is_wp_error($first)){throw new RuntimeException('FAIL first creation '.$first->get_error_code());}
hr239_ok(!empty($first['invoice']),'first installment creates invoice');$id=(int)$first['invoice']->id;$code=(string)$first['invoice']->invoice_code;$token=(string)$first['invoice']->access_token;
$invoice=static fn()=>$wpdb->get_row($wpdb->prepare("SELECT * FROM {$it} WHERE id=%d",$id));
hr239_ok((float)$invoice()->payment_total_amount===10000000.0&&(float)$invoice()->current_due_amount===5000000.0&&$invoice()->payment_plan==='partial','invoice stores entire purchase, first stage only its due');
hr239_ok($dot->is_staged_conversion_invoice($id),'new ledger marker recorded');

// Mock only the public provider boundary; all controllers and SQL are real.
$root=$GLOBALS['hr239_root'];$http=[];$orders=[];$refs=[];$badAmount=false;$badRef=false;
$provider=static function($pre,$args,$url)use(&$http,&$orders,&$refs,&$badAmount,&$badRef,$wpdb,$pay){
 $input=json_decode((string)($args['body']??''),true);$http[]=$url;
 if(($input['merchant']??'')!=='zibal')throw new RuntimeException('Only synthetic test merchant allowed');
 if($url==='https://gateway.zibal.ir/v1/request'){
  $track=(string)random_int(7100000000,7999999999);$orders[$track]=(string)($input['orderId']??'');$refs[$track]='SYN-'.bin2hex(random_bytes(8));
  return ['headers'=>[],'response'=>['code'=>200],'body'=>wp_json_encode(['result'=>100,'trackId'=>$track])];
 }
 if(!in_array($url,['https://gateway.zibal.ir/v1/verify','https://gateway.zibal.ir/v1/inquiry'],true))return new WP_Error('synthetic_network','External calls blocked');
 $track=(string)($input['trackId']??'');$row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$pay} WHERE authority=%s",'zibal:'.$track));
 if(!$row||!isset($orders[$track]))throw new RuntimeException('Unknown synthetic provider request');
 return ['headers'=>[],'response'=>['code'=>200],'body'=>wp_json_encode(['result'=>100,'status'=>1,'amount'=>(int)round((float)$row->amount*10)+($badAmount?10:0),'orderId'=>$orders[$track],'refNumber'=>$badRef?'SYN-DIFFERENT':$refs[$track]])];
};
add_filter('pre_http_request',$provider,PHP_INT_MAX,3);update_option('sn_zibal_test_mode','1');update_option('sn_payment_gateway','zibal');
class Stage243Redirect extends RuntimeException{}
class Stage243Response extends RuntimeException{}
$callback=static function(int $invoice_id,string $track,int $supplied_stage=1)use($plugin):string{
 wp_set_current_user(0);$_POST=[];$_GET=['sn_callback'=>'1','invoice_id'=>$invoice_id,'sn_gateway'=>'zibal','sn_stage'=>(string)$supplied_stage,'trackId'=>$track,'success'=>'1'];$_REQUEST=$_GET;$result='';
 $capture=static function($location)use(&$result){parse_str((string)parse_url($location,PHP_URL_QUERY),$q);$result=(string)($q['pay_result']??'');throw new Stage243Redirect();};
 add_filter('wp_redirect',$capture,999);try{$plugin->handle_zarinpal_callback();}catch(Stage243Redirect $e){}finally{remove_filter('wp_redirect',$capture,999);}return$result;
};
$state=static function(int $actor,int $invoice_id)use($plugin):array{
 wp_set_current_user(0);wp_set_current_user($actor);$_GET=[];$_POST=['action'=>'sn_payment_management_state','invoice_id'=>$invoice_id,'nonce'=>wp_create_nonce('sn_public')];$_REQUEST=$_POST;
 $handler=static fn()=>static function(){throw new Stage243Response();};add_filter('wp_die_handler',$handler);add_filter('wp_die_ajax_handler',$handler);ob_start();
 try{$plugin->ajax_payment_management_state();}catch(Stage243Response $e){}finally{$raw=ob_get_clean();remove_filter('wp_die_handler',$handler);remove_filter('wp_die_ajax_handler',$handler);}
 return json_decode($raw,true,512,JSON_THROW_ON_ERROR);
};
$gateway=new SN_Invoice();$net=static fn(int $invoice_id)=>(float)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(IF(direction='credit',amount,-amount)),0) FROM {$wpdb->prefix}sn_wallet_transactions WHERE invoice_id=%d AND user_id=%d",$invoice_id,$seller));
$count=static fn()=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$it}");$beforeCount=$count();
$firstRequest=$gateway->request_payment($id,5000000,'Synthetic stage one','',home_url('/'),$code,1);hr239_ok(empty($firstRequest['error'])&&!empty($firstRequest['track_id']),'actual new gateway request created');$track1=(string)$firstRequest['track_id'];
$p1=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$pay} WHERE authority=%s",'zibal:'.$track1),ARRAY_A);
hr239_ok($p1['status']==='pending'&&(float)$p1['amount']===5000000.0,'new provider request begins pending for five million');
hr239_ok($orders[$track1]===$code.'/1'&&SN_Invoice::stored_gateway_order_id($p1,$code)===$code.'/1','stage one order reference is persisted with slash one');
$calls=count($http);$reuse=$gateway->request_payment($id,5000000,'Synthetic stage one','',home_url('/'),$code,1);hr239_ok(!empty($reuse['reused'])&&count($http)===$calls&&$reuse['authority']===$firstRequest['authority'],'retry reuses original provider request without another transaction');
$inquiry=$gateway->zibal_inquiry($track1,5000000,SN_Invoice::stored_gateway_order_id($p1,$code));hr239_ok(!empty($inquiry['success']),'new suffixed order survives strict provider inquiry');
hr239_ok(SN_Invoice::stored_gateway_order_id(['payment_stage_no'=>1,'proof_data'=>null],$code)===$code,'historical pending requests retain their original unsuffixed order');
hr239_ok(SN_Invoice::stored_gateway_order_id(['payment_stage_no'=>1,'proof_data'=>wp_json_encode(['_gateway_order_id'=>$code.'/2'])],$code)==='','mismatched stored stage reference fails closed');
hr239_ok($callback($id,$track1)==='partial_success','real pending-to-verified public callback records first stage');
$fresh=$invoice();$one=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$st} WHERE invoice_id=%d AND stage_no=1",$id));
hr239_ok((float)$fresh->paid_total_amount===5000000.0&&(float)$fresh->remaining_amount===5000000.0&&$fresh->status==='partial_paid','five million received and five million outstanding on original invoice');
hr239_ok(in_array($one->status,['paid','approved'],true)&&$one->payment_ref_id===$refs[$track1],'stage one has its own paid status and bank reference');
hr239_ok((string)$fresh->invoice_code===$code&&(string)$fresh->access_token===$token&&$count()===$beforeCount,'first payment does not change invoice code token or invoice count');
hr239_ok($net($id)===500000.0,'first approved receipt credits only its own proportional commission');
$sellerState=$state($seller,$id);$sf=$sellerState['data']['finance']??[];
hr239_ok(!empty($sellerState['success'])&&($sf['stage_invoice_code']??'')===$code.'/1'&&!empty($sf['stage_paid']),'seller sees paid slash-one stage without Finance permission');
hr239_ok((float)$sf['approved']===5000000.0&&(float)$sf['remaining']===5000000.0&&(float)$sf['stage_amount']===5000000.0,'seller financial summary distinguishes stage payment from purchase settlement');
$foreign=$state($other,$id);hr239_ok(empty($foreign['success']),'foreign seller cannot inspect paid stage');
$rows=SN_Payment_Transactions::rows($fresh);hr239_ok(count($rows)===1&&$rows[0]['transaction_code']==='PAY-'.$p1['id']&&$rows[0]['stage_invoice_code']===$code.'/1','history links actual unique receipt to slash-one reference');
hr239_ok($callback($id,$track1)==='partial_success'&&$net($id)===500000.0&&(float)$invoice()->paid_total_amount===5000000.0,'repeated first callback cannot duplicate money or commission');
wp_set_current_user($admin);$next=$plugin->issue_dot_conversion_payment_stage($case_id,$id,1,$admin,'remaining',0.0);
hr239_ok(!empty($next['success'])&&(int)$next['stage_no']===2,'second installment issued through actual owned stage issuer');
$secondRequest=$gateway->request_payment($id,5000000,'Synthetic stage two','',home_url('/'),$code,2);hr239_ok(empty($secondRequest['error'])&&!empty($secondRequest['track_id']),'second stage has independent real provider request');$track2=(string)$secondRequest['track_id'];
$p2=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$pay} WHERE authority=%s",'zibal:'.$track2),ARRAY_A);
hr239_ok($track2!==$track1&&$p2['id']!==$p1['id']&&$orders[$track2]===$code.'/2','two stages have different gateway and PAY identities with slash references');
hr239_ok($callback($id,$track1)==='partial_success'&&(float)$invoice()->paid_total_amount===5000000.0&&$net($id)===500000.0,'late stage-one callback does not consume stage-two balance');
hr239_ok($callback($id,$track2,99)==='success','stored request stage overrides forged browser stage number');
$fresh=$invoice();hr239_ok((float)$fresh->paid_total_amount===10000000.0&&(float)$fresh->remaining_amount===0.0&&in_array($fresh->status,['paid','approved'],true),'purchase completes only after both five-million stages');
hr239_ok($fresh->invoice_code===$code&&$fresh->access_token===$token&&$count()===$beforeCount,'both installments retain exactly one purchase invoice and token');
hr239_ok($net($id)===1000000.0,'commission sums two independent receipts exactly once');
$completed=$state($seller,$id);hr239_ok(!empty($completed['success'])&&$completed['data']['finance']['stage_invoice_code']===$code.'/2'&&!empty($completed['data']['finance']['stage_paid']),'seller sees paid slash-two and full settlement');
hr239_ok($callback($id,$track2)==='success'&&$net($id)===1000000.0,'completed callback replay does not credit again');
$badRef=true;$bad=$callback($id,$track2);$badRef=false;$saved=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$pay} WHERE id=%d",$p2['id']));
hr239_ok($bad==='failed'&&in_array($saved->status,['paid','approved'],true)&&$saved->ref_id===$refs[$track2]&&$net($id)===1000000.0,'conflicting replay cannot demote approved receipt or replace bank reference');

// Additional private purchases isolate each negative/rollback scenario.
$make=static function()use($wpdb,$it,$t,$source,$case,$call,$dot,$admin):array{
 $src=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$it} WHERE id=%d",$source),ARRAY_A);unset($src['id']);$src['invoice_code']='SG-S-'.bin2hex(random_bytes(5));$src['access_token']=bin2hex(random_bytes(24));if(!$wpdb->insert($it,$src))throw new RuntimeException('Synthetic source insert');$sid=(int)$wpdb->insert_id;
 $c=(array)$case();unset($c['id']);$c['source_invoice_id']=$sid;$c['paid_amount']=0;$c['remaining_amount']=10000000;$c['deposit_amount']=0;$c['status']='option_selected';$c['completed_at']=null;if(!$wpdb->insert($t['cases'],$c))throw new RuntimeException('Synthetic case insert');$cid=(int)$wpdb->insert_id;
 wp_set_current_user($admin);$freshcase=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['cases']} WHERE id=%d",$cid));$r=$call($dot,'create_conversion_invoice',$freshcase,'deposit',5000000.0,$admin,'Synthetic address');if(is_wp_error($r))throw new RuntimeException('Synthetic deposit creation');return[(int)$r['invoice']->id,(string)$r['invoice']->invoice_code];
};
$newRequest=static function(int $iid,string $ic)use($gateway,$wpdb,$pay):array{$r=$gateway->request_payment($iid,5000000,'Synthetic rollback stage','',home_url('/'),$ic,1);if(!empty($r['error']))throw new RuntimeException('Synthetic request unavailable');$row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$pay} WHERE authority=%s",$r['authority']),ARRAY_A);return[(string)$r['track_id'],(int)$row['id']];};
$unchanged=static function(int $iid)use($wpdb,$it,$st,$net):bool{$i=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$it} WHERE id=%d",$iid));$stage=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$st} WHERE invoice_id=%d AND stage_no=1",$iid));return(float)$i->paid_total_amount===0.0&&(float)$i->remaining_amount===10000000.0&&$stage->status==='pending'&&$net($iid)===0.0;};
[$badId,$badCode]=$make();[$badTrack,$badPayment]=$newRequest($badId,$badCode);
$badAmount=true;$failedAmount=$callback($badId,$badTrack);$badAmount=false;
hr239_ok($failedAmount==='failed'&&$unchanged($badId),'mismatched verified provider amount cannot post stage or commission');
hr239_ok($callback($badId,$track1)==='failed'&&$unchanged($badId),'another invoice gateway request cannot satisfy this purchase');
foreach(['request','stage','invoice','adapter']as$failure){
 [$fid,$fc]=$make();[$ft,$fp]=$newRequest($fid,$fc);
 $table=['request'=>$pay,'stage'=>$st,'invoice'=>$it,'adapter'=>$t['cases']][$failure];
 $inject=static function($sql)use($table,$failure){if(!preg_match('/^UPDATE\s+`?'.preg_quote($table,'/').'`?\s+SET\s/i',$sql))return$sql;if($failure==='request'&&!preg_match("/SET\s+`status`\s*=\s*'verified'/i",$sql))return$sql;return'SELECT staged243_missing FROM staged243_missing_table';};
 add_filter('query',$inject);try{$result=$callback($fid,$ft);}finally{remove_filter('query',$inject);}
 hr239_ok($result==='failed'&&$unchanged($fid),'SQL '.$failure.' failure rolls back complete financial stage');
 $saved=$wpdb->get_row($wpdb->prepare("SELECT status,ref_id FROM {$pay} WHERE id=%d",$fp));
 hr239_ok($saved->status==='reconcile_error'&&$saved->ref_id===$refs[$ft],'SQL '.$failure.' failure retains verified bank reference for reconciliation');
}
[$heldId,$heldCode]=$make();[$heldTrack,$heldPayment]=$newRequest($heldId,$heldCode);
$proof=['_gateway_order_id'=>$heldCode.'/1','_gateway_manual_selection'=>['version'=>1,'actor_user_id'=>$admin,'provider_status'=>-1]];$wpdb->update($pay,['status'=>'manual_hold','proof_data'=>wp_json_encode($proof)],['id'=>$heldPayment]);
hr239_ok($callback($heldId,$heldTrack)==='pending_finance'&&$unchanged($heldId),'late bank payment after manual selection does not auto-credit');
hr239_ok($wpdb->get_var($wpdb->prepare("SELECT ref_id FROM {$pay} WHERE id=%d",$heldPayment))===$refs[$heldTrack],'manual-selection conflict retains actual verified bank reference');
[$legacyId,$legacyCode]=$make();[$legacyTrack,$legacyPayment]=$newRequest($legacyId,$legacyCode);$wpdb->update($pay,['proof_data'=>null],['id'=>$legacyPayment]);$orders[$legacyTrack]=$legacyCode;
$old=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$pay} WHERE id=%d",$legacyPayment),ARRAY_A);$oldInquiry=$gateway->zibal_inquiry($legacyTrack,5000000,SN_Invoice::stored_gateway_order_id($old,$legacyCode));
hr239_ok(!empty($oldInquiry['success']),'actual historical unsuffixed order still matches inquiry');
hr239_ok($callback($legacyId,$legacyTrack)==='partial_success'&&(float)$wpdb->get_var($wpdb->prepare("SELECT paid_total_amount FROM {$it} WHERE id=%d",$legacyId))===5000000.0,'historical pending gateway session completes without reissuing invoice or payment');
// Preserve a fresh, unmodified pending request for real two-process callback races.
[$raceId,$raceCode]=$make();[$raceTrack,$racePayment]=$newRequest($raceId,$raceCode);
file_put_contents($root.'/staged243-race-fixture.json',wp_json_encode(['invoice_id'=>$raceId,'invoice_code'=>$raceCode,'track'=>$raceTrack,'payment_id'=>$racePayment,'ref'=>$refs[$raceTrack],'seller_id'=>$seller]));
$summary=['passed'=>true,'assertions'=>$GLOBALS['hr239_checks'],'version'=>SN_VERSION,'wordpress'=>$GLOBALS['wp_version'],'php'=>PHP_VERSION,'mysql'=>$wpdb->db_version(),'core_sha256'=>hash_file('sha256',SN_PLUGIN_DIR.'includes/class-sn-plugin.php'),'invoice_gateway_sha256'=>hash_file('sha256',SN_PLUGIN_DIR.'includes/class-sn-invoice.php'),'synthetic'=>true,'actual_public_callback'=>true,'actual_seller_controller'=>true,'real_sms_sent'=>false,'real_provider_calls'=>0,'production_touched'=>false];
file_put_contents($GLOBALS['hr239_output'].'/staged243-native.json',wp_json_encode($summary,JSON_PRETTY_PRINT));echo 'SUMMARY '.wp_json_encode($summary).PHP_EOL;echo 'TOTAL '.$GLOBALS['hr239_checks'].PHP_EOL;ob_end_flush();
