<?php
ob_start();require __DIR__.'/native-support.php';hr239_bootstrap();$f=hr239_data();global $wpdb;
hr239_ok(class_exists('WooCommerce')&&WC_VERSION==='10.4.3','actual WooCommerce loaded for native invoice issuance');
$seller=$f['users']['seller_a']['id'];$manager=$f['users']['manager_a']['id'];$other=$f['users']['seller_b']['id'];
foreach(['manager_a','senior_a','supervisor_a','seller_a','manager_b','senior_b','supervisor_b','seller_b'] as $key){delete_user_meta($f['users'][$key]['id'],SN_HR_Invoice_Reissue::META_KEY);}
foreach([$seller,$other,$f['admin_id']] as $id){update_user_meta($id,'sn_manual_invoice_access','allow');}
$product=new WC_Product_Simple();$product->set_name('محصول ساختگی آزمون مجوز صدور');$product->set_status('publish');$product->set_regular_price('120000');$product->set_virtual(true);$pid=$product->save();update_post_meta($pid,'_sn_enabled','1');
hr239_ok($pid>0&&wc_get_product($pid)->get_price()==120000,'native published CRM-enabled Woo product');
$core=new ReflectionMethod(SN_Plugin::class,'sn_create_invoice_core');$core->setAccessible(true);$plugin=sn_bootstrap_plugin_instance();
$phone='09'.str_pad((string)random_int(100000000,999999999),9,'0',STR_PAD_LEFT);
$payload=['customer_name'=>'مشتری کاملاً ساختگی','customer_phone'=>$phone,'customer_address'=>'آدرس ساختگی برای آزمون','province'=>'تهران','city'=>'تهران','product_ids'=>[$pid],'product_qtys'=>[1]];
$call=static function(int $actor,array $extra=[],bool $controlled=false)use($core,$plugin,$payload):array{wp_set_current_user(0);wp_set_current_user($actor);return $core->invoke($plugin,array_merge($payload,$extra),$controlled);};
$count=static fn()=>(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}sn_invoices WHERE customer_phone=%s",$phone));
$first=$call($seller);hr239_private('hr239-last-invoice-result.json',$first);
hr239_ok(!empty($first['success'])&&$count()===1,'first ordinary invoice succeeds with default deny policy');
$second=$call($seller,['mode'=>'allow','sn_invoice_reissue_mode'=>'allow','seller_id'=>$other]);
hr239_ok(empty($second['success'])&&($second['status']??0)===409&&$count()===1,'same seller duplicate denied despite forged permission and seller input');
$persian=strtr($phone,['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹']);
$normalized=$call($seller,['customer_phone'=>$persian]);
hr239_ok(empty($normalized['success'])&&($normalized['status']??0)===409,'normalized Persian phone still matches existing invoice');
hr239_ok(hr239_save($f,$manager,'allow')['success'],'HR manager policy changed through actual AJAX');
$allowed=$call($seller);hr239_private('hr239-last-invoice-result.json',$allowed);
hr239_ok(!empty($allowed['success'])&&$count()===2,'inherited manager allow permits a real duplicate invoice');
hr239_ok(hr239_save($f,$seller,'deny')['success'],'HR personal deny stored through actual AJAX');
$denied=$call($seller);hr239_ok(empty($denied['success'])&&($denied['status']??0)===409&&$count()===2,'personal deny blocks duplicate after inherited grant');
hr239_ok(hr239_save($f,$seller,'inherit')['success'],'HR personal override cleared');
$again=$call($seller);hr239_ok(!empty($again['success'])&&$count()===3,'cleared personal override immediately inherits manager allow');
hr239_ok(hr239_save($f,$manager,'deny')['success'],'HR manager policy disabled');
$off=$call($seller);hr239_ok(empty($off['success'])&&($off['status']??0)===409&&$count()===3,'turning policy off immediately restores duplicate prevention');
$distinct=$call($other);hr239_ok(!empty($distinct['success'])&&$count()===4,'other seller may issue first invoice for same phone');
$admin=$call($f['admin_id'],['seller_id'=>$seller]);hr239_private('hr239-last-invoice-result.json',$admin);hr239_ok(!empty($admin['success'])&&$count()===5,'existing administrator duplicate exception preserved');
$controlled=$call($seller,['seller_id'=>$seller],true);hr239_ok(!empty($controlled['success'])&&$count()===6,'existing controlled workflow duplicate exception preserved');
// A grant must not bypass manual-issuance, product or lead ownership controls.
hr239_ok(hr239_save($f,$manager,'allow')['success'],'inherited grant prepared for independent access gates');
update_user_meta($seller,'sn_manual_invoice_access','deny');$manual=$call($seller);
hr239_ok(empty($manual['success'])&&($manual['status']??0)===403&&$count()===6,'reissue grant cannot bypass separate manual issuance denial');
update_user_meta($seller,'sn_manual_invoice_access','allow');update_post_meta($pid,'_sn_enabled','0');$disabled=$call($seller);
hr239_ok(empty($disabled['success'])&&($disabled['status']??0)===403&&$count()===6,'reissue grant cannot bypass CRM product eligibility');update_post_meta($pid,'_sn_enabled','1');
$wpdb->insert($wpdb->prefix.'sn_leads',['phone'=>'09'.random_int(100000000,999999999),'seller_id'=>$other,'supervisor_id'=>$f['users']['supervisor_b']['id'],'customer_name'=>'منبع ساختگی شاخه دیگر','imported_at'=>current_time('mysql')]);$lead=(int)$wpdb->insert_id;
hr239_ok($lead>0,'synthetic foreign-owned lead prepared');$foreign=$call($seller,['lead_id'=>$lead]);
hr239_ok(empty($foreign['success'])&&($foreign['status']??0)===403&&$count()===6,'reissue grant cannot bypass assigned lead ownership');
$rows=$wpdb->get_results($wpdb->prepare("SELECT id,access_token,payment_total_amount,status FROM {$wpdb->prefix}sn_invoices WHERE customer_phone=%s",$phone),ARRAY_A);
hr239_ok(count(array_unique(array_column($rows,'access_token')))===6&&!in_array('',array_column($rows,'access_token'),true),'every real reissue has a distinct nonempty public token');
hr239_ok(count(array_filter($rows,static fn($row)=>(float)$row['payment_total_amount']===120000.0&&$row['status']==='pre_invoice'))===6,'reissue preserves original unpaid invoice amounts and state');
delete_user_meta($manager,SN_HR_Invoice_Reissue::META_KEY);delete_user_meta($seller,SN_HR_Invoice_Reissue::META_KEY);
$summary=hr239_source_fingerprint()+['version'=>SN_VERSION,'woocommerce'=>WC_VERSION,'assertions'=>$GLOBALS['hr239_checks'],'passed'=>true,'real_invoice_rows'=>6,'synthetic'=>true,'production_touched'=>false];
file_put_contents($GLOBALS['hr239_output'].'/hr239-invoices-summary.json',wp_json_encode($summary,JSON_PRETTY_PRINT));echo 'TOTAL '.$GLOBALS['hr239_checks'].PHP_EOL;ob_end_flush();
