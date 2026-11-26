<?php
/** Synthetic actor/owner catalog proof, including server-side forged selections. */
ob_start();require dirname(__DIR__).'/hr/native-support.php';hr239_bootstrap();$f=hr239_data();
$manager=(int)$f['users']['manager_a']['id'];$seller=(int)$f['users']['seller_a']['id'];$other=(int)$f['users']['seller_b']['id'];$admin=(int)$f['admin_id'];
$key='_sn_sales_catalog_policy';$had=metadata_exists('user',$manager,$key);$old=get_user_meta($manager,$key,true);
register_shutdown_function(static function()use($manager,$key,$old,$had){if($had)update_user_meta($manager,$key,$old);else delete_user_meta($manager,$key);});
$ids=[];foreach(['Allowed','Forbidden']as $name){$p=new WC_Product_Simple();$p->set_name('Synthetic catalog '.$name);$p->set_status('publish');$p->set_regular_price('1000');$p->set_virtual(true);$ids[]=$p->save();update_post_meta(end($ids),'_sn_enabled','1');}
update_user_meta($manager,$key,['mode'=>'selected','products'=>[$ids[0]=>['discounts'=>['10000'],'allow_custom_discount'=>true]],'excluded_products'=>[],'revision'=>'synthetic245']);
$catalog=SN_Sales_Catalog::instance();hr239_ok($catalog->manager_for_user($seller)===$manager,'seller resolves to its HR sales manager');
foreach([$manager,$seller]as $actor){wp_set_current_user(0);wp_set_current_user($actor);$products=SN_Helpers::get_sn_products();
 hr239_ok(array_column($products,'id')===[$ids[0]],'published product list respects selected manager policy');
 $discounts=$catalog->discounts($ids[0],$actor);hr239_ok(count($discounts)===1&&$discounts[0]['amount']==100.0,'fixed manager discount survives staff catalog resolution');
 hr239_ok($catalog->custom_discount_allowed($ids[0],$actor),'custom discount permission reaches manager and seller');
 hr239_ok(is_wp_error($catalog->validate_products([$ids[1]],$actor)),'forged forbidden product remains rejected by server');
}
wp_set_current_user($seller);hr239_ok(is_wp_error($catalog->price_items([['product_id'=>$ids[0],'qty'=>1,'unit_price'=>1000,'total_price'=>1000]],['999999'],$seller,$seller)),'forged fixed discount remains rejected');
class Catalog245End extends RuntimeException{}
$handler=static fn()=>static function(){throw new Catalog245End();};add_filter('wp_die_ajax_handler',$handler);add_filter('wp_die_handler',$handler);
$request=static function(int $actor,array $data)use($catalog):array{
 wp_set_current_user(0);wp_set_current_user($actor);$_POST=array_replace(['nonce'=>wp_create_nonce('sn_sales_catalog_staff')],$data);$_REQUEST=$_POST;ob_start();
 try{$catalog->ajax_staff();}catch(Catalog245End $e){}$body=ob_get_clean();return json_decode($body,true,512,JSON_THROW_ON_ERROR);
};
$r=$request($seller,[]);hr239_ok(!empty($r['success'])&&$r['data']['manager_id']===$manager&&array_column($r['data']['products'],'id')===[$ids[0]],'actual staff AJAX returns only manager-authorized product');
hr239_ok(count($r['data']['products'][0]['discounts'])===1&&$r['data']['products'][0]['allow_custom_discount']===true,'actual AJAX retains fixed and optional custom discounts');
$r=$request($seller,['seller_id'=>$other]);hr239_ok(empty($r['success']),'seller cannot request another branch catalog');
$r=$request($admin,['seller_id'=>$seller]);hr239_ok(!empty($r['success'])&&array_column($r['data']['products'],'id')===[$ids[0]],'admin acting for owner respects owner product policy');
remove_filter('wp_die_ajax_handler',$handler);remove_filter('wp_die_handler',$handler);
file_put_contents($GLOBALS['hr239_output'].'/catalog245-native.json',wp_json_encode(['passed'=>true,'synthetic'=>true,'production_touched'=>false,'version'=>SN_VERSION,'assertions'=>$GLOBALS['hr239_checks'],'real_wordpress_mysql'=>true,'owner_scope'=>true,'forged_selections_denied'=>true,'catalog_sha256'=>hash_file('sha256',SN_PLUGIN_DIR.'includes/class-sn-sales-catalog.php')]));
echo 'TOTAL '.$GLOBALS['hr239_checks'].PHP_EOL;ob_end_flush();
