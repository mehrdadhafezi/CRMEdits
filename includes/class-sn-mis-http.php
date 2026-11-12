<?php
if (!defined('ABSPATH')) { exit; }

/** Isolated MIS transport: no unrelated CRM migrations, page repair or runtime installers. */
final class SN_MIS_HTTP {
    public static function is_request(): bool {
        $raw=$_REQUEST['action']??'';$action=is_scalar($raw)?sanitize_key((string)$raw):'';
        return in_array($action,['sn_mis_user_tools_access','sn_distribution_deliver_pool','sn_mis_setup','sn_mis_view','sn_mis_job','sn_mis_download','sn_mis_v2','sn_mis_create_batch','sn_mis_import_csv','sn_mis_assign_batch','sn_mis_return_assignments','sn_mis_quick_assign_data','sn_mis_prepare_lead_pool','sn_mis_create_distribution_rule','sn_mis_create_distribution_plan','sn_mis_approve_distribution_plan','sn_mis_create_live_leads_from_plan','sn_mis_archive_batch','sn_mis_delete_batch_data','sn_mis_cleanup_deleted_traces'],true);
    }
    public static function portal_template(string $template): string {
        if(is_admin() || !is_singular('page'))return $template;
        $post=get_queried_object();
        if(!($post instanceof WP_Post) || !has_shortcode($post->post_content,'sn_mis_panel'))return $template;
        if(!apply_filters('sn_mis_use_portal_template',true,$post))return $template;
        return dirname(__DIR__).'/templates/mis-portal.php';
    }
    public static function register(): void {
        foreach(['class-sn-helpers.php','class-sn-migration-service.php','class-sn-hr-service.php','class-sn-hierarchy-service.php','class-sn-scope-service.php','class-sn-plugin.php'] as $file)require_once __DIR__.'/'.$file;
        if(($_REQUEST['action']??'')==='sn_mis_create_live_leads_from_plan')require_once __DIR__.'/class-sn-campaign-tracking.php';
        $plugin=new SN_Plugin();$plugin->register_mis_transport();
    }
}
