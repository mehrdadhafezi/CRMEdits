<?php
/** Test-only MU plugin; packaging excludes tests. OS egress is blocked before bootstrap. */
if (!defined('SN_APPROVAL_TEST_SANDBOX') || !SN_APPROVAL_TEST_SANDBOX
    || !defined('DB_NAME') || !preg_match('/^sn_approval_[a-z0-9_]+_test$/', DB_NAME)
    || DB_HOST !== 'localhost' || WP_ENVIRONMENT_TYPE !== 'local') {
    throw new RuntimeException('Refusing to load the integration helper outside its sandbox.');
}
add_filter('pre_http_request', static fn() => new WP_Error('synthetic_network_blocked', 'Sandbox HTTP disabled'), 1);
add_filter('pre_wp_mail', static fn() => false);
if (PHP_SAPI === 'cli') {
    ini_set('display_errors', 'stderr');
    ini_set('zend.exception_ignore_args', '1');
}
add_action('init', static function (): void {
    if (!isset($_GET['sn_test_nonce'])) { return; }
    if (!is_user_logged_in()) { wp_send_json(['success'=>false], 403); }
    wp_send_json(['success'=>true, 'nonce'=>wp_create_nonce('sn_public'), 'report_nonce'=>wp_create_nonce('sn_reporting_export'), 'user_id'=>get_current_user_id()]);
}, 99999);
