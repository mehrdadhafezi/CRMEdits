<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Product-wide invoice-link SMS gate and minimal durable delivery evidence. */
final class SN_Invoice_SMS_Policy {
    private const META = '_sn_invoice_sms_policy';
    private const DELIVERY = 'sn_invoice_sms_delivery_';
    private static array $active = [];

    public static function register_hooks(): void {
        add_action( 'add_meta_boxes_product', [ __CLASS__, 'metabox' ] );
        add_action( 'save_post_product', [ __CLASS__, 'save_product' ], 25, 2 );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'assets' ] );
        add_action( 'wp_ajax_sn_product_invoice_sms_preview', [ __CLASS__, 'preview' ] );
        add_action( 'wp_ajax_sn_product_invoice_sms_send', [ __CLASS__, 'send_previous' ] );
    }

    public static function policy( int $id ): array {
        $raw = get_post_meta( $id, self::META, true );
        if ( $raw === '' ) { return [ 'enabled' => true, 'previous' => false, 'revision' => '' ]; }
        return [ 'enabled' => is_array( $raw ) && ( $raw['enabled'] ?? false ) === true,
            'previous' => is_array( $raw ) && ( $raw['previous'] ?? false ) === true,
            'revision' => is_array( $raw ) ? (string) ( $raw['revision'] ?? '' ) : '' ];
    }

    public static function metabox(): void {
        add_meta_box( 'sn-invoice-sms-policy', 'پیامک پیش‌فاکتور محصول', [ __CLASS__, 'render' ], 'product', 'normal', 'default' );
    }

    public static function render( WP_Post $post ): void {
        if ( ! current_user_can( 'edit_post', $post->ID ) ) { return; }
        $p = self::policy( $post->ID );
        wp_nonce_field( 'sn_invoice_sms_product_' . $post->ID, 'sn_invoice_sms_product_nonce' );
        ?>
        <style>.sn-product-invoice-sms [hidden]{display:none!important}</style>
        <div class="sn-product-invoice-sms" dir="rtl" data-product-id="<?php echo (int) $post->ID; ?>">
            <input type="hidden" name="sn_invoice_sms_product_present" value="1">
            <input type="hidden" name="sn_invoice_sms_product_revision" value="<?php echo esc_attr( $p['revision'] ); ?>">
            <p><label><input type="checkbox" name="sn_invoice_sms_product_enabled" value="1" <?php checked( $p['enabled'] ); ?>> ارسال پیامک لینک پیش‌فاکتور این محصول</label></p>
            <p class="description">این تنظیم برای کل CRM است. اگر فاکتور چند محصول داشته باشد، خاموش‌بودن پیامک هر یک از آن‌ها، پیامک لینک همان فاکتور را متوقف می‌کند. پیامک ورود و رسید پرداخت از این گزینه مستقل‌اند.</p>
            <p><label><input type="checkbox" name="sn_invoice_sms_product_previous" value="1" <?php checked( $p['previous'] ); ?>> پس از روشن‌شدن، امکان ارسال برای پیش‌فاکتورهای قبلی هم فعال باشد</label></p>
            <p class="description">ذخیره به‌تنهایی پیامک گروهی ارسال نمی‌کند. فقط پیش‌فاکتورهای باز و پرداخت‌نشده با سابقهٔ قطعیِ ارسال‌نشدن قابل انتخاب‌اند؛ سابقهٔ نامشخص یا ارسال پذیرفته‌شده کنار گذاشته می‌شود.</p>
            <?php if ( current_user_can( 'manage_options' ) ) : ?>
                <p><button type="button" class="button sn-product-sms-preview">بررسی پیش‌فاکتورهای قبلی</button> <button type="button" class="button button-primary sn-product-sms-send" hidden>تأیید و ارسال موارد بررسی‌شده</button> <button type="button" class="button sn-product-sms-stop" hidden>توقف ادامهٔ ارسال</button></p>
                <div class="sn-product-sms-notice" role="status" aria-live="polite">ابتدا تغییرات محصول را ذخیره کنید، سپس فهرست قبلی را بررسی کنید.</div>
            <?php endif; ?>
        </div>
        <?php
    }

    public static function assets(): void {
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen || $screen->post_type !== 'product' || ! current_user_can( 'manage_options' ) ) { return; }
        wp_enqueue_script( 'sn-product-invoice-sms', SN_PLUGIN_URL . 'assets/js/product-invoice-sms.js', [ 'jquery' ], SN_VERSION, true );
        wp_localize_script( 'sn-product-invoice-sms', 'snProductInvoiceSMS', [ 'ajaxUrl' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'sn_product_invoice_sms' ) ] );
    }

    public static function save_product( int $id, WP_Post $post ): void {
        if ( $post->post_type !== 'product' || wp_is_post_revision( $id ) || defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE
            || ! isset( $_POST['sn_invoice_sms_product_present'] ) || ! current_user_can( 'edit_post', $id ) ) { return; }
        $nonce = $_POST['sn_invoice_sms_product_nonce'] ?? null;
        if ( ! is_string( $nonce ) || ! wp_verify_nonce( $nonce, 'sn_invoice_sms_product_' . $id ) ) { return; }
        global $wpdb;
        $lock = 'sn_sms_product_' . md5( DB_NAME . ':' . $wpdb->prefix . ':' . $id );
        if ( (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,0)', $lock ) ) !== '1' ) { self::save_error(); return; }
        try {
            wp_cache_delete( $id, 'post_meta' );
            $old = get_post_meta( $id, self::META, true ); $policy = self::policy( $id );
            $revision = $_POST['sn_invoice_sms_product_revision'] ?? null;
            if ( ! is_string( $revision ) || ! hash_equals( $policy['revision'], wp_unslash( $revision ) ) ) { self::save_error(); return; }
            foreach ( [ 'sn_invoice_sms_product_enabled', 'sn_invoice_sms_product_previous' ] as $key ) {
                if ( isset( $_POST[ $key ] ) && $_POST[ $key ] !== '1' ) { self::save_error(); return; }
            }
            $next = [ 'enabled' => isset( $_POST['sn_invoice_sms_product_enabled'] ), 'previous' => isset( $_POST['sn_invoice_sms_product_previous'] ), 'revision' => wp_generate_uuid4() ];
            $saved = $old === '' ? add_post_meta( $id, self::META, $next, true ) : update_post_meta( $id, self::META, $next, $old );
            if ( ! $saved || get_post_meta( $id, self::META, true ) !== $next ) { self::save_error(); }
        } finally { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); }
    }

    private static function save_error(): void {
        if ( class_exists( 'WC_Admin_Meta_Boxes' ) ) { WC_Admin_Meta_Boxes::add_error( 'تنظیم پیامک ذخیره نشد؛ مقدار نامعتبر یا تغییر هم‌زمان است. محصول را دوباره بارگذاری کنید.' ); }
    }

    public static function delivery( int $id ): array {
        global $wpdb;
        // A request may have inspected this option before another process sent
        // the SMS. Read the durable row again under the dispatch lock.
        $raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s", self::DELIVERY . $id ) );
        if ( $wpdb->last_error !== '' ) { return [ 'storage_error' => true ]; }
        $value = is_string( $raw ) ? json_decode( $raw, true ) : null;
        return is_array( $value ) ? $value : [];
    }

    /** Called only immediately after committing a newly created invoice. */
    public static function created( int $id ): void {
        if ( $id < 1 ) { return; }
        global $wpdb; $lock = self::lock_name( $id );
        if ( (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,0)', $lock ) ) !== '1' ) { return; }
        try {
            if ( self::delivery( $id ) === [] ) { self::write_delivery( $id, [ 'state' => 'created', 'ever_sent' => false, 'ever_uncertain' => false, 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ] ); }
        } finally { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); }
    }

    private static function lock_name( int $id ): string {
        global $wpdb;
        return 'sn_invoice_sms_' . substr( hash( 'sha256', DB_NAME . ':' . $wpdb->prefix . ':' . $id ), 0, 44 );
    }

    private static function write_delivery( int $id, array $value ): bool {
        // First field is deliberately stable for the bounded backlog query.
        $eligible = in_array( $value['state'], [ 'blocked', 'failed' ], true ) && empty( $value['ever_sent'] ) && empty( $value['ever_uncertain'] );
        $record = [ 'backlog_eligible' => $eligible ] + $value;
        $json = wp_json_encode( $record );
        if ( ! is_string( $json ) ) { return false; }
        update_option( self::DELIVERY . $id, $json, false );
        global $wpdb;
        $stored = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s", self::DELIVERY . $id ) );
        return $wpdb->last_error === '' && $stored === $json;
    }

    private static function invoice( int $id ): ?object {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}sn_invoices WHERE id=%d", $id ) );
        return is_object( $row ) ? $row : null;
    }

    private static function products( object $invoice ): array {
        global $wpdb;
        $ids = (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT product_id FROM {$wpdb->prefix}sn_invoice_items WHERE invoice_id=%d AND product_id>0", (int) $invoice->id ) );
        if ( $wpdb->last_error !== '' ) { return []; }
        if ( (int) ( $invoice->product_id ?? 0 ) > 0 ) { $ids[] = (int) $invoice->product_id; }
        return array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
    }

    public static function allowed( int $id ): bool {
        $invoice = self::invoice( $id );
        if ( ! $invoice ) { return false; }
        $products = self::products( $invoice );
        if ( ! $products ) { return false; }
        foreach ( $products as $product ) { if ( ! self::policy( $product )['enabled'] ) { return false; } }
        return true;
    }

    private static function previous_eligible( object $invoice, array $delivery ): bool {
        if ( empty( $delivery['backlog_eligible'] ) || ! in_array( $delivery['state'] ?? '', [ 'blocked', 'failed' ], true )
            || ! empty( $delivery['ever_sent'] ) || ! empty( $delivery['ever_uncertain'] )
            || ! in_array( (string) ( $invoice->status ?? '' ), [ 'pre_invoice', 'pending', 'pending_payment' ], true )
            || (float) ( $invoice->paid_total_amount ?? 0 ) > 0 || (float) ( $invoice->paid_amount ?? 0 ) > 0
            || in_array( (string) ( $invoice->payment_status ?? '' ), [ 'paid', 'approved', 'verified', 'completed' ], true ) ) { return false; }
        global $wpdb;
        $payment = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}sn_payments WHERE invoice_id=%d AND (status IN ('paid','approved','verified','reconcile_error') OR (status='pending' AND COALESCE(authority,'')='')) LIMIT 1", (int) $invoice->id ) );
        return $wpdb->last_error === '' && ! $payment;
    }

    /** Known invoice only. No phone, customer text, token or provider payload is stored. */
    public static function dispatch( int $id, callable $send, ?callable $uncertain = null, bool $previous = false ): array {
        $result = [ 'sent' => false, 'state' => 'failed', 'message' => '' ];
        if ( isset( self::$active[ $id ] ) ) { $result['sent'] = (bool) $send(); return $result; }
        global $wpdb;
        $lock = self::lock_name( $id );
        if ( (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,0)', $lock ) ) !== '1' ) { $result['message'] = 'ارسال پیامک همین پیش‌فاکتور در حال پردازش است؛ کمی صبر کنید.'; return $result + [ 'busy' => true ]; }
        try {
            $invoice = self::invoice( $id );
            if ( ! $invoice ) { $result['message'] = 'پیش‌فاکتور برای ارسال پیامک یافت نشد.'; return $result; }
            $record = self::delivery( $id );
            if ( ! empty( $record['storage_error'] ) ) { $result['message'] = 'خواندن سابقهٔ پیامک ممکن نیست؛ ارسال انجام نشد.'; return $result; }
            $ever_sent = ! empty( $record['ever_sent'] );
            $ever_uncertain = ! $record || ! empty( $record['ever_uncertain'] ) || in_array( $record['state'] ?? '', [ 'dispatching', 'uncertain' ], true );
            $record = [ 'state' => 'blocked', 'ever_sent' => $ever_sent, 'ever_uncertain' => $ever_uncertain, 'actor_user_id' => get_current_user_id(), 'attempt' => wp_generate_uuid4(), 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ];
            if ( ! self::allowed( $id ) ) {
                self::write_delivery( $id, $record );
                $result['state'] = 'blocked'; $result['message'] = 'ارسال پیامک پیش‌فاکتور در تنظیمات یکی از محصولات خاموش است.'; return $result;
            }
            if ( $previous && ! self::previous_eligible( $invoice, self::delivery( $id ) ) ) { $result['state'] = 'skipped'; $result['message'] = 'این پیش‌فاکتور دیگر واجد شرایط ارسال قبلی نیست.'; return $result; }
            $record['state'] = 'dispatching';
            if ( ! self::write_delivery( $id, $record ) ) { $result['message'] = 'ثبت امن سابقهٔ پیامک ممکن نیست؛ ارسال انجام نشد.'; return $result; }
            self::$active[ $id ] = true;
            try {
                $result['sent'] = (bool) $send();
                $is_uncertain = ! $result['sent'] && ( $uncertain ? (bool) $uncertain() : true );
            } catch ( Throwable $error ) { $result['sent'] = false; $is_uncertain = true; }
            finally { unset( self::$active[ $id ] ); }
            $record['state'] = $result['sent'] ? 'sent' : ( $is_uncertain ? 'uncertain' : 'failed' );
            $record['ever_sent'] = $ever_sent || $result['sent'];
            $record['ever_uncertain'] = $ever_uncertain || $is_uncertain;
            $result['state'] = $record['state'];
            // A failed final write leaves the durable dispatching claim in place;
            // historical sends can never infer "unsent" from that ambiguity.
            self::write_delivery( $id, $record );
            return $result;
        } finally { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); }
    }

    private static function authorize(): int {
        if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' || ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) { wp_send_json_error( [ 'message' => 'دسترسی مجاز نیست.' ], 403 ); }
        if ( ! check_ajax_referer( 'sn_product_invoice_sms', 'nonce', false ) ) { wp_send_json_error( [ 'message' => 'نشست معتبر نیست؛ صفحه را تازه‌سازی کنید.' ], 403 ); }
        $raw = $_POST['product_id'] ?? null;
        $id = is_string( $raw ) && preg_match( '/^[1-9][0-9]{0,9}$/D', $raw ) ? (int) $raw : 0;
        if ( ! $id || get_post_type( $id ) !== 'product' || ! current_user_can( 'edit_post', $id ) ) { wp_send_json_error( [ 'message' => 'محصول معتبر نیست یا در دسترس شما نیست.' ], 403 ); }
        $policy = self::policy( $id );
        if ( ! $policy['enabled'] || ! $policy['previous'] ) { wp_send_json_error( [ 'message' => 'ابتدا پیامک و امکان ارسال قبلی را در تنظیمات همین محصول ذخیره کنید.' ], 409 ); }
        return $id;
    }

    public static function preview(): void {
        $id = self::authorize(); global $wpdb;
        $after = $_POST['after'] ?? '0';
        if ( ! is_string( $after ) || ! preg_match( '/^[0-9]{1,12}$/D', $after ) ) { wp_send_json_error( [ 'message' => 'صفحهٔ بررسی معتبر نیست.' ], 400 ); }
        $names = $wpdb->esc_like( '{"backlog_eligible":true,' ) . '%';
        $rows = (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT i.id FROM {$wpdb->prefix}sn_invoices i INNER JOIN {$wpdb->options} o ON o.option_name=CONCAT(%s,i.id) WHERE i.id>%d AND o.option_value LIKE %s AND (i.product_id=%d OR EXISTS (SELECT 1 FROM {$wpdb->prefix}sn_invoice_items x WHERE x.invoice_id=i.id AND x.product_id=%d)) ORDER BY i.id LIMIT 201", self::DELIVERY, (int) $after, $names, $id, $id ) );
        $more = count( $rows ) > 200; $rows = array_slice( $rows, 0, 200 ); $ids = [];
        foreach ( $rows as $invoice_id ) {
            $invoice = self::invoice( (int) $invoice_id );
            if ( $invoice && self::allowed( (int) $invoice_id ) && self::previous_eligible( $invoice, self::delivery( (int) $invoice_id ) ) ) { $ids[] = (int) $invoice_id; }
        }
        $token = wp_generate_uuid4();
        $batch = [ 'actor' => get_current_user_id(), 'product' => $id, 'revision' => self::policy( $id )['revision'], 'ids' => $ids, 'index' => 0, 'sent' => 0, 'skipped' => 0, 'failed' => 0 ];
        if ( $ids && ! set_transient( 'sn_product_sms_' . $token, $batch, 15 * MINUTE_IN_SECONDS ) ) { wp_send_json_error( [ 'message' => 'ثبت فهرست ارسال انجام نشد؛ دوباره بررسی کنید.' ], 500 ); }
        wp_send_json_success( [ 'count' => count( $ids ), 'token' => $ids ? $token : '', 'more' => $more, 'after' => $rows ? (int) end( $rows ) : (int) $after,
            'message' => 'فقط موارد باز و پرداخت‌نشده با سابقهٔ قطعیِ ارسال‌نشدن بررسی شدند. سوابق نامشخص، پیامک پذیرفته‌شده و نتیجهٔ مبهم وارد ارسال نمی‌شوند.' ] );
    }

    public static function send_previous(): void {
        $id = self::authorize(); global $wpdb;
        $token = $_POST['token'] ?? null;
        if ( ! is_string( $token ) || ! preg_match( '/^[a-f0-9-]{36}$/D', $token ) || ( $_POST['confirmation'] ?? '' ) !== 'SEND_PREVIOUS' ) { wp_send_json_error( [ 'message' => 'تأیید ارسال معتبر نیست.' ], 400 ); }
        $key = 'sn_product_sms_' . $token; $lock = 'sn_sms_batch_' . $token;
        if ( (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,0)', $lock ) ) !== '1' ) { wp_send_json_error( [ 'message' => 'ارسال همین فهرست در حال انجام است.' ], 409 ); }
        $response = null; $error = '';
        try {
            $batch = get_transient( $key );
            if ( ! is_array( $batch ) || $batch['actor'] !== get_current_user_id() || $batch['product'] !== $id || ! hash_equals( $batch['revision'], self::policy( $id )['revision'] ) ) { $error = 'فهرست منقضی یا تنظیمات تغییر کرده است؛ دوباره بررسی کنید.'; }
            else {
                $position = (int) $batch['index'];
                if ( $position < count( $batch['ids'] ) ) {
                    $invoice_id = (int) $batch['ids'][ $position ]; $invoice = self::invoice( $invoice_id );
                    if ( ! $invoice || ! in_array( $id, self::products( $invoice ), true ) || ! self::allowed( $invoice_id ) || ! self::previous_eligible( $invoice, self::delivery( $invoice_id ) ) ) { $batch['skipped']++; }
                    else {
                        $plugin = sn_bootstrap_plugin_instance( false );
                        if ( $plugin->send_product_invoice_sms_backlog( $invoice_id ) ) { $batch['sent']++; } else { $batch['failed']++; }
                    }
                    $batch['index']++;
                }
                $done = $batch['index'] >= count( $batch['ids'] );
                $saved = set_transient( $key, $batch, 15 * MINUTE_IN_SECONDS );
                if ( ! $saved && get_transient( $key ) !== $batch ) { $error = 'ثبت پیشرفت تأیید نشد؛ ارسال متوقف است. پیش از ادامه، دوباره فهرست را بررسی کنید.'; }
                else { $response = [ 'done' => $done, 'processed' => $batch['index'], 'total' => count( $batch['ids'] ), 'sent' => $batch['sent'], 'skipped' => $batch['skipped'], 'failed' => $batch['failed'] ]; }
            }
        } finally { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); }
        if ( $error !== '' ) { wp_send_json_error( [ 'message' => $error ], 409 ); }
        wp_send_json_success( $response );
    }
}
