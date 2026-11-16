<?php
require __DIR__ . '/support.php';
sn_test_bootstrap(true);
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
$password = bin2hex(random_bytes(24));
$installed = wp_install('CRM synthetic integration', 'sn_test_admin', 'admin@example.invalid', false, '', $password);
sn_test_ok(!is_wp_error($installed), 'fresh WordPress installed in disposable MySQL');
$activation = activate_plugin('sales-network-v4/sales-network.php');
sn_test_ok(!is_wp_error($activation), 'baseline plugin activation completed');
update_option('WPLANG', 'fa_IR');
update_option('timezone_string', 'Asia/Tehran');
update_option('permalink_structure', '');
switch_theme('sn-synthetic');
file_put_contents(getenv('SN_APPROVAL_ROOT') . '/admin.json', json_encode(['id'=>$installed['user_id'], 'password'=>$password], JSON_THROW_ON_ERROR));
echo 'TOTAL ' . $GLOBALS['sn_test_checks'] . PHP_EOL;
