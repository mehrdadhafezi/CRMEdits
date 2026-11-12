<?php
if (!defined('ABSPATH')) { exit; }

require_once __DIR__.'/class-sn-mis-read-model.php';
require_once __DIR__.'/class-sn-mis-jobs.php';
require_once __DIR__.'/class-sn-mis-import-log.php';
require_once __DIR__.'/class-sn-mis-feedback.php';

/** MIS v2: additive storage, one row per number, and append-only history. */
final class SN_MIS_Service
{
    private $db;
    private int $actor;
    private bool $transaction = false;
    private array $after_commit=[];
    private ?bool $schema_ready=null;
    private ?array $state_expressions=null;
    public function __construct($db, int $actor = 0) { $this->db = $db; $this->actor = $actor; }
    private function t(string $name): string { return $this->db->prefix . 'sn_' . $name; }
    public function checked($result) {
        if ($result === false || $this->db->last_error !== '') { throw new RuntimeException('خطای پایگاه داده؛ عملیات ثبت نشد.'); }
        return $result;
    }
    public function rows(string $sql): array { return (array) $this->checked($this->db->get_results($sql, ARRAY_A)); }
    public function value(string $sql) { return $this->checked($this->db->get_var($sql)); }
    private function sql(string $sql, ...$args): string { return $args ? $this->db->prepare($sql, ...$args) : $sql; }
    public static function schema(string $p, string $charset): array {
        return array_merge([
            "CREATE TABLE IF NOT EXISTS {$p}sn_mis_record_meta (
                row_id bigint unsigned NOT NULL, source_code varchar(191) NOT NULL DEFAULT '',
                first_name varchar(191) NOT NULL DEFAULT '', last_name varchar(191) NOT NULL DEFAULT '',
                source_datetime_raw varchar(191) NOT NULL DEFAULT '',
                PRIMARY KEY (row_id), KEY source_code (source_code)
            ) ENGINE=InnoDB {$charset}",
            "CREATE TABLE IF NOT EXISTS {$p}sn_mis_events (
                id bigint unsigned NOT NULL AUTO_INCREMENT, row_id bigint unsigned NOT NULL,
                batch_id bigint unsigned NOT NULL, operation_id varchar(64) NOT NULL,
                event_type varchar(40) NOT NULL, old_manager_id bigint unsigned DEFAULT NULL,
                new_manager_id bigint unsigned DEFAULT NULL, branch_key varchar(80) NOT NULL DEFAULT 'unknown',
                branch_label varchar(191) NOT NULL DEFAULT 'نامشخص', campaign_code varchar(120) NOT NULL DEFAULT '',
                actor_user_id bigint unsigned NOT NULL, before_json longtext, after_json longtext,
                reason text, created_at datetime NOT NULL,
                PRIMARY KEY (id), KEY row_event (row_id,event_type,id),
                KEY event_time (event_type,created_at), KEY batch_id (batch_id),
                KEY manager_branch (new_manager_id,branch_key), KEY operation_id (operation_id)
            ) ENGINE=InnoDB {$charset}",
            "CREATE TABLE IF NOT EXISTS {$p}sn_mis_claims (
                row_id bigint unsigned NOT NULL, normalized_phone varchar(50) NOT NULL,
                owner_user_id bigint unsigned DEFAULT NULL, updated_at datetime NOT NULL,
                PRIMARY KEY (row_id), UNIQUE KEY phone_unique (normalized_phone)
            ) ENGINE=InnoDB {$charset}"
        ],SN_MIS_Read_Model::schema($p,$charset),SN_MIS_Jobs::schema($p,$charset),SN_MIS_Import_Log::schema($p,$charset));
    }
    public function install(): void {
        foreach (self::schema($this->db->prefix, $this->db->get_charset_collate()) as $sql) { $this->checked($this->db->query($sql)); }
        // Add only missing lookup indexes. Never rewrite source values or add unique constraints.
        foreach(['invoices'=>['lead_id'],'mis_distribution_plan_items'=>['source_row_id']] as $table=>$columns) {
            $existing=$this->rows('SHOW INDEX FROM '.$this->t($table));
            foreach($columns as $column) { $found=false;foreach($existing as $index) { if((int)$index['Seq_in_index']===1 && $index['Column_name']===$column)$found=true; }
                if(!$found) { $this->checked($this->db->query('ALTER TABLE '.$this->t($table).' ADD KEY sn_mis_'.$column.' ('.$column.')')); }
            }
        }
        $this->schema_ready=null;
    }
    public function ready(): bool {
        if ($this->schema_ready!==null) { return $this->schema_ready; }
        foreach (['mis_record_meta','mis_events','mis_claims'] as $table) {
            if (!$this->value($this->sql('SHOW TABLES LIKE %s', $this->db->esc_like($this->t($table))))) { return $this->schema_ready=false; }
        }
        return $this->schema_ready=true;
    }
    private function assert_storage(): void {
        if (!$this->ready()) { throw new RuntimeException('ابتدا راه‌اندازی افزایشی MIS را از بخش مدیریت اجرا کنید.'); }
        // Never pretend that a transaction protects MyISAM history or sales data.
        $names = array_map(fn($n) => $this->t($n), ['mis_data_rows','mis_import_batches','mis_lead_pool','distribution_items','distribution_item_logs','mis_distribution_logs','mis_distribution_plan_items','mis_distribution_plans','mis_record_meta','mis_events','mis_claims','leads','invoices']);
        $marks = implode(',', array_fill(0, count($names), '%s'));
        $engines = $this->rows($this->sql("SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ({$marks})", ...$names));
        if (count($engines) !== count($names)) { throw new RuntimeException('جدول‌های لازم برای بررسی امن داده کامل نیستند.'); }
        foreach ($engines as $entry) { if (strcasecmp((string)$entry['ENGINE'], 'InnoDB') !== 0) { throw new RuntimeException('عملیات امن MIS به جدول‌های InnoDB نیاز دارد؛ ساختار موجود تغییر داده نشد.'); } }
    }
    private function begin(): void {
        $this->assert_storage();
        $this->checked($this->db->query('START TRANSACTION'));
        $this->transaction = true;
    }
    private function commit(): void { $this->checked($this->db->query('COMMIT')); $this->transaction=false;$callbacks=$this->after_commit;$this->after_commit=[];SN_MIS_Read_Model::flush($this->db,true);foreach($callbacks as $callback) { try { $callback(); }catch(Throwable $e) { error_log('SN MIS: supplementary post-commit capture requires review; primary records were retained.'); } } }
    private function rollback(): void { if ($this->transaction) { $this->db->query('ROLLBACK'); $this->transaction=false;$this->after_commit=[]; } }
    private function atomic(callable $work) {
        $lock='sn_crm_write_'.substr(hash('sha256',(string)$this->value('SELECT DATABASE()').$this->db->prefix),0,40);
        $acquired=!SN_MIS_Request_Lock::owns($this->db);
        if ($acquired && (int)$this->value($this->sql('SELECT GET_LOCK(%s,5)',$lock))!==1) { throw new RuntimeException('عملیات دیگری در حال ثبت است؛ دوباره تلاش کنید.'); }
        try {
            $this->begin();
            try { $result = $work(); $this->commit(); return $result; }
            catch (Throwable $error) { $this->rollback(); throw $error; }
        } finally { if ($acquired) { $this->db->get_var($this->sql('SELECT RELEASE_LOCK(%s)',$lock)); } }
    }
    private function stamp(): string { return gmdate('Y-m-d H:i:s'); }
    private function operation(): string { return bin2hex(random_bytes(16)); }
    public function event(array $row, string $type, array $before, array $after, string $reason, string $operation, array $branch = []): void {
        $campaign = (string)$this->value($this->sql('SELECT campaign_code FROM '.$this->t('mis_import_batches').' WHERE id=%d', (int)$row['batch_id']));
        $this->checked($this->db->insert($this->t('mis_events'), [
            'row_id'=>(int)$row['id'], 'batch_id'=>(int)$row['batch_id'], 'operation_id'=>$operation,
            'event_type'=>$type, 'old_manager_id'=>$before['assigned_manager_user_id'] ?? null,
            'new_manager_id'=>$after['assigned_manager_user_id'] ?? null,
            'branch_key'=>$branch['key'] ?? 'unknown', 'branch_label'=>$branch['label'] ?? 'نامشخص',
            'campaign_code'=>$campaign, 'actor_user_id'=>$this->actor,
            'before_json'=>wp_json_encode($before, JSON_UNESCAPED_UNICODE), 'after_json'=>wp_json_encode($after, JSON_UNESCAPED_UNICODE),
            'reason'=>$reason, 'created_at'=>$this->stamp()
        ]));
        SN_MIS_Read_Model::queue($this->db,[(int)$row['id']]);
    }
    /** Canonical relationship covers direct MIS, pool, and mixed historical items. */
    public function item_link(string $r = 'r', string $di = 'di'): string {
        $pool = $this->t('mis_lead_pool');
        return "(({$di}.source_type='mis_data_row' AND {$di}.source_id={$r}.id) OR EXISTS (SELECT 1 FROM {$pool} mip WHERE mip.source_row_id={$r}.id AND (mip.id={$di}.pool_item_id OR ({$di}.source_type='mis_pool' AND {$di}.source_id=mip.id))))";
    }
    /** Read projection only; write guards always classify fresh evidence in record(). */
    public function state_sql(): array {
        $ready=(new SN_MIS_Read_Model($this->db))->ready() && !get_option('sn_mis_index_needs_rebuild',0);
        $state=$ready?"CASE WHEN ix.row_id IS NULL OR dirty.row_id IS NOT NULL THEN 'PENDING' ELSE ix.logical_state END":"'PENDING'";
        $reason=$ready?"COALESCE(ix.block_reason,'در انتظار آماده‌سازی آمار')":"'در انتظار آماده‌سازی آمار'";
        $manager=$ready?'COALESCE(NULLIF(r.assigned_manager_user_id,0),ix.manager_id,0)':'COALESCE(r.assigned_manager_user_id,0)';
        $consumed=$ready?'CASE WHEN dirty.row_id IS NULL THEN ix.is_consumed ELSE NULL END':'NULL';
        return compact('state','reason','manager','consumed');
    }
    private function record(int $id, bool $lock = false): array {
        $rows=$this->rows($this->sql('SELECT * FROM '.$this->t('mis_data_rows').' WHERE id=%d'.($lock?' FOR UPDATE':''),$id));
        if (!$rows) { throw new RuntimeException('رکورد پیدا نشد.'); }
        $facts=(new SN_MIS_Read_Model($this->db))->classify($rows);
        return $rows[0]+$facts[$id];
    }
    private function linked_items(int $id, bool $lock = false): array {
        $items=[];$suffix=$lock?' FOR UPDATE':'';
        foreach($this->rows($this->sql('SELECT * FROM '.$this->t('distribution_items')." WHERE source_type='mis_data_row' AND source_id=%d".$suffix,$id)) as $item) { $items[(int)$item['id']]=$item; }
        $pools=$this->rows($this->sql('SELECT id FROM '.$this->t('mis_lead_pool').' WHERE source_row_id=%d'.$suffix,$id));
        foreach($pools as $pool) {
            foreach($this->rows($this->sql('SELECT * FROM '.$this->t('distribution_items')." WHERE pool_item_id=%d OR (source_type='mis_pool' AND source_id=%d)".$suffix,(int)$pool['id'],(int)$pool['id'])) as $item) { $items[(int)$item['id']]=$item; }
        }
        return array_values($items);
    }
    public function has_linked_items(int $id): bool { return (bool)$this->linked_items($id); }
    private function sync_batch(int $batch): void {
        $rows=$this->t('mis_data_rows'); $batches=$this->t('mis_import_batches');
        $managers=$this->rows($this->sql("SELECT DISTINCT assigned_manager_user_id uid FROM {$rows} WHERE batch_id=%d AND assigned_manager_user_id>0",$batch));
        $owner=count($managers)===1 ? (int)$managers[0]['uid'] : null;
        $header=$this->rows($this->sql("SELECT import_status FROM {$batches} WHERE id=%d",$batch));
        if (!$header) { throw new RuntimeException('پرونده مبنا پیدا نشد.'); }
        $data=['assigned_manager_user_id'=>$owner,'updated_at'=>current_time('mysql')];
        if (!in_array($header[0]['import_status'],['archived','deleted','file_deleted','reset_deleted'],true)) { $data['import_status']=$managers?'assigned_to_manager':'imported'; }
        $this->checked($this->db->update($batches,$data,['id'=>$batch]));
    }
    public function assign(array $ids, int $manager, array $branch, string $reason, string $position='sales_manager', bool $create_item=true): array {
        if (!$manager || empty($branch['key']) || $reason==='') { throw new RuntimeException('مدیر، شعبه و دلیل تخصیص الزامی است.'); }
        return $this->atomic(function() use($ids,$manager,$branch,$reason,$position,$create_item) {
            $operation=$this->operation(); $report=['assigned'=>0,'blocked'=>0,'reasons'=>[],'operation_id'=>$operation]; $batches=[];
            foreach (array_unique(array_map('intval',$ids)) as $id) {
                $row=$this->record($id,true);
                if ($row['logical_state']!=='RAW') { $report['blocked']++; continue; }
                $phone=(string)$row['normalized_phone'];
                if ($phone==='') { $report['blocked']++; continue; }
                // Covers old cross-batch duplicates and an active direct/pool path before a new claim.
                $other=$this->value($this->sql('SELECT COUNT(*) FROM '.$this->t('mis_data_rows')." WHERE normalized_phone=%s AND id<>%d AND row_status='valid' AND (assigned_manager_user_id>0 OR id<%d)",$phone,$id,$id));
                $other_item=$this->value($this->sql('SELECT COUNT(*) FROM '.$this->t('distribution_items')." di WHERE (di.normalized_phone=%s OR di.customer_phone=%s) AND di.status<>'returned_to_mis'",$phone,$phone));
                if ($other || $other_item) { $report['blocked']++; $report['reasons']['duplicate']=($report['reasons']['duplicate']??0)+1; continue; }
                $claim=$this->rows($this->sql('SELECT * FROM '.$this->t('mis_claims').' WHERE normalized_phone=%s OR row_id=%d FOR UPDATE',$phone,$id));
                if ($claim && ((int)$claim[0]['row_id']!==$id || (int)$claim[0]['owner_user_id']>0)) { $report['blocked']++; continue; }
                $items=$this->linked_items($id,true);
                if (count($items)>1 || ($items && $items[0]['status']!=='returned_to_mis')) { $report['blocked']++; continue; }
                $item_id=0;
                if ($create_item || $items) {
                $data=['current_owner_user_id'=>$manager,'current_owner_position'=>$position,'current_step_order'=>$position==='seller'?999:0,'final_seller_user_id'=>$position==='seller'?$manager:null,'status'=>'assigned_to_role','updated_at'=>current_time('mysql')];
                if ($items) {
                    $item_id=(int)$items[0]['id'];
                    $this->checked($this->db->update($this->t('distribution_items'),$data,['id'=>$item_id,'status'=>'returned_to_mis']));
                } else {
                    $data+=['source_type'=>'mis_data_row','source_id'=>$id,'batch_id'=>(int)$row['batch_id'],'normalized_phone'=>$phone,'customer_phone'=>$row['customer_phone'],'created_at'=>current_time('mysql')];
                    $this->checked($this->db->insert($this->t('distribution_items'),$data)); $item_id=(int)$this->db->insert_id;
                }
                }
                $after=['assigned_manager_user_id'=>$position==='sales_manager'?$manager:null,'assigned_at'=>current_time('mysql')];
                $this->checked($this->db->update($this->t('mis_data_rows'),$after+['updated_at'=>current_time('mysql')],['id'=>$id]));
                $this->checked($this->db->query($this->sql('INSERT INTO '.$this->t('mis_claims').' (row_id,normalized_phone,owner_user_id,updated_at) VALUES (%d,%s,%d,%s) ON DUPLICATE KEY UPDATE owner_user_id=VALUES(owner_user_id),updated_at=VALUES(updated_at)',$id,$phone,$manager,$this->stamp())));
                $this->checked($this->db->query($this->sql('UPDATE '.$this->t('mis_lead_pool')." SET assigned_manager_user_id=%d,pool_status='ready',updated_at=%s WHERE source_row_id=%d",$manager,current_time('mysql'),$id)));
                if ($item_id>0) { $this->item_log($item_id,'mis_manager_assignment',0,$manager,'mis',$position,$reason); }
                $this->event($row,'assigned',['assigned_manager_user_id'=>$row['assigned_manager_user_id'],'logical_state'=>'RAW'],$after+['logical_state'=>$position==='sales_manager'?'MANAGER_HELD':'CONSUMED','item_id'=>$item_id],$reason,$operation,$branch);
                if ($position!=='sales_manager') { $this->event($row,'consumed',[],['assigned_manager_user_id'=>null,'item_id'=>$item_id],$reason,$operation,$branch); }
                $report['assigned']++; $batches[(int)$row['batch_id']]=true;
            }
            foreach (array_keys($batches) as $batch) { $this->sync_batch($batch); }
            return $report;
        });
    }
    private function item_log(int $item, string $action, int $from, int $to, string $from_pos, string $to_pos, string $reason): void {
        $this->checked($this->db->insert($this->t('distribution_item_logs'),[
            'item_id'=>$item,'action'=>$action,'actor_user_id'=>$this->actor,'from_user_id'=>$from?:null,'to_user_id'=>$to?:null,
            'from_position'=>$from_pos,'to_position'=>$to_pos,'step_order'=>0,'note'=>$reason,'payload_json'=>'{}','created_at'=>current_time('mysql')
        ]));
    }
    public function return_rows(array $ids, string $reason): array {
        if ($reason==='') { throw new RuntimeException('دلیل بازگشت الزامی است.'); }
        return $this->atomic(function() use($ids,$reason) {
            $operation=$this->operation(); $report=['returned'=>0,'blocked'=>0,'reasons'=>[],'operation_id'=>$operation]; $batches=[];
            foreach (array_unique(array_map('intval',$ids)) as $id) {
                $row=$this->record($id,true); $items=$this->linked_items($id,true);
                // Re-read after locks, inside the transaction; preview is never authority.
                $row=$this->record($id);
                if ($row['logical_state']!=='MANAGER_HELD') { $report['blocked']++; $key=$row['block_reason']?:'در اختیار مدیر و قابل بازگشت نیست'; $report['reasons'][$key]=($report['reasons'][$key]??0)+1; continue; }
                foreach ($items as $item) {
                    if ($item['status']==='returned_to_mis') { continue; }
                    $this->checked($this->db->update($this->t('distribution_items'),['current_owner_user_id'=>null,'current_owner_position'=>'mis','status'=>'returned_to_mis','updated_at'=>current_time('mysql')],['id'=>(int)$item['id']]));
                    $this->item_log((int)$item['id'],'returned_to_mis',(int)$row['assigned_manager_user_id'],0,'sales_manager','mis',$reason);
                }
                $after=['assigned_manager_user_id'=>null,'assigned_at'=>null];
                $this->checked($this->db->update($this->t('mis_data_rows'),$after+['updated_at'=>current_time('mysql')],['id'=>$id]));
                $this->checked($this->db->query($this->sql('UPDATE '.$this->t('mis_lead_pool')." SET assigned_manager_user_id=NULL,pool_status='returned_to_mis',updated_at=%s WHERE source_row_id=%d",current_time('mysql'),$id)));
                $this->checked($this->db->query($this->sql('UPDATE '.$this->t('mis_claims').' SET owner_user_id=NULL,updated_at=%s WHERE row_id=%d',$this->stamp(),$id)));
                $branch=$this->last_branch($id);
                $this->event($row,'returned',['assigned_manager_user_id'=>$row['assigned_manager_user_id'],'logical_state'=>'MANAGER_HELD'],$after+['logical_state'=>'RAW'],$reason,$operation,$branch);
                $report['returned']++; $batches[(int)$row['batch_id']]=true;
            }
            foreach(array_keys($batches) as $batch) { $this->sync_batch($batch); }
            return $report;
        });
    }
    public function last_branch(int $id): array {
        $rows=$this->rows($this->sql('SELECT branch_key,branch_label FROM '.$this->t('mis_events')." WHERE row_id=%d AND event_type='assigned' ORDER BY id DESC LIMIT 1",$id));
        return $rows ? ['key'=>$rows[0]['branch_key'],'label'=>$rows[0]['branch_label']] : ['key'=>'unknown','label'=>'نامشخص'];
    }
    /** Preview and commit revalidate the same fully parsed input; reimport never deletes. */
    public function import_rows(int $batch, array $records, string $filename, bool $apply, int $run_id=0): array {
        if (count($records)>10000 || !$records) { throw new RuntimeException('فایل باید بین ۱ تا ۱۰۰۰۰ ردیف داشته باشد.'); }
        $work=function() use($batch,$records,$filename,$apply,$run_id) {
            $b=$this->rows($this->sql('SELECT * FROM '.$this->t('mis_import_batches').' WHERE id=%d'.($apply?' FOR UPDATE':''),$batch));
            if (!$b || in_array($b[0]['import_status'],['archived','deleted','file_deleted','reset_deleted'],true)) { throw new RuntimeException('پرونده فعال انتخاب کنید.'); }
            $log=null;$active_run=0;
            if($apply) {
                $log=new SN_MIS_Import_Log($this->db,$this->actor);$active_run=$run_id>0?$run_id:$log->start($batch,$filename);$attempt=$log->get($active_run,true);
                if((int)$attempt['batch_id']!==$batch)throw new RuntimeException('پرونده فایل با پیش‌نمایش مطابقت ندارد؛ پیش‌نمایش تازه بسازید.');
                if($attempt['status']==='completed') { $saved=json_decode($attempt['report_json'],true);if(!is_array($saved))throw new RuntimeException('نتیجه قبلی فایل در دسترس نیست؛ تاریخچه را بررسی کنید.');return $saved+['replayed'=>true]; }
            }
            $report=['seen'=>count($records),'imported'=>0,'valid'=>0,'duplicate'=>0,'invalid'=>0,'unchanged'=>0,'preview'=>[]];
            if($active_run)$report['import_run_id']=$active_run;
            $seen=[]; $operation=$this->operation();
            $base=(int)$this->value($this->sql('SELECT COALESCE(MAX(`row_number`),0) FROM '.$this->t('mis_data_rows').' WHERE batch_id=%d',$batch));
            foreach($records as $n=>$record) {
                $phone=(string)$record['normalized_phone']; $status=$record['row_status']; $reason=$record['duplicate_reason']??'';
                if(!empty($record['file_code']) && (string)$record['file_code']!==(string)$b[0]['batch_code'])throw new RuntimeException('کد فایل در ردیف '.($n+1).' با کد پرونده انتخاب‌شده مطابقت ندارد. پرونده درست را انتخاب کنید یا ستون کد فایل را اصلاح کنید.');
                $record['source_code']=(string)($record['source_code']??'');if($record['source_code']==='')$record['source_code']=(string)$b[0]['batch_code'];
                $record['source_meta']['file_code']=(string)$b[0]['batch_code'];$record['source_meta']['file_code_basis']='selected_global_case';
                try { $source_time=SN_MIS_Feedback::jalali_datetime((string)($record['source_datetime_raw']??''));$record['source_datetime_raw']=$source_time['display'];$record['source_meta']['source_datetime_utc']=$source_time['utc'];$record['source_meta']['source_datetime_basis']='jalali_asia_tehran'; }
                catch(RuntimeException $date_error) { $status='invalid';$reason='invalid_source_datetime';$record['source_meta']['source_datetime_error']=$date_error->getMessage(); }
                $same=[];
                if ($status==='valid') {
                    $same=$this->rows($this->sql('SELECT id FROM '.$this->t('mis_data_rows').' WHERE batch_id=%d AND normalized_phone=%s ORDER BY id LIMIT 1',$batch,$phone));
                    if ($same) { $report['unchanged']++; $status='duplicate'; $reason='duplicate_in_same_batch'; }
                    elseif (isset($seen[$phone])) { $status='duplicate'; $reason='duplicate_in_file'; }
                    else {
                        $exists=$this->value($this->sql('SELECT COUNT(*) FROM '.$this->t('mis_data_rows')." WHERE normalized_phone=%s AND row_status='valid'",$phone));
                        $lead=$this->value($this->sql('SELECT COUNT(*) FROM '.$this->t('leads').' WHERE phone=%s',$phone));
                        if ($exists || $lead) { $status='duplicate'; $reason=$lead?'duplicate_in_leads':'duplicate_in_mis'; }
                    }
                    $seen[$phone]=true;
                }
                $report[$status]++;
                if (count($report['preview'])<25) { $report['preview'][]=['number'=>$n+1,'name'=>$record['customer_name'],'phone'=>$phone,'status'=>$status,'reason'=>$reason,'detail'=>$record['source_meta']['source_datetime_error']??'']; }
                if (!$apply || $same || $reason==='duplicate_in_file') { continue; }
                $row=['batch_id'=>$batch,'row_number'=>$base+$n+1,'customer_name'=>$record['customer_name'],'customer_phone'=>$record['customer_phone'],'normalized_phone'=>$phone,'city'=>$record['city']??'','province'=>$record['province']??'','source_meta'=>wp_json_encode($record['source_meta']??[],JSON_UNESCAPED_UNICODE),'row_status'=>$status,'duplicate_reason'=>$reason?:null,'created_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')];
                $this->checked($this->db->insert($this->t('mis_data_rows'),$row)); $id=(int)$this->db->insert_id;
                $meta=['row_id'=>$id,'source_code'=>$record['source_code']??'','source_datetime_raw'=>$record['source_datetime_raw']??'','first_name'=>$record['first_name']??'','last_name'=>$record['last_name']??''];
                $this->checked($this->db->insert($this->t('mis_record_meta'),$meta));
                $this->event(['id'=>$id,'batch_id'=>$batch],'imported',[],['logical_state'=>($status==='valid'?'RAW':strtoupper($status)),'source_meta'=>$meta],'ورود فایل '.$filename,$operation);
                $report['imported']++;
            }
            if ($apply) {
                $counts=$this->rows($this->sql('SELECT COUNT(*) total,SUM(row_status=\'valid\') valid,SUM(row_status=\'duplicate\') duplicate,SUM(row_status=\'invalid\') invalid FROM '.$this->t('mis_data_rows').' WHERE batch_id=%d',$batch))[0];
                $data=['total_rows'=>(int)$counts['total'],'valid_rows'=>(int)$counts['valid'],'duplicate_rows'=>(int)$counts['duplicate'],'invalid_rows'=>(int)$counts['invalid'],'import_file_name'=>$filename,'updated_at'=>current_time('mysql')];
                // Keep the old header state and manager on an idempotent reimport.
                if ($b[0]['import_status']==='draft') { $data['import_status']='imported'; }
                $this->checked($this->db->update($this->t('mis_import_batches'),$data,['id'=>$batch]));
                $log->progress($active_run,'completed',$report);
            }
            return $report;
        };
        return $apply ? $this->atomic($work) : $work();
    }
    public function edit(int $id, array $changes, string $reason): array {
        if ($reason==='') { throw new RuntimeException('دلیل ویرایش الزامی است.'); }
        return $this->atomic(function() use($id,$changes,$reason) {
            $row=$this->record($id,true);
            $meta=$this->rows($this->sql('SELECT * FROM '.$this->t('mis_record_meta').' WHERE row_id=%d',$id));
            $before=$meta[0]??['row_id'=>$id,'source_code'=>'','first_name'=>'','last_name'=>'','source_datetime_raw'=>''];
            // Ids are never editable. Identity changes require a completely unused RAW row.
            $phone=(string)($changes['normalized_phone']??$row['normalized_phone']);
            $code=(string)($changes['source_code']??$before['source_code']);
            if ($phone!==$row['normalized_phone'] || $code!==$before['source_code']) {
                $blocked=$row['_consumed'] || $row['_reserved'];
                $pool_count=(int)$this->value($this->sql('SELECT COUNT(*) FROM '.$this->t('mis_lead_pool').' WHERE source_row_id=%d',$id));
                if ($blocked || (int)$row['assigned_manager_user_id']>0 || $pool_count || $this->linked_items($id) || !in_array($row['logical_state'],['RAW','INVALID','DUPLICATE'],true)) { throw new RuntimeException('تغییر شماره یا کد داده دارای سابقه مصرف یا تخصیص مجاز نیست.'); }
            }
            if ($phone!==$row['normalized_phone']) {
                $history=$this->value($this->sql('SELECT COUNT(*) FROM '.$this->t('mis_events')." WHERE row_id=%d AND event_type IN ('assigned','consumed','returned')",$id));
                $other=$this->value($this->sql('SELECT COUNT(*) FROM '.$this->t('mis_data_rows').' WHERE normalized_phone=%s AND id<>%d',$phone,$id));
                $lead=$this->value($this->sql('SELECT COUNT(*) FROM '.$this->t('leads').' WHERE phone=%s',$phone));
                $claim=$this->value($this->sql('SELECT COUNT(*) FROM '.$this->t('mis_claims').' WHERE row_id=%d OR normalized_phone=%s',$id,$phone));
                if ($history || $other || $lead || $claim || $this->linked_items($id)) { throw new RuntimeException('شماره دارای سابقه یا شماره تکراری قابل جایگزینی نیست.'); }
            }
            $after=array_intersect_key($changes,array_flip(['source_code','first_name','last_name','source_datetime_raw']))+$before;
            if($after['source_datetime_raw']!==$before['source_datetime_raw'])$after['source_datetime_raw']=SN_MIS_Feedback::jalali_datetime((string)$after['source_datetime_raw'])['display'];
            $this->checked($this->db->query($this->sql('INSERT INTO '.$this->t('mis_record_meta').' (row_id,source_code,first_name,last_name,source_datetime_raw) VALUES (%d,%s,%s,%s,%s) ON DUPLICATE KEY UPDATE source_code=VALUES(source_code),first_name=VALUES(first_name),last_name=VALUES(last_name),source_datetime_raw=VALUES(source_datetime_raw)',$id,$after['source_code'],$after['first_name'],$after['last_name'],$after['source_datetime_raw'])));
            $data=['customer_name'=>trim($after['first_name'].' '.$after['last_name']),'updated_at'=>current_time('mysql')];
            if($after['source_datetime_raw']!==$before['source_datetime_raw']) { $meta_values=json_decode((string)($row['source_meta']??'{}'),true)?:[];$meta_values['source_datetime_utc']=SN_MIS_Feedback::jalali_datetime((string)$after['source_datetime_raw'])['utc'];$meta_values['source_datetime_basis']='jalali_asia_tehran';$data['source_meta']=wp_json_encode($meta_values,JSON_UNESCAPED_UNICODE); }
            if(($row['duplicate_reason']??'')==='invalid_source_datetime' && preg_match('/^09[0-9]{9}$/',$phone) && $after['source_datetime_raw']!==$before['source_datetime_raw']) { $data['row_status']='valid';$data['duplicate_reason']=null; }
            if ($data['customer_name']==='') { $data['customer_name']=$row['customer_name']; }
            if ($phone!==$row['normalized_phone']) { $data+=['normalized_phone'=>$phone,'customer_phone'=>$phone,'row_status'=>'valid','duplicate_reason'=>null]; }
            $this->checked($this->db->update($this->t('mis_data_rows'),$data,['id'=>$id]));
            if ($phone!==$row['normalized_phone']) {
                $counts=$this->rows($this->sql('SELECT COUNT(*) total,SUM(row_status=\'valid\') valid,SUM(row_status=\'invalid\') invalid,SUM(row_status=\'duplicate\') duplicate FROM '.$this->t('mis_data_rows').' WHERE batch_id=%d',(int)$row['batch_id']))[0];
                $this->checked($this->db->update($this->t('mis_import_batches'),['total_rows'=>(int)$counts['total'],'valid_rows'=>(int)$counts['valid'],'invalid_rows'=>(int)$counts['invalid'],'duplicate_rows'=>(int)$counts['duplicate']],['id'=>(int)$row['batch_id']]));
            }
            $this->event($row,'edited',$before+['normalized_phone'=>$row['normalized_phone'],'customer_name'=>$row['customer_name'],'row_status'=>$row['row_status'],'duplicate_reason'=>$row['duplicate_reason']],$after+$data,$reason,$this->operation(),$this->last_branch($id));
            return ['edited'=>1];
        });
    }
    private function base_query(array $filters, bool $history=false): array {
        $q=$this->state_sql(); $events=$this->t('mis_events'); $indexed=(new SN_MIS_Read_Model($this->db))->ready();
        $from=' FROM '.$this->t('mis_data_rows').' r LEFT JOIN '.$this->t('mis_import_batches').' b ON b.id=r.batch_id LEFT JOIN '.$this->t('mis_record_meta').' m ON m.row_id=r.id ';
        if ($indexed) { $from.=' LEFT JOIN '.$this->t('mis_row_index').' ix ON ix.row_id=r.id LEFT JOIN '.$this->t('mis_index_dirty').' dirty ON dirty.row_id=r.id '; }
        $from.=$history ? " INNER JOIN {$events} e ON e.row_id=r.id " : " LEFT JOIN {$events} e ON ".($indexed?'e.id=ix.assigned_event_id':'1=0')." ";
        $from.=" LEFT JOIN {$events} er ON ".($indexed?'er.id=ix.returned_event_id':'1=0')." ";
        $where=['1=1']; $args=[];
        if(!empty($filters['__row_ids'])) { $chosen=array_slice(array_values(array_unique(array_map('intval',(array)$filters['__row_ids']))),0,1000);$where[]='r.id IN ('.implode(',',array_fill(0,count($chosen),'%d')).')';$args=array_merge($args,$chosen); }
        foreach(['batch_id'=>'r.batch_id','manager_id'=>($history?"COALESCE(e.new_manager_id,e.old_manager_id)":$q['manager'])] as $key=>$col) {
            if (!empty($filters[$key])) { $where[]="{$col}=%d"; $args[]=(int)$filters[$key]; }
        }
        foreach(['campaign'=>($history?'e.campaign_code':'b.campaign_code'),'branch'=>"COALESCE(e.branch_key,'unknown')"] as $key=>$col) {
            if (($filters[$key]??'')!=='') { $where[]="{$col}=%s"; $args[]=$filters[$key]; }
        }
        if (!empty($filters['state'])) { $where[]='('.$q['state'].')=%s'; $args[]=$filters['state']; }
        if (!empty($filters['consumed'])) { $where[]='('.$q['consumed'].')='.($filters['consumed']==='yes'?'1':'0'); }
        if (!empty($filters['search'])) {
            $like='%'.$this->db->esc_like($filters['search']).'%';
            $where[]='(r.normalized_phone LIKE %s OR r.customer_name LIKE %s OR m.source_code LIKE %s OR m.first_name LIKE %s OR m.last_name LIKE %s)';
            array_push($args,$like,$like,$like,$like,$like);
        }
        if ($history && !empty($filters['event_type'])) { $where[]='e.event_type=%s'; $args[]=$filters['event_type']; }
        // A typed historical date must select the corresponding event kind.
        if ($history && isset($filters['date_type']) && in_array($filters['date_type'],['imported','assigned','consumed','returned'],true)) { $where[]='e.event_type=%s'; $args[]=$filters['date_type']; }
        $date_type=$filters['date_type']??'imported';
        if ($history || $date_type==='imported' || $date_type==='assigned') {
            $column=$history || $date_type==='assigned' ? 'e.created_at' : 'r.created_at';
            foreach(['from'=>'>=','to'=>'<'] as $key=>$op) { if (!empty($filters[$key])) { $where[]="{$column}{$op}%s"; $args[]=$filters[$key]; } }
        } else {
            $condition=["ed.row_id=r.id AND ed.event_type=%s"]; $date_args=[$date_type];
            foreach(['from'=>'>=','to'=>'<'] as $key=>$op) { if (!empty($filters[$key])) { $condition[]="ed.created_at{$op}%s"; $date_args[]=$filters[$key]; } }
            $where[]="EXISTS (SELECT 1 FROM {$events} ed WHERE ".implode(' AND ',$condition).')'; $args=array_merge($args,$date_args);
        }
        return [$from.' WHERE '.implode(' AND ',$where),$args,$q];
    }
    public function query(array $filters=[], int $limit=50, int $offset=0, bool $history=false, int $after=0, int $ceiling=0): array {
        [$tail,$args,$q]=$this->base_query($filters,$history);
        $count=!empty($filters['__skip_count'])?0:(int)$this->value($this->sql('SELECT COUNT(*)'.$tail,...$args));
        $cursor=$history?'e.id':'r.id';
        if ($after>0) { $tail.=" AND {$cursor}>%d"; $args[]=$after; }
        if ($ceiling>0) { $tail.=" AND {$cursor}<=%d"; $args[]=$ceiling; }
        $select="SELECT r.id,r.batch_id,r.`row_number`,r.customer_name,r.customer_phone,r.normalized_phone,r.row_status,r.assigned_manager_user_id,r.assigned_at,r.created_at,r.updated_at, m.source_code,m.first_name,m.last_name,m.source_datetime_raw,b.batch_code,b.title batch_title,b.campaign_code,e.branch_key,e.branch_label,e.created_at event_time,e.event_type,e.reason event_reason,e.old_manager_id,e.new_manager_id,er.created_at return_time,er.reason return_reason,{$cursor} cursor_id,".$q['state'].' logical_state,'.$q['reason'].' block_reason,'.$q['manager'].' resolved_manager_id';
        $direction=($filters['selection']??'oldest')==='newest'?'DESC':'ASC';
        $rows=$this->rows($this->sql($select.$tail." ORDER BY {$cursor} {$direction} LIMIT %d OFFSET %d",...array_merge($args,[max(1,min(1000,$limit)),max(0,$offset)])));
        return ['total'=>$count,'rows'=>!empty($filters['__projection_only'])?$rows:(new SN_MIS_Read_Model($this->db))->hydrate($rows,$history,!$this->transaction)];
    }
    public function ids(array $filters, int $limit, bool $returning=false): array {
        if (!empty($filters['state']) && (get_option('sn_mis_index_needs_rebuild',0) || (new SN_MIS_Read_Model($this->db))->coverage()['pending']>0)) { throw new RuntimeException('ابتدا آماده‌سازی آمار را در ابزارهای MIS کامل کنید یا شماره‌ها را مستقیم انتخاب کنید.'); }
        // Select all requested rows for returns so blocked counts/reasons remain visible.
        $result=$this->query($filters,max(1,min(1000,$limit)));
        return array_column($result['rows'],'id');
    }
    private function cached(string $name,array $filters,callable $build): array {
        $key='sn_mis_read_'.substr(hash('sha256',$this->db->prefix.$name.wp_json_encode($filters).get_option('sn_mis_read_epoch','0')),0,40);
        $value=get_transient($key); if (is_array($value)) { return $value; }
        $value=$build(); set_transient($key,$value,60); return $value;
    }
    public function stats(array $filters=[]): array {
        return $this->cached('states',$filters,function()use($filters) {
            [$tail,$args,$q]=$this->base_query($filters);
            $rows=$this->rows($this->sql('SELECT '.$q['state'].' state,COUNT(*) total'.$tail.' GROUP BY state',...$args));
            $out=['RAW'=>0,'MANAGER_HELD'=>0,'CONSUMED'=>0,'LOCKED'=>0,'INVALID'=>0,'DUPLICATE'=>0,'PENDING'=>0];
            foreach($rows as $row) { $out[$row['state']]=(int)$row['total']; }return $out;
        });
    }
    public function history_stats(array $filters=[]): array {
        return $this->cached('history',$filters,function()use($filters) {
            if(empty($filters['__typed_history']))$filters['date_type']='event';
            [$tail,$args]=$this->base_query($filters,true);
            $totals=$this->rows($this->sql("SELECT COUNT(*) events,COUNT(DISTINCT r.id) unique_rows,COUNT(DISTINCT CASE WHEN e.event_type='assigned' THEN r.id END) unique_assigned,SUM(e.event_type='assigned') assignments,SUM(e.event_type='returned') returns".$tail,...$args));
            $branches=$this->rows($this->sql("SELECT e.branch_key,e.branch_label,COUNT(*) assignments,COUNT(DISTINCT r.id) unique_rows".$tail." AND e.event_type='assigned' GROUP BY e.branch_key,e.branch_label ORDER BY assignments DESC LIMIT 100",...$args));
            $managers=$this->rows($this->sql("SELECT e.new_manager_id,COUNT(*) assignments,COUNT(DISTINCT r.id) unique_rows".$tail." AND e.event_type='assigned' GROUP BY e.new_manager_id ORDER BY assignments DESC LIMIT 100",...$args));
            $days=max(7,min(365,(int)($filters['chart_days']??31)));$start=(new DateTimeImmutable('today',new DateTimeZone('Asia/Tehran')))->modify('-'.($days-1).' days')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            $trend=$this->rows($this->sql("SELECT DATE(CONVERT_TZ(e.created_at,'+00:00','+03:30')) day,SUM(e.event_type='assigned') assignments,SUM(e.event_type='returned') returns".$tail." AND e.created_at>=%s AND e.event_type IN ('assigned','returned') GROUP BY day ORDER BY day",...array_merge($args,[$start])));
            return ['totals'=>$totals[0]??[],'branches'=>$branches,'managers'=>$managers,'trend'=>$trend];
        });
    }
    public function assignment_periods(array $filters,array $starts): array {
        return $this->cached('periods',[$filters,$starts],function()use($filters,$starts){
            unset($filters['from'],$filters['to']);$filters['event_type']='assigned';$filters['date_type']='event';
            [$tail,$args]=$this->base_query($filters,true);$select=[];$values=[];
            foreach(array_values($starts) as $i=>$start) { $select[]="SUM(e.created_at>=%s) p{$i}";$values[]=$start; }
            return $this->rows($this->sql('SELECT '.implode(',',$select).$tail,...array_merge($values,$args)))[0]??[];
        });
    }
    /** Freeze export membership with one indexed INSERT SELECT; values are captured by bounded job steps. */
    public function freeze_export(int $job,array $filters,bool $history): int {
        [$tail,$args]=$this->base_query($filters,$history);$cursor=$history?'e.id':'r.id';
        $this->checked($this->db->query($this->sql('INSERT INTO '.$this->t('mis_job_rows')." (job_id,cursor_id,row_id,event_id) SELECT %d,{$cursor},r.id,".($history?'e.id':'0').$tail.' LIMIT 1048576',...array_merge([$job],$args))));
        return (int)$this->value($this->sql('SELECT COUNT(*) FROM '.$this->t('mis_job_rows').' WHERE job_id=%d',$job));
    }
    public function export_selection_rows(array $selection,bool $history): array {
        if (!$selection) { return []; }$out=[];$events=$this->t('mis_events');
        $ids=array_map('intval',array_column($selection,'row_id'));
        $rows=$this->rows('SELECT r.*,m.source_code,m.first_name,m.last_name,m.source_datetime_raw,b.batch_code,b.campaign_code FROM '.$this->t('mis_data_rows').' r LEFT JOIN '.$this->t('mis_record_meta').' m ON m.row_id=r.id LEFT JOIN '.$this->t('mis_import_batches').' b ON b.id=r.batch_id WHERE r.id IN ('.implode(',',$ids).')');
        $map=[];foreach((new SN_MIS_Read_Model($this->db))->hydrate($rows,false,false) as $r) { $map[(int)$r['id']]=$r; }
        $emap=[];
        if ($history) { $eids=array_map('intval',array_column($selection,'event_id'));foreach($this->rows("SELECT * FROM {$events} WHERE id IN (".implode(',',$eids).')') as $e) { $emap[(int)$e['id']]=$e; } }
        foreach($selection as $sel) {
            $r=$map[(int)$sel['row_id']]??null;if (!$r) { throw new RuntimeException('رکورد انتخاب‌شده در دسترس نیست؛ خروجی ناقص تحویل داده نشد.'); }
            if ($history) { $e=$emap[(int)$sel['event_id']]??null;if (!$e) { throw new RuntimeException('رویداد انتخاب‌شده در دسترس نیست.'); }foreach(['branch_key','branch_label','old_manager_id','new_manager_id','event_type'] as $key) { $r[$key]=$e[$key]; }$r['event_time']=$e['created_at'];$r['event_reason']=$e['reason']; }
            $out[]=$r;
        }return $out;
    }
    public function record_history(int $id, int $limit=100): array {
        return $this->rows($this->sql('SELECT * FROM '.$this->t('mis_events').' WHERE row_id=%d ORDER BY id DESC LIMIT %d',$id,min(100,$limit)));
    }
    public function conversion_summary(int $batch): array {
        $total=0;$lead_count=0;$matching=0;$invoice_ids=[];$paid=[];$after=0;$model=new SN_MIS_Read_Model($this->db);
        do {
            $rows=$this->rows($this->sql('SELECT * FROM '.$this->t('mis_data_rows').' WHERE batch_id=%d AND id>%d ORDER BY id LIMIT 250',$batch,$after));
            foreach($model->classify($rows) as $id=>$fact) { $total++;$after=$id;$lead_count+=(int)$fact['_flags']['lead'];$matching+=(int)$fact['_flags']['invoice'];foreach($fact['_invoice_ids'] as $iid)$invoice_ids[$iid]=true;foreach($fact['_paid_invoice_ids'] as $iid)$paid[$iid]=true; }
        }while(count($rows)===250);
        return ['imported_rows'=>$total,'matching_leads'=>$lead_count,'matching_invoices'=>count($invoice_ids),'matching_paid_invoices'=>count($paid),'converted_unique_rows'=>$matching,'conversion_percentage'=>$total?round(100*$matching/$total,2):0];
    }
    public function safe_archive(int $batch, string $reason): array {
        return $this->atomic(function() use($batch,$reason) {
            if (!$this->rows($this->sql('SELECT id FROM '.$this->t('mis_import_batches').' WHERE id=%d FOR UPDATE',$batch))) { throw new RuntimeException('پرونده پیدا نشد.'); }
            $this->checked($this->db->update($this->t('mis_import_batches'),['import_status'=>'archived','updated_at'=>current_time('mysql')],['id'=>$batch]));
            $this->checked($this->db->insert($this->t('mis_distribution_logs'),['batch_id'=>$batch,'actor_user_id'=>$this->actor,'action'=>'batch_archived_preserving_history','reason'=>$reason,'created_at'=>current_time('mysql')]));
            $ids=$this->rows($this->sql('SELECT id FROM '.$this->t('mis_data_rows').' WHERE batch_id=%d',$batch)); SN_MIS_Read_Model::queue($this->db,array_column($ids,'id'));
            return ['archived'=>1,'deleted'=>0];
        });
    }
    /** Long exports use a consistent read snapshot and never hold the CRM writer mutex. */
    public function read_snapshot(callable $work) {
        $this->assert_storage();
        $this->checked($this->db->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ'));
        $this->checked($this->db->query('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY')); $this->transaction=true;
        try { $result=$work(); $this->commit(); return $result; }
        catch(Throwable $error) { $this->rollback(); throw $error; }
    }
    public function after_commit(callable $callback): void { if($this->transaction)$this->after_commit[]=$callback;else $callback(); }
    public function run_atomic(callable $work) { return $this->atomic($work); }
    public function observe_distribution(int $item_id,string $action,int $to_user,string $to_position,string $reason): void {
        $item=$this->rows($this->sql('SELECT * FROM '.$this->t('distribution_items').' WHERE id=%d',$item_id));
        $ids=[];
        if ($item) {
            $item=$item[0];if ($item['source_type']==='mis_data_row')$ids[(int)$item['source_id']]=true;
            $pids=array_values(array_unique(array_filter([(int)($item['pool_item_id']??0),$item['source_type']==='mis_pool'?(int)$item['source_id']:0])));
            foreach($pids as $pid) { foreach($this->rows($this->sql('SELECT source_row_id FROM '.$this->t('mis_lead_pool').' WHERE id=%d',$pid)) as $pool) { $ids[(int)$pool['source_row_id']]=true; } }
        }
        $rows=$ids?$this->rows('SELECT * FROM '.$this->t('mis_data_rows').' WHERE id IN ('.implode(',',array_keys($ids)).')'):[];
        foreach($rows as $row) {
            $initial=in_array($action,['mis_quick_assignment_to_role','item_created_from_mis_pool','mis_manager_assignment'],true) && $to_position==='sales_manager';
            if ($action==='returned_to_mis') { continue; }
            if ($initial) {
                $exists=$this->value($this->sql('SELECT COUNT(*) FROM '.$this->t('mis_events')." WHERE row_id=%d AND event_type='assigned'",(int)$row['id']));
                if (!$exists) { $this->event($row,'legacy_delivered',[],['assigned_manager_user_id'=>$to_user,'item_id'=>$item_id,'legacy_path'=>true],$reason,$this->operation()); }
            } else {
                $exists=$this->value($this->sql('SELECT COUNT(*) FROM '.$this->t('mis_events')." WHERE row_id=%d AND event_type='consumed'",(int)$row['id']));
                if (!$exists) { $this->event($row,'consumed',['assigned_manager_user_id'=>$row['assigned_manager_user_id']],['assigned_manager_user_id'=>$row['assigned_manager_user_id'],'item_id'=>$item_id,'action'=>$action],$reason,$this->operation(),$this->last_branch((int)$row['id'])); }
            }
        }
    }

}

/** Registered by the light bootstrap too, so lightweight seller actions cannot race MIS. */
final class SN_MIS_Request_Lock
{
    private static string $name='';
    public static function acquire(): void {
        if (self::$name!=='') { return; }
        $action=is_scalar($_REQUEST['action']??'')?sanitize_key((string)($_REQUEST['action']??'')):'';
        $route=(string)($_GET['rest_route']??''); $uri=(string)($_SERVER['REQUEST_URI']??'');
        $read_actions=['sn_campaign_track','sn_project_notifications','sn_hr_transfer_search_users','sn_sales_manager_leads','sn_sales_manager_lazy_tab','sn_senior_supervisor_lazy_tab','sn_seller_leads','sn_seller_invoices','sn_supervisor_distribution_items','sn_get_invoice_info','sn_get_lead_info'];
        if (in_array($action,array_merge($read_actions,['sn_mis_job','sn_mis_download','sn_mis_setup','sn_mis_view']),true) || ($action==='sn_mis_v2' && in_array($_REQUEST['operation']??'',['export','start_export','start_index','start_audit','job_step','job_cancel','preferences','save_report','delete_report','save_view'],true))) { return; }

        if (strpos($action,'sn_')!==0 && strpos($route,'/sn-')!==0 && strpos($uri,'/wp-json/sn-')===false) { return; }
        global $wpdb;
        $name='sn_crm_write_'.substr(hash('sha256',(string)$wpdb->get_var('SELECT DATABASE()').$wpdb->prefix),0,40);
        if ((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,5)',$name))!==1) {
            if (wp_doing_ajax()) { wp_send_json_error(['message'=>'عملیات دیگری در حال ثبت است؛ دوباره تلاش کنید.'],409); }
            wp_die('عملیات دیگری در حال ثبت است؛ دوباره تلاش کنید.','MIS',['response'=>409]);
        }
        self::$name=$name;
        register_shutdown_function([self::class,'release']);
    }
    public static function owns($db): bool { return self::$name!=='' && ($GLOBALS['wpdb']??null)===$db; }
    public static function release(): void {
        if (self::$name==='') { return; }
        global $wpdb;
        $name=self::$name; self::$name='';
        $wpdb->query('ROLLBACK'); SN_MIS_Read_Model::flush($wpdb,true); $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$name));
    }

}
