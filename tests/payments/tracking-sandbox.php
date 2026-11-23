<?php
/** Test-only MU plugin, never installed on an existing site. */
if(!defined('SN_TRACKING_TEST_SANDBOX')||!SN_TRACKING_TEST_SANDBOX||WP_ENVIRONMENT_TYPE!=='local'||!preg_match('/^sn_tracking_[a-z0-9]+_test$/',DB_NAME))throw new RuntimeException('Synthetic tracking sandbox required');
add_filter('pre_http_request',static fn()=>new WP_Error('synthetic_egress_blocked','External HTTP disabled'),1);add_filter('pre_wp_mail',static fn()=>false);
add_action('template_redirect',static function(){
 if(!isset($_GET['sn_tracking_fixture']))return;if(!is_user_logged_in()){wp_safe_redirect(wp_login_url());exit;}
 $page=json_decode(file_get_contents('/evidence/browser-page.json'),true);
 foreach(['public','payment-batch'] as $style)wp_enqueue_style('tracking-'.$style,plugins_url('sales-network-v4/assets/css/'.$style.'.css'));
 foreach(['public-shell','payment-transactions','payment-batch','receipt-review','unified-invoice-payments'] as $index=>$script)wp_enqueue_script('tracking-'.$script,plugins_url('sales-network-v4/assets/js/'.$script.'.js'),$index?['tracking-public-shell']:[],filemtime(WP_PLUGIN_DIR.'/sales-network-v4/assets/js/'.$script.'.js'),true);
 wp_add_inline_script('tracking-public-shell','window.snAjax='.wp_json_encode(['ajaxurl'=>admin_url('admin-ajax.php'),'nonce'=>wp_create_nonce('sn_public')]).';','before');
 header('Content-Type: text/html; charset=UTF-8');?><!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><?php wp_head();?></head><body><main style="padding:24px"><h1>پرداخت‌های کاملاً ساختگی</h1><button class="sn-btn sn-unified-state-open" data-invoice-id="<?php echo (int)$page['invoice'];?>">مدیریت پرداخت</button></main><?php wp_footer();?></body></html><?php exit;
},0);
