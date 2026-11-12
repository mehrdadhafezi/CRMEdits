<?php
if (!defined('ABSPATH')) { exit; }

/** Classic MIS presentation over the existing guarded operations and stored history. */
trait SN_MIS_Classic {
    private function sn_mis_classic_tabs(): array {
        $tabs=['mis-overview','mis-import','mis-batches-list','mis-pool','mis-preview','mis-batches'];
        if($this->sn_mis_can_use_advanced_tools())$tabs=array_merge($tabs,['mis-quick','mis-distribution','mis-live','mis-logs']);
        return $tabs;
    }
    private function sn_mis_classic_tab_class(string $id): string {
        return 'sn-tab-panel'.($id===$this->sn_requested_ui_active_tab()?' sn-tab-panel-active':'');
    }
    public function render_mis_panel(): string {
        if(!is_user_logged_in())return $this->render_unified_panel_login();
        if(!$this->sn_can_access_mis_panel())return SN_Helpers::panel_access_denied();
        $tab=$this->sn_requested_ui_active_tab();
        $page=is_scalar($_GET['sn_mis_page']??null)?sanitize_key($_GET['sn_mis_page']):'';
        if($page!=='') {
            $tab=['dashboard'=>'mis-overview','upload'=>'mis-import','return'=>'mis-batches-list','reports'=>'mis-preview','cases'=>'mis-batches','plans'=>'mis-distribution','rules'=>'mis-distribution','logs'=>'mis-logs','imports'=>'mis-import','tools'=>'mis-overview','health'=>'mis-logs','branches'=>'mis-batches'][$page]??'mis-overview';
            if($page==='upload' && ($_GET['section']??'')==='assign')$tab='mis-batches-list';
        }
        $token=is_scalar($_GET['sn_mis_preview']??null)?sanitize_text_field($_GET['sn_mis_preview']):'';
        $preview=null;$preview_error='';
        if($token!=='') {
            try {
                $preview=$this->sn_mis_v2_get_preview($token);
                $tab=in_array($preview['type'],['assign','return'],true)?'mis-batches-list':'mis-import';
                if(!empty($preview['data']['batch_id']))$_GET['sn_mis_batch_id']=(int)$preview['data']['batch_id'];
            }catch(Throwable $e){$preview_error=$e->getMessage();}
        }
        if(!in_array($tab,$this->sn_mis_classic_tabs(),true))$tab='mis-overview';
        $_GET['sn_ui_active_tab']=$tab;
        if(empty($_GET['sn_mis_batch_id']) && !empty($_GET['batch_id']) && is_scalar($_GET['batch_id']))$_GET['sn_mis_batch_id']=absint($_GET['batch_id']);
        if(empty($_GET['sn_mis_plan_id']) && !empty($_GET['plan_id']) && is_scalar($_GET['plan_id']))$_GET['sn_mis_plan_id']=absint($_GET['plan_id']);
        unset($_GET['sn_mis_advanced']);
        ob_start();
        $notice=is_scalar($_GET['sn_mis_notice']??null)?sanitize_key($_GET['sn_mis_notice']):'';
        if(preg_match('/^[a-f0-9]{24}$/',$notice)) {
            $key='sn_mis_notice_'.get_current_user_id().'_'.$notice;$report=get_transient($key);
            if(is_array($report)){$this->sn_mis_feedback_markup($report);delete_transient($key);}
        }
        if($preview_error!=='')$this->sn_mis_feedback_markup(['error'=>$preview_error]);
        if($preview!==null)$this->sn_mis_v2_render_preview($token);
        if(!$this->sn_mis_v2_service()->ready())$this->sn_mis_setup_card();
        $flow=(string)ob_get_clean();
        if($flow!=='') {
            $flow='<div class="sn-mis-v2 sn-mis-classic-flow" dir="rtl">'.$flow.'</div>';
            $flow='<link rel="stylesheet" href="'.esc_url(SN_PLUGIN_URL.'assets/css/mis-portal.css?ver='.SN_VERSION).'">'.$flow;
        }
        $html=$this->render_mis_panel_legacy();
        if($flow!=='')$html=preg_replace_callback('/(<section id="'.preg_quote($tab,'/').'"[^>]*>)/',static fn($m)=>$m[1].$flow,$html,1)??$html;
        return $html.'<script src="'.esc_url(SN_PLUGIN_URL.'assets/js/mis-classic.js?ver='.SN_VERSION).'" defer></script>';
    }
}
