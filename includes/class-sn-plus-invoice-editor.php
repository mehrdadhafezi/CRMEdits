<?php
if (!defined('ABSPATH')) { exit; }

/** Independent, pre-payment Subscription+ editor. No fulfillment or remote side effects. */
final class SN_Plus_Invoice_Editor {
    private $authorize;
    private $validate_product;
    public function __construct(callable $authorize, callable $validate_product) {
        $this->authorize=$authorize; $this->validate_product=$validate_product;
    }
    private function table(string $suffix): string { global $wpdb; return $wpdb->prefix.$suffix; }
    private function exists(string $suffix): bool {
        global $wpdb; $table=$this->table($suffix);
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table))===$table;
    }
    private function fail(string $message, int $status=400): void { throw new RuntimeException($message,$status); }
    private function row(string $suffix,string $where,array $args,bool $lock=false): ?object {
        global $wpdb;
        $row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.$this->table($suffix).' WHERE '.$where.($lock?' FOR UPDATE':''),...$args));
        if($wpdb->last_error)$this->fail('ساختار یا خواندن داده لازم برای ویرایش امن آماده نیست.',503);
        return $row?:null;
    }
    private function rows(string $suffix,string $where,array $args,bool $lock=false): array {
        global $wpdb;
        $rows=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.$this->table($suffix).' WHERE '.$where.' ORDER BY id'.($lock?' FOR UPDATE':''),...$args),ARRAY_A);
        if($wpdb->last_error)$this->fail('ساختار یا خواندن داده لازم برای ویرایش امن آماده نیست.',503);
        return $rows?:[];
    }
    private static function cents($value): int {
        if ($value===0 || $value==='0' || $value==='0.00') return 0;
        $key=SN_Sales_Catalog::amount_key($value);
        return $key===''?-1:(int)$key;
    }
    private function load(int $id,bool $lock): array {
        global $wpdb;
        $invoice=$this->row('sn_invoices','id=%d',[$id],$lock);
        if (!$invoice) $this->fail('فاکتور در محدوده دسترسی شما نیست.',403);
        $permissions=($this->authorize)($invoice);
        if (!$permissions) $this->fail('ویرایش فقط برای مالک یا آخرین مسئول مجاز لینک پرداخت امکان دارد.',403);
        $statuses=[(string)$invoice->status,(string)($invoice->invoice_status??''),(string)($invoice->payment_status??'')];
        if (array_diff(array_filter($statuses),['pending','pre_invoice','pending_payment']) ||
            !in_array((string)$invoice->payment_workflow_status,['awaiting_payment','pending'],true) ||
            (int)$invoice->current_payment_stage!==1 || (float)$invoice->paid_total_amount>0 ||
            !empty($invoice->paid_at) || !empty($invoice->approved_at) || !empty($invoice->financial_reviewed_at) ||
            !empty($invoice->wc_order_id) || !empty($invoice->receipt_url) || !empty($invoice->receipt_file) ||
            !empty($invoice->receipt_urls) || (float)($invoice->manual_amount??0)>0 || (float)($invoice->deposit_amount??0)>0 ||
            !empty($invoice->manual_paid_at) || !empty($invoice->deposit_jalali_datetime) || !empty($invoice->coupon_code) || !empty($invoice->wheel_reward_summary)) {
            $this->fail('پس از ثبت پرداخت، فیش، بررسی مالی یا تحویل، ویرایش اشتراک+ مجاز نیست.',409);
        }
        $stages=$this->rows('sn_invoice_payment_stages','invoice_id=%d',[$id],$lock);
        if (count($stages)!==1 || (int)$stages[0]['stage_no']!==1 || $stages[0]['status']!=='pending' || !empty($stages[0]['paid_at']) || !empty($stages[0]['approved_at'])) {
            $this->fail('فقط مرحله اولِ پرداخت‌نشده قابل ویرایش است.',409);
        }
        $sale=$this->row('sn_subscription_plus_sales','source_invoice_id=%d',[$id],$lock);
        if (!empty($sale->cash_conversion_case_id)) {
            $this->fail(SN_Dot_Flow::INVOICE_CONVERSION_EDIT_NOTICE,409);
        }
        if (!$sale) {
            $conversion=$this->row('sn_dot_invoice_links',"invoice_id=%d AND flow_kind IN ('conversion_payment','conversion_cash') LIMIT 1",[$id],$lock);
            $this->fail($conversion ? SN_Dot_Flow::INVOICE_CONVERSION_EDIT_NOTICE : 'سابقهٔ فروش مستقیم اشتراک+ برای این پیش‌فاکتور پیدا نشد.',409);
        }
        if ($sale->status!=='awaiting_payment' || !empty($sale->membership_id) || !empty($sale->api_payload_json) || $sale->api_sync_status!=='not_ready') {
            $this->fail('اشتراک+ وارد فرایند فعال‌سازی یا عملیات بیرونی شده است.',409);
        }
        // Any issued payment request is immutable here: an old provider callback can still arrive.
        if ($this->row('sn_payments','invoice_id=%d LIMIT 1',[$id],$lock)) $this->fail('برای این فاکتور درخواست پرداخت ثبت شده است؛ ویرایش مالی قفل است.',409);
        if ($this->row('sn_project_memberships','source_invoice_id=%d LIMIT 1',[$id],$lock) ||
            $this->row('sn_dot_cases','source_invoice_id=%d LIMIT 1',[$id],$lock) ||
            $this->row('sn_dot_payments','invoice_id=%d LIMIT 1',[$id],$lock)) $this->fail('فاکتور وارد پرونده تبدیل یا تحویل شده است.',409);
        $links=$this->rows('sn_dot_invoice_links','invoice_id=%d',[$id],$lock);
        if (count($links)>1 || ($links && ($links[0]['flow_kind']!=='assessment_source' || !empty($links[0]['case_id']) || !empty($links[0]['marketing_form_id']) || !empty($links[0]['payment_verified_at'])))) {
            $this->fail('اتصال این فاکتور به گردش‌کار دیگری اجازه ویرایش نمی‌دهد.',409);
        }
        $items=$this->rows('sn_invoice_items','invoice_id=%d',[$id],$lock);
        $base=array_values(array_filter($items,fn($i)=>$i['product_type']==='subscription_plus'));
        $cash=array_values(array_filter($items,fn($i)=>$i['product_type']==='subscription_plus_cash'));
        if (count($base)!==1 || count($cash)>1 || count($items)!==1+count($cash) || (int)$base[0]['qty']!==1 || (int)$base[0]['product_id']!==(int)$sale->product_id) $this->fail('اقلام و سابقه اشتراک+ سازگار نیستند؛ نیاز به بررسی مالی دارند.',409);
        $snapshot=json_decode($sale->snapshot_json,true);
        if (!is_array($snapshot) || (int)($snapshot['product_id']??0)!==(int)$sale->product_id ||
            self::cents($invoice->payment_total_amount)!==self::cents($sale->base_paid_price)+self::cents($sale->cash_price) ||
            ((float)$sale->cash_price>0)!==(bool)$cash) $this->fail('مبالغ یا snapshot اشتراک+ سازگار نیستند.',409);
        $gross=self::cents($snapshot['base_price']??null);$net=self::cents($sale->base_paid_price);$cash_price=self::cents($sale->cash_price);$total=$net+$cash_price;$discount=$gross-$net;
        if($gross<=0 || $net<=0 || $net>$gross || $cash_price<0 ||
            self::cents($invoice->final_total)!==$total || self::cents($invoice->product_price)!==$total || self::cents($invoice->remaining_amount)!==$total ||
            self::cents($invoice->original_total)!==$gross+$cash_price || self::cents($invoice->discount_total)!==$discount || self::cents($invoice->discount_amount)!==$discount ||
            self::cents($invoice->current_due_amount)<=50 || self::cents($invoice->current_due_amount)>$total || self::cents($stages[0]['requested_amount'])!==self::cents($invoice->current_due_amount) ||
            $stages[0]['stage_type']!==$invoice->payment_plan || self::cents($base[0]['unit_price'])!==$gross || self::cents($base[0]['total_price'])!==$gross ||
            self::cents($snapshot['cash_option']['price']??0)!==$cash_price || (bool)$sale->requires_conversion!==($snapshot['cash_option']===null) ||
            ($cash && ((int)$cash[0]['qty']!==1 || self::cents($cash[0]['unit_price'])!==$cash_price || self::cents($cash[0]['total_price'])!==$cash_price))) {
            $this->fail('مبالغ اقلام، مرحله و سابقه اشتراک+ هم‌خوان نیستند؛ ویرایش مسدود است.',409);
        }
        $last_edit=(int)$wpdb->get_var($wpdb->prepare('SELECT MAX(id) FROM '.$this->table('sn_activity_logs')." WHERE invoice_id=%d AND action='subscription_plus_pre_payment_edited'",$id));
        $revision=hash('sha256',wp_json_encode([$invoice,$stages,$sale,$items,$links,$last_edit]));
        return compact('invoice','permissions','stages','sale','items','base','cash','snapshot','links','revision');
    }
    private function current(array $state): array {
        $i=$state['invoice'];$s=$state['snapshot'];$sale=$state['sale'];
        $gross=self::cents($s['base_price']);$net=self::cents($sale->base_paid_price);
        $discounts=SN_Sales_Catalog::invoice_snapshot($i);
        return ['product_id'=>(int)$sale->product_id,'product_name'=>(string)$s['product_name'],
            'payment_plan'=>(string)$i->payment_plan,'prepayment_amount'=>(float)$i->current_due_amount,
            'cash_option'=>(string)($s['cash_option']['id']??'none'),'cash_option_title'=>(string)($s['cash_option']['title']??'بدون اعتبار نقدی'),
            'discount_id'=>(string)($s['base_discount_id']??$discounts[0]['option_id']??''),
            'base_gross'=>$gross/100,'base_discount'=>($gross-$net)/100,'base_net'=>$net/100,
            'cash_price'=>(float)$sale->cash_price,'cash_credit'=>(float)($s['cash_option']['credit']??0),
            'installment_credit'=>(float)$s['installment_credit'],'total'=>($net+self::cents($sale->cash_price))/100,
            'requires_conversion'=>(bool)$sale->requires_conversion];
    }
    private function product(int $id,int $actor,int $owner): void {
        if (!SN_Subscription_Plus::instance()->is_product($id) || get_post_status($id)!=='publish' || get_post_meta($id,'_sn_enabled',true)!=='1' || !wc_get_product($id)) $this->fail('محصول اشتراک+ مجاز و منتشرشده انتخاب کنید.');
        $valid=($this->validate_product)($id,$actor,$owner);
        if (is_wp_error($valid)) $this->fail($valid->get_error_message(),403);
    }
    private function catalog(array $state,int $actor): array {
        $owner=(int)$state['invoice']->seller_id;$result=[];
        $ids=$state['permissions']['can_edit_products']?get_posts(['post_type'=>'product','post_status'=>'publish','fields'=>'ids','posts_per_page'=>-1,'meta_query'=>[['key'=>'_sn_enabled','value'=>'1'],['key'=>'_sn_sales_product_type','value'=>'subscription_plus']]]):[(int)$state['sale']->product_id];
        foreach($ids as $id){try{$this->product((int)$id,$actor,$owner);}catch(RuntimeException $e){continue;}$p=wc_get_product($id);
            $result[]=['id'=>(int)$id,'name'=>$p->get_name(),'base_gross'=>(float)$p->get_price(),'cash_options'=>SN_Subscription_Plus::instance()->options((int)$id),'discounts'=>SN_Sales_Catalog::instance()->discounts((int)$id,$actor,$owner)];
        }
        return $result;
    }
    private function quote(array $state,array $input,int $actor): array {
        $old=$this->current($state);$owner=(int)$state['invoice']->seller_id;
        foreach(['product_id','cash_option','discount_id','payment_plan','prepayment_amount'] as $key) if(!isset($input[$key]) || !is_scalar($input[$key])) $this->fail('تمام گزینه‌های ویرایش باید معتبر و صریح باشند.');
        $pid=absint($input['product_id']);$choice=sanitize_key((string)$input['cash_option']);$discount=trim((string)$input['discount_id']);$plan=sanitize_key((string)$input['payment_plan']);
        if(!in_array($plan,['full','partial'],true)) $this->fail('نوع پرداخت معتبر نیست.');
        if($old['payment_plan']==='full' && $plan==='partial' && !$state['permissions']['can_make_partial']) $this->fail('مرحله‌ای‌کردن پرداخت کامل فقط در اختیار آخرین مسئول لینک پرداخت است.',403);
        if(!$state['permissions']['can_edit_products'] && ($pid!==$old['product_id'] || $choice!==$old['cash_option'] || $discount!==$old['discount_id'])) $this->fail('تغییر محصول، اعتبار نقدی یا تخفیف در اختیار مالک فاکتور است.',403);
        $this->product($pid,$actor,$owner);$snapshot=$state['snapshot'];
        $changed_product=$pid!==$old['product_id'];$changed_cash=$changed_product || $choice!==$old['cash_option'];
        if($changed_product || $changed_cash){$fresh=SN_Subscription_Plus::instance()->prepare_sale([$pid],['plus_cash_option'=>$choice]);if(is_wp_error($fresh))$this->fail($fresh->get_error_message());
            if($changed_product)$snapshot=$fresh;else{$snapshot['cash_option']=$fresh['cash_option'];$snapshot['cash_options']=$fresh['cash_options'];$snapshot['requires_conversion']=$fresh['requires_conversion'];}}
        $gross=self::cents($snapshot['base_price']);if($gross<=0)$this->fail('قیمت پایه معتبر نیست.');
        if(!$changed_product && $discount===$old['discount_id']) {
            $net=self::cents($state['sale']->base_paid_price);$discount_cents=$gross-$net;
            $catalog=['snapshot'=>SN_Sales_Catalog::invoice_snapshot($state['invoice']),'manager_user_id'=>(int)($state['invoice']->catalog_manager_user_id??0)];
        } else {
            $catalog=SN_Sales_Catalog::instance()->price_items([['product_id'=>$pid,'product_name'=>$snapshot['product_name'],'qty'=>1,'unit_price'=>$gross/100]],[$discount],$actor,$owner);
            if(is_wp_error($catalog))$this->fail($catalog->get_error_message(),403);
            $discount_cents=self::cents($catalog['discount_total']);$net=$gross-$discount_cents;
        }
        $cash=self::cents($snapshot['cash_option']['price']??0);$total=$net+$cash;
        $due=$plan==='full'?$total:self::cents($input['prepayment_amount']);
        if($net<=0 || $cash<0 || $due<=50 || $due>$total)$this->fail('مبلغ پایه باید مثبت و مبلغ مرحله بیشتر از نیم تومان و حداکثر برابر کل باشد.');
        if($due===$total)$plan='full';
        $snapshot['base_discount_id']=$discount;$snapshot['base_discount']=$discount_cents/100;$snapshot['base_net']=$net/100;
        $context=[];
        if(!empty($snapshot['requires_conversion'])) {
            if($state['links']){$l=$state['links'][0];$context=['supervisor_id'=>(int)$l['supervisor_id'],'conversion_route'=>(string)$l['conversion_route'],'requires_finance'=>(int)$l['requires_finance'],'sms_delay_minutes'=>(int)$l['sms_delay_minutes'],'send_assessment_sms'=>0];}
            else{$context=SN_Dot_Flow::instance()->prepare_invoice_context([$pid],$owner,$plan,[1],'supervisor_queue',false);if(is_wp_error($context))$this->fail($context->get_error_message());}
        }
        $proposed=['product_id'=>$pid,'product_name'=>$snapshot['product_name'],'payment_plan'=>$plan,'prepayment_amount'=>$due/100,
            'cash_option'=>$choice,'cash_option_title'=>$snapshot['cash_option']['title']??'بدون اعتبار نقدی','discount_id'=>$discount,
            'base_gross'=>$gross/100,'base_discount'=>$discount_cents/100,'base_net'=>$net/100,'cash_price'=>$cash/100,
            'cash_credit'=>(float)($snapshot['cash_option']['credit']??0),'installment_credit'=>(float)$snapshot['installment_credit'],'total'=>$total/100,'requires_conversion'=>(bool)$snapshot['requires_conversion']];
        return compact('proposed','snapshot','catalog','context');
    }
    private function token(int $actor,int $id,string $revision,array $quote,int $expires): string {
        return hash_hmac('sha256',wp_json_encode([$actor,$id,$revision,$quote,$expires]),wp_salt('nonce'));
    }
    private function update(string $suffix,array $data,array $where): void {
        global $wpdb;if(false===$wpdb->update($this->table($suffix),$data,$where))$this->fail('ذخیره امن تغییرات انجام نشد.',500);
    }
    private function persist(array $s,array $q,int $actor,string $reason): void {
        global $wpdb;$i=$s['invoice'];$id=(int)$i->id;$p=$q['proposed'];$now=current_time('mysql');
        $this->update('sn_invoices',['product_id'=>$p['product_id'],'product_price'=>$p['total'],'original_total'=>$p['base_gross']+$p['cash_price'],
            'discount_amount'=>$p['base_discount'],'discount_total'=>$p['base_discount'],'catalog_discounts_snapshot'=>wp_json_encode($q['catalog']['snapshot']),
            'catalog_manager_user_id'=>$q['catalog']['manager_user_id']?:null,'final_total'=>$p['total'],'payment_total_amount'=>$p['total'],
            'payment_plan'=>$p['payment_plan'],'current_due_amount'=>$p['prepayment_amount'],'remaining_amount'=>$p['total'],'updated_at'=>$now],['id'=>$id]);
        $this->update('sn_invoice_payment_stages',['stage_type'=>$p['payment_plan'],'requested_amount'=>$p['prepayment_amount'],'updated_at'=>$now],['id'=>(int)$s['stages'][0]['id']]);
        $this->update('sn_invoice_items',['product_id'=>$p['product_id'],'product_name'=>$p['product_name'],'unit_price'=>$p['base_gross'],'total_price'=>$p['base_gross']],['id'=>(int)$s['base'][0]['id']]);
        $cash_item=['product_id'=>0,'product_name'=>'اعتبار نقدی: '.$p['cash_option_title'],'product_type'=>'subscription_plus_cash','qty'=>1,'unit_price'=>$p['cash_price'],'total_price'=>$p['cash_price'],'is_free'=>0];
        if($p['cash_price']>0){if($s['cash'])$this->update('sn_invoice_items',$cash_item,['id'=>(int)$s['cash'][0]['id']]);elseif(!$wpdb->insert($this->table('sn_invoice_items'),$cash_item+['invoice_id'=>$id,'created_at'=>$now]))$this->fail('ذخیره ردیف اعتبار نقدی انجام نشد.',500);}
        elseif($s['cash'] && false===$wpdb->delete($this->table('sn_invoice_items'),['id'=>(int)$s['cash'][0]['id'],'invoice_id'=>$id]))$this->fail('حذف ردیف اعتبار نقدی انجام نشد.',500);
        $this->update('sn_subscription_plus_sales',['product_id'=>$p['product_id'],'snapshot_json'=>wp_json_encode($q['snapshot'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            'base_paid_price'=>$p['base_net'],'cash_price'=>$p['cash_price'],'requires_conversion'=>$p['requires_conversion']?1:0,'updated_at'=>$now],['id'=>(int)$s['sale']->id]);
        if($p['requires_conversion']){if(!$s['links'] && !SN_Dot_Flow::instance()->register_source_invoice_in_transaction($id,$q['context']))$this->fail('اتصال مسیر تبدیل ذخیره نشد.',500);}
        elseif($s['links'] && false===$wpdb->delete($this->table('sn_dot_invoice_links'),['id'=>(int)$s['links'][0]['id'],'invoice_id'=>$id]))$this->fail('به‌روزرسانی مسیر تبدیل انجام نشد.',500);
        // The audit is part of the same transaction; failed history means no financial edit.
        $old=$this->current($s);
        if(!$wpdb->insert($this->table('sn_activity_logs'),['invoice_id'=>$id,'lead_id'=>$i->lead_id?:null,'user_id'=>$actor,'action'=>'subscription_plus_pre_payment_edited',
            'old_value'=>wp_json_encode($old,JSON_UNESCAPED_UNICODE),'new_value'=>wp_json_encode($p,JSON_UNESCAPED_UNICODE),
            'description'=>'ویرایش اشتراک+ پیش از نخستین پرداخت روی همان فاکتور',
            'context'=>wp_json_encode(['revision'=>$s['revision'],'old_snapshot'=>$s['snapshot'],'new_snapshot'=>$q['snapshot'],'support_reason'=>$reason],JSON_UNESCAPED_UNICODE),'created_at'=>$now]))$this->fail('ثبت تاریخچه کامل انجام نشد؛ تغییرات ذخیره نشدند.',500);
    }
    public function handle(int $id,string $mode,array $input,int $actor,string $reason=''): array {
        global $wpdb;$transaction=false;$locked=false;$lock=SN_Invoice::stage_lock_name($id,1);
        try {
            if($id<1 || !in_array($mode,['read','preview','save'],true))$this->fail('درخواست ویرایش معتبر نیست.');
            foreach(['sn_invoices','sn_invoice_payment_stages','sn_invoice_items','sn_activity_logs','sn_dot_invoice_links','sn_dot_cases','sn_dot_payments','sn_project_memberships'] as $table)if(!$this->exists($table))$this->fail('ساختار لازم برای ویرایش امن آماده نیست.',503);
            if(!SN_Subscription_Plus::instance()->transactional_tables(['sn_invoices','sn_invoice_payment_stages','sn_invoice_items','sn_activity_logs','sn_dot_invoice_links']))$this->fail('ویرایش مالی امن به جدول‌های InnoDB نیاز دارد.',503);
            $locked=(int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock))===1;
            if(!$locked)$this->fail('درخواست پرداخت در حال پردازش است؛ ویرایش فعلاً قفل است.',409);
            if($mode==='save'){if(false===$wpdb->query('START TRANSACTION'))$this->fail('آغاز ذخیره امن انجام نشد.',500);$transaction=true;}
            $s=$this->load($id,$transaction);$old=$this->current($s);
            if($mode==='read')return ['success'=>true,'data'=>['editor_kind'=>'subscription_plus','invoice_id'=>$id,'invoice_code'=>(string)$s['invoice']->invoice_code,
                'revision'=>$s['revision'],'current'=>$old,'products'=>$this->catalog($s,$actor),'permissions'=>$s['permissions']]];
            if(!is_string($input['revision']??null) || !hash_equals($s['revision'],$input['revision']))$this->fail('فاکتور در پنجره دیگری تغییر کرده است؛ ابتدا بازخوانی کنید.',409);
            $q=$this->quote($s,$input,$actor);$expires=time()+300;
            if($mode==='preview')return ['success'=>true,'data'=>['old'=>$old,'proposed'=>$q['proposed'],'revision'=>$s['revision'],'expires'=>$expires,'preview_token'=>$this->token($actor,$id,$s['revision'],$q,$expires)]];
            $provided=is_scalar($input['expires']??null)?(int)$input['expires']:0;
            if(($input['confirmed']??'')!=='1' || $provided<time() || $provided>time()+300 || !is_string($input['preview_token']??null) || !hash_equals($this->token($actor,$id,$s['revision'],$q,$provided),$input['preview_token']))$this->fail('تأیید معتبرِ پیش‌نمایش لازم است؛ تغییرات را دوباره بررسی کنید.',409);
            $this->persist($s,$q,$actor,$reason);
            if(false===$wpdb->query('COMMIT'))$this->fail('ثبت نهایی تغییرات انجام نشد.',500);
            $transaction=false;
            return ['success'=>true,'message'=>'تغییرات روی همان فاکتور ذخیره شد؛ پرداخت، پیامک یا فعال‌سازی انجام نشد.','data'=>['invoice_id'=>$id,'invoice_code'=>(string)$s['invoice']->invoice_code,'proposed'=>$q['proposed']]];
        } catch(Throwable $e) {
            if($transaction)$wpdb->query('ROLLBACK');
            $status=$e instanceof RuntimeException && in_array($e->getCode(),[400,403,409,500,503],true)?$e->getCode():500;
            if($status===500)error_log('SN Plus edit failed for invoice #'.$id.': '.$e->getMessage());
            return ['success'=>false,'message'=>$status===500?'ذخیره امن تغییرات انجام نشد؛ هیچ تغییر مالی تأیید نشده است.':$e->getMessage(),'status'=>$status];
        } finally { if($locked)$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock)); }
    }
}
