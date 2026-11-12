<?php
if (!defined('ABSPATH')) { exit; }
require_once __DIR__.'/class-sn-mis-setup.php';
trait SN_MIS_Transport {
    public function handle_mis_setup(): void {
        if(!current_user_can('manage_options') || !$this->sn_can_access_mis_panel())wp_send_json_error(['message'=>'فقط مدیرکل می‌تواند راه‌اندازی را اجرا کند.'],403);
        if(!check_ajax_referer('sn_mis_setup','nonce',false))wp_send_json_error(SN_MIS_Feedback::session(),403);if(($_SERVER['REQUEST_METHOD']??'')!=='POST')wp_send_json_error(['message'=>'روش درخواست معتبر نیست.'],405);
        try { global $wpdb;wp_send_json_success((new SN_MIS_Setup($wpdb))->step()); }
        catch(Throwable $e) { wp_send_json_error(SN_MIS_Feedback::error($e),409); }
    }
    public function handle_mis_view(): void {
        if(!$this->sn_can_access_mis_panel())wp_send_json_error(['message'=>'دسترسی غیرمجاز'],403);
        if(!check_ajax_referer('sn_mis_view','nonce',false))wp_send_json_error(SN_MIS_Feedback::session(),403);if(($_SERVER['REQUEST_METHOD']??'')!=='POST')wp_send_json_error(['message'=>'روش درخواست معتبر نیست.'],405);
        $params=[];parse_str(is_string($_POST['view_params']??null)?wp_unslash($_POST['view_params']):'',$params);
        $allowed=['sn_mis_page','sn_mis_advanced','sn_mis_batch_id','sn_mis_plan_id','sn_ui_active_tab','batch_id','manager_id','campaign','branch','search','state','consumed','date_type','event_type','from','to','report_mode','batch_search','selection','sn_mis_offset','row_id','case_id','plan_id','after_id','pool_cursor','section','sn_mis_preview','sn_mis_notice','file_status','actor_id','log_action','load_saved','saved_report','page_id'];
        $_GET=[];foreach($allowed as $key)if(isset($params[$key]) && is_scalar($params[$key]))$_GET[$key]=sanitize_text_field($params[$key]);
        $return=is_string($_POST['return_url']??null)?esc_url_raw(wp_unslash($_POST['return_url'])):'';
        $this->sn_mis_base_url=wp_validate_redirect($return,home_url('/'));
        nocache_headers();$start=microtime(true);$html=$this->render_mis_panel();
        wp_send_json_success(['html'=>$html,'elapsed_ms'=>(int)round(1000*(microtime(true)-$start))]);
    }
    private function sn_mis_setup_card(): void {
        echo '<section class="sn-mis-card sn-mis-setup" data-setup-api="'.esc_url(admin_url('admin-ajax.php')).'" data-setup-nonce="'.esc_attr(wp_create_nonce('sn_mis_setup')).'"><div class="sn-mis-section-heading"><div><span class="sn-mis-eyebrow">آماده‌سازی پرتال</span><h3>راه‌اندازی کوتاه و قابل ادامه</h3><p>شماره‌ها، تخصیص‌ها و تاریخچه قبلی حفظ می‌شوند.</p></div><span class="sn-mis-badge">۸ مرحله کوچک</span></div><ol class="sn-mis-steps"><li>بررسی ساختار</li><li>افزودن بخش‌های تکمیلی</li><li>آماده برای کار</li></ol><p class="sn-mis-setup-status" role="status">با یک کلیک شروع کنید؛ پیشرفت همین‌جا نمایش داده می‌شود.</p><progress max="8" value="0" hidden></progress>';
        if(current_user_can('manage_options'))echo '<button type="button" class="sn-mis-setup-run sn-btn sn-btn-primary">راه‌اندازی ابزارهای تکمیلی</button>';
        else echo '<p>مدیرکل می‌تواند این مرحله را اجرا کند.</p>';
        echo '</section>';
    }
}
