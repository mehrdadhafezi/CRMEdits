<?php
if (!defined('ABSPATH')) { exit; }

/** Bounded evidence reads. This cache is never the authority for assigning or returning a number. */
final class SN_MIS_Read_Model
{
    private $db;
    private static array $pending=[];
    private static bool $shutdown_registered=false;
    private ?bool $available=null;
    private static array $columns=[];
    public function __construct($db) { $this->db=$db; }
    private function t(string $name): string { return $this->db->prefix.'sn_'.$name; }
    private function sql(string $q,...$args): string { return $args?$this->db->prepare($q,...$args):$q; }
    private function rows(string $q,...$args): array {
        $r=$this->db->get_results($this->sql($q,...$args),ARRAY_A);
        if ($this->db->last_error!=='' || $r===false) { throw new RuntimeException('خواندن شواهد MIS کامل نشد؛ عملیات ثبت نشد.'); }
        return (array)$r;
    }
    public static function schema(string $p,string $charset): array {
        return [
            "CREATE TABLE IF NOT EXISTS {$p}sn_mis_row_index (
                row_id bigint unsigned NOT NULL, logical_state varchar(20) NOT NULL DEFAULT 'PENDING',
                block_reason varchar(191) NOT NULL DEFAULT '', manager_id bigint unsigned NOT NULL DEFAULT 0,
                is_consumed tinyint(1) NOT NULL DEFAULT 0, assigned_event_id bigint unsigned DEFAULT NULL,
                returned_event_id bigint unsigned DEFAULT NULL, flags_json text, checked_at datetime NOT NULL,
                PRIMARY KEY(row_id), KEY state_row(logical_state,row_id), KEY manager_state(manager_id,logical_state,row_id),
                KEY consumed_row(is_consumed,row_id), KEY checked_row(checked_at,row_id)
            ) ENGINE=InnoDB {$charset}",
            "CREATE TABLE IF NOT EXISTS {$p}sn_mis_index_dirty (
                row_id bigint unsigned NOT NULL, revision bigint unsigned NOT NULL DEFAULT 1,
                touched_at datetime NOT NULL, PRIMARY KEY(row_id)
            ) ENGINE=InnoDB {$charset}"
        ];
    }
    public function ready(): bool {
        if ($this->available!==null) { return $this->available; }
        foreach(['mis_row_index','mis_index_dirty'] as $name) {
            $r=$this->rows('SHOW TABLES LIKE %s',$this->db->esc_like($this->t($name)));
            if (!$r) { return $this->available=false; }
        }
        return $this->available=true;
    }
    public static function queue($db,array $ids): void {
        $key=spl_object_id($db);
        if (!isset(self::$pending[$key])) { self::$pending[$key]=['db'=>$db,'ids'=>[]]; }
        foreach($ids as $id) { if ((int)$id>0) { self::$pending[$key]['ids'][(int)$id]=true; } }
        if (!self::$shutdown_registered) {
            self::$shutdown_registered=true;
            register_shutdown_function(static function() { self::flush_all(false); });
        }
    }
    public static function flush_all(bool $refresh=false): void {
        foreach(array_keys(self::$pending) as $key) { self::flush(self::$pending[$key]['db'],$refresh); }
    }
    public static function flush($db,bool $refresh=true): void {
        $key=spl_object_id($db); $entry=self::$pending[$key]??null;
        if (!$entry || !$entry['ids']) { return; }
        unset(self::$pending[$key]);
        try {
            $model=new self($db); if (!$model->ready()) { return; }
            $ids=array_keys($entry['ids']); $now=gmdate('Y-m-d H:i:s');
            foreach(array_chunk($ids,500) as $chunk) {
                $values=[]; $args=[];
                foreach($chunk as $id) { $values[]='(%d,1,%s)'; array_push($args,$id,$now); }
                $q=$db->prepare('INSERT INTO '.$model->t('mis_index_dirty').' (row_id,revision,touched_at) VALUES '.implode(',',$values).' ON DUPLICATE KEY UPDATE revision=revision+1,touched_at=VALUES(touched_at)',...$args);
                if ($db->query($q)===false) { throw new RuntimeException('index invalidation'); }
            }
            if ($refresh) { $model->refresh_ids(array_slice($ids,-1000)); }
            self::bump();
        } catch(Throwable $e) { update_option('sn_mis_index_needs_rebuild',1,false);self::bump();error_log('SN MIS: derived index refresh is pending; business records were retained.'); }
    }
    public static function bump(): void { if (function_exists('update_option')) { update_option('sn_mis_read_epoch',bin2hex(random_bytes(6)),false); } }
    public static function normalize(string $phone): string {
        $phone=strtr($phone,array_combine(preg_split('//u','۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩',-1,PREG_SPLIT_NO_EMPTY),str_split('01234567890123456789')));
        $phone=preg_replace('/\D/','',$phone);
        if (str_starts_with($phone,'0098')) { $phone='0'.substr($phone,4); }
        elseif (str_starts_with($phone,'98')) { $phone='0'.substr($phone,2); }
        elseif (strlen($phone)===10 && $phone[0]==='9') { $phone='0'.$phone; }
        return $phone;
    }
    private function in(array $values,string $type='%d'): string { return implode(',',array_fill(0,count($values),$type)); }
    private function get_in(string $table,string $column,array $values,string $type='%d',string $extra=''): array {
        if (!$values) { return []; }
        return $this->rows('SELECT * FROM '.$this->t($table).' WHERE '.$column.' IN ('.$this->in($values,$type).') '.$extra,...array_values($values));
    }
    private function has_column(string $table,string $column): bool {
        $key=$this->db->prefix.$table.'.'.$column;
        if(!array_key_exists($key,self::$columns)) { self::$columns[$key]=(bool)$this->rows('SHOW COLUMNS FROM '.$this->t($table).' LIKE %s',$this->db->esc_like($column)); }return self::$columns[$key];
    }
    private function variants(array $phones): array {
        $out=[];foreach($phones as $p) { $out[$p]=true;if(preg_match('/^09[0-9]{9}$/',$p)) { $tail=substr($p,1);foreach([$tail,'98'.$tail,'+98'.$tail,'0098'.$tail] as $v)$out[$v]=true; } }return array_keys($out);
    }
    /** All joins run from selected source IDs toward indexed foreign keys, never invoice -> all MIS rows. */
    public function classify(array $source): array {
        if (count($source)>250) {
            $out=[]; foreach(array_chunk($source,250) as $chunk) { $out+=$this->classify($chunk); } return $out;
        }
        if (!$source) { return []; }
        $records=[]; foreach($source as $row) { $records[(int)$row['id']]=$row; }
        $ids=array_keys($records); $marks=$this->in($ids); $pool=[]; $by_row=[];
        foreach($this->get_in('mis_lead_pool','source_row_id',$ids) as $p) { $pool[(int)$p['id']]=$p; $by_row[(int)$p['source_row_id']][]=$p; }
        $items=[];
        foreach($this->rows("SELECT * FROM ".$this->t('distribution_items')." WHERE source_type='mis_data_row' AND source_id IN ({$marks})",...$ids) as $item) { $items[(int)$item['id']]=$item; }
        if ($pool) {
            $pids=array_keys($pool);$pm=$this->in($pids);
            foreach($this->rows('SELECT * FROM '.$this->t('distribution_items')." WHERE pool_item_id IN ({$pm})",...$pids) as $item) { $items[(int)$item['id']]=$item; }
            foreach($this->rows('SELECT * FROM '.$this->t('distribution_items')." WHERE source_type='mis_pool' AND source_id IN ({$pm})",...$pids) as $item) { $items[(int)$item['id']]=$item; }
        }
        $extra_pool=[];
        foreach($items as $item) { foreach([(int)($item['pool_item_id']??0),$item['source_type']==='mis_pool'?(int)$item['source_id']:0] as $pid) { if ($pid && !isset($pool[$pid])) { $extra_pool[$pid]=true; } } }
        foreach($this->get_in('mis_lead_pool','id',array_keys($extra_pool)) as $p) { $pool[(int)$p['id']]=$p; }
        $item_rows=[]; $origins=[];
        foreach($items as $iid=>$item) {
            $origin=[];
            if ($item['source_type']==='mis_data_row') { $origin[(int)$item['source_id']]=true; }
            foreach([(int)($item['pool_item_id']??0),$item['source_type']==='mis_pool'?(int)$item['source_id']:0] as $pid) { if (isset($pool[$pid])) { $origin[(int)$pool[$pid]['source_row_id']]=true; } }
            $origins[$iid]=array_keys($origin);
            foreach([(int)($item['pool_item_id']??0),$item['source_type']==='mis_pool'?(int)$item['source_id']:0] as $pid) { if($pid && !isset($pool[$pid]))$origins[$iid][]=-$pid; }
            foreach(array_keys($origin) as $rid) { if (isset($records[$rid])) { $item_rows[$rid][$iid]=$item; } }
        }
        $logs=[];
        if ($items) {
            $iids=array_keys($items);$im=$this->in($iids);
            foreach($this->rows("SELECT item_id,MAX(action NOT IN ('mis_quick_assignment_to_role','item_created_from_mis_pool','mis_manager_assignment','returned_to_mis') OR COALESCE(to_position,'') NOT IN ('','mis','sales_manager') OR (COALESCE(from_user_id,0)>0 AND COALESCE(to_user_id,0)>0 AND from_user_id<>to_user_id)) used,MAX(action IN ('mis_quick_assignment_to_role','item_created_from_mis_pool','mis_manager_assignment') AND to_position='sales_manager' AND COALESCE(from_user_id,0)=0) known FROM ".$this->t('distribution_item_logs')." WHERE item_id IN ({$im}) GROUP BY item_id",...$iids) as $log) { $logs[(int)$log['item_id']]=$log; }
        }
        $event_ids=[]; $event_maps=[];
        foreach($this->rows('SELECT row_id,event_type,MAX(id) id FROM '.$this->t('mis_events')." WHERE row_id IN ({$marks}) AND event_type IN ('assigned','returned','consumed') GROUP BY row_id,event_type",...$ids) as $e) { $event_maps[(int)$e['row_id']][$e['event_type']]=(int)$e['id'];$event_ids[(int)$e['id']]=true; }
        $events=[]; foreach($this->get_in('mis_events','id',array_keys($event_ids)) as $e) { $events[(int)$e['id']]=$e; }
        $phones=[];
        foreach($records as $row) { $p=self::normalize((string)(($row['normalized_phone']??'')?:$row['customer_phone']));if($p!=='')$phones[$p]=true; }
        $phone_list=array_keys($phones);$leads=[]; $lead_phone=[];
        foreach(array_chunk($this->variants($phone_list),500) as $chunk) {
            foreach($this->get_in('leads','phone',$chunk,'%s') as $lead) { $p=self::normalize((string)$lead['phone']);$leads[(int)$lead['id']]=$lead;$lead_phone[$p][(int)$lead['id']]=true; }
        }
        $invoice_leads=array_fill_keys(array_keys($leads),true);$invoice_ids=[];
        foreach($items as $item) { $invoice_leads[900000000+(int)$item['id']]=true;if((int)($item['live_lead_id']??0)>0)$invoice_leads[(int)$item['live_lead_id']]=true; foreach(['follow_invoice_id','ref_invoice_id'] as $col) { if((int)($item[$col]??0)>0)$invoice_ids[(int)$item[$col]]=true; } }
        $invoices_by_lead=[];$invoices=[];
        foreach(array_chunk(array_keys($invoice_leads),1000) as $chunk) { foreach($this->get_in('invoices','lead_id',$chunk) as $invoice) { $invoices[(int)$invoice['id']]=$invoice;$invoices_by_lead[(int)$invoice['lead_id']][(int)$invoice['id']]=true; } }
        foreach(array_chunk(array_keys($invoice_ids),1000) as $chunk) { foreach($this->get_in('invoices','id',$chunk) as $invoice) { $invoices[(int)$invoice['id']]=$invoice; } }
        $invoices_by_phone=[];
        if($this->has_column('invoices','customer_phone')) {
            foreach(array_chunk($this->variants($phone_list),500) as $chunk) { foreach($this->get_in('invoices','customer_phone',$chunk,'%s') as $invoice) { $invoices[(int)$invoice['id']]=$invoice;$invoices_by_phone[self::normalize((string)$invoice['customer_phone'])][(int)$invoice['id']]=true; } }
        }
        $duplicates=[];
        foreach(array_chunk($phone_list,500) as $chunk) { foreach($this->rows('SELECT id,normalized_phone,assigned_manager_user_id FROM '.$this->t('mis_data_rows').' WHERE normalized_phone IN ('.$this->in($chunk,'%s').") AND row_status='valid'",...$chunk) as $d) { $duplicates[self::normalize($d['normalized_phone'])][]=$d; } }
        $headers=[];$batchids=array_values(array_unique(array_map(fn($r)=>(int)$r['batch_id'],$records)));
        foreach($this->get_in('mis_import_batches','id',$batchids) as $b) { $headers[(int)$b['id']]=$b; }
        $claims=[];foreach($this->get_in('mis_claims','row_id',$ids) as $c) { $claims[(int)$c['row_id']]=$c; }
        $reserved=[];
        foreach($this->rows('SELECT pi.source_row_id,pi.created_lead_id,pi.item_status,ph.id plan_exists,ph.plan_status FROM '.$this->t('mis_distribution_plan_items').' pi LEFT JOIN '.$this->t('mis_distribution_plans')." ph ON ph.id=pi.plan_id WHERE pi.source_row_id IN ({$marks})",...$ids) as $pi) {
            if ((int)$pi['created_lead_id']>0 || (!in_array($pi['item_status'],['skipped','cancelled'],true) && (!$pi['plan_exists'] || !in_array($pi['plan_status'],['cancelled','archived'],true)))) { $reserved[(int)$pi['source_row_id']]=true; }
        }
        $out=[];
        foreach($records as $id=>$row) {
            $phone=self::normalize((string)(($row['normalized_phone']??'')?:$row['customer_phone']));$manager=(int)($row['assigned_manager_user_id']??0);
            $ev=$event_maps[$id]??[];$linked=$item_rows[$id]??[];$known=!empty($ev['assigned']);$history=false;$operated=false;$invoice=false;$lead=!empty($lead_phone[$phone]);$matched=$invoices_by_phone[$phone]??[];$invoice=(bool)$matched;$active=0;$ambiguous=false;$pool_used=false;$conflict=($row['normalized_phone']??'')==='' || !preg_match('/^09[0-9]{9}$/',$phone);
            foreach(array_keys($lead_phone[$phone]??[]) as $leadid) { if(isset($invoices_by_lead[$leadid])) { $invoice=true;$matched+=$invoices_by_lead[$leadid]; } }
            foreach($linked as $iid=>$item) {
                $known=$known || !empty($logs[$iid]['known']);$history=$history || !empty($logs[$iid]['used']);
                if ($item['status']!=='returned_to_mis') { $active++; }
                if (count($origins[$iid])>1) { $ambiguous=true; }
                if (isset($invoices_by_lead[900000000+$iid]) || ((int)($item['live_lead_id']??0)>0 && isset($invoices_by_lead[(int)$item['live_lead_id']])) || isset($invoices[(int)($item['follow_invoice_id']??0)]) || isset($invoices[(int)($item['ref_invoice_id']??0)])) { $invoice=true; }
                $matched+=($invoices_by_lead[900000000+$iid]??[]);if((int)($item['live_lead_id']??0)>0)$matched+=($invoices_by_lead[(int)$item['live_lead_id']]??[]);foreach(['follow_invoice_id','ref_invoice_id'] as $col) { $fid=(int)($item[$col]??0);if(isset($invoices[$fid]))$matched[$fid]=true; }
                if ((int)($item['live_lead_id']??0)>0 || (int)($item['follow_invoice_id']??0)>0 || (int)($item['ref_invoice_id']??0)>0 || (int)($item['final_seller_user_id']??0)>0 || (string)($item['seller_contact_status']??'')!=='' || !empty($item['seller_updated_at']) || (string)($item['seller_notes']??'')!=='' || (int)($item['seller_sale_probability']??0)>0 || (int)($item['current_step_order']??0)>0 || !in_array((string)($item['current_owner_position']??''),['','mis','sales_manager'],true) || !in_array($item['status'],['assigned_to_role','ready_for_distribution','returned_to_mis'],true) || ($manager>0 && (int)$item['current_owner_user_id']>0 && (int)$item['current_owner_user_id']!==$manager)) { $operated=true; }
            }
            $resolved=$manager;
            foreach($linked as $item) { if (!$resolved && $item['current_owner_position']==='sales_manager' && $item['status']!=='returned_to_mis') { $resolved=(int)$item['current_owner_user_id']; } }
            foreach($by_row[$id]??[] as $p) { if (!$resolved)$resolved=(int)($p['assigned_manager_user_id']??0);if ((int)($p['assigned_seller_user_id']??0)>0 || !in_array($p['pool_status'],['ready','returned_to_mis'],true))$pool_used=true;if ((int)($p['assigned_manager_user_id']??0)>0 && !$manager)$conflict=true; }
            $claim_conflict=isset($claims[$id]) && (self::normalize((string)$claims[$id]['normalized_phone'])!==$phone || (int)$claims[$id]['owner_user_id']!==$manager);if($claim_conflict)$conflict=true;
            $duplicate=false;foreach($duplicates[$phone]??[] as $d) { if ((int)$d['id']!==$id && ((int)($d['assigned_manager_user_id']??0)>0 || (!$manager && (int)$d['id']<$id))) { $duplicate=true;break; } }
            $orphan=!isset($headers[(int)$row['batch_id']]);$closed=!$manager && isset($headers[(int)$row['batch_id']]) && in_array($headers[(int)$row['batch_id']]['import_status'],['archived','deleted','file_deleted','reset_deleted'],true);
            $consumed=$lead || $invoice || $history || $operated || $pool_used || !empty($ev['consumed']);$is_reserved=!empty($reserved[$id]);
            $unknown=$orphan || $duplicate || $closed || $conflict || $is_reserved || $active>1 || $ambiguous || (($manager>0 || $linked) && !$known) || (!$manager && $active>0);
            $state=$row['row_status']==='invalid'?'INVALID':($row['row_status']==='duplicate'?'DUPLICATE':($consumed?'CONSUMED':($unknown?'LOCKED':($manager?'MANAGER_HELD':($row['row_status']==='valid'?'RAW':'LOCKED')))));
            $reason=$invoice?'دارای فاکتور':($lead?'دارای لید':(($history||$operated||$pool_used)?'تخصیص بعدی یا تعیین وضعیت':(!empty($ev['consumed'])?'سابقه مصرف ثبت شده است':($is_reserved?'رزرو در برنامه توزیع':(($active>1||$ambiguous)?'چند آیتم یا ارتباط توزیع متعارض؛ نیازمند بررسی':($orphan?'پرونده مبنا موجود نیست؛ نیازمند بررسی':($duplicate?'شماره دارای هویت تکراری در مخزن است':($closed?'پرونده بایگانی یا بسته شده است':($conflict?'هویت یا مالکیت تاریخی نیازمند بررسی است':($unknown?'سابقه تاریخی کافی نیست':''))))))))));
            $flags=['lead'=>$lead,'invoice'=>$invoice,'reserved'=>$is_reserved,'history'=>$history,'operated'=>$operated||$pool_used,'unknown'=>$unknown,'duplicate'=>$duplicate,'orphan_batch'=>$orphan,'multiple_items'=>$active>1||$ambiguous,'consumed_event'=>!empty($ev['consumed']),'claim_conflict'=>$claim_conflict];
            $out[$id]=['logical_state'=>$state,'block_reason'=>$reason,'resolved_manager_id'=>$resolved,'is_consumed'=>$consumed?1:0,'_consumed'=>$consumed,'_reserved'=>$is_reserved,'_has_items'=>(bool)$linked,'_identity_safe'=>!($orphan||$duplicate||$closed||$conflict||$active>1||$ambiguous||(($manager>0||$linked)&&!$known)||(!$manager&&$active>0)),'_flags'=>$flags,'_assigned_event'=>$events[$ev['assigned']??0]??null,'_returned_event'=>$events[$ev['returned']??0]??null,'_invoice_ids'=>array_keys($matched),'_paid_invoice_ids'=>array_values(array_filter(array_keys($matched),fn($iid)=>in_array($invoices[$iid]['status'],['paid','approved','completed'],true)))];
        }
        return $out;
    }
    public function refresh_ids(array $ids,bool $strict=false): array {
        $ids=array_values(array_unique(array_filter(array_map('intval',$ids))));sort($ids);
        if (!$ids) { return []; }
        if (count($ids)>250) { $all=[];foreach(array_chunk($ids,250) as $chunk) { $all+=$this->refresh_ids($chunk,$strict); }return $all; }
        $input=$this->get_in('mis_data_rows','id',$ids);
        if (!$this->ready()) { return $this->classify($input); }
        $timeout=(int)$this->db->get_var('SELECT @@SESSION.innodb_lock_wait_timeout'); $facts=null;
        try {
            $this->db->query('SET SESSION innodb_lock_wait_timeout=2');
            if ($this->db->query('START TRANSACTION')===false) { throw new RuntimeException('index transaction'); }
            $this->rows('SELECT row_id,revision FROM '.$this->t('mis_index_dirty').' WHERE row_id IN ('.$this->in($ids).') ORDER BY row_id FOR UPDATE',...$ids);
            $input=$this->get_in('mis_data_rows','id',$ids);
            $facts=$this->classify($input);$values=[];$args=[];$now=gmdate('Y-m-d H:i:s');
            foreach($facts as $id=>$fact) {
                $values[]='(%d,%s,%s,%d,%d,%d,%d,%s,%s)';
                array_push($args,$id,$fact['logical_state'],$fact['block_reason'],$fact['resolved_manager_id'],$fact['is_consumed'],(int)($fact['_assigned_event']['id']??0),(int)($fact['_returned_event']['id']??0),wp_json_encode($fact['_flags'],JSON_UNESCAPED_UNICODE),$now);
            }
            if ($values) {
                $q='INSERT INTO '.$this->t('mis_row_index').' (row_id,logical_state,block_reason,manager_id,is_consumed,assigned_event_id,returned_event_id,flags_json,checked_at) VALUES '.implode(',',$values).' ON DUPLICATE KEY UPDATE logical_state=VALUES(logical_state),block_reason=VALUES(block_reason),manager_id=VALUES(manager_id),is_consumed=VALUES(is_consumed),assigned_event_id=VALUES(assigned_event_id),returned_event_id=VALUES(returned_event_id),flags_json=VALUES(flags_json),checked_at=VALUES(checked_at)';
                if ($this->db->query($this->sql($q,...$args))===false) { throw new RuntimeException('index upsert'); }
            }
            if ($this->db->query($this->sql('DELETE FROM '.$this->t('mis_index_dirty').' WHERE row_id IN ('.$this->in($ids).')',...$ids))===false || $this->db->query('COMMIT')===false) { throw new RuntimeException('index checkpoint'); }
            return $facts;
        } catch(Throwable $e) {
            $this->db->query('ROLLBACK');
            if($strict)throw new RuntimeException('ذخیره نمای آماری کامل نشد؛ همین مرحله را دوباره اجرا کنید.');
            if ($facts!==null) { return $facts; }
            return $this->classify($input);
        } finally { if ($timeout>0) { $this->db->query('SET SESSION innodb_lock_wait_timeout='.$timeout); } }
    }
    public function coverage(): array {
        $total=(int)$this->db->get_var('SELECT COUNT(*) FROM '.$this->t('mis_data_rows'));
        if (!$this->ready()) { return ['total'=>$total,'indexed'=>0,'pending'=>$total,'oldest'=>null,'ready'=>false]; }
        $row=$this->rows('SELECT COUNT(*) indexed,MIN(ix.checked_at) oldest FROM '.$this->t('mis_row_index').' ix INNER JOIN '.$this->t('mis_data_rows').' r ON r.id=ix.row_id LEFT JOIN '.$this->t('mis_index_dirty').' d ON d.row_id=ix.row_id WHERE d.row_id IS NULL')[0];
        $indexed=(int)$row['indexed'];return ['total'=>$total,'indexed'=>$indexed,'pending'=>max(0,$total-$indexed),'oldest'=>$row['oldest'],'ready'=>true];
    }
    public function coverage_hint(): array {
        if(!$this->ready())return ['total'=>0,'indexed'=>0,'pending'=>0,'oldest'=>null,'ready'=>false];
        $key='sn_mis_coverage_'.substr(hash('sha256',$this->db->prefix),0,24);$hint=get_transient($key);
        if(is_array($hint))return $hint;
        $hint=$this->coverage();$hint['as_of']=gmdate('Y-m-d H:i:s');set_transient($key,$hint,30);return $hint;
    }
    public function missing_step(int $limit=100): array {
        if (!$this->ready()) { return []; }
        $limit=max(1,min(500,$limit));
        $rows=$this->rows('SELECT r.id FROM '.$this->t('mis_data_rows').' r LEFT JOIN '.$this->t('mis_row_index').' ix ON ix.row_id=r.id LEFT JOIN '.$this->t('mis_index_dirty').' d ON d.row_id=r.id WHERE ix.row_id IS NULL OR d.row_id IS NOT NULL ORDER BY r.id LIMIT '.$limit);
        return $this->refresh_ids(array_column($rows,'id'));
    }
    public function hydrate(array $rows,bool $history=false,bool $write_cache=true): array {
        if (!$rows) { return []; }
        $sources=[];foreach($rows as $r) { $sources[(int)$r['id']]=$r; }
        $facts=$write_cache?$this->refresh_ids(array_keys($sources)):$this->classify(array_values($sources));
        foreach($rows as &$row) {
            $fact=$facts[(int)$row['id']]??null;
            if (!$fact) { continue; }
            foreach(['logical_state','block_reason','resolved_manager_id','is_consumed','_flags'] as $col) { $row[$col]=$fact[$col]; }
            $returned=$fact['_returned_event'];$row['return_time']=$returned['created_at']??null;$row['return_reason']=$returned['reason']??null;
            if (!$history) {
                $assigned=$fact['_assigned_event'];$row['branch_key']=$assigned['branch_key']??'unknown';$row['branch_label']=$assigned['branch_label']??'نامشخص';$row['event_time']=$assigned['created_at']??null;$row['event_type']=$assigned['event_type']??null;$row['event_reason']=$assigned['reason']??null;$row['old_manager_id']=$assigned['old_manager_id']??null;$row['new_manager_id']=$assigned['new_manager_id']??null;
            }
        } unset($row);return $rows;
    }
}
