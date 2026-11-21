<?php
/** Two independent real callback workers; guarded synthetic namespace only. */
ob_start();require dirname(__DIR__).'/hr/native-support.php';hr239_bootstrap();$f=json_decode(file_get_contents($GLOBALS['hr239_root'].'/staged243-race-fixture.json'),true,512,JSON_THROW_ON_ERROR);global $wpdb;
$id=(int)$f['invoice_id'];$track=(string)$f['track'];$operation=(string)getenv('SN_STAGED243_RACE_OP');
if($operation==='callback'){
    $provider=static function($pre,$args,$url)use($f,$track){
        if($url!=='https://gateway.zibal.ir/v1/verify')return new WP_Error('synthetic_network','Blocked');
        $input=json_decode($args['body'],true);if((string)$input['trackId']!==$track||$input['merchant']!=='zibal')throw new RuntimeException('Synthetic provider identity');
        usleep(150000);
        return ['headers'=>[],'response'=>['code'=>200],'body'=>wp_json_encode(['result'=>100,'status'=>1,'amount'=>50000000,'refNumber'=>$f['ref']])];
    };
    add_filter('pre_http_request',$provider,PHP_INT_MAX,3);wp_set_current_user(0);$_POST=[];$_GET=['sn_callback'=>'1','invoice_id'=>$id,'sn_gateway'=>'zibal','sn_stage'=>'1','trackId'=>$track,'success'=>'1'];$_REQUEST=$_GET;
    class Staged243RaceRedirect extends RuntimeException{}
    $result='';$redirect=static function($location)use(&$result){parse_str((string)parse_url($location,PHP_URL_QUERY),$q);$result=(string)($q['pay_result']??'');throw new Staged243RaceRedirect();};add_filter('wp_redirect',$redirect,999);
    try{sn_bootstrap_plugin_instance()->handle_zarinpal_callback();}catch(Staged243RaceRedirect $e){}finally{remove_filter('wp_redirect',$redirect,999);remove_filter('pre_http_request',$provider,PHP_INT_MAX);}
    ob_clean();echo wp_json_encode(['result'=>$result,'version'=>SN_VERSION]);
}elseif($operation==='verify'){
    $i=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_invoices WHERE id=%d",$id));$stage=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_invoice_payment_stages WHERE invoice_id=%d AND stage_no=1",$id));
    $payment=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_payments WHERE id=%d",$f['payment_id']));
    $credits=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_wallet_transactions WHERE invoice_id=%d AND direction='credit'",$id));
    hr239_ok($i->invoice_code===$f['invoice_code']&&(float)$i->paid_total_amount===5000000.0&&(float)$i->remaining_amount===5000000.0,'parallel callbacks preserve one invoice and one received amount');
    hr239_ok($i->status==='partial_paid'&&in_array($stage->status,['paid','approved'],true),'parallel callbacks settle only the first stage');
    hr239_ok(in_array($payment->status,['paid','approved'],true)&&$payment->ref_id===$f['ref'],'parallel callbacks retain canonical receipt and reference');
    hr239_ok((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}sn_payments WHERE invoice_id=%d",$id))===1,'parallel callbacks create no extra transaction');
    hr239_ok(count($credits)===1&&(float)$credits[0]->amount===500000.0&&(int)$credits[0]->user_id===(int)$f['seller_id'],'parallel callbacks credit frozen recipient exactly once');
    echo 'TOTAL '.$GLOBALS['hr239_checks'].PHP_EOL;
}else throw new RuntimeException('Explicit synthetic race operation');
ob_end_flush();
