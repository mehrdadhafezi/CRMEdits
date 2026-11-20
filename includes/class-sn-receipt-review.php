<?php
if (!defined('ABSPATH')) { exit; }

/** Receipt decisions extend the existing payment ledger; no bank transfer is executed here. */
final class SN_Receipt_Review {
    public const VERSION = '2026-10-31-receipts-v1';
    private array $policy;
    private static function name(int $id): string { $u=get_userdata($id); return $u?(string)($u->display_name ?: $u->user_login):'کاربر سابق'; }
    public function __construct(array $policy) { $this->policy = $policy; }
    private function allowed(string $key, ...$args): bool { return isset($this->policy[$key]) && (bool)($this->policy[$key])(...$args); }
    private function reconcile_receipt_commission(int $invoice_id): bool {
        if (!class_exists('SN_Purpose_Commission') || !class_exists('SN_Dot_Flow') || !SN_Dot_Flow::instance()->is_staged_conversion_invoice($invoice_id)) { return true; }
        return empty(SN_Purpose_Commission::instance()->reconcile_invoice($invoice_id)['blocked']);
    }
    public static function modern($row): bool { return empty($row->authority) && trim((string)($row->proof_data ?? ''))!==''; }
    public static function metadata($row): array { $p=json_decode((string)($row->proof_data ?? ''),true); return is_array($p['_review'] ?? null)?$p['_review']:[]; }
    public static function install(): void {
        if (get_option('sn_receipt_review_db_version')===self::VERSION && self::schema_ready()) { return; }
        global $wpdb; require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $table=$wpdb->prefix.'sn_payment_review_events';
        dbDelta("CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            invoice_id BIGINT UNSIGNED NOT NULL,
            payment_id BIGINT UNSIGNED NOT NULL,
            revision INT UNSIGNED NOT NULL,
            actor_user_id BIGINT UNSIGNED NOT NULL,
            action VARCHAR(40) NOT NULL,
            reason TEXT DEFAULT NULL,
            snapshot_json LONGTEXT NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY invoice_payment (invoice_id,payment_id,id)
        ) ENGINE=InnoDB ".$wpdb->get_charset_collate().';');
        if (self::schema_ready()) { update_option('sn_receipt_review_db_version',self::VERSION,false); }
    }
    private static function schema_ready(): bool {
        global $wpdb; $table=$wpdb->prefix.'sn_payment_review_events';
        $engine=$wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$table));
        if (strtolower((string)$engine)!=='innodb') { return false; }
        $columns=(array)$wpdb->get_col("SHOW COLUMNS FROM {$table}",0);
        return !array_diff(['id','invoice_id','payment_id','revision','actor_user_id','action','reason','snapshot_json','created_at'],$columns);
    }

    /** A generic wallet settlement cannot be safely allocated back to a particular sale. */
    public static function hold_settled_commission(array $credit,string $reason): bool {
        global $wpdb;$tx=$wpdb->prefix.'sn_wallet_transactions';
        $settled=$wpdb->get_var($wpdb->prepare("SELECT id FROM {$tx} WHERE user_id=%d AND wallet_type=%s AND type='settlement' AND status='approved' AND created_at>=%s LIMIT 1",(int)$credit['user_id'],(string)$credit['wallet_type'],(string)$credit['created_at']));
        if($wpdb->last_error)throw new RuntimeException('بررسی تسویه پورسانت انجام نشد.');if(!$settled)return false;
        $table=$wpdb->prefix.'sn_payment_review_events';$id=(int)$credit['invoice_id'];$revision=(int)$credit['id'];
        $prior=$wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE invoice_id=%d AND payment_id=0 AND revision=%d AND action='commission_manual_review' LIMIT 1",$id,$revision));
        if(!$prior && !$wpdb->insert($table,['invoice_id'=>$id,'payment_id'=>0,'revision'=>$revision,'actor_user_id'=>get_current_user_id(),'action'=>'commission_manual_review','reason'=>sanitize_textarea_field($reason),'snapshot_json'=>wp_json_encode(['credit_id'=>$revision,'settlement_id'=>(int)$settled,'decision'=>'manual_only','amount'=>(string)$credit['amount'],'user_id'=>(int)$credit['user_id']]),'created_at'=>current_time('mysql')]))throw new RuntimeException('ثبت نیاز به تصمیم دستی پورسانت انجام نشد.');
        return true;
    }

    public static function stage_amounts(int $id,int $no): array {
        global $wpdb;
        $rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_payments WHERE invoice_id=%d AND payment_stage_no=%d AND (COALESCE(authority,'')='' OR status='verified') ORDER BY updated_at DESC,id DESC",$id,$no));
        if ($wpdb->last_error) { throw new RuntimeException('خواندن دفتر واریزی‌ها انجام نشد.'); }
        $approved = 0;
        $pending = 0;
        $modern = false;
        $has_pending = false;
        $has_verified_gateway = false;
        $last_rejected = null;
        foreach ($rows ?: [] as $r) {
            if ((string)$r->authority !== '') {
                $has_verified_gateway = true;
                continue;
            }
            if ($r->status === 'pending') { $has_pending = true; }
            if (!self::modern($r)) { continue; }
            $modern = true;
            if ($r->status === 'approved') { $approved += (float)$r->amount; }
            if ($r->status === 'pending') { $pending += (float)$r->amount; }
            if ($r->status === 'rejected' && !$last_rejected) { $last_rejected = $r; }
        }
        return ['modern'=>$modern,'approved'=>round($approved,2),'pending'=>round($pending,2),'has_pending'=>$has_pending,'has_verified_gateway'=>$has_verified_gateway,'last_rejected'=>$last_rejected];
    }
    private function editable(object $invoice,object $payment): bool {
        $uid=get_current_user_id();
        return self::modern($payment) && (int)$payment->payment_stage_no===(int)$invoice->current_payment_stage
            && in_array((string)$payment->status,['pending','rejected'],true)
            && !in_array((string)$invoice->status,['cancelled','payment_archived','paid','approved'],true)
            && ((int)$payment->uploaded_by_user_id===$uid || (int)$invoice->seller_id===$uid)
            && $this->allowed('submit',$invoice);
    }
    private function fail(string $message): void { throw new RuntimeException($message); }
    private function must($ok): void { if ($ok===false) { $this->fail('ثبت یکپارچه تغییر انجام نشد؛ دوباره وضعیت را دریافت کنید.'); } }
    private function snapshot(object $r): array { return ['amount'=>(string)$r->amount,'status'=>(string)$r->status,'proof'=>json_decode((string)$r->proof_data,true)]; }
    private function event(object $invoice,object $payment,string $action,string $reason,array $before,array $after,int $revision): void {
        global $wpdb;
        if (!$wpdb->insert($wpdb->prefix.'sn_payment_review_events',['invoice_id'=>(int)$invoice->id,'payment_id'=>(int)$payment->id,'revision'=>$revision,'actor_user_id'=>get_current_user_id(),'action'=>$action,'reason'=>$reason,'snapshot_json'=>wp_json_encode(['before'=>$before,'after'=>$after],JSON_UNESCAPED_UNICODE),'created_at'=>current_time('mysql')])) { $this->fail('ثبت سابقه انجام نشد؛ تغییر مالی لغو شد.'); }
    }
    private function check_resubmission_amount(object $payment, object $stage, float $amount): void {
        global $wpdb;
        $gateway = SN_Payment_Gateway_Guard::snapshot((int)$payment->invoice_id, (int)$stage->stage_no);
        if ($gateway['blocked']) { $this->fail($gateway['message']); }
        $other = (float)$wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(amount),0) FROM {$wpdb->prefix}sn_payments WHERE invoice_id=%d AND payment_stage_no=%d AND id<>%d AND status IN ('pending','approved','paid','verified')",
            $payment->invoice_id, $stage->stage_no, $payment->id
        ));
        if ($wpdb->last_error || $amount + $other > (float)$stage->requested_amount + 0.004) {
            $this->fail('با احتساب سایر واریزها، این مبلغ از سقف مرحله بیشتر است یا بررسی مبلغ انجام نشد.');
        }
    }
    private function ready_to_finalize(object $stage, array $amounts): bool {
        return in_array((string)$stage->status, ['pending_financial_approval','receipt_uploaded'], true)
            && (float)$stage->requested_amount > 0 && !$amounts['has_pending'] && !$amounts['has_verified_gateway']
            && abs($amounts['approved'] - (float)$stage->requested_amount) < 0.005;
    }
    private function finalize_saved_decision(int $id, int $stage_no): array {
        global $wpdb;
        try {
            $result = ($this->policy['finalize'])($id, $stage_no);
            if (!empty($result['success'])) {
                return ['success'=>true,'stage_completed'=>true,'message'=>'تأیید مالی مرحله تکمیل شد.'];
            }
        } catch (Throwable $e) {
            // The receipt decision is already committed. Reload before retrying stage finalization.
            $wpdb->query('ROLLBACK');
        }
        return ['success'=>false,'decision_saved'=>true,'message'=>'تأیید واریز ذخیره شد، اما تکمیل مرحله به خطا خورد. وضعیت مرحله را دوباره بررسی کنید.'];
    }
    private function sync(object $invoice,object $stage): array {
        global $wpdb;
        $a=self::stage_amounts((int)$invoice->id,(int)$stage->stage_no);
        $prior=(float)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(requested_amount),0) FROM {$wpdb->prefix}sn_invoice_payment_stages WHERE invoice_id=%d AND stage_no<>%d AND status IN ('approved','paid')",$invoice->id,$stage->stage_no));
        if($wpdb->last_error)$this->fail('خواندن مراحل قبلی انجام نشد.');
        $canonical=($this->policy['totals'])($invoice);$total=(float)$canonical['total'];
        if($a['approved']>(float)$stage->requested_amount+0.004 || $prior+$a['approved']>$total+0.004)$this->fail('مجموع معتبر از مبلغ مجاز بیشتر است.');
        $paid=round($prior+$a['approved'],2); $left=round((float)$stage->requested_amount-$a['approved'],2);
        $rejected = $left >= 0.005 && !$a['has_pending'] && !$a['has_verified_gateway'] && $a['last_rejected'];
        $status = $rejected ? 'rejected' : 'pending_financial_approval';
        $now = current_time('mysql');
        $data = [
            'payment_total_amount' => $total,
            'paid_total_amount' => $paid,
            'remaining_amount' => max(0, round($total - $paid, 2)),
            'current_due_amount' => max(0, $left),
            'status' => $status,
            'invoice_status' => $status,
            'payment_status' => $status,
            'payment_workflow_status' => $rejected ? 'awaiting_payment' : 'awaiting_financial_approval',
            'updated_at' => $now,
        ];
        if ($rejected) {
            $review = self::metadata($a['last_rejected']);
            $actor = (int)($review['reviewer_id'] ?? 0) ?: null;
            $at = ($review['reviewed_at'] ?? '') ?: $now;
            $reason = (string)($review['reason'] ?? '');
            $data += [
                'rejected_by' => $actor, 'financial_rejected_by' => $actor, 'financial_reviewed_by' => $actor,
                'rejected_at' => $at, 'financial_rejected_at' => $at, 'financial_reviewed_at' => $at,
                'rejected_reason' => $reason, 'financial_reject_reason' => $reason,
                'financial_return_state' => 'returned_to_seller', 'returned_to_seller_at' => $now,
            ];
        } elseif ($invoice->status === 'rejected') {
            $data += [
                'financial_return_state' => 'resent_after_return', 'resend_to_financial_at' => $now, 'resent_after_return_at' => $now,
                'rejected_reason' => null, 'financial_reject_reason' => null, 'rejected_by' => null, 'financial_rejected_by' => null,
                'rejected_at' => null, 'financial_rejected_at' => null,
            ];
        }
        $this->must($wpdb->update($wpdb->prefix.'sn_invoices',$data,['id'=>(int)$invoice->id]));
        $this->must($wpdb->update($wpdb->prefix.'sn_invoice_payment_stages',['status'=>$status,'updated_at'=>$now],['id'=>(int)$stage->id]));
        return ['ready'=>$left<0.005 && !$a['has_pending'] && !$a['has_verified_gateway']];
    }
    public function handle(array $in,array $files=[],?callable $guard=null): array {
        global $wpdb; self::install();
        $id=absint($in['invoice_id'] ?? 0);$pid=absint($in['payment_id'] ?? 0);$op=sanitize_key((string)($in['operation'] ?? 'read'));
        $invoice=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_invoices WHERE id=%d",$id));
        if(!$invoice || !$this->allowed('view',$invoice))return ['success'=>false,'message'=>'دسترسی به این فاکتور مجاز نیست.'];
        if($op==='read')return $this->read($invoice);
        $stage_no=(int)$invoice->current_payment_stage;
        $lock=SN_Invoice::stage_lock_name($id,$stage_no);
        if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,2)',$lock))!==1)return ['success'=>false,'message'=>'عملیات دیگری در حال انجام است؛ وضعیت را بازخوانی کنید.'];
        $committed=false;$keep=[];
        try {
            foreach(['sn_invoices','sn_invoice_payment_stages','sn_payments','sn_payment_review_events'] as $t) {
                $engine=$wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$wpdb->prefix.$t));
                if(strtolower((string)$engine)!=='innodb')$this->fail('اصلاح امن رسید به جداول InnoDB نیاز دارد.');
            }
            $this->must($wpdb->query('START TRANSACTION'));
            $invoice=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_invoices WHERE id=%d FOR UPDATE",$id));
            $payment=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_payments WHERE id=%d AND invoice_id=%d FOR UPDATE",$pid,$id));
            if(!$invoice || !$payment || !$this->allowed('view',$invoice) || !self::modern($payment))$this->fail('واریز مستقل معتبر یا دسترسی جاری وجود ندارد.');
            if((int)$invoice->current_payment_stage!==$stage_no || (int)$payment->payment_stage_no!==$stage_no)$this->fail('مرحله جاری پرداخت تغییر کرده است؛ وضعیت را بازخوانی کنید.');
            $stage=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_invoice_payment_stages WHERE invoice_id=%d AND stage_no=%d FOR UPDATE",$id,(int)$payment->payment_stage_no));
            if(!$stage || in_array((string)$invoice->status,['cancelled','payment_archived'],true))$this->fail('فاکتور یا مرحله بسته است.');
            $proof=json_decode($payment->proof_data,true);if(!is_array($proof))$this->fail('ساختار سابقه رسید معتبر نیست؛ تأیید خودکار مجاز نیست.');$meta=self::metadata($payment);$revision=max(1,(int)($meta['revision'] ?? 1));
            if((int)($in['revision'] ?? 0)!==$revision)$this->fail('رسید پس از بازشدن فرم تغییر کرده است؛ بازخوانی کنید.');
            $uid=get_current_user_id();
            $reason=sanitize_textarea_field((string)($in['reason'] ?? ''));$before=$this->snapshot($payment);$changes=[];
            if($op==='approve' || $op==='reject') {
                if(!$this->allowed($op))$this->fail('مجوز تصمیم مالی وجود ندارد.');
                if($payment->status==='approved' && $op==='approve') {
                    $amounts = self::stage_amounts($id, $stage_no);
                    $wpdb->query('ROLLBACK');
                    if (!$this->reconcile_receipt_commission($id)) { return ['success'=>false,'decision_saved'=>true,'commission_pending'=>true,'message'=>'تأیید واریز محفوظ است؛ ثبت پورسانت کامل نشد و باید دوباره بررسی شود.']; }
                    if ($this->ready_to_finalize($stage, $amounts)) {
                        return $this->finalize_saved_decision($id, $stage_no);
                    }
                    return ['success'=>true,'message'=>'این واریز قبلاً تأیید شده است.','already'=>true];
                }
                if($payment->status!=='pending' || in_array((string)$stage->status,['paid','approved'],true) || in_array((string)$invoice->status,['paid','approved'],true))$this->fail('فقط واریز در انتظار بررسی قابل تصمیم است.');
                if((int)$payment->uploaded_by_user_id===$uid)$this->fail('ثبت‌کننده واریز نمی‌تواند همان واریز را تأیید یا رد کند.');
                if ($guard && is_wp_error($check = $guard($invoice, $payment))) { $this->fail($check->get_error_message()); }
                if($op==='approve') {
                    $gateway_error=SN_Payment_Gateway_Guard::manual_approval_error($id,$stage_no,(string)$invoice->invoice_code);
                    if($gateway_error!=='')$this->fail($gateway_error);
                } elseif($reason==='')$this->fail('دلیل رد همین واریز الزامی است.');
                $meta['reviewer_id']=$uid;$meta['reviewed_at']=current_time('mysql');$meta['reason']=$reason;
                $changes['status']=$op==='approve'?'approved':'rejected';
            } elseif($op==='correct') {
                if(!$this->editable($invoice,$payment))$this->fail('اصلاح فقط برای واریز تأییدنشده و ثبت‌کننده/فروشنده دارای دسترسی جاری مجاز است.');
                if($reason==='')$this->fail('دلیل اصلاح همین واریز را ثبت کنید.');
                $fields=($this->policy['fields'])($in,$invoice);
                if(!empty($fields['error']))$this->fail($fields['error']);
                $amount=(float)$fields['fields']['transaction_amount'];unset($fields['fields']['transaction_amount']);
                $this->check_resubmission_amount($payment, $stage, $amount);
                $proof=array_merge($proof,$fields['fields']);$proof['entry_mode']=sanitize_key((string)($in['entry_mode'] ?? 'info'));
                if(SN_Helpers::has_receipt_upload($files['receipt'] ?? [])) {
                    $up=SN_Helpers::upload_receipt($files['receipt']);if(empty($up['success']))$this->fail((string)($up['message'] ?? 'آپلود انجام نشد.'));
                    $keep=$up['urls'];$proof['receipt_urls']=$keep;$proof['receipt_url']=$keep[0];$proof['receipt_file']=$keep[0];
                }
                if($proof['entry_mode']==='receipt' && !SN_Helpers::receipt_urls($proof))$this->fail('برای روش ثبت با فیش، فایل لازم است.');
                $meta['proof_version']=max(1,(int)($meta['proof_version'] ?? 1))+1;
                $meta['reason']=$reason;$meta['corrected_at']=current_time('mysql');$meta['corrected_by']=$uid;
                unset($meta['bank_verification']);$changes=['amount'=>$amount,'status'=>'pending'];
            } elseif($op==='appeal') {
                if(!$this->editable($invoice,$payment) || $payment->status!=='rejected')$this->fail('اعتراض فقط روی همین واریز ردشده و در دامنه مجاز ثبت می‌شود.');
                if($reason==='')$this->fail('توضیح اعتراض را بنویسید.');
                $this->check_resubmission_amount($payment, $stage, (float)$payment->amount);
                $meta['appeal']=$reason;$changes['status']='pending';
            } else $this->fail('عملیات ناشناخته است.');
            unset($meta['owner_id'],$meta['expires_at'],$meta['preferred_reviewer_id']);
            $meta['revision']=$revision+1;$proof['_review']=$meta;
            $changes['proof_data']=wp_json_encode($proof,JSON_UNESCAPED_UNICODE);$changes['updated_at']=current_time('mysql');
            $this->must($wpdb->update($wpdb->prefix.'sn_payments',$changes,['id'=>$pid]));
            $fresh=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_payments WHERE id=%d",$pid));
            if(!$fresh)$this->fail('خواندن نتیجه ثبت انجام نشد.');
            $this->event($invoice,$fresh,$op,$reason,$before,$this->snapshot($fresh),$revision+1);
            $ready=$this->sync($invoice,$stage)['ready'];
            if (class_exists('SN_Dot_Flow') && !SN_Dot_Flow::instance()->staged_conversion_financial_apply_in_transaction($id)) { $this->fail('ثبت مبلغ تأییدشده در پرونده کامل نشد.'); }
            $this->must($wpdb->query('COMMIT'));$committed=true;SN_Helpers::commit_receipt_uploads($keep);
            if ($op === 'approve' && !$this->reconcile_receipt_commission($id)) { return ['success'=>false,'decision_saved'=>true,'commission_pending'=>true,'message'=>'تأیید واریز محفوظ است؛ ثبت پورسانت کامل نشد و باید دوباره بررسی شود.']; }
            if($ready) {
                $result = $this->finalize_saved_decision($id, $stage_no);
                if (empty($result['success'])) { return $result; }
            }
            return ['success'=>true,'message'=>['approve'=>'وصول این واریز تأیید شد.','reject'=>'این واریز رد شد.','correct'=>'نسخه جدید همین واریز ثبت شد؛ نسخه قبلی در سابقه محفوظ است.','appeal'=>'توضیح روی همان واریز ثبت و برای بررسی مجدد ارسال شد.'][$op],'payment_id'=>$pid,'revision'=>$revision+1,'stage_completed'=>$ready];
        } catch(Throwable $e) { if(!$committed)$wpdb->query('ROLLBACK');return ['success'=>false,'message'=>$e->getMessage()]; }
        finally { SN_Helpers::cleanup_receipt_uploads();$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock)); }
    }
    private function duplicates(object $invoice,object $row,array $proof): array {
        global $wpdb;$matches=[];
        $date=(string)($proof['manual_paid_at'] ?? '');$from=(string)($proof['manual_card_from'] ?? $proof['manual_account_last6'] ?? '');$to=(string)($proof['manual_card_to'] ?? '');
        if($date==='' || ($from==='' && $to===''))return [];
        $rows=$wpdb->get_results($wpdb->prepare("SELECT p.*,i.seller_id,i.invoice_code FROM {$wpdb->prefix}sn_payments p INNER JOIN {$wpdb->prefix}sn_invoices i ON i.id=p.invoice_id WHERE p.id<>%d AND p.amount=%f AND COALESCE(p.authority,'')='' AND p.status IN ('pending','approved','paid') ORDER BY p.id DESC LIMIT 100",$row->id,$row->amount));
        foreach($rows ?: [] as $candidate) {
            $p=json_decode((string)$candidate->proof_data,true);if(!is_array($p))continue;
            if(substr((string)($p['manual_paid_at'] ?? ''),0,16)!==substr($date,0,16))continue;
            $source=(string)($p['manual_card_from'] ?? $p['manual_account_last6'] ?? '');
            if(!(($from!=='' && $source===$from) || ($to!=='' && (string)($p['manual_card_to'] ?? '')===$to)))continue;
            $inv=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_invoices WHERE id=%d",$candidate->invoice_id));
            if($inv && $this->allowed('view',$inv))$matches[]=['payment_id'=>(int)$candidate->id,'invoice_code'=>(string)$inv->invoice_code];
        }
        return $matches;
    }
    public function read(object $invoice): array {
        global $wpdb;$id=(int)$invoice->id;$uid=get_current_user_id();$finance=$this->allowed('approve') || $this->allowed('reject');
        $stage_no = (int)$invoice->current_payment_stage;
        $retry_finalize = false;
        if ($this->allowed('approve') && in_array((string)$invoice->status, ['pending_financial_approval','receipt_uploaded'], true)
            && isset($invoice->current_due_amount) && (float)$invoice->current_due_amount < 0.005) {
            $stage = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_invoice_payment_stages WHERE invoice_id=%d AND stage_no=%d",$id,$stage_no));
            if ($stage) { $retry_finalize = $this->ready_to_finalize($stage, self::stage_amounts($id, $stage_no)); }
        }
        $raw=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_payments WHERE invoice_id=%d ORDER BY payment_stage_no,id",$id));
        $rows=SN_Payment_Transactions::rows($invoice);$by=[];foreach($raw ?: [] as $r)$by[(int)$r->id]=$r;
        $gateway_notices=[];
        foreach($rows as $r)if(!empty($r['is_gateway'])&&$r['status']==='reconcile_error')$gateway_notices[]='پرداخت درگاه #'.(int)$r['id'].' نیازمند تطبیق مالی است؛ مرجع بانکی حفظ شده و این مبلغ دوباره به فاکتور یا پورسانت اضافه نشده است.';
        $events=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_payment_review_events WHERE invoice_id=%d ORDER BY id DESC LIMIT 200",$id),ARRAY_A) ?: [];
        foreach($events as &$event){$event['actor_name']=self::name((int)$event['actor_user_id']);$event['snapshot']=json_decode($event['snapshot_json'],true);unset($event['snapshot_json']);}unset($event);
        foreach($rows as &$row) {
            $r=$by[(int)$row['id']] ?? null;if(!$r)continue;
            $m=self::metadata($r);
            $current=(int)$r->payment_stage_no===$stage_no && !in_array((string)$invoice->status,['paid','approved','cancelled','payment_archived'],true);
            $row['review']=['revision'=>max(1,(int)($m['revision'] ?? 1)),'document_revision'=>max(1,(int)($m['proof_version'] ?? 1)),'reason'=>(string)($m['reason'] ?? ''),'appeal'=>(string)($m['appeal'] ?? ''),'reviewer_name'=>!empty($m['reviewer_id'])?self::name((int)$m['reviewer_id']):'','reviewed_at'=>(string)($m['reviewed_at'] ?? ''),'bank_verification'=>$m['bank_verification'] ?? null];
            $own_payment=(int)$r->uploaded_by_user_id===$uid;
            $decidable=$current && self::modern($r) && $r->status==='pending' && !$own_payment;
            $editable=$current && $this->editable($invoice,$r);
            $row['actions']=['approve'=>$decidable && $this->allowed('approve'),'reject'=>$decidable && $this->allowed('reject'),'correct'=>$editable,'appeal'=>$editable && $r->status==='rejected',
                'retry_finalize'=>$current && $retry_finalize && self::modern($r) && $r->status==='approved',
                'retry_commission'=>$this->allowed('approve') && self::modern($r) && $r->status==='approved' && !in_array((string)$invoice->status,['cancelled','payment_archived','rejected'],true) && class_exists('SN_Dot_Flow') && SN_Dot_Flow::instance()->is_staged_conversion_invoice($id)];
            $row['uploaded_by_name']=!empty($r->uploaded_by_user_id)?self::name((int)$r->uploaded_by_user_id):'ثبت‌کننده قدیمی / درگاه';
            if($current && $finance && $r->status==='pending' && $own_payment && self::modern($r))$row['action_hint']='تأیید یا رد این واریز باید توسط کارشناس مالی دیگری انجام شود.';
            $row['duplicates']=$this->duplicates($invoice,$r,$row['proof']);
            $row['history']=array_values(array_filter($events,static fn($e)=>(int)$e['payment_id']===(int)$r->id));
        }unset($row);
        $reference = class_exists('SN_Dot_Flow') ? (SN_Dot_Flow::instance()->conversion_invoice_references([$id])[$id] ?? []) : [];
        return ['success'=>true,'invoice_id'=>$id,'invoice_code'=>(string)($reference['invoice_code'] ?? $invoice->invoice_code),'invoice'=>['id'=>$id,'code'=>(string)$invoice->invoice_code,'customer_name'=>(string)($invoice->customer_name??''),'customer_phone'=>(string)($invoice->customer_phone??''),'seller_name'=>self::name((int)($invoice->seller_id??0)),'stage_no'=>(int)$invoice->current_payment_stage,'display_stage_no'=>(int)($reference['stage_no'] ?? 0),'status'=>(string)$invoice->status],'payments'=>$rows,'financial_notices'=>array_merge($gateway_notices,array_values(array_map(static fn($e)=>'آثار پورسانت این فاکتور به علت وجود تسویه کیف پول نیازمند تصمیم دستی است؛ وصول یا تهاتر خودکار انجام نشد.',array_filter($events,static fn($e)=>$e['action']==='commission_manual_review')))),'totals'=>($this->policy['totals'])($invoice),'message'=>''];
    }
}
