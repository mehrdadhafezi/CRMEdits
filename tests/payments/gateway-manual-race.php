<?php
/** Workers for native advisory-lock/transaction races; synthetic guards match the native suite. */
ob_start();
if(getenv('SN_APPROVAL_ALLOW_SYNTHETIC')==='YES'){
    require __DIR__.'/integration/support.php';sn_test_bootstrap();sn_bootstrap_load_core();sn_bootstrap_plugin_instance(true);$root=(string)getenv('SN_APPROVAL_ROOT');
}else{
    require dirname(__DIR__).'/hr/native-support.php';hr239_bootstrap();
    if(!defined('SN_GATEWAY240_PRIVATE_TEST')||SN_GATEWAY240_PRIVATE_TEST!==true)throw new RuntimeException('Gateway synthetic namespace required');$root=$GLOBALS['hr239_root'];
}
if(!defined('DOING_AJAX'))define('DOING_AJAX',true);
if(SN_VERSION!=='2.0.246')throw new RuntimeException('Exact Gateway240 candidate required');
global $wpdb;$f=json_decode(file_get_contents($root.'/gateway240-fixture.json'),true,512,JSON_THROW_ON_ERROR);
$mode=$argv[1]??'';$key=$argv[2]??'';$cases=['r1','r2','r3','f0'];$plugin=sn_bootstrap_plugin_instance();
function gr_out(array $value):void{ob_end_clean();echo wp_json_encode($value,JSON_THROW_ON_ERROR);exit;}
function gr_pause(string $name):void{global $root;file_put_contents($root.'/gw240-'.$name.'-ready','ready');$end=microtime(true)+12;while(!file_exists($root.'/gw240-'.$name.'-go')){if(microtime(true)>$end)throw new RuntimeException('Synthetic race barrier timeout');usleep(30000);}}
if($mode==='fixtures'){
    $template=(array)$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_invoices WHERE id=%d",$f['cases']['stale']));unset($template['id']);
    $races=[];
    foreach($cases as$case){
        $row=array_merge($template,['invoice_code'=>$f['batch'].'-'.$case,'access_token'=>bin2hex(random_bytes(32))]);
        if(!$wpdb->insert($wpdb->prefix.'sn_invoices',$row))throw new RuntimeException('Synthetic race invoice');$id=(int)$wpdb->insert_id;
        if(!$wpdb->insert($wpdb->prefix.'sn_invoice_payment_stages',['invoice_id'=>$id,'stage_no'=>1,'stage_type'=>'full','requested_amount'=>1980000,'status'=>'pending','pay_method'=>'online']))throw new RuntimeException('Synthetic race stage');
        $track=(string)random_int(250000000,259999999);$ids=[];
        foreach(range(1,$case==='f0'?2:1)as$index){if(!$wpdb->insert($wpdb->prefix.'sn_payments',['invoice_id'=>$id,'payment_stage_no'=>1,'authority'=>'zibal:'.($track+$index-1),'amount'=>1980000,'status'=>'pending','pay_method'=>'online','payment_source'=>'zibal']))throw new RuntimeException('Synthetic race request');$ids[]=(int)$wpdb->insert_id;}
        $races[$case]=['id'=>$id,'track'=>$track,'payments'=>$ids];
    }
    $f['races']=$races;$f['race_wallet_count']=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}sn_wallet_transactions");file_put_contents($root.'/gateway240-fixture.json',wp_json_encode($f));chmod($root.'/gateway240-fixture.json',0600);gr_out(['success'=>true,'cases'=>4]);
}
if(!in_array($key,$cases,true)||!isset($f['races'][$key]))throw new RuntimeException('Fixed synthetic race case');
$case=$f['races'][$key];$id=(int)$case['id'];$actor=$f['users']['seller']['id'];
$provider=static function($pre,$args,$url)use($case,$mode,$key){
    if(!in_array($url,['https://gateway.zibal.ir/v1/inquiry','https://gateway.zibal.ir/v1/verify'],true))return new WP_Error('synthetic_only','Real HTTP disabled');
    if($mode==='switch-pause'&&str_ends_with($url,'/inquiry'))gr_pause('switch');
    if($mode==='callback-signal'&&str_ends_with($url,'/verify'))file_put_contents($GLOBALS['root'].'/gw240-callback-ready','ready');
    return ['headers'=>[],'response'=>['code'=>200],'body'=>wp_json_encode(['result'=>100,'status'=>str_ends_with($url,'/verify')?1:-1,'amount'=>19800000,'refNumber'=>'SYN-RACE-'.$key]),'cookies'=>[]];
};add_filter('pre_http_request',$provider,999,3);
if(in_array($mode,['switch','switch-pause','switch-fail'],true)){
    wp_set_current_user($actor);delete_transient('sn_gateway_switch_to_manual_'.$actor.'_'.$id);
    $_POST=['invoice_id'=>$id,'nonce'=>wp_create_nonce('sn_public'),'operation'=>'switch_to_manual'];$_REQUEST=$_POST;
    if($mode==='switch-fail'){
        $n=0;add_filter('query',static function($query)use(&$n,$wpdb){if(str_starts_with($query,'UPDATE '.$wpdb->prefix.'sn_payments SET status=')&&++$n===2)return 'SELECT gateway240_missing FROM gateway240_missing_table';return $query;});
    }
    $plugin->ajax_payment_gateway_check();exit;
}
if(in_array($mode,['callback','callback-signal'],true)){
    wp_set_current_user(0);$_POST=[];$_GET=['sn_callback'=>1,'invoice_id'=>$id,'sn_stage'=>1,'sn_gateway'=>'zibal','trackId'=>$case['track'],'success'=>'1'];$_REQUEST=$_GET;
    add_filter('wp_redirect',static function($location){parse_str((string)parse_url($location,PHP_URL_QUERY),$q);gr_out(['pay_result'=>$q['pay_result']??'']);},999);
    $plugin->handle_zarinpal_callback();throw new RuntimeException('Callback did not redirect');
}
if(in_array($mode,['save','save-pause'],true)){
    wp_set_current_user($actor);$_POST=[];
    if($mode==='save-pause')add_filter('query',static function($query)use($wpdb){if(preg_match('/^INSERT\s+INTO\s+`?'.preg_quote($wpdb->prefix.'sn_payments','/').'`?\s/i',$query))gr_pause('save');return $query;});
    $method=new ReflectionMethod(SN_Plugin::class,'sn_save_invoice_manual_payment');$method->setAccessible(true);
    $r=$method->invoke($plugin,$id,'seller_manual',['transaction_amount'=>1980000,'submission_key'=>'g240race_'.bin2hex(random_bytes(10)),'receipt_url'=>'https://hr239.example.invalid/synthetic-race.png','receipt_urls'=>['https://hr239.example.invalid/synthetic-race.png']]);gr_out(['success'=>!empty($r['success'])]);
}
if($mode==='verify'){
    $rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_payments WHERE invoice_id=%d ORDER BY id",$id),ARRAY_A);
    $invoice=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_invoices WHERE id=%d",$id));$bank=$rows[0];$paid=(float)$invoice->paid_total_amount;
    $checks=[];
    if($key==='f0'){
        $checks['all_requests_preserved']=count($rows)===2&&array_column($rows,'status')===['pending','pending'];
        $checks['audit_rolled_back']=empty($rows[0]['proof_data'])&&empty($rows[1]['proof_data']);
    }elseif($key==='r3'){
        $checks['ordinary_bank_paid_once']=$paid===1980000.0&&$bank['status']==='approved';
        $checks['stale_manual_selection_denied']=empty(json_decode((string)$bank['proof_data'],true)['_gateway_manual_selection']);
    }else{
        $checks['bank_reference_retained']=$bank['ref_id']==='SYN-RACE-'.$key&&$bank['status']==='reconcile_error';
        $checks['no_invoice_credit']=$paid===0.0;
        $checks['no_wallet_credit']=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}sn_wallet_transactions")===$f['race_wallet_count'];
        $checks['stage_guard_blocks']=SN_Payment_Gateway_Guard::snapshot($id,1)['blocked'];
        if($key==='r2'){
            $checks['receipt_pending']=count($rows)===2&&$rows[1]['status']==='pending'&&empty($rows[1]['authority']);
            wp_set_current_user($f['users']['finance']['id']);
            $service=new SN_Receipt_Review(['view'=>static fn()=>true,'approve'=>static fn()=>current_user_can('sn_approve_payment')]);
            $r=$service->handle(['invoice_id'=>$id,'payment_id'=>$rows[1]['id'],'operation'=>'approve','revision'=>1]);
            $checks['finance_rejects_conflict']=empty($r['success']);
        }
    }
    $name=SN_Invoice::stage_lock_name($id,1);$checks['stage_lock_released']=$wpdb->get_var($wpdb->prepare('SELECT IS_FREE_LOCK(%s)',$name))==='1';
    gr_out(['passed'=>!in_array(false,$checks,true),'case'=>$key,'checks'=>$checks,'assertions'=>count($checks),'synthetic'=>true]);
}
throw new RuntimeException('Fixed worker mode required');
