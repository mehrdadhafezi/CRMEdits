<?php
if (!defined('ABSPATH')) { exit; }
require_once __DIR__.'/class-sn-mis-service.php';
require_once __DIR__.'/class-sn-mis-xlsx.php';
require_once __DIR__.'/trait-sn-mis-extras.php';
require_once __DIR__.'/trait-sn-mis-transport.php';
require_once __DIR__.'/trait-sn-mis-management.php';
require_once __DIR__.'/trait-sn-mis-experience.php';

require_once __DIR__.'/trait-sn-mis-classic.php';
require_once __DIR__.'/trait-sn-mis-user-tools.php';

trait SN_MIS_Portal
{
    use SN_MIS_Classic, SN_MIS_User_Tools;
    use SN_MIS_Extras, SN_MIS_Transport, SN_MIS_Management, SN_MIS_Experience;
    private string $sn_mis_base_url='';
    private ?SN_MIS_Service $sn_mis_service_instance=null;
    private int $sn_mis_service_actor=-1;
    private function sn_mis_v2_service(): SN_MIS_Service { global $wpdb; $actor=get_current_user_id(); if ($this->sn_mis_service_instance===null || $this->sn_mis_service_actor!==$actor) { $this->sn_mis_service_instance=new SN_MIS_Service($wpdb,$actor); $this->sn_mis_service_actor=$actor; } return $this->sn_mis_service_instance; }
    public function register_mis_transport(): void {
        $this->sn_mis_v2_register();
        add_action('init',[$this,'disable_jwt_on_ajax'],1);
        add_action('admin_post_sn_distribution_deliver_pool',[$this,'handle_distribution_deliver_pool']);
        foreach(['create_batch','import_csv','assign_batch','return_assignments','quick_assign_data','prepare_lead_pool','create_distribution_rule','create_distribution_plan','approve_distribution_plan','create_live_leads_from_plan','archive_batch','delete_batch_data','cleanup_deleted_traces'] as $action) { add_action('admin_post_sn_mis_'.$action,[$this,'handle_mis_'.$action]); }
    }
    private function sn_mis_v2_register(): void {
        $this->sn_mis_user_tools_hooks();
        // Every plugin action shares the same connection lock, including seller status and invoice writes.
        add_action('init',[$this,'sn_mis_acquire_request_lock'],0);
        add_action('admin_post_sn_mis_v2',[$this,'handle_mis_v2']);
        add_action('wp_ajax_sn_mis_setup',[$this,'handle_mis_setup']);
        add_action('wp_ajax_sn_mis_view',[$this,'handle_mis_view']);
        add_action('wp_ajax_sn_mis_job',[$this,'handle_mis_job']);
        add_action('admin_post_sn_mis_download',[$this,'handle_mis_download']);
    }
    public function sn_mis_acquire_request_lock(): void { SN_MIS_Request_Lock::acquire(); }
    private function sn_mis_v2_url(string $page='dashboard', array $args=[]): string {
        $base=$this->sn_mis_base_url ?: (get_permalink() ?: wp_get_referer());
        $base=remove_query_arg(['file_status','actor_id','log_action','after_id','batch_id','manager_id','campaign','branch','search','state','consumed','date_type','event_type','from','to','report_mode','batch_search','row_id','case_id','plan_id','section','saved_report','load_saved','sn_mis_batch_id','sn_mis_plan_id','action','_wpnonce','sn_mis_preview','sn_mis_notice','sn_mis_advanced','sn_ui_active_tab','sn_mis_tab','sn_mis_page','paged','sn_mis_offset'], $base ?: home_url('/'));
        return add_query_arg(array_merge(['sn_mis_page'=>$page],$args),$base);
    }
    private function sn_mis_v2_notice(array $report,string $page,string $token=''): void {
        if(!empty($report['error'])) {
            $feedback=SN_MIS_Feedback::error(new RuntimeException((string)$report['error']));
            $report['error']=$feedback['message'];$report['feedback']=$feedback;
            if(SN_MIS_Feedback::is_ajax())wp_send_json_error($feedback,422);
        }
        $notice=bin2hex(random_bytes(12));
        set_transient('sn_mis_notice_'.get_current_user_id().'_'.$notice,$report,10*MINUTE_IN_SECONDS);
        $this->sn_mis_clear_performance_cache();
        $args=['sn_mis_notice'=>$notice];if($token!=='')$args['sn_mis_preview']=$token;
        if(!empty($report['context']) && is_array($report['context']))$args=array_merge($args,array_intersect_key($report['context'],array_flip(['batch_id','manager_id','branch','campaign','from','to','date_type','state','consumed','selection'])));
        if(!empty($report['batch_id']))$args['batch_id']=(int)$report['batch_id'];
        if($page==='upload')$args['section']=str_contains((string)($_POST['operation']??''),'assign')?'assign':'import';
        $url=$this->sn_mis_v2_url($page,$args);
        if(SN_MIS_Feedback::is_ajax())wp_send_json_success(['redirect'=>$url,'report'=>$report]);
        wp_safe_redirect($url);exit;
    }
    private function sn_mis_v2_nonce(string $action): void {
        $nonce=is_scalar($_REQUEST['_wpnonce']??null)?(string)$_REQUEST['_wpnonce']:'';
        if(wp_verify_nonce($nonce,$action))return;
        $feedback=SN_MIS_Feedback::session();
        if(SN_MIS_Feedback::is_ajax())wp_send_json_error($feedback,403);
        wp_die(esc_html($feedback['message'].' '.$feedback['hint']),'اعتبار صفحه تمام شده است',['response'=>403,'back_link'=>true]);
    }
    private function sn_mis_v2_upload_file(): void {
        $file=$_FILES['mis_csv']??[];$batch=absint($_POST['batch_id']??0);
        $filename=sanitize_file_name((string)($file['name']??'فایل بدون نام'));
        $log=new SN_MIS_Import_Log($GLOBALS['wpdb'],get_current_user_id());
        $sha=!empty($file['tmp_name']) && is_uploaded_file($file['tmp_name'])?(string)hash_file('sha256',$file['tmp_name']):'';
        $run=$log->start($batch,$filename,(int)($file['size']??0),$sha);
        try {
            if(empty($file['tmp_name']) || (int)($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)throw new RuntimeException('فایل دریافت نشد یا آپلود کامل نشد. فایل را دوباره انتخاب کنید.');
            if((int)($file['size']??0)>5*1024*1024)throw new RuntimeException('حجم فایل بیش از ۵ مگابایت است. فایل را به بخش‌های کوچک‌تر تقسیم کنید.');
            $parsed=$this->sn_read_tabular_upload($file,['csv'=>'text/csv','xlsx'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
            if(!empty($parsed['errors']))throw new RuntimeException('فایل خوانده نشد: '.implode('، ',array_map('sanitize_text_field',$parsed['errors'])));
            if(count($parsed['rows']??[])>10000 || !count($parsed['rows']??[]))throw new RuntimeException('فایل باید بین ۱ تا ۱۰هزار ردیف اطلاعات داشته باشد.');
            if(count(array_unique($parsed['headers']))!==count($parsed['headers']))throw new RuntimeException('عنوان ستون‌ها تکراری است؛ برای هر ستون نام متفاوت بنویسید.');
            $log->progress($run,'mapping',['seen'=>count($parsed['rows'])]);
            $token=$this->sn_mis_v2_preview('mapping',['parsed'=>$parsed,'batch_id'=>$batch,'filename'=>$filename,'run_id'=>$run]);
            $this->sn_mis_v2_notice(['message'=>'فایل خوانده شد. ستون‌ها را تطبیق دهید؛ هنوز شماره‌ای ثبت نشده است.','import_run_id'=>$run],'upload',$token);
        }catch(Throwable $e) { try{$log->progress($run,'failed',[],SN_MIS_Feedback::error($e)['message']);}catch(Throwable $ignored){error_log('SN MIS: import failure receipt requires review.');}throw $e; }
    }
    private function sn_mis_v2_preview(string $type,array $data): string {
        $token=bin2hex(random_bytes(24));
        if (!set_transient('sn_mis_preview_'.get_current_user_id().'_'.$token,['type'=>$type,'data'=>$data,'actor'=>get_current_user_id()],20*MINUTE_IN_SECONDS)) { throw new RuntimeException('ذخیره پیش‌نمایش ممکن نشد؛ حجم فایل و محدودیت ذخیره‌سازی سرور را بررسی کنید.'); }
        return $token;
    }
    private function sn_mis_v2_get_preview(string $token,string $type=''): array {
        if (!preg_match('/^[a-f0-9]{48}$/',$token)) { throw new RuntimeException('پیش‌نمایش معتبر نیست.'); }
        $p=get_transient('sn_mis_preview_'.get_current_user_id().'_'.$token);
        if (!is_array($p) || (int)$p['actor']!==get_current_user_id() || ($type!=='' && $p['type']!==$type)) { throw new RuntimeException('پیش‌نمایش منقضی شده است؛ دوباره پیش‌نمایش بگیرید.'); }
        return $p;
    }
    private function sn_mis_v2_filters(array $input): array {
        $filters=[];
        foreach(['batch_id','manager_id'] as $key) { $filters[$key]=absint($input[$key]??0); }
        foreach(['campaign','branch','search'] as $key) { $filters[$key]=sanitize_text_field(wp_unslash($input[$key]??'')); }
        $state=strtoupper(sanitize_key($input['state']??''));
        $filters['state']=in_array($state,['RAW','MANAGER_HELD','CONSUMED','INVALID','DUPLICATE','LOCKED','PENDING'],true)?$state:'';
        $filters['consumed']=in_array($input['consumed']??'',['yes','no'],true)?$input['consumed']:'';
        $type=sanitize_key($input['date_type']??((($input['report_mode']??'')==='events' || ($input['sn_mis_page']??'')==='dashboard')?'event':'imported'));
        $filters['date_type']=in_array($type,['imported','assigned','consumed','returned','event'],true)?$type:'imported';
        $filters['selection']=($input['selection']??'')==='newest'?'newest':'oldest';
        $filters['event_type']=in_array($input['event_type']??'',['assigned','returned','consumed','edited','imported'],true)?$input['event_type']:'';
        foreach(['from','to'] as $key) {
            $raw=sanitize_text_field(wp_unslash($input[$key]??''));
            if ($raw==='') { continue; }
            $date=SN_Helpers::jalali_to_gregorian_date($raw);
            if (!$date) { throw new RuntimeException('تاریخ شمسی معتبر وارد کنید.'); }
            $dt=new DateTimeImmutable($date.' 00:00:00',new DateTimeZone('Asia/Tehran'));
            if ($key==='to') { $dt=$dt->modify('+1 day'); }
            // Old row timestamps retain WordPress local semantics; event timestamps are UTC.
            if (($input['report_mode']??'')==='events' || $filters['date_type']!=='imported') { $dt=$dt->setTimezone(new DateTimeZone('UTC')); }
            elseif (function_exists('wp_timezone')) { $dt=$dt->setTimezone(wp_timezone()); }
            $filters[$key]=$dt->format('Y-m-d H:i:s');
        }
        if(($input['report_mode']??'')==='events' && $filters['event_type']!=='' && $filters['date_type']!=='event' && $filters['event_type']!==$filters['date_type'])throw new RuntimeException('نوع رویداد و نوع تاریخ با هم سازگار نیستند؛ برای فیلتر مستقل رویداد، نوع تاریخ را «زمان هر رویداد» بگذارید.');
        if (!empty($filters['from']) && !empty($filters['to']) && $filters['from']>=$filters['to']) { throw new RuntimeException('بازه تاریخ معتبر نیست.'); }
        return $filters;
    }
    private function sn_mis_v2_branches(): array { return $this->sn_sales_branch_options(); }
    private function sn_mis_v2_branch(string $key): array {
        $branches=$this->sn_mis_v2_branches();
        if (!isset($branches[$key])) { throw new RuntimeException('شعبه ثبت‌شده انتخاب کنید؛ مدیرکل می‌تواند شعب را تعریف کند.'); }
        return ['key'=>$key,'label'=>(string)$branches[$key]];
    }
    private function sn_mis_v2_target_active(int $id): bool {
        if (!$id || !$this->sn_distribution_user_is_active($id) || !get_user_by('id',$id)) { return false; }
        global $wpdb;
        if ($this->sn_table_exists_quiet($wpdb->prefix.'sn_hr_profiles')) {
            $row=$wpdb->get_row($wpdb->prepare('SELECT is_active FROM '.$wpdb->prefix.'sn_hr_profiles WHERE user_id=%d ORDER BY id DESC LIMIT 1',$id));
            if ($wpdb->last_error || ($row && (int)$row->is_active!==1)) { return false; }
        }
        return true;
    }
    private function sn_mis_v2_import_records(array $parsed,array $mapping=[]): array {
        if (!empty($parsed['errors'])) { throw new RuntimeException(implode('، ',array_map('sanitize_text_field',$parsed['errors']))); }
        $headers=(array)$parsed['headers']; $records=[]; if (count($headers)>64) { throw new RuntimeException('فایل MIS حداکثر می‌تواند ۶۴ ستون داشته باشد.'); }
        $allowed=['phone','phone_2','file_code','source_code','source_datetime_raw','first_name','last_name','customer_name','city','province','notes','ignore'];
        $map=[]; $used=[];
        foreach($headers as $index=>$header) {
            $normalized=$this->sn_norm_header_key((string)$header);
            $aliases=['phone'=>'phone','mobile'=>'phone','phone_2'=>'phone_2','first_name'=>'first_name','last_name'=>'last_name','display_name'=>'first_name','name'=>'customer_name','full_name'=>'customer_name','city'=>'city','province'=>'province','notes'=>'notes','source_code'=>'source_code','code'=>'source_code','source_datetime'=>'source_datetime_raw','source_datetime_raw'=>'source_datetime_raw'];
            $special=['کد فایل'=>'file_code','کد پرونده'=>'file_code','کد'=>'source_code','شناسه'=>'source_code','کد منبع'=>'source_code','تاریخ و ساعت'=>'source_datetime_raw','تاریخ'=>'source_datetime_raw'];
            $target=$mapping[$index]??($special[$header]??$aliases[$normalized]??'ignore');
            if (!in_array($target,$allowed,true)) { throw new RuntimeException('نگاشت ستون معتبر نیست.'); }
            if ($target!=='ignore' && isset($used[$target])) { throw new RuntimeException('دو ستون به یک فیلد نگاشت شده‌اند.'); }
            $used[$target]=true; $map[$header]=$target;
        }
        if (!isset($used['phone'])) { throw new RuntimeException('ستون شماره تماس را مشخص کنید.'); }
        foreach((array)$parsed['rows'] as $raw) {
            $values=[];
            foreach($map as $header=>$target) { if ($target!=='ignore') { $values[$target]=sanitize_text_field((string)($raw[$header]??'')); } }
            $info=$this->sn_mis_pick_phone(['phone'=>$values['phone']??'','phone_2'=>$values['phone_2']??''],['primary'=>'phone','primary_label'=>'شماره تماس','secondary'=>'phone_2','secondary_label'=>'شماره دوم']);
            $first=$values['first_name']??''; $last=$values['last_name']??'';
            $name=trim((string)($values['customer_name']??'')); if ($name==='') { $name=trim($first.' '.$last); }
            $records[]=['customer_phone'=>$info['raw'],'normalized_phone'=>$info['normalized'],'customer_name'=>$name,'row_status'=>$info['reason']===''?'valid':'invalid','duplicate_reason'=>$info['reason'],'file_code'=>$values['file_code']??'','source_code'=>$values['source_code']??'','source_datetime_raw'=>$values['source_datetime_raw']??'','first_name'=>$first,'last_name'=>$last,'city'=>$values['city']??'','province'=>$values['province']??'','source_meta'=>['source_datetime_basis'=>'jalali_input','code_uniqueness'=>'file_case_reference','raw_values'=>$values,'original_columns'=>array_map('sanitize_text_field',$raw)]];
        }
        return $records;
    }
    public function handle_mis_v2(): void {
        if (!$this->sn_can_access_mis_panel()) { wp_die('دسترسی غیرمجاز','MIS',['response'=>403]); }
        $this->sn_mis_v2_nonce('sn_mis_v2');
        $this->sn_mis_acquire_request_lock();
        $operation=sanitize_key($_POST['operation']??$_GET['operation']??'');
        if (!in_array($operation,['export','template'],true) && (strtoupper($_SERVER['REQUEST_METHOD']??'GET')!=='POST')) { wp_die('روش درخواست معتبر نیست.','MIS',['response'=>405]); }
        $service=$this->sn_mis_v2_service(); $page='dashboard';$import_run=0;
        try {
            if ($operation==='install') {
                if (!current_user_can('manage_options')) { throw new RuntimeException('فقط مدیرکل می‌تواند ساختار افزایشی را راه‌اندازی کند.'); }
                $state=(new SN_MIS_Setup($GLOBALS['wpdb']))->step();
                $this->sn_mis_v2_notice(['message'=>$state['message']],'tools');
            }
            if (!$service->ready()) { throw new RuntimeException('راه‌اندازی افزایشی MIS لازم است.'); }
            if ($this->sn_mis_extra_operation($operation)) { return; }
            if ($operation==='export') { $this->sn_mis_v2_export(); return; }
            if ($operation==='template') { nocache_headers();header('Content-Type: text/csv; charset=UTF-8');header('Content-Disposition: attachment; filename="mis-import-template.csv"');echo "\xEF\xBB\xBF";$out=fopen('php://output','w');fputcsv($out,['کد فایل','شماره تماس','تاریخ و ساعت','نام','نام خانوادگی']);fclose($out);exit; }
            if($operation==='archive_batch') {
                if(!current_user_can('manage_options') || ($_POST['confirm']??'')!=='ARCHIVE')throw new RuntimeException('تأیید مدیرکل برای بایگانی لازم است.');
                $reason=sanitize_textarea_field(wp_unslash($_POST['reason']??''));if($reason==='')throw new RuntimeException('دلیل بایگانی لازم است.');
                $report=$service->safe_archive(absint($_POST['batch_id']??0),$reason);$report['message']='پرونده بایگانی شد؛ شماره‌ها و تاریخچه حفظ شدند.';$this->sn_mis_v2_notice($report,'cases');
            }
            if ($operation==='branches') {
                if (!current_user_can('manage_options')) { throw new RuntimeException('فقط مدیرکل می‌تواند شعب را تعریف کند.'); }
                $this->sn_add_sales_branch(is_string($_POST['branch_label'] ?? null) ? wp_unslash($_POST['branch_label']) : '');
                $this->sn_mis_v2_notice(['message'=>'شعبه ثبت شد.'],'branches');
            }
            if ($operation==='save_view') {
                $this->sn_mis_v2_filters($_POST); $saved=[]; foreach(['batch_id','manager_id','campaign','branch','search','state','consumed','date_type','event_type','from','to','report_mode'] as $key) { if (isset($_POST[$key]) && is_scalar($_POST[$key])) { $saved[$key]=sanitize_text_field(wp_unslash($_POST[$key])); } } update_user_meta(get_current_user_id(),'sn_mis_saved_filters_v2',$saved);
                $this->sn_mis_v2_notice(['message'=>'فیلترهای گزارش برای شما ذخیره شد.'],'reports');
            }
            if ($operation==='upload') { $page='upload';$this->sn_mis_v2_upload_file(); }
            if ($operation==='map_import') {
                $page='upload'; $old=sanitize_text_field($_POST['preview_token']??''); $p=$this->sn_mis_v2_get_preview($old,'mapping');$import_run=(int)($p['data']['run_id']??0);
                $records=$this->sn_mis_v2_import_records($p['data']['parsed'],(array)($_POST['column_map']??[]));
                $report=$service->import_rows((int)$p['data']['batch_id'],$records,$p['data']['filename'],false);
                $token=$this->sn_mis_v2_preview('import',['records'=>$records,'batch_id'=>$p['data']['batch_id'],'filename'=>$p['data']['filename'],'run_id'=>$p['data']['run_id']??0,'report'=>$report]);
                if(!empty($p['data']['run_id']))(new SN_MIS_Import_Log($GLOBALS['wpdb'],get_current_user_id()))->progress((int)$p['data']['run_id'],'preview',$report);
                delete_transient('sn_mis_preview_'.get_current_user_id().'_'.$old);
                $report['message']='اعتبارسنجی فایل انجام شد. نتیجه را بررسی کنید و سپس ثبت را تأیید کنید.';$this->sn_mis_v2_notice($report,'upload',$token);
            }
            if (in_array($operation,['preview_assign','preview_return'],true)) {
                $returning=$operation==='preview_return'; $page=$returning?'return':'upload';
                $filters=$this->sn_mis_v2_filters($_POST); $limit=max(1,min(1000,absint($_POST['limit']??100)));
                $reason=sanitize_textarea_field(wp_unslash($_POST['reason']??'')); if ($reason==='') { throw new RuntimeException('دلیل عملیات را وارد کنید.'); }
                $selected=array_slice(array_values(array_filter(array_unique(array_map('absint',(array)($_POST['row_ids']??[]))))),0,1000);
                $target=absint($_POST['target_manager_id']??0); $branch=[]; $team_branch=[];
                if (!$returning) {
                    if (!$target || $this->sn_hr_position_for_user($target)!=='sales_manager' || !$this->sn_mis_v2_target_active($target)) { throw new RuntimeException('مدیر فروش فعال انتخاب کنید.'); }
                    $team_branch = $this->sn_sales_manager_branches()[$target] ?? [];
                    $branch = $team_branch ?: $this->sn_mis_v2_branch(sanitize_key($_POST['assignment_branch'] ?? ''));
                    $filters['state']='RAW';
                } elseif (!$filters['manager_id'] && !$filters['batch_id'] && !$selected) { throw new RuntimeException('مدیر، پرونده یا شماره‌ها را انتخاب کنید.'); }
                $requested=$selected?count($selected):$limit;if($selected)$filters['__row_ids']=$selected;
                $result=$service->query($filters,$limit);$ids=array_column($result['rows'],'id');
                if(!$ids)throw new RuntimeException('شماره‌ای مطابق این انتخاب پیدا نشد؛ فیلترها و آماده‌بودن آمار را بررسی کنید.');
                $eligible=0;$reasons=[];foreach($result['rows'] as $candidate){if($candidate['logical_state']===($returning?'MANAGER_HELD':'RAW'))$eligible++;else{$why=$candidate['block_reason']?:'وضعیت این شماره اجازه عملیات را نمی‌دهد';$reasons[$why]=($reasons[$why]??0)+1;}}
                $preview_report=['requested'=>$requested,'selected'=>count($ids),'eligible'=>$eligible,'blocked'=>count($ids)-$eligible,'total'=>$result['total'],'reasons'=>$reasons,'preview'=>$result['rows']];
                $context=[];foreach(['batch_id','manager_id','branch','campaign','from','to','date_type','state','consumed','selection'] as $key)if(isset($_POST[$key]) && is_scalar($_POST[$key]))$context[$key]=sanitize_text_field(wp_unslash($_POST[$key]));
                $token=$this->sn_mis_v2_preview($returning?'return':'assign',['batch_id'=>(int)$filters['batch_id'],'context'=>$context,'ids'=>$ids,'manager'=>$target,'branch'=>$branch,'team_branch'=>(bool)$team_branch,'reason'=>$reason,'report'=>$preview_report]);
                $this->sn_mis_v2_notice(['message'=>'پیش‌نمایش آماده است؛ وضعیت هر شماره هنگام ثبت دوباره بررسی می‌شود.'],$page,$token);
            }
            if (in_array($operation,['apply_assign','apply_return','apply_import'],true)) {
                $type=substr($operation,6); $page=$type==='return'?'return':'upload';
                if (($_POST['confirm']??'')!=='YES') { throw new RuntimeException('تأیید نهایی الزامی است.'); }
                $token=sanitize_text_field($_POST['preview_token']??''); $p=$this->sn_mis_v2_get_preview($token,$type); $data=$p['data'];$import_run=$type==='import'?(int)($data['run_id']??0):0;
                if ($type==='assign' && ($this->sn_hr_position_for_user((int)$data['manager'])!==($data['position']??'sales_manager') || !$this->sn_mis_v2_target_active((int)$data['manager']))) { throw new RuntimeException('مدیر انتخاب‌شده دیگر فعال نیست.'); }
                if ($type === 'assign') {
                    $target = (int)$data['manager'];
                    $team_branch = ($data['position'] ?? 'sales_manager') === 'sales_manager'
                        ? ($this->sn_sales_manager_branches()[$target] ?? [])
                        : ($this->sn_sales_branch_map([$target])[$target] ?? []);
                    $preview_key = $data['branch']['key'] ?? 'unknown';
                    if (($team_branch && $team_branch['key'] !== $preview_key) || (!empty($data['team_branch']) && !$team_branch)) {
                        throw new RuntimeException('شعبه گیرنده پس از پیش‌نمایش تغییر کرده است. پیش‌نمایش تازه بگیرید.');
                    }
                    $data['branch'] = $team_branch ?: $this->sn_mis_v2_branch($preview_key === 'unknown'
                        ? sanitize_key($_POST['assignment_branch'] ?? '')
                        : $preview_key);
                }
                $report=$type==='assign' ? $service->assign($data['ids'],(int)$data['manager'],$data['branch'],$data['reason'],$data['position']??'sales_manager',$data['create_item']??true) : ($type==='return' ? $service->return_rows($data['ids'],$data['reason']) : $service->import_rows((int)$data['batch_id'],$data['records'],$data['filename'],true,(int)($data['run_id']??0)));
                $report['batch_id']=(int)($data['batch_id']??0);$report['context']=$data['context']??[];
                delete_transient('sn_mis_preview_'.get_current_user_id().'_'.$token);
                $this->sn_mis_v2_notice($report,$page);
            }
            if ($operation==='edit') {
                $page='return'; $phone=$this->sn_normalize_iran_mobile(sanitize_text_field(wp_unslash($_POST['normalized_phone']??'')));
                if ($phone['reason']!=='') { throw new RuntimeException('شماره تماس معتبر نیست.'); }
                $changes=['normalized_phone'=>$phone['normalized']];
                foreach(['source_code','first_name','last_name','source_datetime_raw'] as $key) { $changes[$key]=sanitize_text_field(wp_unslash($_POST[$key]??'')); }
                $report=$service->edit(absint($_POST['row_id']??0),$changes,sanitize_textarea_field(wp_unslash($_POST['reason']??'')));
                $this->sn_mis_v2_notice($report,'return');
            }
            throw new RuntimeException('عملیات معتبر نیست.');
        } catch (Throwable $error) { if($import_run>0){try{(new SN_MIS_Import_Log($GLOBALS['wpdb'],get_current_user_id()))->progress($import_run,'failed',[],SN_MIS_Feedback::error($error)['message']);}catch(Throwable $ignored){error_log('SN MIS: import error receipt requires review.');}}$this->sn_mis_v2_notice(['error'=>$error->getMessage()],$page); }
    }
    private function sn_mis_v2_export(): void {
        $service=$this->sn_mis_v2_service(); $filters=$this->sn_mis_v2_filters($_GET); $history=($_GET['report_mode']??'')==='events'; $filters['__skip_count']=true;
        $count=$service->query(array_diff_key($filters,['__skip_count'=>true]),1,0,$history)['total'];
        if($count>1000) { $id=$this->sn_mis_jobs()->start('export',$filters,$history);$this->sn_mis_v2_notice(['message'=>'خروجی حجیم به پردازش مرحله‌ای #'.$id.' منتقل شد.'],'tools'); }
        $writer=new SN_MIS_XLSX(['شناسه رکورد','کد منبع','شماره تماس','تاریخ و ساعت منبع','نام','نام خانوادگی','پرونده','کمپین','شعبه هنگام تخصیص','مدیر','زمان رویداد','وضعیت فعلی','وضعیت مصرف','دلیل محدودیت','زمان بازگشت','دلیل بازگشت','نوع رویداد','دلیل رویداد']);
        $path=$service->read_snapshot(function() use ($service,$filters,$history,$writer) {
        $after=0; $max=(int)$service->value('SELECT COALESCE(MAX(id),0) FROM '.($history?$GLOBALS['wpdb']->prefix.'sn_mis_events':$GLOBALS['wpdb']->prefix.'sn_mis_data_rows'));
        do {
            $part=$service->query($filters,500,0,$history,$after,$max); $rows=$part['rows'];
            foreach($rows as $row) {
                $writer->add($this->sn_mis_export_values($row,$history));
                $after=(int)$row['cursor_id'];
            }
        } while(count($rows)===500);
        return $writer->finish();
        });
        try { nocache_headers(); header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'); header('Content-Disposition: attachment; filename="mis-report-'.gmdate('Ymd-His').'.xlsx"'); header('Content-Length: '.filesize($path)); readfile($path); }
        finally { unlink($path); }
        exit;
    }
    private function sn_mis_v2_time(?string $value,bool $utc=false): string {
        if (!$value || $value==='0000-00-00 00:00:00') { return '—'; }
        try {
            $date=new DateTimeImmutable($value,$utc?new DateTimeZone('UTC'):wp_timezone());
            $local=$date->setTimezone(new DateTimeZone('Asia/Tehran'))->format('Y-m-d H:i:s');
            // Pass an explicit offset so the legacy helper cannot re-interpret UTC as site time.
            return SN_Helpers::gregorian_to_jalali_date($local.'+03:30');
        } catch(Throwable $e) { return '—'; }
    }
    private function sn_mis_v2_state_label(string $state): string {
        return ['RAW'=>'خام و آزاد','MANAGER_HELD'=>'در اختیار مدیر؛ مصرف‌نشده','CONSUMED'=>'مصرف‌شده','LOCKED'=>'قفل‌شده؛ نیازمند بررسی','INVALID'=>'نامعتبر','DUPLICATE'=>'تکراری','PENDING'=>'در انتظار بررسی آماری'][$state]??$state;
    }
    private function sn_mis_nonce_field(string $action): void {
        wp_nonce_field($action,'_wpnonce',false);
        $base=$this->sn_mis_base_url ?: (get_permalink() ?: home_url('/'));
        echo '<input type="hidden" name="_wp_http_referer" value="'.esc_attr($base).'">';
    }
    private function sn_mis_v2_form_start(string $operation): void {
        echo '<form method="post" '.($operation==='upload'?'enctype="multipart/form-data" ':'').'action="'.esc_url(admin_url('admin-post.php')).'" class="sn-mis-form">';
        echo '<input type="hidden" name="action" value="sn_mis_v2"><input type="hidden" name="operation" value="'.esc_attr($operation).'">';
        $this->sn_mis_nonce_field('sn_mis_v2');
    }
    private function sn_mis_v2_filter_form(string $page,array $input): void {
        $service=$this->sn_mis_v2_service(); global $wpdb;
        $batches=$this->sn_mis_batch_options($input);
        echo '<details class="sn-mis-filter-disclosure" '.(!empty($input['search'])||!empty($input['batch_id'])||!empty($input['manager_id'])||!empty($input['state'])||!empty($input['from'])?'open':'').'><summary>فیلتر و جست‌وجوی پیشرفته</summary><form method="get" class="sn-mis-filters"><input type="hidden" name="sn_mis_page" value="'.esc_attr($page).'">';
        if (isset($_GET['page_id'])) { echo '<input type="hidden" name="page_id" value="'.absint($_GET['page_id']).'">'; }
        $this->sn_mis_v2_filter_fields($input,$batches);
        if ($page==='reports') {
            echo '<label>نوع گزارش<select name="report_mode"><option value="current">موجودی فعلی</option><option value="events" '.selected($input['report_mode']??'','events',false).'>رویدادهای تاریخی</option></select></label>';
            echo '<label>نوع رویداد (گزارش تاریخی)<select name="event_type"><option value="">همه رویدادها</option>';foreach(['imported','assigned','consumed','returned','edited'] as $event)echo '<option value="'.$event.'" '.selected($input['event_type']??'',$event,false).'>'.esc_html($this->sn_mis_v2_event_label($event)).'</option>';echo '</select></label>';
        }
        if($page==='reports')echo '<p class="sn-mis-muted">در گزارش تاریخی، نوع تاریخ «تخصیص» فقط رویدادهای تخصیص همان بازه را نشان می‌دهد. برای همه رویدادها، «زمان هر رویداد» را انتخاب کنید.</p>';
        echo '<button class="sn-btn sn-btn-primary">اعمال فیلتر</button><a class="sn-btn sn-btn-secondary" href="'.esc_url($this->sn_mis_v2_url($page)).'">پاک کردن</a></form></details>';
    }
    private function sn_mis_v2_filter_managers(): array {
        global $wpdb;
        $ids=array_map(static fn($r)=>(int)$r->user_id,$this->sn_mis_sales_managers());
        $cache='sn_mis_managers_'.substr(hash('sha256',$wpdb->prefix.get_option('sn_mis_read_epoch','0')),0,32);$historic=get_transient($cache);
        if(!is_array($historic)) { $historic=$this->sn_mis_v2_service()->rows("SELECT DISTINCT assigned_manager_user_id uid FROM {$wpdb->prefix}sn_mis_data_rows WHERE assigned_manager_user_id>0 UNION SELECT DISTINCT new_manager_id uid FROM {$wpdb->prefix}sn_mis_events WHERE new_manager_id>0 UNION SELECT DISTINCT old_manager_id uid FROM {$wpdb->prefix}sn_mis_events WHERE old_manager_id>0");set_transient($cache,$historic,300); }
        foreach($historic as $row) { $ids[]=(int)$row['uid']; }
        if((new SN_MIS_Read_Model($wpdb))->ready()) { foreach($this->sn_mis_v2_service()->rows('SELECT DISTINCT manager_id uid FROM '.$wpdb->prefix.'sn_mis_row_index WHERE manager_id>0') as $row)$ids[]=(int)$row['uid']; }
        return array_map(static fn($id)=>(object)['user_id'=>$id],array_values(array_unique($ids)));
    }
    private function sn_mis_v2_filter_fields(array $input,array $batches): void {
        echo '<label>جست‌وجوی پرونده<input type="search" name="batch_search" value="'.esc_attr($input['batch_search']??'').'" placeholder="کد یا عنوان؛ سپس اعمال فیلتر"></label><small>حداکثر ۱۰۰ پرونده؛ برای پرونده قدیمی جست‌وجو کنید.</small>';
        echo '<label>پرونده<select name="batch_id"><option value="">همه پرونده‌ها</option>';
        foreach($batches as $b) { echo '<option value="'.(int)$b['id'].'" '.selected(absint($input['batch_id']??0),(int)$b['id'],false).'>'.esc_html($b['batch_code'].' — '.$b['title']).'</option>'; } echo '</select></label>';
        echo '<label>مدیر فروش<select name="manager_id"><option value="">همه مدیران</option>';
        foreach($this->sn_mis_v2_filter_managers() as $manager) { echo '<option value="'.(int)$manager->user_id.'" '.selected(absint($input['manager_id']??0),(int)$manager->user_id,false).'>'.esc_html($this->sn_user_label((int)$manager->user_id)).'</option>'; } echo '</select></label>';
        echo '<label>شعبه زمان تخصیص<select name="branch"><option value="">همه شعب</option><option value="unknown" '.selected($input['branch']??'','unknown',false).'>نامشخص</option>';
        foreach($this->sn_mis_v2_branches() as $key=>$label) { echo '<option value="'.esc_attr($key).'" '.selected($input['branch']??'',$key,false).'>'.esc_html($label).'</option>'; } echo '</select></label>';
        foreach(['campaign'=>'کد کمپین','search'=>'جست‌وجوی کد، شماره یا نام','from'=>'از تاریخ شمسی','to'=>'تا تاریخ شمسی'] as $key=>$label) { echo '<label>'.esc_html($label).'<input type="text" name="'.esc_attr($key).'" value="'.esc_attr($input[$key]??'').'" '.(in_array($key,['from','to'],true)?'placeholder="۱۴۰۵/۰۸/۰۱" inputmode="numeric"':'').'></label>'; }
        echo '<label>نوع تاریخ<select name="date_type">';
        foreach(['event'=>'زمان هر رویداد (گزارش تاریخی)','imported'=>'ورود','assigned'=>'تخصیص','consumed'=>'مصرف','returned'=>'بازگشت'] as $key=>$label) { echo '<option value="'.$key.'" '.selected($input['date_type']??((($input['report_mode']??'')==='events' || ($input['sn_mis_page']??'')==='dashboard')?'event':'imported'),$key,false).'>'.$label.'</option>'; } echo '</select></label>';
        echo '<label>وضعیت فعلی<select name="state"><option value="">همه وضعیت‌ها</option>';
        foreach(['RAW','MANAGER_HELD','CONSUMED','LOCKED','INVALID','DUPLICATE'] as $state) { echo '<option value="'.$state.'" '.selected($input['state']??'',$state,false).'>'.esc_html($this->sn_mis_v2_state_label($state)).'</option>'; } echo '</select></label>';
        echo '<label>وضعیت مصرف<select name="consumed"><option value="">همه</option><option value="yes" '.selected($input['consumed']??'','yes',false).'>مصرف‌شده</option><option value="no" '.selected($input['consumed']??'','no',false).'>بدون شاهد مصرف</option></select></label>';
    }
    private function sn_mis_v2_table(array $rows,bool $history=false): void {
        echo '<div class="sn-mis-table-scroll"><table class="sn-mis-records"><thead><tr><th>شناسه / کد</th><th>شماره / نام</th><th>پرونده / کمپین</th><th>مدیر / شعبه</th><th>وضعیت</th><th>تاریخ و دلیل</th><th>جزئیات</th></tr></thead><tbody>';
        $current_branches = $history ? [] : $this->sn_sales_manager_branches();
        foreach($rows as $row) {
            $manager=(int)($history?($row['new_manager_id']?:$row['old_manager_id']):($row['resolved_manager_id']??$row['assigned_manager_user_id']));
            $branch_label = (string)($row['branch_label'] ?: 'نامشخص');
            if (isset($current_branches[$manager]) && $current_branches[$manager]['key'] !== ($row['branch_key'] ?? 'unknown')) {
                $branch_label .= ' · شعبه فعلی: ' . $current_branches[$manager]['label'];
            }
            echo '<tr><td>#'.(int)$row['id'].'<small>'.esc_html($row['source_code']??'').'</small></td><td><bdi>'.esc_html($row['normalized_phone']).'</bdi><small>'.esc_html($row['customer_name']).'</small></td><td>'.esc_html($row['batch_code']).'<small>'.esc_html($row['campaign_code']).'</small></td><td>'.esc_html($manager?$this->sn_user_label($manager):'—').'<small>'.esc_html($branch_label).'</small></td><td><span class="sn-mis-state sn-mis-state-'.esc_attr(strtolower($row['logical_state'])).'">'.esc_html($this->sn_mis_v2_state_label($row['logical_state'])).'</span><small>'.esc_html($row['block_reason']).'</small></td><td>'.esc_html($this->sn_mis_v2_time($row['event_time']??'',true)).'<small>'.esc_html($history?($this->sn_mis_v2_event_label($row['event_type']).' / '.$row['event_reason']):$this->sn_mis_source_time((string)($row['source_datetime_raw']??''))).'</small></td><td><a href="'.esc_url($this->sn_mis_v2_url('return',['row_id'=>(int)$row['id']])).'">مشاهده و ویرایش</a></td></tr>';
        }
        if (!$rows) { echo '<tr><td colspan="7">رکوردی مطابق فیلتر پیدا نشد.</td></tr>'; }
        echo '</tbody></table></div>';
    }
    private function sn_mis_v2_render_preview(string $token): void {
        $p=$this->sn_mis_v2_get_preview($token);$data=$p['data'];$type=$p['type'];$assigning=$type==='assign';$returning=$type==='return';
        $this->sn_mis_stepper($type==='mapping'?2:3,($assigning||$returning)?['انتخاب شماره‌ها','بررسی وضعیت','تأیید و ثبت']:['انتخاب پرونده و فایل','تطبیق ستون‌ها','بازبینی و ثبت']);
        echo '<section class="sn-mis-card sn-mis-preview"><div class="sn-mis-section-heading"><div><span class="sn-mis-eyebrow">'.($type==='mapping'?'فایل خوانده شده؛ بدون ثبت شماره':'آخرین بررسی پیش از ثبت').'</span><h3>'.($type==='mapping'?'ستون‌ها را به فیلد درست متصل کنید':($assigning?'بازبینی تخصیص':($returning?'بازبینی بازگشت':'بازبینی ورود فایل'))).'</h3></div><span class="sn-mis-badge">اعتبار پیش‌نمایش: ۲۰ دقیقه</span></div>';
        if($type==='mapping') {
            echo '<p>شماره تماس الزامی است. هر فیلد را فقط یک بار انتخاب کنید؛ ستون‌های اضافی را نادیده بگیرید.</p><p class="sn-mis-file-name">فایل: <strong>'.esc_html($data['filename']).'</strong> · '.number_format_i18n(count($data['parsed']['rows'])).' ردیف</p>';
            $this->sn_mis_v2_form_start('map_import');echo '<input type="hidden" name="preview_token" value="'.esc_attr($token).'">';
            foreach($data['parsed']['headers'] as $i=>$header) {
                $normalized=$this->sn_norm_header_key($header);$defaults=['phone'=>'phone','mobile'=>'phone','phone_2'=>'phone_2','name'=>'customer_name','full_name'=>'customer_name','display_name'=>'first_name','first_name'=>'first_name','last_name'=>'last_name','source_code'=>'source_code','code'=>'source_code','source_datetime'=>'source_datetime_raw','city'=>'city','province'=>'province','notes'=>'notes'];
                $special=['کد'=>'file_code','کد فایل'=>'file_code','کد پرونده'=>'file_code','کد منبع'=>'source_code','تاریخ و ساعت'=>'source_datetime_raw','تاریخ'=>'source_datetime_raw'];$chosen=$special[$header]??$defaults[$normalized]??'ignore';
                echo '<label class="sn-mis-map-field"><strong>'.esc_html($header).'</strong><small>نمونه: '.esc_html(mb_substr((string)($data['parsed']['rows'][0][$header]??'—'),0,80)).'</small><select name="column_map['.(int)$i.']" aria-label="تطبیق ستون '.esc_attr($header).'">';
                foreach(['ignore'=>'نادیده گرفتن','phone'=>'شماره تماس','phone_2'=>'شماره دوم','file_code'=>'کد فایل / پرونده','source_code'=>'کد مرجع قدیمی (اختیاری)','source_datetime_raw'=>'تاریخ و ساعت شمسی','first_name'=>'نام','last_name'=>'نام خانوادگی','customer_name'=>'نام کامل','city'=>'شهر','province'=>'استان','notes'=>'توضیحات'] as $key=>$label)echo '<option value="'.$key.'" '.selected($chosen,$key,false).'>'.$label.'</option>';echo '</select></label>';
            }echo '<button class="sn-btn sn-btn-primary">اعتبارسنجی و دیدن نتیجه</button></form>';
        } else {
            $report=$data['report']??[];
            if($assigning)echo '<div class="sn-mis-recipient"><span>گیرنده</span><strong>'.esc_html($this->sn_user_label((int)$data['manager'])).'</strong><span>شعبه: '.esc_html($data['branch']['label']).'</span></div>';
            echo '<div class="sn-mis-preview-counts">';foreach(['requested'=>'درخواست‌شده','selected'=>'بررسی‌شده','eligible'=>$returning?'قابل بازگشت':'قابل تحویل','blocked'=>'غیرقابل عملیات','valid'=>'معتبر','invalid'=>'نامعتبر','duplicate'=>'تکراری','unchanged'=>'قبلاً ثبت‌شده'] as $key=>$label)if(isset($report[$key]))echo '<article class="'.(in_array($key,['blocked','invalid'],true)?'is-warning':'').'"><strong>'.number_format_i18n((int)$report[$key]).'</strong><span>'.$label.'</span></article>';echo '</div>';
            if(!empty($report['reasons'])){echo '<div class="sn-mis-inline-warning"><strong>این شماره‌ها تغییر نمی‌کنند:</strong><ul>';foreach($report['reasons'] as $reason=>$count)echo '<li>'.esc_html($reason).' — '.number_format_i18n((int)$count).'</li>';echo '</ul></div>';}
            if($type==='import' && !empty($report['invalid']))echo '<p class="sn-mis-inline-warning">ردیف‌های نامعتبر با دلیل خطا در پرونده حفظ می‌شوند و قابل تخصیص نیستند. پس از ثبت می‌توانید آنها را در «بازگشت و ویرایش» اصلاح کنید.</p>';
            $preview=array_slice($report['preview']??[],0,25);if($preview)echo '<p class="sn-mis-muted">نمونه حداکثر ۲۵ ردیف از '.number_format_i18n((int)($report['selected']??$report['seen']??count($preview))).' ردیف؛ ثبت نهایی همه ردیف‌های انتخاب‌شده را دوباره بررسی می‌کند.</p>';
            if($preview && isset($preview[0]['logical_state']))$this->sn_mis_v2_table($preview);
            elseif($preview){echo '<div class="sn-mis-table-scroll"><table><thead><tr><th>ردیف فایل</th><th>نام</th><th>شماره</th><th>نتیجه / راه اصلاح</th></tr></thead><tbody>';foreach($preview as $row)echo '<tr><td>'.(int)$row['number'].'</td><td>'.esc_html($row['name']).'</td><td><bdi>'.esc_html($row['phone']).'</bdi></td><td>'.esc_html($this->sn_mis_row_status_label($row['status'])).'<small>'.esc_html(($row['detail']??'')?:$this->sn_mis_v2_import_reason($row['reason'])).'</small></td></tr>';echo '</tbody></table></div>';}
            if(isset($data['reason']))echo '<p><strong>دلیل ثبت‌شونده:</strong> '.esc_html($data['reason']).'</p>';
            $this->sn_mis_v2_form_start('apply_'.$type);
            if($assigning && ($data['branch']['key']??'unknown')==='unknown'){echo '<label>شعبه همین تخصیص<select name="assignment_branch" aria-label="شعبه همین تخصیص" required><option value="">انتخاب شعبه</option>';foreach($this->sn_mis_v2_branches() as $key=>$label)echo '<option value="'.esc_attr($key).'">'.esc_html($label).'</option>';echo '</select></label>';}
            echo '<input type="hidden" name="preview_token" value="'.esc_attr($token).'"><label class="sn-mis-confirm"><input type="checkbox" name="confirm" value="YES" required>نتیجه و دلیل را بررسی کردم و '.($assigning?'تخصیص':($returning?'بازگشت فقط شماره‌های مجاز':'ثبت فایل')).' را تأیید می‌کنم.</label><button class="sn-btn sn-btn-primary" '.(($assigning||$returning) && isset($report['eligible']) && (int)$report['eligible']===0?'disabled':'').'>'.($assigning?'تأیید و تخصیص شماره‌ها':($returning?'تأیید و بازگشت شماره‌های مجاز':'تأیید و ثبت فایل')).'</button></form>';
        }
        echo '<a class="sn-mis-back-link" href="'.esc_url($this->sn_mis_v2_url($returning?'return':'upload',array_merge($data['context']??[],['batch_id'=>(int)($data['batch_id']??0),'section'=>$assigning?'assign':'import']))).'">بازگشت به انتخاب و اصلاح اطلاعات</a></section>';
    }
    private function sn_mis_v2_event_label(string $event): string {
        return ['imported'=>'ورود داده','assigned'=>'تخصیص به مدیر','returned'=>'بازگشت به مخزن','consumed'=>'مصرف داده','edited'=>'ویرایش','legacy_delivered'=>'تحویل از مسیر قدیمی'][$event]??$event;
    }
    private function sn_mis_v2_import_reason(string $reason): string {
        return ['invalid_source_datetime'=>'تاریخ و ساعت باید شمسی و معتبر باشد؛ نمونه: ۱۴۰۵/۰۸/۱۰ ۱۴:۳۰.','duplicate_in_file'=>'تکرار در همین فایل','duplicate_in_same_batch'=>'قبلاً در همین پرونده ثبت شده','duplicate_in_mis'=>'قبلاً در مخزن MIS ثبت شده','duplicate_in_leads'=>'دارای لید قبلی'][$reason]??$this->sn_mis_invalid_reason_label($reason);
    }
    private function sn_mis_v2_report_label(string $key): string {
        return ['batch_id'=>'شناسه پرونده','batch_code'=>'کد پرونده','assigned'=>'تخصیص موفق','returned'=>'بازگشت موفق','blocked'=>'مسدود','edited'=>'ویرایش موفق','requested'=>'درخواست','selected'=>'انتخاب‌شده','total'=>'کل نتایج','seen'=>'ردیف فایل','imported'=>'ثبت‌شده','valid'=>'معتبر','duplicate'=>'تکراری','invalid'=>'نامعتبر','unchanged'=>'قبلاً ثبت‌شده؛ حفظ شد','operation_id'=>'شناسه عملیات','archived'=>'بایگانی‌شده','deleted'=>'حذف‌شده'][$key]??$key;
    }
    public function render_mis_workspace(): string {
        if (!is_user_logged_in()) { return $this->render_unified_panel_login(); }
        if (!$this->sn_can_access_mis_panel()) { return SN_Helpers::panel_access_denied(); }
        if(!empty($_GET['sn_mis_advanced'])) {
            $old=sanitize_key($_GET['sn_ui_active_tab']??'');
            $_GET['sn_mis_page']=['mis-preview'=>'reports','mis-files'=>'cases','mis-cases'=>'cases','mis-plans'=>'plans','mis-lead-create'=>'plans','mis-rules'=>'rules','mis-diagnostics'=>'health','mis-logs'=>'logs','mis-pool'=>'plans','mis-quick'=>'tools'][$old]??'cases';
            if(!empty($_GET['sn_mis_batch_id']))$_GET['batch_id']=absint($_GET['sn_mis_batch_id']);
            unset($_GET['sn_mis_advanced']);
        }
        $page=sanitize_key($_GET['sn_mis_page']??'dashboard');
        $pages=['dashboard'=>'داشبورد','upload'=>'ورود و تخصیص','return'=>'بازگشت و ویرایش','reports'=>'گزارش‌ها','tools'=>'پردازش‌ها و تنظیمات','cases'=>'پرونده‌ها','plans'=>'برنامه توزیع','rules'=>'قواعد توزیع','branches'=>'شعبه‌ها','health'=>'سلامت سامانه','logs'=>'لاگ و عیب‌یابی','imports'=>'سابقه ورود فایل'];
        if (!isset($pages[$page])) { $page='dashboard'; }
        $service=$this->sn_mis_v2_service(); global $wpdb;
        ob_start();
        echo '<link rel="stylesheet" href="'.esc_url(SN_PLUGIN_URL.'assets/css/mis-portal.css?ver='.SN_VERSION).'">';
        $prefs=$this->sn_mis_preferences();
        $is_admin=current_user_can('manage_options');$management=in_array($page,['cases','plans','rules','branches','health','logs'],true);
        echo '<div class="sn-mis-v2 sn-mis-theme-'.esc_attr($prefs['theme']).' sn-mis-density-'.esc_attr($prefs['density']).'" data-columns="'.esc_attr(implode(' ',$prefs['columns'])).'" data-refresh="'.(int)$prefs['refresh'].'" data-view-api="'.esc_url(admin_url('admin-ajax.php')).'" data-view-nonce="'.esc_attr(wp_create_nonce('sn_mis_view')).'" dir="rtl"><a class="sn-mis-skip" href="#sn-mis-main">رفتن به محتوای اصلی</a><header class="sn-mis-header"><div class="sn-mis-brand"><span class="sn-mis-brand-mark">M</span><div><span class="sn-mis-eyebrow">شبکه فروش</span><h2>فضای کار MIS</h2></div></div><div class="sn-mis-header-actions">'.$this->sn_panel_user_actions_markup().'</div></header><div class="sn-mis-layout"><aside class="sn-mis-sidebar"><span class="sn-mis-nav-label">چرخه داده</span><nav class="sn-mis-main-nav" aria-label="منوی MIS">';
        $icons=['dashboard'=>'◈','upload'=>'+','return'=>'↶','reports'=>'▤','cases'=>'▦','tools'=>'⚙'];
        foreach(['dashboard','upload','return','reports'] as $key)echo '<a data-mis-nav href="'.esc_url($this->sn_mis_v2_url($key)).'" '.($page===$key?'aria-current="page"':'').'><span aria-hidden="true">'.$icons[$key].'</span>'.$pages[$key].'</a>';
        if($is_admin)echo '<a data-mis-nav href="'.esc_url($this->sn_mis_v2_url('cases')).'" '.($management?'aria-current="page"':'').'><span aria-hidden="true">▦</span>مدیریت و ابزارهای پیشرفته</a>';
        echo '<a data-mis-nav href="'.esc_url($this->sn_mis_v2_url('tools')).'" '.($page==='tools'?'aria-current="page"':'').'><span aria-hidden="true">⚙</span>پردازش‌ها و تنظیمات</a></nav><div class="sn-mis-sidebar-note"><span class="sn-mis-status-dot"></span>سوابق شما محفوظ است<small>تخصیص و بازگشت با بررسی نهایی</small></div></aside><main id="sn-mis-main"><div class="sn-mis-title"><div><span class="sn-mis-eyebrow">'.($management?'مدیریت سامانه':'کار با داده‌ها').'</span><h3 tabindex="-1">'.esc_html($pages[$page]).'</h3></div><span>زمان‌ها به وقت تهران</span></div><div class="sn-mis-navigation-status" role="status" hidden></div>';
        try {
            $notice_token=sanitize_key($_GET['sn_mis_notice']??'');$notice_key='sn_mis_notice_'.get_current_user_id().(preg_match('/^[a-f0-9]{24}$/',$notice_token)?'_'.$notice_token:'');
            $notice=get_transient($notice_key);
            if (is_array($notice)) {
                $this->sn_mis_feedback_markup($notice); delete_transient($notice_key);
            }
            if (!$service->ready()) {
                $this->sn_mis_setup_card();
            } else {
                $this->sn_mis_index_notice();
                $input=$_GET;
                if(!empty($input['saved_report'])) { $reports=get_user_meta(get_current_user_id(),'sn_mis_named_reports_v3',true);$report=$reports[sanitize_key($input['saved_report'])]??null;if(is_array($report)) { $input=array_merge($input,$report['filters']); } }
                if (!empty($_GET['load_saved'])) {
                    $saved=get_user_meta(get_current_user_id(),'sn_mis_saved_filters_v2',true);
                    if (is_array($saved)) {
                        foreach(['batch_id','manager_id','campaign','branch','search','state','consumed','date_type','event_type','from','to','report_mode'] as $key) { if (isset($saved[$key])) { $input[$key]=$saved[$key]; } }
                    }
                }
                $filters_ok=true;try{$filters=$this->sn_mis_v2_filters($input);}catch(Throwable $filter_error){$feedback=SN_MIS_Feedback::error($filter_error);$this->sn_mis_feedback_markup(['error'=>$feedback['message'],'feedback'=>$feedback]);$this->sn_mis_v2_filter_form($page,$input);$filters_ok=false;$filters=[];}
                if($filters_ok){$filters['chart_days']=(int)$prefs['chart_days'];$filters['__projection_only']=true;
                $has_preview=!empty($_GET['sn_mis_preview']);if ($has_preview) { $this->sn_mis_v2_render_preview(sanitize_text_field($_GET['sn_mis_preview'])); }
                if ($page==='dashboard') { $this->sn_mis_v2_dashboard($service,$filters,$input); }
                elseif ($page==='upload' && !$has_preview) { $this->sn_mis_v2_upload_page($service,$input); }
                elseif ($page==='return' && !$has_preview) { $this->sn_mis_v2_return_page($service,$filters,$input); }
                elseif ($management) { $this->sn_mis_management_page($page,$input); }
                elseif ($page==='tools') { $this->sn_mis_tools_page(); }
                elseif ($page==='imports') { $this->sn_mis_import_history($filters,$input); }
                elseif(!$has_preview) { $this->sn_mis_v2_reports_page($service,$filters,$input); }
                }
            }
        } catch(Throwable $error) { $feedback=SN_MIS_Feedback::error($error);$this->sn_mis_feedback_markup(['error'=>$feedback['message'],'feedback'=>$feedback]); }
        echo '</main></div></div><script src="'.esc_url(SN_PLUGIN_URL.'assets/js/mis-portal.js?ver='.SN_VERSION).'" defer></script>';
        return (string)ob_get_clean();
    }
    private function sn_mis_v2_dashboard(SN_MIS_Service $service,array $filters,array $input): void {
        global $wpdb;
        $this->sn_mis_v2_filter_form('dashboard',$input);
        $stats=$service->stats(array_diff_key($filters,['from'=>true,'to'=>true,'date_type'=>true])); $history=$service->history_stats($filters);
        echo '<section class="sn-mis-card sn-mis-next-action"><div><span class="sn-mis-eyebrow">شروع کار</span><h3>داده امروز را از اینجا مدیریت کنید</h3><p>ورود فایل، تخصیص به مدیر و بررسی بازگشت هر کدام مسیر مشخصی دارند.</p></div><div class="sn-mis-actions"><a class="sn-btn sn-btn-primary" href="'.esc_url($this->sn_mis_v2_url('upload')).'">ورود و تخصیص داده</a><a class="sn-btn" href="'.esc_url($this->sn_mis_v2_url('return')).'">بررسی بازگشت</a></div></section><section aria-label="موجودی فعلی"><div class="sn-mis-metrics">';
        echo '<article><strong>'.number_format_i18n(array_sum($stats)).'</strong><span>کل رکوردهای ثبت‌شده</span></article>';
        foreach(['RAW'=>'خام و آماده تخصیص','MANAGER_HELD'=>'در اختیار مدیر؛ مصرف‌نشده','CONSUMED'=>'مصرف‌شده'] as $state=>$label)echo '<article><strong>'.($stats['PENDING']>0 && $stats[$state]===0?'—':number_format_i18n($stats[$state])).'</strong><span>'.$label.($stats['PENDING']>0?' · تا این مرحله':'').'</span></article>';
        echo '</div><details class="sn-mis-filter-disclosure"><summary>کیفیت و آمادگی داده‌ها</summary><div class="sn-mis-metrics">';foreach(['LOCKED','INVALID','DUPLICATE','PENDING'] as $state)echo '<article><strong>'.number_format_i18n($stats[$state]).'</strong><span>'.esc_html($this->sn_mis_v2_state_label($state)).'</span></article>';echo '</div></details></section>';
        echo '<section class="sn-mis-card"><h3>رویدادهای تاریخی ثبت‌شده</h3><p class="sn-mis-muted">شعبه هر رویداد، شعبه ثبت‌شده هنگام همان تخصیص است. سوابق پیش از راه‌اندازی پرتال جدید در آمار رویدادهای جدید بازسازی نمی‌شوند.</p><div class="sn-mis-metrics">';
        foreach(['assignments'=>'دفعات تخصیص شماره','unique_assigned'=>'شماره‌های یکتای تخصیص‌یافته','returns'=>'بازگشت‌ها'] as $key=>$label) { echo '<article><strong>'.number_format_i18n((int)($history['totals'][$key]??0)).'</strong><span>'.$label.'</span></article>'; }
        $now=new DateTimeImmutable('now',new DateTimeZone('Asia/Tehran')); $jalali=SN_Helpers::gregorian_to_jalali_input_value($now->format('Y-m-d')); $parts=explode('/',$jalali);
        $starts=['امروز'=>$now->format('Y-m-d'),'ماه جاری'=>SN_Helpers::jalali_to_gregorian_date(($parts[0]??'').'/'.($parts[1]??'').'/01'),'سال جاری'=>SN_Helpers::jalali_to_gregorian_date(($parts[0]??'').'/01/01')];
        $utc_starts=[];foreach($starts as $label=>$start) { if($start)$utc_starts[$label]=(new DateTimeImmutable($start.' 00:00:00',new DateTimeZone('Asia/Tehran')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'); }
        $periods=$service->assignment_periods($filters,$utc_starts);$i=0;foreach($utc_starts as $label=>$start) { echo '<article><strong>'.number_format_i18n((int)($periods['p'.$i++]??0)).'</strong><span>تخصیص '.$label.'</span></article>'; }
        echo '</div></section><div class="sn-mis-two-columns">';
        foreach(['branches'=>'عملکرد شعب','managers'=>'عملکرد مدیران'] as $key=>$title) {
            echo '<section class="sn-mis-card"><h3>'.$title.'</h3><div class="sn-mis-table-scroll"><table><thead><tr><th>عنوان</th><th>دفعات تخصیص</th><th>شماره یکتا</th></tr></thead><tbody>';
            foreach($history[$key] as $r) { echo '<tr><td>'.esc_html($key==='branches'?$r['branch_label']:$this->sn_user_label((int)$r['new_manager_id'])).'</td><td>'.(int)$r['assignments'].'</td><td>'.(int)$r['unique_rows'].'</td></tr>'; }
            if (!$history[$key]) { echo '<tr><td colspan="3">رویدادی ثبت نشده است.</td></tr>'; } echo '</tbody></table></div></section>';
        } echo '</div>';
        $this->sn_mis_charts($stats,$history);
        $files=(new SN_MIS_Import_Log($wpdb,get_current_user_id()))->summary(array_diff_key($filters,['from'=>true,'to'=>true]));
        echo '<section class="sn-mis-card"><div class="sn-mis-section-heading"><div><h3>فایل‌های ورودی</h3><p>تعداد فایل با تعداد پرونده متفاوت است؛ هر نوبت ورود فایل جدا ثبت می‌شود.</p></div><a class="sn-btn" href="'.esc_url($this->sn_mis_v2_url('imports')).'">سابقه و خطاهای فایل‌ها</a></div>';
        if(empty($files['ready']))echo '<p>برای شروع ثبت سابقه فایل، راه‌اندازی تکمیلی را ادامه دهید.</p>';
        else {echo '<div class="sn-mis-metrics">';foreach(['completed'=>'فایل ثبت‌شده','failed'=>'نیازمند اصلاح','pending'=>'منتظر تأیید','invalid_rows'=>'ردیف نامعتبر در فایل‌ها'] as $key=>$label)echo '<article><strong>'.number_format_i18n((int)$files[$key]).'</strong><span>'.$label.'</span></article>';echo '</div><small class="sn-mis-muted">آمار فایل‌ها از زمان فعال‌شدن این قابلیت ثبت می‌شود و مربوط به پرونده/کمپین انتخاب‌شده است؛ فیلتر شعبه و مدیر روی آن اعمال نمی‌شود.</small>'; }echo '</section>';
        $events=$service->query(array_merge($filters,['selection'=>'newest']),10,0,true); echo '<section class="sn-mis-card"><h3>رویدادهای MIS</h3>'; $this->sn_mis_v2_table($events['rows'],true); echo '</section>';

    }
    private function sn_mis_v2_upload_page(SN_MIS_Service $service,array $input): void {
        $section=($input['section']??'')==='assign'?'assign':'import';$selected=absint($input['batch_id']??0);$batches=$this->sn_mis_batch_options($input,true);
        echo '<nav class="sn-mis-work-tabs" aria-label="ورود و تخصیص">';foreach(['import'=>'۱. ورود فایل','assign'=>'۲. تخصیص شماره‌ها'] as $key=>$label)echo '<a href="'.esc_url($this->sn_mis_v2_url('upload',['section'=>$key,'batch_id'=>$selected])).'" '.($section===$key?'aria-current="page"':'').'>'.$label.'</a>';echo '<a href="'.esc_url($this->sn_mis_v2_url('imports',['batch_id'=>$selected])).'">سابقه فایل‌ها</a></nav>';
        echo '<form method="get" class="sn-mis-inline-search"><input type="hidden" name="sn_mis_page" value="upload"><input type="hidden" name="section" value="'.$section.'"><label>پیدا کردن پرونده<input type="search" name="batch_search" value="'.esc_attr($input['batch_search']??'').'" placeholder="کد سراسری یا عنوان پرونده"></label><button class="sn-btn">جست‌وجوی پرونده</button></form>';
        if($section==='import') {
            $this->sn_mis_stepper(1,['انتخاب پرونده و فایل','تطبیق ستون‌ها','بازبینی و ثبت']);
            echo '<section class="sn-mis-card"><div class="sn-mis-section-heading"><div><h3>فایل را به پرونده درست متصل کنید</h3><p>کد پرونده مرجع سراسری فایل است. تاریخ و ساعت داخل فایل باید شمسی باشد.</p></div><span class="sn-mis-badge">CSV یا XLSX</span></div>';
            $this->sn_mis_v2_form_start('upload');echo '<label>پرونده مقصد<select name="batch_id" aria-label="پرونده مقصد" required><option value="">انتخاب پرونده</option>';foreach($batches as $b)echo '<option value="'.(int)$b['id'].'" '.selected($selected,(int)$b['id'],false).'>'.esc_html($b['batch_code'].' — '.$b['title']).'</option>';echo '</select><small>کد نمایش‌داده‌شده کنار عنوان، مرجع فایل‌های همین پرونده است.</small></label><label class="sn-mis-file-field">فایل اطلاعات<input type="file" name="mis_csv" aria-label="فایل اطلاعات" accept=".csv,.xlsx" required aria-describedby="sn-mis-file-help"><small id="sn-mis-file-help">حداکثر ۵ مگابایت و ۱۰هزار ردیف؛ فایل قالب قبلی نیز پذیرفته می‌شود.</small></label><button class="sn-btn sn-btn-primary">ادامه به تطبیق ستون‌ها</button></form><details class="sn-mis-file-guide"><summary>قالب فایل و نمونه تاریخ شمسی</summary><p>ستون‌های پیشنهادی: کد فایل، شماره تماس، تاریخ و ساعت، نام و نام خانوادگی. اگر ستون کد فایل ندارید، پرونده انتخاب‌شده مرجع فایل خواهد بود. کد فایلِ واردشده باید با کد پرونده مقصد یکسان باشد.</p><p>نمونه زمان: <bdi>۱۴۰۵/۰۸/۱۰ ۱۴:۳۰</bdi> به وقت تهران. فایل Excel باید زمان را به شکل متن شمسی نگه دارد؛ تاریخ میلادی یا شماره سریال Excel به‌جای تاریخ شمسی پذیرفته نمی‌شود.</p><a class="sn-btn" download href="'.esc_url(wp_nonce_url(add_query_arg(['action'=>'sn_mis_v2','operation'=>'template'],admin_url('admin-post.php')),'sn_mis_v2')).'">دریافت قالب CSV</a></details></section>';
            echo '<details class="sn-mis-card"><summary>پرونده جدید می‌خواهید؟</summary><p>ابتدا پرونده بسازید. سامانه کد مرجع یکتای آن را تعیین می‌کند.</p><form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="sn-mis-form"><input type="hidden" name="action" value="sn_mis_create_batch">';$this->sn_mis_nonce_field('sn_mis_create_batch');foreach(['title'=>'عنوان پرونده','campaign_code'=>'کد کمپین (اختیاری)','source_label'=>'منبع فایل (اختیاری)'] as $key=>$label)echo '<label>'.$label.'<input name="'.$key.'" maxlength="191" '.($key==='title'?'required':'').'></label>';echo '<button class="sn-btn sn-btn-primary">ساخت پرونده و ادامه</button></form></details>';
            return;
        }
        $this->sn_mis_stepper(1,['انتخاب گیرنده و تعداد','بررسی قابل تحویل','تأیید و ثبت']);
        $stats=$service->stats($selected?['batch_id'=>$selected]:[]);
        echo '<section class="sn-mis-card"><div class="sn-mis-section-heading"><div><h3>تخصیص بخشی از مخزن</h3><p>این تحویل اولیه، مصرف محسوب نمی‌شود. فقط شماره‌های خام و آزاد بررسی می‌شوند.</p></div><span class="sn-mis-stock">'.($stats['PENDING']>0?'آمار در حال آماده‌سازی':number_format_i18n($stats['RAW']).' شماره خام').'</span></div>';
        if($stats['PENDING']>0)echo '<p class="sn-mis-notice">وضعیت '.number_format_i18n($stats['PENDING']).' شماره هنوز بررسی نشده است. آماده‌سازی آمار را از پردازش‌ها کامل کنید؛ این شماره‌ها آزاد فرض نمی‌شوند.</p>';
        $this->sn_mis_v2_form_start('preview_assign');echo '<label>محدوده پرونده<select name="batch_id"><option value="">همه پرونده‌ها</option>';foreach($batches as $b)echo '<option value="'.(int)$b['id'].'" '.selected($selected,(int)$b['id'],false).'>'.esc_html($b['batch_code'].' — '.$b['title']).'</option>';echo '</select></label><label>مدیر فروش گیرنده<select name="target_manager_id" aria-label="مدیر فروش گیرنده" required><option value="">انتخاب مدیر فعال</option>';$manager_branches=$this->sn_sales_manager_branches();foreach($this->sn_mis_sales_managers() as $manager){$id=(int)$manager->user_id;$label=$this->sn_user_label($id);if(isset($manager_branches[$id]))$label.=' — '.$manager_branches[$id]['label'];echo '<option value="'.$id.'">'.esc_html($label).'</option>';}echo '</select></label><label>شعبه برای مدیرِ بدون شعبه<select name="assignment_branch"><option value="">انتخاب شعبه در صورت نیاز</option>';foreach($this->sn_mis_v2_branches() as $key=>$label)echo '<option value="'.esc_attr($key).'">'.esc_html($label).'</option>';echo '</select><small>برای مدیر دارای شعبه، شعبه ثبت‌شدهٔ خودش استفاده می‌شود و در سابقه همین تخصیص می‌ماند.</small></label><label>تعداد شماره<input type="number" name="limit" aria-label="تعداد شماره" min="1" max="1000" value="100" required><small>حداکثر ۱۰۰۰ شماره در هر عملیات.</small></label><label>ترتیب انتخاب<select name="selection"><option value="oldest">قدیمی‌ترین شماره‌های آزاد</option><option value="newest">جدیدترین شماره‌های آزاد</option></select></label><label>دلیل تخصیص<textarea name="reason" required maxlength="2000" placeholder="مثلاً شروع کمپین شعبه تهران"></textarea></label><button class="sn-btn sn-btn-primary">بررسی شماره‌های قابل تحویل</button></form></section>';
        if(!$this->sn_mis_v2_branches())echo '<p role="status" class="sn-mis-notice">هنوز شعبه‌ای تعریف نشده است. مدیرکل می‌تواند از «مدیریت و ابزارهای پیشرفته ← شعبه‌ها» شعبه اضافه کند.</p>';
    }
    private function sn_mis_v2_return_page(SN_MIS_Service $service,array $filters,array $input): void {
        global $wpdb;
        $id=absint($input['row_id']??0);
        if ($id) {
            $row=$service->rows($wpdb->prepare('SELECT r.*,m.source_code,m.first_name,m.last_name,m.source_datetime_raw FROM '.$wpdb->prefix.'sn_mis_data_rows r LEFT JOIN '.$wpdb->prefix.'sn_mis_record_meta m ON m.row_id=r.id WHERE r.id=%d',$id));
            if (!$row) { throw new RuntimeException('رکورد پیدا نشد.'); } $r=$row[0];$fact=(new SN_MIS_Read_Model($wpdb))->classify([$r])[$id];$identity_readonly=$fact['_consumed'] || $fact['_reserved'] || (int)$r['assigned_manager_user_id']>0 || $fact['_has_items'] || $fact['logical_state']==='LOCKED';
            echo '<section class="sn-mis-card"><h3>ویرایش رکورد #'.$id.'</h3><p>ویرایش با ثبت مقدار قبلی و جدید انجام می‌شود. شناسه داخلی ثابت است.</p>'; $this->sn_mis_v2_form_start('edit'); echo '<input type="hidden" name="row_id" value="'.$id.'">';
            foreach(['source_code'=>'کد منبع','normalized_phone'=>'شماره تماس','first_name'=>'نام','last_name'=>'نام خانوادگی','source_datetime_raw'=>'تاریخ و ساعت منبع'] as $key=>$label) { $value=$r[$key]??''; if ($key==='first_name' && !$value && empty($r['last_name'])) { $value=$r['customer_name']; } echo '<label>'.$label.'<input type="text" name="'.$key.'" value="'.esc_attr($value).'" '.($key==='normalized_phone'?'required':'').' '.(($key==='source_code' || ($key==='normalized_phone' && $identity_readonly))?'readonly aria-describedby="sn-mis-identity-help" ':'').'maxlength="191" '.($key==='source_datetime_raw'?'placeholder="۱۴۰۵/۰۸/۱۰ ۱۴:۳۰"':'').'></label>'; }
            echo '<p id="sn-mis-identity-help" class="sn-mis-muted">کد مرجع ثابت است. شماره دارای تخصیص، مصرف یا سابقه توزیع از این فرم قابل جایگزینی نیست. تاریخ منبع را به شمسی و به وقت تهران وارد کنید.</p><label>دلیل ویرایش<textarea name="reason" required></textarea></label><button class="sn-btn sn-btn-primary">ثبت ویرایش</button></form><h4>تاریخچه؛ جدیدترین ۱۰۰ رویداد</h4>';
            foreach($service->record_history($id) as $event) {
                echo '<details><summary>'.esc_html($this->sn_mis_v2_event_label($event['event_type']).' — '.$this->sn_mis_v2_time($event['created_at'],true).' — '.$this->sn_user_label((int)$event['actor_user_id'])).'</summary><p>'.esc_html($event['reason']).'</p><p>شعبه: '.esc_html($event['branch_label']).'</p><pre>'.esc_html($event['before_json'].' → '.$event['after_json']).'</pre></details>';
            } echo '<a class="sn-btn sn-btn-secondary" href="'.esc_url($this->sn_mis_v2_url('return',['batch_id'=>(int)$r['batch_id']])).'">بازگشت به فهرست شماره‌های پرونده</a></section>';return;
        }
        $this->sn_mis_v2_filter_form('return',$input); $stats=$service->stats($filters);
        echo '<section class="sn-mis-card"><h3>پیش‌نمایش بازگشت</h3><p>در اختیار مدیر و قابل بازگشت: <strong>'.number_format_i18n($stats['MANAGER_HELD']).'</strong> — مصرف‌شده: <strong>'.number_format_i18n($stats['CONSUMED']).'</strong> — قفل‌شده: <strong>'.number_format_i18n($stats['LOCKED']).'</strong></p>';
        $offset=max(0,absint($input['sn_mis_offset']??0)); $result=$service->query($filters,(int)$this->sn_mis_preferences()['page_size'],$offset);
        $this->sn_mis_v2_form_start('preview_return');
        foreach($input as $key=>$value) { if (in_array($key,['batch_id','manager_id','branch','campaign','search','from','to','date_type','state','consumed'],true) && is_scalar($value)) { echo '<input type="hidden" name="'.esc_attr($key).'" value="'.esc_attr($value).'">'; } }
        echo '<fieldset><legend>شماره‌ها را برای بررسی بازگشت انتخاب کنید</legend><p class="sn-mis-muted">بدون انتخاب دستی، تعداد درخواستی از نتایج فیلتر بررسی می‌شود. شماره مصرف‌شده یا قفل‌شده تغییر نمی‌کند.</p><button type="button" class="sn-btn" data-select-visible>انتخاب شماره‌های این صفحه برای بررسی</button><div class="sn-mis-checks">';
        foreach($result['rows'] as $r) { $blocked=!in_array($r['logical_state'],['MANAGER_HELD','PENDING'],true);echo '<label class="'.($blocked?'is-blocked':'').'"><input type="checkbox" name="row_ids[]" value="'.(int)$r['id'].'" '.($blocked?'disabled':'').'><span><bdi>'.esc_html($r['normalized_phone']).'</bdi><small> #'.(int)$r['id'].' — '.esc_html($this->sn_mis_v2_state_label($r['logical_state'])).($blocked?' · '.esc_html($r['block_reason']):'').'</small></span></label>'; } echo '</div></fieldset><label>تعداد درخواستی<input type="number" name="limit" min="1" max="1000" value="500"></label><label>دلیل بازگشت<textarea name="reason" required></textarea></label><button class="sn-btn sn-btn-primary">پیش‌نمایش بازگشت</button></form>';
        $this->sn_mis_v2_table($result['rows']); $this->sn_mis_v2_pager($result['total'],$offset,'return'); echo '</section>';
    }
    private function sn_mis_v2_pager(int $total,int $offset,string $page): void {
        $page_size=(int)$this->sn_mis_preferences()['page_size'];
        echo '<div class="sn-mis-pager"><span>کل نتایج: '.number_format_i18n($total).' — صفحه '.(int)(floor($offset/$page_size)+1).'</span>';
        $paging=$_GET;if(!empty($_GET['saved_report'])) { $reports=get_user_meta(get_current_user_id(),'sn_mis_named_reports_v3',true);$paging=array_merge($paging,$reports[sanitize_key($_GET['saved_report'])]['filters']??[]); }
        $args=[]; foreach($paging as $key=>$value) { if (in_array($key,['batch_id','manager_id','branch','campaign','search','from','to','date_type','event_type','selection','state','consumed','report_mode'],true) && is_scalar($value)) { $args[$key]=sanitize_text_field(wp_unslash($value)); } }
        if ($offset>0) { echo '<a href="'.esc_url($this->sn_mis_v2_url($page,$args+['sn_mis_offset'=>max(0,$offset-$page_size)])).'">قبلی</a>'; }
        if ($offset+$page_size<$total) { echo '<a href="'.esc_url($this->sn_mis_v2_url($page,$args+['sn_mis_offset'=>$offset+$page_size])).'">بعدی</a>'; } echo '</div>';
    }
    private function sn_mis_v2_reports_page(SN_MIS_Service $service,array $filters,array $input): void {
        $this->sn_mis_v2_filter_form('reports',$input); $history=($input['report_mode']??'')==='events';
        $offset=max(0,absint($input['sn_mis_offset']??0)); $result=$service->query($filters,(int)$this->sn_mis_preferences()['page_size'],$offset,$history);
        echo '<section class="sn-mis-card"><h3>'.($history?'رویدادهای تاریخی':'موجودی فعلی').'</h3>';
        if ($history) { $s=$service->history_stats($filters+['__typed_history'=>true]); echo '<p>دفعات تخصیص: '.(int)$s['totals']['assignments'].' — شماره یکتای تخصیص‌یافته: '.(int)$s['totals']['unique_assigned'].'</p>'; }
        $this->sn_mis_v2_table($result['rows'],$history); $this->sn_mis_v2_pager($result['total'],$offset,'reports');
        echo '<p>خروجی تمام نتایج در پس‌زمینه مرحله‌ای آماده می‌شود. عضویت ردیف‌ها هنگام شروع ثابت است و مقادیر در زمان پردازش هر مرحله خوانده می‌شوند.</p>';
        foreach(['start_export'=>'ساخت خروجی Excel مرحله‌ای','save_report'=>'ذخیره گزارش با نام'] as $op=>$label) {
            $this->sn_mis_v2_form_start($op);echo '<input type="hidden" name="report_mode" value="'.($history?'events':'current').'">';
            foreach($input as $key=>$value) { if (in_array($key,['batch_id','manager_id','branch','campaign','search','from','to','date_type','event_type','state','consumed'],true) && is_scalar($value)) { echo '<input type="hidden" name="'.esc_attr($key).'" value="'.esc_attr($value).'">'; } }
            if($op==='save_report')echo '<label>نام گزارش<input name="report_name" maxlength="100" required></label>';
            echo '<button class="sn-btn sn-btn-primary">'.$label.'</button></form>';
        }echo '</section>';$this->sn_mis_named_reports();
    }
    /** Old URLs use the same preview, state guard and history as the new portal. */
    private function sn_mis_v2_legacy(string $kind): void {
        if($kind==='quick' && !$this->sn_mis_can_use_advanced_tools())wp_die('دسترسی غیرمجاز','MIS',['response'=>403]);
        $nonce=['import'=>'sn_mis_import_csv','assign'=>'sn_mis_assign_batch','quick'=>'sn_mis_quick_assign_data','return'=>'sn_mis_return_assignments'][$kind];
        if (!$this->sn_can_access_mis_panel()) { wp_die('دسترسی غیرمجاز','MIS',['response'=>403]); }
        check_admin_referer($nonce); $this->sn_mis_acquire_request_lock();
        $service=$this->sn_mis_v2_service();
        try {
            if (!$service->ready()) { throw new RuntimeException('ابتدا پرتال جدید MIS را راه‌اندازی کنید.'); }
            if ($kind==='import') { $this->sn_mis_v2_upload_file(); }
            $returning=$kind==='return'; $filters=['batch_id'=>absint($_POST['batch_id']??0)];
            if (!$filters['batch_id']) { throw new RuntimeException('پرونده لازم است.'); }
            if ($returning) { $filters['manager_id']=absint($_POST['manager_user_id']??0); } else { $filters['state']='RAW'; }
            $limit=max(1,min(1000,absint($_POST['limit']??500)));
            $reason=sanitize_textarea_field(wp_unslash($_POST['reason']??'')); if ($reason==='') { $reason=$returning?'بازگشت از مسیر مدیریتی قدیمی':'تخصیص از مسیر مدیریتی قدیمی'; }
            $target=$returning?0:absint($_POST[$kind==='quick'?'target_user_id':'manager_user_id']??0);
            $position=$returning?'sales_manager':$this->sn_hr_position_for_user($target);
            if (!$returning && (!in_array($position,$kind==='quick'?['sales_manager','sales_deputy','senior_supervisor','supervisor','seller']:['sales_manager'],true) || !$this->sn_mis_v2_target_active($target))) { throw new RuntimeException('گیرنده فعال و معتبر نیست.'); }
            $result=$service->query($filters,$limit);
            $team_branch = $returning ? [] : ($this->sn_sales_branch_map([$target])[$target] ?? []);
            $branch = $team_branch ?: ['key'=>'unknown','label'=>'نامشخص'];
            if (!$team_branch && !empty($_POST['assignment_branch'])) { $branch=$this->sn_mis_v2_branch(sanitize_key($_POST['assignment_branch'])); }
            $token=$this->sn_mis_v2_preview($returning?'return':'assign',['batch_id'=>(int)$filters['batch_id'],'ids'=>array_column($result['rows'],'id'),'manager'=>$target,'position'=>$position,'create_item'=>$kind!=='assign','branch'=>$branch,'team_branch'=>(bool)$team_branch,'reason'=>$reason,'report'=>['requested'=>$limit,'selected'=>count($result['rows']),'total'=>$result['total'],'preview'=>$result['rows']]]);
            $this->sn_mis_v2_notice(['message'=>'مسیر قدیمی به پیش‌نمایش امن هدایت شد. تعیین وضعیت یا تحویل به زیرمجموعه، مصرف محسوب می‌شود.'],$returning?'return':'upload',$token);
        } catch(Throwable $e) { $this->sn_mis_v2_notice(['error'=>$e->getMessage()],$kind==='return'?'return':'upload'); }
    }
    private function sn_mis_v2_create_batch(): void {
        if (!$this->sn_can_access_mis_panel()) { wp_die('دسترسی غیرمجاز','MIS',['response'=>403]); }
        $this->sn_mis_v2_nonce('sn_mis_create_batch'); $this->sn_mis_acquire_request_lock();
        global $wpdb;
        try {
            $data=['batch_code'=>$this->sn_mis_generate_batch_code(),'created_by'=>get_current_user_id(),'import_status'=>'draft'];
            foreach(['title','source_type','source_label','campaign_code','data_category','notes'] as $key) { $data[$key]=sanitize_text_field(wp_unslash($_POST[$key]??'')); }
            if ($data['title']==='') { throw new RuntimeException('عنوان پرونده لازم است.'); }
            $report=$this->sn_mis_v2_service()->run_atomic(function() use($wpdb,$data) {
                $this->sn_mis_v2_service()->checked($wpdb->insert($wpdb->prefix.'sn_mis_import_batches',$data)); $id=(int)$wpdb->insert_id;
                $this->sn_mis_v2_service()->checked($wpdb->insert($wpdb->prefix.'sn_mis_distribution_logs',['batch_id'=>$id,'actor_user_id'=>get_current_user_id(),'action'=>'batch_created','new_value'=>wp_json_encode($data,JSON_UNESCAPED_UNICODE),'created_at'=>current_time('mysql')]));
                return ['message'=>'پرونده ساخته شد. کد آن مرجع سراسری فایل‌های همین پرونده است؛ اکنون فایل را انتخاب کنید.','batch_id'=>$id,'batch_code'=>$data['batch_code']];
            });
            $this->sn_mis_v2_notice($report,'upload');
        } catch(Throwable $error) { $this->sn_mis_v2_notice(['error'=>$error->getMessage()],'upload'); }
    }
    private function sn_mis_v2_cleanup(): void {
        if (!current_user_can('manage_options')) { wp_die('فقط مدیرکل به ابزار حسابرسی دسترسی دارد.','MIS',['response'=>403]); }
        check_admin_referer('sn_mis_cleanup_deleted_traces');
        // No physical purge can prove safety for unknown historical orphans. Keep every link/log.
        $this->sn_mis_v2_notice(['message'=>'پاکسازی عمومی به حسابرسی بدون حذف تبدیل شده است. برای کنارگذاشتن پرونده، بایگانی آن را انتخاب کنید.','deleted'=>0],'reports');
    }
    private function sn_mis_v2_archive_legacy(): void {
        if (!current_user_can('manage_options')) { wp_die('فقط مدیرکل می‌تواند پرونده را بایگانی کند.','MIS',['response'=>403]); }
        check_admin_referer('sn_mis_delete_batch_data'); $this->sn_mis_acquire_request_lock();
        try {
            $confirm=strtoupper(trim(sanitize_text_field($_POST['confirm']??'')));
            if (!in_array($confirm,['DELETE_FILE','DELETE_BATCH','ARCHIVE'],true)) { throw new RuntimeException('تأیید بایگانی لازم است.'); }
            $batch=absint($_POST['batch_id']??0); if (!$batch) { throw new RuntimeException('پرونده انتخاب کنید.'); }
            $report=$this->sn_mis_v2_service()->safe_archive($batch,sanitize_textarea_field(wp_unslash($_POST['reason']??'بایگانی با حفظ سابقه')));
            $report['message']='پرونده بایگانی شد؛ تمام شماره‌ها، ارتباط‌ها و سوابق حفظ شدند.'; $this->sn_mis_v2_notice($report,'reports');
        } catch(Throwable $e) { $this->sn_mis_v2_notice(['error'=>$e->getMessage()],'reports'); }
    }
    private function sn_mis_v2_plan_source_allowed(object $item): bool {
        global $wpdb; $service=$this->sn_mis_v2_service(); $link=$service->item_link();
        $row_id=(int)($item->source_row_id??0); $pool_id=(int)($item->pool_id??0); $manager=(int)($item->manager_user_id??0);
        $seller=(int)($item->seller_id??$item->planned_seller_user_id??0); $item_id=(int)($item->item_id??$item->id??0);
        if (!$row_id || !$pool_id || !$manager || !$seller || !$this->sn_mis_v2_target_active($seller) || !in_array($seller,$this->sn_mis_sellers_under_manager($manager),true)) { return false; }
        $linked=$service->has_linked_items($row_id);
        $record=$service->rows($wpdb->prepare('SELECT * FROM '.$wpdb->prefix.'sn_mis_data_rows WHERE id=%d',$row_id));if(!$record)return false;$fact=(new SN_MIS_Read_Model($wpdb))->classify($record)[$row_id];if($fact['_consumed'])return false;
        if(!$fact['_identity_safe']) {
            // A historical approved plan is explicit authority for its own reserved source, never for returning it to RAW.
            if(!preg_match('/^09[0-9]{9}$/',(string)$record[0]['normalized_phone']) || $fact['_flags']['orphan_batch'] || $fact['_flags']['duplicate'] || $fact['_flags']['multiple_items'] || $fact['_flags']['claim_conflict'])return false;
            $proof=(int)$service->value($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}sn_mis_distribution_plan_items pi INNER JOIN {$wpdb->prefix}sn_mis_distribution_plans ph ON ph.id=pi.plan_id WHERE pi.id=%d AND pi.source_row_id=%d AND pi.pool_id=%d AND pi.manager_user_id=%d AND pi.planned_seller_user_id=%d AND ph.manager_user_id=%d AND ph.plan_status='approved' AND COALESCE(pi.created_lead_id,0)=0",$item_id,$row_id,$pool_id,$manager,$seller,$manager));
            if($proof!==1)return false;
        }
        $source=(int)$service->value($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}sn_mis_data_rows r INNER JOIN {$wpdb->prefix}sn_mis_import_batches mb ON mb.id=r.batch_id AND mb.import_status NOT IN ('archived','deleted','file_deleted','reset_deleted') INNER JOIN {$wpdb->prefix}sn_mis_lead_pool p ON p.source_row_id=r.id WHERE r.id=%d AND p.id=%d AND r.row_status='valid' AND r.assigned_manager_user_id=%d AND p.assigned_manager_user_id=%d AND p.pool_status='ready' AND COALESCE(p.assigned_seller_user_id,0)=0",$row_id,$pool_id,$manager,$manager));
        $other=(int)$service->value($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}sn_mis_distribution_plan_items pi LEFT JOIN {$wpdb->prefix}sn_mis_distribution_plans ph ON ph.id=pi.plan_id WHERE (pi.source_row_id=%d OR pi.pool_id=%d) AND pi.id<>%d AND (COALESCE(pi.created_lead_id,0)>0 OR (pi.item_status NOT IN ('skipped','cancelled') AND (ph.id IS NULL OR ph.plan_status NOT IN ('cancelled','archived'))))",$row_id,$pool_id,$item_id));
        return !$linked && $source===1 && !$other;
    }
    private function sn_mis_v2_legacy_pool_allowed(object $pool): bool {
        global $wpdb; $service=$this->sn_mis_v2_service();
        // Direct and pool items share the canonical source-row identity.
        $link=$service->item_link();
        if($service->has_linked_items((int)$pool->source_row_id))return false;
        $reserved=(int)$service->value($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}sn_mis_distribution_plan_items pi LEFT JOIN {$wpdb->prefix}sn_mis_distribution_plans ph ON ph.id=pi.plan_id WHERE (pi.source_row_id=%d OR pi.pool_id=%d) AND (COALESCE(pi.created_lead_id,0)>0 OR (pi.item_status NOT IN ('skipped','cancelled') AND (ph.id IS NULL OR ph.plan_status NOT IN ('cancelled','archived'))))",(int)$pool->source_row_id,(int)$pool->id));
        return $reserved===0;
    }
}
