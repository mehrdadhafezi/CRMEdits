<?php
require __DIR__ . '/support.php';
sn_test_bootstrap();
sn_bootstrap_load_core();
sn_bootstrap_plugin_instance(true);
global $wpdb, $wp_version;
$f = sn_test_data();
$phase = $argv[1] ?? 'upgrade';
$admin = json_decode(file_get_contents(getenv('SN_APPROVAL_ROOT') . '/admin.json'), true);
wp_set_current_user((int)$admin['id']);
if (in_array($phase, ['upgrade','rollback','restored'], true)) {
    sn_test_ok(sn_test_financial_hash() === $f['financial_hash'], $phase . ': invoice/stage/payment data unchanged by bootstrap');
    sn_test_ok(hash('sha256', wp_json_encode(wp_roles()->roles)) === $f['roles_hash'], $phase . ': existing roles/capabilities preserved');
    sn_test_ok(SN_VERSION === ($phase === 'rollback' ? $f['baseline_version'] : '2.0.244'), $phase . ': intended runtime version loaded');
}
if ($phase === 'upgrade') {
    foreach (['sn_invoices','sn_invoice_payment_stages','sn_payments','sn_payment_review_events'] as $suffix) {
        $engine = $wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $wpdb->prefix . $suffix));
        sn_test_ok(strtolower((string)$engine) === 'innodb', 'native transactional engine ' . $suffix);
    }
    $sql = sn_test_call('sn_invoice_received_amount_sql', 'i');
    $rows = $wpdb->get_results("SELECT i.*, ({$sql}) AS synthetic_sql_received FROM {$wpdb->prefix}sn_invoices i");
    sn_test_ok($wpdb->last_error === '' && count($rows) === count($f['invoices']), 'native MySQL executes the complete received aggregate');
    foreach ($rows as $row) {
        sn_test_ok(abs((float)$row->synthetic_sql_received - sn_test_call('sn_invoice_received_amount', $row)) < 0.005, 'MySQL/PHP monetary parity ' . $row->invoice_code);
    }
    $filters = ['seller_id'=>$f['users']['seller']['id'], 'q'=>'SYN-PARTIAL', 'pay_method'=>'', 'product_type'=>'', 'date_from'=>'', 'date_to'=>''];
    $report = sn_test_call('sn_successful_payment_report_stats', $filters);
    sn_test_ok((int)$report['payment_count'] === 1 && abs((float)$report['total_amount'] - 300000) < 0.005, 'actual MySQL report includes approved receipt once');
    $filters['q'] = 'SYN-FINAL';
    $report = sn_test_call('sn_successful_payment_report_stats', $filters);
    sn_test_ok((int)$report['payment_count'] === 1 && abs((float)$report['total_amount'] - 1200000) < 0.005, 'actual MySQL report uses the legacy final-invoice fallback once');
    sn_test_ok(sn_test_financial_hash() === $f['financial_hash'], 'read-only reports and aggregates preserve financial rows');
}
if ($phase === 'final') {
    $id = $f['invoices']['decide'];
    $invoice = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_invoices WHERE id=%d", $id));
    sn_test_ok(in_array($invoice->status, ['approved','paid'], true) && (float)$invoice->remaining_amount < 0.005 && (float)$invoice->paid_total_amount === 1000000.0, 'real two-receipt approval settles the invoice exactly');
    sn_test_ok((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}sn_payment_review_events WHERE invoice_id=%d AND action='approve'", $id)) === 2, 'concurrent/repeated approval creates one decision event per receipt');
    $filters = ['seller_id'=>$f['users']['seller']['id'], 'q'=>'SYN-DECIDE', 'pay_method'=>'', 'product_type'=>'', 'date_from'=>'', 'date_to'=>''];
    $report = sn_test_call('sn_successful_payment_report_stats', $filters);
    sn_test_ok((int)$report['payment_count'] === 2 && abs((float)$report['total_amount'] - 1000000) < 0.005, 'completed two-stage ledger does not duplicate the invoice fallback');
    $policy = [
        'view'=>static fn() => sn_test_call('sn_can_view_finance'),
        'approve'=>static fn() => sn_test_call('sn_can_approve_payment'),
        'reject'=>static fn() => sn_test_call('sn_can_reject_payment'),
        'totals'=>static fn($inv) => sn_test_call('sn_invoice_payment_totals', $inv),
        'finalize'=>static fn($invoiceId,$no) => sn_test_call('sn_finalize_current_payment_stage', $invoiceId, 'card', 'finance_receipt_review', '', get_current_user_id(), 'approved', $no),
    ];
    $service = new SN_Receipt_Review($policy);
    $before = sn_test_financial_hash();
    $walletBefore = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}sn_wallet_transactions");
    $duplicate = $service->handle(['invoice_id'=>$id, 'payment_id'=>$f['payments']['decide_last'], 'operation'=>'approve', 'revision'=>2]);
    sn_test_ok(!empty($duplicate['already']) && sn_test_financial_hash() === $before, 'idempotent repeated approval cannot change financial rows');
    sn_test_ok($wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}sn_wallet_transactions") === $walletBefore, 'idempotent approval cannot create an extra wallet credit');
    $failureInput = ['invoice_id'=>$f['invoices']['failure'], 'payment_id'=>$f['payments']['failure_first'], 'operation'=>'approve', 'revision'=>1];
    $denied = $service->handle($failureInput, [], static fn() => new WP_Error('synthetic_guard', 'Synthetic pre-write failure'));
    sn_test_ok(empty($denied['success']) && sn_test_financial_hash() === $before, 'pre-write failure leaves native financial rows unchanged');
    $failEventInsert = static function($query) use ($wpdb) {
        return preg_match('/^INSERT\s+INTO\s+`?' . preg_quote($wpdb->prefix . 'sn_payment_review_events', '/') . '`?\s/i', $query)
            ? str_replace($wpdb->prefix . 'sn_payment_review_events', $wpdb->prefix . 'synthetic_missing_events', $query) : $query;
    };
    add_filter('query', $failEventInsert);
    try { $failed = $service->handle($failureInput); } finally { remove_filter('query', $failEventInsert); }
    sn_test_ok(empty($failed['success']) && sn_test_financial_hash() === $before, 'actual SQL insert failure rolls back receipt decision and invoice sync');
    sn_test_ok((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}sn_payment_review_events WHERE invoice_id=%d", $f['invoices']['failure'])) === 0, 'failed transaction leaves no partial decision history');
    $revoked = new WP_User($f['users']['revoked']['id']);
    sn_test_ok(!$revoked->has_cap('sn_approve_payment') && !$revoked->has_cap('manage_options'), 'view-only fixture has no hidden financial privilege');
}
$summary = ['phase'=>$phase, 'assertions'=>$GLOBALS['sn_test_checks'] ?? 0, 'php'=>PHP_VERSION, 'wordpress'=>$wp_version,
    'mysql'=>$wpdb->db_version(), 'plugin'=>SN_VERSION, 'baseline'=>$f['baseline_version'], 'synthetic'=>true];
file_put_contents(getenv('SN_APPROVAL_OUTPUT') . '/mysql-' . $phase . '.json', json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo 'TOTAL ' . ($GLOBALS['sn_test_checks'] ?? 0) . PHP_EOL;
