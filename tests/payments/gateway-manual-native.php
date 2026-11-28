<?php
/** Native WordPress/MySQL regression, guarded disposable synthetic sites only. */
ob_start();
if(getenv('SN_APPROVAL_ALLOW_SYNTHETIC')==='YES'){
    require __DIR__.'/integration/support.php';sn_test_bootstrap();sn_bootstrap_load_core();sn_bootstrap_plugin_instance(true);
    $test_root=(string)getenv('SN_APPROVAL_ROOT');
}else{
    require dirname(__DIR__).'/hr/native-support.php';hr239_bootstrap();
    if(!defined('SN_GATEWAY240_PRIVATE_TEST')||SN_GATEWAY240_PRIVATE_TEST!==true)throw new RuntimeException('Gateway synthetic namespace required');
    $test_root=$GLOBALS['hr239_root'];
}
if(!defined('DOING_AJAX'))define('DOING_AJAX',true);
if(SN_VERSION!=='2.0.247')throw new RuntimeException('Exact Gateway240 candidate required');
global $wpdb;$checks=0;$batch='G240-'.bin2hex(random_bytes(3));$plugin=sn_bootstrap_plugin_instance();
function gm_ok($value,string $label):void{global $checks;if(!$value)throw new RuntimeException('FAIL '.$label);$checks++;echo 'PASS '.$label.PHP_EOL;}
function gm_call(string $name,...$args){global $plugin;$m=new ReflectionMethod(SN_Plugin::class,$name);$m->setAccessible(true);return $m->invoke($plugin,...$args);}
class Gateway240Response extends RuntimeException{}
class Gateway240Redirect extends RuntimeException{public string $location;public function __construct(string $location){$this->location=$location;parent::__construct('Synthetic redirect');}}
function gm_request(int $actor,string $action,array $data=[],?string $nonce=null):array{
    wp_set_current_user(0);wp_set_current_user($actor);http_response_code(200);
    $_GET=[];$_FILES=[];$_POST=array_merge(['action'=>$action,'nonce'=>$nonce??wp_create_nonce('sn_public')],$data);$_REQUEST=$_POST;
    $captured=null;$status=200;
    // An HTTP request ends at the first wp_die. Preserve that boundary when a CLI exception is caught by a controller.
    $handler=static function()use(&$captured,&$status){return static function(...$args)use(&$captured,&$status):void{if($captured===null){$captured=ob_get_contents();$status=http_response_code();}throw new Gateway240Response();};};add_filter('wp_die_ajax_handler',$handler);
    ob_start();try{
        // The gateway action is not registered for guests; also verify its handler denies them.
        if(!$actor&&$action==='sn_payment_gateway_check')sn_bootstrap_plugin_instance()->ajax_payment_gateway_check();
        else do_action($actor?'wp_ajax_'.$action:'wp_ajax_nopriv_'.$action);
    }catch(Gateway240Response $end){}
    finally{$raw=ob_get_clean();remove_filter('wp_die_ajax_handler',$handler);}
    $reply=json_decode($captured??$raw,true,512,JSON_THROW_ON_ERROR);$reply['_status']=$status;return $reply;
}
function gm_clear_rate(int $actor,int $invoice):void{foreach(['check','switch_to_manual']as$op)delete_transient('sn_gateway_'.$op.'_'.$actor.'_'.$invoice);}
function gm_bank_callback(int $invoice,string $track):string{
    global $plugin;wp_set_current_user(0);$_POST=[];$_GET=['sn_callback'=>'1','invoice_id'=>$invoice,'sn_gateway'=>'zibal','sn_stage'=>'1','trackId'=>$track,'success'=>'1'];$_REQUEST=$_GET;
    $capture=static function($location){throw new Gateway240Redirect($location);};add_filter('wp_redirect',$capture,999);
    try{$plugin->handle_zarinpal_callback();}catch(Gateway240Redirect $r){parse_str((string)parse_url($r->location,PHP_URL_QUERY),$query);return (string)($query['pay_result']??'');}
    finally{remove_filter('wp_redirect',$capture,999);}
    throw new RuntimeException('Expected gateway redirect');
}
$users=[];$credentials=[];
foreach(['seller'=>'sn_seller','other'=>'sn_seller','finance'=>'subscriber','unrelated'=>'subscriber']as$key=>$role){
    $password=bin2hex(random_bytes(24));$login=$batch.'-'.$key;
    $id=wp_insert_user(['user_login'=>$login,'user_pass'=>$password,'user_email'=>strtolower($batch).'-'.$key.'@example.invalid','role'=>$role]);
    gm_ok(!is_wp_error($id),'disposable gateway actor '.$key);$users[$key]=(int)$id;
    $credentials[$key]=['id'=>(int)$id,'login'=>$login,'password'=>$password];
}
$finance=get_user_by('id',$users['finance']);foreach(['sn_view_finance','sn_approve_payment','sn_reject_payment']as$cap)$finance->add_cap($cap);
update_option('sn_card_to_card_enabled','1');update_option('sn_payment_gateway','zibal');update_option('sn_zibal_test_mode','1');
$cases=[];$tracks=[];$payments=[];$tokens=[];
$new=static function(string $key,array $extra=[])use($wpdb,$users,$batch,&$cases,&$tracks,&$payments,&$tokens):int{
    $row=array_replace(['invoice_code'=>$batch.'-'.$key,'seller_id'=>$users['seller'],'issued_by_user_id'=>$users['seller'],'customer_name'=>'ساختگی <img src=x onerror=window.__gwXss=1>','customer_phone'=>'00000000000','product_price'=>1980000,'final_total'=>1980000,'status'=>'pending','invoice_status'=>'pending','payment_status'=>'pending','payment_plan'=>'full','payment_total_amount'=>1980000,'paid_total_amount'=>0,'remaining_amount'=>1980000,'current_due_amount'=>1980000,'current_payment_stage'=>1,'payment_workflow_status'=>'awaiting_payment','pay_method'=>'online','payment_source'=>'zibal','access_token'=>bin2hex(random_bytes(32)),'created_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')],$extra);
    gm_ok($wpdb->insert($wpdb->prefix.'sn_invoices',$row)!==false,'synthetic invoice '.$key);$id=(int)$wpdb->insert_id;$cases[$key]=$id;$tokens[$key]=$row['access_token'];
    gm_ok($wpdb->insert($wpdb->prefix.'sn_invoice_payment_stages',['invoice_id'=>$id,'stage_no'=>1,'stage_type'=>'full','requested_amount'=>1980000,'status'=>'pending','pay_method'=>'online','payment_source'=>'zibal'])!==false,'synthetic stage '.$key);
    $tracks[$key]=(string)random_int(240000000,249999999);
    gm_ok($wpdb->insert($wpdb->prefix.'sn_payments',['invoice_id'=>$id,'payment_stage_no'=>1,'authority'=>'zibal:'.$tracks[$key],'amount'=>1980000,'status'=>'pending','pay_method'=>'online','payment_source'=>'zibal','created_at'=>'2020-01-01 00:00:00','updated_at'=>'2020-01-01 00:00:00'])!==false,'old unresolved request '.$key);$payments[$key]=(int)$wpdb->insert_id;
    return $id;
};
$hash=static function(int $id)use($wpdb):string{$out=[];foreach(['sn_invoices','sn_invoice_payment_stages','sn_payments']as$t){$where=$t==='sn_invoices'?'id':'invoice_id';$out[$t]=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}{$t} WHERE {$where}=%d ORDER BY id",$id),ARRAY_A);}return hash('sha256',wp_json_encode($out));};
$statuses=[];$http_calls=[];$orderMismatch=false;$amountMismatch=false;$providerFailure=false;
$provider=static function($pre,$args,$url)use(&$statuses,&$http_calls,&$orderMismatch,&$amountMismatch,&$providerFailure,$wpdb){
    if(!in_array($url,['https://gateway.zibal.ir/v1/inquiry','https://gateway.zibal.ir/v1/verify'],true))return new WP_Error('synthetic_provider_block','Only synthetic inquiry and verify permitted');
    $payload=json_decode($args['body'],true);$track=(string)$payload['trackId'];$http_calls[]=$url;
    if($providerFailure)return new WP_Error('synthetic_timeout','Synthetic provider unavailable');
    $row=$wpdb->get_row($wpdb->prepare("SELECT i.invoice_code,p.amount FROM {$wpdb->prefix}sn_payments p JOIN {$wpdb->prefix}sn_invoices i ON i.id=p.invoice_id WHERE p.authority=%s",'zibal:'.$track));
    $reply=['result'=>100,'status'=>$url==='https://gateway.zibal.ir/v1/verify'?1:($statuses[$track]??-1),'amount'=>$amountMismatch?1:(int)round((float)$row->amount*10),'orderId'=>$orderMismatch?'SYNTHETIC-WRONG':$row->invoice_code,'refNumber'=>'SYNREF-'.$track];
    return ['headers'=>[],'response'=>['code'=>200],'body'=>wp_json_encode($reply),'cookies'=>[]];
};add_filter('pre_http_request',$provider,999,3);
try{
    $id=$new('manual');$before=$hash($id);$seller=$users['seller'];
    $state=gm_request($seller,'sn_payment_management_state',['invoice_id'=>$id]);
    gm_ok($state['success']&&!$state['data']['allowed_actions']['register_payment']&&$state['data']['allowed_actions']['switch_to_manual'],'native management offers explicit method selection, not an upload bypass');
    foreach([$tokens['manual'],'zibal:'.$tracks['manual']]as$secret)gm_ok(!str_contains(wp_json_encode($state),$secret),'management omits public token and gateway authority');
    foreach([0,$users['other'],$users['unrelated']]as$actor){$r=gm_request($actor,'sn_payment_gateway_check',['invoice_id'=>$id,'operation'=>'switch_to_manual']);gm_ok(empty($r['success'])&&$hash($id)===$before,'guest/foreign/unrelated actor cannot switch '.$actor);}
    foreach(['','invalid']as$nonce){$r=gm_request($seller,'sn_payment_gateway_check',['invoice_id'=>$id,'operation'=>'switch_to_manual'],$nonce);gm_ok(empty($r['success'])&&$hash($id)===$before,'missing or invalid nonce cannot switch');}
    wp_set_current_user($users['other']);$nonce=wp_create_nonce('sn_public');$r=gm_request($seller,'sn_payment_gateway_check',['invoice_id'=>$id,'operation'=>'switch_to_manual'],$nonce);
    gm_ok(empty($r['success'])&&$hash($id)===$before,'another user nonce cannot authorize method selection');
    foreach([['operation'=>[]],['operation'=>'unknown'],['invoice_id'=>['1 OR 1=1']]]as$bad){$r=gm_request($seller,'sn_payment_gateway_check',array_merge(['invoice_id'=>$id,'operation'=>'switch_to_manual'],$bad));gm_ok(empty($r['success'])&&$hash($id)===$before,'malformed or forged operation input leaves financial rows unchanged');}
    update_option('sn_card_to_card_enabled','0');$r=gm_request($seller,'sn_payment_gateway_check',['invoice_id'=>$id,'operation'=>'switch_to_manual']);gm_ok(empty($r['success'])&&$hash($id)===$before,'disabled manual-payment option is enforced in the backend');update_option('sn_card_to_card_enabled','1');gm_clear_rate($seller,$id);
    foreach([1,2,999]as$status){$statuses[$tracks['manual']]=$status;$r=gm_request($seller,'sn_payment_gateway_check',['invoice_id'=>$id,'operation'=>'switch_to_manual']);gm_ok(empty($r['success'])&&$hash($id)===$before,'paid or unknown native inquiry cannot select manual '.$status);gm_clear_rate($seller,$id);}
    $statuses[$tracks['manual']]=-1;
    foreach(['orderMismatch','amountMismatch','providerFailure']as$flag){$$flag=true;$r=gm_request($seller,'sn_payment_gateway_check',['invoice_id'=>$id,'operation'=>'switch_to_manual']);gm_ok(empty($r['success'])&&$hash($id)===$before,'native mismatched or unavailable provider fails closed '.$flag);$$flag=false;gm_clear_rate($seller,$id);}
    $r=gm_request($seller,'sn_payment_gateway_check',['invoice_id'=>$id,'operation'=>'switch_to_manual']);gm_ok(!empty($r['success']),'real AJAX chooses manual after fresh matched unpaid inquiry');
    $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_payments WHERE id=%d",$payments['manual']));gm_ok($row->status==='manual_hold'&&$row->authority==='zibal:'.$tracks['manual']&&(float)$row->amount===1980000.0,'native choice preserves authority and amount in live bank hold');
    $state=gm_request($seller,'sn_payment_management_state',['invoice_id'=>$id]);gm_ok($state['data']['allowed_actions']['register_payment']&&(float)$state['data']['finance']['available_to_register']===1980000.0,'receipt capacity returns after explicit method selection');
    $rows=SN_Payment_Transactions::rows((object)['id'=>$id,'current_payment_stage'=>1]);gm_ok($rows[0]['is_gateway']&&!$rows[0]['is_transaction']&&$rows[0]['status_label']==='واریز دستی انتخاب شده؛ لغو بانکی نیست','gateway request is labelled separately from a transfer with missing receipt');
    $beforeCount=count($http_calls);$g=new SN_Invoice();$next=$g->request_payment($id,1980000,'Synthetic','',home_url('/'),$batch.'-manual',1);gm_ok(isset($next['error'])&&count($http_calls)===$beforeCount,'selected manual method prevents a new external gateway request');
    wp_set_current_user($seller);$_POST=[];
    $save=gm_call('sn_save_invoice_manual_payment',$id,'seller_manual',['transaction_amount'=>1980000,'submission_key'=>'g240_'.bin2hex(random_bytes(12)),'receipt_url'=>'https://hr239.example.invalid/synthetic-receipt.png','receipt_urls'=>['https://hr239.example.invalid/synthetic-receipt.png']]);
    gm_ok(!empty($save['success']),'actual transaction service stores a separate pending receipt after selection');
    $receipt=(int)$save['payment_id'];$state=gm_request($seller,'sn_payment_management_state',['invoice_id'=>$id]);gm_ok((float)$state['data']['finance']['approved']===0.0&&(float)$state['data']['finance']['pending']===1980000.0,'captured proof is pending, not approved money');
    $before=$hash($id);$r=gm_request($seller,'sn_receipt_review',['invoice_id'=>$id,'payment_id'=>$receipt,'operation'=>'approve','revision'=>1]);gm_ok(empty($r['success'])&&$hash($id)===$before,'seller selection never grants financial approval');
    $statuses[$tracks['manual']]=2;$r=gm_request($users['finance'],'sn_receipt_review',['invoice_id'=>$id,'payment_id'=>$receipt,'operation'=>'approve','revision'=>1]);gm_ok(empty($r['success'])&&$hash($id)===$before,'actual Finance approval re-inquires and rejects newly bank-paid requests');
    $statuses[$tracks['manual']]=-1;$r=gm_request($users['finance'],'sn_receipt_review',['invoice_id'=>$id,'payment_id'=>$receipt,'operation'=>'approve','revision'=>1]);gm_ok(!empty($r['success']),'actual Finance approves receipt after fresh unpaid inquiry');
    $inv=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_invoices WHERE id=%d",$id));gm_ok(in_array($inv->status,['paid','approved'],true)&&(float)$inv->paid_total_amount===1980000.0&&(float)$inv->remaining_amount===0.0,'receipt approval settles the original amount exactly once');
    $wallet=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}sn_wallet_transactions");$paid=(float)$inv->paid_total_amount;
    gm_ok(gm_bank_callback($id,$tracks['manual'])==='pending_finance','real late public callback routes verified bank payment to financial reconciliation');
    $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_payments WHERE id=%d",$payments['manual']));gm_ok($row->status==='reconcile_error'&&$row->ref_id==='SYNREF-'.$tracks['manual'],'late bank reference is stored without another approved ledger entry');
    gm_ok(gm_bank_callback($id,$tracks['manual'])==='pending_finance','duplicate late callback remains in reconciliation');
    $inv=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_invoices WHERE id=%d",$id));gm_ok((float)$inv->paid_total_amount===$paid&&(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}sn_wallet_transactions")===$wallet,'late and repeated callback preserve settled invoice and wallet count');
    wp_set_current_user((int)$wpdb->get_var("SELECT ID FROM {$wpdb->users} ORDER BY ID LIMIT 1"));
    $report=gm_call('sn_successful_payment_report_stats',['seller_id'=>$seller,'q'=>$batch.'-manual','pay_method'=>'','product_type'=>'','date_from'=>'','date_to'=>'']);gm_ok((int)$report['payment_count']===1&&(float)$report['total_amount']===1980000.0,'successful payment report counts only the approved receipt once after late bank callback');
    $view=gm_request($users['finance'],'sn_receipt_review',['invoice_id'=>$id,'operation'=>'read']);gm_ok(!empty($view['financial_notices']),'Finance can see a reconciliation notice on the affected invoice');
    $normal=$new('normal');gm_ok(gm_bank_callback($normal,$tracks['normal'])==='success','ordinary bank callback still follows its existing automatic policy');
    $inv=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_invoices WHERE id=%d",$normal));gm_ok((float)$inv->paid_total_amount===1980000.0,'ordinary bank callback settles the expected stage');
    $before=$hash($normal);gm_clear_rate($seller,$normal);$r=gm_request($seller,'sn_payment_gateway_check',['invoice_id'=>$normal,'operation'=>'switch_to_manual']);gm_ok(empty($r['success'])&&$hash($normal)===$before,'a completed bank stage cannot be converted to manual');
    $stale=$new('stale');wp_set_current_user($seller);$before=$hash($stale);$calls=count($http_calls);$r=(new SN_Invoice())->request_payment($stale,1980000,'Synthetic','',home_url('/'),$batch.'-stale',1);gm_ok(isset($r['error'])&&$hash($stale)===$before&&count($http_calls)===$calls,'request age cannot silently expire a live request or create another one');
    $terminal=$new('terminal');$statuses[$tracks['terminal']]=3;gm_clear_rate($seller,$terminal);$r=gm_request($seller,'sn_payment_gateway_check',['invoice_id'=>$terminal,'operation'=>'check']);gm_ok(!empty($r['success'])&&!$r['gateway_guard']['blocked'],'provider-confirmed cancellation still releases the original manual guard');
    $new('browser');$new('mobile');
    $page=wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>'آزمون پرداخت ساختگی ۲۴۰','post_content'=>'[sn_seller_panel]']);gm_ok(!is_wp_error($page)&&$page>0,'private payment browser page');
    $fixture=['users'=>$credentials,'cases'=>$cases,'tracks'=>$tracks,'payments'=>$payments,'batch'=>$batch,'page_id'=>(int)$page];file_put_contents($test_root.'/gateway240-fixture.json',wp_json_encode($fixture));chmod($test_root.'/gateway240-fixture.json',0600);
    $summary=['passed'=>true,'assertions'=>$checks,'version'=>SN_VERSION,'wordpress'=>$GLOBALS['wp_version'],'php'=>PHP_VERSION,'mysql'=>$wpdb->db_version(),'synthetic'=>true,'production_touched'=>false,'actual_multipart_upload_tested'=>false];
    $output=getenv('SN_APPROVAL_OUTPUT')?:($GLOBALS['hr239_output']??'');if($output!=='')file_put_contents($output.'/gateway-manual-native.json',wp_json_encode($summary,JSON_PRETTY_PRINT));
    echo 'SUMMARY '.wp_json_encode($summary).PHP_EOL;
    echo 'TOTAL '.$checks.PHP_EOL;
}finally{remove_filter('pre_http_request',$provider,999);}
ob_end_flush();
