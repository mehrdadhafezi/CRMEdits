<?php
/** Guarded, disposable synthetic WordPress/WooCommerce integration only. */
ob_start(); require dirname(__DIR__).'/hr/native-support.php'; hr239_bootstrap(); $f=hr239_data(); global $wpdb;
hr239_ok(SN_VERSION==='2.0.243'&&class_exists('WooCommerce')&&WC_VERSION==='10.4.3','real reviewed WordPress/WooCommerce candidate');
$admin=$f['admin_id'];$seller=$f['users']['seller_a']['id'];$manager=$f['users']['manager_a']['id'];$other=$f['users']['seller_b']['id'];
$_SERVER['REQUEST_METHOD']='POST';
function p241_request(int $actor,string $action,array $data=[],string $nonce_action='sn_admin'):array{
 wp_set_current_user(0);wp_set_current_user($actor);$_POST=wp_slash(array_merge(['action'=>$action,'nonce'=>wp_create_nonce($nonce_action)],$data));$_REQUEST=$_POST;http_response_code(200);
 $handler=static fn()=>static function($message='',$title='',$args=[]){if(is_array($args)&&isset($args['response']))http_response_code((int)$args['response']);throw new HR239_Response_End();};add_filter('wp_die_ajax_handler',$handler);ob_start();
 try{do_action($actor?'wp_ajax_'.$action:'wp_ajax_nopriv_'.$action);}catch(HR239_Response_End $end){}finally{$raw=ob_get_clean();remove_filter('wp_die_ajax_handler',$handler);}
 $result=json_decode($raw,true);if(!is_array($result))$result=['success'=>false];$result['_status']=http_response_code();return $result;
}
function p241_product(string $title):int{$p=new WC_Product_Simple();$p->set_name($title);$p->set_status('publish');$p->set_regular_price('1000');$p->set_virtual(true);$id=$p->save();update_post_meta($id,'_sn_enabled','1');return $id;}
function p241_save_product(int $actor,int $id,bool $enabled,bool $previous,?string $revision=null,string $nonce='auto'):array{
 wp_set_current_user(0);wp_set_current_user($actor);$policy=SN_Invoice_SMS_Policy::policy($id);
 $_POST=['sn_invoice_sms_product_present'=>'1','sn_invoice_sms_product_revision'=>$revision??$policy['revision'],'sn_invoice_sms_product_nonce'=>$nonce==='auto'?wp_create_nonce('sn_invoice_sms_product_'.$id):$nonce];
 if($enabled)$_POST['sn_invoice_sms_product_enabled']='1';if($previous)$_POST['sn_invoice_sms_product_previous']='1';
 SN_Invoice_SMS_Policy::save_product($id,get_post($id));return SN_Invoice_SMS_Policy::policy($id);
}
function p241_catalog(int $admin,int $manager,array $products,?string $revision=null):array{
 $p=SN_Sales_Catalog::instance()->policy($manager);return p241_request($admin,'sn_sales_catalog_save',['manager_id'=>(string)$manager,'policy'=>wp_json_encode(['mode'=>'all','revision'=>$revision??$p['revision'],'products'=>$products])],'sn_sales_catalog_admin');
}
update_option('sn_sms_provider','kavenegar');update_option('sn_sms_credentials_provider','kavenegar');update_option('sn_sms_api_key_kavenegar','synthetic-p241-key');update_option('sn_sms_sender_kavenegar','');update_option('sn_kavenegar_template_invoice','');update_option('p241_sms_calls',0);update_option('p241_sms_mode','accept'); // gitleaks:allow -- fixed synthetic credential; both provider stubs reject real keys.
add_filter('pre_http_request',static function($pre,$args,$url){
 if(wp_parse_url($url,PHP_URL_HOST)!=='api.kavenegar.com')return $pre;
 if(is_array($pre)&&isset($pre['headers']['x-p241-synthetic'])||is_wp_error($pre)&&$pre->get_error_code()==='synthetic_timeout')return $pre;
 update_option('p241_sms_calls',(int)get_option('p241_sms_calls',0)+1);$mode=get_option('p241_sms_mode','accept');
 if($mode==='uncertain')return new WP_Error('synthetic_timeout','Synthetic ambiguous result');
 return ['headers'=>['x-p241-synthetic'=>'1'],'response'=>['code'=>200,'message'=>'Synthetic'],'body'=>wp_json_encode($mode==='accept'?['return'=>['status'=>200],'entries'=>[['messageid'=>241,'status'=>1]]]:['return'=>['status'=>400,'message'=>'Synthetic rejected'],'entries'=>[]]),'cookies'=>[],'filename'=>null];
},9999,3);
$calls=static fn()=>(int)get_option('p241_sms_calls',0);
$pid=p241_product('محصول ساختگی <img src=x onerror=window.__p241Xss=1>');$second=p241_product('محصول دوم ساختگی');
hr239_ok($pid>0&&$second>0&&SN_Invoice_SMS_Policy::policy($pid)['enabled'],'existing product SMS default remains enabled');
$p=p241_save_product($admin,$pid,false,false);hr239_ok(!$p['enabled']&&!$p['previous'],'actual product save disables SMS without dispatch');
$rev=$p['revision'];p241_save_product($seller,$pid,true,true);hr239_ok(SN_Invoice_SMS_Policy::policy($pid)['revision']===$rev,'seller cannot change global product SMS policy');
p241_save_product($admin,$pid,true,true,null,'invalid');hr239_ok(SN_Invoice_SMS_Policy::policy($pid)['revision']===$rev,'invalid product nonce preserves policy');
p241_save_product($admin,$pid,true,true,'stale');hr239_ok(SN_Invoice_SMS_Policy::policy($pid)['revision']===$rev,'stale product revision cannot overwrite policy');
hr239_ok($calls()===0,'product setting saves never send historical SMS automatically');
$load=p241_request($admin,'sn_sales_catalog_load',['manager_id'=>(string)$manager],'sn_sales_catalog_admin');hr239_ok($load['success'],'existing catalog load authorizes administrator');
$rows=[$pid=>['discounts'=>['100'],'allow_custom_discount'=>false],$second=>['discounts'=>[],'allow_custom_discount'=>false]];
hr239_ok(p241_catalog($admin,$manager,$rows)['success'],'actual catalog saves fixed discounts and explicit custom denial');
$before=SN_Sales_Catalog::instance()->policy($manager);
foreach([$seller,$other,$f['users']['subscriber']['id']] as $actor){$bad=p241_request($actor,'sn_sales_catalog_save',['manager_id'=>(string)$manager,'policy'=>wp_json_encode(['mode'=>'all','revision'=>$before['revision'],'products'=>$rows])],'sn_sales_catalog_admin');hr239_ok(!$bad['success']&&$bad['_status']===403,'unrelated actor cannot change discount grant '.$actor);}
$bad=p241_request($admin,'sn_sales_catalog_save',['nonce'=>'invalid','manager_id'=>(string)$manager],'sn_sales_catalog_admin');hr239_ok(!$bad['success']&&$bad['_status']===403,'catalog rejects invalid nonce');
$badrows=$rows;$badrows[$pid]['allow_custom_discount']='true';hr239_ok(!p241_catalog($admin,$manager,$badrows)['success']&&SN_Sales_Catalog::instance()->policy($manager)===$before,'nonboolean custom grant rejected without mutation');
$core=new ReflectionMethod(SN_Plugin::class,'sn_create_invoice_core');$core->setAccessible(true);$plugin=sn_bootstrap_plugin_instance();
foreach([$seller,$other] as $id)update_user_meta($id,'sn_manual_invoice_access','allow');
$make=static function(string $choice,string $phone='')use($core,$plugin,$seller,$pid):array{
 wp_set_current_user(0);wp_set_current_user($seller);
 return $core->invoke($plugin,['customer_name'=>'مشتری کاملاً ساختگی','customer_phone'=>$phone?:'09'.random_int(100000000,999999999),'customer_address'=>'آدرس کاملاً ساختگی','province'=>'تهران','city'=>'تهران','product_ids'=>[$pid],'product_qtys'=>[2],'catalog_discount_ids'=>[$choice]]);
};
$count=static fn()=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}sn_invoices");$n=$count();$r=$make('custom:12525');
hr239_ok(empty($r['success'])&&$count()===$n,'real invoice handler rejects forged custom discount while off');
$rows[$pid]['allow_custom_discount']=true;$oldrev=$before['revision'];hr239_ok(p241_catalog($admin,$manager,$rows)['success'],'custom per-product manager grant saved through AJAX');
hr239_ok(!p241_catalog($admin,$manager,$rows,$oldrev)['success'],'stale catalog save cannot overwrite newer grant');
$staff=p241_request($seller,'sn_sales_catalog_staff',[],'sn_sales_catalog_staff');$allowed=array_column($staff['data']['products'],'allow_custom_discount','id');
hr239_ok($staff['success']&&$allowed[$pid]===true&&$allowed[$second]===false,'staff API exposes only effective product-specific custom choice');
$r=$make('custom:12525');hr239_private('p241-last-invoice-result.json',$r);hr239_ok(!empty($r['success'])&&$count()===$n+1,'real invoice creation accepts authorized custom per-unit discount');
$invoice_id=(int)$r['data']['invoice_id'];$invoice=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_invoices WHERE id=%d",$invoice_id));
hr239_ok((float)$invoice->original_total===2000.0&&(float)$invoice->discount_total===250.5&&(float)$invoice->final_total===1749.5&&(float)$invoice->payment_total_amount===1749.5,'discounted totals and compatibility fields agree');
hr239_ok((float)$wpdb->get_var($wpdb->prepare("SELECT requested_amount FROM {$wpdb->prefix}sn_invoice_payment_stages WHERE invoice_id=%d AND stage_no=1",$invoice_id))===1749.5,'initial stage uses discounted payable amount');
hr239_ok($r['data']['sms_status']==='blocked'&&!$r['data']['sms_sent']&&$calls()===0,'actual creation succeeds with explicit product-blocked SMS result');
hr239_ok(SN_Invoice_SMS_Policy::delivery($invoice_id)['backlog_eligible'],'new blocked invoice has durable known-unsent history');
$snapshot=$invoice->catalog_discounts_snapshot;$rows[$pid]['allow_custom_discount']=false;p241_catalog($admin,$manager,$rows);$fail=$make('custom:12525');hr239_ok(empty($fail['success']),'revoked custom permission enforced by real creation handler');
hr239_ok($wpdb->get_var($wpdb->prepare("SELECT catalog_discounts_snapshot FROM {$wpdb->prefix}sn_invoices WHERE id=%d",$invoice_id))===$snapshot,'existing invoice discount snapshot is immutable after permission change');
$rows[$pid]['allow_custom_discount']=true;p241_catalog($admin,$manager,$rows);
$copy=static function(bool $known=true,string $status='pre_invoice')use($invoice,$pid,$wpdb):int{
 $row=(array)$invoice;unset($row['id']);$row['invoice_code']=SN_Helpers::generate_invoice_code();$row['access_token']=SN_Helpers::generate_unique_access_token();$row['short_code']='';$row['status']=$status;
 if(isset($row['wc_order_id']))$row['wc_order_id']=null;
 $wpdb->insert($wpdb->prefix.'sn_invoices',$row);$id=(int)$wpdb->insert_id;
 $wpdb->insert($wpdb->prefix.'sn_invoice_items',['invoice_id'=>$id,'product_id'=>$pid,'product_name'=>'ساختگی','qty'=>1,'unit_price'=>1749.5,'total_price'=>1749.5,'is_free'=>0,'created_at'=>current_time('mysql')]);
 if($known)SN_Invoice_SMS_Policy::created($id);return $id;
};
$unsent=$copy();$legacy=$copy(false);$paid=$copy();$cancelled=$copy();
foreach([$unsent,$legacy,$paid,$cancelled] as $id){(new SN_SMS())->send_invoice_link('09100000000','Synthetic','https://synthetic.example.invalid/invoice/','Synthetic','1000','',$id);}
$wpdb->update($wpdb->prefix.'sn_invoices',['status'=>'paid','paid_total_amount'=>1749.5],['id'=>$paid]);$wpdb->update($wpdb->prefix.'sn_invoices',['status'=>'cancelled'],['id'=>$cancelled]);
hr239_ok(!SN_Invoice_SMS_Policy::delivery($legacy)['backlog_eligible'],'unknown legacy send history excluded after disabled resend');
p241_save_product($admin,$pid,true,false);$r=p241_request($admin,'sn_product_invoice_sms_preview',['product_id'=>(string)$pid],'sn_product_invoice_sms');hr239_ok(!$r['success']&&$r['_status']===409&&$calls()===0,'re-enable without previous consent starts no bulk send');
p241_save_product($admin,$pid,true,true);hr239_ok($calls()===0,'enabling previous capability still requires explicit confirmation');
$uncertain=$copy();update_option('p241_sms_mode','uncertain');(new SN_SMS())->send_invoice_link('09100000000','Synthetic','https://synthetic.example.invalid/invoice/','Synthetic','1000','',$uncertain);update_option('p241_sms_mode','accept');
hr239_ok(!SN_Invoice_SMS_Policy::delivery($uncertain)['backlog_eligible'],'provider timeout not relabelled as unsent');
$accepted=$copy();(new SN_SMS())->send_invoice_link('09100000000','Synthetic','https://synthetic.example.invalid/invoice/','Synthetic','1000','',$accepted);
hr239_ok(SN_Invoice_SMS_Policy::delivery($accepted)['ever_sent'],'actual provider acceptance remembered');
$base_calls=$calls();
foreach([$seller,$manager,$other,$f['users']['subscriber']['id']] as $actor){$r=p241_request($actor,'sn_product_invoice_sms_preview',['product_id'=>(string)$pid],'sn_product_invoice_sms');hr239_ok(!$r['success']&&$r['_status']===403,'non-admin cannot preview global historical SMS '.$actor);}
$r=p241_request($admin,'sn_product_invoice_sms_preview',['product_id'=>['1']],'sn_product_invoice_sms');hr239_ok(!$r['success'],'array product identifier denied');
$r=p241_request($admin,'sn_product_invoice_sms_preview',['product_id'=>(string)$pid,'after'=>'1 OR 1=1'],'sn_product_invoice_sms');hr239_ok(!$r['success']&&$r['_status']===400,'injected preview cursor denied');
$r=p241_request($admin,'sn_product_invoice_sms_preview',['product_id'=>(string)$pid,'nonce'=>'invalid'],'sn_product_invoice_sms');hr239_ok(!$r['success']&&$r['_status']===403,'historical preview requires valid nonce');
$preview=p241_request($admin,'sn_product_invoice_sms_preview',['product_id'=>(string)$pid],'sn_product_invoice_sms');$token=$preview['data']['token'];$batch=get_transient('sn_product_sms_'.$token);
hr239_ok($preview['success']&&$preview['data']['count']===2&&$batch['ids']===[$invoice_id,$unsent],'preview selects only two open unpaid definitely-unsent invoices');
hr239_ok($calls()===$base_calls,'preview never contacts SMS provider');
$password=bin2hex(random_bytes(24));$login='p241_admin_'.bin2hex(random_bytes(3));$browser_admin=wp_insert_user(['user_login'=>$login,'user_pass'=>$password,'user_email'=>$login.'@example.invalid','role'=>'administrator','display_name'=>'مدیر آزمایشی']);hr239_ok(!is_wp_error($browser_admin),'private browser administrator created');
$send_data=['product_id'=>(string)$pid,'token'=>$token,'confirmation'=>'SEND_PREVIOUS'];
$bad=p241_request((int)$browser_admin,'sn_product_invoice_sms_send',$send_data,'sn_product_invoice_sms');hr239_ok(!$bad['success']&&$bad['_status']===409&&$calls()===$base_calls,'another administrator cannot reuse a batch token');
p241_save_product($admin,$second,true,true);$bad=p241_request($admin,'sn_product_invoice_sms_send',array_merge($send_data,['product_id'=>(string)$second]),'sn_product_invoice_sms');hr239_ok(!$bad['success']&&$bad['_status']===409&&$calls()===$base_calls,'batch token is bound to its original product');
$_SERVER['REQUEST_METHOD']='GET';$bad=p241_request($admin,'sn_product_invoice_sms_send',$send_data,'sn_product_invoice_sms');$_SERVER['REQUEST_METHOD']='POST';hr239_ok(!$bad['success']&&$bad['_status']===403&&$calls()===$base_calls,'historical send requires POST even with valid nonce');
p241_save_product($admin,$pid,true,true);$bad=p241_request($admin,'sn_product_invoice_sms_send',$send_data,'sn_product_invoice_sms');hr239_ok(!$bad['success']&&$bad['_status']===409&&$calls()===$base_calls,'saved product revision invalidates a previously previewed batch');
$preview=p241_request($admin,'sn_product_invoice_sms_preview',['product_id'=>(string)$pid],'sn_product_invoice_sms');$token=$preview['data']['token'];
$bad=p241_request($admin,'sn_product_invoice_sms_send',['product_id'=>(string)$pid,'token'=>$token],'sn_product_invoice_sms');hr239_ok(!$bad['success']&&$bad['_status']===400&&$calls()===$base_calls,'missing confirmation cannot send');
$wpdb->update($wpdb->prefix.'sn_invoices',['status'=>'paid','paid_total_amount'=>1749.5],['id'=>$unsent]);
$result=p241_request($admin,'sn_product_invoice_sms_send',['product_id'=>(string)$pid,'token'=>$token,'confirmation'=>'SEND_PREVIOUS'],'sn_product_invoice_sms');
hr239_ok($result['success']&&$result['data']['sent']===1&&$calls()===$base_calls+1,'confirmed batch sends through canonical invoice sender');
$result=p241_request($admin,'sn_product_invoice_sms_send',['product_id'=>(string)$pid,'token'=>$token,'confirmation'=>'SEND_PREVIOUS'],'sn_product_invoice_sms');
hr239_ok($result['success']&&$result['data']['done']&&$result['data']['skipped']===1&&$calls()===$base_calls+1,'payment after preview prevents subsequent batch dispatch');
$again=p241_request($admin,'sn_product_invoice_sms_send',['product_id'=>(string)$pid,'token'=>$token,'confirmation'=>'SEND_PREVIOUS'],'sn_product_invoice_sms');hr239_ok($again['success']&&$calls()===$base_calls+1,'repeated completed batch cannot duplicate SMS');
$moved=$copy();p241_save_product($admin,$pid,false,false);(new SN_SMS())->send_invoice_link('09100000000','Synthetic','https://synthetic.example.invalid/invoice/','Synthetic','1000','',$moved);p241_save_product($admin,$pid,true,true);
$preview=p241_request($admin,'sn_product_invoice_sms_preview',['product_id'=>(string)$pid],'sn_product_invoice_sms');hr239_ok($preview['success']&&$preview['data']['count']===1,'fresh preview contains the isolated movable fixture');
$wpdb->update($wpdb->prefix.'sn_invoices',['product_id'=>$second],['id'=>$moved]);$wpdb->update($wpdb->prefix.'sn_invoice_items',['product_id'=>$second],['invoice_id'=>$moved]);
$m=p241_request($admin,'sn_product_invoice_sms_send',['product_id'=>(string)$pid,'token'=>$preview['data']['token'],'confirmation'=>'SEND_PREVIOUS'],'sn_product_invoice_sms');hr239_ok($m['success']&&$m['data']['skipped']===1&&$calls()===$base_calls+1,'invoice moved to another product after preview is skipped');
$record=wp_json_encode(SN_Invoice_SMS_Policy::delivery($invoice_id));hr239_ok(strpos($record,(string)$invoice->access_token)===false&&strpos($record,(string)$invoice->customer_phone)===false&&strpos($record,'http')===false,'new durable delivery audit contains no token phone or URL');
// JSON settings and max_input_vars are exercised through actual HTTP in the browser suite.
// Invoice-link callers in Dot/Operations obey the same product switch. OTP stays separate.
p241_save_product($admin,$pid,false,false);$before_calls=$calls();
$vars=['customer_name'=>'Synthetic','amount'=>'1000','invoice_url'=>'https://synthetic.example.invalid/invoice/'];
$dot=SN_Dot_Flow::instance();$sent=$dot->send_sales_cycle_payment_sms('09100000000',$vars,['invoice_id'=>$invoice_id]);
hr239_ok(!$sent&&$calls()===$before_calls,'Dot configured payment route obeys global product switch');
$mode=get_option('sn_dot_sms_test_mode',null);update_option('sn_dot_sms_test_mode','1');
$sent=$dot->send_sales_cycle_payment_sms('09100000000',$vars,['invoice_id'=>$invoice_id]);hr239_ok(!$sent&&$calls()===$before_calls,'Dot test preview cannot bypass product suppression');
if($mode===null)delete_option('sn_dot_sms_test_mode');else update_option('sn_dot_sms_test_mode',$mode);
$ops=new ReflectionMethod(SN_Operations_Flow::class,'send_invoice_sms');$ops->setAccessible(true);
$sent=$ops->invoke(SN_Operations_Flow::instance(),'09100000000','Synthetic',(string)$invoice->invoice_code,'https://synthetic.example.invalid/invoice/',1000.0,'Synthetic',$invoice_id);
hr239_ok(!$sent&&$calls()===$before_calls,'Operations canonical invoice route obeys global product switch');
$otp=get_option('sn_kavenegar_template_customer_otp',null);update_option('sn_kavenegar_template_customer_otp','synthetic-p241-otp');
hr239_ok((new SN_SMS())->send_customer_portal_otp('09100000000','000241')&&$calls()===$before_calls+1,'customer OTP remains independent of invoice SMS switch');
if($otp===null)delete_option('sn_kavenegar_template_customer_otp');else update_option('sn_kavenegar_template_customer_otp',$otp);
// Browser owns fresh synthetic credentials and rows in this namespace only.
wp_set_current_user($admin);
p241_save_product($admin,$pid,false,false);$browser_invoice=$copy();(new SN_SMS())->send_invoice_link('09100000000','Synthetic','https://synthetic.example.invalid/invoice/','Synthetic','1000','',$browser_invoice);
$page=wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>'آزمون محصول ۲۴۱','post_content'=>'[sn_seller_panel]']);
hr239_private('product241-fixture.json',['synthetic'=>true,'version'=>SN_VERSION,'admin'=>['id'=>(int)$browser_admin,'login'=>$login,'password'=>$password],'seller'=>$f['users']['seller_a'],'other'=>$f['users']['seller_b'],'manager_id'=>$manager,'product_id'=>$pid,'second_product_id'=>$second,'browser_invoice_id'=>$browser_invoice,'seller_page_id'=>(int)$page]);
$summary=['passed'=>true,'version'=>SN_VERSION,'assertions'=>$GLOBALS['hr239_checks'],'wordpress'=>$GLOBALS['wp_version'],'php'=>PHP_VERSION,'woocommerce'=>WC_VERSION,'synthetic'=>true,'production_touched'=>false,'real_sms_sent'=>false];
file_put_contents($GLOBALS['hr239_output'].'/product241-native.json',wp_json_encode($summary));echo 'SUMMARY '.wp_json_encode($summary).PHP_EOL;echo 'TOTAL '.$GLOBALS['hr239_checks'].PHP_EOL;ob_end_flush();
