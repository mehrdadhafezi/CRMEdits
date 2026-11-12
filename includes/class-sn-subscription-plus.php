<?php
if (!defined('ABSPATH')) { exit; }

/** Subscription+ is an additive entitlement, never a Product* activation or an API call. */
final class SN_Subscription_Plus {
    private const VERSION = '2026-11-08-v3';
    private const VERSION_OPTION = 'sn_subscription_plus_db_version';
    private const SALE_COLUMNS = [
        'id', 'source_invoice_id', 'product_id', 'snapshot_json', 'base_paid_price',
        'cash_price', 'requires_conversion', 'membership_id', 'status',
        'api_payload_json', 'api_sync_status', 'created_at', 'updated_at',
    ];
    private const CASH_COLUMNS = [
        'cash_conversion_case_id' => 'BIGINT UNSIGNED DEFAULT NULL',
        'cash_profile_sms_sent_at' => 'DATETIME DEFAULT NULL',
        'cash_profile_sms_state' => "VARCHAR(20) NOT NULL DEFAULT ''",
    ];
    private const CASH_OPTIONS = 'sn_shared_cash_credit_options';
    private const PRODUCT_CASH_CONFIG = '_sn_product_cash_credit_config';
    private static ?self $instance = null;
    private array $storage_schemas = [];
    private array $upgrade_attempted = [];
    public static function instance(): self { return self::$instance ?? (self::$instance = new self()); }
    private function table(): string { global $wpdb; return $wpdb->prefix.'sn_subscription_plus_sales'; }

    private function storage_schema(): array {
        global $wpdb;
        $table = $this->table();
        if (isset($this->storage_schemas[$table])) { return $this->storage_schemas[$table]; }
        $columns = $unique = [];
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table;
        if ($exists) {
            $columns = $wpdb->get_results("SHOW COLUMNS FROM {$table}", OBJECT_K) ?: [];
            $keys = [];
            foreach ($wpdb->get_results("SHOW INDEX FROM {$table} WHERE Non_unique=0") ?: [] as $key) {
                $keys[$key->Key_name][] = $key->Column_name;
            }
            foreach ($keys as $key_columns) {
                if (count($key_columns) === 1) { $unique[$key_columns[0]] = true; }
            }
        }
        return $this->storage_schemas[$table] = ['exists'=>$exists, 'columns'=>$columns, 'unique'=>$unique];
    }

    /** Fail before writes when an installation cannot provide atomic financial changes. Never change existing engines. */
    public function transactional_tables(array $suffixes): bool {
        global $wpdb;
        foreach (array_unique(array_merge(['sn_subscription_plus_sales'],$suffixes)) as $suffix) {
            $engine=$wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$wpdb->prefix.$suffix));
            if (strtolower((string)$engine)!=='innodb') { return false; }
        }
        return true;
    }
    public function register_hooks(): void {
        add_action('init', [$this,'maybe_upgrade'], 10, 0);
        // The core must register the Sales Network parent menu first.
        add_action('admin_menu', [$this,'cash_options_menu'], 20);
        add_action('admin_post_sn_save_shared_cash_credit', [$this,'save_shared_options']);
        add_action('add_meta_boxes_product', [$this,'metabox']);
        add_action('save_post_product', [$this,'save_product'], 50, 2);
        add_action('wp_enqueue_scripts', [$this,'assets'], 40);
        add_action('sn_invoice_paid', [$this,'on_paid'], 25, 2);
        add_action('sn_invoice_financial_rejected', [$this,'on_reversed'], 35, 3);
        add_action('sn_invoice_financial_reopened', [$this,'on_reversed'], 35, 3);
        add_action('sn_plus_reconcile', [$this,'reconcile'], 10, 2);
    }
    public function maybe_upgrade(bool $verify_storage = false): void {
        $table = $this->table();
        if (isset($this->upgrade_attempted[$table])) { return; }
        $stored_version = get_option(self::VERSION_OPTION);
        $version_current = $stored_version === self::VERSION;
        if ($version_current && (!$verify_storage || $this->cash_schema_ready())) { return; }
        $this->upgrade_attempted[$table] = true;
        $schema = $this->storage_schema();
        global $wpdb;
        if (!$schema['exists']) {
            // An already installed table may be missing after an incomplete restore.
            if ($stored_version !== false) { return; }
            require_once ABSPATH.'wp-admin/includes/upgrade.php';
            $charset = $wpdb->get_charset_collate();
            dbDelta("CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            source_invoice_id BIGINT UNSIGNED NOT NULL,
            product_id BIGINT UNSIGNED NOT NULL,
            snapshot_json LONGTEXT NOT NULL,
            base_paid_price DECIMAL(18,2) NOT NULL DEFAULT 0,
            cash_price DECIMAL(18,2) NOT NULL DEFAULT 0,
            requires_conversion TINYINT(1) NOT NULL DEFAULT 0,
            membership_id BIGINT UNSIGNED DEFAULT NULL,
            cash_conversion_case_id BIGINT UNSIGNED DEFAULT NULL,
            cash_profile_sms_sent_at DATETIME DEFAULT NULL,
            cash_profile_sms_state VARCHAR(20) NOT NULL DEFAULT '',
            status VARCHAR(40) NOT NULL DEFAULT 'awaiting_payment',
            api_payload_json LONGTEXT DEFAULT NULL,
            api_sync_status VARCHAR(30) NOT NULL DEFAULT 'not_ready',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY source_invoice_id (source_invoice_id),
            UNIQUE KEY membership_id (membership_id),
            UNIQUE KEY cash_conversion_case_id (cash_conversion_case_id),
            KEY status (status)
            ) ENGINE=InnoDB {$charset};");
        } elseif ($this->sales_schema_ready()) {
            // Upgrade only the optional credit fields on existing sales tables.
            $changes = [];
            foreach (array_diff_key(self::CASH_COLUMNS, $schema['columns']) as $column => $definition) {
                $changes[] = "ADD COLUMN {$column} {$definition}";
            }
            if (!isset($schema['unique']['cash_conversion_case_id'])) {
                $changes[] = 'ADD UNIQUE KEY sn_plus_cash_case_unique (cash_conversion_case_id)';
            }
            if ($changes) { $wpdb->query("ALTER TABLE {$table} " . implode(', ', $changes)); }
        }
        unset($this->storage_schemas[$table]);
        if ($this->cash_schema_ready()) {
            update_option(self::VERSION_OPTION, self::VERSION, false);
        }
    }
    public function cash_schema_ready(): bool {
        $schema = $this->storage_schema();
        return $this->sales_schema_ready()
            && isset($schema['unique']['cash_conversion_case_id'])
            && !array_diff_key(self::CASH_COLUMNS, $schema['columns']);
    }

    private function has_conversion_cash_columns(): bool {
        return isset($this->storage_schema()['columns']['cash_conversion_case_id']);
    }

    private function sale_columns_ready(): bool {
        return !array_diff(self::SALE_COLUMNS, array_keys($this->storage_schema()['columns']));
    }

    public function sales_schema_ready(): bool {
        $schema = $this->storage_schema();
        $columns = $schema['columns'];
        return $this->sale_columns_ready()
            && isset($schema['unique']['id'], $schema['unique']['source_invoice_id'], $schema['unique']['membership_id'])
            && strpos((string) $columns['id']->Extra, 'auto_increment') !== false
            && ($columns['cash_conversion_case_id']->Null ?? 'YES') === 'YES';
    }

    public function is_product(int $id): bool { return get_post_meta($id,'_sn_sales_product_type',true)==='subscription_plus'; }
    private static function money($raw, bool $allow_zero = false): ?float {
        if (is_string($raw)) { $raw = str_replace(['،', "\u{00A0}", "\u{2009}", "\u{202F}"], '', $raw); }
        $key=SN_Sales_Catalog::amount_key($raw, $allow_zero);
        return $key !== '' ? ((int)$key)/100 : null;
    }
    public static function installment_credit($raw): ?float {
        return self::money($raw, true);
    }
    public function options(int $product_id): array {
        $config = $this->product_cash_config($product_id);
        if ($config['mode'] === 'custom') { return $config['options']; }
        return $this->shared_options();
    }

    private function product_cash_config(int $product_id): array {
        $saved = get_post_meta($product_id, self::PRODUCT_CASH_CONFIG, true);
        return [
            'mode' => ($saved['mode'] ?? '') === 'custom' ? 'custom' : 'shared',
            'options' => is_array($saved['options'] ?? null) ? $saved['options'] : [],
        ];
    }

    public function shared_options(): array {
        $saved = get_option(self::CASH_OPTIONS, null);
        if (is_array($saved)) { return $saved; }

        // Import existing product choices once. Invoices retain their own snapshots.
        global $wpdb;
        $legacy = $wpdb->get_col($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key=%s ORDER BY meta_id",
            '_sn_plus_cash_options'
        ));
        $options = [];
        $seen = [];
        foreach ((array) $legacy as $value) {
            foreach ((array) maybe_unserialize($value) as $row) {
                if (!is_array($row) || !is_scalar($row['title'] ?? null)) { continue; }
                $legacy_title = sanitize_text_field((string) $row['title']);
                if (strlen($legacy_title) <= 600 && mb_strlen($legacy_title) > 191) {
                    // Old product settings allowed 600 bytes. Keep the choice usable in fields limited to 191 characters.
                    $row['title'] = mb_substr($legacy_title, 0, 191);
                }
                $validated = self::validate_options([$row]);
                $choice = $validated['options'][0] ?? null;
                if (!$choice) { continue; }
                $signature = wp_json_encode([$legacy_title, $choice['credit'], $choice['price']]);
                if (isset($seen[$signature])) { continue; }
                $seen[$signature] = true;
                if (isset($options[$choice['id']])) {
                    $choice['id'] = 'cash_' . substr(hash('sha256', $signature), 0, 32);
                }
                $options[$choice['id']] = $choice;
            }
        }
        $options = array_values($options);
        if (!$wpdb->last_error) {
            add_option(self::CASH_OPTIONS, $options, '', false);
            return (array) get_option(self::CASH_OPTIONS, $options);
        }
        return [];
    }

    public function cash_options_menu(): void {
        add_submenu_page('sn-dashboard', 'اعتبارهای نقدی', 'اعتبارهای نقدی', 'manage_options', 'sn-shared-cash-credit', [$this, 'render_shared_options']);
    }

    public function render_shared_options(): void {
        if (!current_user_can('manage_options')) { return; }
        $this->maybe_upgrade(true);
        $options = $this->shared_options();
        echo '<div class="wrap" dir="rtl"><h1>اعتبارهای نقدی</h1>';
        if (isset($_GET['saved'])) { echo '<div class="notice notice-success"><p>فهرست اعتبارهای نقدی ذخیره شد.</p></div>'; }
        echo '<p>این فهرست برای محصولاتی که «فهرست مشترک» را انتخاب کرده‌اند استفاده می‌شود. گزینه «بدون اعتبار نقدی» خودکار نمایش داده می‌شود.</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('sn_save_shared_cash_credit');
        echo '<input type="hidden" name="action" value="sn_save_shared_cash_credit"><input type="hidden" name="revision" value="' . esc_attr(hash('sha256', wp_json_encode($options))) . '">';
        $this->render_options_editor($options, 'sn_plus_options');
        submit_button('ذخیره فهرست');
        echo '</form></div>';
    }

    public function save_shared_options(): void {
        if (!current_user_can('manage_options')) { wp_die('دسترسی غیرمجاز', '', ['response' => 403]); }
        check_admin_referer('sn_save_shared_cash_credit');
        $revision = hash('sha256', wp_json_encode($this->shared_options()));
        if (!is_string($_POST['revision'] ?? null) || !hash_equals($revision, wp_unslash($_POST['revision']))) {
            wp_die('فهرست در پنجره دیگری تغییر کرده است؛ صفحه را تازه کنید.', '', ['response' => 409]);
        }
        $result = self::submitted_options('sn_plus_options');
        if (isset($result['error'])) { wp_die(esc_html($result['error']), '', ['response' => 400]); }
        if (!update_option(self::CASH_OPTIONS, $result['options'], false) && get_option(self::CASH_OPTIONS) !== $result['options']) {
            wp_die('ذخیره فهرست انجام نشد.', '', ['response' => 500]);
        }
        wp_safe_redirect(admin_url('admin.php?page=sn-shared-cash-credit&saved=1'));
        exit;
    }

    private static function submitted_options(string $name): array {
        $rows = wp_unslash($_POST[$name] ?? []);
        if (is_string($rows)) { $rows = json_decode($rows, true); }
        return self::validate_options($rows);
    }

    public static function validate_options($rows): array {
        if (!is_array($rows)) { return ['error'=>'ساختار فهرست اعتبار نقدی معتبر نیست.']; }
        if (count($rows)>200) { return ['error'=>'حداکثر ۲۰۰ گزینه اعتبار نقدی تعریف کنید.']; }
        $out=[]; $seen=[];
        foreach ($rows as $row) {
            if (!is_array($row)) { return ['error'=>'ساختار گزینه اعتبار نقدی معتبر نیست.']; }
            foreach ($row as $value) { if (!is_scalar($value)) { return ['error'=>'مقادیر گزینه اعتبار نقدی معتبر نیست.']; } }
            $title=sanitize_text_field((string)($row['title']??''));
            if ($title==='' && trim((string)($row['credit']??''))==='' && trim((string)($row['price']??''))==='') { continue; }
            $credit=self::money($row['credit']??''); $price=self::money($row['price']??'');
            if ($title==='' || mb_strlen($title)>191 || $credit===null || $price===null || $price<=.5) { return ['error'=>'هر گزینه به عنوان، اعتبار مثبت و قیمت مثبت نیاز دارد.']; }
            $id=sanitize_key((string)($row['id']??''));
            if ($id==='') { $id='cash_'.str_replace('-','',wp_generate_uuid4()); }
            if (!preg_match('/^cash_[a-z0-9]{8,40}$/D',$id) || isset($seen[$id])) { return ['error'=>'شناسه گزینه‌های نقدی باید معتبر و یکتا باشد.']; }
            $seen[$id]=true; $out[]=['id'=>$id,'title'=>$title,'credit'=>$credit,'price'=>$price];
        }
        return ['options'=>$out];
    }

    private function can_edit_product_cash_options(int $product_id): bool {
        return current_user_can('edit_post', $product_id)
            && (current_user_can('manage_options') || current_user_can('manage_woocommerce'));
    }

    public function metabox(): void { add_meta_box('sn-subscription-plus','تنظیمات اشتراک و اعتبار نقدی',[$this,'render_meta'],'product','normal'); }
    public function render_meta(WP_Post $post): void {
        wp_nonce_field('sn_subscription_plus_product','sn_plus_nonce');
        $invite = get_post_meta($post->ID, '_sn_conversion_invite_sms', true);
        echo '<p><label>پیامک ورود به بیاوین پس از تسویه تبدیل اشتراک <select name="sn_conversion_invite_sms">';
        foreach (['' => 'مطابق تنظیم سراسری', '1' => 'ارسال شود', '0' => 'ارسال نشود'] as $value => $label) {
            echo '<option value="' . esc_attr((string) $value) . '" ' . selected($invite, (string) $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></label></p>';
        $cash_enabled = get_post_meta($post->ID, '_sn_conversion_cash_enabled', true);
        echo '<p><input type="hidden" name="sn_conversion_cash_enabled" value="0"><label>'
            . '<input type="checkbox" name="sn_conversion_cash_enabled" value="1" '
            . checked($cash_enabled, '1', false) . '> مشمول طرح اعتبار نقدی پس از تبدیل اعتبارسنجی</label></p>';
        echo '<p>این دو تنظیم برای «اشتراک» و «اشتراک+» در مسیر تبدیل اعتبارسنجی اعمال می‌شوند.</p>';
        echo '<p><label>لینک سایت مقصد اشتراک+ <input type="url" name="sn_plus_destination" style="width:70%" value="' . esc_attr((string) get_post_meta($post->ID, '_sn_plus_destination', true)) . '"></label></p>';
        if ($this->can_edit_product_cash_options($post->ID)) {
            $config = $this->product_cash_config($post->ID);
            ?>
            <div>
                <p><label>گزینه‌های اعتبار نقدی این محصول
                    <select name="sn_plus_cash_options_mode">
                        <option value="shared" <?php selected($config['mode'], 'shared'); ?>>مطابق فهرست مشترک</option>
                        <option value="custom" <?php selected($config['mode'], 'custom'); ?>>فهرست اختصاصی این محصول</option>
                    </select>
                </label></p>
                <p>برای اعتبار نقدی پس از تبدیل و فروش مستقیم اشتراک+ استفاده می‌شود.</p>
                <fieldset <?php if ($config['mode'] !== 'custom') { echo 'hidden disabled'; } ?>>
                    <?php $this->render_options_editor($config['options'], 'sn_plus_product_options'); ?>
                    <input type="hidden" name="sn_plus_product_options_complete" value="1">
                </fieldset>
                <?php if (current_user_can('manage_options')) : ?>
                    <p><a href="<?php echo esc_url(admin_url('admin.php?page=sn-shared-cash-credit')); ?>">مدیریت فهرست مشترک اعتبارهای نقدی</a></p>
                <?php endif; ?>
                <script>
                (function (root) {
                    const mode = root.querySelector('[name="sn_plus_cash_options_mode"]');
                    const editor = root.querySelector('fieldset');
                    mode.addEventListener('change', function () {
                        editor.disabled = editor.hidden = mode.value !== 'custom';
                    });
                })(document.currentScript.parentElement);
                </script>
            </div>
            <?php
        }
    }

    private function render_options_editor(array $options, string $name): void {
        ?>
        <div>
            <input type="hidden" data-sn-plus-options="<?php echo esc_attr($name); ?>">
            <table class="widefat">
                <thead><tr><th>عنوان اعتبار نقدی</th><th>مبلغ اعتبار (تومان)</th><th>قیمت فروش (تومان)</th><th></th></tr></thead>
                <tbody><?php foreach (array_values($options) as $index => $row) { $this->meta_row($name, (string) $index, $row); } ?></tbody>
            </table>
            <p><button type="button" class="button sn-plus-add">افزودن اعتبار نقدی</button></p>
            <template><?php $this->meta_row($name, '__index__', []); ?></template>
            <script>
            (function (root) {
                const table = root.querySelector('tbody');
                const template = root.querySelector('template');
                const payload = root.querySelector('[data-sn-plus-options]');
                function syncOptions() {
                    const rows = Array.from(table.rows, function (row) {
                        const values = {};
                        row.querySelectorAll('[data-sn-plus-field]').forEach(function (input) {
                            values[input.dataset.snPlusField] = input.value;
                            input.removeAttribute('name');
                        });
                        return values;
                    });
                    payload.value = JSON.stringify(rows);
                }
                root.querySelector('.sn-plus-add').addEventListener('click', function () {
                    table.appendChild(template.content.cloneNode(true));
                    syncOptions();
                });
                table.addEventListener('click', function (event) {
                    if (event.target.classList.contains('sn-plus-remove')) {
                        event.target.closest('tr').remove();
                        syncOptions();
                    }
                });
                table.addEventListener('input', syncOptions);
                table.addEventListener('change', syncOptions);
                root.closest('form')?.addEventListener('submit', syncOptions, true);
                syncOptions();
                payload.name = payload.dataset.snPlusOptions;
            })(document.currentScript.parentElement);
            </script>
        </div>
        <?php
    }

    private function meta_row(string $name, string $index, array $row): void {
        $prefix = esc_attr($name . '[' . $index . ']');
        echo '<tr><td><input type="hidden" data-sn-plus-field="id" name="'.$prefix.'[id]" value="'.esc_attr($row['id'] ?? '').'"><input data-sn-plus-field="title" name="'.$prefix.'[title]" value="'.esc_attr($row['title'] ?? '').'" maxlength="191"></td>';
        foreach (['credit', 'price'] as $field) {
            echo '<td><input inputmode="decimal" data-sn-plus-field="'.$field.'" name="'.$prefix.'['.$field.']" value="'.esc_attr((string) ($row[$field] ?? '')).'"></td>';
        }
        echo '<td><button type="button" class="button sn-plus-remove">حذف</button></td></tr>';
    }
    public function save_product(int $id, WP_Post $post): void {
        if (!isset($_POST['sn_plus_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['sn_plus_nonce'])),'sn_subscription_plus_product') || !current_user_can('edit_post',$id) || (defined('DOING_AUTOSAVE')&&DOING_AUTOSAVE) || wp_is_post_revision($id)) { return; }
        if (is_string($_POST['sn_plus_destination'] ?? null)) {
            update_post_meta($id, '_sn_plus_destination', esc_url_raw(wp_unslash($_POST['sn_plus_destination']), ['https','http']));
        }
        $invite = $_POST['sn_conversion_invite_sms'] ?? null;
        if ($invite === '0' || $invite === '1') {
            update_post_meta($id, '_sn_conversion_invite_sms', $invite);
        } elseif ($invite === '') {
            delete_post_meta($id, '_sn_conversion_invite_sms');
        }
        $cash_enabled = $_POST['sn_conversion_cash_enabled'] ?? null;
        if ($cash_enabled === '0' || $cash_enabled === '1') {
            update_post_meta($id, '_sn_conversion_cash_enabled', $cash_enabled);
        }
        $mode = $_POST['sn_plus_cash_options_mode'] ?? '';
        if (!$this->can_edit_product_cash_options($id) || !in_array($mode, ['shared', 'custom'], true)) { return; }
        $config = $this->product_cash_config($id);
        if ($mode === 'custom') {
            $result = ($_POST['sn_plus_product_options_complete'] ?? '') === '1'
                ? self::submitted_options('sn_plus_product_options')
                : ['error' => 'فهرست اعتبار نقدی این محصول کامل دریافت نشد. دوباره ذخیره کنید.'];
            if (isset($result['error'])) {
                if (class_exists('WC_Admin_Meta_Boxes')) { WC_Admin_Meta_Boxes::add_error($result['error']); }
                return;
            }
            $config['options'] = $result['options'];
        }
        $config['mode'] = $mode;
        if (!update_post_meta($id, self::PRODUCT_CASH_CONFIG, wp_slash($config)) && get_post_meta($id, self::PRODUCT_CASH_CONFIG, true) !== $config
            && class_exists('WC_Admin_Meta_Boxes')) {
            WC_Admin_Meta_Boxes::add_error('ذخیره فهرست اعتبار نقدی این محصول انجام نشد.');
        }
    }
    /** Caller already validates product availability, quantity, catalog and ownership. */
    public function prepare_sale(array $ids,array $request) {
        $plus=array_values(array_filter($ids,fn($id)=>$this->is_product((int)$id)));
        if (!$plus) { return null; }
        if (count($ids)!==1 || count($plus)!==1) { return new WP_Error('sn_plus_single','اشتراک+ باید به‌تنهایی و با تعداد یک فروخته شود.'); }
        if (!is_scalar($request['plus_cash_option']??null)) { return new WP_Error('sn_plus_choice','گزینه اعتبار نقدی یا «بدون اعتبار نقدی» را انتخاب کنید.'); }
        $id=(int)$plus[0]; $choice=sanitize_key((string)wp_unslash($request['plus_cash_option'])); $selected=null;
        $options=$this->options($id);
        if ($choice!=='none') {
            foreach ($options as $option) { if ($option['id']===$choice) { $selected=$option; break; } }
            if (!$selected) { return new WP_Error('sn_plus_option','گزینه اعتبار نقدی حذف یا تغییر کرده است؛ فهرست را تازه کنید.'); }
        }
        $credit=self::installment_credit(get_post_meta($id,'_sn_product_credit_amount',true));
        if ($credit===null) { return new WP_Error('sn_plus_credit','در باکس «شبکه فروش» محصول، مبلغ اعتبار قابل ارائه به مشتری را تعیین کنید. برای اشتراک+ بدون اعتبار اقساطی، ۰ وارد کنید.'); }
        $product=wc_get_product($id);
        if (!$product || (float)$product->get_price()<=0) { return new WP_Error('sn_plus_price','قیمت اشتراک+ معتبر نیست.'); }
        return ['version'=>1,'product_id'=>$id,'product_name'=>$product->get_name(),'base_price'=>(float)$product->get_price(),'installment_credit'=>$credit,'cash_option'=>$selected,'cash_options'=>$options,'destination_url'=>(string)get_post_meta($id,'_sn_plus_destination',true),'image_url'=>(string)get_the_post_thumbnail_url($id,'large'),'description_html'=>wp_kses_post($product->get_description()),'requires_conversion'=>$selected?0:1];
    }
    public function persist_sale(int $invoice_id,array $snapshot,float $base_net): bool {
        global $wpdb;
        return (bool)$wpdb->insert($this->table(),['source_invoice_id'=>$invoice_id,'product_id'=>$snapshot['product_id'],'snapshot_json'=>wp_json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'base_paid_price'=>$base_net,'cash_price'=>(float)($snapshot['cash_option']['price']??0),'requires_conversion'=>$snapshot['requires_conversion'],'status'=>'awaiting_payment','created_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')]);
    }
    public function sale(int $source): ?object {
        if (!$this->sale_columns_ready()) { return null; }
        global $wpdb;
        $regular = $this->has_conversion_cash_columns() ? ' AND cash_conversion_case_id IS NULL' : '';
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM '.$this->table()." WHERE source_invoice_id=%d{$regular} LIMIT 1", $source)) ?: null;
    }
    public function case_sale(object $case): ?object { return $this->sale((int) ($case->source_invoice_id ?? 0)); }
    public function invoice_sale(int $invoice_id): ?object {
        if (!$this->sale_columns_ready()) { return null; }
        global $wpdb;
        $sale = $wpdb->get_row($wpdb->prepare('SELECT * FROM '.$this->table()." WHERE source_invoice_id=%d AND status<>'notification_only' LIMIT 1", $invoice_id));
        if ($sale) { return $sale; }
        $regular = $this->has_conversion_cash_columns() ? ' AND p.cash_conversion_case_id IS NULL' : '';
        return $wpdb->get_row($wpdb->prepare('SELECT p.* FROM '.$this->table()." p INNER JOIN {$wpdb->prefix}sn_dot_cases c ON c.source_invoice_id=p.source_invoice_id INNER JOIN {$wpdb->prefix}sn_dot_invoice_links dl ON dl.case_id=c.id WHERE dl.invoice_id=%d AND dl.flow_kind='conversion_payment'{$regular} LIMIT 1", $invoice_id)) ?: null;
    }
    public function handles_invoice(int $id): bool { return (bool) $this->invoice_sale($id); }
    public function is_membership(int $id): bool {
        if (!$this->sale_columns_ready()) { return false; }
        global $wpdb;
        $regular = $this->has_conversion_cash_columns() ? ' AND cash_conversion_case_id IS NULL' : '';
        return (bool) $wpdb->get_var($wpdb->prepare('SELECT id FROM '.$this->table()." WHERE membership_id=%d{$regular} LIMIT 1", $id));
    }
    public function is_item(int $id): bool {
        if (!$this->sale_columns_ready()) { return false; }
        global $wpdb;
        $regular = $this->has_conversion_cash_columns() ? ' AND p.cash_conversion_case_id IS NULL' : '';
        return (bool) $wpdb->get_var($wpdb->prepare('SELECT p.id FROM '.$this->table()." p INNER JOIN {$wpdb->prefix}sn_project_membership_items mi ON mi.membership_id=p.membership_id WHERE mi.id=%d{$regular} LIMIT 1", $id));
    }
    public function case_options(object $sale): array {
        $snapshot = json_decode($sale->snapshot_json, true);
        return $this->cash_case_options((array)($snapshot['cash_options'] ?? []));
    }

    public function current_case_options(object $sale): array {
        return $this->cash_case_options($this->options((int)$sale->product_id));
    }

    private function cash_case_options(array $rows): array {
        $options = [['key'=>'plus_none','product_id'=>0,'title'=>'بدون اعتبار نقدی','price'=>0,'credit'=>0,'active'=>1,'content'=>'']];
        foreach ($rows as $row) {
            $options[] = ['key'=>'plus_'.$row['id'],'product_id'=>0,'title'=>$row['title'],'price'=>(float)$row['price'],'credit'=>(float)$row['credit'],'active'=>1,'content'=>'','cash_option_id'=>$row['id']];
        }
        return $options;
    }

    public function option_for_case(object $case, ?object $sale = null): ?array {
        $sale ??= $this->case_sale($case);
        if (!$sale) { return null; }
        $key = (string)$case->selected_option_key;
        $options = array_column((array)json_decode((string)($case->options_snapshot_json ?? ''), true), null, 'key');
        if (isset($options[$key])) { return $options[$key]; }
        // Older cases can resolve their agreed option from the source sale snapshot.
        $options = array_column($this->case_options($sale), null, 'key');
        return $options[$key] ?? null;
    }
    public function complete_without_cash(object $case): bool {
        $sale=$this->case_sale($case);
        if (!$sale || (string)$case->selected_option_key!=='plus_none') { return false; }
        global $wpdb; $table=$wpdb->prefix.'sn_dot_cases';
        if (!$this->transactional_tables(['sn_dot_cases','sn_dot_payments','sn_invoices']) || false===$wpdb->query('START TRANSACTION')) { return false; }
        $locked=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d FOR UPDATE",(int)$case->id));
        $count=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}sn_dot_payments WHERE case_id=%d",(int)$case->id));
        $source=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_invoices WHERE id=%d FOR UPDATE",(int)$case->source_invoice_id));
        if (!$locked || !$this->source_verified($sale,$source) || $count>0 || (string)$locked->selected_option_key!=='plus_none' || !empty($locked->operational_archived_at)) { $wpdb->query('ROLLBACK'); return false; }
        if (false===$wpdb->update($table,['status'=>'completed','paid_amount'=>0,'remaining_amount'=>0,'completed_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')],['id'=>(int)$case->id]) || false===$wpdb->query('COMMIT')) { $wpdb->query('ROLLBACK'); return false; }
        $this->reconcile((int)$case->source_invoice_id,0);
        $sale=$this->sale((int)$case->source_invoice_id); return $sale && (string)$sale->status==='active';
    }
    private function verified(?object $invoice): bool {
        return $invoice && in_array((string)$invoice->status,['paid','approved'],true) && (string)($invoice->payment_workflow_status??'')==='completed' && (float)($invoice->remaining_amount??1)>-0.01 && (float)($invoice->remaining_amount??1)<=0.5;
    }
    private function source_verified(object $sale, ?object $invoice): bool {
        if (!$this->verified($invoice)) { return false; }
        $expected=(float)$sale->base_paid_price+(float)$sale->cash_price;
        $total=(float)($invoice->payment_total_amount??$invoice->final_total??$invoice->product_price??0);
        return $expected>0 && abs($total-$expected)<0.005 && (float)($invoice->paid_total_amount??0)+0.005>=$expected;
    }
    public function on_paid(int $id,$invoice): void {
        $sale=$this->invoice_sale($id); if (!$sale) { return; }
        if (!empty($sale->cash_conversion_case_id)) {
            SN_Conversion_Cash_Credit::instance()->settle((int)$sale->id);
            return;
        }
        $this->reconcile((int)$sale->source_invoice_id,0);
    }
    public function reconcile(int $source,int $attempt=0): void {
        $sale=$this->sale($source); if (!$sale) { return; }
        global $wpdb;
        $inv=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_invoices WHERE id=%d",$source));
        if (!$this->source_verified($sale,$inv)) { return; }
        $s=json_decode($sale->snapshot_json,true); $cash=$s['cash_option']??null; $case=null;
        if (!empty($sale->requires_conversion)) {
            $case=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_dot_cases WHERE source_invoice_id=%d LIMIT 1",$source));
            if (!$case) { SN_Dot_Flow::instance()->on_invoice_paid($source,$inv); $case=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_dot_cases WHERE source_invoice_id=%d LIMIT 1",$source)); }
            if (!$case || (string)$case->status!=='completed') {
                $wpdb->update($this->table(),['status'=>'awaiting_conversion','updated_at'=>current_time('mysql')],['id'=>(int)$sale->id]);
                if (!$case) { $this->retry($source,$attempt); } return;
            }
            $option=$this->option_for_case($case,$sale); if (!$option) { return; }
            if ($option['key']!=='plus_none') {
                if ((float)$case->paid_amount+0.005<(float)$option['price']) { return; }
                // Every contributing conversion invoice must still be financially complete.
                $bad=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}sn_dot_payments p LEFT JOIN {$wpdb->prefix}sn_invoices i ON i.id=p.invoice_id WHERE p.case_id=%d AND p.status='paid' AND (i.id IS NULL OR i.status NOT IN ('paid','approved') OR COALESCE(i.remaining_amount,1)>0.5)",(int)$case->id));
                if ($bad>0) { return; }
                $cash=['id'=>$option['cash_option_id'],'title'=>$option['title'],'credit'=>$option['credit'],'price'=>$option['price']];
            } else { $cash=null; }
        }
        if (!$this->activate($sale,$inv,$s,$cash,$case)) { $this->retry($source,$attempt); }
    }
    private function retry(int $source,int $attempt): void {
        $args=[$source,$attempt+1];
        if ($attempt<5 && !wp_next_scheduled('sn_plus_reconcile',$args)) { wp_schedule_single_event(time()+120,'sn_plus_reconcile',$args); }
    }
    private function activate(object $sale,object $invoice,array $snapshot,?array $cash,?object $case): bool {
        global $wpdb; $table=$this->table(); $members=$wpdb->prefix.'sn_project_memberships'; $items=$wpdb->prefix.'sn_project_membership_items'; $now=current_time('mysql');
        // Provision all dependent schemas before opening a transaction; DDL can implicitly commit.
        SN_Projects::instance()->maybe_upgrade(); SN_Customer_Portal::instance()->maybe_upgrade();
        $required=['sn_project_memberships','sn_project_membership_items','sn_invoices'];
        if ($case) { $required=array_merge($required,['sn_dot_cases','sn_dot_payments']); }
        if (!$this->transactional_tables($required)) { error_log('SN Plus: transactional tables required for invoice #'.(int)$invoice->id); return false; }
        if (false===$wpdb->query('START TRANSACTION')) { return false; }
        try {
            $locked=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d FOR UPDATE",(int)$sale->id));
            $fresh=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_invoices WHERE id=%d FOR UPDATE",(int)$invoice->id));
            if (!$locked || !$this->source_verified($locked,$fresh)) { throw new RuntimeException('source_unpaid'); }
            if ($case) {
                $fresh_case=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_dot_cases WHERE id=%d FOR UPDATE",(int)$case->id));
                if (!$fresh_case || (string)$fresh_case->status!=='completed' || (string)$fresh_case->selected_option_key!==(string)$case->selected_option_key) { throw new RuntimeException('case_changed'); }
            }
            $membership=(int)($locked->membership_id??0);
            if ($membership>0 && (string)$locked->status==='active') {
                if (false===$wpdb->query('COMMIT')) { throw new RuntimeException('commit'); }
                SN_Customer_Portal::instance()->send_membership_invite($membership,(int)$sale->source_invoice_id,$fresh); return true;
            }
            $origin=$case && (int)$case->converter_id>0?(int)$case->converter_id:(int)$fresh->seller_id;
            if (!$membership) {
                $data=['source_key'=>'subscription_plus:'.(int)$sale->source_invoice_id,'source_type'=>'subscription_plus','source_invoice_id'=>(int)$sale->source_invoice_id,'dot_case_id'=>$case?(int)$case->id:null,'subscription_product_id'=>(int)$sale->product_id,'subscription_name_snapshot'=>$snapshot['product_name'],'customer_wp_id'=>(int)($fresh->customer_wp_id??0)?:null,'customer_name'=>$fresh->customer_name,'customer_phone'=>SN_Helpers::normalize_mobile($fresh->customer_phone),'origin_user_id'=>$origin,'origin_role'=>$case?'converter':'seller','sales_chat_user_id'=>$origin,'sales_chat_role'=>$case?'converter':'seller','first_payment_invoice_id'=>(int)$fresh->id,'first_payment_stage_no'=>1,'first_payment_amount'=>(float)$fresh->paid_total_amount,'contents_snapshot_json'=>wp_json_encode([$snapshot]),'status'=>'active','activated_at'=>$now,'created_at'=>$now,'updated_at'=>$now];
                if (!$wpdb->insert($members,$data)) { throw new RuntimeException('membership_insert'); }
                $membership=(int)$wpdb->insert_id;
                if (!$wpdb->insert($items,['membership_id'=>$membership,'content_product_id'=>(int)$sale->product_id,'content_name_snapshot'=>$snapshot['product_name'],'base_credit_snapshot'=>$snapshot['installment_credit'],'upgrade_options_snapshot_json'=>'[]','workflow_status'=>'completed','created_at'=>$now,'updated_at'=>$now])) { throw new RuntimeException('card_insert'); }
            } elseif (false===$wpdb->update($members,['status'=>'active','updated_at'=>$now],['id'=>$membership])) { throw new RuntimeException('membership_restore'); }
            $payload=['schema_version'=>1,'idempotency_key'=>'subscription-plus-'.(int)$sale->id,'source_invoice_id'=>(int)$sale->source_invoice_id,'dot_case_id'=>$case?(int)$case->id:null,'product_id'=>(int)$sale->product_id,'customer'=>['user_id'=>(int)($fresh->customer_wp_id??0),'name'=>$fresh->customer_name,'mobile'=>SN_Helpers::normalize_mobile($fresh->customer_phone)],'installment_wallet'=>['credit'=>(float)$snapshot['installment_credit'],'currency'=>'IRT'],'cash_wallet'=>['credit'=>(float)($cash['credit']??0),'option_id'=>$cash['id']??null,'option_title'=>$cash['title']??'بدون اعتبار نقدی','paid_price'=>(float)($cash['price']??0),'currency'=>'IRT'],'subscription_price'=>(float)$sale->base_paid_price,'destination_url'=>$snapshot['destination_url'],'ready_at'=>$now];
            $sale_data = ['membership_id'=>$membership,'status'=>'active','api_payload_json'=>wp_json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'api_sync_status'=>'ready','updated_at'=>$now];
            if ($case && $cash) {
                // Retain the original quote and record the cash choice actually settled by this case.
                $snapshot['conversion_cash_option'] = $cash;
                $sale_data['snapshot_json'] = wp_json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            if (false===$wpdb->update($table,$sale_data,['id'=>(int)$sale->id])) { throw new RuntimeException('payload_save'); }
            if (false===$wpdb->query('COMMIT')) { throw new RuntimeException('commit'); }
        } catch (Throwable $e) { $wpdb->query('ROLLBACK'); error_log('SN Plus #'.(int)$sale->source_invoice_id.': '.$e->getMessage()); return false; }
        // Intentionally do not fire card-activation hooks: this card has no operations workflow.
        SN_Customer_Portal::instance()->send_membership_invite($membership,(int)$sale->source_invoice_id,$fresh);
        return true;
    }
    public function on_reversed(int $id,$invoice=null,string $reason=''): void {
        $sale=$this->invoice_sale($id); if (!$sale) { return; }
        global $wpdb;
        if (!$this->transactional_tables(['sn_project_memberships']) || false===$wpdb->query('START TRANSACTION')) { error_log('SN Plus: suspension transaction unavailable for invoice #'.$id); return; }
        $locked=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.$this->table().' WHERE id=%d FOR UPDATE',(int)$sale->id));
        if (!$locked || (!empty($locked->cash_conversion_case_id) && (int) $locked->source_invoice_id !== $id)) { $wpdb->query('ROLLBACK'); return; }
        $ok=false!==$wpdb->update($this->table(),['status'=>'suspended','api_sync_status'=>'suspended','updated_at'=>current_time('mysql')],['id'=>(int)$sale->id]);
        if (!empty($locked->membership_id) && empty($locked->cash_conversion_case_id)) { $ok=$ok && false!==$wpdb->update($wpdb->prefix.'sn_project_memberships',['status'=>'suspended','updated_at'=>current_time('mysql')],['id'=>(int)$locked->membership_id]); }
        if (!$ok || false===$wpdb->query('COMMIT')) { $wpdb->query('ROLLBACK'); }
    }
    public function commission_components(int $invoice_id,float $stage_amount): ?array {
        $sale=$this->invoice_sale($invoice_id); if (!$sale) { return null; }
        if (!empty($sale->cash_conversion_case_id) || (int)$sale->source_invoice_id!==$invoice_id) { return ['subscription_plus_cash'=>$stage_amount]; }
        $base=(float)$sale->base_paid_price; $cash=(float)$sale->cash_price; $total=$base+$cash;
        if ($total<=0) { return []; }
        $base_share=round($stage_amount*$base/$total,2);
        return ['subscription_plus'=>$base_share,'subscription_plus_cash'=>round($stage_amount-$base_share,2)];
    }
    public function decorate_membership(array $membership): array {
        if (($membership['source_type']??'')!=='subscription_plus') { return $membership; }
        $sale=$this->sale((int)$membership['source_invoice_id']); if (!$sale) { return $membership; }
        $s=json_decode($sale->snapshot_json,true); $payload=json_decode((string)$sale->api_payload_json,true);
        foreach ($membership['items'] as &$item) {
            $item['subscription_plus']=true; $item['credit']=(float)$s['installment_credit']; $item['cash_credit']=(float)($payload['cash_wallet']['credit']??0);
            $item['cash_credit_title']=(string)($payload['cash_wallet']['option_title']??'بدون اعتبار نقدی');
            $item['operations_stage']='completed'; $item['operations_mode']='';
            $item['destination_url']=(string)get_post_meta((int)$sale->product_id,'_sn_plus_destination',true) ?: (string)$s['destination_url'];
            if (empty($item['image'])) { $item['image']=$s['image_url']; }
            if (empty($item['description_html'])) { $item['description_html']=wp_kses_post(wpautop($s['description_html'])); }
        } unset($item);
        return $membership;
    }
    public function assets(): void {
        if (!is_user_logged_in() || !function_exists('sn_bootstrap_current_post_has_crm_shortcode') || !sn_bootstrap_current_post_has_crm_shortcode()) { return; }
        $ids=get_posts(['post_type'=>'product','post_status'=>'publish','fields'=>'ids','posts_per_page'=>-1,'meta_query'=>[['key'=>'_sn_enabled','value'=>'1'],['key'=>'_sn_sales_product_type','value'=>'subscription_plus']]]);
        $options=[];
        foreach ($ids as $id) { if (SN_Sales_Catalog::instance()->product_allowed((int)$id,get_current_user_id())) { $options[(int)$id]=$this->options((int)$id); } }
        if (!$options) { return; }
        wp_enqueue_script('sn-subscription-plus',SN_PLUGIN_URL.'assets/js/subscription-plus.js',['jquery','sn-public-shell'],SN_VERSION,true);
        // No purchased/customer/API snapshots enter the browser configuration.
        wp_localize_script('sn-subscription-plus','snSubscriptionPlus',['options'=>$options]);
    }
}
