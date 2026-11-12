<?php
if (!defined('ABSPATH')) { exit; }

/** One durable receipt for every file attempt; no uploaded customer file is retained. */
final class SN_MIS_Import_Log {
    private $db;
    private int $actor;
    public function __construct($db,int $actor=0) { $this->db=$db;$this->actor=$actor; }
    private function table(): string { return $this->db->prefix.'sn_mis_import_runs'; }
    private function check($result) { if($result===false || $this->db->last_error!=='')throw new RuntimeException('ثبت سابقه فایل کامل نشد؛ عملیات را پس از بررسی نتیجه دوباره انجام دهید.');return $result; }
    public static function schema(string $p,string $charset): array { return [
        "CREATE TABLE IF NOT EXISTS {$p}sn_mis_import_runs (
            id bigint unsigned NOT NULL AUTO_INCREMENT,batch_id bigint unsigned NOT NULL,
            batch_code varchar(120) NOT NULL,actor_user_id bigint unsigned NOT NULL,
            filename varchar(191) NOT NULL,file_sha256 char(64) NOT NULL DEFAULT '',file_bytes bigint unsigned NOT NULL DEFAULT 0,
            status varchar(24) NOT NULL DEFAULT 'received',total_rows int unsigned NOT NULL DEFAULT 0,
            valid_rows int unsigned NOT NULL DEFAULT 0,invalid_rows int unsigned NOT NULL DEFAULT 0,duplicate_rows int unsigned NOT NULL DEFAULT 0,
            imported_rows int unsigned NOT NULL DEFAULT 0,unchanged_rows int unsigned NOT NULL DEFAULT 0,
            report_json longtext,error_message text,created_at datetime NOT NULL,updated_at datetime NOT NULL,
            PRIMARY KEY(id),KEY batch_time(batch_id,created_at,id),KEY status_time(status,created_at),KEY actor_time(actor_user_id,created_at)
        ) ENGINE=InnoDB {$charset}"
    ]; }
    public function ready(): bool {
        $engine=$this->db->get_var($this->db->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$this->table()));
        return $this->db->last_error==='' && strcasecmp((string)$engine,'InnoDB')===0;
    }
    public function start(int $batch,string $filename,int $bytes=0,string $sha=''): int {
        if(!$this->ready())throw new RuntimeException('برای ثبت سابقه فایل، راه‌اندازی ابزارهای تکمیلی را ادامه دهید.');
        $header=$this->db->get_row($this->db->prepare('SELECT batch_code,import_status FROM '.$this->db->prefix.'sn_mis_import_batches WHERE id=%d',$batch),ARRAY_A);$this->check($header);
        if(!$header || in_array($header['import_status'],['archived','deleted','file_deleted','reset_deleted'],true))throw new RuntimeException('پرونده فعال انتخاب کنید؛ کد فایل از همان پرونده دریافت می‌شود.');
        $this->check($this->db->insert($this->table(),['batch_id'=>$batch,'batch_code'=>$header['batch_code'],'actor_user_id'=>$this->actor,'filename'=>mb_substr(sanitize_file_name($filename),0,191),'file_sha256'=>preg_match('/^[a-f0-9]{64}$/',$sha)?$sha:'','file_bytes'=>max(0,$bytes),'status'=>'received','created_at'=>gmdate('Y-m-d H:i:s'),'updated_at'=>gmdate('Y-m-d H:i:s')]));
        return (int)$this->db->insert_id;
    }
    public function get(int $id,bool $lock=false): array {
        $row=$this->db->get_row($this->db->prepare('SELECT * FROM '.$this->table().' WHERE id=%d AND actor_user_id=%d'.($lock?' FOR UPDATE':''),$id,$this->actor),ARRAY_A);$this->check($row);
        if(!$row)throw new RuntimeException('سابقه فایل متعلق به شما پیدا نشد؛ فایل را دوباره انتخاب کنید.');return $row;
    }
    public function progress(int $id,string $status,array $report=[],string $message=''): void {
        $current=$this->get($id);if($current['status']==='completed')return;
        if(!in_array($status,['mapping','preview','completed','failed'],true))throw new RuntimeException('وضعیت فایل معتبر نیست.');
        $data=['status'=>$status,'updated_at'=>gmdate('Y-m-d H:i:s'),'error_message'=>mb_substr(sanitize_text_field($message),0,1000)];
        foreach(['seen'=>'total_rows','valid'=>'valid_rows','invalid'=>'invalid_rows','duplicate'=>'duplicate_rows','imported'=>'imported_rows','unchanged'=>'unchanged_rows'] as $key=>$column)if(isset($report[$key]))$data[$column]=max(0,(int)$report[$key]);
        if($report)$data['report_json']=wp_json_encode($report,JSON_UNESCAPED_UNICODE);
        $this->check($this->db->update($this->table(),$data,['id'=>$id,'actor_user_id'=>$this->actor]));
    }
    private function filter(array $filters): array {
        $where=['1=1'];$args=[];
        if(!empty($filters['batch_id'])){$where[]='f.batch_id=%d';$args[]=(int)$filters['batch_id'];}
        if(!empty($filters['campaign'])){$where[]='b.campaign_code=%s';$args[]=$filters['campaign'];}
        if(!empty($filters['actor_id'])){$where[]='f.actor_user_id=%d';$args[]=(int)$filters['actor_id'];}
        if(!empty($filters['file_status']) && in_array($filters['file_status'],['received','mapping','preview','completed','failed'],true)){$where[]='f.status=%s';$args[]=$filters['file_status'];}
        foreach(['from'=>'>=','to'=>'<'] as $key=>$operator)if(!empty($filters[$key])){$where[]='f.created_at'.$operator.'%s';$args[]=$filters[$key];}
        return [' FROM '.$this->table().' f LEFT JOIN '.$this->db->prefix.'sn_mis_import_batches b ON b.id=f.batch_id WHERE '.implode(' AND ',$where),$args];
    }
    public function summary(array $filters=[]): array {
        if(!$this->ready())return ['ready'=>false];[$tail,$args]=$this->filter($filters);
        $q="SELECT COUNT(*) attempts,SUM(f.status='completed') completed,SUM(f.status='failed') failed,SUM(f.status IN ('received','mapping','preview')) pending,SUM(f.invalid_rows) invalid_rows,SUM(f.duplicate_rows) duplicate_rows".$tail;
        $row=$this->db->get_row($args?$this->db->prepare($q,...$args):$q,ARRAY_A);$this->check($row);
        return ['ready'=>true]+array_map('intval',$row?:[]);
    }
    public function query(array $filters=[],int $offset=0,int $limit=20): array {
        if(!$this->ready())return ['total'=>0,'rows'=>[]];[$tail,$args]=$this->filter($filters);
        $count=$this->db->get_var($args?$this->db->prepare('SELECT COUNT(*)'.$tail,...$args):'SELECT COUNT(*)'.$tail);$this->check($count);
        $rows=$this->db->get_results($this->db->prepare('SELECT f.*,b.title batch_title,b.campaign_code'.$tail.' ORDER BY f.id DESC LIMIT %d OFFSET %d',...array_merge($args,[max(1,min(100,$limit)),max(0,$offset)])),ARRAY_A);$this->check($rows);
        return ['total'=>(int)$count,'rows'=>(array)$rows];
    }
}
