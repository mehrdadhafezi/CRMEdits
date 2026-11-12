<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/** Import upgrade rules without creating products or changing historical purchases. */
final class SN_Upgrade_Importer {
    public static function register(): void {
        add_action( 'admin_menu', [ __CLASS__, 'menu' ] );
        add_action( 'admin_post_sn_upgrade_csv_import', [ __CLASS__, 'handle' ] );
        add_action( 'admin_post_sn_upgrade_rollback', [ __CLASS__, 'rollback' ] );
    }
    public static function menu(): void {
        add_submenu_page( 'woocommerce', 'درون‌ریزی افزایشی کارت‌ها', 'درون‌ریزی افزایشی کارت‌ها', 'manage_woocommerce', 'sn-upgrade-import', [ __CLASS__, 'page' ] );
    }
    private static function resolve( string $sku, string $id ): int {
        $sku = trim( $sku ); $id = trim( $id );
        if ( $sku !== '' ) {
            if ( ! function_exists( 'wc_get_product_id_by_sku' ) ) { return 0; }
            $found = (int) wc_get_product_id_by_sku( $sku );
            if ( $id !== '' && $found !== absint( $id ) ) { return 0; }
            return $found;
        }
        return ctype_digit( $id ) ? absint( $id ) : 0;
    }
    private static function valid_type( int $id ): bool {
        if ( $id < 1 || get_post_type( $id ) !== 'product' || get_post_status( $id ) === 'trash' ) { return false; }
        return class_exists( 'SN_Seller_Flow' ) && SN_Seller_Flow::instance()->product_type( $id ) === 'product_star';
    }
    private static function product_diagnostic( string $side, string $sku, string $raw_id, int $resolved ): string {
        $label = $side === 'source' ? 'مبدأ' : 'مقصد';
        $sku = trim( $sku ); $raw_id = trim( $raw_id );
        if ( $resolved < 1 ) {
            if ( $sku !== '' ) {
                $found = function_exists( 'wc_get_product_id_by_sku' ) ? (int) wc_get_product_id_by_sku( $sku ) : 0;
                return $label . ': SKU «' . $sku . '» ' . ( $found ? 'با شناسه درج‌شده در CSV تطابق ندارد' : 'در ووکامرس یافت نشد؛ ابتدا محصول را وارد کنید' );
            }
            return $label . ': شناسه محصول خالی یا نامعتبر است';
        }
        if ( get_post_type( $resolved ) !== 'product' ) { return $label . ': شناسه ' . $resolved . ' از نوع محصول ووکامرس نیست'; }
        if ( get_post_status( $resolved ) === 'trash' ) { return $label . ': محصول ' . $resolved . ' در زباله‌دان است'; }
        if ( ! class_exists( 'SN_Seller_Flow' ) ) { return $label . ': ماژول تشخیص نوع محصول در دسترس نیست'; }
        $actual = SN_Seller_Flow::instance()->product_type( $resolved );
        if ( $actual !== 'product_star' ) {
            $meta = (string) get_post_meta( $resolved, '_sn_sales_product_type', true );
            return $label . ': محصول ' . $resolved . ' نوع «' . $actual . '» تشخیص داده شد (meta: ' . ( $meta !== '' ? $meta : 'خالی' ) . ')؛ باید product_star باشد';
        }
        return '';
    }
    public static function page(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( 'دسترسی غیرمجاز' ); }
        echo '<div class="wrap"><h1>درون‌ریزی گروهی افزایشی کارت‌ها</h1><p>ابتدا محصولات را در ووکامرس ایجاد کنید. سپس CSV قوانین را بارگذاری کنید. هیچ محصول یا فاکتوری در این صفحه ساخته یا اصلاح نمی‌شود.</p>';
        echo '<p>ستون‌های CSV: <code>source_sku,source_id,target_sku,target_id,upgrade_amount</code>. مبلغ به واحد پول تنظیم‌شده برای فرآیند افزایشی CRM است؛ در این سایت قبل از ثبت آن را با یک نمونه کنترل کنید.</p>';
        echo '<p>هر ردیف یک اتصال محصول مبدا به محصول مقصد است. مقدار SKU یا ID برای هر طرف کافی است. اگر هر دو وارد شوند باید دقیقاً با یکدیگر تطابق داشته باشند. ایمپورت دوباره مبلغ همان رابطه را به‌روزرسانی می‌کند؛ سایر روابط حذف نمی‌شوند.</p>';
        if ( isset( $_GET['sn_upgrade_report'] ) ) {
            $token = sanitize_text_field( wp_unslash( $_GET['sn_upgrade_report'] ) );
            $payload = get_transient( 'sn_upgrade_report_' . get_current_user_id() . '_' . $token );
            if ( is_array( $payload ) ) {
                echo '<h2>نتیجه بررسی</h2><p>' . esc_html( $payload['summary'] ) . '</p><ol>';
                foreach ( array_slice( $payload['messages'], 0, 100 ) as $msg ) { echo '<li>' . esc_html( $msg ) . '</li>'; }
                echo '</ol>';
            }
        }
        $backup = get_option( 'sn_upgrade_import_latest_backup', [] );
        if ( is_array( $backup ) && ! empty( $backup['id'] ) ) {
            echo '<hr><h2>بازیابی آخرین ایمپورت</h2><p>فقط آخرین ایمپورت قابل بازگشت است و اگر قوانین پس از آن تغییر کرده باشند، بازگشت مسدود می‌شود.</p>';
            echo '<form action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post">';
            wp_nonce_field( 'sn_upgrade_rollback' );
            echo '<input type="hidden" name="action" value="sn_upgrade_rollback"><button type="submit" class="button button-secondary" onclick="return confirm(\'بازگرداندن آخرین ایمپورت؟\')">بازگرداندن آخرین ایمپورت</button></form>';
        }
        echo '<form action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post" enctype="multipart/form-data">';
        wp_nonce_field( 'sn_upgrade_csv_import' );
        echo '<input type="hidden" name="action" value="sn_upgrade_csv_import"><input type="file" name="upgrade_csv" accept=".csv,text/csv" required><p><label><input type="checkbox" name="apply_changes" value="1"> اعمال نهایی تغییرات (بدون انتخاب این گزینه فقط پیش‌نمایش و اعتبارسنجی انجام می‌شود)</label></p>';
        submit_button( 'بررسی فایل / اجرای ایمپورت' );echo '</form>';
        // Historical cards contain their own snapshots. CSV import alone never mutates them.
        // Reuse the existing, explicitly confirmed per-product sync workflow after import.
        if ( current_user_can( 'manage_options' ) && class_exists( 'SN_Product_Card_Sync' ) ) {
            global $wpdb;
            $rules_table = $wpdb->prefix . 'sn_project_card_upgrade_rules';
            $sources = $wpdb->get_col( "SELECT DISTINCT source_product_id FROM {$rules_table} WHERE is_active=1 ORDER BY source_product_id ASC LIMIT 500" );
            $sources = array_values( array_filter( array_map( 'absint', (array) $sources ), [ __CLASS__, 'valid_type' ] ) );
            $selected = isset( $_GET['sn_sync_product'] ) ? absint( $_GET['sn_sync_product'] ) : 0;
            echo '<hr><h2>اعمال تنظیمات روی کارت‌های قبلی</h2>';
            echo '<p>پس از ثبت موفق CSV، کارت اصلی را انتخاب کنید و تنظیمات افزایشی جدید را با همان ابزار ویرایش محصول روی کارت‌های واجد شرایط قبلی اعمال کنید. این عملیات مستقل از ایمپورت، اختیاری و نیازمند پیش‌نمایش و تأیید جداگانه است. تغییرات Snapshot کارت‌ها با بازیابی CSV برگردانده نمی‌شوند.</p>';
            echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
            echo '<input type="hidden" name="page" value="sn-upgrade-import"><label for="sn-sync-product">کارت اصلی: </label> <select id="sn-sync-product" name="sn_sync_product"><option value="0">انتخاب کارت</option>';
            foreach ( $sources as $source_id ) {
                echo '<option value="' . esc_attr( (string) $source_id ) . '" ' . selected( $selected, $source_id, false ) . '>' . esc_html( get_the_title( $source_id ) . ' (#' . $source_id . ')' ) . '</option>';
            }
            echo '</select> <button type="submit" class="button">نمایش تنظیمات</button></form>';
            if ( $selected && in_array( $selected, $sources, true ) && current_user_can( 'edit_post', $selected ) ) {
                echo '<p><strong>' . esc_html( get_the_title( $selected ) ) . '</strong> — فقط گزینه «حالت‌های افزایشی» به‌صورت پیش‌فرض انتخاب است. برای تغییر اعتبار پایه باید آن را جداگانه انتخاب کنید.</p>';
                SN_Product_Card_Sync::instance()->render_controls( $selected, true );
            } elseif ( $selected ) {
                echo '<p class="notice notice-warning" style="padding:10px">کارت انتخاب‌شده معتبر یا مجاز نیست.</p>';
            }
        }
        echo '</div>';
    }
    /** Revert only the last batch and only when every imported row is unchanged. */
    public static function rollback(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) || ! check_admin_referer( 'sn_upgrade_rollback', '_wpnonce', false ) ) { wp_die( 'درخواست غیرمجاز', '', [ 'response' => 403 ] ); }
        global $wpdb;
        $table = $wpdb->prefix . 'sn_project_card_upgrade_rules';
        $backup = get_option( 'sn_upgrade_import_latest_backup', [] );
        $messages = []; $ok = false;
        if ( is_array( $backup ) && ! empty( $backup['before'] ) && count( $backup['before'] ) === count( $backup['after'] ?? [] ) && false !== $wpdb->query( 'START TRANSACTION' ) ) {
            $ok = true;
            foreach ( $backup['before'] as $i => $old ) {
                $current = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE source_product_id=%d AND target_product_id=%d FOR UPDATE", $old['source'], $old['target'] ), ARRAY_A );
                if ( $current != $backup['after'][$i] ) { $ok = false; break; }
            }
            if ( $ok ) {
                foreach ( $backup['before'] as $old ) {
                    if ( is_array( $old['row'] ) ) {
                        $prior = $old['row'];
                        $id = (int) $prior['id']; unset( $prior['id'] );
                        $res = $wpdb->update( $table, $prior, [ 'id' => $id ] );
                    } else {
                        $res = $wpdb->delete( $table, [ 'source_product_id' => $old['source'], 'target_product_id' => $old['target'] ], [ '%d', '%d' ] );
                    }
                    if ( $res === false ) { $ok = false; break; }
                }
            }
            if ( $ok ) { $ok = false !== $wpdb->query( 'COMMIT' ); }
            if ( ! $ok ) { $wpdb->query( 'ROLLBACK' ); }
        }
        if ( $ok ) { delete_option( 'sn_upgrade_import_latest_backup' ); }
        $messages[] = $ok ? 'آخرین ایمپورت با موفقیت بازگردانده شد.' : 'بازیابی انجام نشد: داده‌ها تغییر کرده‌اند، بکاپ موجود نیست یا دیتابیس خطا داده است.';
        $token = wp_generate_password( 12, false, false );
        set_transient( 'sn_upgrade_report_' . get_current_user_id() . '_' . $token, [ 'summary' => $messages[0], 'messages' => [] ], 10 * MINUTE_IN_SECONDS );
        wp_safe_redirect( add_query_arg( [ 'page' => 'sn-upgrade-import', 'sn_upgrade_report' => $token ], admin_url( 'admin.php' ) ) ); exit;
    }
    public static function handle(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) || ! check_admin_referer( 'sn_upgrade_csv_import', '_wpnonce', false ) ) { wp_die( 'درخواست غیرمجاز', '', [ 'response' => 403 ] ); }
        $apply = isset( $_POST['apply_changes'] ) && wp_unslash( $_POST['apply_changes'] ) === '1';
        $msgs = []; $summary = ''; $rows = []; $errors = false;
        $upload = $_FILES['upgrade_csv'] ?? null;
        if ( ! is_array( $upload ) || (int) ( $upload['error'] ?? 1 ) !== UPLOAD_ERR_OK || (int) ( $upload['size'] ?? 0 ) > 1048576 || ! is_uploaded_file( $upload['tmp_name'] ?? '' ) || strtolower( pathinfo( (string) ( $upload['name'] ?? '' ), PATHINFO_EXTENSION ) ) !== 'csv' ) {
            $msgs[] = 'فایل CSV معتبر با حجم کمتر از یک مگابایت ارسال کنید.'; $errors = true;
        } else {
            $fp = fopen( $upload['tmp_name'], 'rb' );
            $header = is_resource( $fp ) ? fgetcsv( $fp ) : false;
            if ( ! is_resource( $fp ) ) { $msgs[] = 'امکان بازکردن فایل آپلودشده وجود ندارد.'; $errors = true; }
            if ( is_array( $header ) ) { $header = array_map( static fn($x) => trim( preg_replace( '/^\xEF\xBB\xBF/', '', (string) $x ) ), $header ); }
            $required = [ 'source_sku','source_id','target_sku','target_id','upgrade_amount' ];
            if ( ! $header || array_diff( $required, $header ) || count( array_unique( $header ) ) !== count( $header ) ) { $msgs[] = 'ستون‌های ضروری CSV کامل نیستند.'; $errors = true; }
            else {
                $seen = []; $line = 1;
                while ( ( $record = fgetcsv( $fp ) ) !== false ) {
                    $line++;
                    if ( $line > 1002 ) { $msgs[] = 'حداکثر ۱۰۰۰ ردیف پذیرفته می‌شود.'; $errors = true; break; }
                    if ( count( $record ) !== count( $header ) ) { $msgs[] = "ردیف {$line}: تعداد ستون‌ها اشتباه است."; $errors = true; continue; }
                    $data = array_combine( $header, $record );
                    if ( ! count( array_filter( $data, static fn($v) => trim( (string) $v ) !== '' ) ) ) { continue; }
                    $source = self::resolve( (string) $data['source_sku'], (string) $data['source_id'] );
                    $target = self::resolve( (string) $data['target_sku'], (string) $data['target_id'] );
                    $raw = class_exists( 'SN_Helpers' ) ? SN_Helpers::to_english_nums( trim( (string) $data['upgrade_amount'] ) ) : trim( (string) $data['upgrade_amount'] );
                    $raw = str_replace( [ ',', '٬', '،', ' ' ], '', $raw );
                    $issues = [];
                    if ( ! self::valid_type( $source ) ) { $issues[] = self::product_diagnostic( 'source', (string) $data['source_sku'], (string) $data['source_id'], $source ); }
                    if ( ! self::valid_type( $target ) ) { $issues[] = self::product_diagnostic( 'target', (string) $data['target_sku'], (string) $data['target_id'], $target ); }
                    if ( $source > 0 && $source === $target ) { $issues[] = 'مبدأ و مقصد نمی‌توانند یکی باشند'; }
                    if ( ! preg_match( '/^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,2})?$/D', $raw ) ) { $issues[] = 'مبلغ «' . $raw . '» نامعتبر است'; }
                    if ( $issues ) {
                        $msgs[] = "ردیف {$line}: " . implode( ' | ', $issues ); $errors = true; continue;
                    }
                    $key = $source . ':' . $target;
                    if ( isset( $seen[$key] ) ) { $msgs[] = "ردیف {$line}: رابطه تکراری در همین فایل."; $errors = true; continue; }
                    $seen[$key] = true;
                    $rows[] = [ 'source' => $source, 'target' => $target, 'amount' => $raw, 'line' => $line ];
                    $msgs[] = "ردیف {$line}: {$source} → {$target} / مبلغ {$raw} معتبر است.";
                }
            }
            if ( is_resource( $fp ) ) { fclose( $fp ); }
        }
        if ( ! $errors && $apply && ! $rows ) { $errors = true; $msgs[] = 'فایل فاقد ردیف معتبر است.'; }
        if ( ! $errors && $apply && $rows ) {
            global $wpdb;
            $table = $wpdb->prefix . 'sn_project_card_upgrade_rules';
            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) { $errors = true; $msgs[] = 'جدول قوانین افزایشی موجود نیست.'; }
            elseif ( false === $wpdb->query( 'START TRANSACTION' ) ) { $errors = true; $msgs[] = 'شروع تراکنش امکان‌پذیر نیست.'; }
            else {
                $now = current_time( 'mysql' );
                $before = [];
                foreach ( $rows as $row ) {
                    $existing_row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE source_product_id=%d AND target_product_id=%d FOR UPDATE", $row['source'], $row['target'] ), ARRAY_A );
                    $before[] = [ 'source' => $row['source'], 'target' => $row['target'], 'row' => $existing_row ];
                }
                foreach ( $rows as $row ) {
                    $data = [ 'upgrade_amount' => $row['amount'], 'is_active' => 1, 'updated_at' => $now ];
                    $existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE source_product_id=%d AND target_product_id=%d", $row['source'], $row['target'] ) );
                    if ( $existing ) { $ok = $wpdb->update( $table, $data, [ 'id' => (int) $existing ] ); }
                    else { $ok = $wpdb->insert( $table, array_merge( $data, [ 'source_product_id' => $row['source'], 'target_product_id' => $row['target'], 'created_by' => get_current_user_id(), 'created_at' => $now ] ) ); }
                    if ( false === $ok ) { $errors = true; $msgs[] = 'خطای پایگاه‌داده در ردیف ' . $row['line']; break; }
                }
                // Save rollback journal only when DB operations succeeded. Do not overwrite an old journal on failure.
                $after = [];
                if ( ! $errors ) {
                    foreach ( $rows as $row ) {
                        $after[] = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE source_product_id=%d AND target_product_id=%d", $row['source'], $row['target'] ), ARRAY_A );
                    }
                }
                if ( $errors || false === $wpdb->query( 'COMMIT' ) ) {
                    if ( ! $errors ) { $errors = true; $msgs[] = 'ثبت نهایی تراکنش ناموفق بود.'; }
                    $wpdb->query( 'ROLLBACK' );
                } else {
                    update_option( 'sn_upgrade_import_latest_backup', [ 'id' => wp_generate_uuid4(), 'created_at' => $now, 'before' => $before, 'after' => $after ], false );
                }
            }
        }
        $summary = $errors ? 'خطا: هیچ تغییری از این فایل نباید اعمال شده باشد؛ خطاها را اصلاح و مجدداً آزمایش کنید.' : ( $apply ? 'ایمپورت نهایی ' . count($rows) . ' رابطه تکمیل شد.' : 'پیش‌نمایش موفق ' . count($rows) . ' رابطه؛ هیچ تغییری ثبت نشد.' );
        $token = wp_generate_password( 12, false, false );
        set_transient( 'sn_upgrade_report_' . get_current_user_id() . '_' . $token, [ 'summary' => $summary, 'messages' => $msgs ], 10 * MINUTE_IN_SECONDS );
        wp_safe_redirect( add_query_arg( [ 'page' => 'sn-upgrade-import', 'sn_upgrade_report' => $token ], admin_url( 'admin.php' ) ) ); exit;
    }
}
