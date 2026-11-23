<?php
/** Test-only MU plugin. Install only in a fresh, guarded disposable fixture. */
if(!defined('SN_PREPAYMENT_TEST_SANDBOX')||!SN_PREPAYMENT_TEST_SANDBOX||WP_ENVIRONMENT_TYPE!=='local'||!preg_match('/^sn_prepaid_[a-z0-9]+_test$/',DB_NAME)){throw new RuntimeException('Invalid synthetic sandbox');}
add_filter('pre_http_request',static fn()=>new WP_Error('synthetic_egress_blocked','External HTTP disabled'),1);
add_filter('pre_wp_mail',static fn()=>false);
if(($_SERVER['HTTP_X_SYNTHETIC_PREPAYMENT_FAILURE']??'')==='stage-write'){add_filter('query',static function($sql){global $wpdb;return str_starts_with($sql,'UPDATE `'.$wpdb->prefix.'sn_invoice_payment_stages`')?'SYNTHETIC_EXPECTED_SQL_FAILURE':$sql;});}
add_action('template_redirect',static function(){
 if(!isset($_GET['sn_prepayment_fixture']))return;
 if(!is_user_logged_in()){wp_safe_redirect(wp_login_url());exit;}
 $f=json_decode(file_get_contents('/evidence/browser-page.json'),true);
 wp_enqueue_style('prep-public',plugins_url('sales-network-v4/assets/css/public.css'),[],filemtime(WP_PLUGIN_DIR.'/sales-network-v4/assets/css/public.css'));
 wp_enqueue_script('prep-shell',plugins_url('sales-network-v4/assets/js/public-shell.js'),[],filemtime(WP_PLUGIN_DIR.'/sales-network-v4/assets/js/public-shell.js'),true);
 wp_add_inline_script('prep-shell','window.snAjax='.wp_json_encode(['ajaxurl'=>admin_url('admin-ajax.php'),'nonce'=>wp_create_nonce('sn_public')]).';','before');
 header('Content-Type: text/html; charset=UTF-8');?><!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><?php wp_head();?></head><body><main style="padding:24px"><h1>پیش‌فاکتورهای کاملاً ساختگی</h1><?php foreach(['browser_invoice','browser_sender_invoice','browser_locked_invoice'] as $key){echo '<p><button class="sn-btn sn-edit-invoice" data-invoice-id="'.(int)$f[$key].'">ویرایش پیش از پرداخت</button></p>';}?></main><?php wp_footer();?></body></html><?php exit;
},0);
