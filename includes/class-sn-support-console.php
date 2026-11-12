<?php
if (!defined('ABSPATH')) { exit; }

final class SN_Support_Console {
    private const CONFIG = 'sn_support_console';
    private const AJAX = 'sn_support_action';
    private const SEARCH_TYPES = [
        'all' => 'همهٔ موارد',
        'invoice' => 'فاکتور',
        'order' => 'سفارش ووکامرس',
        'case' => 'پروندهٔ تبدیل',
    ];
    private const RESULT_LIMIT = 20;
    private const CASE_ACTIONS = [
        'sn_dot_assign_converter' => 'handle_assign_converter',
        'sn_dot_converter_update' => 'handle_converter_update',
        'sn_dot_create_payment' => 'handle_create_payment',
        'sn_dot_resend_payment' => 'handle_resend_payment',
        'sn_dot_conversion_cash' => 'handle_conversion_cash',
    ];

    public static function register(): void {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_init', [self::class, 'route']);
        add_action('wp_ajax_' . self::AJAX, [self::class, 'dispatch']);
        add_action('admin_post_sn_support_case', [self::class, 'case_action']);
    }

    private static function config(): array { return (array) get_option(self::CONFIG, []); }
    private static function text(array $data, string $key): string {
        return is_string($data[$key] ?? null) ? wp_unslash($data[$key]) : '';
    }
    private static function secure_transport(): bool {
        return is_ssl() || (
            in_array(wp_parse_url(admin_url(), PHP_URL_HOST), ['127.0.0.1','localhost','[::1]'], true)
            && in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1','::1'], true)
        );
    }
    private static function administrator(): bool {
        return is_user_logged_in() && current_user_can('manage_options') && self::secure_transport();
    }
    private static function session_token(): string {
        // WordPress can authenticate wp-admin without the front-end logged_in cookie.
        foreach ([is_ssl() ? 'secure_auth' : 'auth', 'logged_in'] as $scheme) {
            $cookie = wp_parse_auth_cookie('', $scheme);
            if ($cookie && wp_validate_auth_cookie('', $scheme) === get_current_user_id()) {
                return $cookie['token'];
            }
        }
        return '';
    }
    private static function grant_key(array $config, string $token): string {
        return 'sn_sup_' . hash('sha256', get_current_user_id() . '|' . $token . '|' . ($config['route'] ?? ''));
    }
    public static function unlocked(): bool {
        if (!self::administrator()) { return false; }
        $token = self::session_token();
        $config = self::config();
        return $token !== '' && !empty($config['hash']) && !empty($config['route'])
            && (int)get_transient(self::grant_key($config, $token)) > time();
    }
    private static function url(array $args = []): string {
        return add_query_arg(['sn_support' => self::config()['route'] ?? ''] + $args, admin_url('admin.php'));
    }
    private static function lookup(): array {
        $number = sanitize_text_field(self::text($_GET, 'invoice'));
        $args = [];
        if ($number !== '') { $args['invoice'] = mb_substr($number, 0, 100); }
        $type = sanitize_key(self::text($_GET, 'search_type'));
        if (isset(self::SEARCH_TYPES[$type]) && $type !== 'all') { $args['search_type'] = $type; }
        foreach (['order_id', 'case_id'] as $key) {
            $id = self::numeric_id(SN_Helpers::normalize_invoice_code(self::text($_GET, $key)));
            if ($id) { $args[$key] = $id; }
        }
        return $args;
    }
    private static function numeric_id(string $value): int {
        $digits = ltrim($value, '0');
        return ctype_digit($digits) && $digits === (string)(int)$digits ? (int)$digits : 0;
    }
    public static function headers(): void {
        nocache_headers();
        header('Cache-Control: private, no-store, max-age=0');
        header('Referrer-Policy: no-referrer');
        header('X-Robots-Tag: noindex, nofollow, noarchive');
        header('X-Frame-Options: DENY');
        header("Content-Security-Policy: frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
    }

    public static function menu(): void {
        $hook = add_options_page('دسترسی پشتیبانی شبکه فروش', 'دسترسی پشتیبانی شبکه فروش', 'manage_options', 'sn-support-setup', [self::class, 'setup']);
        if ($hook) { add_action('load-' . $hook, [self::class, 'headers']); }
    }
    public static function setup(): void {
        if (!self::administrator()) { wp_die('این تنظیم برای مدیر وردپرس روی اتصال امن در دسترس است.', '', ['response'=>403]); }
        $message = '';
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            check_admin_referer('sn_support_setup');
            $password = self::text($_POST, 'support_password');
            if (isset($_POST['disable'])) {
                delete_option(self::CONFIG);
                $message = 'پنل پشتیبانی غیرفعال شد.';
            } elseif (strlen($password) < 10 || strlen($password) > 72 || str_contains($password, "\0")) {
                $message = 'رمز پشتیبانی باید ۱۰ تا ۷۲ بایت باشد.';
            } else {
                $config = ['route'=>bin2hex(random_bytes(20)), 'hash'=>wp_hash_password($password)];
                $message = update_option(self::CONFIG, $config, false)
                    ? 'رمز و لینک تازه ثبت شدند. نشست‌های قبلی بسته شدند.'
                    : 'ذخیره تنظیمات انجام نشد.';
            }
        }
        $configured = !empty(self::config()['route']);
        ?>
        <div class="wrap" dir="rtl"><h1>دسترسی پشتیبانی شبکه فروش</h1>
            <?php if ($message !== '') : ?><p class="notice notice-info"><?php echo esc_html($message); ?></p><?php endif; ?>
            <?php if ($configured) : ?><p><a href="<?php echo esc_url(self::url()); ?>">باز کردن پنل پشتیبانی</a></p>
                <p><code dir="ltr"><?php echo esc_html(self::url()); ?></code></p><?php endif; ?>
            <p>ورود به پنل با حساب مدیر وردپرس و رمز جداگانه انجام می‌شود. ثبت رمز، یک لینک تصادفی تازه می‌سازد.</p>
            <form method="post">
                <?php wp_nonce_field('sn_support_setup'); ?>
                <label>رمز پشتیبانی <input name="support_password" type="password" minlength="10" maxlength="72" autocomplete="new-password" required></label>
                <?php submit_button('ذخیره رمز و ساخت لینک', 'primary', 'configure', false); ?>
                <?php if ($configured) : ?><button type="submit" name="disable" value="1" class="button" formnovalidate>غیرفعال کردن پنل</button><?php endif; ?>
            </form>
        </div>
        <?php
    }

    private static function unlock(array $config): string {
        global $wpdb;
        $token = self::session_token();
        if (!$token || empty($config['hash'])) { return 'نشست وردپرس معتبر نیست.'; }
        $rate = 'sn_sup_rate_' . get_current_user_id();
        $lock = 'sn_sup_' . md5(get_current_blog_id() . ':' . get_current_user_id());
        if ((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)', $lock)) !== 1) { return 'کمی بعد دوباره تلاش کنید.'; }
        try {
            $tries = (int)get_transient($rate);
            if ($tries >= 5) { return 'تعداد تلاش‌ها بیش از حد مجاز است. ۱۵ دقیقه بعد دوباره تلاش کنید.'; }
            $password = self::text($_POST, 'support_password');
            if (strlen($password) > 72 || !wp_check_password($password, $config['hash'])) {
                set_transient($rate, $tries + 1, 15 * MINUTE_IN_SECONDS);
                return 'رمز پشتیبانی معتبر نیست.';
            }
            $expires = time() + 15 * MINUTE_IN_SECONDS;
            $key = self::grant_key($config, $token);
            if (!set_transient($key, $expires, 15 * MINUTE_IN_SECONDS) && (int)get_transient($key) !== $expires) {
                return 'باز کردن نشست پشتیبانی انجام نشد.';
            }
            delete_transient($rate);
            return '';
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }

    public static function route(): void {
        global $pagenow;
        if ($pagenow !== 'admin.php' || !isset($_GET['sn_support'])) { return; }
        self::headers();
        $config = self::config();
        if (!is_user_logged_in() || !current_user_can('manage_options') || empty($config['route']) || !hash_equals($config['route'], self::text($_GET, 'sn_support'))) {
            wp_die('صفحه یافت نشد.', '', ['response'=>404]);
        }
        if (!self::secure_transport()) {
            wp_die('وردپرس اتصال این درخواست را HTTPS تشخیص نداده است. پنل پشتیبانی روی اتصال امن باز می‌شود.', '', ['response'=>403]);
        }
        $lookup = self::lookup();
        $message = '';
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            check_admin_referer('sn_support_session');
            if (isset($_POST['lock_panel'])) {
                $token = self::session_token();
                if ($token !== '') { delete_transient(self::grant_key($config, $token)); }
            } else {
                $message = self::unlock($config);
            }
            if ($message === '') { wp_safe_redirect(wp_make_link_relative(self::url(array_map('rawurlencode', $lookup)))); exit; }
        }
        $open = self::unlocked();
        ?>
        <!doctype html><html lang="fa" dir="rtl"><head>
        <meta charset="<?php bloginfo('charset'); ?>"><meta name="viewport" content="width=device-width, initial-scale=1">
        <title>پشتیبانی شبکه فروش</title>
        <link rel="stylesheet" href="<?php echo esc_url(SN_PLUGIN_URL . 'assets/css/support-console.css?v=' . SN_VERSION); ?>">
        <?php if ($open) : ?>
        <link rel="stylesheet" href="<?php echo esc_url(SN_PLUGIN_URL . 'assets/css/public.css?v=' . SN_VERSION); ?>">
        <link rel="stylesheet" href="<?php echo esc_url(SN_PLUGIN_URL . 'assets/css/dot-flow.css?v=' . SN_VERSION); ?>">
        <?php endif; ?>
        </head><body><main class="sn-support"><header>
        <div><span class="sn-support-eyebrow">میز کار مدیر</span><h1>پشتیبانی شبکه فروش</h1></div>
        <form method="post">
            <?php wp_nonce_field('sn_support_session'); ?>
            <?php if ($open) : ?><button name="lock_panel" value="1">قفل کردن پنل</button>
            <?php else : ?><label>رمز پشتیبانی <input name="support_password" type="password" maxlength="72" required autocomplete="current-password"></label> <button>ورود</button>
            <?php endif; ?>
        </form></header>
        <?php if ($message !== '') : ?><p role="alert"><?php echo esc_html($message); ?></p><?php endif; ?>
        <?php if ($open) { self::workspace($lookup); } ?>
        </main></body></html>
        <?php
        exit;
    }

    private static function invoice(int $id): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_invoices WHERE id=%d", $id)) ?: null;
    }
    public static function stamp(object $invoice, array $extra = []): string {
        return hash_hmac('sha256', serialize([$invoice,$extra]), wp_salt('nonce'));
    }
    public static function audit(object $invoice, string $operation, string $reason, array $before = [], array $after = []): bool {
        global $wpdb;
        return (bool)$wpdb->insert($wpdb->prefix . 'sn_activity_logs', [
            'invoice_id'=>(int)$invoice->id, 'lead_id'=>(int)($invoice->lead_id ?? 0) ?: null, 'user_id'=>get_current_user_id(),
            'action'=>'support_' . $operation, 'description'=>$reason,
            'old_value'=>wp_json_encode($before, JSON_UNESCAPED_UNICODE), 'new_value'=>wp_json_encode($after, JSON_UNESCAPED_UNICODE),
            'created_at'=>current_time('mysql'),
        ]);
    }
    private static function reason(): string {
        return sanitize_textarea_field(self::text($_POST, 'reason'));
    }
    private static function valid_reason(string $reason): bool {
        return $reason !== '' && mb_strlen($reason) <= 500;
    }
    private static function respond(bool $success, string $message, int $status = 200): void {
        wp_send_json(['success'=>$success,'message'=>$message], $status);
    }

    public static function dispatch(): void {
        self::headers();
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !self::unlocked() || !check_ajax_referer(self::AJAX, 'support_nonce', false)) {
            self::respond(false, 'نشست پشتیبانی معتبر نیست. پنل را دوباره باز کنید.', 403);
        }
        $id = absint(self::text($_POST, 'invoice_id'));
        $operation = sanitize_key(self::text($_POST, 'operation'));
        $reason = self::reason();
        $plus_mode = sanitize_key(self::text($_POST, 'edit_mode'));
        $read_only = $operation === 'plus_edit' && in_array($plus_mode, ['read','preview'], true);
        if ($id < 1 || (!$read_only && !self::valid_reason($reason))) {
            self::respond(false, 'شناسه فاکتور و دلیل اقدام تا ۵۰۰ نویسه الزامی است.', 400);
        }
        $core = sn_bootstrap_plugin_instance();
        if ($operation === 'edit') {
            $result = $core->support_edit_unpaid_invoice($id, wp_unslash($_POST), $reason);
            self::respond(!is_wp_error($result), is_wp_error($result) ? $result->get_error_message() : 'پیش‌فاکتور و مبلغ مرحله ذخیره شدند.');
        }
        if ($operation === 'plus_edit') {
            $_POST['nonce'] = $_REQUEST['nonce'] = wp_create_nonce('sn_public');
            $core->ajax_plus_invoice_edit(true, $reason);
            return;
        }
        $handlers = [
            'approve'=>'ajax_financial_approve_payment', 'reject'=>'ajax_financial_reject_payment',
            'reopen'=>'ajax_financial_reopen_invoice', 'cancel'=>'ajax_financial_cancel_invoice',
            'approve_receipt'=>'ajax_receipt_review', 'reject_receipt'=>'ajax_receipt_review',
        ];
        if (!isset($handlers[$operation])) { self::respond(false, 'عملیات پشتیبانی معتبر نیست.', 400); }
        $revision = self::text($_POST, 'invoice_revision');
        $amount = SN_Sales_Catalog::amount_key(self::text($_POST, 'confirm_amount'));
        $receipt_id = absint(self::text($_POST, 'payment_id'));
        $receipt_revision = absint(self::text($_POST, 'receipt_revision'));
        $tables = ['sn_invoices','sn_invoice_payment_stages','sn_payments','sn_activity_logs','sn_dot_cases','sn_dot_invoice_links','sn_dot_payments'];
        if ($operation === 'cancel') { $tables[] = 'sn_leads'; }
        if (!SN_Subscription_Plus::instance()->transactional_tables($tables)) {
            self::respond(false, 'جداول لازم برای ثبت امن عملیات مالی آماده نیستند.', 503);
        }
        $_POST = wp_slash([
            'invoice_id'=>$id, 'nonce'=>wp_create_nonce('sn_admin'), 'reason'=>$reason,
            'finance_edit_note'=>$reason, 'operation'=>$operation === 'approve_receipt' ? 'approve' : 'reject',
            'payment_id'=>$receipt_id, 'revision'=>$receipt_revision,
        ]);
        $_REQUEST['nonce'] = $_POST['nonce'];
        $guard = static function (object $invoice, ?object $receipt = null) use ($operation,$revision,$amount,$reason) {
            return self::finance_guard($invoice, $receipt, $operation, $revision, $amount, $reason);
        };
        if ($operation === 'cancel') {
            global $wpdb;
            $lock = SN_Invoice::stage_lock_name($id, 1);
            if ((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)', $lock)) !== 1) {
                self::respond(false, 'درخواست پرداخت در حال پردازش است.', 409);
            }
            register_shutdown_function(static function () use ($wpdb, $lock): void {
                $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
            });
        }
        $core->{$handlers[$operation]}($guard);
    }

    private static function finance_guard(object $invoice, ?object $receipt, string $operation, string $revision, string $amount, string $reason) {
        global $wpdb;
        if (!hash_equals(self::stamp($invoice), $revision)) {
            return new WP_Error('support_stale', 'فاکتور تغییر کرده است. قبل از تصمیم دوباره آن را باز کنید.');
        }
        $id = (int)$invoice->id;
        $stage = (int)$invoice->current_payment_stage;
        if ($operation === 'cancel') {
            $transactions = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}sn_payments WHERE invoice_id=%d", $id));
            $marketing = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}sn_dot_invoice_links WHERE invoice_id=%d AND COALESCE(marketing_form_id,0)>0", $id));
            $stage_status = $wpdb->get_var($wpdb->prepare("SELECT status FROM {$wpdb->prefix}sn_invoice_payment_stages WHERE invoice_id=%d AND stage_no=1", $id));
            if ($transactions === null || (int)$transactions > 0 || $marketing === null || (int)$marketing > 0 || $stage !== 1
                || $stage_status !== 'pending' || (float)$invoice->paid_total_amount > 0
                || !empty($invoice->wc_order_id) || !empty($invoice->paid_at) || !empty($invoice->approved_at)
                || !empty($invoice->receipt_url) || !empty($invoice->receipt_file) || !empty($invoice->receipt_urls)
                || (float)($invoice->manual_amount ?? 0) > 0 || (float)($invoice->deposit_amount ?? 0) > 0
                || !in_array($invoice->status, ['pre_invoice','pending','pending_payment'], true)) {
                return new WP_Error('support_cancel', 'لغو از این پنل فقط برای پیش‌فاکتور بدون پرداخت، فیش و درخواست درگاه مجاز است.');
            }
        }
        if ($operation === 'reject') {
            $online = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}sn_payments WHERE invoice_id=%d AND payment_stage_no=%d AND status='verified'", $id, $stage));
            if ($online === null || (int)$online > 0) {
                return new WP_Error('support_verified', 'تراکنش درگاهی تأییدشده برای بررسی یا عودت به روال مالی ارجاع می‌شود.');
            }
        }
        if (in_array($operation, ['approve','approve_receipt'], true)) {
            $expected = SN_Sales_Catalog::amount_key($receipt ? $receipt->amount : $invoice->current_due_amount);
            if ($expected === '' || !hash_equals($expected, $amount)) {
                return new WP_Error('support_amount', 'مبلغ واردشده باید با مبلغ واقعی واریز یا مرحله برابر باشد.');
            }
            if (!$receipt) {
                $stage_amount = $wpdb->get_var($wpdb->prepare(
                    "SELECT requested_amount FROM {$wpdb->prefix}sn_invoice_payment_stages WHERE invoice_id=%d AND stage_no=%d FOR UPDATE", $id, $stage
                ));
                if (SN_Sales_Catalog::amount_key($stage_amount) !== $expected) {
                    return new WP_Error('support_stage_amount', 'مبلغ مرحله و ماندهٔ جاری فاکتور هم‌خوان نیستند.');
                }
                $payment = $wpdb->get_row($wpdb->prepare(
                    "SELECT id,amount,uploaded_by_user_id FROM {$wpdb->prefix}sn_payments WHERE invoice_id=%d AND payment_stage_no=%d
                     AND (status='verified' OR (status='pending' AND COALESCE(authority,'')='')) ORDER BY id DESC LIMIT 1 FOR UPDATE", $id, $stage
                ));
                $legacy_amount = ($invoice->manual_amount ?? 0) ?: ($invoice->deposit_amount ?? 0);
                $legacy_proof = !empty($invoice->receipt_url) || !empty($invoice->receipt_file) || !empty($invoice->manual_card_from);
                if ($wpdb->last_error || ($payment
                    ? (SN_Sales_Catalog::amount_key($payment->amount) !== $expected || (int)$payment->uploaded_by_user_id === get_current_user_id())
                    : (!$legacy_proof || SN_Sales_Catalog::amount_key($legacy_amount) !== $expected))) {
                    return new WP_Error('support_proof', 'واریز معتبر با مبلغ دقیق پیدا نشد یا واریز توسط همین مدیر ثبت شده است.');
                }
            }
        }
        if (!self::audit($invoice, $operation, $reason, ['status'=>$invoice->status,'stage'=>$stage,'due'=>$invoice->current_due_amount],
            ['payment_id'=>$receipt ? (int)$receipt->id : null,'confirmed_amount'=>$amount === '' ? null : (int)$amount / 100])) {
            return new WP_Error('support_audit', 'ثبت تاریخچه ممکن نیست. عملیات متوقف شد.');
        }
        return null;
    }

    public static function case_action_field(string $action, int $support_case_id = 0): string {
        if (!$support_case_id) { return '<input type="hidden" name="action" value="' . esc_attr($action) . '">'; }
        return '<input type="hidden" name="action" value="sn_support_case"><input type="hidden" name="support_operation" value="' . esc_attr($action) . '">'
            . wp_nonce_field('sn_support_case_' . $support_case_id . '_' . $action, 'support_nonce', false, false)
            . '<label>دلیل اقدام پشتیبانی <input name="support_reason" maxlength="500" required></label>';
    }
    public static function case_action(): void {
        global $wpdb;
        self::headers();
        $case_id = absint(self::text($_POST,'case_id'));
        $action = sanitize_key(self::text($_POST,'support_operation'));
        $reason = sanitize_textarea_field(self::text($_POST,'support_reason'));
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !self::unlocked() || !$case_id || !isset(self::CASE_ACTIONS[$action])
            || !self::valid_reason($reason) || !wp_verify_nonce(self::text($_POST,'support_nonce'), 'sn_support_case_' . $case_id . '_' . $action)) {
            wp_die('نشست، دلیل اقدام یا دسترسی پشتیبانی معتبر نیست.', '', ['response'=>403]);
        }
        $case = $wpdb->get_row($wpdb->prepare("SELECT source_invoice_id,operational_archived_at FROM {$wpdb->prefix}sn_dot_cases WHERE id=%d", $case_id));
        $invoice = $case ? self::invoice((int)$case->source_invoice_id) : null;
        if ($action === 'sn_dot_resend_payment' && !$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}sn_dot_payments WHERE case_id=%d AND id=%d", $case_id, absint(self::text($_POST,'payment_id'))))) {
            wp_die('پرداخت برای این پرونده نیست.', '', ['response'=>403]);
        }
        if (!$invoice || !empty($case->operational_archived_at) || !self::audit($invoice, 'case_request', $reason, ['case_id'=>$case_id], ['operation'=>$action])) {
            wp_die('پرونده بسته است یا ثبت درخواست پشتیبانی انجام نشد.', '', ['response'=>409]);
        }
        $_POST['action'] = $action;
        $_REQUEST['_wp_http_referer'] = self::url(['case_id'=>$case_id]);
        SN_Dot_Flow::instance()->{self::CASE_ACTIONS[$action]}();
    }

    private static function order_invoices(int $order_id): array {
        global $wpdb;
        if (!$order_id) { return []; }
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}sn_invoices WHERE wc_order_id=%d ORDER BY id DESC LIMIT %d",
            $order_id, self::RESULT_LIMIT + 1
        )) ?: [];
        if (!$rows && function_exists('wc_get_order')) {
            $order = wc_get_order($order_id);
            $invoice = $order ? self::invoice((int)$order->get_meta('_sn_invoice_id', true)) : null;
            if ($invoice && (!(int)$invoice->wc_order_id || (int)$invoice->wc_order_id === $order_id)) { $rows[] = $invoice; }
        }
        return $rows;
    }

    private static function search_invoices(string $term, string $type): array {
        global $wpdb;
        $table = $wpdb->prefix . 'sn_invoices';
        $code = SN_Helpers::normalize_invoice_code($term);
        if ($code === '') { return []; }
        $id = self::numeric_id($code);
        if ($type === 'order') { return self::order_invoices(self::numeric_id(ltrim($code, '#'))); }
        if (str_starts_with($code, '#')) {
            $row = self::invoice(self::numeric_id(substr($code, 1)));
            return $row ? [$row] : [];
        }

        $where = ['i.invoice_code IN (%s,%s)', 'i.short_code=%s'];
        $args = [$term, $code, $code];
        if (ctype_digit($code) && strlen($code) === 8) {
            $where[] = 'i.invoice_code=%s';
            $args[] = 'INV-' . $code;
        }
        if ($id) {
            $where[] = 'i.id=%d';
            $args[] = $id;
        }
        if ($type === 'all') {
            if ($id) {
                $where[] = 'i.wc_order_id=%d';
                $args[] = $id;
            }
            $localized = SN_Helpers::to_english_nums($term);
            if (preg_match('/^[A-Za-z0-9_-]+$/D', $localized)) {
                $where[] = "i.id IN (SELECT invoice_id FROM {$wpdb->prefix}sn_payments WHERE ref_id=%s)";
                $where[] = "i.id IN (SELECT invoice_id FROM {$wpdb->prefix}sn_invoice_payment_stages WHERE payment_ref_id=%s)";
                array_push($args, $localized, $localized);
            }
            if (preg_match('/^\+?[0-9() .-]+$/D', $localized) && SN_Helpers::is_valid_mobile($localized)) {
                $mobile = SN_Helpers::normalize_mobile($localized);
                $intl = '98' . substr($mobile, 1);
                $where[] = 'i.customer_phone IN (%s,%s,%s) OR i.customer_phone_secondary IN (%s,%s,%s)';
                array_push($args, $mobile, $intl, '+' . $intl, $mobile, $intl, '+' . $intl);
            }
        }
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT i.* FROM {$table} i WHERE (" . implode(' OR ', $where) . ") ORDER BY i.id DESC LIMIT %d",
            [...$args, self::RESULT_LIMIT + 1]
        )) ?: [];
        if (!$rows && $type === 'all' && $id) { $rows = self::order_invoices($id); }
        if (!$rows && preg_match('/^.{3,}$/uD', $term)) {
            $prefix = $wpdb->esc_like($code) . '%';
            $where = 'invoice_code LIKE %s';
            $args = [$prefix];
            if ($type === 'all' && preg_match('/\p{L}/u', $term)) {
                $where .= ' OR customer_name LIKE %s';
                $args[] = '%' . $wpdb->esc_like($term) . '%';
            }
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$table} WHERE {$where} ORDER BY id DESC LIMIT %d",
                [...$args, self::RESULT_LIMIT + 1]
            )) ?: [];
        }
        return $rows;
    }

    private static function invoice_list(array $rows, string $title, int $case_id = 0): void {
        ?>
        <section class="sn-support-results"><h2><?php echo esc_html($title); ?></h2>
            <?php if (count($rows) > self::RESULT_LIMIT) : ?><p class="sn-support-hint">۲۰ مورد اول نمایش داده شده‌اند. برای نتیجهٔ دقیق‌تر، شماره یا نام کامل را وارد کن.</p><?php endif; ?>
            <div class="sn-support-table-scroll">
                <table class="sn-support-table">
                    <thead><tr><th scope="col">فاکتور</th><th scope="col">مشتری</th><th scope="col">وضعیت</th><th scope="col">مبلغ کل</th><th scope="col">تاریخ صدور</th></tr></thead>
                    <tbody><?php foreach (array_slice($rows, 0, self::RESULT_LIMIT) as $row) :
                        $args = ['invoice' => rawurlencode('#' . $row->id)];
                        if ($case_id) { $args['case_id'] = $case_id; }
                        ?>
                        <tr>
                            <td><a href="<?php echo esc_url(self::url($args)); ?>"><bdi><?php echo esc_html($row->invoice_code); ?></bdi></a></td>
                            <td><?php echo esc_html($row->customer_name); ?></td>
                            <td><span class="sn-support-badge"><?php echo esc_html(SN_Helpers::status_label($row->status)); ?></span></td>
                            <td><?php echo esc_html(self::money($row->payment_total_amount ?? $row->final_total ?? $row->product_price)); ?></td>
                            <td><bdi><?php echo esc_html(substr(SN_Helpers::gregorian_to_jalali_date($row->created_at), 0, 10)); ?></bdi></td>
                        </tr>
                    <?php endforeach; ?></tbody>
                </table>
            </div>
        </section>
        <?php
    }

    private static function workspace(array $lookup): void {
        global $wpdb;
        $term = $lookup['invoice'] ?? '';
        $type = $lookup['search_type'] ?? 'all';
        $order_id = $lookup['order_id'] ?? 0;
        $case_id = $lookup['case_id'] ?? 0;
        $searching = $term !== '' || $order_id || $case_id;
        if ($term !== '' && $type === 'case') {
            $case_id = self::numeric_id(ltrim(SN_Helpers::normalize_invoice_code($term), '#'));
        }
        $case = $case_id ? $wpdb->get_row($wpdb->prepare(
            "SELECT id,source_invoice_id FROM {$wpdb->prefix}sn_dot_cases WHERE id=%d", $case_id
        )) : null;
        $rows = [];
        if ($term !== '' && $type !== 'case') { $rows = self::search_invoices($term, $type); }
        if (!$rows && $order_id) { $rows = self::order_invoices($order_id); }
        $display_term = $term !== '' ? $term : ($order_id ?: ($case_id ?: ''));
        $display_type = $term !== '' ? $type : ($order_id ? 'order' : ($case_id ? 'case' : $type));
        ?>
        <nav aria-label="پنل‌های مرتبط">
            <a href="<?php echo esc_url(admin_url('admin.php?page=sn-leads')); ?>">سرنخ‌ها و ارجاع فروش</a>
            <a href="<?php echo esc_url(admin_url('admin.php?page=sn-financial-approval')); ?>">کارتابل مالی</a>
            <a href="<?php echo esc_url(admin_url('admin.php?page=sn-invoices')); ?>">فاکتورها</a>
            <a href="<?php echo esc_url(admin_url('edit.php?post_type=product')); ?>">محصولات</a>
            <?php foreach (['sn_operations_sales_manager_panel_page_id'=>'فروش بیاوین','sn_operations_executive_manager_panel_page_id'=>'اجرای بیاوین','sn_shipping_panel_page_id'=>'ارسال سفارش'] as $option=>$label) :
                $page = (int)get_option($option, 0); $link = $page ? get_permalink($page) : false;
                if ($link) : ?><a href="<?php echo esc_url($link); ?>"><?php echo esc_html($label); ?></a><?php endif;
            endforeach; ?>
        </nav>
        <section class="sn-support-search"><h2>فاکتور یا پرونده را پیدا کن</h2>
            <p class="sn-support-hint" id="sn-support-search-help">با کد فاکتور، شماره سفارش، کد پیگیری پرداخت، نام یا موبایل مشتری جست‌وجو کن. برای شمارهٔ پرونده، نوع جست‌وجو را روی «پروندهٔ تبدیل» بگذار.</p>
            <form method="get" action="<?php echo esc_url(wp_make_link_relative(admin_url('admin.php'))); ?>">
                <input type="hidden" name="sn_support" value="<?php echo esc_attr(self::config()['route']); ?>">
                <label class="sn-support-search-term">عبارت جست‌وجو <input type="search" name="invoice" maxlength="100" value="<?php echo esc_attr($display_term); ?>" placeholder="شماره یا نام مشتری" aria-describedby="sn-support-search-help" dir="auto" required></label>
                <label>جست‌وجو در <select name="search_type">
                    <?php foreach (self::SEARCH_TYPES as $key => $label) : ?><option value="<?php echo esc_attr($key); ?>" <?php selected($display_type, $key); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?>
                </select></label>
                <button>جست‌وجو</button>
                <?php if ($searching) : ?><a class="sn-support-clear" href="<?php echo esc_url(self::url()); ?>">پاک کردن</a><?php endif; ?>
            </form>
            <?php if ($searching && !$rows && !$case) : ?>
                <p class="sn-support-empty" role="status"><?php echo esc_html($type === 'case' || ($case_id && !$order_id && $term === '') ? 'پروندهٔ تبدیل پیدا نشد. شمارهٔ پرونده را بررسی کن یا با موبایل مشتری در «همهٔ موارد» جست‌وجو کن.' : 'موردی پیدا نشد. شماره را بررسی کن یا با نام یا موبایل مشتری در «همهٔ موارد» جست‌وجو کن.'); ?></p>
            <?php endif; ?>
        </section>
        <?php
        if (!$searching) {
            $recent = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}sn_invoices ORDER BY id DESC LIMIT 12") ?: [];
            if ($recent) { self::invoice_list($recent, 'آخرین فاکتورها'); }
        }
        if (count($rows) === 1) { self::render_invoice($rows[0]); }
        if (count($rows) > 1) { self::invoice_list($rows, 'نتایج جست‌وجو'); }
        if ($case) {
            $case_invoices = $wpdb->get_results($wpdb->prepare(
                "SELECT i.* FROM {$wpdb->prefix}sn_invoices i
                 WHERE i.id=%d OR i.id IN (SELECT invoice_id FROM {$wpdb->prefix}sn_dot_invoice_links WHERE case_id=%d)
                 ORDER BY i.id DESC LIMIT %d",
                $case->source_invoice_id, $case_id, self::RESULT_LIMIT + 1
            )) ?: [];
            if ($case_invoices) { self::invoice_list($case_invoices, 'فاکتورهای همین پرونده', $case_id); }
            echo SN_Dot_Flow::instance()->render_converter_panel('', $case_id);
        }
        ?>
        <div id="sn-support-config" data-ajax="<?php echo esc_url(wp_make_link_relative(admin_url('admin-ajax.php'))); ?>" data-nonce="<?php echo esc_attr(wp_create_nonce(self::AJAX)); ?>"></div>
        <?php if ($case) : ?>
        <script src="<?php echo esc_url(includes_url('js/jquery/jquery.min.js')); ?>"></script>
        <script src="<?php echo esc_url(SN_PLUGIN_URL . 'assets/js/public-dot.js?v=' . SN_VERSION); ?>"></script>
        <?php endif; ?>
        <script src="<?php echo esc_url(SN_PLUGIN_URL . 'assets/js/plus-invoice-editor.js?v=' . SN_VERSION); ?>"></script>
        <script src="<?php echo esc_url(SN_PLUGIN_URL . 'assets/js/support-console.js?v=' . SN_VERSION); ?>"></script>
        <?php
    }

    private static function render_invoice(object $invoice): void {
        global $wpdb;
        $id = (int)$invoice->id;
        $stage = (int)$invoice->current_payment_stage;
        $items = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_invoice_items WHERE invoice_id=%d ORDER BY id", $id), ARRAY_A) ?: [];
        $stage_row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_invoice_payment_stages WHERE invoice_id=%d AND stage_no=%d", $id, $stage));
        $payments = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_payments WHERE invoice_id=%d ORDER BY id DESC LIMIT 100", $id)) ?: [];
        $case_id = (int)$wpdb->get_var($wpdb->prepare("SELECT case_id FROM {$wpdb->prefix}sn_dot_invoice_links WHERE invoice_id=%d LIMIT 1", $id));
        if (!$case_id) {
            $case_id = (int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}sn_dot_cases WHERE source_invoice_id=%d", $id));
        }
        $memberships = $wpdb->get_results($wpdb->prepare("SELECT id,status,subscription_name_snapshot FROM {$wpdb->prefix}sn_project_memberships WHERE source_invoice_id=%d OR first_payment_invoice_id=%d ORDER BY id DESC LIMIT 50", $id,$id)) ?: [];
        $events = $wpdb->get_results($wpdb->prepare("SELECT user_id,action,description,old_value,new_value,created_at FROM {$wpdb->prefix}sn_activity_logs WHERE invoice_id=%d ORDER BY id DESC LIMIT 30", $id)) ?: [];
        $plus = SN_Subscription_Plus::instance()->sale($id);
        $editing = sn_bootstrap_plugin_instance()->support_invoice_edit_allowed($invoice, $stage_row);
        ?>
        <section class="sn-support-invoice" data-invoice-id="<?php echo esc_attr((string)$id); ?>">
            <div class="sn-support-invoice-heading"><div><h2>فاکتور <bdi><?php echo esc_html($invoice->invoice_code); ?></bdi></h2><p><?php echo esc_html($invoice->customer_name); ?></p></div>
                <span class="sn-support-badge"><?php echo esc_html(SN_Helpers::status_label($invoice->status)); ?></span>
            </div>
            <dl class="sn-support-amounts">
                <div><dt>مبلغ کل</dt><dd><?php echo esc_html(self::money($invoice->payment_total_amount ?? $invoice->final_total ?? $invoice->product_price)); ?></dd></div>
                <div><dt>وصول‌شده</dt><dd><?php echo esc_html(self::money($invoice->paid_total_amount)); ?></dd></div>
                <div><dt>مانده</dt><dd><?php echo esc_html(self::money($invoice->remaining_amount)); ?></dd></div>
                <div><dt>مبلغ مرحلهٔ <?php echo esc_html((string)$stage); ?></dt><dd><?php echo esc_html(self::money($invoice->current_due_amount)); ?></dd></div>
            </dl>
            <?php if ($case_id) : ?><p><a href="<?php echo esc_url(self::url(['invoice'=>rawurlencode('#'.$id),'case_id'=>$case_id])); ?>">باز کردن پروندهٔ تبدیل <bdi>#<?php echo esc_html((string)$case_id); ?></bdi></a></p><?php endif; ?>
            <?php if (!empty($invoice->wc_order_id)) : ?><p>سفارش ووکامرس #<?php echo esc_html($invoice->wc_order_id); ?></p><?php endif; ?>
            <?php foreach ($memberships as $member) : ?><p>عضویت بیاوین #<?php echo esc_html($member->id . ' · ' . $member->subscription_name_snapshot . ' · ' . $member->status); ?></p><?php endforeach; ?>
            <ul><?php foreach ($items as $item) : ?><li><?php echo esc_html($item['product_name'] . ' (#' . $item['product_id'] . ') × ' . $item['qty'] . ' · ' . self::money($item['total_price'])); ?></li><?php endforeach; ?></ul>
        </section>
        <?php if ($editing && $items && !$payments) : ?>
            <section><h2>اصلاح پیش‌فاکتور پیش از پرداخت</h2>
            <p>شناسهٔ محصول جایگزین از فهرست محصولات ووکامرس خوانده می‌شود. قیمت واحد و تعداد، مبلغ کل جدید را تعیین می‌کنند.</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" data-sn-support-action>
                <?php self::action_fields($invoice, 'edit', self::stamp($invoice, [$stage_row,$items])); ?>
                <?php foreach ($items as $item) : $name = 'lines[' . (int)$item['id'] . ']'; ?>
                    <fieldset><legend><?php echo esc_html($item['product_name']); ?></legend>
                        <label>شناسه محصول <input name="<?php echo esc_attr($name . '[product]'); ?>" value="<?php echo esc_attr($item['product_id']); ?>" inputmode="numeric" required></label>
                        <label>تعداد <input name="<?php echo esc_attr($name . '[qty]'); ?>" value="<?php echo esc_attr($item['qty']); ?>" inputmode="numeric" required></label>
                        <label>قیمت واحد (تومان) <input name="<?php echo esc_attr($name . '[price]'); ?>" value="<?php echo esc_attr($item['unit_price']); ?>" inputmode="decimal" required></label>
                    </fieldset>
                <?php endforeach; ?>
                <?php if ($invoice->payment_plan === 'partial') : ?><label>مبلغ مرحلهٔ اول <input name="stage_amount" value="<?php echo esc_attr($invoice->current_due_amount); ?>" inputmode="decimal" required></label><?php endif; ?>
                <label>تأیید مبلغ کل جدید <input name="new_total" inputmode="decimal" required></label>
                <button>ذخیرهٔ اصلاح</button><p role="status" data-sn-result></p>
            </form></section>
        <?php elseif ($plus && $plus->status === 'awaiting_payment') : ?>
            <section><h2>ویرایش اشتراک+</h2>
                <label>دلیل اصلاح <input id="sn-support-plus-reason" maxlength="500"></label>
                <button type="button" data-sn-support-plus="<?php echo esc_attr((string)$id); ?>">باز کردن ویرایشگر و پیش‌نمایش</button>
            </section>
        <?php endif; ?>
        <section><h2>بررسی مالی و وضعیت</h2>
        <?php $modern = false; foreach ($payments as $payment) :
            $is_current = (int)$payment->payment_stage_no === $stage;
            $independent = SN_Receipt_Review::modern($payment);
            $modern = $modern || ($independent && $is_current);
            ?>
            <details><summary>واریز #<?php echo esc_html($payment->id . ' · مرحله ' . $payment->payment_stage_no . ' · ' . self::money($payment->amount) . ' · ' . SN_Helpers::status_label($payment->status)); ?></summary>
            <?php
                $proof = json_decode((string)$payment->proof_data, true);
                self::proof(is_array($proof) ? $proof : []);
                if ($is_current && $independent && $payment->status === 'pending') {
                    self::finance_form($invoice, 'approve_receipt', 'تأیید این واریز', $payment);
                    self::finance_form($invoice, 'reject_receipt', 'رد این واریز', $payment);
                }
            ?></details>
        <?php endforeach; ?>
        <?php self::proof((array)$invoice); ?>
        <?php if ($stage_row && in_array($stage_row->status, ['pending_financial_approval','receipt_uploaded'], true) && (!$modern || SN_Receipt_Review::stage_amounts($id,$stage)['has_verified_gateway'])) :
            self::finance_form($invoice, 'approve', 'تأیید مبلغ مرحله');
            if (!$modern) { self::finance_form($invoice, 'reject', 'رد مرحله'); }
        endif;
        if ($invoice->status === 'approved') { self::finance_form($invoice, 'reopen', 'بازگشت به بررسی مالی'); }
        if (in_array($invoice->status, ['pre_invoice','pending','pending_payment'], true) && !$payments) {
            self::finance_form($invoice, 'cancel', 'لغو پیش‌فاکتور پرداخت‌نشده');
        } ?>
        <p>رسیدهای دارای سابقه، عودت وجه و وضعیت تحویل از روال‌های مالی و اجرایی مرتبط پیگیری می‌شوند.</p>
        </section>
        <?php if ($events) : ?><section><h2>آخرین فعالیت‌ها</h2><ol>
            <?php foreach ($events as $event) : ?><li><?php echo esc_html($event->created_at . ' · کاربر #' . $event->user_id . ' · ' . $event->action . ' · ' . $event->description); ?>
                <?php if (str_starts_with($event->action, 'support_') && ($event->old_value || $event->new_value)) : ?>
                    <details><summary>جزئیات ثبت‌شده</summary><pre><?php echo esc_html($event->old_value . "\n" . $event->new_value); ?></pre></details>
                <?php endif; ?>
            </li><?php endforeach; ?></ol></section><?php endif;
    }

    private static function money($amount): string {
        return number_format_i18n((float)$amount, floor((float)$amount) === (float)$amount ? 0 : 2) . ' تومان';
    }
    private static function proof(array $proof): void {
        $labels = ['manual_card_from'=>'۴ رقم کارت مبدأ','manual_card_to'=>'۴ رقم کارت مقصد','manual_account_last6'=>'۶ رقم حساب مبدأ',
            'manual_tracking_last6'=>'۶ رقم پیگیری','manual_account_owner'=>'صاحب حساب','manual_paid_at_jalali'=>'تاریخ واریز'];
        foreach ($labels as $key=>$label) {
            if (!empty($proof[$key]) && is_scalar($proof[$key])) {
                echo '<p>' . esc_html($label . ' · ' . $proof[$key]) . '</p>';
            }
        }
        foreach (SN_Helpers::receipt_urls($proof) as $index=>$url) {
            echo '<p><a href="' . esc_url($url, ['http','https']) . '" target="_blank" rel="noopener noreferrer">مشاهده فیش ' . esc_html((string)($index+1)) . '</a></p>';
        }
    }
    private static function action_fields(object $invoice, string $operation, string $revision): void {
        ?>
        <input type="hidden" name="action" value="<?php echo esc_attr(self::AJAX); ?>">
        <input type="hidden" name="operation" value="<?php echo esc_attr($operation); ?>">
        <input type="hidden" name="invoice_id" value="<?php echo esc_attr($invoice->id); ?>">
        <input type="hidden" name="invoice_revision" value="<?php echo esc_attr($revision); ?>">
        <?php wp_nonce_field(self::AJAX, 'support_nonce', false); ?>
        <label>دلیل اقدام <input name="reason" maxlength="500" required></label>
        <?php
    }
    private static function finance_form(object $invoice, string $operation, string $label, ?object $payment = null): void {
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" data-sn-support-action>
            <?php self::action_fields($invoice, $operation, self::stamp($invoice)); ?>
            <?php if ($payment) : ?>
                <input type="hidden" name="payment_id" value="<?php echo esc_attr($payment->id); ?>">
                <input type="hidden" name="receipt_revision" value="<?php echo esc_attr(max(1,(int)(SN_Receipt_Review::metadata($payment)['revision'] ?? 1))); ?>">
            <?php endif; ?>
            <?php if (in_array($operation, ['approve','approve_receipt'], true)) : ?>
                <label>تأیید مبلغ <?php echo esc_html(self::money($payment ? $payment->amount : $invoice->current_due_amount)); ?>
                    <input name="confirm_amount" inputmode="decimal" required></label>
            <?php endif; ?>
            <button><?php echo esc_html($label); ?></button><p role="status" data-sn-result></p>
        </form>
        <?php
    }
}
