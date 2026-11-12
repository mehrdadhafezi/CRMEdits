<?php
if (!defined('ABSPATH')) { exit; }

/** Per-account permission for the four classic MIS tools; never grants WordPress administration. */
trait SN_MIS_User_Tools {
    private int $sn_mis_tools_profile_user=0;
    private function sn_mis_can_use_advanced_tools(): bool {
        if(current_user_can('manage_options'))return true;
        return $this->sn_can_access_mis_panel() && (string)get_user_meta(get_current_user_id(),'sn_mis_advanced_tools_access',true)==='1';
    }
    private function sn_mis_user_tools_hooks(): void {
        add_action('edit_user_profile',[$this,'sn_mis_user_tools_profile']);
        add_action('show_user_profile',[$this,'sn_mis_user_tools_profile']);
        add_action('admin_post_sn_mis_user_tools_access',[$this,'handle_mis_user_tools_access']);
        add_action('admin_footer',[$this,'sn_mis_user_tools_profile_form']);
    }
    public function sn_mis_user_tools_profile($user): void {
        if(!($user instanceof WP_User) || !current_user_can('manage_options') || !current_user_can('edit_user',$user->ID))return;
        $granted=(string)get_user_meta($user->ID,'sn_mis_advanced_tools_access',true)==='1';
        if($this->sn_hr_position_for_user((int)$user->ID)!=='mis' && !$granted)return;
        $this->sn_mis_tools_profile_user=(int)$user->ID;
        echo '<h2>دسترسی اختصاصی ابزارهای MIS</h2><table class="form-table"><tr><th>چهار ابزار پیشرفته</th><td>';
        echo '<label><input type="checkbox" form="sn-mis-user-tools-access-form" name="sn_mis_user_tools_enabled" value="1" '.checked($granted,true,false).'> مشاهده و اجرای ابزار سریع، برنامه پخش، ساخت لید و مشاهده لاگ MIS برای همین حساب</label>';
        echo '<p><button type="submit" form="sn-mis-user-tools-access-form" class="button button-primary">ذخیره دسترسی ابزارهای MIS</button></p>';
        echo '<p class="description">برای ثبت همین مجوز از دکمه بالا استفاده کنید؛ نقش‌ها و اطلاعات دیگر حساب تغییر نمی‌کنند. این مجوز فقط برای حساب MIS فعال معتبر است. دسترسی کامل وردپرس، تغییر کاربران، تنظیمات مالی، راه‌اندازی ساختار و بایگانی مدیریتی را اضافه نمی‌کند.</p></td></tr></table>';
    }
    public function sn_mis_user_tools_profile_form(): void {
        $user_id=$this->sn_mis_tools_profile_user;
        if(!$user_id || !current_user_can('manage_options') || !current_user_can('edit_user',$user_id))return;
        echo '<form id="sn-mis-user-tools-access-form" method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="sn_mis_user_tools_access"><input type="hidden" name="user_id" value="'.(int)$user_id.'">';
        wp_nonce_field('sn_mis_user_tools_'.$user_id,'sn_mis_user_tools_nonce');echo '</form>';
    }
    public function handle_mis_user_tools_access(): void {
        if(!current_user_can('manage_options') || ($_SERVER['REQUEST_METHOD']??'')!=='POST')wp_die('دسترسی غیرمجاز','MIS',['response'=>403]);
        $user_id=absint($_POST['user_id']??0);
        if(!$user_id || !current_user_can('edit_user',$user_id))wp_die('حساب مقصد مجاز نیست.','MIS',['response'=>403]);
        $nonce=is_scalar($_POST['sn_mis_user_tools_nonce']??null)?sanitize_text_field(wp_unslash($_POST['sn_mis_user_tools_nonce'])):'';
        if(!wp_verify_nonce($nonce,'sn_mis_user_tools_'.$user_id))wp_die('اعتبار صفحه تمام شده است؛ صفحه را دوباره باز کنید.','MIS',['response'=>403]);
        $enabled=isset($_POST['sn_mis_user_tools_enabled']) && is_scalar($_POST['sn_mis_user_tools_enabled']) && (string)$_POST['sn_mis_user_tools_enabled']==='1';
        if($enabled && $this->sn_hr_position_for_user($user_id)!=='mis')wp_die('این مجوز فقط برای حساب دارای سمت MIS است.','MIS',['response'=>403]);
        $this->sn_mis_user_tools_save($user_id);
        if((string)get_user_meta($user_id,'sn_mis_advanced_tools_access',true)!==($enabled?'1':'0'))wp_die('مجوز ذخیره نشد؛ دوباره بررسی کنید.','MIS',['response'=>500]);
        wp_safe_redirect(add_query_arg(['user_id'=>$user_id,'sn_mis_tools_saved'=>1],admin_url('user-edit.php')));exit;
    }
    public function sn_mis_user_tools_save(int $user_id): void {
        if(!isset($_POST['sn_mis_user_tools_nonce']))return;
        if(!current_user_can('manage_options') || !current_user_can('edit_user',$user_id))return;
        $nonce=is_scalar($_POST['sn_mis_user_tools_nonce'])?sanitize_text_field(wp_unslash($_POST['sn_mis_user_tools_nonce'])):'';
        if(!wp_verify_nonce($nonce,'sn_mis_user_tools_'.$user_id))return;
        $enabled=isset($_POST['sn_mis_user_tools_enabled']) && is_scalar($_POST['sn_mis_user_tools_enabled']) && (string)$_POST['sn_mis_user_tools_enabled']==='1';
        if($enabled && $this->sn_hr_position_for_user($user_id)!=='mis')return;
        $value=$enabled?'1':'0';$old=(string)get_user_meta($user_id,'sn_mis_advanced_tools_access',true);
        if($old===$value)return;
        update_user_meta($user_id,'sn_mis_advanced_tools_access',$value);
        if((string)get_user_meta($user_id,'sn_mis_advanced_tools_access',true)!==$value)return;
        $history=get_user_meta($user_id,'sn_mis_advanced_tools_audit',true);if(!is_array($history))$history=[];
        $history[]=['enabled'=>$enabled,'actor_user_id'=>get_current_user_id(),'updated_at'=>gmdate('Y-m-d H:i:s')];
        update_user_meta($user_id,'sn_mis_advanced_tools_audit',array_slice($history,-50));
    }
}
