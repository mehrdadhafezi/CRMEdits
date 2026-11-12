<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Provision and present the existing HR workflows on a standalone front page. */
final class SN_HR_Front_Panel {
    private const SETUP_VERSION = '2.0.170';

    public static function register_hooks(): void {
        add_action( 'admin_init', [ self::class, 'maybe_setup_page' ], 3 );
        add_action( 'admin_menu', [ self::class, 'admin_menu' ], 35 );
        add_action( 'wp_enqueue_scripts', [ self::class, 'maybe_enqueue_assets' ], 35 );
        add_action( 'template_redirect', [ self::class, 'protect_response' ], 1 );
    }

    private static function is_hr_page( $post ): bool {
        if ( ! $post instanceof WP_Post || $post->post_type !== 'page' || ! in_array( $post->post_status, [ 'publish', 'private', 'draft', 'pending', 'future' ], true ) ) { return false; }
        return has_shortcode( (string) $post->post_content, 'sn_hr_panel' )
            || strpos( (string) get_post_meta( $post->ID, '_elementor_data', true ), 'sn_hr_panel' ) !== false;
    }

    /** Reuse configured/custom pages; never append to or rewrite an unrelated page. */
    public static function provision_page(): int {
        $ids = get_option( 'sn_portal_page_ids', [] );
        $ids = is_array( $ids ) ? $ids : [];
        $page_id = 0;
        foreach ( [ absint( $ids['hr'] ?? 0 ), absint( get_option( 'sn_hr_panel_page_id', 0 ) ) ] as $candidate ) {
            if ( $candidate && self::is_hr_page( get_post( $candidate ) ) ) { $page_id = $candidate; break; }
        }
        if ( ! $page_id ) {
            $post = get_page_by_path( 'crm-hr', OBJECT, 'page' );
            if ( self::is_hr_page( $post ) ) { $page_id = (int) $post->ID; }
        }
        if ( ! $page_id ) {
            foreach ( get_posts( [ 'post_type' => 'page', 'post_status' => [ 'publish', 'private', 'draft', 'pending', 'future' ], 's' => 'sn_hr_panel', 'numberposts' => 50, 'orderby' => 'ID', 'order' => 'ASC' ] ) as $post ) {
                if ( self::is_hr_page( $post ) ) { $page_id = (int) $post->ID; break; }
            }
        }
        if ( ! $page_id ) {
            // WordPress assigns a unique slug if crm-hr belongs to another page.
            $created = wp_insert_post( [
                'post_type' => 'page', 'post_status' => 'publish',
                'post_title' => 'پنل منابع انسانی', 'post_name' => 'crm-hr',
                'post_content' => '[sn_hr_panel]', 'comment_status' => 'closed', 'ping_status' => 'closed',
            ], true );
            if ( is_wp_error( $created ) || ! $created ) { return 0; }
            $page_id = (int) $created;
            update_post_meta( $page_id, '_sn_system_page', '1' );
            if ( defined( 'ELEMENTOR_VERSION' ) ) {
                update_post_meta( $page_id, '_wp_page_template', 'elementor_canvas' );
                update_post_meta( $page_id, '_elementor_page_settings', [ 'page_layout' => 'elementor_canvas' ] );
            }
        }
        $ids['hr'] = $page_id;
        update_option( 'sn_hr_panel_page_id', $page_id, false );
        update_option( 'sn_portal_page_ids', $ids, false );
        return $page_id;
    }

    public static function maybe_setup_page(): void {
        if ( ! current_user_can( 'manage_options' ) || wp_doing_ajax() || get_option( 'sn_hr_front_setup_version' ) === self::SETUP_VERSION ) { return; }
        $lock = 'sn_hr_front_setup_lock';
        if ( ! add_option( $lock, time(), '', false ) ) {
            if ( (int) get_option( $lock, 0 ) > time() - 120 ) { return; }
            delete_option( $lock );
            if ( ! add_option( $lock, time(), '', false ) ) { return; }
        }
        try {
            if ( get_option( 'sn_hr_front_setup_version' ) !== self::SETUP_VERSION && self::provision_page() > 0 ) {
                update_option( 'sn_hr_front_setup_version', self::SETUP_VERSION, false );
            }
        } finally { delete_option( $lock ); }
    }

    public static function page_url(): string {
        $ids = get_option( 'sn_portal_page_ids', [] );
        $page_id = absint( is_array( $ids ) ? ( $ids['hr'] ?? 0 ) : 0 ) ?: absint( get_option( 'sn_hr_panel_page_id', 0 ) );
        return $page_id && self::is_hr_page( get_post( $page_id ) ) ? (string) get_permalink( $page_id ) : '';
    }

    private static function is_front_context(): bool {
        if ( is_admin() ) { return false; }
        global $post;
        return self::is_hr_page( $post );
    }

    public static function enqueue_assets(): void {
        wp_enqueue_style( 'sn-hr-front', SN_PLUGIN_URL . 'assets/css/hr-front.css', [ 'sn-public', 'sn-public-performance' ], SN_VERSION );
        if ( class_exists( 'SN_HR_Workforce_Filters' ) ) { SN_HR_Workforce_Filters::assets(); }
    }

    public static function maybe_enqueue_assets(): void {
        if ( self::is_front_context() ) { self::enqueue_assets(); return; }
        // The unified portal can resolve to HR only when its shortcode renders.
        // Queue the scoped CSS before wp_head there too, avoiding late CSS output.
        if ( is_admin() ) { return; }
        global $post;
        if ( $post instanceof WP_Post && (
            has_shortcode( (string) $post->post_content, 'sn_my_panel' )
            || strpos( (string) get_post_meta( $post->ID, '_elementor_data', true ), 'sn_my_panel' ) !== false
        ) ) { self::enqueue_assets(); }
    }

    public static function protect_response(): void {
        if ( ! self::is_front_context() ) { return; }
        if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); }
        nocache_headers();
        add_filter( 'wp_robots', static function ( array $robots ): array {
            $robots['noindex'] = true; $robots['nofollow'] = true; $robots['noarchive'] = true;
            return $robots;
        } );
    }

    public static function render_intro( array $tabs, string $active_tab ): string {
        if ( is_admin() ) { return ''; }
        $descriptions = [
            'hr-workforce' => 'جستجو، ویرایش اطلاعات نیروها و مدیریت دسترسی و همکاری.',
            'hr-manual-add' => 'ثبت نیروی جدید همراه با سمت، سطح و مدیر مستقیم.',
            'hr-csv' => 'ورود گروهی نیروها از فایل و دریافت خروجی اطلاعات کاربران.',
            'hr-bulk' => 'اعمال تغییرات یکسان روی نیروهای انتخاب‌شده.',
            'hr-change-requests' => 'بررسی درخواست‌های جابجایی و قطع همکاری و پیگیری نتیجه.',
            'hr-compensation' => 'مدیریت حقوق، قوانین پورسانت و تاریخ اثرگذاری تغییرات.',
            'hr-positions' => 'تعریف و مدیریت سمت‌ها و سطح‌های سازمان.',
            'hr-extra' => 'تکمیل پروفایل‌ها و بررسی نگاشت نقش‌های منابع انسانی.',
            'hr-overview' => 'نمای کلی نیروها، سمت‌ها و وضعیت تکمیل اطلاعات.',
            'hr-structure' => 'مدیریت دپارتمان‌ها، واحدها و تیم‌ها.',
            'hr-hierarchy' => 'مدیریت ارتباط نیروها با مدیران مستقیم و زیرمجموعه‌ها.',
            'hr-logs' => 'مشاهده سوابق تغییرات و عملیات منابع انسانی.',
        ];
        $base = remove_query_arg( [ 'sn_hr_page', 'sn_hr_selected', 'sn_hr_q', 'sn_msg', 'sn_ui_active_tab' ] );
        ob_start(); ?>
        <div class="sn-hr-front-context">
            <div><span class="sn-hr-front-eyebrow">فضای کار منابع انسانی</span><h3><?php echo esc_html( $tabs[ $active_tab ] ?? 'منابع انسانی' ); ?></h3><p><?php echo esc_html( $descriptions[ $active_tab ] ?? '' ); ?></p></div>
            <div class="sn-hr-front-shortcuts" aria-label="دسترسی سریع منابع انسانی">
                <a class="sn-btn sn-btn-primary" data-sn-open-tab="hr-manual-add" href="<?php echo esc_url( add_query_arg( 'sn_hr_tab', 'hr-manual-add', $base ) . '#hr-manual-add' ); ?>">افزودن نیرو</a>
                <a class="sn-btn sn-btn-secondary" data-sn-open-tab="hr-csv" href="<?php echo esc_url( add_query_arg( 'sn_hr_tab', 'hr-csv', $base ) . '#hr-csv' ); ?>">ورودی / خروجی</a>
            </div>
        </div>
        <?php return (string) ob_get_clean();
    }

    public static function admin_menu(): void {
        add_submenu_page( 'sn-dashboard', 'پنل فرانت منابع انسانی', 'پنل فرانت منابع انسانی', 'manage_options', 'sn-hr-front', [ self::class, 'render_admin_page' ] );
    }

    public static function render_admin_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'دسترسی مجاز نیست.' ); }
        $url = self::page_url();
        echo '<div class="wrap" dir="rtl"><h1>پنل فرانت منابع انسانی</h1><p>مدیریت نیروها، ساختار سازمانی، حقوق و پورسانت از صفحهٔ مستقل سایت.</p>';
        if ( $url !== '' ) {
            echo '<p><a class="button button-primary" href="' . esc_url( $url ) . '">ورود به پنل منابع انسانی</a></p>';
        } else {
            echo '<p>صفحه هنوز آماده نیست. صفحه مدیریت را دوباره باز کنید یا یک برگه با شورت‌کد زیر بسازید.</p>';
        }
        echo '<p>شورت‌کد برای برگهٔ دلخواه: <code>[sn_hr_panel]</code></p><p>این پنل فقط برای مدیر سیستم و کاربران منابع انسانی قابل استفاده است.</p></div>';
    }
}
