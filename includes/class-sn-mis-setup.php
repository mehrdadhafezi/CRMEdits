<?php
if (!defined('ABSPATH')) { exit; }

/** Browser setup never alters, indexes, normalizes or scans an operational table. */
final class SN_MIS_Setup {
    private $db;
    public function __construct($db) { $this->db=$db; }
    private function table_names(): array { return ['mis_record_meta','mis_events','mis_claims','mis_row_index','mis_index_dirty','mis_jobs','mis_job_rows','mis_import_runs']; }
    public function status(): array {
        $saved=get_option('sn_mis_setup_v4',[]);
        return ['ready'=>!empty($saved['ready']) && (int)($saved['completed']??0)===8,'completed'=>(int)($saved['completed']??0),'total'=>8,'message'=>(string)($saved['message']??'راه‌اندازی بدون تغییر شماره‌ها و سوابق')];
    }
    public function step(): array {
        $lock='sn_mis_setup_'.substr(hash('sha256',$this->db->prefix),0,40);
        if ((int)$this->db->get_var($this->db->prepare('SELECT GET_LOCK(%s,0)',$lock))!==1) { return $this->status()+['busy'=>true]; }
        $timeout=(int)$this->db->get_var('SELECT @@SESSION.lock_wait_timeout');
        try {
            $this->db->query('SET SESSION lock_wait_timeout=2');
            $schemas=SN_MIS_Service::schema($this->db->prefix,$this->db->get_charset_collate());$completed=0;$created=false;
            foreach($this->table_names() as $i=>$name) {
                $table=$this->db->prefix.'sn_'.$name;
                $exists=$this->db->get_var($this->db->prepare('SHOW TABLES LIKE %s',$this->db->esc_like($table)));
                if($this->db->last_error!=='')throw new RuntimeException('بررسی ساختار کامل نشد؛ همین مرحله را دوباره اجرا کنید.');
                if(!$exists) {
                    if($created)break;
                    if($this->db->query($schemas[$i])===false)throw new RuntimeException('ساخت جدول تکمیلی کامل نشد؛ مجوز ساخت جدول یا قفل دیتابیس را در سلامت سامانه بررسی کنید.');
                    $created=true;
                }
                $completed++;
            }
            $state=['ready'=>$completed===8,'completed'=>$completed,'message'=>$completed===8?'راه‌اندازی کامل شد؛ داده‌ها و تاریخچه حفظ شدند.':'مرحله '.$completed.' از ۸ ثبت شد.'];
            update_option('sn_mis_setup_v4',$state,false);if($state['ready'])delete_transient('sn_mis_coverage_'.substr(hash('sha256',$this->db->prefix),0,24));return $state+['total'=>8];
        }finally {
            if($timeout>0)$this->db->query('SET SESSION lock_wait_timeout='.$timeout);
            $this->db->get_var($this->db->prepare('SELECT RELEASE_LOCK(%s)',$lock));
        }
    }
}
