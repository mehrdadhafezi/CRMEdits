<?php
if (!defined('ABSPATH')) { exit; }
/** Isolated outbox: reads legacy finance; writes only its own table/options. */
final class SN_Plus_API {
    private const SCHEMA='2026-10-31-plus-api-v1';
    private static ?self $instance=null;
    public static function instance(): self { return self::$instance ?? (self::$instance=new self()); }
    private function table(): string { global $wpdb; return $wpdb->prefix.'sn_plus_api_jobs'; }
    private function sales(): string { global $wpdb; return $wpdb->prefix.'sn_subscription_plus_sales'; }
    public function settings(): array { return (array)get_option('sn_plus_api_settings',[]); }
    private function secret(): string { $s=$this->settings(); return defined('SN_PLUS_API_SECRET')?(string)SN_PLUS_API_SECRET:(string)($s['secret']??''); }
    public function source(): string { return (string)get_option('sn_plus_api_source_id',''); }
    private function endpoint(): string { return (string)($this->settings()['endpoint']??''); }
    public function register_hooks(): void {
        add_filter('cron_schedules',static function ($s) { $s['sn_plus_api_minute']=['interval'=>60,'display'=>'SN Plus API']; return $s; });
        add_action('init',[$this,'setup'],30);
        add_action('sn_plus_api_tick',[$this,'tick']);
        add_action('admin_menu',[$this,'menu']);
        add_action('admin_post_sn_plus_api_save',[$this,'save']);
        add_action('admin_post_sn_plus_api_run',[$this,'run']);
        add_action('admin_post_sn_plus_api_retry',[$this,'retry']);
        register_deactivation_hook(SN_PLUGIN_FILE,static function () { wp_clear_scheduled_hook('sn_plus_api_tick'); });
        // Deliberately no HTTP, DDL, or nested transaction in finance hooks.
        // The rotating observer reads committed legacy state and recovers missed events.
    }
    private static function must($result): void { if ($result===false) { throw new RuntimeException('database'); } }
    public function setup(): void {
        global $wpdb;
        if (!get_option('sn_plus_api_source_id')) { add_option('sn_plus_api_source_id',wp_generate_uuid4(),'','no'); }
        if (get_option('sn_plus_api_schema_version')!==self::SCHEMA) {
            require_once ABSPATH.'wp-admin/includes/upgrade.php'; $table=$this->table(); $charset=$wpdb->get_charset_collate();
            dbDelta("CREATE TABLE {$table} (
                sale_id BIGINT UNSIGNED NOT NULL,
                revision INT UNSIGNED NOT NULL,
                state_hash CHAR(64) NOT NULL,
                destination_hash CHAR(64) NOT NULL,
                event_id VARCHAR(36) NOT NULL,
                payload_json LONGTEXT NOT NULL,
                delivery_status VARCHAR(20) NOT NULL DEFAULT 'pending',
                attempts INT UNSIGNED NOT NULL DEFAULT 0,
                next_attempt_at DATETIME DEFAULT NULL,
                lease_token VARCHAR(36) DEFAULT NULL,
                lease_expires_at DATETIME DEFAULT NULL,
                last_http_code INT UNSIGNED NOT NULL DEFAULT 0,
                last_error_code VARCHAR(60) NOT NULL DEFAULT '',
                ack_at DATETIME DEFAULT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY  (sale_id),
                UNIQUE KEY event_id (event_id),
                KEY delivery_due (delivery_status,next_attempt_at)
            ) ENGINE=InnoDB {$charset};");
            if ($this->ready()) { update_option('sn_plus_api_schema_version',self::SCHEMA,false); }
        }
        if ($this->enabled()) {
            if (!wp_next_scheduled('sn_plus_api_tick')) { wp_schedule_event(time()+30,'sn_plus_api_minute','sn_plus_api_tick'); }
        } else { wp_clear_scheduled_hook('sn_plus_api_tick'); }
    }
    private function ready(): bool {
        global $wpdb; $table=$this->table();
        $engine=$wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$table));
        if (strtolower((string)$engine)!=='innodb') { return false; }
        $cols=(array)$wpdb->get_col("SHOW COLUMNS FROM {$table}",0);
        return !array_diff(['sale_id','revision','state_hash','destination_hash','event_id','payload_json','delivery_status','attempts','next_attempt_at','lease_token','lease_expires_at','last_http_code','last_error_code','ack_at','updated_at'],$cols);
    }
    private static function valid_url(string $url): bool {
        $p=wp_parse_url($url);
        return is_array($p) && strtolower($p['scheme']??'')==='https' && !empty($p['host']) && !isset($p['user']) && !isset($p['pass']) && !isset($p['fragment']);
    }
    private function enabled(): bool {
        $s=$this->settings();
        return PHP_INT_SIZE>=8 && !empty($s['enabled']) && self::valid_url($this->endpoint()) && strlen($this->secret())>=32 && SN_Plus_API_Contract::uuid($this->source()) && $this->ready();
    }
    private function invoice_paid($i): bool {
        return $i && in_array((string)$i->status,['paid','approved'],true) && (string)($i->payment_workflow_status??'')==='completed' && isset($i->remaining_amount) && (float)$i->remaining_amount>-.01 && (float)$i->remaining_amount<=.5;
    }
    /** Read one committed snapshot after the sale lock. Do not invert core invoice/sale locks. */
    private function state(object $sale): ?array {
        global $wpdb;
        $p=json_decode((string)$sale->api_payload_json,true);
        if (!is_array($p) || !is_array($p['customer']??null) || !is_array($p['installment_wallet']??null) || !is_array($p['cash_wallet']??null)) { return null; }
        $invoice=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_invoices WHERE id=%d",(int)$sale->source_invoice_id));
        if ($wpdb->last_error || !$invoice) { throw new RuntimeException('source_read'); }
        $snapshot=json_decode((string)$sale->snapshot_json,true);
        if (!is_array($snapshot)) { throw new RuntimeException('snapshot'); }
        $phone=SN_Plus_API_Contract::phone($p['customer']['mobile']??'');
        $name=$p['customer']['name']??'';
        if (!$phone || !is_string($name) || trim($name)==='' || strlen($name)>1000 || !preg_match('/^.{1,250}$/usD',$name) || preg_match('/[\x00-\x1f\x7f]/u',$name)) { throw new RuntimeException('customer'); }
        $parts=preg_split('/\s+/u',trim($name),2); $first=$parts[0]; $last=$parts[1]??''; $name_source='split_full_name';
        $uid=(int)($p['customer']['user_id']??0);
        if ($uid>0) {
            $user_phone=get_user_meta($uid,'billing_phone',true);
            $f=get_user_meta($uid,'first_name',true); $l=get_user_meta($uid,'last_name',true);
            if (SN_Plus_API_Contract::phone($user_phone)===$phone && is_string($f) && is_string($l) && trim($f)!=='' && trim($l)!=='' && strlen($f)<=1000 && strlen($l)<=1000 && preg_match('/^.{1,250}$/usD',$f) && preg_match('/^.{1,250}$/usD',$l) && !preg_match('/[\x00-\x1f\x7f]/u',$f.$l)) { $first=$f; $last=$l; $name_source='user_meta'; }
        }
        $expected=(float)$sale->base_paid_price+(float)$sale->cash_price;
        $total=(float)($invoice->payment_total_amount??$invoice->final_total??$invoice->product_price??0);
        $active=(string)$sale->status==='active' && (int)$sale->membership_id>0 && $this->invoice_paid($invoice) && $expected>0 && abs($total-$expected)<.005 && (float)($invoice->paid_total_amount??0)+.005>=$expected;
        if ((int)$sale->membership_id>0) {
            $member=$wpdb->get_row($wpdb->prepare("SELECT status FROM {$wpdb->prefix}sn_project_memberships WHERE id=%d",(int)$sale->membership_id));
            if ($wpdb->last_error) { throw new RuntimeException('membership_read'); }
            $active=$active && $member && $member->status==='active';
        }
        if (!empty($sale->cash_conversion_case_id)) {
            $active = $active && SN_Conversion_Cash_Credit::instance()->entitlement_active($sale);
        }
        if (!empty($sale->requires_conversion)) {
            $case=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_dot_cases WHERE source_invoice_id=%d LIMIT 1",(int)$sale->source_invoice_id));
            if ($wpdb->last_error) { throw new RuntimeException('case_read'); }
            $option=$p['cash_wallet']['option_id']??null;
            $key=$option!==null?'plus_'.$option:'plus_none';
            $active=$active && $case && $case->status==='completed' && empty($case->operational_archived_at) && (string)$case->selected_option_key===$key;
            if ($case && $option!==null) {
                $bad=$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}sn_dot_payments d LEFT JOIN {$wpdb->prefix}sn_invoices i ON i.id=d.invoice_id WHERE d.case_id=%d AND d.status='paid' AND (i.id IS NULL OR i.status NOT IN ('paid','approved') OR COALESCE(i.payment_workflow_status,'')<>'completed' OR COALESCE(i.remaining_amount,1)>0.5 OR COALESCE(i.remaining_amount,-1)<=-0.01)",(int)$case->id));
                if ($bad===null || $wpdb->last_error) { throw new RuntimeException('conversion_read'); }
                $active=$active && (float)$case->paid_amount+.005>=(float)($p['cash_wallet']['paid_price']??0) && (int)$bad===0;
            }
        }
        if (($p['cash_wallet']['currency']??null)!=='IRT' || ($p['installment_wallet']['currency']??null)!=='IRT') { throw new RuntimeException('credit_currency'); }
        $selected=$snapshot['cash_option']??null;
        if (!empty($sale->requires_conversion)) {
            $selected=$snapshot['conversion_cash_option']??null;
            if (!$selected) {
                foreach ((array)($snapshot['cash_options']??[]) as $option_row) {
                    if (($option_row['id']??null)===($p['cash_wallet']['option_id']??null)) { $selected=$option_row; break; }
                }
            }
        }
        $cash_credit=SN_Plus_API_Contract::amount($p['cash_wallet']['credit']??null);
        if ($cash_credit!==SN_Plus_API_Contract::amount($selected['credit']??0) || ($selected['id']??null)!==($p['cash_wallet']['option_id']??null)) { throw new RuntimeException('cash_snapshot'); }
        $installment=SN_Plus_API_Contract::amount($p['installment_wallet']['credit']??null);
        if ($installment!==SN_Plus_API_Contract::amount($snapshot['installment_credit']??null)) { throw new RuntimeException('credit_snapshot'); }
        return ['customer'=>['first_name'=>$first,'last_name'=>$last,'full_name'=>$name,'phone'=>$phone,'name_source'=>$name_source], 'credits'=>['installment'=>['amount'=>$installment,'currency'=>'IRT'],'cash'=>['amount'=>$cash_credit,'currency'=>'IRT']], 'status'=>$active?'active':'suspended','references'=>['invoice_id'=>(int)$sale->source_invoice_id,'product_id'=>(int)$sale->product_id,'membership_id'=>(int)$sale->membership_id]];
    }
    public function queue(int $id): ?object {
        global $wpdb; if (!$this->enabled()) { return null; }
        $required=['sn_plus_api_jobs','sn_invoices','sn_project_memberships'];
        if (!SN_Subscription_Plus::instance()->transactional_tables($required)) { throw new RuntimeException('innodb_required'); }
        $t=$this->table(); self::must($wpdb->query('START TRANSACTION'));
        try {
            $sale=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.$this->sales().' WHERE id=%d FOR UPDATE',$id));
            $old=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE sale_id=%d FOR UPDATE",$id));
            if ($wpdb->last_error || !$sale) { throw new RuntimeException('sale_read'); }
            if ((!empty($sale->requires_conversion) || !empty($sale->cash_conversion_case_id)) && !SN_Subscription_Plus::instance()->transactional_tables(['sn_dot_cases','sn_dot_payments'])) { throw new RuntimeException('conversion_engine'); }
            $state=$this->state($sale);
            if (!$state && ($old || (int)$sale->membership_id>0)) { throw new RuntimeException('payload_missing'); }
            if (!$state || (!$old && $state['status']!=='active')) { self::must($wpdb->query('COMMIT')); return null; }
            $destination=hash('sha256',$this->endpoint());
            $json=wp_json_encode([$this->source(),$destination,$state],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            if (!is_string($json)) { throw new RuntimeException('encoding'); }
            $hash=hash('sha256',$json);
            if ($old && hash_equals((string)$old->state_hash,$hash)) { self::must($wpdb->query('COMMIT')); return $old; }
            $revision=$old?(int)$old->revision+1:1;
            if ($revision<1 || $revision>2147483647) { throw new RuntimeException('revision_limit'); }
            $event=wp_generate_uuid4();
            $payload=array_merge(['schema_version'=>'1.0','event_id'=>$event,'event_type'=>'subscription_plus.entitlement.updated','source_id'=>$this->source(),'entitlement_id'=>'subscription-plus:'.$id,'revision'=>$revision,'occurred_at'=>gmdate('c')],$state);
            $body=wp_json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            if (!is_string($body) || strlen($body)>16384) { throw new RuntimeException('body_limit'); }
            $values=['revision'=>$revision,'state_hash'=>$hash,'destination_hash'=>$destination,'event_id'=>$event,'payload_json'=>$body,'delivery_status'=>'pending','attempts'=>0,'next_attempt_at'=>gmdate('Y-m-d H:i:s'),'lease_token'=>null,'lease_expires_at'=>null,'last_http_code'=>0,'last_error_code'=>'','ack_at'=>null,'updated_at'=>gmdate('Y-m-d H:i:s')];
            if ($old) { self::must($wpdb->update($t,$values,['sale_id'=>$id])); }
            else { $values['sale_id']=$id; self::must($wpdb->insert($t,$values)); }
            self::must($wpdb->query('COMMIT'));
            return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE sale_id=%d",$id));
        } catch (Throwable $e) { $wpdb->query('ROLLBACK'); throw $e; }
    }
    public function tick(): void {
        global $wpdb; if (!$this->enabled()) { return; }
        $lock='sn_plus_api_'.substr(hash('sha256',$wpdb->prefix),0,32);
        if ((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock))!==1) { return; }
        try {
            $t=$this->table();
            // A fatal/terminated request may use the eighth attempt without
            // reaching finish(). Preserve its event/body and expose uncertainty.
            self::must($wpdb->query("UPDATE {$t} SET delivery_status='failed',last_error_code='delivery_uncertain',updated_at=UTC_TIMESTAMP() WHERE delivery_status IN ('pending','retry') AND attempts>=8 AND (lease_expires_at IS NULL OR lease_expires_at<=UTC_TIMESTAMP())"));
            $cursor=(int)get_option('sn_plus_api_scan_cursor',0); $sales=$this->sales();
            $ids=$wpdb->get_col($wpdb->prepare("SELECT s.id FROM {$sales} s LEFT JOIN {$t} j ON j.sale_id=s.id WHERE s.id>%d AND (s.membership_id>0 OR j.sale_id IS NOT NULL) ORDER BY s.id LIMIT 50",$cursor));
            if ($wpdb->last_error) { throw new RuntimeException('scan'); }
            foreach ($ids as $id) { try { $this->queue((int)$id); } catch (Throwable $e) { $this->warning('queue_unavailable',(int)$id); } }
            update_option('sn_plus_api_scan_cursor',count($ids)===50?(int)end($ids):0,false);
            $due=$wpdb->get_col("SELECT sale_id FROM {$t} WHERE delivery_status IN ('pending','retry') AND attempts<8 AND (next_attempt_at IS NULL OR next_attempt_at<=UTC_TIMESTAMP()) AND (lease_expires_at IS NULL OR lease_expires_at<=UTC_TIMESTAMP()) ORDER BY next_attempt_at,sale_id LIMIT 2");
            if ($wpdb->last_error) { throw new RuntimeException('delivery_scan'); }
            foreach ((array)$due as $id) { $this->deliver((int)$id); }
        } catch (Throwable $e) { $this->warning('worker_unavailable',0); }
        finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock)); }
    }
    private function warning(string $code,int $id): void { update_option('sn_plus_api_last_warning',['code'=>$code,'sale_id'=>$id,'at'=>gmdate('c')],false); }
    public function deliver(int $id): void {
        global $wpdb; $t=$this->table();
        try { $job=$this->queue($id); }
        catch (Throwable $e) {
            $wpdb->query($wpdb->prepare("UPDATE {$t} SET next_attempt_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 5 MINUTE),last_error_code='source_recheck' WHERE sale_id=%d AND delivery_status IN ('pending','retry') AND (lease_expires_at IS NULL OR lease_expires_at<=UTC_TIMESTAMP())",$id)); return;
        }
        if (!$job || !in_array($job->delivery_status,['pending','retry'],true)) { return; }
        $token=wp_generate_uuid4();
        $claim=$wpdb->query($wpdb->prepare("UPDATE {$t} SET lease_token=%s,lease_expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 120 SECOND),attempts=attempts+1 WHERE sale_id=%d AND revision=%d AND delivery_status IN ('pending','retry') AND attempts<8 AND (next_attempt_at IS NULL OR next_attempt_at<=UTC_TIMESTAMP()) AND (lease_expires_at IS NULL OR lease_expires_at<=UTC_TIMESTAMP())",$token,$id,(int)$job->revision));
        if ($claim!==1) { return; }
        $job=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE sale_id=%d AND lease_token=%s",$id,$token));
        if (!$job) { return; }
        $s=$this->settings(); $endpoint=$this->endpoint();
        if (!$this->enabled() || !hash_equals((string)$job->destination_hash,hash('sha256',$endpoint))) { $this->finish($job,$token,0,'config_changed',false); return; }
        $timestamp=(string)time();
        try {
            $response=wp_safe_remote_post($endpoint,['timeout'=>8,'redirection'=>0,'sslverify'=>true,'limit_response_size'=>16384,'headers'=>['Accept'=>'application/json','Content-Type'=>'application/json','X-SN-Timestamp'=>$timestamp,'X-SN-Event-ID'=>$job->event_id,'X-SN-Signature'=>SN_Plus_API_Contract::sign((string)$job->payload_json,$timestamp,(string)$job->event_id,$this->secret())],'body'=>(string)$job->payload_json]);
            if (is_wp_error($response)) { $this->finish($job,$token,0,'transport_error',true); return; }
            $http=(int)wp_remote_retrieve_response_code($response);
            $ack=json_decode((string)wp_remote_retrieve_body($response),true);
            if ($http>=200 && $http<300 && SN_Plus_API_Contract::ack($ack,(string)$job->event_id,(int)$job->revision)) { $this->finish($job,$token,$http,'',false); return; }
            $retry=$http===408 || $http===429 || $http>=500;
            $this->finish($job,$token,$http,$http>=200 && $http<300?'invalid_ack':'http_error',$retry);
        } catch (Throwable $e) { $this->finish($job,$token,0,'transport_error',true); }
    }
    private function finish(object $job,string $token,int $http,string $error,bool $retry): void {
        global $wpdb;
        $ok=$error===''; $retry=$retry && (int)$job->attempts<8;
        $delays=[60,300,900,3600,10800,21600,43200,43200];
        $values=['delivery_status'=>$ok?'synced':($retry?'retry':'failed'),'last_http_code'=>$http,'last_error_code'=>$error,'lease_token'=>null,'lease_expires_at'=>null,'next_attempt_at'=>$retry?gmdate('Y-m-d H:i:s',time()+$delays[min(7,max(0,(int)$job->attempts-1))]):null,'ack_at'=>$ok?gmdate('Y-m-d H:i:s'):null,'updated_at'=>gmdate('Y-m-d H:i:s')];
        $updated=$wpdb->update($this->table(),$values,['sale_id'=>(int)$job->sale_id,'revision'=>(int)$job->revision,'lease_token'=>$token]);
        if ($updated===false) { $this->warning('ack_save_failed',(int)$job->sale_id); }
    }
    public function menu(): void { add_submenu_page('sn-dashboard','API اعتبار مقصد','API اعتبار مقصد','manage_options','sn-plus-api',[$this,'page']); }
    private function auth(string $nonce): void { if (!current_user_can('manage_options')) { wp_die('Forbidden','',['response'=>403]); } check_admin_referer($nonce); }
    public function save(): void {
        $this->auth('sn_plus_api_save'); $old=$this->settings();
        $endpoint=trim((string)wp_unslash($_POST['endpoint']??''));
        $secret=defined('SN_PLUS_API_SECRET')?$this->secret():(string)wp_unslash($_POST['secret']??''); if ($secret==='') { $secret=$this->secret(); }
        $enabled=!empty($_POST['enabled']);
        if (($endpoint!=='' && !self::valid_url($endpoint)) || ($enabled && ($endpoint==='' || strlen($secret)<32 || strlen($secret)>512))) { wp_safe_redirect(admin_url('admin.php?page=sn-plus-api&invalid=1')); exit; }
        $settings=['enabled'=>$enabled?1:0,'endpoint'=>$endpoint]; if (!defined('SN_PLUS_API_SECRET')) { $settings['secret']=$secret; }
        update_option('sn_plus_api_settings',$settings,false);
        if (($old['endpoint']??'')!==$endpoint || (bool)($old['enabled']??false)!==$enabled) { update_option('sn_plus_api_scan_cursor',0,false); }
        $this->setup(); wp_safe_redirect(admin_url('admin.php?page=sn-plus-api&saved=1')); exit;
    }
    public function run(): void { $this->auth('sn_plus_api_run'); $this->tick(); wp_safe_redirect(admin_url('admin.php?page=sn-plus-api')); exit; }
    public function retry(): void {
        global $wpdb; $this->auth('sn_plus_api_retry'); $id=absint($_POST['sale_id']??0);
        if ($this->enabled()) { $wpdb->query($wpdb->prepare('UPDATE '.$this->table()." SET delivery_status='retry',attempts=0,next_attempt_at=UTC_TIMESTAMP(),last_error_code='' WHERE sale_id=%d AND (lease_expires_at IS NULL OR lease_expires_at<=UTC_TIMESTAMP())",$id)); }
        wp_safe_redirect(admin_url('admin.php?page=sn-plus-api')); exit;
    }
    public function page(): void {
        global $wpdb; if (!current_user_can('manage_options')) { return; } $s=$this->settings();
        echo '<div class="wrap" dir="rtl"><h1>API اعتبار مقصد</h1><p>ارسال نام، نام خانوادگی، شماره و دو اعتبار اشتراک+ به افزونه مقصد ووکامرس. پیش‌فرض خاموش؛ اطلاعات مالی قبلی بازنویسی نمی‌شود.</p>';
        if (isset($_GET['invalid'])) { echo '<div class="notice notice-error"><p>آدرس HTTPS و کلید حداقل ۳۲ کاراکتری لازم است.</p></div>'; }
        $warning=get_option('sn_plus_api_last_warning',[]); if (is_array($warning) && !empty($warning['code'])) { echo '<p>آخرین هشدار: '.esc_html((string)$warning['code']).' / تخصیص '.esc_html((string)($warning['sale_id']??0)).' / '.esc_html((string)($warning['at']??'')).'</p>'; }
        if (!SN_Plus_API_Contract::uuid($this->source())) { echo '<p>شناسه منبع معتبر نیست؛ برای حفظ هویت اعتبار، شناسه را خودکار بازسازی نمی‌کنیم. بررسی مدیر لازم است.</p>'; }
        echo '<p>شناسه ثابت منبع: <code dir="ltr">'.esc_html($this->source()).'</code></p>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'; wp_nonce_field('sn_plus_api_save');
        echo '<input type="hidden" name="action" value="sn_plus_api_save"><table class="form-table"><tr><th>ارسال فعال</th><td><input type="checkbox" name="enabled" value="1" '.checked(!empty($s['enabled']),true,false).'></td></tr><tr><th>آدرس دریافت مقصد</th><td><input class="large-text" dir="ltr" name="endpoint" value="'.esc_attr($this->endpoint()).'" placeholder="https://shop.example.com/wp-json/sn-plus/v1/entitlements"></td></tr><tr><th>کلید مشترک</th><td><input class="regular-text" name="secret" type="password" autocomplete="new-password" '.(defined('SN_PLUS_API_SECRET')?'disabled':'').'><p>برای حفظ کلید فعلی خالی بگذارید. کلید دقیقاً یکسان را در مقصد ثبت کنید؛ ثابت SN_PLUS_API_SECRET در wp-config اولویت دارد.</p></td></tr></table>';
        submit_button('ذخیره'); echo '</form><p>نام و تلفن از تخصیص تأییدشده خوانده می‌شود؛ مبلغ اعتبار با قیمت فروش تفاوت دارد. اسکن هر نوبت حداکثر ۵۰ تخصیص و ارسال حداکثر ۲ پیام است. اجرای خودکار به WP-Cron وابسته است.</p><p>تغییر مقصد، اعتبار سایت قبلی را پس نمی‌گیرد. توقف ارسال نیز به‌تنهایی اعتبار مقصد را لغو نمی‌کند.</p>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'; wp_nonce_field('sn_plus_api_run'); echo '<input type="hidden" name="action" value="sn_plus_api_run">'; submit_button('اجرای یک نوبت صف','secondary'); echo '</form>';
        if (!$this->ready()) { echo '<p>جدول صف InnoDB آماده نیست؛ ارسال متوقف است.</p></div>'; return; }
        $rows=$wpdb->get_results('SELECT sale_id,revision,delivery_status,attempts,last_http_code,last_error_code,updated_at FROM '.$this->table().' ORDER BY updated_at DESC LIMIT 50');
        echo '<table class="widefat striped"><thead><tr><th>تخصیص</th><th>نسخه</th><th>وضعیت</th><th>تلاش</th><th>HTTP / نتیجه</th><th>ارسال مجدد همان پیام</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            echo '<tr><td>'.esc_html($row->sale_id).'</td><td>'.esc_html($row->revision).'</td><td>'.esc_html($row->delivery_status).'</td><td>'.esc_html($row->attempts).'</td><td>'.esc_html($row->last_http_code.' / '.$row->last_error_code).'</td><td><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'; wp_nonce_field('sn_plus_api_retry'); echo '<input type="hidden" name="action" value="sn_plus_api_retry"><input type="hidden" name="sale_id" value="'.esc_attr($row->sale_id).'"><button class="button">تلاش مجدد</button></form></td></tr>';
        }
        echo '</tbody></table><p>راهنمای اتصال و گزارش بررسی به‌صورت فایل جدا ارائه شده است. این نسخه ادغام‌شده هنوز نیازمند آزمون روی کپی واقعی دیتابیس است.</p></div>';
    }
}
