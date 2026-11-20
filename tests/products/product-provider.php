<?php
/** Test-only MU bridge; excluded from every runtime package. */
if(defined('SN_APPROVAL_TEST_SANDBOX')&&SN_APPROVAL_TEST_SANDBOX===true){
 if(!preg_match('/^sn_approval_[a-z0-9_]+_test$/D',DB_NAME)||DB_HOST!=='localhost'||wp_get_environment_type()!=='local')throw new RuntimeException('Synthetic HTTP guard');
 $p241_root=realpath((string)getenv('SN_APPROVAL_ROOT'));if(!$p241_root||!str_starts_with($p241_root,'/tmp/sn-approval-'))throw new RuntimeException('Generated root required');
}elseif(!defined('SN_PRODUCT241_PRIVATE_TEST')||SN_PRODUCT241_PRIVATE_TEST!==true||DB_NAME!=='crmstg1_restore237'||posix_geteuid()!==1073){exit(74);}
add_filter('pre_http_request',static function($pre,$args,$url){
 if(wp_parse_url($url,PHP_URL_HOST)!=='api.kavenegar.com')return $pre;
 if(strpos((string)wp_parse_url($url,PHP_URL_PATH),'/v1/synthetic-p241-key/')!==0)return new WP_Error('synthetic_only','Real credentials blocked');
 update_option('p241_sms_calls',(int)get_option('p241_sms_calls',0)+1);
 if(get_option('p241_sms_mode','accept')==='uncertain')return new WP_Error('synthetic_timeout','Synthetic ambiguity');
 $body=get_option('p241_sms_mode','accept')==='accept'?['return'=>['status'=>200],'entries'=>[['messageid'=>241,'status'=>1]]]:['return'=>['status'=>400,'message'=>'Synthetic rejected'],'entries'=>[]];
 return ['headers'=>['x-p241-synthetic'=>'1'],'response'=>['code'=>200,'message'=>'Synthetic'],'body'=>wp_json_encode($body),'cookies'=>[],'filename'=>null];
},9999,3);
add_action('init',static function(){
 if(isset($_GET['p241_nonce'])){
  if(!is_user_logged_in())wp_send_json(['success'=>false],403);
  $out=['success'=>true,'nonce'=>wp_create_nonce('sn_public'),'user_id'=>get_current_user_id()];
  foreach(['sn_admin','sn_sales_catalog_admin','sn_sales_catalog_staff','sn_product_invoice_sms'] as $key)$out[$key]=wp_create_nonce($key);
  wp_send_json($out);
 }
 if(isset($_GET['p241_test_state'])){
  if(!current_user_can('manage_options'))wp_send_json(['success'=>false],403);
  global $wpdb;$id=absint($_GET['product']??0);
  $state=['wallet'=>get_option('sn_wallet_auto_credit'),'roles'=>get_option($wpdb->prefix.'user_roles'),'dot'=>get_option('sn_dot_config'),'cards'=>get_option('sn_sales_manager_cards')];
  foreach(['sn_wallets','sn_wallet_transactions','sn_payment_commission_matrix'] as $table)$state[$table]=$wpdb->get_results("SELECT * FROM {$wpdb->prefix}{$table} ORDER BY id",ARRAY_A);
  $parts=[];foreach($state as $key=>$value)$parts[$key]=hash('sha256',serialize($value));
  wp_send_json(['success'=>true,'version'=>SN_VERSION,'max_input_vars'=>(int)ini_get('max_input_vars'),'template'=>get_option('sn_sms_invoice_template',''),'protected_hash'=>hash('sha256',serialize($state)),'protected_components'=>$parts,'provider_calls'=>(int)get_option('p241_sms_calls',0),'policy'=>SN_Invoice_SMS_Policy::policy($id)]);
 }
},99999);
