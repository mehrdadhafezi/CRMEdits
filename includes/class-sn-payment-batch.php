<?php
if (!defined('ABSPATH')) { exit; }

require_once __DIR__ . '/class-sn-payment-tracking.php';

/** Batch validation and transaction boundary; individual saves use the existing payment service. */
final class SN_Payment_Batch
{
    public const MAX_ROWS = 10;

    public static function decode($json): array
    {
        if (!is_string($json) || strlen($json) > 65536) { return ['error'=>'اطلاعات واریزی‌ها معتبر نیست.']; }
        $rows = json_decode(wp_unslash($json), true);
        if (!is_array($rows) || count($rows) < 1 || count($rows) > self::MAX_ROWS || array_keys($rows) !== range(0, count($rows)-1)) { return ['error'=>'در هر نوبت بین ۱ تا ۱۰ واریزی ثبت کنید.']; }
        foreach ($rows as $row) {
            if (!is_array($row)) { return ['error'=>'ساختار ردیف واریزی معتبر نیست.']; }
            foreach ($row as $value) { if (!is_scalar($value) && $value !== null) { return ['error'=>'اطلاعات هر واریزی باید مقدار ساده باشد.']; } }
        }
        return ['rows'=>$rows];
    }

    /** Validate the whole multipart envelope before moving files. */
    public static function check_files(array $rows, array $files): string
    {
        $count = 0; $bytes = 0;
        foreach ($rows as $index => $row) {
            $file = $files['receipt_' . $index] ?? [];
            $names = $file['name'] ?? [];
            if (!is_array($names)) { return 'ساختار فایل‌های فیش معتبر نیست.'; }
            $expected = (string)($row['receipt_count'] ?? '0');
            if (!preg_match('/^(?:[0-9]|10)$/D', $expected) || (int)$expected !== count($names)) { return 'همه فیش‌های انتخاب‌شده به سرور نرسیده‌اند؛ تعداد و حجم فایل‌ها را بررسی کنید.'; }
            foreach ($names as $key => $name) {
                if (!is_string($name) || !isset($file['size'][$key], $file['error'][$key], $file['tmp_name'][$key]) || !is_string($file['tmp_name'][$key]) || !is_numeric($file['size'][$key]) || !is_numeric($file['error'][$key]) || (int)$file['error'][$key] !== UPLOAD_ERR_OK || (int)$file['size'][$key] <= 0) { return 'یک یا چند فیش کامل دریافت نشد.'; }
                $count++; $bytes += (int)$file['size'][$key];
            }
        }
        foreach ($files as $key => $unused) {
            if (!preg_match('/^receipt_([0-9])$/D', (string)$key, $match) || (int)$match[1] >= count($rows)) { return 'فایل بدون ردیف واریزی معتبر ارسال شده است.'; }
        }
        if ($count > 10 || $bytes > 20 * 1024 * 1024) { return 'در مجموع حداکثر ۱۰ فیش و ۲۰ مگابایت در هر نوبت مجاز است.'; }
        return '';
    }

    public static function fields(array $row, bool $internal, callable $destination, callable $date_check): array
    {
        $amount = SN_Payment_Transactions::parse_amount($row['amount'] ?? '');
        if (!is_finite($amount) || $amount <= 0) { return ['error'=>'مبلغ همین واریزی را به تومان وارد کنید.']; }
        $mode = sanitize_key((string)($row['entry_mode'] ?? ''));
        if (!in_array($mode, ['receipt','info'], true)) { return ['error'=>'روش ثبت واریزی را انتخاب کنید.']; }
        $fields = array_fill_keys(['manual_transfer_type','manual_account_last6','manual_tracking_last6','manual_account_owner','manual_card_from','manual_card_to','manual_card_to_number','manual_paid_at','manual_paid_at_jalali'], null);
        $tracking = SN_Payment_Tracking::normalize($row[SN_Payment_Tracking::FIELD] ?? '');
        if ($tracking === null) { return ['error'=>SN_Payment_Tracking::ERROR]; }
        $fields[SN_Payment_Tracking::FIELD] = $tracking !== '' ? $tracking : null;
        $fields['transaction_amount'] = $amount;
        if ($mode === 'receipt') {
            foreach (['card_from'=>'manual_card_from','card_to'=>'manual_card_to'] as $input=>$column) {
                $raw = $row[$input] ?? '';
                if (!is_scalar($raw)) { return ['error'=>'چهار رقم کارت اختیاری است؛ در صورت ورود باید دقیقاً چهار رقم باشد.']; }
                $value = trim(SN_Helpers::to_english_nums((string)$raw));
                if ($value !== '' && !preg_match('/^[0-9]{4}$/D', $value)) { return ['error'=>'چهار رقم کارت اختیاری است؛ در صورت ورود باید دقیقاً چهار رقم باشد.']; }
                $fields[$column] = $value === '' ? null : $value;
            }
            if ((int)($row['receipt_count'] ?? 0) < 1) { return ['error'=>'حداقل یک فیش برای این واریزی انتخاب کنید.']; }
            return ['fields'=>$fields];
        }
        $type = sanitize_key((string)($row['manual_transfer_type'] ?? 'card'));
        if (!in_array($type, ['card','paya','pol','account'], true)) { return ['error'=>'روش انتقال معتبر نیست.']; }
        $fields['manual_transfer_type'] = $type;
        $from = trim(SN_Helpers::to_english_nums((string)($row['card_from'] ?? '')));
        if ($type === 'card') {
            if (!preg_match('/^[0-9]{4}$/D', $from)) { return ['error'=>'۴ رقم آخر کارت مبدأ را وارد کنید.']; }
            $fields['manual_card_from'] = $from;
        } else {
            $owner = sanitize_text_field((string)($row['manual_account_owner'] ?? ''));
            if (!preg_match('/^.{1,191}$/us', $owner)) { return ['error'=>'نام صاحب حساب مبدأ را وارد کنید (حداکثر ۱۹۱ کاراکتر).']; }
            $fields['manual_account_owner'] = $owner;
            foreach (['manual_account_last6','manual_tracking_last6'] as $key) {
                $value = trim(SN_Helpers::to_english_nums((string)($row[$key] ?? '')));
                if ($value !== '' && !preg_match('/^[0-9]{6}$/D', $value)) { return ['error'=>'حساب و پیگیری در صورت ورود باید دقیقاً ۶ رقم باشند.']; }
                $fields[$key] = $value !== '' ? $value : null;
            }
        }
        $to = $destination((string)($row['card_to'] ?? ''), $internal);
        if (empty($to['valid']) || (!$internal && !preg_match('/^[0-9]{4}$/D', (string)($to['last4'] ?? '')))) { return ['error'=>'کارت مقصد معتبر نیست؛ در پنل کارکنان ورود ۴ رقم آخر مقصد اختیاری است.']; }
        $date = $date_check((string)($row['paid_at'] ?? ''));
        if (empty($date['success'])) { return ['error'=>(string)($date['message'] ?? 'تاریخ و ساعت واریز معتبر نیست.')]; }
        $fields['manual_card_to'] = (string)($to['last4'] ?? '');
        $fields['manual_card_to_number'] = !empty($to['number']) ? (string)$to['number'] : null;
        $fields['manual_paid_at'] = (string)$date['mysql'];
        $fields['manual_paid_at_jalali'] = (string)$date['jalali'];
        return ['fields'=>$fields];
    }

    public static function save(int $invoice_id, string $source, array $prepared, callable $save): array
    {
        global $wpdb;
        foreach (['sn_invoices','sn_invoice_payment_stages','sn_payments'] as $suffix) {
            $engine = $wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $wpdb->prefix.$suffix));
            if (strtolower((string)$engine) !== 'innodb') { return ['success'=>false,'message'=>'ثبت یکجای چند واریزی به جداول InnoDB نیاز دارد؛ مدیر سایت تنظیمات دیتابیس را بررسی کند.']; }
        }
        // Share the gateway/review lock before taking SQL row locks. Hold it through the outer commit.
        $stage_no = max(1, (int)$wpdb->get_var($wpdb->prepare("SELECT current_payment_stage FROM {$wpdb->prefix}sn_invoices WHERE id=%d", $invoice_id)));
        $lock_name = SN_Invoice::stage_lock_name($invoice_id, $stage_no);
        if ((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lock_name, 8)) !== 1) {
            SN_Helpers::cleanup_receipt_uploads();
            return ['success'=>false,'message'=>'درخواست دیگری برای همین مرحله در حال پردازش است؛ دوباره تلاش کنید.'];
        }
        try {
            if (false === $wpdb->query('START TRANSACTION')) { throw new RuntimeException('آغاز ثبت واریزی‌ها انجام نشد.'); }
            $invoice = $wpdb->get_row($wpdb->prepare("SELECT id,current_payment_stage FROM {$wpdb->prefix}sn_invoices WHERE id=%d FOR UPDATE", $invoice_id));
            if (!$invoice) { throw new RuntimeException('فاکتور یافت نشد.'); }
            if (max(1,(int)$invoice->current_payment_stage) !== $stage_no) { throw new RuntimeException('مرحله پرداخت تغییر کرده است؛ اطلاعات را تازه‌سازی کنید.'); }
            $results = []; $keep = [];
            foreach ($prepared as $index => $fields) {
                $result = $save($invoice_id, $source, $fields, true);
                if (empty($result['success'])) { throw new RuntimeException('واریزی '.($index+1).': '.($result['message'] ?? 'ثبت انجام نشد.')); }
                $results[] = $result;
                if (empty($result['already'])) { $keep = array_merge($keep, SN_Helpers::receipt_urls($fields)); }
            }
            if (false === $wpdb->query('COMMIT')) { throw new RuntimeException('ثبت نهایی مجموعه انجام نشد.'); }
            SN_Helpers::commit_receipt_uploads($keep);
            return ['success'=>true,'payments'=>$results,'count'=>count($results)];
        } catch (Throwable $error) {
            $wpdb->query('ROLLBACK');
            SN_Helpers::cleanup_receipt_uploads();
            return ['success'=>false,'message'=>$error->getMessage()];
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
        }
    }
}
