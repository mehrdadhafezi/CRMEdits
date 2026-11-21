<?php
if (!defined('ABSPATH')) { exit; }

/** Read-only projections of invoices and their payment ledger. Never repairs stored state. */
final class SN_Invoice_Financial_Report
{
    private $db;
    private string $seller;

    public function __construct($db, string $seller = 'i.seller_id')
    {
        $this->db = $db;
        // The expression is assembled from known schema columns by the internal caller.
        $this->seller = $seller;
    }

    public static function status_label(string $status): string
    {
        $labels = [
            'approved'=>'تأیید مالی‌شده', 'pending_financial_approval'=>'در انتظار تأیید مالی',
            'recontact_requested'=>'درخواست تماس مجدد', 'pre_invoice'=>'پیش‌فاکتور',
            'rejected'=>'ردشده', 'partial_paid'=>'پرداخت ناقص', 'pending'=>'در انتظار بررسی',
            'pending_payment'=>'در انتظار پرداخت', 'payment_archived'=>'پرداخت بایگانی‌شده',
            'awaiting_payment'=>'منتظر واریز مشتری', 'customer_followed'=>'پیگیری مشتری انجام‌شده',
            'paid'=>'پرداخت‌شده', 'cancelled'=>'لغوشده', 'completed'=>'تکمیل‌شده',
            'receipt_uploaded'=>'فیش بارگذاری‌شده', 'needs_finance_review'=>'نیازمند بررسی مالی',
            'verified'=>'تأیید درگاه؛ نیازمند ثبت نهایی', 'reconcile_error'=>'نیازمند تطبیق مالی',
            'manual_hold'=>'انتخاب واریز دستی؛ لغو بانکی نیست', 'failed'=>'ناموفق',
            'expired'=>'منقضی‌شده', 'archived'=>'بایگانی‌شده', 'financial_rejected'=>'رد مالی‌شده',
            'payment_rejected'=>'پرداخت ردشده', 'success'=>'موفق (وضعیت قدیمی)',
        ];
        return $labels[$status] ?? ($status === '' ? 'ثبت نشده' : 'وضعیت ناشناخته (' . $status . ')');
    }

    public static function method_label(string $method): string
    {
        $labels = ['online'=>'آنلاین','gateway'=>'آنلاین','zibal'=>'آنلاین','zarinpal'=>'آنلاین',
            'asanpardakht'=>'آنلاین','card'=>'کارت‌به‌کارت','card_to_card'=>'کارت‌به‌کارت',
            'receipt'=>'کارت‌به‌کارت','cash'=>'نقدی','cheque'=>'چکی','check'=>'چکی',
            'admin'=>'ثبت اداری؛ روش انتقال نامشخص'];
        return $labels[$method] ?? ($method === '' ? 'نامشخص' : 'روش نامشخص (' . $method . ')');
    }

    public static function source_label(string $source): string
    {
        $labels = ['zibal'=>'درگاه زیبال','zarinpal'=>'درگاه زرین‌پال','asanpardakht'=>'درگاه آسان‌پرداخت',
            'gateway'=>'درگاه پرداخت','supervisor_manual'=>'ثبت دستی سرپرست','seller_manual'=>'ثبت دستی فروشنده',
            'supervisor_upload'=>'بارگذاری سرپرست','customer_upload'=>'بارگذاری مشتری',
            'seller_upload'=>'بارگذاری فروشنده','seller_resubmit'=>'ارسال مجدد فروشنده',
            'admin_upload'=>'بارگذاری مدیر','admin'=>'ثبت مدیر','finance_approval'=>'تأیید واحد مالی',
            'converter_manual'=>'ثبت دستی تبدیل‌کننده','converter_upload'=>'بارگذاری تبدیل‌کننده'];
        return $labels[$source] ?? ($source === '' ? 'ثبت نشده' : 'مسیر نامشخص (' . $source . ')');
    }

    private static function gateway(string $p): string
    {
        return "(COALESCE({$p}.authority,'')<>'' OR COALESCE({$p}.pay_method,'') IN ('online','gateway','zibal','zarinpal','asanpardakht'))";
    }

    private static function pending(string $p): string
    {
        return "({$p}.status IN ('pending','pending_financial_approval','receipt_uploaded') AND NOT " . self::gateway($p) . ')';
    }

    private static function closed(string $alias): string
    {
        $parts = [];
        foreach (['status','invoice_status','payment_status'] as $field) {
            $parts[] = "COALESCE({$alias}.{$field},'') IN ('cancelled','payment_archived','archived')";
        }
        $parts[] = "COALESCE({$alias}.payment_workflow_status,'')='archived'";
        return '(' . implode(' OR ',$parts) . ')';
    }

    /** Fixed SQL identifiers only. Unknown filter values remain prepared values. */
    public static function condition($db, string $status, string $alias = 'i'): array
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/D', $alias)) { throw new InvalidArgumentException('Invalid invoice alias'); }
        $payments = $db->prefix . 'sn_payments';
        $confirmed = "EXISTS (SELECT 1 FROM {$payments} fp WHERE fp.invoice_id={$alias}.id AND fp.status IN ('approved','paid'))";
        $has_ledger = "EXISTS (SELECT 1 FROM {$payments} fp WHERE fp.invoice_id={$alias}.id)";
        $pending = "EXISTS (SELECT 1 FROM {$payments} fp WHERE fp.invoice_id={$alias}.id AND " . self::pending('fp') . ')';
        // Cancellation is document/workflow state; payment_archived stays separately identifiable.
        $closed = self::closed($alias);
        $legacy_review = "({$alias}.status IN ('receipt_uploaded','pending_financial_approval','needs_finance_review') OR {$alias}.payment_status IN ('receipt_uploaded','pending_financial_approval','needs_finance_review') OR {$alias}.invoice_status IN ('receipt_uploaded','pending_financial_approval','needs_finance_review'))";
        if ($status === 'needs_review') { return ["({$pending} OR (NOT {$has_ledger} AND NOT {$closed} AND {$legacy_review} AND COALESCE({$alias}.status,'') NOT IN ('approved','paid','rejected') AND COALESCE({$alias}.invoice_status,'') NOT IN ('approved','paid','rejected')))", []]; }
        if ($status === 'paid') { return [$confirmed, []]; }
        if ($status === 'cancelled') { return [$closed, []]; }
        if ($status === 'pre_invoice') {
            return ["(NOT {$confirmed} AND NOT {$closed} AND COALESCE({$alias}.status,'') NOT IN ('paid','approved','completed') AND COALESCE({$alias}.invoice_status,'') NOT IN ('paid','approved','completed'))", []];
        }
        if ($status !== '' && $status !== 'all') { return ["{$alias}.status=%s", [$status]]; }
        return ['1=1', []];
    }

    public static function financial_condition($db, string $filter): array
    {
        $sum = "(SELECT COALESCE(SUM(fp.amount),0) FROM {$db->prefix}sn_payments fp WHERE fp.invoice_id=i.id AND fp.status IN ('approved','paid'))";
        $total = 'COALESCE(NULLIF(i.payment_total_amount,0),NULLIF(i.final_total,0),NULLIF(i.product_price,0),0)';
        $conditions = [
            'unpaid'=>"{$sum}=0", 'partial'=>"({$sum}>0 AND {$sum}<{$total}-0.005)",
            'settled'=>"({$total}>0 AND ABS({$sum}-{$total})<=0.005)",
            'overpaid'=>"{$sum}>{$total}+0.005",
            'mismatch'=>"ABS(COALESCE(i.paid_total_amount,0)-{$sum})>0.005",
            'unapplied'=>"EXISTS (SELECT 1 FROM {$db->prefix}sn_payments fp WHERE fp.invoice_id=i.id AND fp.status IN ('verified','reconcile_error'))",
        ];
        if ($filter === '' || $filter === 'all') { return ['1=1',[]]; }
        return [$conditions[$filter] ?? '0=1',[]];
    }

    private function read(string $sql, array $args = []): array
    {
        $rows = $this->db->get_results($args ? $this->db->prepare($sql, ...$args) : $sql, ARRAY_A);
        if ($this->db->last_error !== '' || !is_array($rows)) { throw new RuntimeException('خواندن کامل گزارش مالی ممکن نشد؛ ساختار دفتر پرداخت را بررسی کنید.'); }
        return $rows;
    }

    private function source(string $where): string
    {
        $p = $this->db->prefix;
        $gateway = self::gateway('p'); $pending = self::pending('p');
        $confirmed = "p.status IN ('approved','paid')";
        $online = "p.pay_method IN ('online','gateway','zibal','zarinpal','asanpardakht')";
        $card = "p.pay_method IN ('card','card_to_card','receipt')";
        $amount = 'COALESCE(NULLIF(i.payment_total_amount,0),NULLIF(i.final_total,0),NULLIF(i.product_price,0),0)';
        [$review] = self::condition($this->db,'needs_review');
        // A stable normalized phone is used only for grouping; no customer record is changed.
        $phone = "COALESCE(i.customer_phone,'')";
        foreach (['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
                  '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
                  '+'=>'',' '=>'','-'=>'','('=>'',')'=>''] as $from=>$to) { $phone = "REPLACE({$phone},'{$from}','{$to}')"; }
        $key = "CASE WHEN {$phone}='' THEN CONCAT('INV-',i.id) WHEN LENGTH({$phone})=14 AND SUBSTR({$phone},1,4)='0098' THEN CONCAT('0',SUBSTR({$phone},5)) WHEN LENGTH({$phone})=12 AND SUBSTR({$phone},1,2)='98' THEN CONCAT('0',SUBSTR({$phone},3)) WHEN LENGTH({$phone})=10 AND SUBSTR({$phone},1,1)='9' THEN CONCAT('0',{$phone}) ELSE {$phone} END";
        return "SELECT i.id,i.invoice_code,i.customer_name,i.customer_phone,{$key} customer_key,
            {$this->seller} seller_id,i.product_id,i.province,i.city,i.status,i.invoice_status,i.payment_status,
            i.pay_method,i.payment_source,i.created_at,i.paid_at,i.current_payment_stage,i.payment_workflow_status,
            CASE WHEN {$review} THEN 1 ELSE 0 END review_required,
            {$amount} total_amount,COALESCE(i.paid_total_amount,0) stored_paid_amount,
            COALESCE(f.confirmed_amount,0) confirmed_amount,COALESCE(f.pending_amount,0) pending_amount,
            COALESCE(f.confirmed_count,0) confirmed_count,COALESCE(f.pending_count,0) pending_count,
            COALESCE(f.transaction_count,0) transaction_count,COALESCE(f.ledger_count,0) ledger_count,
            COALESCE(f.online_amount,0) online_amount,COALESCE(f.card_amount,0) card_amount,
            COALESCE(f.other_amount,0) other_amount,COALESCE(f.unapplied_amount,0) unapplied_amount,
            GREATEST({$amount}-COALESCE(f.confirmed_amount,0),0) remaining_amount,
            COALESCE(i.paid_total_amount,0)-COALESCE(f.confirmed_amount,0) discrepancy_amount
            FROM {$p}sn_invoices i LEFT JOIN (
                SELECT p.invoice_id,COUNT(*) ledger_count,
                SUM(CASE WHEN {$confirmed} OR {$pending} OR p.status IN ('verified','reconcile_error') OR (p.status='rejected' AND NOT {$gateway}) THEN 1 ELSE 0 END) transaction_count,
                SUM(CASE WHEN {$confirmed} THEN 1 ELSE 0 END) confirmed_count,
                SUM(CASE WHEN {$pending} THEN 1 ELSE 0 END) pending_count,
                SUM(CASE WHEN {$confirmed} THEN p.amount ELSE 0 END) confirmed_amount,
                SUM(CASE WHEN {$pending} THEN p.amount ELSE 0 END) pending_amount,
                SUM(CASE WHEN {$confirmed} AND {$online} THEN p.amount ELSE 0 END) online_amount,
                SUM(CASE WHEN {$confirmed} AND {$card} THEN p.amount ELSE 0 END) card_amount,
                SUM(CASE WHEN {$confirmed} AND NOT COALESCE({$online},0) AND NOT COALESCE({$card},0) THEN p.amount ELSE 0 END) other_amount,
                SUM(CASE WHEN p.status IN ('verified','reconcile_error') THEN p.amount ELSE 0 END) unapplied_amount
                FROM {$p}sn_payments p GROUP BY p.invoice_id
            ) f ON f.invoice_id=i.id WHERE {$where}";
    }

    public function invoices(string $where, array $args, int $after = 0, int $size = 200): array
    {
        $size = max(1, min(500, $size));
        return $this->read('SELECT q.* FROM (' . $this->source($where) . ') q WHERE q.id>%d ORDER BY q.id ASC LIMIT %d', array_merge($args, [$after,$size]));
    }

    public function for_ids(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('absint',$ids))));
        if (!$ids) { return []; }
        $rows = $this->read($this->source('i.id IN (' . implode(',',array_fill(0,count($ids),'%d')) . ')'), $ids);
        return array_column($rows, null, 'id');
    }

    public function item_labels(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('absint',$ids))));
        $parts = [];
        foreach (array_chunk($ids,200) as $chunk) {
            $marks = implode(',',array_fill(0,count($chunk),'%d'));
            foreach ($this->read("SELECT invoice_id,product_name,qty FROM {$this->db->prefix}sn_invoice_items WHERE invoice_id IN ({$marks}) ORDER BY invoice_id,id",$chunk) as $item) {
                $parts[(int)$item['invoice_id']][] = trim((string)$item['product_name']) . ((int)$item['qty']>1 ? ' × '.(int)$item['qty'] : '');
            }
        }
        return array_map(static fn($names)=>implode('، ',$names),$parts);
    }

    public function summary(string $where, array $args): array
    {
        return $this->read('SELECT ' . $this->metrics() . ' FROM (' . $this->source($where) . ') q', $args)[0] ?? [];
    }

    private function metrics(): string
    {
        $columns = ['total_amount','confirmed_amount','pending_amount','remaining_amount','online_amount','card_amount','other_amount','unapplied_amount','transaction_count','confirmed_count','pending_count'];
        $closed = self::closed('q');
        $metrics = ['COUNT(*) invoice_count', "SUM(CASE WHEN q.confirmed_count>0 THEN 1 ELSE 0 END) paid_count",
            "SUM(CASE WHEN q.confirmed_count=0 AND NOT {$closed} AND COALESCE(q.status,'') NOT IN ('paid','approved','completed') AND COALESCE(q.invoice_status,'') NOT IN ('paid','approved','completed') THEN 1 ELSE 0 END) pre_invoice_count",
            'COUNT(DISTINCT CASE WHEN q.confirmed_count>0 THEN q.customer_key ELSE NULL END) paying_customers',
            'SUM(CASE WHEN ABS(q.discrepancy_amount)>0.005 THEN 1 ELSE 0 END) discrepancy_count'];
        $metrics[] = "COALESCE(SUM(CASE WHEN NOT {$closed} THEN q.remaining_amount ELSE 0 END),0) active_remaining_amount";
        $metrics[] = "COALESCE(SUM(CASE WHEN {$closed} THEN q.remaining_amount ELSE 0 END),0) closed_remaining_amount";
        foreach ($columns as $column) { $metrics[] = "COALESCE(SUM(q.{$column}),0) {$column}"; }
        return implode(',', $metrics);
    }

    public function groups(string $where, array $args, string $type = 'sellers'): array
    {
        $key = $type === 'customers' ? 'q.customer_key' : 'q.seller_id';
        return $this->read("SELECT {$key} group_key,MAX(q.customer_name) customer_name," . $this->metrics() . ' FROM (' . $this->source($where) . ") q GROUP BY {$key} ORDER BY confirmed_amount DESC,group_key ASC", $args);
    }

    public function payments(string $where, array $args, int $after = 0): array
    {
        $p = $this->db->prefix;
        return $this->read("SELECT p.id,p.invoice_id,i.invoice_code,i.customer_name,i.customer_phone,{$this->seller} seller_id,
            p.payment_stage_no,p.amount,p.status,p.pay_method,p.payment_source,p.created_at,p.updated_at,
            CASE WHEN " . self::gateway('p') . " THEN 1 ELSE 0 END is_gateway,
            (SELECT MAX(s.requested_amount) FROM {$p}sn_invoice_payment_stages s WHERE s.invoice_id=p.invoice_id AND s.stage_no=p.payment_stage_no) stage_amount
            FROM {$p}sn_payments p INNER JOIN {$p}sn_invoices i ON i.id=p.invoice_id
            WHERE ({$where}) AND p.id>%d ORDER BY p.id ASC LIMIT 200", array_merge($args,[$after]));
    }

    public static function settlement(array $row): string
    {
        $confirmed = (float)($row['confirmed_amount'] ?? 0); $total = (float)($row['total_amount'] ?? 0);
        if ($confirmed <= 0) { return 'بدون دریافت تأییدشده'; }
        if ($total <= 0) { return 'مبلغ فاکتور نامعتبر؛ نیازمند بررسی'; }
        if ($confirmed > $total + 0.005) { return 'دریافت بیش از مبلغ؛ نیازمند بررسی'; }
        return $total - $confirmed > 0.005 ? 'تسویه ناقص' : 'تسویه کامل';
    }

    public static function finance_label(array $row): string
    {
        if ((int)($row['pending_count'] ?? 0)>0) { return (int)$row['confirmed_count']>0 ? 'دریافت تأییدشده + واریز در انتظار بررسی' : 'واریز در انتظار بررسی مالی'; }
        if ((float)($row['unapplied_amount'] ?? 0)>0) { return 'پرداخت درگاه نیازمند ثبت یا تطبیق نهایی'; }
        return (int)($row['confirmed_count'] ?? 0)>0 ? 'دریافت تأییدشده در دفتر پرداخت' : 'دریافت تأییدشده در دفتر ثبت نشده';
    }

    /** CSV cells are literal text even after whitespace/control characters. */
    public static function safe_cell($value): string
    {
        $value = (string)$value;
        $value = preg_replace('/https?:\/\/[^\s,"]+/i','[hidden-url]',$value);
        $value = preg_replace('/(access_token|token|password|hash)=([^&\s,"]+)/i','$1=[hidden]',$value);
        return preg_match('/^[\s\x00-\x20]*[=+@\-]/u', $value) ? "'" . $value : $value;
    }

    public static function write_row($stream, array $values, bool $xls, array $numeric = []): void
    {
        if (!$xls) {
            $cells = [];
            foreach (array_values($values) as $index=>$value) {
                $cells[] = in_array($index,$numeric,true) && preg_match('/^-?[0-9]+(?:\.[0-9]+)?$/D',(string)$value) ? (string)$value : self::safe_cell($value);
            }
            if (fputcsv($stream, $cells, ',', '"', '') === false) { throw new RuntimeException('Export write failed'); }
            return;
        }
        $xml = '<Row>';
        foreach (array_values($values) as $index=>$value) {
            $number = in_array($index,$numeric,true) && preg_match('/^-?[0-9]+(?:\.[0-9]+)?$/D',(string)$value);
            $value = $number ? (string)$value : self::safe_cell($value);
            $value = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u','',(string)$value);
            // Only explicitly typed amounts/counts become numbers; identifiers stay strings.
            $xml .= '<Cell><Data ss:Type="' . ($number ? 'Number' : 'String') . '">' . htmlspecialchars($value, ENT_XML1|ENT_QUOTES,'UTF-8') . '</Data></Cell>';
        }
        if (fwrite($stream,$xml.'</Row>')===false) { throw new RuntimeException('Export write failed'); }
    }
}
