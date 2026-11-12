<?php
if (!defined('ABSPATH')) { exit; }

final class SN_Conversion_Cash_Credit {
    private const RETRY_HOOK = 'sn_conversion_cash_settle';
    private static ?self $instance = null;

    public static function instance(): self {
        return self::$instance ?? (self::$instance = new self());
    }

    public function register_hooks(): void {
        add_action(self::RETRY_HOOK, [$this, 'settle'], 10, 2);
    }

    private function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'sn_subscription_plus_sales';
    }

    private function request(int $case_id): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . $this->table() . ' WHERE cash_conversion_case_id=%d',
            $case_id
        )) ?: null;
    }

    private function invoice(int $id): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_invoices WHERE id=%d", $id)) ?: null;
    }

    private function paid(?object $invoice, float $expected): bool {
        return $invoice && in_array((string) $invoice->status, ['paid', 'approved'], true)
            && ($invoice->payment_workflow_status ?? '') === 'completed'
            && $expected > 0 && abs((float) ($invoice->payment_total_amount ?? 0) - $expected) < .005
            && abs((float) ($invoice->remaining_amount ?? 1)) <= .5
            && (float) ($invoice->paid_total_amount ?? 0) + .005 >= $expected;
    }

    public function completed_case(int $case_id, int $product_id): bool {
        global $wpdb;
        $case = $wpdb->get_row($wpdb->prepare(
            "SELECT status, operational_archived_at, selected_option_product_id, selected_option_price, paid_amount, remaining_amount
             FROM {$wpdb->prefix}sn_dot_cases WHERE id=%d",
            $case_id
        ));
        if ($wpdb->last_error) { throw new RuntimeException('conversion_case_read'); }
        if (!$case || $case->status !== 'completed' || !empty($case->operational_archived_at)
            || (int) $case->selected_option_product_id !== $product_id
            || (float) $case->selected_option_price <= 0 || (float) $case->remaining_amount > .5
            || (float) $case->paid_amount + .5 < (float) $case->selected_option_price) {
            return false;
        }
        $paid = $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(d.requested_amount) FROM {$wpdb->prefix}sn_dot_payments d
             INNER JOIN {$wpdb->prefix}sn_invoices i ON i.id=d.invoice_id
             WHERE d.case_id=%d AND d.status='paid' AND i.status IN ('paid','approved')
             AND i.payment_workflow_status='completed' AND ABS(i.remaining_amount)<=0.5
             AND ABS(i.payment_total_amount-d.requested_amount)<0.005 AND i.paid_total_amount+0.005>=d.requested_amount",
            $case_id
        ));
        if ($wpdb->last_error) { throw new RuntimeException('conversion_payment_read'); }
        return $paid !== null && (float) $paid + .5 >= (float) $case->selected_option_price;
    }

    public function entitlement_active(object $sale): bool {
        $member = $this->membership((int) $sale->cash_conversion_case_id, (int) $sale->product_id);
        if ($member && !empty($sale->membership_id) && (int) $sale->membership_id !== (int) $member->id) { return false; }
        return $member && (bool) $this->verified_parent($member);
    }

    private function verified_parent(object $member): ?object {
        global $wpdb;
        $case_id = (int) $member->dot_case_id;
        if (!$this->completed_case($case_id, (int) $member->subscription_product_id)) { return null; }
        // The original reference remains in the sale snapshot. Finance can
        // legitimately replace that invoice when it restores this membership.
        $parent = $wpdb->get_row($wpdb->prepare(
            "SELECT i.* FROM {$wpdb->prefix}sn_invoices i
             INNER JOIN {$wpdb->prefix}sn_dot_invoice_links l ON l.invoice_id=i.id
             WHERE i.id=%d AND l.case_id=%d AND l.flow_kind='conversion_payment'",
            (int) $member->source_invoice_id, $case_id
        ));
        if ($wpdb->last_error) { throw new RuntimeException('conversion_parent_read'); }
        return $this->paid($parent, (float) ($parent->payment_total_amount ?? 0)) ? $parent : null;
    }

    public function resume_for_case(int $case_id): void {
        if (!SN_Subscription_Plus::instance()->cash_schema_ready()) { return; }
        $request = $this->request($case_id);
        if ($request && $request->cash_profile_sms_state === '' && empty($request->cash_profile_sms_sent_at)) {
            $this->schedule_retry((int) $request->id, 0);
        }
    }

    public function profile_credit(int $membership_id): float {
        if (!SN_Subscription_Plus::instance()->cash_schema_ready()) { return 0; }
        global $wpdb;
        $sale = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . $this->table() . " WHERE membership_id=%d AND cash_conversion_case_id IS NOT NULL AND status='active'",
            $membership_id
        ));
        if (!$sale || !$this->paid($this->invoice((int) $sale->source_invoice_id), (float) $sale->cash_price)) { return 0; }
        $snapshot = json_decode($sale->snapshot_json, true);
        try {
            return is_array($snapshot) && $this->entitlement_active($sale)
                ? (float) ($snapshot['cash_option']['credit'] ?? 0) : 0;
        } catch (RuntimeException $error) {
            return 0;
        }
    }

    public function payment_allowed(int $invoice_id, int $case_id = 0): bool {
        global $wpdb;
        if (!SN_Subscription_Plus::instance()->cash_schema_ready()) { return false; }
        $sale = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . $this->table() . " WHERE source_invoice_id=%d AND cash_conversion_case_id IS NOT NULL AND status<>'notification_only'",
            $invoice_id
        ));
        try {
            return $sale && (!$case_id || (int) $sale->cash_conversion_case_id === $case_id) && $this->entitlement_active($sale);
        } catch (RuntimeException $error) {
            return false;
        }
    }

    public function payment_sms_available(object $invoice): bool {
        return $invoice->payment_workflow_status === 'awaiting_payment'
            && in_array($invoice->status, ['pre_invoice','pending','pending_payment','rejected'], true)
            && (float)$invoice->current_due_amount > 0.5;
    }

    private function membership(int $case_id, int $product_id): ?object {
        global $wpdb;
        $key = 'dot:' . $case_id . ':' . $product_id;
        $member = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}sn_project_memberships WHERE source_key=%s AND status='active'",
            $key
        ));
        if ($wpdb->last_error) { throw new RuntimeException('conversion_membership_read'); }
        return $member ?: null;
    }

    private function product_enabled(int $product_id): bool {
        return get_post_meta($product_id, '_sn_conversion_cash_enabled', true) === '1';
    }

    public function staged_payment_badges(array $case_ids): array {
        if (!$case_ids || !SN_Subscription_Plus::instance()->cash_schema_ready()) { return []; }
        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($case_ids), '%d'));
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT s.cash_conversion_case_id, i.status, i.payment_workflow_status, i.paid_total_amount
             FROM ' . $this->table() . " s INNER JOIN {$wpdb->prefix}sn_invoices i ON i.id=s.source_invoice_id
             WHERE s.cash_conversion_case_id IN ({$placeholders}) AND s.status<>'notification_only'
             AND i.payment_plan='partial' AND i.status NOT IN ('cancelled','payment_archived')",
            ...$case_ids
        )) ?: [];
        $badges = [];
        foreach ($rows as $row) {
            $case_id = (int) $row->cash_conversion_case_id;
            if ($row->payment_workflow_status === 'completed') {
                $badges[$case_id] = 'اعتبار نقدی مرحله‌ای تسویه شد';
            } elseif (in_array($row->status, ['pending_financial_approval', 'receipt_uploaded', 'card_submitted'], true)) {
                $badges[$case_id] = 'واریز مرحله‌ای اعتبار در بررسی مالی';
            } elseif ((float) $row->paid_total_amount > 0.5) {
                $badges[$case_id] = 'پیش‌پرداخت اعتبار نقدی تأیید شد';
            }
        }
        return $badges;
    }

    private function render_payment_fields(bool $next_stage = false): void {
        ?>
        <div data-sn-cash-payment-fields>
            <label>روش پرداخت
                <select name="cash_payment_mode">
                    <option value="<?php echo $next_stage ? 'remaining' : 'full'; ?>"><?php echo $next_stage ? 'تسویه کامل مانده' : 'پرداخت کامل'; ?></option>
                    <option value="partial">پرداخت مرحله‌ای</option>
                </select>
            </label>
            <label data-sn-cash-stage-amount><?php echo $next_stage ? 'مبلغ این مرحله (تومان)' : 'مبلغ پیش‌پرداخت (تومان)'; ?>
                <input name="cash_stage_amount" type="text" inputmode="decimal" autocomplete="off">
                <small>این مبلغ برای پرداخت مرحله‌ای استفاده می‌شود.</small>
            </label>
        </div>
        <?php
    }

    public function render(object $case, callable $invoice_url, int $support_case_id = 0): void {
        if ($case->status !== 'completed' || !empty($case->operational_archived_at)
            || !SN_Subscription_Plus::instance()->cash_schema_ready()) { return; }
        $case_id = (int) $case->id;
        $product_id = (int) $case->selected_option_product_id;
        if (!$product_id || !in_array(SN_Seller_Flow::instance()->product_type($product_id), ['subscription', 'subscription_plus'], true)) { return; }
        try {
            if (!$this->membership($case_id, $product_id)) { return; }
        } catch (RuntimeException $error) {
            return;
        }
        $request = $this->request($case_id);
        $enabled = $this->product_enabled($product_id);
        if (!$request && !$enabled) { return; }
        $invoice = $request && $request->status !== 'notification_only' ? $this->invoice((int) $request->source_invoice_id) : null;
        $closed = $invoice && in_array($invoice->status, ['cancelled', 'payment_archived'], true);
        $can_issue = $enabled && (!$request || ($closed && empty($request->membership_id)));
        $staged = $invoice && $invoice->payment_plan === 'partial';
        $can_issue_next = $staged && !$closed && $invoice->payment_workflow_status === 'awaiting_assignment'
            && (float) $invoice->paid_total_amount > 0.5 && (float) $invoice->remaining_amount > 0.5;
        $options = $can_issue && SN_Dot_Flow::instance()->conversion_product_allowed($case, $product_id, get_current_user_id())
            ? SN_Subscription_Plus::instance()->options($product_id) : [];
        $sms_uncertain = $request && in_array($request->cash_profile_sms_state, ['sending', 'uncertain'], true)
            && empty($request->cash_profile_sms_sent_at);
        $can_retry = $request && !$sms_uncertain && empty($request->cash_profile_sms_sent_at)
            && ($request->status === 'notification_only' || $this->paid($invoice, (float) $request->cash_price));
        ?>
        <section class="sn-converter-simple-step">
            <div class="sn-converter-simple-step-head"><span>۳</span><div><strong>صدور پیش‌فاکتور اعتبار نقدی</strong><small>مربوط به فاکتور تسویه‌شده اشتراک</small></div></div>
            <?php if ($request && $request->status === 'notification_only') : ?>
                <p><?php echo $request->cash_profile_sms_sent_at ? 'پیامک پروفایل ارسال شد.' : ($sms_uncertain ? 'انتخاب «بدون اعتبار نقدی» ثبت شد.' : 'انتخاب «بدون اعتبار نقدی» ثبت شد؛ پیامک در انتظار ارسال است.'); ?></p>
            <?php elseif ($invoice) : ?>
                <p>پیش‌فاکتور اعتبار <bdi><?php echo esc_html($invoice->invoice_code); ?></bdi>
                    — <?php echo esc_html(SN_Helpers::format_price((float) $request->cash_price)); ?> تومان
                    <?php if (!$closed) : ?>
                        <a href="<?php echo esc_url($invoice_url($invoice)); ?>" target="_blank" rel="noopener">مشاهده لینک فاکتور</a>
                    <?php endif; ?>
                </p>
                <?php if ($this->payment_sms_available($invoice)) : ?>
                    <form class="sn-dot-action-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php echo SN_Support_Console::case_action_field('sn_dot_conversion_cash', $support_case_id); ?>
                        <input type="hidden" name="case_id" value="<?php echo esc_attr((string)$case_id); ?>">
                        <input type="hidden" name="cash_option" value="resend_payment_sms">
                        <input type="hidden" name="cash_invoice_id" value="<?php echo esc_attr((string)$invoice->id); ?>">
                        <input type="hidden" name="cash_stage_no" value="<?php echo esc_attr((string)$invoice->current_payment_stage); ?>">
                        <?php wp_nonce_field('sn_dot_conversion_cash_' . $case_id, '_wpnonce', false); ?>
                        <button type="submit" class="sn-btn sn-btn-secondary">ارسال مجدد پیامک لینک پرداخت اعتبار نقدی</button>
                    </form>
                <?php endif; ?>
                <?php if ($staged) : ?>
                    <p>مرحله <?php echo esc_html(number_format_i18n((int) $invoice->current_payment_stage)); ?>
                        · تأییدشده <?php echo esc_html(SN_Helpers::format_price((float) $invoice->paid_total_amount)); ?> تومان
                        · مانده <?php echo esc_html(SN_Helpers::format_price((float) $invoice->remaining_amount)); ?> تومان
                        <?php if (!$closed && (float) $invoice->current_due_amount > 0.5) : ?>
                            · مبلغ مرحله جاری <?php echo esc_html(SN_Helpers::format_price((float) $invoice->current_due_amount)); ?> تومان
                        <?php endif; ?>
                    </p>
                <?php endif; ?>
                <?php if ($request->status === 'active') : ?>
                    <p><?php echo $request->cash_profile_sms_sent_at ? 'اعتبار ثبت و پیامک پروفایل ارسال شد.' : ($sms_uncertain ? 'اعتبار ثبت شد.' : 'اعتبار ثبت شد؛ پیامک پروفایل در انتظار ارسال است.'); ?></p>
                <?php endif; ?>
            <?php endif; ?>
            <?php if ($sms_uncertain) : ?>
                <p>نتیجه ارسال پیامک مشخص نیست. وضعیت تحویل را در پنل سرویس پیامک بررسی کنید.</p>
            <?php endif; ?>
            <?php if ($can_issue) : ?>
                <form class="sn-dot-action-form" data-sn-cash-stage-form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php echo SN_Support_Console::case_action_field('sn_dot_conversion_cash', $support_case_id); ?>
                    <input type="hidden" name="case_id" value="<?php echo esc_attr((string) $case_id); ?>">
                    <?php wp_nonce_field('sn_dot_conversion_cash_' . $case_id, '_wpnonce', false); ?>
                    <input type="hidden" name="cash_revision" value="<?php echo esc_attr(hash('sha256', wp_json_encode($options))); ?>">
                    <label>اعتبار نقدی
                        <select name="cash_option" required>
                            <option value="">انتخاب اعتبار</option>
                            <option value="none">بدون اعتبار نقدی — فقط ارسال پیامک پروفایل</option>
                            <?php foreach ($options as $option) : ?>
                                <option value="<?php echo esc_attr($option['id']); ?>"><?php echo esc_html($option['title'] . ' — اعتبار ' . number_format_i18n($option['credit']) . ' تومان، قیمت ' . number_format_i18n($option['price']) . ' تومان'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <?php if ($options) { $this->render_payment_fields(); } ?>
                    <button type="submit" class="sn-btn sn-btn-primary">ثبت انتخاب</button>
                </form>
            <?php elseif ($can_issue_next) : ?>
                <form class="sn-dot-action-form" data-sn-cash-stage-form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php echo SN_Support_Console::case_action_field('sn_dot_conversion_cash', $support_case_id); ?>
                    <input type="hidden" name="case_id" value="<?php echo esc_attr((string) $case_id); ?>">
                    <input type="hidden" name="cash_option" value="next_stage">
                    <input type="hidden" name="cash_invoice_id" value="<?php echo esc_attr((string) $invoice->id); ?>">
                    <input type="hidden" name="cash_stage_no" value="<?php echo esc_attr((string) $invoice->current_payment_stage); ?>">
                    <?php wp_nonce_field('sn_dot_conversion_cash_' . $case_id, '_wpnonce', false); ?>
                    <?php $this->render_payment_fields(true); ?>
                    <button type="submit" class="sn-btn sn-btn-primary">صدور لینک مرحله بعد اعتبار</button>
                </form>
            <?php elseif ($can_retry || ($sms_uncertain && current_user_can('manage_options'))) : ?>
                <form class="sn-dot-action-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php echo SN_Support_Console::case_action_field('sn_dot_conversion_cash', $support_case_id); ?>
                    <input type="hidden" name="case_id" value="<?php echo esc_attr((string) $case_id); ?>">
                    <input type="hidden" name="cash_option" value="retry">
                    <?php wp_nonce_field('sn_dot_conversion_cash_' . $case_id, '_wpnonce', false); ?>
                    <?php if ($sms_uncertain) : ?>
                        <label><input type="checkbox" name="confirm_sms_not_delivered" value="1" required> در پنل سرویس بررسی کردم و پیامک قبلی ارسال نشده است.</label>
                    <?php endif; ?>
                    <button type="submit" class="sn-btn sn-btn-secondary">تلاش دوباره برای ارسال پیامک</button>
                </form>
            <?php endif; ?>
        </section>
        <?php
    }

    /**
     * The caller supplies invoice identifiers generated by the standard Dot Flow
     * routines. Case access has already been checked by that same panel.
     */
    public function issue(object $case, string $choice, string $revision, int $actor_id, callable $identifiers, bool $confirmed_retry = false, string $payment_mode = 'full', float $deposit = 0.0) {
        global $wpdb;
        $plus = SN_Subscription_Plus::instance();
        $case_id = (int) $case->id;
        $product_id = (int) $case->selected_option_product_id;
        if (!$product_id || !in_array(SN_Seller_Flow::instance()->product_type($product_id), ['subscription', 'subscription_plus'], true)) {
            return new WP_Error('sn_cash_product', 'این قابلیت برای اشتراک تبدیل‌شده در دسترس است.');
        }
        if (!$plus->cash_schema_ready() || !$plus->transactional_tables([
            'sn_dot_cases', 'sn_dot_payments', 'sn_dot_invoice_links', 'sn_project_memberships', 'sn_invoices',
            'sn_invoice_items', 'sn_invoice_payment_stages', 'sn_payments', 'sn_payment_review_events',
        ])) {
            return new WP_Error('sn_cash_schema', 'جداول لازم برای ثبت امن اعتبار آماده نیست.');
        }
        $option = null;
        $stage_amount = 0.0;
        if ($choice !== 'none' && $choice !== 'retry') {
            $options = $plus->options($product_id);
            if (!hash_equals(hash('sha256', wp_json_encode($options)), $revision)) {
                return new WP_Error('sn_cash_stale', 'فهرست اعتبار تغییر کرده است؛ صفحه را تازه کنید و دوباره انتخاب کنید.');
            }
            foreach ($options as $candidate) {
                if ($candidate['id'] === $choice && (float) $candidate['price'] > .5) { $option = $candidate; break; }
            }
            if (!$option) { return new WP_Error('sn_cash_choice', 'گزینه اعتبار را از فهرست فعلی انتخاب کنید.'); }
            if (!in_array($payment_mode, ['full', 'partial'], true)) {
                return new WP_Error('sn_cash_payment_mode', 'روش پرداخت اعتبار نقدی معتبر نیست.');
            }
            $price = (float) $option['price'];
            $stage_amount = $payment_mode === 'partial' ? round($deposit, 2) : $price;
            if ($payment_mode === 'partial' && (!is_finite($stage_amount) || $stage_amount <= 0.5 || $stage_amount >= $price - 0.5)) {
                return new WP_Error('sn_cash_deposit', 'مبلغ پیش‌پرداخت باید بیشتر از صفر و کمتر از قیمت فروش اعتبار باشد.');
            }
        }
        // A unique case key and the row lock prevent a second simultaneous order.
        if ($wpdb->query('START TRANSACTION') === false) {
            return new WP_Error('sn_cash_start', 'آغاز ثبت اعتبار انجام نشد.');
        }
        try {
            $locked = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_dot_cases WHERE id=%d FOR UPDATE", $case_id));
            if (!$locked || (int) $locked->converter_id !== (int) $case->converter_id || $locked->status !== 'completed'
                || (int) $locked->selected_option_product_id !== $product_id || !empty($locked->operational_archived_at)) {
                throw new DomainException('ابتدا تسویه و تأیید مالی تبدیل را تکمیل کنید.');
            }
            $member = $this->membership($case_id, $product_id);
            $parent = $member ? $this->verified_parent($member) : null;
            if (!$parent) {
                throw new DomainException('فاکتور تأییدشده و عضویت فعال پیدا نشد.');
            }
            $request = $this->request($case_id);
            if ($wpdb->last_error) { throw new RuntimeException('request_read'); }
            if ($choice === 'retry') {
                if (!$request || ($request->status !== 'notification_only'
                    && !$this->paid($this->invoice((int) $request->source_invoice_id), (float) $request->cash_price))) {
                    throw new DomainException('درخواست آماده ارسال پیامک نیست.');
                }
                if (in_array($request->cash_profile_sms_state, ['sending', 'uncertain'], true)
                    && empty($request->cash_profile_sms_sent_at) && (!$confirmed_retry || !current_user_can('manage_options'))) {
                    throw new DomainException('نتیجه ارسال قبلی مشخص نیست؛ وضعیت تحویل را در پنل پیامک بررسی کنید.');
                }
                $sale_id = (int) $request->id;
            } else {
                if (!$this->product_enabled($product_id)) {
                    throw new DomainException('طرح اعتبار نقدی پس از تبدیل برای این اشتراک فعال نیست.');
                }
                if ($option && !SN_Dot_Flow::instance()->conversion_product_allowed($locked, $product_id, $actor_id)) {
                    throw new DomainException('این اشتراک در فهرست محصولات مجاز این پرونده نیست.');
                }
                if ($request) {
                    $previous = $this->invoice((int) $request->source_invoice_id);
                    if (!$previous || !in_array($previous->status, ['cancelled', 'payment_archived'], true) || !empty($request->membership_id)) {
                        throw new DomainException('انتخاب اعتبار برای این تبدیل قبلاً ثبت شده است.');
                    }
                }
                $now = current_time('mysql');
                $invoice_id = $option ? $this->create_invoice($parent, $locked, $option, $actor_id, $identifiers(), $now, $payment_mode, $stage_amount) : (int) $parent->id;
                $snapshot = [
                    'cash_option' => $option,
                    'installment_credit' => 0,
                    'conversion_parent_invoice_id' => (int) $parent->id,
                ];
                $values = [
                    'source_invoice_id' => $invoice_id,
                    'product_id' => $product_id,
                    'snapshot_json' => wp_json_encode($snapshot, JSON_UNESCAPED_UNICODE),
                    'cash_price' => (float) ($option['price'] ?? 0),
                    'cash_conversion_case_id' => $case_id,
                    'status' => $option ? 'awaiting_payment' : 'notification_only',
                    'updated_at' => $now,
                ];
                if ($request) {
                    $this->must($wpdb->update($this->table(), $values, ['id' => (int) $request->id]));
                    $sale_id = (int) $request->id;
                } else {
                    $this->must($wpdb->insert($this->table(), $values + ['created_at' => $now]));
                    $sale_id = (int) $wpdb->insert_id;
                }
                if ($option && !SN_Purpose_Commission::instance()->snapshot_sale($invoice_id)) {
                    throw new RuntimeException('commission_snapshot');
                }
            }
            $this->must($wpdb->query('COMMIT'));
        } catch (Throwable $error) {
            $wpdb->query('ROLLBACK');
            if ($error instanceof DomainException) { return new WP_Error('sn_cash_case', $error->getMessage()); }
            return new WP_Error('sn_cash_write', 'ثبت درخواست اعتبار انجام نشد؛ وضعیت را دوباره بررسی کنید.');
        }
        if ($option) {
            $sms = SN_Dot_Flow::instance()->send_conversion_cash_payment_sms($case, $invoice_id, 1);
            return 'پیش‌فاکتور اعتبار نقدی صادر شد. ' . (is_wp_error($sms) ? $sms->get_error_message() : $sms);
        }
        return $this->settle($sale_id, 0, $choice === 'retry' && $confirmed_retry) ? 'پیامک لینک پروفایل ارسال شد.' : 'انتخاب ثبت شده و پیامک هنوز ارسال نشده است.';
    }

    private function must($result): void {
        if ($result === false) { throw new RuntimeException('cash_write'); }
    }

    private function create_invoice(object $parent, object $case, array $option, int $actor_id, array $ids, string $now, string $payment_mode, float $stage_amount): int {
        global $wpdb;
        $amount = (float) $option['price'];
        $data = array_intersect_key((array) $parent, array_flip([
            'customer_wp_id', 'customer_name', 'customer_phone', 'customer_phone_secondary',
            'customer_address', 'customer_postal_code', 'province', 'city', 'payment_card_number',
            'payment_card_owner', 'payment_card_sales_manager_user_id', 'payment_card_deputy_user_id',
        ]));
        if (property_exists($parent, 'original_seller_id')) {
            $data['original_seller_id'] = (int) $case->seller_id;
        }
        $this->must($wpdb->insert($wpdb->prefix . 'sn_invoices', $data + $ids + [
            'seller_id' => (int) $case->seller_id,
            'product_id' => 0,
            'product_price' => $amount,
            'original_total' => $amount,
            'final_total' => $amount,
            'payment_plan' => $payment_mode,
            'payment_total_amount' => $amount,
            'current_due_amount' => $stage_amount,
            'paid_total_amount' => 0,
            'remaining_amount' => $amount,
            'current_payment_stage' => 1,
            'payment_workflow_status' => 'awaiting_payment',
            'current_stage_issued_by_user_id' => $actor_id,
            'issued_by_user_id' => $actor_id,
            'payment_purpose' => 'subscription_plus_cash',
            'status' => 'pre_invoice',
            'invoice_status' => 'pre_invoice',
            'payment_status' => 'pre_invoice',
            'created_at' => $now,
            'updated_at' => $now,
        ]));
        $id = (int) $wpdb->insert_id;
        $this->must($wpdb->insert($wpdb->prefix . 'sn_invoice_items', [
            'invoice_id' => $id, 'product_id' => 0, 'product_name' => 'اعتبار نقدی: ' . $option['title'],
            'product_type' => 'subscription_plus_cash', 'qty' => 1, 'unit_price' => $amount,
            'total_price' => $amount, 'is_free' => 0, 'created_at' => $now,
        ]));
        $this->must($wpdb->insert($wpdb->prefix . 'sn_invoice_payment_stages', [
            'invoice_id' => $id, 'stage_no' => 1, 'stage_type' => $payment_mode, 'requested_amount' => $stage_amount,
            'status' => 'pending', 'issued_by_user_id' => $actor_id, 'note' => 'اعتبار نقدی بعد از تبدیل',
            'created_at' => $now, 'updated_at' => $now,
        ]));
        $this->must($wpdb->insert($wpdb->prefix . 'sn_dot_invoice_links', [
            'invoice_id' => $id, 'case_id' => (int) $case->id, 'flow_kind' => 'conversion_cash',
            'payment_kind' => 'full', 'supervisor_id' => (int) $case->supervisor_id,
            'requires_finance' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]));
        return $id;
    }

    public function settle(int $sale_id, int $attempt = 0, bool $confirmed_retry = false): bool {
        global $wpdb;
        if (!SN_Subscription_Plus::instance()->cash_schema_ready()) { return false; }
        $lock = 'sn_cash_sms_' . md5($wpdb->prefix . ':' . $sale_id);
        if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)', $lock)) !== 1) {
            $this->schedule_retry($sale_id, $attempt);
            return false;
        }
        try {
            $sale = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $this->table() . ' WHERE id=%d', $sale_id));
            if (!$sale || !$sale->cash_conversion_case_id) { return false; }
            $case_id = (int) $sale->cash_conversion_case_id;
            $product_id = (int) $sale->product_id;
            $notify_only = $sale->status === 'notification_only';
            $snapshot = json_decode($sale->snapshot_json, true);
            $member = $this->membership($case_id, $product_id);
            $parent = $member ? $this->verified_parent($member) : null;
            if (!$parent || !is_array($snapshot) || (!empty($sale->membership_id) && (int) $sale->membership_id !== (int) $member->id)) {
                $this->schedule_retry($sale_id, $attempt);
                return false;
            }
            if (!$notify_only) {
                $invoice = $this->invoice((int) $sale->source_invoice_id);
                if (!$this->paid($invoice, (float) $sale->cash_price)) { return false; }
                $option = $snapshot['cash_option'] ?? null;
                if (!is_array($option) || !isset($option['credit'], $option['id'], $option['title'])
                    || SN_Helpers::normalize_mobile($invoice->customer_phone) !== SN_Helpers::normalize_mobile($parent->customer_phone)) {
                    throw new RuntimeException('invalid_credit_snapshot');
                }
                $payload = [
                    'customer' => ['user_id' => (int) ($invoice->customer_wp_id ?? 0), 'name' => $invoice->customer_name,
                        'mobile' => SN_Helpers::normalize_mobile($invoice->customer_phone)],
                    'installment_wallet' => ['credit' => 0, 'currency' => 'IRT'],
                    'cash_wallet' => ['credit' => (float) $option['credit'], 'option_id' => $option['id'],
                        'option_title' => $option['title'], 'paid_price' => (float) $sale->cash_price, 'currency' => 'IRT'],
                ];
                if ($sale->status !== 'active' || !(int) $sale->membership_id) {
                    $this->must($wpdb->update($this->table(), [
                        'status' => 'active', 'membership_id' => (int) $member->id,
                        'api_payload_json' => wp_json_encode($payload, JSON_UNESCAPED_UNICODE),
                        'api_sync_status' => 'ready', 'updated_at' => current_time('mysql'),
                    ], ['id' => $sale_id]));
                }
            }
            if (!empty($sale->cash_profile_sms_sent_at)) { return true; }
            $sms_state = (string) $sale->cash_profile_sms_state;
            if ($sms_state !== '' && (!$confirmed_retry || !current_user_can('manage_options'))) { return false; }
            // Persist the attempt before the billable call. A crash, lost
            // response, or failed acknowledgement must never send it again.
            if ($sms_state !== 'sending') {
                $claimed = $wpdb->update($this->table(), ['cash_profile_sms_state' => 'sending'], [
                    'id' => $sale_id, 'cash_profile_sms_state' => $sms_state, 'cash_profile_sms_sent_at' => null,
                ]);
                $this->must($claimed);
                if ($claimed !== 1) { return false; }
            }
            $delivery = SN_Customer_Portal::instance()->send_cash_profile_link($member, $parent);
            $values = ['cash_profile_sms_state' => ''];
            if ($delivery === 'sent') { $values['cash_profile_sms_sent_at'] = current_time('mysql'); }
            if ($delivery === 'uncertain') { $values['cash_profile_sms_state'] = 'uncertain'; }
            $this->must($wpdb->update($this->table(), $values, ['id' => $sale_id, 'cash_profile_sms_state' => 'sending']));
            if ($delivery !== 'retry') { return $delivery === 'sent'; }
        } catch (Throwable $error) {
            error_log('SN conversion cash: settlement or notification could not be recorded for request #' . $sale_id);
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
        $this->schedule_retry($sale_id, $attempt);
        return false;
    }

    private function schedule_retry(int $sale_id, int $attempt): void {
        if ($attempt < 5 && !wp_next_scheduled(self::RETRY_HOOK, [$sale_id, $attempt + 1])) {
            wp_schedule_single_event(time() + 300 * ($attempt + 1), self::RETRY_HOOK, [$sale_id, $attempt + 1]);
        }
    }
}
