<?php
if (!defined('ABSPATH')) { exit; }

/** One read/write contract for manual payment versus an unresolved gateway session. */
final class SN_Payment_Gateway_Guard {
    public static function snapshot(int $invoice_id,int $stage_no): array {
        global $wpdb;
        $rows=$wpdb->get_results($wpdb->prepare("SELECT id,status,authority,amount,created_at FROM {$wpdb->prefix}sn_payments WHERE invoice_id=%d AND COALESCE(authority,'')<>'' AND ((payment_stage_no=%d AND status IN ('pending','verified','paid','approved','manual_hold')) OR status='reconcile_error') ORDER BY id ASC",$invoice_id,$stage_no),ARRAY_A);
        if($wpdb->last_error!=='' || !is_array($rows))return ['blocked'=>true,'can_check'=>false,'code'=>'gateway_storage_error','message'=>'خواندن وضعیت پرداخت‌ها کامل نشد؛ وضعیت فاکتور را بازخوانی کنید. خطای دیتابیس به معنی وجود پرداخت درگاهی نیست.','requests'=>[]];
        $blocking=array_filter($rows,static fn($r)=>$r['status']!=='manual_hold');
        $out=['blocked'=>!empty($blocking),'can_check'=>false,'can_switch_to_manual'=>!empty($blocking),'manual_selected'=>false,'code'=>'','message'=>'','requests'=>[]];
        foreach($rows as $row){
            $zibal=(bool)preg_match('/^zibal:[1-9][0-9]*$/D',(string)$row['authority']);
            $out['requests'][]=['payment_id'=>(int)$row['id'],'status'=>(string)$row['status'],'amount'=>(float)$row['amount']];
            if(in_array($row['status'],['pending','manual_hold'],true)&&$zibal)$out['can_check']=true;
            if($row['status']==='manual_hold')$out['manual_selected']=true;
            if($row['status']!=='pending'||!$zibal)$out['can_switch_to_manual']=false;
        }
        if($blocking){
            $paid=(bool)array_filter($blocking,static fn($r)=>in_array($r['status'],['verified','paid','approved'],true));
            $conflict=(bool)array_filter($blocking,static fn($r)=>$r['status']==='reconcile_error');
            $out['code']=$conflict?'gateway_reconciliation_required':($paid?'gateway_recorded':'gateway_pending');
            $out['message']=$conflict?'یک پرداخت درگاهی نیازمند تطبیق مالی است؛ ثبت و تأیید واریز دیگر تا بررسی واحد مالی مجاز نیست.':($paid?'برای همین مرحله پرداخت درگاهی ثبت شده است؛ پیش از واریز دستی، واحد مالی وضعیت آن را تطبیق دهد.':'یک درخواست درگاه وجود دارد؛ این درخواست نشانه پرداخت موفق نیست. برای ثبت فیش، «انتخاب واریز دستی» را بزنید تا وضعیت همه درخواست‌ها دوباره بررسی شود.');
            if(!$out['can_check']&&!$paid&&!$conflict)$out['message'].=' استعلام خودکار این درخواست در دسترس نیست؛ واحد مالی باید آن را بررسی کند.';
        }elseif($out['manual_selected']){
            $out['code']='gateway_manual_selected';
            $out['message']='واریز دستی انتخاب شده است. از درخواست قبلی درگاه استفاده نکنید؛ این انتخاب به معنی لغو بانکی آن نیست. فیش فقط پس از بررسی مالی تأیید می‌شود.';
        }
        return $out;
    }
    /** Only a provider-confirmed terminal state may retire a pending local request. No age-only expiry. */
    public static function check(int $invoice_id,int $stage_no,callable $inquiry): array {
        global $wpdb;$lock=SN_Invoice::stage_lock_name($invoice_id,$stage_no);
        if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,3)',$lock))!==1)return ['success'=>false,'message'=>'عملیات دیگری برای همین مرحله در حال اجراست؛ پس از پایان آن وضعیت را بازخوانی کنید.'];
        try {
            $stage=(int)$wpdb->get_var($wpdb->prepare("SELECT current_payment_stage FROM {$wpdb->prefix}sn_invoices WHERE id=%d",$invoice_id));
            if($wpdb->last_error!==''||max(1,$stage)!==$stage_no)return ['success'=>false,'message'=>'مرحله پرداخت تغییر کرده یا خواندن آن کامل نشده است؛ وضعیت را بازخوانی کنید.'];
            $state=self::snapshot($invoice_id,$stage_no);if(!$state['blocked']&&empty($state['manual_selected']))return ['success'=>true,'message'=>'درخواست درگاهی حل‌نشده‌ای برای این مرحله وجود ندارد.','gateway_guard'=>$state];
            if(!$state['can_check'])return ['success'=>false,'message'=>$state['message'],'gateway_guard'=>$state];
            $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_payments WHERE invoice_id=%d AND payment_stage_no=%d AND status IN ('pending','manual_hold') AND authority REGEXP '^zibal:[1-9][0-9]*$' ORDER BY id ASC LIMIT 1",$invoice_id,$stage_no),ARRAY_A);
            if(!$row||$wpdb->last_error!=='')return ['success'=>false,'message'=>'خواندن درخواست درگاه کامل نشد؛ هیچ وضعیت مالی تغییر نکرد.'];
            $reply=$inquiry($row);
            if(empty($reply['success']))return ['success'=>false,'message'=>(string)($reply['message']??'استعلام درگاه کامل نشد؛ ثبت فیش تا تعیین وضعیت مجاز نیست.'),'gateway_guard'=>$state];
            $status=(int)$reply['status'];
            if($row['status']==='manual_hold'&&in_array($status,[1,2],true)){
                $changed=$wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}sn_payments SET status='reconcile_error',updated_at=%s WHERE id=%d AND invoice_id=%d AND payment_stage_no=%d AND status='manual_hold'",current_time('mysql'),(int)$row['id'],$invoice_id,$stage_no));
                if($changed!==1)return ['success'=>false,'message'=>'نتیجه بانکی نیازمند تطبیق مالی است؛ وضعیت را بازخوانی کنید.'];
                return ['success'=>true,'provider_status'=>$status,'gateway_guard'=>self::snapshot($invoice_id,$stage_no),'message'=>'زیبال برای درخواست قبلی پرداخت گزارش کرد؛ ثبت و تأیید فیش متوقف شد تا واحد مالی تطبیق دهد.'];
            }
            if(in_array($status,[3,15,18],true)){
                $changed=$wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}sn_payments SET status='failed',updated_at=%s WHERE id=%d AND invoice_id=%d AND payment_stage_no=%d AND status=%s AND authority=%s",current_time('mysql'),(int)$row['id'],$invoice_id,$stage_no,$row['status'],$row['authority']));
                if($changed!==1||$wpdb->last_error!=='')return ['success'=>false,'message'=>'نتیجه استعلام دریافت شد اما ثبت آن کامل نشد؛ وضعیت را بازخوانی کنید.'];
                $fresh=self::snapshot($invoice_id,$stage_no);return ['success'=>true,'closed_payment_id'=>(int)$row['id'],'provider_status'=>$status,'gateway_guard'=>$fresh,'message'=>$fresh['blocked']?'یک درخواست لغوشده/برگشت‌خورده تعیین تکلیف شد؛ درخواست دیگری هنوز نیاز به بررسی دارد.':'زیبال لغو یا برگشت قطعی درخواست را تأیید کرد؛ اکنون وضعیت را بازخوانی و فیش را ثبت کنید.'];
            }
            $message=in_array($status,[1,2],true)?'زیبال برای این درخواست پرداخت بانکی گزارش کرده است؛ فیش دیگری ثبت نکنید و وضعیت پرداخت اینترنتی را با واحد مالی تطبیق دهید.':($status===-1?'زیبال هنوز پرداختی برای این درخواست گزارش نکرده است. برای ثبت فیش «انتخاب واریز دستی» را بزنید؛ درخواست قبلی درگاه را دوباره استفاده نکنید.':'وضعیت زیبال برای آزادکردن ثبت فیش قطعی نیست؛ واحد مالی نتیجه را بررسی کند.');
            return ['success'=>true,'message'=>$message,'provider_status'=>$status,'gateway_guard'=>$state];
        }finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
    }

    /** An explicit local method choice, not cancellation or expiry at the bank. */
    public static function switch_to_manual(int $invoice_id,int $stage_no,callable $inquiry,callable $authorize): array {
        global $wpdb;$lock=SN_Invoice::stage_lock_name($invoice_id,$stage_no);$transaction=false;
        if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,3)',$lock))!==1)return ['success'=>false,'message'=>'عملیات دیگری برای همین مرحله در حال اجراست؛ وضعیت را بازخوانی کنید.'];
        try {
            foreach(['sn_invoices','sn_payments'] as $table){
                $engine=$wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$wpdb->prefix.$table));
                if(strtolower((string)$engine)!=='innodb')throw new RuntimeException('انتخاب امن روش پرداخت به جداول تراکنشی نیاز دارد؛ مدیر سیستم بررسی کند.');
            }
            $invoice=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_invoices WHERE id=%d",$invoice_id));
            if(!$invoice||max(1,(int)$invoice->current_payment_stage)!==$stage_no||!$authorize($invoice))throw new RuntimeException('مرحله یا دسترسی ثبت واریز تغییر کرده است؛ وضعیت را بازخوانی کنید.');
            $state=self::snapshot($invoice_id,$stage_no);
            if(!$state['blocked']&&!empty($state['manual_selected']))return ['success'=>true,'already'=>true,'gateway_guard'=>$state,'message'=>$state['message']];
            if(empty($state['can_switch_to_manual']))throw new RuntimeException($state['message']?:'درخواست قابل تغییر به واریز دستی وجود ندارد.');
            $rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_payments WHERE invoice_id=%d AND payment_stage_no=%d AND status='pending' AND COALESCE(authority,'')<>'' ORDER BY id ASC",$invoice_id,$stage_no),ARRAY_A);
            if($wpdb->last_error!==''||!$rows||count($rows)>10)throw new RuntimeException('تعداد یا وضعیت درخواست‌ها نیازمند بررسی واحد مالی است.');
            // All inquiries must succeed before any request is changed.
            $statuses=[];
            foreach($rows as $row){
                if(!preg_match('/^zibal:[1-9][0-9]*$/D',(string)$row['authority']))throw new RuntimeException('استعلام خودکار این درگاه برای انتخاب واریز دستی در دسترس نیست.');
                $reply=$inquiry($row);$status=$reply['status']??null;
                if(empty($reply['success'])||!is_int($status)||!in_array($status,[-1,3,15,18],true))throw new RuntimeException('پرداخت‌نشدن همه درخواست‌های درگاه تأیید نشد؛ هیچ روشی تغییر نکرد. وضعیت را با واحد مالی بررسی کنید.');
                $statuses[(int)$row['id']]=$status;
            }
            if(false===$wpdb->query('START TRANSACTION'))throw new RuntimeException('آغاز ثبت انتخاب روش پرداخت انجام نشد.');$transaction=true;
            $invoice=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_invoices WHERE id=%d FOR UPDATE",$invoice_id));
            if(!$invoice||max(1,(int)$invoice->current_payment_stage)!==$stage_no||!$authorize($invoice))throw new RuntimeException('مرحله یا دسترسی ثبت واریز تغییر کرده است؛ وضعیت را بازخوانی کنید.');
            $fresh=self::snapshot($invoice_id,$stage_no);
            if(empty($fresh['can_switch_to_manual'])||array_column($fresh['requests'],'payment_id')!==array_map(static fn($r)=>(int)$r['id'],$rows))throw new RuntimeException('درخواست‌های پرداخت تغییر کرده‌اند؛ وضعیت را بازخوانی کنید.');
            foreach($rows as $row){
                $proof=trim((string)($row['proof_data']??''))===''?[]:json_decode((string)$row['proof_data'],true);
                if(!is_array($proof))throw new RuntimeException('سابقه درخواست درگاه قابل حفظ نیست؛ واحد مالی بررسی کند.');
                $proof['_gateway_manual_selection']=['version'=>1,'actor_user_id'=>get_current_user_id(),'selected_at'=>current_time('mysql'),'provider_status'=>$statuses[(int)$row['id']]];
                $changed=$wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}sn_payments SET status=%s,proof_data=%s,updated_at=%s WHERE id=%d AND invoice_id=%d AND payment_stage_no=%d AND status='pending' AND authority=%s",$statuses[(int)$row['id']]===-1?'manual_hold':'failed',wp_json_encode($proof),current_time('mysql'),(int)$row['id'],$invoice_id,$stage_no,$row['authority']));
                if($changed!==1||$wpdb->last_error!=='')throw new RuntimeException('ثبت انتخاب روش پرداخت کامل نشد؛ هیچ درخواست قبلی حذف نشد.');
            }
            if(false===$wpdb->query('COMMIT'))throw new RuntimeException('ثبت نهایی انتخاب روش پرداخت انجام نشد.');$transaction=false;
            return ['success'=>true,'gateway_guard'=>self::snapshot($invoice_id,$stage_no),'message'=>'واریز دستی انتخاب شد؛ اکنون فیش را ثبت کنید. درخواست قبلی درگاه را استفاده نکنید؛ این انتخاب لغو بانکی نیست.'];
        }catch(Throwable $e){if($transaction)$wpdb->query('ROLLBACK');return ['success'=>false,'message'=>$e instanceof RuntimeException?$e->getMessage():'انتخاب روش پرداخت کامل نشد؛ وضعیت را بازخوانی کنید.'];}
        finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
    }

    /** Re-inquire selected-but-still-live requests before any manual financial approval. */
    public static function manual_approval_error(int $invoice_id,int $stage_no,string $order_id): string {
        global $wpdb;$state=self::snapshot($invoice_id,$stage_no);
        if($state['blocked'])return $state['message'];
        if(empty($state['manual_selected']))return '';
        $rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_payments WHERE invoice_id=%d AND payment_stage_no=%d AND status='manual_hold' AND COALESCE(authority,'')<>'' ORDER BY id ASC",$invoice_id,$stage_no),ARRAY_A);
        if($wpdb->last_error!==''||!$rows||count($rows)>10)return 'خواندن درخواست‌های درگاه کامل نشد؛ تأیید مالی انجام نشد.';
        $gateway=new SN_Invoice();
        foreach($rows as $row){
            $reply=$gateway->zibal_inquiry(substr((string)$row['authority'],6),(float)$row['amount'],$order_id);
            if(empty($reply['success'])||!in_array($reply['status']??null,[-1,3,15,18],true))return 'پیش از تأیید فیش، پرداخت‌نشدن درخواست قبلی درگاه باید تأیید شود؛ واحد مالی وضعیت درگاه را بررسی کند.';
        }
        return '';
    }

    /** Serialize a verified bank callback with manual selection, submission and approval. */
    public static function verified_callback(int $invoice_id,int $stage_no,string $authority,string $ref_id,callable $finalize): array {
        global $wpdb;$lock=SN_Invoice::stage_lock_name($invoice_id,$stage_no);$transaction=false;
        if($authority===''||(int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,8)',$lock))!==1)return ['success'=>false,'message'=>'ثبت نتیجه درگاه هم‌زمان با عملیات دیگری است؛ واحد مالی بررسی کند.'];
        try {
            $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_payments WHERE invoice_id=%d AND payment_stage_no=%d AND authority=%s ORDER BY id DESC LIMIT 1",$invoice_id,$stage_no,$authority),ARRAY_A);
            if(!$row||$wpdb->last_error!=='')return ['success'=>false,'message'=>'درخواست درگاه قابل تطبیق نیست؛ واحد مالی بررسی کند.'];
            $proof=json_decode((string)($row['proof_data']??''),true);
            $manual=$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}sn_payments WHERE invoice_id=%d AND payment_stage_no=%d AND COALESCE(authority,'')='' AND status IN ('pending','approved','paid')",$invoice_id,$stage_no));
            if($manual===null||$wpdb->last_error!=='')return ['success'=>false,'message'=>'وضعیت واریز دستی قابل بررسی نیست؛ واحد مالی بررسی کند.'];
            $stage=$wpdb->get_row($wpdb->prepare("SELECT status,pay_method FROM {$wpdb->prefix}sn_invoice_payment_stages WHERE invoice_id=%d AND stage_no=%d",$invoice_id,$stage_no));
            if(!$stage||$wpdb->last_error!=='')return ['success'=>false,'message'=>'مرحله پرداخت قابل بررسی نیست؛ واحد مالی بررسی کند.'];
            $manual_stage=in_array($stage->status,['approved','paid'],true)&&in_array($stage->pay_method,['card','admin'],true);
            if(!empty($proof['_gateway_manual_selection'])||in_array($row['status'],['manual_hold','reconcile_error'],true)||$manual>0||$manual_stage){
                if(false===$wpdb->query('START TRANSACTION'))throw new RuntimeException('ثبت نتیجه درگاه آغاز نشد.');$transaction=true;
                $invoice=$wpdb->get_row($wpdb->prepare("SELECT id FROM {$wpdb->prefix}sn_invoices WHERE id=%d FOR UPDATE",$invoice_id));
                if(!$invoice||$wpdb->last_error!=='')throw new RuntimeException('فاکتور نتیجه درگاه قابل تطبیق نیست.');
                $changed=$wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}sn_payments SET status='reconcile_error',ref_id=%s,updated_at=%s WHERE id=%d AND invoice_id=%d AND payment_stage_no=%d AND authority=%s",$ref_id,current_time('mysql'),(int)$row['id'],$invoice_id,$stage_no,$authority));
                if($changed===false||false===$wpdb->query('COMMIT'))throw new RuntimeException('ثبت نتیجه درگاه کامل نشد.');$transaction=false;
                return ['success'=>false,'reconciliation_required'=>true,'message'=>'پرداخت درگاه پس از انتخاب واریز دستی دریافت شد؛ مرجع بانکی حفظ شد و تأیید دوباره انجام نشد. واحد مالی باید تطبیق دهد.'];
            }
            return $finalize();
        }catch(Throwable $e){if($transaction)$wpdb->query('ROLLBACK');return ['success'=>false,'message'=>'ثبت نتیجه درگاه کامل نشد؛ واحد مالی بررسی کند.'];}
        finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
    }
}
