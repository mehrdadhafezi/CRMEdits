<?php
if (! defined('ABSPATH')) { exit; }

/** Global workforce column filtering; expressions are a fixed server-side whitelist. */
final class SN_HR_Workforce_Filters {
    public static function assets(): void {
        wp_enqueue_script('sn-hr-workforce-filters', SN_PLUGIN_URL . 'assets/js/hr-workforce-filters.js', [], SN_VERSION, true);
        wp_enqueue_style('sn-hr-workforce-filters', SN_PLUGIN_URL . 'assets/css/hr-workforce-filters.css', [], SN_VERSION);
    }

    public static function parse($raw): array {
        if ($raw === '' || $raw === null || $raw === []) { return []; }
        if (! is_string($raw) || strlen($raw) > 16000) { return ['invalid' => true]; }
        $data = json_decode($raw, true);
        if (! is_array($data) || count($data) > 11 || isset($data['invalid'])) { return ['invalid' => true]; }
        $out = [];
        foreach ($data as $key => $row) {
            if (! ctype_digit((string) $key) || (int) $key < 1 || (int) $key > 11 || ! is_array($row)) { return ['invalid' => true]; }
            $query = $row['query'] ?? '';
            $values = $row['values'] ?? null;
            if (! is_string($query) || strlen($query) > 400 || ($values !== null && (! is_array($values) || count($values) > 100))) { return ['invalid' => true]; }
            $clean = null;
            if ($values !== null) {
                $clean = [];
                foreach ($values as $value) {
                    if (! is_string($value) || strlen($value) > 400) { return ['invalid' => true]; }
                    $clean[] = sanitize_text_field($value);
                }
                $clean = array_values(array_unique($clean));
            }
            $query = sanitize_text_field($query);
            if ($query !== '' || $clean !== null) { $out[(int) $key] = ['query' => $query, 'values' => $clean]; }
        }
        return $out;
    }

    public static function expressions($wpdb): array {
        $meta = static function (string $key) use ($wpdb): string {
            return "(SELECT hm.meta_value FROM {$wpdb->usermeta} hm WHERE hm.user_id=p.user_id AND hm.meta_key='{$key}' ORDER BY hm.umeta_id ASC LIMIT 1)";
        };
        $access = static function (string $expr): string {
            return "CASE {$expr} WHEN 'allow' THEN 'مجاز' WHEN 'deny' THEN 'غیرمجاز' ELSE 'طبق سمت (پیش‌فرض)' END";
        };
        return [
            1 => 'p.user_id',
            2 => "COALESCE(NULLIF(p.hr_display_name,''),u.display_name,'')",
            3 => "CONCAT(COALESCE(u.user_login,''),' ',COALESCE(u.user_email,''),' ',COALESCE(p.work_phone,''),' ',COALESCE(p.emergency_phone,''))",
            4 => "COALESCE(NULLIF(pos.label,''),'بدون سمت')",
            5 => "COALESCE(NULLIF(parent.hr_display_name,''),(SELECT hu.display_name FROM {$wpdb->users} hu WHERE hu.ID=parent.user_id),'بدون مدیر مستقیم')",
            6 => "COALESCE(NULLIF(lvl.label,''),'بدون سطح')",
            7 => "CASE COALESCE(NULLIF(p.employment_status,''),'active') WHEN 'active' THEN 'فعال' WHEN 'inactive' THEN 'غیرفعال' WHEN 'suspended' THEN 'تعلیق' WHEN 'resigned' THEN 'استعفا' WHEN 'terminated' THEN 'قطع همکاری' WHEN 'probation' THEN 'آزمایشی' ELSE p.employment_status END",
            8 => "CASE p.employment_type WHEN 'full_time' THEN 'تمام‌وقت' WHEN 'part_time' THEN 'پاره‌وقت' WHEN 'contractor' THEN 'قراردادی' WHEN 'intern' THEN 'کارآموز' ELSE '—' END",
            9 => $access($meta('sn_manual_invoice_access')),
            10 => $access($meta('sn_extra_number_request_access')),
            11 => "CASE " . $meta('sn_team_invoice_source_mode') . " WHEN 'mis_only' THEN 'فقط MIS / شماره تخصیص‌یافته' ELSE 'دستی / آزاد' END",
        ];
    }

    public static function conditions(array $columns, $wpdb): array {
        if (isset($columns['invalid'])) { return [['1=0'], []]; }
        $expressions = self::expressions($wpdb); $where = []; $args = [];
        foreach ($columns as $column => $filter) {
            if (! isset($expressions[$column])) { return [['1=0'], []]; }
            $expr = $expressions[$column];
            if ($filter['query'] !== '') {
                $query = in_array((int) $column, [1,3], true) ? SN_Helpers::to_english_nums($filter['query']) : $filter['query'];
                if ((int) $column === 1) { $query = ltrim($query, '# '); }
                $where[] = "CAST(({$expr}) AS CHAR) LIKE %s";
                $args[] = '%' . $wpdb->esc_like($query) . '%';
            }
            if ($filter['values'] !== null) {
                if (! $filter['values']) { $where[] = '1=0'; continue; }
                $where[] = "({$expr}) IN (" . implode(',', array_fill(0, count($filter['values']), '%s')) . ')';
                array_push($args, ...$filter['values']);
            }
        }
        return [$where, $args];
    }

    public static function order($column, $direction, $wpdb): string {
        $expressions = self::expressions($wpdb);
        if (! is_scalar($column) || ! isset($expressions[(int) $column]) || ! in_array($direction, ['asc','desc'], true)) { return 'pos.sort_order ASC, u.display_name ASC, p.user_id ASC'; }
        return $expressions[(int) $column] . ($direction === 'desc' ? ' DESC' : ' ASC') . ', p.user_id ASC';
    }
}
