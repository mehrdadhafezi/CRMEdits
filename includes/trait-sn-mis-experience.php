<?php
if (!defined('ABSPATH')) { exit; }

trait SN_MIS_Experience {
    private function sn_mis_feedback_markup(array $report): void {
        $error=!empty($report['error']);$partial=(int)($report['blocked']??0)>0 || (int)($report['invalid']??0)>0;$feedback=$report['feedback']??[];
        echo '<section class="sn-mis-feedback '.($error?'is-error':($partial?'is-partial':'is-success')).'" role="'.($error?'alert':'status').'" tabindex="-1"><div class="sn-mis-feedback-icon" aria-hidden="true">'.(($error||$partial)?'!':'✓').'</div><div><h4>'.esc_html($error?($feedback['title']??'عملیات کامل نشد'):($report['message']??($partial?(((int)($report['assigned']??$report['returned']??$report['imported']??0)>0)?'عملیات برای شماره‌های مجاز انجام شد؛ بقیه بدون تغییر ماندند':'هیچ شماره‌ای تغییر نکرد؛ موارد زیر را بررسی کنید'):'عملیات با موفقیت ثبت شد'))).'</h4>';
        if($error)echo '<p>'.esc_html($report['error']).'</p><p class="sn-mis-recovery">'.esc_html($feedback['hint']??'ورودی‌ها را بررسی کنید و دوباره تلاش کنید.').'</p>';
        $labels=['assigned'=>'تخصیص‌یافته','returned'=>'بازگشتی','blocked'=>'بدون تغییر / غیرمجاز','imported'=>'ردیف ثبت‌شده','valid'=>'معتبر','invalid'=>'نامعتبر','duplicate'=>'تکراری','unchanged'=>'قبلاً ثبت‌شده','import_run_id'=>'شماره پیگیری فایل'];
        echo '<div class="sn-mis-result-metrics">';foreach($labels as $key=>$label)if(isset($report[$key]))echo '<span class="'.($key==='blocked'?'is-warning':'').'"><b>'.number_format_i18n((int)$report[$key]).'</b> '.esc_html($label).'</span>';echo '</div>';
        if(!empty($report['reasons'])){echo '<details><summary>دلیل شماره‌های بدون تغییر</summary><ul>';foreach($report['reasons'] as $reason=>$count)echo '<li>'.esc_html($reason==='duplicate'?'شماره تکراری در مخزن یا زنجیره فروش':$reason).' — '.number_format_i18n((int)$count).' شماره</li>';echo '</ul></details>';}
        if(!empty($report['operation_id']))echo '<small>شناسه پیگیری عملیات: <bdi>'.esc_html($report['operation_id']).'</bdi></small>';
        echo '</div></section>';
    }
    private function sn_mis_stepper(int $current,array $labels): void {
        echo '<ol class="sn-mis-flow-steps" aria-label="مراحل کار">';foreach(array_values($labels) as $i=>$label)echo '<li '.($i+1===$current?'aria-current="step"':'').' class="'.($i+1<$current?'is-complete':'').'"><span>'.($i+1<$current?'✓':($i+1)).'</span>'.esc_html($label).'</li>';echo '</ol>';
    }
    private function sn_mis_source_time(string $raw): string {
        if(trim($raw)==='')return '—';
        try{return SN_MIS_Feedback::jalali_datetime($raw)['display'];}catch(Throwable $ignored){}
        // An explicit four-digit Gregorian legacy date can be displayed in Jalali without rewriting it.
        if(preg_match('~^((?:19|20)[0-9]{2})[-/]([0-9]{2})[-/]([0-9]{2})(?:[ T]([0-9]{2}:[0-9]{2}(?::[0-9]{2})?))?$~',$raw,$m) && checkdate((int)$m[2],(int)$m[3],(int)$m[1]))return SN_Helpers::gregorian_to_jalali_input_value($m[1].'-'.$m[2].'-'.$m[3]).(!empty($m[4])?' '.$m[4]:'');
        return 'تاریخ نیازمند بررسی';
    }
    private function sn_mis_file_status(string $status): string {
        return ['received'=>'دریافت‌شده','mapping'=>'در انتظار تطبیق ستون‌ها','preview'=>'آماده تأیید نهایی','completed'=>'ثبت‌شده','failed'=>'نیازمند اصلاح'][$status]??'نیازمند بررسی';
    }
    private function sn_mis_import_history(array $filters,array $input): void {
        $log=new SN_MIS_Import_Log($GLOBALS['wpdb'],get_current_user_id());
        echo '<section class="sn-mis-card"><div class="sn-mis-section-heading"><div><span class="sn-mis-eyebrow">ردگیری فایل‌ها</span><h3>سابقه ورود فایل</h3><p>هر بار انتخاب و ارسال فایل، یک سابقه مستقل دارد. کد پرونده مرجع سراسری فایل است؛ شماره پیگیری، نوبت ورود را مشخص می‌کند.</p></div><a class="sn-btn sn-btn-primary" href="'.esc_url($this->sn_mis_v2_url('upload')).'">ورود فایل جدید</a></div>';
        if(!$log->ready()){echo '<p class="sn-mis-notice">ثبت سابقه فایل از این نسخه شروع می‌شود. مدیرکل باید راه‌اندازی تکمیلی را ادامه دهد.</p></section>';$this->sn_mis_setup_card();return;}
        $filters=$this->sn_mis_v2_filters(array_merge($input,['date_type'=>'event']));
        echo '<form method="get" class="sn-mis-form"><input type="hidden" name="sn_mis_page" value="imports"><input type="hidden" name="date_type" value="event">';foreach(['batch_id'=>'شناسه پرونده','campaign'=>'کد کمپین','from'=>'از تاریخ شمسی','to'=>'تا تاریخ شمسی'] as $key=>$label)echo '<label>'.$label.'<input name="'.$key.'" value="'.esc_attr($input[$key]??'').'"></label>';echo '<label>نتیجه فایل<select name="file_status"><option value="">همه وضعیت‌ها</option>';foreach(['received','mapping','preview','completed','failed'] as $status)echo '<option value="'.$status.'" '.selected($input['file_status']??'',$status,false).'>'.esc_html($this->sn_mis_file_status($status)).'</option>';echo '</select></label><button class="sn-btn sn-btn-primary">اعمال فیلتر فایل‌ها</button></form>';
        $filters['file_status']=sanitize_key($input['file_status']??'');$summary=$log->summary($filters);
        echo '<div class="sn-mis-metrics">';foreach(['attempts'=>'کل نوبت‌های ورود','completed'=>'فایل‌های ثبت‌شده','failed'=>'نیازمند اصلاح','pending'=>'در انتظار تأیید'] as $key=>$label)echo '<article><strong>'.number_format_i18n((int)($summary[$key]??0)).'</strong><span>'.$label.'</span></article>';echo '</div><p class="sn-mis-muted">بازه تاریخ، زمان ارسال فایل است. شعبه و مدیر به سابقه ورود فایل تعلق ندارند؛ برای آنها گزارش شماره‌ها را ببینید. سابقه فایل‌های پیش از این نسخه بازسازی نمی‌شود.</p>';
        $offset=absint($input['sn_mis_offset']??0);$data=$log->query($filters,$offset,20);
        echo '<div class="sn-mis-table-scroll"><table><caption class="sn-mis-sr-only">نتیجه هر نوبت ورود فایل</caption><thead><tr><th>فایل / پیگیری</th><th>پرونده / اپراتور</th><th>زمان ارسال</th><th>وضعیت</th><th>نتیجه و راه اصلاح</th></tr></thead><tbody>';
        foreach($data['rows'] as $run){echo '<tr><td><strong>'.esc_html($run['filename']).'</strong><small>#'.(int)$run['id'].'</small></td><td><bdi>'.esc_html($run['batch_code']).'</bdi><small>'.esc_html($this->sn_user_label((int)$run['actor_user_id'])).'</small></td><td>'.esc_html($this->sn_mis_v2_time($run['created_at'],true)).'</td><td><span class="sn-mis-state sn-mis-state-'.($run['status']==='failed'?'invalid':($run['status']==='completed'?'raw':'pending')).'">'.esc_html($this->sn_mis_file_status($run['status'])).'</span></td><td>'.number_format_i18n((int)$run['imported_rows']).' ثبت‌شده / '.number_format_i18n((int)$run['invalid_rows']).' نامعتبر / '.number_format_i18n((int)$run['duplicate_rows']).' تکراری';if($run['error_message'])echo '<small class="sn-mis-error-copy">'.esc_html($run['error_message']).'</small>';if((int)$run['invalid_rows']>0)echo '<small>برای اصلاح شماره‌های نامعتبر، پرونده را در «بازگشت و ویرایش» فیلتر کنید.</small>';echo '</td></tr>';}
        if(!$data['rows'])echo '<tr><td colspan="5"><div class="sn-mis-empty"><strong>هنوز فایلی در این بازه نیست</strong><p>بازه را تغییر دهید یا ورود یک فایل جدید را شروع کنید.</p></div></td></tr>';
        echo '</tbody></table></div>';$this->sn_mis_management_pager($data['total'],$offset,'imports',array_intersect_key($input,array_flip(['batch_id','campaign','from','to','file_status'])),20);echo '</section>';
    }
    private function sn_mis_logs_page(array $input): void {
        if(!current_user_can('manage_options')){echo '<p role="alert" class="sn-mis-notice is-error">فقط مدیرکل می‌تواند لاگ‌های فنی را ببیند.</p>';return;}
        global $wpdb;$service=$this->sn_mis_v2_service();$filters=$this->sn_mis_v2_filters(array_merge($input,['date_type'=>'event']));$where=['1=1'];$args=[];
        foreach(['batch_id'=>'batch_id','actor_id'=>'actor_user_id'] as $key=>$column)if(!empty($input[$key])){$where[]=$column.'=%d';$args[]=absint($input[$key]);}
        if(!empty($input['log_action'])){$where[]='action=%s';$args[]=sanitize_key($input['log_action']);}
        foreach(['from'=>'>=','to'=>'<'] as $key=>$op)if(!empty($filters[$key])){$where[]='created_at'.$op.'%s';$args[]=(new DateTimeImmutable($filters[$key],new DateTimeZone('UTC')))->setTimezone(wp_timezone())->format('Y-m-d H:i:s');}
        $tail=' FROM '.$wpdb->prefix.'sn_mis_distribution_logs WHERE '.implode(' AND ',$where);$offset=absint($input['sn_mis_offset']??0);
        $total=(int)$service->value($args?$wpdb->prepare('SELECT COUNT(*)'.$tail,...$args):'SELECT COUNT(*)'.$tail);
        $rows=$service->rows($wpdb->prepare('SELECT *'.$tail.' ORDER BY id DESC LIMIT 20 OFFSET %d',...array_merge($args,[$offset])));
        echo '<section class="sn-mis-card"><span class="sn-mis-eyebrow">فقط خواندنی · مدیرکل</span><h3>لاگ و عیب‌یابی MIS</h3><p>لاگ‌های قدیمی حفظ شده‌اند. این صفحه داده یا تاریخچه را پاک نمی‌کند؛ رویدادهای جدید هر شماره در گزارش تاریخی نیز قابل مشاهده‌اند.</p><a class="sn-btn" href="'.esc_url($this->sn_mis_v2_url('reports',['report_mode'=>'events','date_type'=>'event'])).'">رویدادهای شماره‌ها</a><form method="get" class="sn-mis-form"><input type="hidden" name="sn_mis_page" value="logs"><input type="hidden" name="date_type" value="event">';
        foreach(['batch_id'=>'شناسه پرونده','actor_id'=>'شناسه اپراتور','from'=>'از تاریخ شمسی','to'=>'تا تاریخ شمسی','log_action'=>'کد عملیات (اختیاری)'] as $key=>$label)echo '<label>'.$label.'<input name="'.$key.'" value="'.esc_attr($input[$key]??'').'" '.(in_array($key,['batch_id','actor_id'],true)?'inputmode="numeric"':'').'></label>';
        echo '<button class="sn-btn sn-btn-primary">نمایش لاگ‌ها</button></form><div class="sn-mis-table-scroll"><table><thead><tr><th>زمان / پیگیری</th><th>اپراتور</th><th>عملیات / پرونده</th><th>خلاصه و دلیل</th><th>جزئیات</th></tr></thead><tbody>';
        foreach($rows as $row){$log=(object)$row;echo '<tr><td>'.esc_html($this->sn_mis_v2_time($row['created_at'])).'<small>#'.(int)$row['id'].'</small></td><td>'.esc_html($this->sn_user_label((int)$row['actor_user_id'])).'</td><td>'.esc_html($this->sn_mis_action_label($row['action'])).'<small>پرونده #'.(int)$row['batch_id'].'</small></td><td>'.esc_html($this->sn_mis_log_summary($log)).'<small>'.esc_html($row['reason']??'').'</small></td><td><details><summary>جزئیات فنی</summary><pre>'.esc_html($this->sn_mis_safe_json(['old'=>$row['old_value']??null,'new'=>$row['new_value']??null])).'</pre></details></td></tr>';}
        if(!$rows)echo '<tr><td colspan="5" class="sn-mis-empty">لاگی مطابق فیلتر پیدا نشد. بازه یا شناسه پرونده را تغییر دهید.</td></tr>';
        echo '</tbody></table></div>';$this->sn_mis_management_pager($total,$offset,'logs',array_intersect_key($input,array_flip(['batch_id','actor_id','log_action','from','to'])),20);echo '</section>';
        foreach(['sn_mis_lead_pool_report'=>'گزارش فنی آماده‌سازی مخزن','sn_mis_distribution_plan_report'=>'گزارش فنی برنامه توزیع','sn_mis_lead_creation_report'=>'گزارش فنی ساخت لید'] as $key=>$label){$report=get_option($key,[]);if($report)echo '<details class="sn-mis-card"><summary>'.esc_html($label).'</summary><pre>'.esc_html($this->sn_mis_safe_json($report)).'</pre></details>';}
    }
}
