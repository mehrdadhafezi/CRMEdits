<?php
if (!defined('ABSPATH')) { exit; }

/** Side-effect-free quotation for the ordinary unpaid-invoice editor. */
final class SN_Invoice_Edit_Quote
{
    public static function selections($invoice, array $items): array
    {
        $selected = array_fill(0, count($items), '');
        foreach (SN_Sales_Catalog::invoice_snapshot($invoice) as $row) {
            $index = $row['line_index'] ?? null;
            if (is_numeric($index) && isset($items[(int)$index])
                && (int)($row['product_id'] ?? 0) === (int)$items[(int)$index]['product_id']) {
                $selected[(int)$index] = (string)($row['option_id'] ?? '');
            }
        }
        return $selected;
    }

    public static function legacy_adjustments($invoice, array $items): bool
    {
        return !empty($invoice->coupon_code) || !empty($invoice->wheel_reward_summary)
            || (float)($invoice->coupon_discount_amount ?? 0) > 0
            || (!SN_Sales_Catalog::invoice_snapshot($invoice)
                && max((float)($invoice->discount_total ?? 0), (float)($invoice->discount_amount ?? 0)) > 0)
            || (bool)array_filter($items, static fn($item) => !empty($item['is_free']));
    }

    public static function revision($invoice, $stage, array $items): string
    {
        return hash('sha256', wp_json_encode([$invoice, $stage, $items]));
    }

    public static function token(int $id, int $actor, string $revision, array $quote, int $expires): string
    {
        return hash_hmac('sha256', wp_json_encode([$id, $actor, $revision, $quote, $expires]), wp_salt('nonce'));
    }

    public static function build($invoice, array $old, array $fresh, $selections, float $current_total, int $actor, bool $force_reprice = false)
    {
        if (!is_array($selections) || count($selections) !== count($old)
            || count($fresh) !== count($old) || count(array_filter($selections, 'is_scalar')) !== count($old)) {
            return new WP_Error('sn_edit_discount_input', 'گزینه‌های تخفیف هر ردیف باید مشخص و معتبر باشند.');
        }
        $selections = array_values(array_map(static fn($value) => trim((string)$value), $selections));
        $same = !$force_reprice && $selections === self::selections($invoice, $old);
        foreach ($old as $i => $item) {
            $same = $same && (int)$item['product_id'] === (int)$fresh[$i]['product_id']
                && (int)$item['qty'] === (int)$fresh[$i]['qty'];
        }
        if ($same) {
            // Payment-plan/amount edits must not silently reprice a frozen quote.
            $pricing = ['original_total'=>(float)($invoice->original_total ?? $current_total),
                'discount_total'=>(float)($invoice->discount_total ?? $invoice->discount_amount ?? 0),
                'final_total'=>$current_total, 'snapshot'=>SN_Sales_Catalog::invoice_snapshot($invoice),
                'manager_user_id'=>(int)($invoice->catalog_manager_user_id ?? 0)];
            return ['repriced'=>false, 'items'=>$old, 'selections'=>$selections, 'pricing'=>$pricing];
        }
        if (self::legacy_adjustments($invoice, $old)) {
            return new WP_Error('sn_edit_legacy_adjustment', 'کوپن، جایزه یا تخفیف قدیمی این فاکتور باید حفظ شود؛ تغییر شرایط پرداخت مجاز است، ولی تغییر اقلام یا تخفیف به محاسبهٔ اختصاصی همان مزایا نیاز دارد.');
        }
        $pricing = SN_Sales_Catalog::instance()->price_items($fresh, $selections, $actor, (int)$invoice->seller_id);
        if (is_wp_error($pricing)) { return $pricing; }
        return ['repriced'=>true, 'items'=>$fresh, 'selections'=>$selections, 'pricing'=>$pricing];
    }

    public static function catalog(int $actor, int $owner): array
    {
        $catalog = SN_Sales_Catalog::instance(); $products = [];
        foreach (SN_Helpers::get_sn_products(0, true) as $product) {
            $id = (int)$product['id'];
            if (SN_Subscription_Plus::instance()->is_product($id)
                || is_wp_error($catalog->validate_products([$id], $actor, $owner))) { continue; }
            $product['discounts'] = $catalog->discounts($id, $actor, $owner);
            $product['allow_custom_discount'] = $catalog->custom_discount_allowed($id, $actor, $owner);
            $products[] = $product;
        }
        return $products;
    }
}
