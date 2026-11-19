<?php
if (! defined('ABSPATH')) { exit; }

/** HR-owned permission for ordinary seller reissue; never changes WP roles. */
final class SN_HR_Invoice_Reissue {
    public const META_KEY = 'sn_invoice_reissue_mode';
    private const RANKS = ['seller'=>0, 'supervisor'=>1, 'senior_supervisor'=>2, 'sales_manager'=>3];
    private const LABELS = ['seller'=>'فروشنده', 'supervisor'=>'سرپرست', 'senior_supervisor'=>'سرپرست ارشد', 'sales_manager'=>'مدیر فروش'];
    private static $permission;

    public static function register(callable $permission): void {
        self::$permission = $permission;
        add_action('wp_ajax_sn_hr_invoice_reissue', [self::class, 'ajax']);
        add_action('wp_ajax_nopriv_sn_hr_invoice_reissue', [self::class, 'ajax']);
    }

    public static function assets(): void {
        wp_enqueue_style('sn-hr-invoice-reissue', SN_PLUGIN_URL . 'assets/css/hr-invoice-reissue.css', [], SN_VERSION);
        wp_enqueue_script('sn-hr-invoice-reissue', SN_PLUGIN_URL . 'assets/js/hr-invoice-reissue.js', [], SN_VERSION, true);
        wp_localize_script('sn-hr-invoice-reissue', 'SNHRInvoiceReissue', [
            'url'=>admin_url('admin-ajax.php'), 'nonce'=>wp_create_nonce('sn_hr_invoice_reissue'),
        ]);
    }

    /** A pure policy evaluation, also used by synthetic regression tests. */
    public static function evaluate(int $user_id, array $nodes, array $parents, array $modes): array {
        $result = ['allowed'=>false, 'source_id'=>0, 'chain'=>[], 'valid'=>false, 'reason'=>'hierarchy'];
        $seen = []; $rank = -1; $current = $user_id;
        for ($depth = 0; $depth < 5; $depth++) {
            $node = $nodes[$current] ?? null;
            if (! $node || isset($seen[$current]) || ! isset(self::RANKS[$node['position'] ?? ''])
                || self::RANKS[$node['position']] <= $rank || empty($node['active'])) { return $result; }
            $seen[$current] = true; $result['chain'][] = $current; $rank = self::RANKS[$node['position']];
            if ($node['position'] === 'sales_manager') { $result['valid'] = true; break; }
            $links = $parents[$current] ?? [];
            if (count($links) !== 1 || (int)$links[0] < 1) { return $result; }
            $current = (int)$links[0];
        }
        if (! $result['valid']) { return $result; }
        $result['reason'] = 'default';
        foreach ($result['chain'] as $id) {
            $mode = $modes[$id] ?? '';
            if ($mode === '' || $mode === 'inherit') { continue; }
            $result['source_id'] = $id;
            $result['allowed'] = $mode === 'allow';
            $result['reason'] = in_array($mode, ['allow','deny'], true) ? 'explicit' : 'invalid_mode';
            break;
        }
        return $result;
    }

    private static function snapshot(): array {
        global $wpdb;
        $rows = $wpdb->get_results("SELECT p.id,p.user_id,p.is_active,p.employment_status,p.hr_display_name,u.display_name,pos.slug position_slug FROM {$wpdb->prefix}sn_hr_profiles p INNER JOIN {$wpdb->users} u ON u.ID=p.user_id INNER JOIN {$wpdb->prefix}sn_hr_positions pos ON pos.id=p.position_id WHERE pos.slug IN ('sales_manager','senior_supervisor','supervisor','seller') ORDER BY p.id LIMIT 20001", ARRAY_A);
        if ($wpdb->last_error || ! is_array($rows) || count($rows) > 20000) { return ['nodes'=>[], 'parents'=>[], 'error'=>true]; }
        $nodes = []; $profile_users = []; $duplicates = [];
        foreach ($rows as $row) {
            $id = (int)$row['user_id']; $profile = (int)$row['id'];
            if (isset($nodes[$id])) { $duplicates[$id] = true; }
            $profile_users[$profile] = $id;
            $nodes[$id] = ['profile_id'=>$profile, 'position'=>$row['position_slug'],
                'label'=>(string)($row['hr_display_name'] ?: $row['display_name']),
                'active'=>(int)$row['is_active'] === 1 && in_array((string)$row['employment_status'], ['', 'active', 'probation'], true)];
        }
        foreach ($duplicates as $id=>$unused) { unset($nodes[$id]); }
        $edges = $wpdb->get_results("SELECT a.child_profile_id,a.parent_profile_id,p.user_id parent_user_id FROM {$wpdb->prefix}sn_hr_assignments a LEFT JOIN {$wpdb->prefix}sn_hr_profiles p ON p.id=a.parent_profile_id WHERE a.is_current=1 AND a.relationship_type='reports_to' ORDER BY a.id LIMIT 40001", ARRAY_A);
        if ($wpdb->last_error || ! is_array($edges) || count($edges) > 40000) { return ['nodes'=>[], 'parents'=>[], 'error'=>true]; }
        $parents = [];
        foreach ($edges as $edge) {
            $child = $profile_users[(int)$edge['child_profile_id']] ?? 0;
            if ($child) { $parents[$child][] = (int)($edge['parent_user_id'] ?? 0); }
        }
        return ['nodes'=>$nodes, 'parents'=>$parents, 'error'=>false];
    }

    private static function modes(array $ids): array {
        global $wpdb;
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (! $ids) { return []; }
        $marks = implode(',', array_fill(0, count($ids), '%d'));
        $rows = $wpdb->get_results($wpdb->prepare("SELECT user_id,meta_value FROM {$wpdb->usermeta} WHERE meta_key=%s AND user_id IN ({$marks}) ORDER BY umeta_id", self::META_KEY, ...$ids), ARRAY_A);
        if ($wpdb->last_error || ! is_array($rows)) { return array_fill_keys($ids, '!invalid'); }
        $out = [];
        foreach ($rows as $row) { $id = (int)$row['user_id']; $out[$id] = array_key_exists($id, $out) ? '!invalid' : (string)$row['meta_value']; }
        return $out;
    }

    public static function allows(int $seller_id): bool {
        $graph = self::snapshot();
        if ($graph['error'] || ($graph['nodes'][$seller_id]['position'] ?? '') !== 'seller') { return false; }
        $chain = self::evaluate($seller_id, $graph['nodes'], $graph['parents'], []);
        return $chain['valid'] && self::evaluate($seller_id, $graph['nodes'], $graph['parents'], self::modes($chain['chain']))['allowed'];
    }

    private static function id($value): int {
        return (is_int($value) || is_string($value)) && preg_match('/^[1-9][0-9]{0,9}$/D', (string)$value) && (float)$value <= 2147483647 ? (int)$value : 0;
    }

    private static function ids($value) {
        if (! is_array($value) || count($value) > 100) { return new WP_Error('input', 'انتخاب نیروها معتبر نیست.', ['status'=>400]); }
        $out = [];
        foreach ($value as $id) { $id = self::id($id); if (! $id) { return new WP_Error('input', 'شناسه نیرو معتبر نیست.', ['status'=>400]); } $out[$id] = $id; }
        return array_values($out);
    }

    private static function selection(array $input) {
        $manager = ($input['manager_id'] ?? '') === '' || ($input['manager_id'] ?? '') === '0' ? 0 : self::id($input['manager_id']);
        if (! $manager && ! in_array($input['manager_id'] ?? '', ['', '0'], true)) { return new WP_Error('input', 'مدیر فروش معتبر نیست.', ['status'=>400]); }
        $out = ['manager'=>$manager];
        foreach (['senior','supervisor','seller'] as $key) {
            $out[$key] = self::ids($input[$key . '_ids'] ?? []);
            if (is_wp_error($out[$key])) { return $out[$key]; }
        }
        return $out;
    }

    private static function context(array $graph, array $selection) {
        $manager = $selection['manager']; $chains = [];
        foreach ($graph['nodes'] as $id=>$node) {
            $chain = self::evaluate((int)$id, $graph['nodes'], $graph['parents'], []);
            if ($chain['valid']) { $chains[$id] = $chain['chain']; }
        }
        if (! $manager) {
            if ($selection['senior'] || $selection['supervisor'] || $selection['seller']) { return new WP_Error('scope', 'ابتدا مدیر فروش را انتخاب کنید.', ['status'=>400]); }
            return ['chains'=>$chains, 'targets'=>[], 'selection'=>$selection];
        }
        if (($graph['nodes'][$manager]['position'] ?? '') !== 'sales_manager' || ! isset($chains[$manager])) { return new WP_Error('scope', 'مدیر فروش انتخاب‌شده فعال یا معتبر نیست.', ['status'=>403]); }
        foreach (['senior'=>'senior_supervisor','supervisor'=>'supervisor','seller'=>'seller'] as $key=>$position) {
            foreach ($selection[$key] as $id) {
                $chain = $chains[$id] ?? [];
                $within = in_array($manager, $chain, true) && ($graph['nodes'][$id]['position'] ?? '') === $position;
                $anchors = $key === 'seller' && $selection['supervisor'] ? $selection['supervisor'] : ($key !== 'senior' ? $selection['senior'] : []);
                if (! $within || ($anchors && ! array_intersect($anchors, $chain))) { return new WP_Error('scope', 'انتخاب‌ها باید از همان شاخهٔ منابع انسانی باشند؛ فهرست را تازه‌سازی کنید.', ['status'=>403]); }
            }
        }
        return ['chains'=>$chains, 'targets'=>array_values(array_unique(array_merge([$manager], $selection['senior'], $selection['supervisor'], $selection['seller']))), 'selection'=>$selection];
    }

    private static function version(int $id, array $graph, array $chain, array $modes): string {
        $state = [];
        foreach ($chain as $user_id) { $state[] = [$user_id, $graph['nodes'][$user_id], $graph['parents'][$user_id] ?? [], $modes[$user_id] ?? '']; }
        return hash('sha256', wp_json_encode([$id, $state]));
    }

    private static function view(array $graph, array $context): array {
        $selection = $context['selection']; $manager = $selection['manager'];
        $out = ['selection'=>$selection, 'managers'=>[], 'senior'=>[], 'supervisor'=>[], 'seller'=>[], 'rows'=>[]];
        foreach ($context['chains'] as $id=>$chain) {
            $node = $graph['nodes'][$id]; $item = ['id'=>(int)$id, 'label'=>$node['label']];
            if ($node['position'] === 'sales_manager') { $out['managers'][] = $item; }
            if (! $manager || ! in_array($manager, $chain, true) || $id === $manager) { continue; }
            if ($node['position'] === 'senior_supervisor') { $out['senior'][] = $item; }
            if ($node['position'] === 'supervisor' && (! $selection['senior'] || array_intersect($selection['senior'], $chain))) { $out['supervisor'][] = $item; }
            $anchors = $selection['supervisor'] ?: $selection['senior'];
            if ($node['position'] === 'seller' && (! $anchors || array_intersect($anchors, $chain))) { $out['seller'][] = $item; }
        }
        $ids = [];
        foreach ($context['targets'] as $id) { $ids = array_merge($ids, $context['chains'][$id]); }
        $modes = self::modes($ids);
        foreach ($context['targets'] as $id) {
            $node = $graph['nodes'][$id]; $decision = self::evaluate($id, $graph['nodes'], $graph['parents'], $modes); $raw = $modes[$id] ?? '';
            $out['rows'][] = ['id'=>$id, 'label'=>$node['label'], 'position'=>self::LABELS[$node['position']],
                'mode'=>in_array($raw, ['allow','deny'], true) ? $raw : 'inherit', 'invalid'=>! in_array($raw, ['', 'inherit', 'allow','deny'], true),
                'allowed'=>$decision['allowed'], 'source'=>$decision['source_id'] ? $graph['nodes'][$decision['source_id']]['label'] : 'پیش‌فرض: جلوگیری از تکرار',
                'version'=>self::version($id, $graph, $context['chains'][$id], $modes)];
        }
        return $out;
    }

    private static function save(array $input, array $selection) {
        global $wpdb;
        $id = self::id($input['user_id'] ?? 0); $mode = $input['mode'] ?? null; $version = $input['version'] ?? null;
        if (! $id || ! is_string($mode) || ! in_array($mode, ['inherit','allow','deny'], true) || ! is_string($version) || ! preg_match('/^[a-f0-9]{64}$/D', $version)) { return new WP_Error('input', 'تنظیم صدور مجدد معتبر نیست.', ['status'=>400]); }
        $graph = self::snapshot(); $context = self::context($graph, $selection);
        if (is_wp_error($context)) { return $context; }
        if (! in_array($id, $context['targets'], true)) { return new WP_Error('scope', 'نیرو باید در انتخاب‌های همین شاخه باشد.', ['status'=>403]); }
        $chain = $context['chains'][$id]; $profile_ids = array_map(static fn($uid)=>(int)$graph['nodes'][$uid]['profile_id'], $chain);
        $tables = [$wpdb->users, $wpdb->usermeta, $wpdb->prefix.'sn_hr_profiles', $wpdb->prefix.'sn_hr_assignments', $wpdb->prefix.'sn_hr_profile_logs'];
        $engines = $wpdb->get_col($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (%s,%s,%s,%s,%s)', ...$tables));
        if ($wpdb->last_error || count($engines) !== 5 || count(array_filter($engines, static fn($v)=>strtoupper((string)$v) !== 'INNODB'))) { return new WP_Error('storage', 'ذخیرهٔ امن این مجوز روی ساختار فعلی پایگاه داده ممکن نیست؛ مدیر سیستم بررسی کند.', ['status'=>503]); }
        if ($wpdb->query('START TRANSACTION') === false) { return new WP_Error('storage', 'ذخیرهٔ مجوز انجام نشد.', ['status'=>500]); }
        try {
            $users = array_values($chain); sort($users, SORT_NUMERIC); sort($profile_ids, SORT_NUMERIC);
            $user_marks = implode(',', array_fill(0, count($users), '%d')); $profile_marks = implode(',', array_fill(0, count($profile_ids), '%d'));
            foreach ([
                $wpdb->prepare("SELECT ID FROM {$wpdb->users} WHERE ID IN ({$user_marks}) ORDER BY ID FOR UPDATE", ...$users),
                $wpdb->prepare("SELECT id FROM {$wpdb->prefix}sn_hr_profiles WHERE id IN ({$profile_marks}) ORDER BY id FOR UPDATE", ...$profile_ids),
                $wpdb->prepare("SELECT id FROM {$wpdb->prefix}sn_hr_assignments WHERE child_profile_id IN ({$profile_marks}) AND is_current=1 AND relationship_type='reports_to' ORDER BY id FOR UPDATE", ...$profile_ids),
            ] as $sql) { $wpdb->get_col($sql); if ($wpdb->last_error) { throw new RuntimeException('storage'); } }
            $graph = self::snapshot(); $context = self::context($graph, $selection);
            if (is_wp_error($context) || ! isset($context['chains'][$id]) || ! in_array($id, $context['targets'], true)) { throw new RuntimeException('conflict'); }
            $modes = self::modes($context['chains'][$id]);
            if (! hash_equals(self::version($id, $graph, $context['chains'][$id], $modes), $version)) { throw new RuntimeException('conflict'); }
            $old = $modes[$id] ?? '';
            if ($old !== $mode && ! ($mode === 'inherit' && $old === '')) {
                if ($old === '!invalid') { throw new RuntimeException('storage'); }
                $changed = $mode === 'inherit' ? delete_user_meta($id, self::META_KEY) : update_user_meta($id, self::META_KEY, $mode);
                if (! $changed || $wpdb->last_error) { throw new RuntimeException('storage'); }
                $logged = $wpdb->insert($wpdb->prefix.'sn_hr_profile_logs', [
                    'profile_id'=>$graph['nodes'][$id]['profile_id'], 'user_id'=>$id, 'actor_user_id'=>get_current_user_id(),
                    'action'=>'invoice_reissue_policy_updated', 'old_value'=>wp_json_encode(['mode'=>in_array($old, ['', 'inherit','allow','deny'], true) ? ($old ?: 'inherit') : 'invalid']),
                    'new_value'=>wp_json_encode(['mode'=>$mode]), 'context'=>wp_json_encode(['source'=>'hr_invoice_reissue', 'sales_manager_id'=>$selection['manager']]), 'created_at'=>current_time('mysql'),
                ]);
                if ($logged === false) { throw new RuntimeException('storage'); }
            }
            if ($wpdb->query('COMMIT') === false) { throw new RuntimeException('storage'); }
        } catch (Throwable $error) {
            $wpdb->query('ROLLBACK'); wp_cache_delete($id, 'user_meta');
            return new WP_Error($error->getMessage() === 'conflict' ? 'conflict' : 'storage', $error->getMessage() === 'conflict' ? 'سلسله‌مراتب یا مجوزها تغییر کرده‌اند؛ فهرست را تازه‌سازی کنید.' : 'مجوز ذخیره نشد؛ هیچ تغییری تأیید نشده است.', ['status'=>$error->getMessage() === 'conflict' ? 409 : 500]);
        }
        wp_cache_delete($id, 'user_meta');
        $graph = self::snapshot(); $context = self::context($graph, $selection);
        return is_wp_error($context) ? $context : self::view($graph, $context);
    }

    public static function ajax(): void {
        if (! is_user_logged_in()) { wp_send_json_error(['message'=>'ابتدا وارد شوید.'], 401); return; }
        if (! is_callable(self::$permission) || ! call_user_func(self::$permission)) { wp_send_json_error(['message'=>'دسترسی منابع انسانی لازم است.'], 403); return; }
        if (! is_string($_POST['nonce'] ?? null) || ! check_ajax_referer('sn_hr_invoice_reissue', 'nonce', false)) { wp_send_json_error(['message'=>'اعتبار صفحه تمام شده؛ صفحه را تازه‌سازی کنید.'], 403); return; }
        nocache_headers();
        $input = wp_unslash($_POST); $selection = self::selection($input);
        $operation = $input['operation'] ?? 'list';
        if (is_wp_error($selection)) { $result = $selection; }
        elseif ($operation === 'save') { $result = self::save($input, $selection); }
        elseif ($operation === 'list') {
            $graph = self::snapshot(); $context = self::context($graph, $selection);
            $result = $graph['error'] ? new WP_Error('storage', 'فهرست منابع انسانی در دسترس نیست.', ['status'=>503]) : (is_wp_error($context) ? $context : self::view($graph, $context));
        } else { $result = new WP_Error('input', 'درخواست معتبر نیست.', ['status'=>400]); }
        if (is_wp_error($result)) { wp_send_json_error(['message'=>$result->get_error_message()], (int)($result->get_error_data()['status'] ?? 400)); return; }
        wp_send_json_success($result);
    }

    public static function render(): void {
        if (! is_callable(self::$permission) || ! call_user_func(self::$permission)) { return; }
        self::assets();
        ?>
        <section class="sn-card sn-hr-reissue" id="sn-hr-invoice-reissue" aria-labelledby="sn-hr-reissue-title">
            <h3 id="sn-hr-reissue-title">صدور مجدد فاکتور برای یک شماره</h3>
            <p>پیش‌فرض، جلوگیری از صدور مجدد توسط همان فروشنده است. تنظیم نزدیک‌تر به فروشنده اولویت دارد؛ «طبق بالادست» مجوز را از سطح بالاتر می‌گیرد.</p>
            <p class="sn-note">تنظیم مدیر فروش به زیرمجموعهٔ بدون استثنا می‌رسد. برای تغییر مجوز فرد مشخص، نام او را در مراحل زیر انتخاب و تنظیم همان ردیف را ذخیره کنید.</p>
            <div class="sn-hr-reissue-filters">
                <label>۱. مدیر فروش<select data-reissue-level="manager"><option value="">انتخاب مدیر فروش</option></select></label>
                <label>۲. سرپرستان ارشد<select data-reissue-level="senior" multiple size="4" disabled></select></label>
                <label>۳. سرپرستان<select data-reissue-level="supervisor" multiple size="4" disabled></select></label>
                <label>۴. فروشندگان<select data-reissue-level="seller" multiple size="4" disabled></select></label>
            </div>
            <p class="sn-muted">در مراحل ۲ تا ۴ می‌توانید چند نفر را انتخاب کنید. انتخاب خالی، فهرست مرحلهٔ بعد را به همهٔ افراد همان شاخه محدود می‌کند و مجوز کسی را تغییر نمی‌دهد.</p>
            <div class="sn-hr-reissue-status" role="status" aria-live="polite"></div>
            <button type="button" class="sn-btn sn-btn-secondary" data-reissue-refresh>تازه‌سازی فهرست</button>
            <div class="sn-table-wrap"><table class="sn-table"><thead><tr><th>نیرو</th><th>سمت</th><th>تنظیم صدور مجدد</th><th>مجوز مؤثر</th><th>منبع مجوز</th><th>ذخیره</th></tr></thead><tbody data-reissue-rows></tbody></table></div>
        </section>
        <?php
    }
}
