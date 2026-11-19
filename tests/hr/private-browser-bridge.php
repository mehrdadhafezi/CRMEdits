<?php
/** Private synthetic browser transport; never packaged or exposed on WordPress. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
ob_start();
$raw=stream_get_contents(STDIN,32769);if(strlen($raw)>32768){exit(70);}
$input=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
if(!in_array($input['phase']??'', ['session','page','ajax'],true)){exit(70);}
$_SERVER['HTTPS']='on';$_SERVER['HTTP_HOST']='hr239.example.invalid';$_SERVER['REQUEST_METHOD']=$input['phase']==='ajax'?'POST':'GET';$_SERVER['REQUEST_URI']='/?sn_hr_tab=hr-invoice-reissue';
$_COOKIE=[];
foreach(explode(';',$input['cookie']??'') as $part){$pair=explode('=',trim($part),2);if(count($pair)===2&&preg_match('/^wordpress_(?:logged_in|sec)_[a-f0-9]{32}$/D',$pair[0])){$_COOKIE[$pair[0]]=rawurldecode($pair[1]);}}
if($input['phase']==='ajax'){
    parse_str($input['body']??'',$_POST);$_REQUEST=$_POST;
    if(($_POST['action']??'')!=='sn_hr_invoice_reissue'){exit(70);}
    define('DOING_AJAX',true);define('WP_ADMIN',true);$_SERVER['PHP_SELF']='/wp-admin/admin-ajax.php';
}
require __DIR__.'/native-support.php';hr239_bootstrap(false);$f=hr239_data();
if($input['phase']==='session'){
    $who=$input['who']??'';
    if(!in_array($who,['hr','seller_a','subscriber'],true)){exit(70);}
    $id=$f['users'][$who]['id'];$expiry=time()+1800;$token=WP_Session_Tokens::get_instance($id)->create($expiry);
    $cookie=wp_generate_auth_cookie($id,$expiry,'logged_in',$token);
    $result=['cookies'=>[['name'=>LOGGED_IN_COOKIE,'value'=>$cookie],['name'=>SECURE_AUTH_COOKIE,'value'=>wp_generate_auth_cookie($id,$expiry,'secure_auth',$token)]], 'id'=>$id,'users'=>array_map(static fn($u)=>['id'=>$u['id']],$f['users'])];
} elseif($input['phase']==='page'){
    $_GET=['sn_hr_tab'=>'hr-invoice-reissue'];
    if(sn_bootstrap_should_run_full()){exit(71);}
    $html=do_shortcode('[sn_hr_panel]');
    $result=['html'=>$html,'nonce'=>wp_create_nonce('sn_hr_invoice_reissue'),'public_nonce'=>wp_create_nonce('sn_public'),'user_id'=>get_current_user_id(),'light_bootstrap'=>true];
} else {
    if(empty($GLOBALS['hr239_automatic_policy_hook'])||!sn_bootstrap_should_run_full()){exit(72);}
    $handler=static fn()=>static function(...$args):void{throw new HR239_Response_End();};add_filter('wp_die_ajax_handler',$handler);
    http_response_code(200);ob_start();
    try{do_action(is_user_logged_in()?'wp_ajax_sn_hr_invoice_reissue':'wp_ajax_nopriv_sn_hr_invoice_reissue');}
    catch(HR239_Response_End $end){}
    finally{$body=ob_get_clean();remove_filter('wp_die_ajax_handler',$handler);}
    $result=['body'=>json_decode($body,true,512,JSON_THROW_ON_ERROR),'status'=>http_response_code(),'user_id'=>get_current_user_id()];
}
ob_end_clean();echo wp_json_encode($result,JSON_THROW_ON_ERROR);
