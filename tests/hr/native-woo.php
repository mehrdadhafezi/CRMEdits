<?php
require __DIR__.'/native-support.php';hr239_bootstrap();
$f=hr239_data();wp_set_current_user($f['admin_id']);
require_once ABSPATH.'wp-admin/includes/plugin.php';
if(!is_plugin_active('woocommerce/woocommerce.php')){
    $result=activate_plugin('woocommerce/woocommerce.php');
    hr239_ok(!is_wp_error($result),'official WooCommerce activated only in private synthetic site');
}
hr239_ok(class_exists('WooCommerce')&&defined('WC_VERSION')&&WC_VERSION==='10.4.3','native WooCommerce dependency matches tested production version');
