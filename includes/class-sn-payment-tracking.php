<?php
if (!defined('ABSPATH')) { exit; }

/** Optional bank reference metadata; never a gateway authority or approval. */
final class SN_Payment_Tracking
{
    public const FIELD = 'manual_tracking_number';
    public const ERROR = 'شماره پیگیری باید حداکثر ۶۴ کاراکتر و شامل حروف انگلیسی، عدد، خط تیره، زیرخط، نقطه یا / باشد.';

    /** Exact stage alias, correlated to the caller's already scoped invoice. */
    public static function stage_condition(string $invoice_id, string $invoices, string $stages, string $query): array
    {
        if (!preg_match('/^[A-Za-z0-9_]+\.id$/D', $invoice_id)
            || !preg_match('/^[A-Za-z0-9_]+$/D', $invoices)
            || !preg_match('/^[A-Za-z0-9_]+$/D', $stages)) {
            throw new InvalidArgumentException('Invalid stage search identifier');
        }
        $query = self::normalize($query);
        if ($query === null || !preg_match('~^([A-Z0-9][A-Z0-9._/-]*)/([1-9][0-9]{0,8})$~D', $query, $match)) { return ['0=1', []]; }
        return ["EXISTS (SELECT 1 FROM {$invoices} si INNER JOIN {$stages} ss ON ss.invoice_id=si.id WHERE si.id={$invoice_id} AND si.invoice_code=%s AND ss.stage_no=%d)", [$match[1], (int)$match[2]]];
    }

    /** Null means invalid; empty string is a valid omitted optional reference. */
    public static function normalize($value): ?string
    {
        if ($value === null) { return ''; }
        if (!is_string($value) && !is_int($value)) { return null; }
        $value = trim(strtr((string)$value, [
            '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
            '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
        ]));
        if ($value === '') { return ''; }
        return preg_match('~^[A-Za-z0-9][A-Za-z0-9._/-]{0,63}$~D', $value) ? strtoupper($value) : null;
    }

    /** Append inside the caller's search OR group; caller retains auth/scope/date filters. */
    public static function condition(string $invoice_id, string $payments, string $query, array $columns): array
    {
        if (!preg_match('/^[A-Za-z0-9_]+\.id$/D', $invoice_id)
            || !preg_match('/^[A-Za-z0-9_]+$/D', $payments)) {
            throw new InvalidArgumentException('Invalid payment tracking identifier');
        }
        $query = self::normalize($query);
        if ($query === null || $query === '' || !in_array('proof_data',$columns,true)
            || !in_array('invoice_id',$columns,true)) { return ['0=1', []]; }
        // JSON_EXTRACT must never receive corrupt legacy JSON, even if SQL reorders predicates.
        $document = "CASE WHEN JSON_VALID(pt.proof_data) THEN pt.proof_data ELSE '{}' END";
        $parts = []; $args = []; $like = '%' . strtr($query, ['='=>'==','%'=>'=%','_'=>'=_']) . '%';
        foreach ([self::FIELD, 'manual_tracking_last6'] as $field) {
            $value = "JSON_EXTRACT({$document}, '$.{$field}')";
            $parts[] = "(JSON_TYPE({$value})='STRING' AND UPPER(JSON_UNQUOTE({$value})) LIKE %s ESCAPE '=')";
            $args[] = $like;
        }
        $manual = in_array('authority',$columns,true) ? " AND COALESCE(pt.authority,'')=''" : '';
        return ["EXISTS (SELECT 1 FROM {$payments} pt WHERE pt.invoice_id={$invoice_id}{$manual} AND (" . implode(' OR ', $parts) . '))', $args];
    }
}
