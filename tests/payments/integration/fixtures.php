<?php
require __DIR__ . '/support.php';
sn_test_bootstrap();
sn_bootstrap_load_core();
sn_bootstrap_plugin_instance(true);
global $wpdb;
$admin = json_decode(file_get_contents(getenv('SN_APPROVAL_ROOT') . '/admin.json'), true);
wp_set_current_user((int)$admin['id']);
$fixtures = ['users'=>[], 'invoices'=>[], 'payments'=>[], 'pages'=>[]];
$roles = ['seller'=>'sn_seller', 'other'=>'sn_seller', 'supervisor'=>'sn_supervisor', 'senior'=>'sn_senior_supervisor', 'finance'=>'sn_financial', 'subscriber'=>'subscriber', 'revoked'=>'sn_test_revoked'];
add_role('sn_test_revoked', 'Synthetic view-only finance', ['read'=>true, 'sn_view_finance'=>true]);
$profiles = [];
foreach ($roles as $key=>$role) {
    $password = bin2hex(random_bytes(20));
    $id = wp_insert_user(['user_login'=>'sn_fixture_' . $key, 'user_pass'=>$password, 'user_email'=>$key . '@example.invalid', 'display_name'=>'آزمایشی ' . $key, 'role'=>$role]);
    sn_test_ok(!is_wp_error($id), 'synthetic account ' . $key);
    $fixtures['users'][$key] = ['id'=>(int)$id, 'login'=>'sn_fixture_' . $key, 'password'=>$password];
    if (in_array($key, ['seller','other','supervisor','senior'], true)) {
        $position = ['other'=>'seller', 'senior'=>'senior_supervisor'][$key] ?? $key;
        $positionId = (int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}sn_hr_positions WHERE slug=%s", $position));
        sn_test_ok($positionId > 0, 'installed HR position ' . $key);
        $wpdb->replace($wpdb->prefix . 'sn_hr_profiles', ['user_id'=>$id, 'position_id'=>$positionId, 'is_active'=>1, 'employment_status'=>'active']);
        $profiles[$key] = (int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}sn_hr_profiles WHERE user_id=%d", $id));
    }
}
// Explicit synthetic finance caps; production alias roles intentionally do not grant them.
$finance = get_user_by('id', $fixtures['users']['finance']['id']);
foreach (['sn_view_finance','sn_approve_payment','sn_reject_payment'] as $cap) { $finance->add_cap($cap); }
$hierarchy = new SN_Hierarchy_Service();
foreach ([['seller','supervisor'], ['supervisor','senior']] as [$child,$parent]) {
    sn_test_ok(!empty($hierarchy->assign_parent($profiles[$child], $profiles[$parent], $admin['id'], 'synthetic_test')['success']), 'real HR descendant assignment ' . $child);
}
update_user_meta($fixtures['users']['seller']['id'], 'sn_supervisor_id', $fixtures['users']['supervisor']['id']);
$makeInvoice = static function(string $key, array $changes = []) use (&$fixtures, $wpdb): int {
    $row = array_replace([
        'invoice_code'=>'SYN-' . strtoupper($key), 'seller_id'=>$fixtures['users']['seller']['id'],
        'issued_by_user_id'=>$fixtures['users']['seller']['id'], 'customer_name'=>'مشتری آزمایشی <img src=x onerror=window.__snFixtureXss=1>',
        'customer_phone'=>'00000000000', 'product_id'=>0, 'product_price'=>1000000, 'final_total'=>1000000,
        'status'=>'pending_financial_approval', 'invoice_status'=>'pending_financial_approval', 'payment_status'=>'pending_financial_approval',
        'payment_plan'=>'partial', 'payment_total_amount'=>1000000, 'paid_total_amount'=>0, 'remaining_amount'=>1000000,
        'current_due_amount'=>1000000, 'current_payment_stage'=>1, 'payment_workflow_status'=>'awaiting_financial_approval',
        'pay_method'=>'card', 'payment_source'=>'seller_manual', 'access_token'=>bin2hex(random_bytes(32)),
        'created_at'=>'2026-11-01 10:00:00', 'updated_at'=>'2026-11-01 10:00:00',
    ], $changes);
    sn_test_ok($wpdb->insert($wpdb->prefix . 'sn_invoices', $row) !== false, 'synthetic invoice ' . $key);
    $id = (int)$wpdb->insert_id;
    $fixtures['invoices'][$key] = $id;
    $fixtures['tokens'][] = $row['access_token'];
    sn_test_ok($wpdb->insert($wpdb->prefix . 'sn_invoice_payment_stages', ['invoice_id'=>$id, 'stage_no'=>1, 'stage_type'=>'partial', 'requested_amount'=>1000000, 'status'=>'pending_financial_approval']) !== false, 'synthetic stage ' . $key);
    return $id;
};
$makePayment = static function(string $key, int $invoiceId, float $amount, string $status = 'pending') use (&$fixtures, $wpdb): int {
    $proof = ['entry_mode'=>'info', 'manual_transfer_type'=>'card', '_review'=>['revision'=>1]];
    sn_test_ok($wpdb->insert($wpdb->prefix . 'sn_payments', ['invoice_id'=>$invoiceId, 'payment_stage_no'=>1, 'amount'=>$amount, 'status'=>$status,
        'authority'=>'', 'pay_method'=>'card', 'payment_source'=>'seller_manual', 'uploaded_by_type'=>'seller',
        'uploaded_by_user_id'=>$fixtures['users']['seller']['id'], 'proof_data'=>wp_json_encode($proof)]) !== false, 'synthetic payment ' . $key);
    return $fixtures['payments'][$key] = (int)$wpdb->insert_id;
};
$partial = $makeInvoice('partial', ['paid_total_amount'=>300000, 'remaining_amount'=>700000, 'current_due_amount'=>700000]);
$makePayment('partial_paid', $partial, 300000, 'approved');
$makePayment('partial_pending', $partial, 700000);
$makeInvoice('final', ['status'=>'approved', 'invoice_status'=>'approved', 'payment_status'=>'pending_financial_approval', 'product_price'=>1200000,
    'final_total'=>1200000, 'payment_total_amount'=>1200000, 'paid_total_amount'=>1200000, 'remaining_amount'=>0, 'current_due_amount'=>0, 'payment_workflow_status'=>'completed']);
$makeInvoice('foreign', ['seller_id'=>$fixtures['users']['other']['id'], 'issued_by_user_id'=>$fixtures['users']['other']['id'],
    'status'=>'approved', 'invoice_status'=>'approved', 'payment_status'=>'approved', 'paid_total_amount'=>999000, 'remaining_amount'=>1000]);
$makeInvoice('cancelled', ['status'=>'cancelled', 'invoice_status'=>'cancelled', 'payment_status'=>'cancelled', 'paid_total_amount'=>100000, 'remaining_amount'=>900000]);
foreach (['decide','failure'] as $key) {
    $id = $makeInvoice($key, $key === 'failure' ? ['customer_name'=>'=1+1'] : []);
    $makePayment($key . '_first', $id, 300000);
    $makePayment($key . '_last', $id, 700000);
}
foreach (['seller'=>'sn_seller_panel', 'supervisor'=>'sn_supervisor_panel', 'senior'=>'sn_senior_supervisor_panel'] as $key=>$shortcode) {
    $id = wp_insert_post(['post_type'=>'page', 'post_status'=>'publish', 'post_title'=>'آزمون ' . $key, 'post_content'=>'[' . $shortcode . ']']);
    $fixtures['pages'][$key] = '/?page_id=' . $id;
}
$fixtures['pages']['reports'] = '/?page_id=' . wp_insert_post(['post_type'=>'page', 'post_status'=>'publish', 'post_title'=>'گزارش آزمایشی', 'post_content'=>'[sn_reports_panel]']);
$fixtures['users']['admin'] = ['id'=>(int)$admin['id'], 'login'=>'sn_test_admin', 'password'=>$admin['password']];
$fixtures['baseline_version'] = SN_VERSION;
$fixtures['financial_hash'] = sn_test_financial_hash();
$fixtures['roles_hash'] = hash('sha256', wp_json_encode(wp_roles()->roles));
file_put_contents(getenv('SN_APPROVAL_ROOT') . '/fixture.json', json_encode($fixtures, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
echo 'TOTAL ' . $GLOBALS['sn_test_checks'] . PHP_EOL;
