<?php
if (!defined('ABSPATH')) { exit; }

/** New conversion purchases use the ordinary invoice/stage ledger; legacy links stay readable. */
trait SN_Dot_Invoice_Ledger
{
    /** Expand the adapter into canonical stages for display; adapter id is only a resend target. */
    private function staged_conversion_overview_rows(object $case, array $fallback): array
    {
        global $wpdb; $link = $this->staged_conversion_link((int)$case->id);
        if (!$link) { return $fallback; }
        $invoice = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_invoices WHERE id=%d", (int)$link->invoice_id));
        if (!$invoice || !$this->same_conversion_invoice_customer($invoice, $case)) { return []; }
        $stages = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_invoice_payment_stages WHERE invoice_id=%d ORDER BY stage_no DESC", (int)$invoice->id)) ?: [];
        $adapter_id = 0;
        foreach ($fallback as $row) { if ((int)$row->invoice_id === (int)$invoice->id) { $adapter_id = (int)$row->id; break; } }
        foreach ($stages as $stage) {
            $stage->id = (int)$stage->stage_no === (int)$invoice->current_payment_stage ? $adapter_id : 0;
            $stage->invoice_code = (string)$invoice->invoice_code;
            $stage->payment_type = ['partial'=>'deposit','remaining'=>'balance','full'=>'full'][$stage->stage_type] ?? 'deposit';
            $stage->status = ['approved'=>'paid','receipt_uploaded'=>'pending_finance','pending_financial_approval'=>'pending_finance'][$stage->status] ?? $stage->status;
        }
        return $stages;
    }

    private function staged_conversion_link(int $case_id): ?object
    {
        global $wpdb; $t = $this->tables();
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['links']} WHERE case_id=%d AND flow_kind='conversion_payment' AND payment_kind='staged_receipt' LIMIT 1", $case_id)) ?: null;
    }

    public function is_staged_conversion_invoice(int $invoice_id): bool
    {
        global $wpdb; $t = $this->tables();
        if (!$invoice_id || !$this->table_exists($t['links'])) { return false; }
        return (bool)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$t['links']} WHERE invoice_id=%d AND flow_kind='conversion_payment' AND payment_kind='staged_receipt' LIMIT 1", $invoice_id));
    }

    /** Internal finance adapter; caller owns the invoice/stage transaction and authorization. */
    public function staged_conversion_financial_apply_in_transaction(int $invoice_id): bool
    {
        return !empty($this->synchronize_staged_conversion($invoice_id, false)['success']);
    }

    /** Invoked only after the invoice row is locked by the common stage issuer. */
    public function can_issue_staged_conversion_stage(int $case_id, int $invoice_id, int $actor_id): bool
    {
        global $wpdb; $t = $this->tables();
        if (get_current_user_id() !== $actor_id || !SN_Subscription_Plus::instance()->transactional_tables(['sn_dot_cases','sn_dot_invoice_links','sn_dot_payments','sn_invoices','sn_invoice_payment_stages'])) { return false; }
        $link = $this->staged_conversion_link($case_id);
        $case = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['cases']} WHERE id=%d FOR UPDATE", $case_id));
        $invoice = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_invoices WHERE id=%d", $invoice_id));
        if (!$link || (int)$link->invoice_id !== $invoice_id || !$case || !$invoice || !$this->can_manage_converter_case($case, $actor_id)
            || !empty($case->operational_archived_at) || in_array((string)$case->status, ['customer_declined','archived_unpaid_subscription'], true)
            || (int)$invoice->seller_id !== (int)$case->seller_id || !$this->same_conversion_invoice_customer($invoice, $case)
            || abs((float)$invoice->payment_total_amount - (float)$case->selected_option_price) > 0.005) { return false; }
        $product_id = (int)($case->selected_option_product_id ?? 0);
        if ($product_id && (!wc_get_product($product_id) || get_post_status($product_id) !== 'publish' || get_post_meta($product_id, '_sn_enabled', true) !== '1')) { return false; }
        $plus_sale = SN_Subscription_Plus::instance()->case_sale($case);
        $converted_plus = !$plus_sale && empty($case->referral_item_id) && SN_Subscription_Plus::instance()->is_product($product_id);
        if ($product_id && empty($case->referral_item_id) && !$converted_plus && !SN_Projects::instance()->is_subscription_product($product_id)) { return false; }
        return true;
    }

    /** Same transaction as the common issuer: a failed case update rolls back its new stage. */
    public function advance_staged_conversion_stage(int $case_id, int $invoice_id, int $stage_no, float $amount, string $mode): bool
    {
        global $wpdb; $t = $this->tables(); $now = current_time('mysql');
        $link = $this->staged_conversion_link($case_id);
        if (!$link || (int)$link->invoice_id !== $invoice_id) { return false; }
        $payment = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['payments']} WHERE invoice_id=%d AND case_id=%d FOR UPDATE", $invoice_id, $case_id));
        if (!$payment) { return false; }
        return false !== $wpdb->update($t['payments'], ['payment_type'=>$mode === 'remaining' ? 'balance' : 'deposit','requested_amount'=>$amount,'status'=>'pending','paid_at'=>null,'sms_sent_at'=>null,'updated_at'=>$now], ['id'=>(int)$payment->id])
            && false !== $wpdb->update($t['cases'], ['status'=>'payment_link_sent','payment_mode'=>$mode === 'remaining' ? 'balance' : 'deposit','updated_at'=>$now], ['id'=>$case_id]);
    }

    /** Existing aggregate adapter row is a projection, never another receipt or invoice. */
    private function synchronize_staged_conversion(int $invoice_id, bool $owns_transaction = true, ?string $forced_payment_status = null): array
    {
        global $wpdb; $t = $this->tables();
        if (!$this->is_staged_conversion_invoice($invoice_id)) { return ['success'=>true,'handled'=>false]; }
        if ($owns_transaction && false === $wpdb->query('START TRANSACTION')) { return ['success'=>false]; }
        try {
            $invoice = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_invoices WHERE id=%d FOR UPDATE", $invoice_id));
            $link = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['links']} WHERE invoice_id=%d AND payment_kind='staged_receipt' FOR UPDATE", $invoice_id));
            $case = $link ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['cases']} WHERE id=%d FOR UPDATE", (int)$link->case_id)) : null;
            $payment = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['payments']} WHERE invoice_id=%d FOR UPDATE", $invoice_id));
            $stage = $invoice ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_invoice_payment_stages WHERE invoice_id=%d AND stage_no=%d", $invoice_id, (int)$invoice->current_payment_stage)) : null;
            if (!$invoice || !$case || !$payment || !$stage || !$this->same_conversion_invoice_customer($invoice, $case)) { throw new RuntimeException('Staged conversion identity'); }
            $total = max(0, (float)$invoice->payment_total_amount);
            $paid = min($total, max(0, (float)$invoice->paid_total_amount)); $remaining = max(0, $total - $paid);
            $closed = in_array((string)$invoice->status, ['cancelled','payment_archived'], true);
            $completed = !$closed && $total > 0.5 && $remaining <= 0.5 && (string)$invoice->payment_workflow_status === 'completed' && in_array((string)$stage->status, ['approved','paid'], true);
            $status = $closed ? 'superseded' : (in_array((string)$stage->status, ['approved','paid'], true) ? 'paid' : ((string)$stage->status === 'rejected' ? 'rejected' : (in_array((string)$stage->status, ['pending_financial_approval','receipt_uploaded'], true) ? 'pending_finance' : 'pending')));
            if (!$closed && $forced_payment_status !== null) { $status = $forced_payment_status; }
            $case_status = $completed ? 'completed' : ($closed || $status === 'rejected' ? ($paid > 0.5 ? 'deposit_paid' : 'payment_rejected') : ($status === 'paid' ? 'deposit_paid' : 'payment_link_sent'));
            $now = current_time('mysql');
            $case_data = ['paid_amount'=>$paid,'remaining_amount'=>$remaining,'status'=>$case_status,'completed_at'=>$completed ? (($case->completed_at ?? '') ?: $now) : null,'updated_at'=>$now];
            if (!$closed && $paid > 0.5) { $case_data += ['operational_archived_at'=>null,'operational_archive_reason'=>null]; }
            if ((float)($case->deposit_amount ?? 0) <= 0 && $paid > 0.5) { $case_data['deposit_amount'] = $paid; }
            if (false === $wpdb->update($t['payments'], ['status'=>$status,'requested_amount'=>(float)$stage->requested_amount,'paid_at'=>$status === 'paid' ? (($stage->paid_at ?? '') ?: $now) : null,'updated_at'=>$now], ['id'=>(int)$payment->id])
                || false === $wpdb->update($t['cases'], $case_data, ['id'=>(int)$case->id])) { throw new RuntimeException('Staged conversion projection'); }
            if ($owns_transaction && false === $wpdb->query('COMMIT')) { throw new RuntimeException('Staged conversion commit'); }
            return ['success'=>true,'handled'=>true];
        } catch (Throwable $error) {
            if ($owns_transaction) { $wpdb->query('ROLLBACK'); }
            return ['success'=>false,'handled'=>true,'message'=>'همگام‌سازی دفتر مراحل این پرونده کامل نشد.'];
        }
    }

    /** Return null for legacy cases, whose physical invoice/token history is retained. */
    private function continue_staged_conversion_payment(object $case, string $type, float $amount, int $actor_id): ?array
    {
        global $wpdb; $t = $this->tables(); $link = $this->staged_conversion_link((int)$case->id);
        if (!$link) { return null; }
        $invoice = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_invoices WHERE id=%d", (int)$link->invoice_id));
        if (!$invoice || !$this->can_manage_converter_case($case, $actor_id) || !$this->same_conversion_invoice_customer($invoice, $case)) { return ['error'=>'دسترسی به فاکتور این پرونده مجاز نیست.']; }
        if (in_array((string)$invoice->status, ['cancelled','payment_archived'], true)) { return ['error'=>'وضعیت فاکتور لغوشده یا بایگانی‌شده باید ابتدا توسط مالی تعیین تکلیف شود.']; }
        // A rejected request can reuse its existing link and receipt-correction flow.
        if ((string)$invoice->status === 'rejected' && abs((float)$invoice->current_due_amount - $amount) <= 0.005) {
            $payment = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['payments']} WHERE invoice_id=%d", (int)$invoice->id));
            return $payment ? ['invoice'=>$invoice,'payment_id'=>(int)$payment->id,'payment_type'=>$type,'amount'=>$amount,'case'=>$case,'reused'=>true] : ['error'=>'سابقه مرحله پیدا نشد.'];
        }
        $mode = $type === 'balance' ? 'remaining' : 'partial';
        $result = sn_bootstrap_plugin_instance()->issue_dot_conversion_payment_stage((int)$case->id, (int)$invoice->id, (int)$invoice->current_payment_stage, $actor_id, $mode, $amount);
        if (empty($result['success'])) { return ['error'=>(string)($result['message'] ?? 'صدور مرحله انجام نشد.')]; }
        $payment = $wpdb->get_row($wpdb->prepare("SELECT id FROM {$t['payments']} WHERE invoice_id=%d", (int)$invoice->id));
        return ['invoice'=>$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_invoices WHERE id=%d", (int)$invoice->id)),'payment_id'=>(int)$payment->id,'payment_type'=>$type,'amount'=>$amount,'case'=>$this->get_case((int)$case->id)];
    }

    private function staged_conversion_public_context(object $link): array
    {
        global $wpdb; $invoice = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_invoices WHERE id=%d", (int)$link->invoice_id));
        $case = $this->get_case((int)$link->case_id);
        if (!$invoice || !$case || !$this->same_conversion_invoice_customer($invoice, $case)) { return []; }
        $total = max(0, (float)$invoice->payment_total_amount); $paid = min($total, max(0, (float)$invoice->paid_total_amount));
        $stages = $wpdb->get_results($wpdb->prepare("SELECT stage_no,stage_type,requested_amount,status,pay_method,payment_source,created_at,updated_at,paid_at FROM {$wpdb->prefix}sn_invoice_payment_stages WHERE invoice_id=%d ORDER BY stage_no", (int)$invoice->id), ARRAY_A) ?: [];
        foreach ($stages as &$stage) { $stage['stage_type_label'] = ['partial'=>'پرداخت مرحله‌ای','remaining'=>'تسویه مانده','full'=>'پرداخت کامل'][$stage['stage_type']] ?? ''; $stage['status_label'] = SN_Helpers::status_label((string)$stage['status']); $stage['display_date_jalali'] = $this->jalali_datetime((string)(($stage['paid_at'] ?? '') ?: $stage['updated_at'])); }
        unset($stage);
        return ['is_dot_conversion_payment'=>true,'case_id'=>(int)$case->id,'option_title'=>(string)$case->selected_option_title,'payment_type'=>(string)$case->payment_mode,'payment_type_label'=>$this->payment_type_label((string)$case->payment_mode),'payment_plan'=>(string)$invoice->payment_plan,'payment_total_amount'=>$total,'paid_total_amount'=>$paid,'remaining_amount'=>max(0,$total-$paid),'progress_percent'=>$total > 0 ? (int)round($paid/$total*100) : 0,'is_completed'=>$total > 0 && $total-$paid <= 0.5,'current_payment_stage'=>(int)$invoice->current_payment_stage,'payment_stages'=>$stages];
    }
}
