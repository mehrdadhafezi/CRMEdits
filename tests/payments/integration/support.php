<?php
/** Guards and assertions for the disposable native WordPress/MySQL suite. */
function sn_test_bootstrap(bool $installing = false): void {
    if (PHP_SAPI !== 'cli' || getenv('SN_APPROVAL_ALLOW_SYNTHETIC') !== 'YES') {
        throw new RuntimeException('Explicit disposable CLI sandbox required.');
    }
    $root = realpath((string) getenv('SN_APPROVAL_ROOT'));
    $bootstrap = realpath((string) getenv('SN_PAYMENT_TEST_BOOTSTRAP'));
    if (!$root || !str_starts_with($root, '/tmp/sn-approval-') || !$bootstrap
        || $bootstrap !== $root . '/wordpress/wp-load.php') {
        throw new RuntimeException('Bootstrap must belong to the generated disposable sandbox.');
    }
    if ($installing) { define('WP_INSTALLING', true); }
    require_once $bootstrap;
    global $wpdb;
    if (!defined('SN_APPROVAL_TEST_SANDBOX') || !SN_APPROVAL_TEST_SANDBOX
        || wp_get_environment_type() !== 'local' || DB_HOST !== 'localhost'
        || !preg_match('/^sn_approval_[a-z0-9_]+_test$/', DB_NAME)
        || $wpdb->get_var('SELECT DATABASE()') !== DB_NAME) {
        throw new RuntimeException('Disposable local MySQL isolation guard failed.');
    }
    $wpdb->hide_errors();
}
function sn_test_ok($condition, string $name): void {
    if (!$condition) { throw new RuntimeException('FAIL ' . $name); }
    $GLOBALS['sn_test_checks'] = ($GLOBALS['sn_test_checks'] ?? 0) + 1;
    echo 'PASS ' . $name . PHP_EOL;
}
function sn_test_data(): array {
    return json_decode(file_get_contents(getenv('SN_APPROVAL_ROOT') . '/fixture.json'), true, 512, JSON_THROW_ON_ERROR);
}
function sn_test_call(string $method, ...$args) {
    $m = new ReflectionMethod(SN_Plugin::class, $method);
    $m->setAccessible(true);
    return $m->invoke(sn_bootstrap_plugin_instance(), ...$args);
}
function sn_test_financial_hash(): string {
    global $wpdb;
    $snapshot = [];
    foreach (['sn_invoices', 'sn_invoice_payment_stages', 'sn_payments'] as $suffix) {
        $snapshot[$suffix] = $wpdb->get_results('SELECT * FROM ' . $wpdb->prefix . $suffix . ' ORDER BY id', ARRAY_A);
    }
    return hash('sha256', wp_json_encode($snapshot));
}
