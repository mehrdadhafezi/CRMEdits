<?php
if (!defined('ABSPATH')) { exit; }
final class SN_Plus_API_Contract {
    public static function phone($value) {
        if (!is_scalar($value)) { return ''; }
        $value = strtr((string)$value, array('۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9'));
        $value = preg_replace('/[\s()+.\-]/u', '', $value);
        if (strpos($value, '0098') === 0) { $value = '0'.substr($value, 4); }
        elseif (strpos($value, '98') === 0) { $value = '0'.substr($value, 2); }
        elseif (preg_match('/^9[0-9]{9}$/D', $value)) { $value = '0'.$value; }
        return preg_match('/^09[0-9]{9}$/D', $value) ? $value : '';
    }

    public static function uuid($v): bool { return is_string($v) && (bool)preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D',$v); }
    public static function amount($v): string {
        if (is_float($v) || is_int($v)) {
            if (!is_finite((float)$v) || $v<0 || $v>999999999999.99) { throw new RuntimeException('amount'); }
            $v=number_format((float)$v,2,'.','');
        } elseif (is_string($v) && preg_match('/^(0|[1-9][0-9]{0,11})(?:\.([0-9]{1,2}))?$/D',$v,$m)) { $v=$m[1].'.'.str_pad($m[2]??'',2,'0'); }
        else { throw new RuntimeException('amount'); }
        if (!preg_match('/^(0|[1-9][0-9]{0,11})\.[0-9]{2}$/D',$v)) { throw new RuntimeException('amount'); }
        return $v;
    }
    public static function sign(string $body,string $timestamp,string $event,string $secret): string {
        return 'sha256='.hash_hmac('sha256',"SNPLUS-V1\n".$timestamp."\n".$event."\n".hash('sha256',$body),$secret);
    }
    public static function ack($data,string $event,int $revision): bool {
        return is_array($data) && ($data['success']??null)===true && ($data['event_id']??null)===$event && ($data['revision']??null)===$revision && ($data['applied_revision']??null)===$revision;
    }
}
