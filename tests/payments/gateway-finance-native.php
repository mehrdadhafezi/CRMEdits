<?php
/** Real synthetic WordPress/MySQL; never contact a provider or load customer data. */
ob_start();require dirname(__DIR__).'/hr/native-support.php';hr239_bootstrap();$f=hr239_data();global $wpdb;
$admin=(int)$f['admin_id'];$seller=(int)$f['users']['seller_a']['id'];wp_set_current_user($admin);
$plugin=sn_bootstrap_plugin_instance();$it=$wpdb->prefix.'sn_invoices';$st=$wpdb->prefix.'sn_invoice_payment_stages';$pt=$wpdb->prefix.'sn_payments';
$call=static function(string $name,...$args)use($plugin){$m=new ReflectionMethod($plugin,$name);$m->setAccessible(true);return$m->invoke($plugin,...$args);};
class Gateway245Response extends RuntimeException{}
function gateway245_response(bool $ok,string $message,array $data=[]):void{$GLOBALS['g245_response']=['success'=>$ok,'message'=>$message,'data'=>$data];throw new Gateway245Response();}
$controller=static function(string $name)use($plugin):Closure{
 $m=new ReflectionMethod($plugin,$name);$lines=file($m->getFileName());$source=implode('',array_slice($lines,$m->getStartLine()-1,$m->getEndLine()-$m->getStartLine()+1));
 $body=substr($source,strpos($source,'{')+1);$body=substr($body,0,strrpos($body,'}'));
 return (eval('return function(?callable $guard=null){'.str_replace('SN_Helpers::send_json','gateway245_response',$body).'};'))->bindTo($plugin,SN_Plugin::class);
};
$approve=$controller('ajax_financial_approve_payment');$list=$controller('ajax_financial_invoices');
$request=static function(Closure $handler,int $actor,array $data,?callable $guard=null):array{
 wp_set_current_user(0);wp_set_current_user($actor);$_GET=[];$_POST=array_replace(['nonce'=>wp_create_nonce('sn_public')],$data);$_REQUEST=$_POST;
 try{$handler($guard);}catch(Gateway245Response $e){}return$GLOBALS['g245_response'];
};
$fixture=static function(array $payment_changes=[],array $stage_changes=[],bool $historical=false)use($wpdb,$it,$st,$pt,$seller):array{
 $now=current_time('mysql');$code='G245-'.bin2hex(random_bytes(5));$ref='SYN-'.bin2hex(random_bytes(6));
 hr239_ok($wpdb->insert($it,['invoice_code'=>$code,'seller_id'=>$seller,'issued_by_user_id'=>$seller,'customer_name'=>'Synthetic gateway Finance','customer_phone'=>'00000000000','product_price'=>190000000,'final_total'=>190000000,'payment_total_amount'=>190000000,'paid_total_amount'=>0,'remaining_amount'=>190000000,'current_due_amount'=>30000000,'current_payment_stage'=>1,'payment_plan'=>'partial','payment_purpose'=>'subscription_plus_cash','status'=>'pending_financial_approval','payment_status'=>'pending_financial_approval','invoice_status'=>'pending_financial_approval','payment_workflow_status'=>'awaiting_financial_approval','pay_method'=>'online','payment_source'=>'zibal','access_token'=>bin2hex(random_bytes(24)),'created_at'=>$now,'updated_at'=>$now])!==false,'synthetic credit invoice inserted');$id=(int)$wpdb->insert_id;
 hr239_ok($wpdb->insert($st,array_replace(['invoice_id'=>$id,'stage_no'=>1,'stage_type'=>'partial','requested_amount'=>30000000,'status'=>'pending_financial_approval','pay_method'=>'online','payment_source'=>'zibal','payment_ref_id'=>$ref],$stage_changes))!==false,'synthetic online stage inserted');$stage=(int)$wpdb->insert_id;
 hr239_ok($wpdb->insert($wpdb->prefix.'sn_dot_invoice_links',['invoice_id'=>$id,'case_id'=>0,'flow_kind'=>'conversion_cash','payment_kind'=>'full','requires_finance'=>1,'created_at'=>$now,'updated_at'=>$now])!==false,'synthetic conversion link inserted');
 hr239_ok($wpdb->insert($pt,array_replace(['invoice_id'=>$id,'payment_stage_no'=>1,'amount'=>30000000,'status'=>'verified','authority'=>'zibal:'.random_int(100000000,999999999),'ref_id'=>$ref,'pay_method'=>'online','payment_source'=>'zibal','proof_data'=>'{}','created_at'=>$now,'updated_at'=>$now],$payment_changes))!==false,'synthetic verified receipt inserted');$payment=(int)$wpdb->insert_id;
 if($historical)hr239_ok($wpdb->insert($pt,['invoice_id'=>$id,'payment_stage_no'=>1,'amount'=>30000000,'status'=>'rejected','authority'=>'','pay_method'=>'card','payment_source'=>'seller_manual','proof_data'=>'{"entry_mode":"info"}'])!==false,'synthetic historical rejected manual receipt inserted');
 return compact('id','stage','payment','ref','code');
};
$state=static fn($x)=>$wpdb->get_row($wpdb->prepare("SELECT paid_total_amount,remaining_amount,status,pay_method FROM {$it} WHERE id=%d",$x['id']),ARRAY_A);
$one=$fixture();
// Replay the exact former finalizer body on a separate fixture to prove the bug.
$before=file_get_contents($GLOBALS['hr239_root'].'/gateway245-before-source.php');$a=strpos($before,'private function sn_finalize_current_payment_stage(');$b=strpos($before,'private function sn_send_invoice_stage_sms(',$a);$method=substr($before,$a,$b-$a);$method=substr($method,0,strrpos($method,'}')+1);
$old=(eval(str_replace('private function sn_finalize_current_payment_stage','return function',$method).';'))->bindTo($plugin,SN_Plugin::class);
$bad=$old($one['id'],'card','finance_approval','',$admin,'approved',1,true);
hr239_ok(empty($bad['success'])&&str_contains($bad['message'],'درگاه'),'reviewed baseline reproduces verified gateway approval failure');
hr239_ok((float)$state($one)['paid_total_amount']===0.0,'baseline failure does not credit money');
$out=$request($approve,$admin,['invoice_id'=>$one['id']]);hr239_ok(!empty($out['success']),'authorized Finance controller approves verified first stage');
$current=$state($one);hr239_ok((float)$current['paid_total_amount']===30000000.0&&(float)$current['remaining_amount']===160000000.0&&$current['status']==='partial_paid'&&$current['pay_method']==='online','only first stage is credited and online method is preserved');
hr239_ok($wpdb->get_var($wpdb->prepare("SELECT status FROM {$pt} WHERE id=%d",$one['payment']))==='paid','same gateway receipt becomes paid without inserting a duplicate');
hr239_ok((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$pt} WHERE invoice_id=%d",$one['id']))===1,'approval preserves one payment identity');
hr239_ok(!empty($request($approve,$admin,['invoice_id'=>$one['id']])['success'])&&(float)$state($one)['paid_total_amount']===30000000.0,'duplicate approval is idempotent');
$negative=[['amount'=>29000000],['ref_id'=>''],['ref_id'=>'SYN-WRONG'],['payment_source'=>'other'],['status'=>'pending'],['status'=>'reconcile_error']];
foreach($negative as $changes){$x=$fixture($changes);$r=$request($approve,$admin,['invoice_id'=>$x['id']]);hr239_ok(empty($r['success'])&&(float)$state($x)['paid_total_amount']===0.0,'unverified, mismatched or ambiguous receipt cannot be approved');}
$denied=$fixture();hr239_ok(empty($request($approve,$seller,['invoice_id'=>$denied['id']])['success']),'seller without Finance capability cannot approve gateway');
hr239_ok(empty($request($approve,$admin,['invoice_id'=>$denied['id'],'payment_stage_no'=>2])['success']),'stale displayed stage cannot approve a different stage');
hr239_ok(empty($request($approve,$admin,['invoice_id'=>$denied['id'],'payment_stage_no'=>['1']])['success']),'malformed expected stage cannot bypass validation');
hr239_ok(empty($request($approve,$admin,['invoice_id'=>$denied['id'],'nonce'=>'invalid'])['success']),'invalid nonce cannot approve gateway');
hr239_ok(empty($request($approve,$admin,['invoice_id'=>$denied['id']],static fn()=>new WP_Error('synthetic_scope','denied'))['success']),'record scope guard remains effective');
$dupe=$fixture();$r=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$pt} WHERE id=%d",$dupe['payment']),ARRAY_A);unset($r['id']);$r['authority']='zibal:'.random_int(100000000,999999999);$wpdb->insert($pt,$r);
hr239_ok(empty($request($approve,$admin,['invoice_id'=>$dupe['id']])['success'])&&(float)$state($dupe)['paid_total_amount']===0.0,'multiple verified requests remain blocked');
$mixed=$fixture();$wpdb->insert($pt,['invoice_id'=>$mixed['id'],'payment_stage_no'=>1,'amount'=>1,'status'=>'pending','authority'=>'','pay_method'=>'card','proof_data'=>'{"entry_mode":"info"}']);
hr239_ok(empty($request($approve,$admin,['invoice_id'=>$mixed['id']])['success']),'live manual receipt prevents gateway bulk approval');
$rollback=$fixture();$fail=static function($sql)use($pt){if(str_starts_with($sql,"UPDATE {$pt} SET status='paid'"))throw new RuntimeException('Synthetic payment write failure');return$sql;};add_filter('query',$fail,999);
try{$failed=$request($approve,$admin,['invoice_id'=>$rollback['id']]);}catch(RuntimeException $e){$wpdb->query('ROLLBACK');$failed=['success'=>false];}finally{remove_filter('query',$fail,999);}
hr239_ok(empty($failed['success'])&&(float)$state($rollback)['paid_total_amount']===0.0&&$wpdb->get_var($wpdb->prepare("SELECT status FROM {$st} WHERE id=%d",$rollback['stage']))==='pending_financial_approval','failed payment write rolls back stage and invoice');
$history=$fixture([],[],true);$rows=$request($list,$admin,['q'=>$history['code'],'tab'=>'all','limit'=>10,'page'=>1]);$row=($rows['data']['items']??[])[0]??[];
hr239_ok(!empty($row['review_gateway_stage'])&&!empty($row['can_approve_payment']),'historical rejected manual receipt does not hide gateway Finance route');
$evidence=['passed'=>true,'synthetic'=>true,'production_touched'=>false,'version'=>SN_VERSION,'assertions'=>$GLOBALS['hr239_checks'],'baseline_reproduced'=>true,'real_wordpress_mysql'=>true,'scope_nonce_negative'=>true,'rollback'=>true,'duplicate_guard'=>true,'core_sha256'=>hash_file('sha256',SN_PLUGIN_DIR.'includes/class-sn-plugin.php')];
file_put_contents($GLOBALS['hr239_output'].'/gateway245-native.json',wp_json_encode($evidence));
echo 'TOTAL '.$GLOBALS['hr239_checks'].PHP_EOL;ob_end_flush();
