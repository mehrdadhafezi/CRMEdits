<?php
if (!defined('ABSPATH')) { exit; }
require_once __DIR__.'/class-sn-mis-jobs.php';
trait SN_MIS_Extras {
    private function sn_mis_jobs(): SN_MIS_Jobs { global $wpdb;return new SN_MIS_Jobs($wpdb,get_current_user_id()); }
    private function sn_mis_preferences(): array {
        $defaults=['page_size'=>50,'density'=>'comfortable','theme'=>'light','chart_days'=>31,'refresh'=>0,'columns'=>['id','phone','batch','manager','state','date','actions']];
        $stored=get_user_meta(get_current_user_id(),'sn_mis_preferences_v3',true);return is_array($stored)?array_merge($defaults,$stored):$defaults;
    }
    private function sn_mis_extra_operation(string $operation): bool {
        if (!in_array($operation,['preferences','save_report','delete_report','start_index','start_audit','start_export','job_step','job_cancel'],true))return false;
        if ($operation==='preferences') {
            $p=$this->sn_mis_preferences();$size=absint($_POST['page_size']??50);$p['page_size']=in_array($size,[25,50,100],true)?$size:50;
            $p['density']=($_POST['density']??'')==='compact'?'compact':'comfortable';$p['theme']=($_POST['theme']??'')==='dark'?'dark':'light';
            $days=absint($_POST['chart_days']??31);$p['chart_days']=in_array($days,[7,31,90,365],true)?$days:31;$p['refresh']=($_POST['refresh']??'')==='300'?300:0;
            $p['columns']=array_values(array_intersect(['id','phone','batch','manager','state','date','actions'],array_map('sanitize_key',(array)($_POST['columns']??[]))));
            $p['columns']=array_values(array_unique(array_merge($p['columns'],['phone','state','actions'])));update_user_meta(get_current_user_id(),'sn_mis_preferences_v3',$p);
            $this->sn_mis_v2_notice(['message'=>'تنظیمات نمایش ذخیره شد.'],'tools');
        }
        if ($operation==='save_report' || $operation==='delete_report') {
            $reports=get_user_meta(get_current_user_id(),'sn_mis_named_reports_v3',true);if(!is_array($reports))$reports=[];
            if($operation==='delete_report') { unset($reports[sanitize_key($_POST['report_id']??'')]); }
            else {
                $name=sanitize_text_field(wp_unslash($_POST['report_name']??''));if($name==='')throw new RuntimeException('نام گزارش لازم است.');
                if(count($reports)>=20)throw new RuntimeException('حداکثر ۲۰ گزارش شخصی؛ یکی را حذف کنید.');
                $this->sn_mis_v2_filters($_POST);$filters=[];foreach(['batch_id','manager_id','campaign','branch','search','state','consumed','date_type','event_type','from','to','report_mode'] as $key) { if(isset($_POST[$key]) && is_scalar($_POST[$key]))$filters[$key]=sanitize_text_field(wp_unslash($_POST[$key])); }
                $reports[bin2hex(random_bytes(8))]=['name'=>wp_html_excerpt($name,100,''),'filters'=>$filters];
            }
            update_user_meta(get_current_user_id(),'sn_mis_named_reports_v3',$reports);$this->sn_mis_v2_notice(['message'=>'گزارش‌های شخصی به‌روزرسانی شد.'],'reports');
        }
        $jobs=$this->sn_mis_jobs();
        if(str_starts_with($operation,'start_')) {
            $kind=substr($operation,6);$filters=$kind==='export'?$this->sn_mis_v2_filters($_POST):[];$id=$jobs->start($kind,$filters,($_POST['report_mode']??'')==='events');
            $this->sn_mis_v2_notice(['message'=>'پردازش #'.$id.' ساخته شد. ادامه خودکار را فعال کنید؛ هر مرحله کوتاه و قابل ادامه است.'],'tools');
        }
        $id=absint($_POST['job_id']??0);
        if($operation==='job_cancel')$jobs->cancel($id);else $jobs->step($id,[$this,'sn_mis_export_values']);
        $this->sn_mis_v2_notice(['message'=>'مرحله پردازش ثبت شد.'],'tools');return true;
    }
    public function sn_mis_export_values(array $row,bool $history): array {
        return [$row['id'],$row['source_code']??'',$row['normalized_phone'],$this->sn_mis_source_time((string)($row['source_datetime_raw']??'')),$row['first_name']?:$row['customer_name'],$row['last_name']??'',$row['batch_code']??'',$row['campaign_code']??'',$row['branch_label']?:'نامشخص',$this->sn_user_label((int)($history?($row['new_manager_id']?:$row['old_manager_id']):($row['resolved_manager_id']??$row['assigned_manager_user_id']))),$this->sn_mis_v2_time($row['event_time']??'',true),$this->sn_mis_v2_state_label($row['logical_state']),!empty($row['is_consumed'])?'مصرف‌شده':(in_array($row['logical_state'],['LOCKED','PENDING'],true)?'نامشخص/نیازمند بررسی':'مصرف‌نشده'),$row['block_reason'],$this->sn_mis_v2_time($row['return_time']??'',true),$row['return_reason']??'',$history?$this->sn_mis_v2_event_label($row['event_type']):'',$history?$row['event_reason']:''];
    }
    public function handle_mis_job(): void {
        if(!$this->sn_can_access_mis_panel())wp_send_json_error(['message'=>'دسترسی غیرمجاز'],403);
        if(!check_ajax_referer('sn_mis_jobs','nonce',false))wp_send_json_error(SN_MIS_Feedback::session(),403);if(($_SERVER['REQUEST_METHOD']??'')!=='POST')wp_send_json_error(['message'=>'روش درخواست معتبر نیست.'],405);
        try { $result=$this->sn_mis_jobs()->step(absint($_POST['job_id']??0),[$this,'sn_mis_export_values']);wp_send_json_success($result); }
        catch(Throwable $e) { wp_send_json_error(SN_MIS_Feedback::error($e),409); }
    }
    public function handle_mis_download(): void {
        if(!$this->sn_can_access_mis_panel())wp_die('دسترسی غیرمجاز','MIS',['response'=>403]);check_admin_referer('sn_mis_jobs');
        try { $id=absint($_GET['job_id']??0);$path=$this->sn_mis_jobs()->download($id);nocache_headers();header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');header('Content-Disposition: attachment; filename="mis-report-'.$id.'.xlsx"');header('Content-Length: '.filesize($path));header('X-Content-Type-Options: nosniff');readfile($path);exit; }
        catch(Throwable $e) { wp_die(esc_html(SN_MIS_Feedback::error($e)['message']),'MIS',['response'=>409]); }
    }
    private function sn_mis_batch_options(array $input=[],bool $active=false): array {
        global $wpdb;$service=$this->sn_mis_v2_service();$search=sanitize_text_field(wp_unslash($input['batch_search']??''));$where=$active?" WHERE import_status NOT IN ('archived','deleted','file_deleted','reset_deleted')":" WHERE 1=1";
        if($search!=='') { $like='%'.$wpdb->esc_like($search).'%';$where.=$wpdb->prepare(' AND (batch_code LIKE %s OR title LIKE %s)',$like,$like); }
        $rows=$service->rows('SELECT id,batch_code,title FROM '.$wpdb->prefix.'sn_mis_import_batches'.$where.' ORDER BY id DESC LIMIT 100');$selected=absint($input['batch_id']??0);
        if($selected && !in_array($selected,array_map('intval',array_column($rows,'id')),true)) { $rows=array_merge($rows,$service->rows($wpdb->prepare('SELECT id,batch_code,title FROM '.$wpdb->prefix.'sn_mis_import_batches'.$where.' AND id=%d',$selected))); }
        return $rows;
    }
    private function sn_mis_index_notice(): void {
        global $wpdb;$model=new SN_MIS_Read_Model($wpdb);
        if(!$model->ready() || !$this->sn_mis_jobs()->ready() || !(new SN_MIS_Import_Log($wpdb,get_current_user_id()))->ready()) {
            echo '<p class="sn-mis-notice">برای ثبت سابقه فایل‌ها، آماده‌سازی تکمیلی لازم است. <a href="'.esc_url($this->sn_mis_v2_url('tools')).'">راه‌اندازی و ادامه کار ←</a></p>';return;
        }
        $coverage=$model->coverage_hint();
        if(get_option('sn_mis_index_needs_rebuild',0) || $coverage['pending']>0)echo '<p role="status" class="sn-mis-notice sn-mis-pending-notice">وضعیت '.number_format_i18n($coverage['pending']).' شماره هنوز بررسی نشده است. عدد نامشخص با «—» نمایش داده می‌شود؛ این شماره‌ها خام فرض نمی‌شوند. <a href="'.esc_url($this->sn_mis_v2_url('tools')).'">آماده‌سازی آمار ←</a></p>';
    }
    private function sn_mis_tools_page(): void {
        global $wpdb;$jobs=$this->sn_mis_jobs();$coverage=(new SN_MIS_Read_Model($wpdb))->coverage_hint();
        $section=($_GET['section']??'')==='display'?'display':'jobs';echo '<nav class="sn-mis-tool-tabs" aria-label="ابزارهای MIS">';foreach(['jobs'=>'آمادگی و پردازش‌ها','display'=>'نمایش شخصی'] as $key=>$label)echo '<a href="'.esc_url($this->sn_mis_v2_url('tools',['section'=>$key])).'" '.($section===$key?'aria-current="page"':'').'>'.$label.'</a>';echo '</nav>';
        if($section==='jobs') {
        if(!$jobs->ready() || !$coverage['ready'] || !(new SN_MIS_Import_Log($wpdb,get_current_user_id()))->ready()) { $this->sn_mis_setup_card();if(!$jobs->ready() || !$coverage['ready'])return; }
        echo '<section class="sn-mis-card"><h3>آماده‌سازی آمار و ممیزی</h3><p>بررسی‌شده: '.number_format_i18n($coverage['indexed']).' از '.number_format_i18n($coverage['total']).'. پردازش با بسته‌های کوچک پیش می‌رود و بعد از قطع ارتباط قابل ادامه است. خروجی‌ها ۲۴ ساعت در دسترس‌اند.</p>';
        echo '<div class="sn-mis-start-actions">';if($jobs->ready() && $coverage['ready']) { foreach(['start_index'=>'آماده‌سازی یا تازه‌سازی آمار','start_audit'=>'ممیزی فقط خواندنی'] as $op=>$label) { $this->sn_mis_v2_form_start($op);echo '<button class="sn-btn sn-btn-secondary">'.$label.'</button></form>'; } }
        echo '</div></section><section class="sn-mis-card" data-job-api="'.esc_url(admin_url('admin-ajax.php')).'" data-job-nonce="'.esc_attr(wp_create_nonce('sn_mis_jobs')).'"><h3>پردازش‌های من</h3><p>برای شروع یا ادامه، دکمه ادامه خودکار را بزنید. با بستن صفحه، پیشرفت ذخیره‌شده از بین نمی‌رود.</p>';
        $recent=$jobs->recent();if(!$recent)echo '<div class="sn-mis-empty"><strong>هنوز پردازشی شروع نشده است</strong><p>از آماده‌سازی آمار در بالای صفحه یا خروجی Excel در گزارش‌ها شروع کنید.</p></div>';
        foreach($recent as $job) {
            $id=(int)$job['id'];$active=in_array($job['status'],['queued','running'],true);echo '<article class="sn-mis-job" data-job-id="'.$id.'"><h4>#'.$id.' — '.esc_html(['index'=>'آمار','audit'=>'ممیزی','export'=>'خروجی Excel'][$job['kind']]??$job['kind']).'</h4><p class="sn-mis-job-status" role="status">'.esc_html($this->sn_mis_job_status_label($job['status'])).' — '.(int)$job['processed'].' / '.(int)$job['total'].'</p><progress max="'.max(1,(int)$job['total']).'" value="'.(int)$job['processed'].'"></progress>';
            if($active) {
                echo '<button type="button" class="sn-btn sn-mis-job-run">ادامه خودکار</button><button type="button" class="sn-btn sn-mis-job-pause" hidden>توقف موقت</button>';
                $this->sn_mis_v2_form_start('job_step');echo '<input type="hidden" name="job_id" value="'.$id.'"><button class="sn-btn">اجرای یک مرحله</button></form>';
            }
            if($job['kind']==='export' && $job['status']==='ready')echo '<a class="sn-btn" href="'.esc_url(wp_nonce_url(add_query_arg(['action'=>'sn_mis_download','job_id'=>$id],admin_url('admin-post.php')),'sn_mis_jobs')).'">دانلود Excel</a>';
            $this->sn_mis_v2_form_start('job_cancel');echo '<input type="hidden" name="job_id" value="'.$id.'"><button class="sn-btn" data-confirm-action="لغو این پردازش و حذف فایل موقت آن را تأیید می‌کنید؟">لغو پردازش / حذف فایل موقت</button></form>';
            if(!empty($job['result_json'])) { $result=json_decode($job['result_json'],true);echo '<details><summary>نتیجه بررسی</summary><p>'.esc_html($result['message']??'').'</p>';foreach($result['counts']??[] as $name=>$count) { echo '<p>'.esc_html($this->sn_mis_audit_label($name)).': '.(int)$count.' — نمونه شناسه‌ها: '.esc_html(implode('، ',$result['samples'][$name]??[])).'</p>'; }echo '</details>'; }echo '</article>';
        }echo '</section>';
        }
        $p=$this->sn_mis_preferences();if($section!=='display')return;echo '<section class="sn-mis-card"><h3>تنظیمات نمایش شخصی</h3>';$this->sn_mis_v2_form_start('preferences');
        foreach(['page_size'=>['تعداد ردیف صفحه',[25=>'۲۵',50=>'۵۰',100=>'۱۰۰']],'density'=>['فاصله ردیف‌ها',['comfortable'=>'معمولی','compact'=>'فشرده']],'theme'=>['رنگ‌بندی',['light'=>'روشن','dark'=>'تیره']],'chart_days'=>['بازه نمودار',[7=>'۷ روز',31=>'۳۱ روز',90=>'۹۰ روز',365=>'۳۶۵ روز']],'refresh'=>['تازه‌سازی صفحه',[0=>'دستی',300=>'هر ۵ دقیقه']]] as $key=>$info) { echo '<label>'.esc_html($info[0]).'<select name="'.$key.'">';foreach($info[1] as $value=>$label)echo '<option value="'.esc_attr($value).'" '.selected($p[$key],$value,false).'>'.esc_html($label).'</option>';echo '</select></label>'; }
        echo '<fieldset><legend>ستون‌های جدول</legend>';foreach(['id'=>'شناسه و کد منبع','batch'=>'پرونده و کمپین','manager'=>'مدیر و شعبه','date'=>'زمان'] as $key=>$label)echo '<label><input type="checkbox" name="columns[]" value="'.$key.'" '.checked(in_array($key,$p['columns'],true),true,false).'>'.$label.'</label>';echo '<p>شماره، وضعیت و عملیات همیشه نمایش داده می‌شوند.</p></fieldset><button class="sn-btn sn-btn-primary">ذخیره تنظیمات</button></form></section>';
    }
    private function sn_mis_job_status_label(string $s): string { return ['queued'=>'در صف','running'=>'در حال پردازش','ready'=>'آماده','cancelled'=>'لغو شده','failed'=>'ناموفق'][$s]??$s; }
    private function sn_mis_audit_label(string $s): string { return ['lead'=>'دارای لید','invoice'=>'دارای فاکتور','reserved'=>'رزرو برنامه','history'=>'سابقه عملیاتی','operated'=>'مصرف عملیاتی','unknown'=>'نیازمند بررسی','duplicate'=>'هویت تکراری','orphan_batch'=>'پرونده مبنا مفقود','multiple_items'=>'توزیع متعارض','consumed_event'=>'سابقه مصرف تغییرناپذیر','claim_conflict'=>'تعارض مالکیت رزرو','index_mismatch'=>'اختلاف با آخرین نمای آماری','orphan_delivery'=>'آیتم توزیع بدون ردیف مبنا','orphan_pool'=>'مخزن توزیع بدون ردیف مبنا','orphan_claim'=>'رزرو بدون ردیف مبنا'][$s]??$s; }
    private function sn_mis_named_reports(): void {
        $reports=get_user_meta(get_current_user_id(),'sn_mis_named_reports_v3',true);if(!is_array($reports))$reports=[];
        echo '<section class="sn-mis-card"><h3>گزارش‌های شخصی</h3>';
        foreach($reports as $id=>$report) { echo '<div><a href="'.esc_url($this->sn_mis_v2_url('reports',['saved_report'=>$id])).'">'.esc_html($report['name']).'</a>';$this->sn_mis_v2_form_start('delete_report');echo '<input type="hidden" name="report_id" value="'.esc_attr($id).'"><button class="sn-btn" data-confirm-action="حذف این گزارش ذخیره‌شده را تأیید می‌کنید؟">حذف گزارش ذخیره‌شده</button></form></div>'; }
        echo '<a href="'.esc_url($this->sn_mis_v2_url('reports',['load_saved'=>1])).'">بازیابی فیلترهای ذخیره‌شده نسخه قبل</a></section>';
    }
    private function sn_mis_charts(array $stats,array $history): void {
        echo '<section class="sn-mis-card"><h3>نمودار موجودی</h3><div class="sn-mis-chart-bars">';$total=max(1,array_sum($stats));foreach($stats as $state=>$count) { echo '<div><span>'.esc_html($this->sn_mis_v2_state_label($state)).'</span><meter min="0" max="'.$total.'" value="'.$count.'">'.$count.'</meter><b>'.number_format_i18n($count).'</b></div>'; }echo '</div></section>';
        $p=$this->sn_mis_preferences();$days=(int)$p['chart_days'];$map=[];foreach($history['trend'] as $r)$map[$r['day']]=$r;$data=[];$now=new DateTimeImmutable('today',new DateTimeZone('Asia/Tehran'));
        for($i=$days-1;$i>=0;$i--) { $day=$now->modify('-'.$i.' days')->format('Y-m-d');$data[]=['day'=>$day,'assignments'=>(int)($map[$day]['assignments']??0),'returns'=>(int)($map[$day]['returns']??0)]; }
        $max=max(1,...array_column($data,'assignments'),...array_column($data,'returns'));$lines=[];
        foreach(['assignments','returns'] as $key) { $pts=[];foreach($data as $i=>$r)$pts[]=round(30+740*$i/max(1,$days-1),2).','.round(190-160*$r[$key]/$max,2);$lines[$key]=implode(' ',$pts); }
        echo '<section class="sn-mis-card"><h3>روند تخصیص و بازگشت؛ '.$days.' روز</h3><p>آبی: تخصیص — نارنجی: بازگشت. بیشترین مقدار روزانه: '.number_format_i18n($max).'</p><svg class="sn-mis-chart" viewBox="0 0 800 220" role="img" aria-label="روند تخصیص و بازگشت؛ جدول داده در ادامه"><path d="M30 20 V190 H770" fill="none" stroke="currentColor"/><polyline points="'.$lines['assignments'].'" fill="none" stroke="#2563eb" stroke-width="3"/><polyline points="'.$lines['returns'].'" fill="none" stroke="#ea580c" stroke-width="3"/></svg><details><summary>جدول داده نمودار</summary><div class="sn-mis-table-scroll"><table><thead><tr><th>روز</th><th>تخصیص</th><th>بازگشت</th></tr></thead><tbody>';
        foreach($data as $r)echo '<tr><td>'.esc_html(SN_Helpers::gregorian_to_jalali_input_value($r['day'])).'</td><td>'.$r['assignments'].'</td><td>'.$r['returns'].'</td></tr>';echo '</tbody></table></div></details></section>';
    }
}
