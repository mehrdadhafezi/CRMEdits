<?php
if (!defined('ABSPATH')) { exit; }
require_once __DIR__.'/class-sn-mis-xlsx.php';

/** Resumable jobs. Only additive derived tables and private temporary files are written. */
final class SN_MIS_Jobs {
    private $db;
    private int $actor;
    public function __construct($db,int $actor) { $this->db=$db;$this->actor=$actor; }
    private function t(string $name): string { return $this->db->prefix.'sn_'.$name; }
    private function check($result) { if ($result===false || $this->db->last_error!=='') { throw new RuntimeException('ذخیره مرحله پردازش ممکن نشد؛ دوباره تلاش کنید.'); } return $result; }
    public static function schema(string $p,string $charset): array { return [
        "CREATE TABLE IF NOT EXISTS {$p}sn_mis_jobs (id bigint unsigned NOT NULL AUTO_INCREMENT, owner_id bigint unsigned NOT NULL, kind varchar(20) NOT NULL, status varchar(20) NOT NULL DEFAULT 'queued', payload longtext, cursor_id bigint unsigned NOT NULL DEFAULT 0, total bigint unsigned NOT NULL DEFAULT 0, processed bigint unsigned NOT NULL DEFAULT 0, result_json longtext, created_at datetime NOT NULL, updated_at datetime NOT NULL, expires_at datetime NOT NULL, PRIMARY KEY(id), KEY owner_status(owner_id,status), KEY expires(expires_at)) ENGINE=InnoDB {$charset}",
        "CREATE TABLE IF NOT EXISTS {$p}sn_mis_job_rows (job_id bigint unsigned NOT NULL, cursor_id bigint unsigned NOT NULL, row_id bigint unsigned NOT NULL, event_id bigint unsigned NOT NULL DEFAULT 0, PRIMARY KEY(job_id,cursor_id)) ENGINE=InnoDB {$charset}"
    ]; }
    public function ready(): bool { foreach(['mis_jobs','mis_job_rows'] as $n) { if (!$this->db->get_var($this->db->prepare('SHOW TABLES LIKE %s',$this->db->esc_like($this->t($n))))) { return false; } }return true; }
    private function directory(): string {
        $base=realpath(sys_get_temp_dir());$web=realpath(ABSPATH);$doc=realpath(!empty($_SERVER['DOCUMENT_ROOT'])?$_SERVER['DOCUMENT_ROOT']:ABSPATH);
        if (!$base || ($web && str_starts_with($base.'/',rtrim($web,'/').'/')) || ($doc && str_starts_with($base.'/',rtrim($doc,'/').'/'))) { throw new RuntimeException('مسیر موقت خصوصی خارج از پوشه وب برای خروجی لازم است.'); }
        $dir=$base.'/sn-mis-'.substr(hash_hmac('sha256',$this->db->prefix,wp_salt('auth')),0,24);
        if (!is_dir($dir) && !@mkdir($dir,0700)) { throw new RuntimeException('مسیر خروجی خصوصی ساخته نشد.'); }@chmod($dir,0700);return $dir;
    }
    private function paths(array $job): array { $key=hash_hmac('sha256',(string)$job['id'].'|'.$job['created_at'],wp_salt('auth'));$base=$this->directory().'/'.$key;return [$base.'.xml',$base.'.xlsx']; }
    public function get(int $id): array {
        $job=$this->db->get_row($this->db->prepare('SELECT * FROM '.$this->t('mis_jobs').' WHERE id=%d AND owner_id=%d',$id,$this->actor),ARRAY_A);
        $this->check($job);if (!$job) { throw new RuntimeException('پردازش متعلق به شما پیدا نشد.'); }
        if ($job['expires_at']<gmdate('Y-m-d H:i:s')) { throw new RuntimeException('پردازش منقضی شده است؛ دوباره بسازید.'); }return $job;
    }
    public function recent(): array { if (!$this->ready())return [];return (array)$this->db->get_results($this->db->prepare('SELECT id,kind,status,total,processed,created_at,result_json FROM '.$this->t('mis_jobs').' WHERE owner_id=%d AND expires_at>%s ORDER BY id DESC LIMIT 10',$this->actor,gmdate('Y-m-d H:i:s')),ARRAY_A); }
    public function start(string $kind,array $filters=[],bool $history=false): int {
        if (!in_array($kind,['index','audit','export'],true) || !$this->ready() || !(new SN_MIS_Read_Model($this->db))->ready()) { throw new RuntimeException('ابتدا ساختار ابزارهای تکمیلی را راه‌اندازی کنید.'); }
        $active=(int)$this->db->get_var($this->db->prepare('SELECT COUNT(*) FROM '.$this->t('mis_jobs')." WHERE owner_id=%d AND status IN ('queued','running') AND expires_at>%s",$this->actor,gmdate('Y-m-d H:i:s')));
        if ($active>=3) { throw new RuntimeException('حداکثر سه پردازش همزمان؛ ابتدا یکی را کامل یا لغو کنید.'); }
        $ceiling=(int)$this->db->get_var('SELECT COALESCE(MAX(id),0) FROM '.$this->t('mis_data_rows'));
        $total=(int)$this->db->get_var('SELECT COUNT(*) FROM '.$this->t('mis_data_rows'));
        $payload=['filters'=>$filters,'history'=>$history,'ceiling'=>$ceiling,'checkpoint'=>null];
        $this->check($this->db->insert($this->t('mis_jobs'),['owner_id'=>$this->actor,'kind'=>$kind,'status'=>'queued','payload'=>wp_json_encode($payload),'total'=>$total,'created_at'=>gmdate('Y-m-d H:i:s'),'updated_at'=>gmdate('Y-m-d H:i:s'),'expires_at'=>gmdate('Y-m-d H:i:s',time()+DAY_IN_SECONDS)]));$id=(int)$this->db->insert_id;
        try {
            if ($kind==='export') {
                if ((!empty($filters['state']) || !empty($filters['consumed']) || !empty($filters['branch'])) && (get_option('sn_mis_index_needs_rebuild',0) || (new SN_MIS_Read_Model($this->db))->coverage()['pending']>0)) { throw new RuntimeException('برای خروجی وابسته به وضعیت یا شعبه، ابتدا آماده‌سازی آمار را کامل کنید.'); }
                $total=(new SN_MIS_Service($this->db,$this->actor))->freeze_export($id,$filters,$history);
                if ($total>1048575) { throw new RuntimeException('بیش از سقف Excel؛ فیلتر گزارش را محدود کنید.'); }
                $this->check($this->db->update($this->t('mis_jobs'),['total'=>$total],['id'=>$id]));
            }
        } catch(Throwable $e) { $this->db->delete($this->t('mis_job_rows'),['job_id'=>$id]);$this->db->update($this->t('mis_jobs'),['status'=>'failed','result_json'=>wp_json_encode(['message'=>$e->getMessage()])],['id'=>$id]);throw $e; }
        return $id;
    }
    public function cancel(int $id): void {
        $job=$this->get($id);$lock=$this->lock_name($id);
        if ((int)$this->db->get_var($this->db->prepare('SELECT GET_LOCK(%s,0)',$lock))!==1)throw new RuntimeException('مرحله جاری در حال پایان است؛ دوباره تلاش کنید.');
        try { $this->check($this->db->update($this->t('mis_jobs'),['status'=>'cancelled','updated_at'=>gmdate('Y-m-d H:i:s')],['id'=>$id]));$this->db->query($this->db->prepare('DELETE FROM '.$this->t('mis_job_rows').' WHERE job_id=%d LIMIT 1000',$id));if ($job['kind']==='export') { foreach($this->paths($job) as $p) { if(is_file($p))unlink($p); } } }finally { $this->db->get_var($this->db->prepare('SELECT RELEASE_LOCK(%s)',$lock)); }
    }
    private function lock_name(int $id): string { return 'sn_mis_job_'.substr(hash('sha256',$this->db->prefix.'|'.$id),0,45); }
    public function step(int $id,?callable $format=null): array {
        $lock=$this->lock_name($id);
        if ((int)$this->db->get_var($this->db->prepare('SELECT GET_LOCK(%s,0)',$lock))!==1) { return $this->public_status($this->get($id)); }
        try {
            $job=$this->get($id);if (!in_array($job['status'],['queued','running'],true))return $this->public_status($job);
            $payload=json_decode($job['payload'],true);$result=json_decode($job['result_json']??'',true)?:[];$model=new SN_MIS_Read_Model($this->db);$limit=max(10,min(100,(int)($payload['batch_size']??100)));$started=microtime(true);
            if ($job['kind']==='export') {
                if (!$format)throw new RuntimeException('قالب خروجی در دسترس نیست.');
                [$sheet,$zip]=$this->paths($job);$writer=new SN_MIS_XLSX(self::headers(),$sheet,$payload['checkpoint']);
                $selected=(array)$this->db->get_results($this->db->prepare('SELECT * FROM '.$this->t('mis_job_rows').' WHERE job_id=%d AND cursor_id>%d ORDER BY cursor_id LIMIT %d',$id,(int)$job['cursor_id'],$limit),ARRAY_A);$this->check($selected);
                foreach((new SN_MIS_Service($this->db,$this->actor))->export_selection_rows($selected,(bool)$payload['history']) as $row) { $writer->add($format($row,(bool)$payload['history'])); }
                $processed=(int)$job['processed']+count($selected);$cursor=$selected?(int)end($selected)['cursor_id']:(int)$job['cursor_id'];$payload['checkpoint']=$writer->checkpoint();
                $done=$processed>=(int)$job['total'];
                if ($done) { $writer->finish($zip);$result=['message'=>'انتخاب ردیف‌ها هنگام شروع ثابت شد؛ مقادیر طی مراحل خروجی خوانده شده‌اند.','started_at'=>$job['created_at'],'finished_at'=>gmdate('Y-m-d H:i:s')]; }
            } else {
                $rows=(array)$this->db->get_results($this->db->prepare('SELECT * FROM '.$this->t('mis_data_rows').' WHERE id>%d AND id<=%d ORDER BY id LIMIT %d',(int)$job['cursor_id'],(int)$payload['ceiling'],$limit),ARRAY_A);$this->check($rows);
                $facts=$job['kind']==='index'?$model->refresh_ids(array_column($rows,'id'),true):$model->classify($rows);
                if ($job['kind']==='audit') {
                    $old=[];$rids=array_map('intval',array_keys($facts));if($rids) { foreach((array)$this->db->get_results('SELECT row_id,logical_state FROM '.$this->t('mis_row_index').' WHERE row_id IN ('.implode(',',$rids).')',ARRAY_A) as $cached)$old[(int)$cached['row_id']]=$cached['logical_state']; }
                    foreach($facts as $rid=>$fact) { if(!isset($old[$rid]) || $old[$rid]!==$fact['logical_state']) { $result['counts']['index_mismatch']=($result['counts']['index_mismatch']??0)+1;if(count($result['samples']['index_mismatch']??[])<20)$result['samples']['index_mismatch'][]=$rid; } foreach($fact['_flags'] as $name=>$flag) { if (!$flag)continue;$result['counts'][$name]=($result['counts'][$name]??0)+1;if(count($result['samples'][$name]??[])<20)$result['samples'][$name][]=$rid; }$result['states'][$fact['logical_state']]=($result['states'][$fact['logical_state']]??0)+1; }
                    $result['message']='ممیزی فقط خواندنی است؛ نمونه‌ها شناسه داخلی‌اند. داده عملیاتی تغییر نکرد.';
                }
                $processed=(int)$job['processed']+count($rows);$cursor=$rows?(int)end($rows)['id']:(int)$job['cursor_id'];$done=count($rows)<$limit || $cursor>=(int)$payload['ceiling'];
                if($job['kind']==='audit' && $done) {
                    foreach(['orphan_delivery'=>['distribution_items',"x.source_type='mis_data_row' AND r.id IS NULL",'x.source_id'],'orphan_pool'=>['mis_lead_pool','r.id IS NULL','x.source_row_id'],'orphan_claim'=>['mis_claims','r.id IS NULL','x.row_id']] as $name=>$check) {
                        $tail=' FROM '.$this->t($check[0]).' x LEFT JOIN '.$this->t('mis_data_rows').' r ON r.id='.$check[2].' WHERE '.$check[1];$result['counts'][$name]=(int)$this->db->get_var('SELECT COUNT(*)'.$tail);$this->check($result['counts'][$name]);
                        $pk=$name==='orphan_claim'?'x.row_id':'x.id';$samples=(array)$this->db->get_results('SELECT '.$pk.' id'.$tail.' ORDER BY '.$pk.' LIMIT 20',ARRAY_A);$this->check($samples);$result['samples'][$name]=array_column($samples,'id');
                    }
                }
                if($job['kind']==='index') { if($done) { delete_option('sn_mis_index_needs_rebuild');delete_transient('sn_mis_coverage_'.substr(hash('sha256',$this->db->prefix),0,24)); }SN_MIS_Read_Model::bump(); }
            }
            $elapsed=microtime(true)-$started;$payload['batch_size']=$elapsed>0.8?max(10,intdiv($limit,2)):($elapsed<0.15?min(100,$limit+10):$limit);
            $this->check($this->db->update($this->t('mis_jobs'),['cursor_id'=>$cursor,'processed'=>$processed,'payload'=>wp_json_encode($payload),'result_json'=>wp_json_encode($result,JSON_UNESCAPED_UNICODE),'status'=>$done?'ready':'running','updated_at'=>gmdate('Y-m-d H:i:s')],['id'=>$id]));
            return $this->public_status($this->get($id));
        }finally { $this->db->get_var($this->db->prepare('SELECT RELEASE_LOCK(%s)',$lock)); }
    }
    public function public_status(array $job): array { return array_intersect_key($job,array_flip(['id','kind','status','total','processed','result_json','created_at','updated_at'])); }
    public function download(int $id): string { $job=$this->get($id);if($job['kind']!=='export' || $job['status']!=='ready')throw new RuntimeException('خروجی هنوز آماده نیست.');$path=$this->paths($job)[1];if(!is_file($path))throw new RuntimeException('فایل موقت حذف شده؛ خروجی را دوباره بسازید.');return $path; }
    public function cleanup(): void {
        if(!$this->ready())return;
        $old=(array)$this->db->get_results($this->db->prepare('SELECT * FROM '.$this->t('mis_jobs').' WHERE expires_at<%s ORDER BY id LIMIT 10',gmdate('Y-m-d H:i:s')),ARRAY_A);
        foreach($old as $job) { $lock=$this->lock_name((int)$job['id']);if((int)$this->db->get_var($this->db->prepare('SELECT GET_LOCK(%s,0)',$lock))!==1)continue;
            try { if($job['kind']==='export') { foreach($this->paths($job) as $p) { if(is_file($p))unlink($p); } }$this->db->query($this->db->prepare('DELETE FROM '.$this->t('mis_job_rows').' WHERE job_id=%d LIMIT 1000',(int)$job['id']));if(!$this->db->get_var($this->db->prepare('SELECT cursor_id FROM '.$this->t('mis_job_rows').' WHERE job_id=%d LIMIT 1',(int)$job['id'])))$this->db->delete($this->t('mis_jobs'),['id'=>$job['id']]); }finally { $this->db->get_var($this->db->prepare('SELECT RELEASE_LOCK(%s)',$lock)); }
        }
    }
    public static function headers(): array { return ['شناسه رکورد','کد منبع','شماره تماس','تاریخ و ساعت منبع','نام','نام خانوادگی','پرونده','کمپین','شعبه هنگام تخصیص','مدیر','زمان رویداد','وضعیت فعلی','وضعیت مصرف','دلیل محدودیت','زمان بازگشت','دلیل بازگشت','نوع رویداد','دلیل رویداد']; }
}
