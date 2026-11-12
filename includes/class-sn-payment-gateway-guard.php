<?php
if (!defined('ABSPATH')) { exit; }

/** One read/write contract for manual payment versus an unresolved gateway session. */
final class SN_Payment_Gateway_Guard {
    public static function snapshot(int $invoice_id,int $stage_no): array {
        global $wpdb;
        $rows=$wpdb->get_results($wpdb->prepare("SELECT id,status,authority,amount,created_at FROM {$wpdb->prefix}sn_payments WHERE invoice_id=%d AND payment_stage_no=%d AND COALESCE(authority,'')<>'' AND status IN ('pending','verified','paid','approved') ORDER BY id ASC",$invoice_id,$stage_no),ARRAY_A);
        if($wpdb->last_error!=='' || !is_array($rows))return ['blocked'=>true,'can_check'=>false,'code'=>'gateway_storage_error','message'=>'خواندن وضعیت پرداخت‌ها کامل نشد؛ وضعیت فاکتور را بازخوانی کنید. خطای دیتابیس به معنی وجود پرداخت درگاهی نیست.','requests'=>[]];
        $out=['blocked'=>!empty($rows),'can_check'=>false,'code'=>'','message'=>'','requests'=>[]];
        foreach($rows as $row){$out['requests'][]=['payment_id'=>(int)$row['id'],'status'=>(string)$row['status'],'amount'=>(float)$row['amount']];if($row['status']==='pending' && preg_match('/^zibal:[1-9][0-9]*$/D',(string)$row['authority']))$out['can_check']=true;}
        if($rows){$paid=(bool)array_filter($rows,fn($r)=>in_array($r['status'],['verified','paid','approved'],true));$out['code']=$paid?'gateway_recorded':'gateway_pending';$out['message']=$paid?'برای همین مرحله پرداخت درگاهی ثبت شده است؛ پیش از واریز دستی، واحد مالی وضعیت آن را تطبیق دهد.':'برای همین مرحله یک درخواست درگاه تعیین‌تکلیف‌نشده وجود دارد. ابتدا «بررسی وضعیت درگاه» را بزنید؛ این وضعیت به‌تنهایی نشانه پرداخت موفق نیست.';if(!$out['can_check']&&!$paid)$out['message'].=' استعلام خودکار این درخواست در دسترس نیست؛ واحد مالی باید آن را بررسی کند.';}
        return $out;
    }
    /** Only a provider-confirmed terminal state may retire a pending local request. No age-only expiry. */
    public static function check(int $invoice_id,int $stage_no,callable $inquiry): array {
        global $wpdb;$lock=SN_Invoice::stage_lock_name($invoice_id,$stage_no);
        if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,3)',$lock))!==1)return ['success'=>false,'message'=>'عملیات دیگری برای همین مرحله در حال اجراست؛ پس از پایان آن وضعیت را بازخوانی کنید.'];
        try {
            $stage=(int)$wpdb->get_var($wpdb->prepare("SELECT current_payment_stage FROM {$wpdb->prefix}sn_invoices WHERE id=%d",$invoice_id));
            if($wpdb->last_error!==''||max(1,$stage)!==$stage_no)return ['success'=>false,'message'=>'مرحله پرداخت تغییر کرده یا خواندن آن کامل نشده است؛ وضعیت را بازخوانی کنید.'];
            $state=self::snapshot($invoice_id,$stage_no);if(!$state['blocked'])return ['success'=>true,'message'=>'درخواست درگاهی حل‌نشده‌ای برای این مرحله وجود ندارد.','gateway_guard'=>$state];
            if(!$state['can_check'])return ['success'=>false,'message'=>$state['message'],'gateway_guard'=>$state];
            $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_payments WHERE invoice_id=%d AND payment_stage_no=%d AND status='pending' AND authority REGEXP '^zibal:[1-9][0-9]*$' ORDER BY id ASC LIMIT 1",$invoice_id,$stage_no),ARRAY_A);
            if(!$row||$wpdb->last_error!=='')return ['success'=>false,'message'=>'خواندن درخواست درگاه کامل نشد؛ هیچ وضعیت مالی تغییر نکرد.'];
            $reply=$inquiry($row);
            if(empty($reply['success']))return ['success'=>false,'message'=>(string)($reply['message']??'استعلام درگاه کامل نشد؛ ثبت فیش تا تعیین وضعیت مجاز نیست.'),'gateway_guard'=>$state];
            $status=(int)$reply['status'];
            if(in_array($status,[3,15,18],true)){
                $changed=$wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}sn_payments SET status='failed',updated_at=%s WHERE id=%d AND invoice_id=%d AND payment_stage_no=%d AND status='pending' AND authority=%s",current_time('mysql'),(int)$row['id'],$invoice_id,$stage_no,$row['authority']));
                if($changed!==1||$wpdb->last_error!=='')return ['success'=>false,'message'=>'نتیجه استعلام دریافت شد اما ثبت آن کامل نشد؛ وضعیت را بازخوانی کنید.'];
                $fresh=self::snapshot($invoice_id,$stage_no);return ['success'=>true,'closed_payment_id'=>(int)$row['id'],'provider_status'=>$status,'gateway_guard'=>$fresh,'message'=>$fresh['blocked']?'یک درخواست لغوشده/برگشت‌خورده تعیین تکلیف شد؛ درخواست دیگری هنوز نیاز به بررسی دارد.':'زیبال لغو یا برگشت قطعی درخواست را تأیید کرد؛ اکنون وضعیت را بازخوانی و فیش را ثبت کنید.'];
            }
            $message=in_array($status,[1,2],true)?'زیبال برای این درخواست پرداخت بانکی گزارش کرده است؛ فیش دیگری ثبت نکنید و وضعیت پرداخت اینترنتی را با واحد مالی تطبیق دهید.':($status===-1?'درخواست درگاه هنوز در انتظار پرداخت است. اگر پرداخت بانکی انجام نشده، مشتری از صفحه درگاه انصراف دهد؛ سپس دوباره وضعیت درگاه را بررسی کنید.':'وضعیت زیبال برای آزادکردن ثبت فیش قطعی نیست؛ واحد مالی نتیجه را بررسی کند.');
            return ['success'=>true,'message'=>$message,'provider_status'=>$status,'gateway_guard'=>$state];
        }finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
    }
}
