<?php
/** Synthetic memory-only invoice reporting regression. No WordPress, external DB or provider. */
if (PHP_SAPI !== 'cli' || !class_exists('SQLite3')) { throw new RuntimeException('CLI with SQLite3 required'); }
define('ABSPATH','/synthetic-reporting/'); define('ARRAY_A','ARRAY_A'); define('OBJECT','OBJECT');
$root = getenv('SN_INVOICE_REPORT_SOURCE') ?: dirname(__DIR__,2);
require_once $root.'/includes/class-sn-helpers.php';
require_once $root.'/includes/class-sn-plugin.php';
require_once $root.'/includes/reports/class-sn-report-service.php';
function absint($v): int { return abs((int)$v); }
function wp_unslash($v) { return $v; }
function sanitize_text_field($v): string { return is_scalar($v) ? trim(strip_tags((string)$v)) : ''; }
function sanitize_key($v): string { return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$v)); }
function current_user_can($cap): bool { return !empty($GLOBALS['report_admin']); }
function is_admin(): bool { return true; }
function wp_die($message): void { throw new RuntimeException('DENIED: '.$message); }
function check_admin_referer(...$args): void { if (empty($GLOBALS['report_nonce'])) { wp_die('nonce'); } }
function esc_html($value): string { return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8'); }
function wp_timezone(): DateTimeZone { return new DateTimeZone($GLOBALS['report_timezone'] ?? 'Asia/Tehran'); }
$checks=0;
function ok($value,string $label): void { global $checks; if (!$value) { throw new RuntimeException('FAIL '.$label); } ++$checks; echo 'PASS '.$label.PHP_EOL; }
final class InvoiceReportMemoryDB {
    public string $prefix='test_'; public string $last_error=''; public array $queries=[]; public SQLite3 $memory;
    public function __construct() {
        $this->memory=new SQLite3(':memory:'); $this->memory->enableExceptions(true);
        $this->memory->createFunction('GREATEST','max');
        $this->memory->createFunction('CONCAT',static fn(...$args)=>implode('',$args));
    }
    public function prepare(string $sql,...$args): string {
        $index=0;
        $sql=preg_replace_callback('/%[dsf]/',static function($m) use (&$index,$args) {
            if (!array_key_exists($index,$args)) { throw new RuntimeException('Missing prepared value'); }
            $v=$args[$index++]; return $m[0]==='%s' ? "'".SQLite3::escapeString((string)$v)."'" : (string)(0+$v);
        },$sql);
        if ($index!==count($args)) { throw new RuntimeException('Unused prepared value'); }
        return $sql;
    }
    public function esc_like(string $v): string { return addcslashes($v,'_%\\'); }
    public function get_results(string $sql,$format=ARRAY_A): array {
        if (!preg_match('/^\s*SELECT\b/i',$sql)) { throw new RuntimeException('Report attempted a write'); }
        $this->queries[]=$sql; $query=$this->memory->query($sql); $rows=[];
        while ($row=$query->fetchArray(SQLITE3_ASSOC)) { $rows[]=$format===ARRAY_A ? $row : (object)$row; }
        return $rows;
    }
    public function get_col(string $sql): array {
        if (preg_match('/^SHOW COLUMNS FROM ([a-z_]+)$/',$sql,$m)) {
            $q=$this->memory->query('PRAGMA table_info('.$m[1].')'); $out=[];
            while($r=$q->fetchArray(SQLITE3_ASSOC)) { $out[]=$r['name']; } return $out;
        }
        return array_map(static fn($r)=>array_values($r)[0],$this->get_results($sql));
    }
    public function get_var(string $sql) {
        if (preg_match("/^SHOW TABLES LIKE '([^']+)'$/",$sql,$m)) {
            $sql="SELECT name FROM sqlite_master WHERE type='table' AND name='".SQLite3::escapeString(str_replace('\\_','_',$m[1]))."'";
        }
        $rows=$this->get_results($sql); return $rows ? array_values($rows[0])[0] : null;
    }
}
$wpdb=new InvoiceReportMemoryDB();
$wpdb->memory->exec('CREATE TABLE test_sn_invoices (id INTEGER PRIMARY KEY,seller_id INTEGER,original_seller_id INTEGER,invoice_code TEXT,customer_name TEXT,customer_phone TEXT,product_id INTEGER,province TEXT,city TEXT,status TEXT,invoice_status TEXT,payment_status TEXT,payment_workflow_status TEXT,pay_method TEXT,payment_source TEXT,created_at TEXT,paid_at TEXT,current_payment_stage INTEGER,payment_total_amount REAL,final_total REAL,product_price REAL,paid_total_amount REAL)');
$wpdb->memory->exec('CREATE TABLE test_sn_payments (id INTEGER PRIMARY KEY,invoice_id INTEGER,payment_stage_no INTEGER,amount REAL,status TEXT,pay_method TEXT,payment_source TEXT,authority TEXT,created_at TEXT,updated_at TEXT)');
$wpdb->memory->exec('CREATE TABLE test_sn_invoice_payment_stages (id INTEGER PRIMARY KEY,invoice_id INTEGER,stage_no INTEGER,requested_amount REAL)');
$wpdb->memory->exec('CREATE TABLE test_sn_invoice_items (id INTEGER PRIMARY KEY,invoice_id INTEGER,product_name TEXT,qty INTEGER)');
$wpdb->memory->exec("INSERT INTO test_sn_invoice_items VALUES (1,1,'Synthetic A',2),(2,1,'Synthetic B',1),(3,2,'Synthetic C',1)");
function invoice(int $id,array $changes=[]): void {
    global $wpdb;
    $r=array_replace(['id'=>$id,'seller_id'=>10,'original_seller_id'=>0,'invoice_code'=>'SYN-'.$id,'customer_name'=>'Synthetic customer','customer_phone'=>'','product_id'=>0,'province'=>'','city'=>'','status'=>'pre_invoice','invoice_status'=>null,'payment_status'=>null,'pay_method'=>'card','payment_source'=>'seller_manual','created_at'=>'2026-11-01 12:00:00','paid_at'=>null,'current_payment_stage'=>1,'payment_total_amount'=>1000,'final_total'=>1000,'product_price'=>1000,'paid_total_amount'=>0],$changes);
    $r += ['payment_workflow_status'=>''];
    $values=array_map(static fn($v)=>$v===null ? 'NULL' : "'".SQLite3::escapeString((string)$v)."'",array_values($r));
    $wpdb->memory->exec('INSERT INTO test_sn_invoices ('.implode(',',array_keys($r)).') VALUES ('.implode(',',$values).')');
}
function payment(int $id,int $invoice,float $amount,string $status='approved',string $method='card',string $authority=''): void {
    global $wpdb;
    $wpdb->memory->exec($wpdb->prepare("INSERT INTO test_sn_payments VALUES(%d,%d,1,%f,%s,%s,'synthetic',%s,'2026-11-02 10:00:00','2026-11-02 11:00:00')",$id,$invoice,$amount,$status,$method,$authority));
}
invoice(1,['status'=>'recontact_requested','payment_status'=>'partial_paid','customer_phone'=>'۰۹۱۲۱۲۳۴۵۶۷','payment_total_amount'=>5000,'paid_total_amount'=>2000]); payment(1,1,2000);
invoice(2,['status'=>'paid','invoice_status'=>'paid','customer_phone'=>'+98 912-1234567','payment_total_amount'=>5000,'paid_total_amount'=>5000]); payment(2,2,2000); payment(3,2,3000,'paid','online','synthetic-authority');
invoice(3,['status'=>'approved','invoice_status'=>'approved','payment_status'=>'pending_financial_approval','paid_total_amount'=>1000]); payment(4,3,1000);
invoice(4,['status'=>'pending_financial_approval','payment_total_amount'=>4000]); payment(5,4,1000,'pending'); payment(6,4,2000,'pending','online','synthetic-pending');
invoice(5,['status'=>'paid','invoice_status'=>'paid','paid_total_amount'=>1000]);
invoice(6,['status'=>'cancelled','payment_total_amount'=>5000,'paid_total_amount'=>1000]); payment(7,6,1000);
invoice(7,['status'=>'recontact_requested','payment_total_amount'=>2000]);
invoice(8,['status'=>'pending_financial_approval']);
invoice(9); payment(8,9,1000,'verified','online','synthetic-verified');
invoice(10); payment(9,10,1000,'reconcile_error','online','synthetic-unapplied');
invoice(11,['paid_total_amount'=>1000]); payment(10,11,1100,'approved','admin');
invoice(12,['seller_id'=>20,'paid_total_amount'=>9999,'payment_total_amount'=>9999]); payment(11,12,9999);
$wpdb->memory->exec('INSERT INTO test_sn_invoice_payment_stages VALUES(1,1,1,2000),(2,2,1,5000)');
$plugin=(new ReflectionClass(SN_Plugin::class))->newInstanceWithoutConstructor();
function invoke(string $method,...$args) { global $plugin; $m=new ReflectionMethod($plugin,$method); $m->setAccessible(true); return $m->invoke($plugin,...$args); }
function filters(array $changes=[]): array { return array_replace(['status'=>'all','finance'=>'all','search'=>'','seller_ids'=>[],'supervisor_ids'=>[],'senior_supervisor_ids'=>[],'sales_manager_ids'=>[],'seller_id'=>0,'supervisor_id'=>0,'date_from'=>'','date_to'=>'','time_from'=>'','time_to'=>'','date_error'=>''],$changes); }
$report=new SN_Invoice_Financial_Report($wpdb);
$snapshot=hash('sha256',json_encode($wpdb->get_results('SELECT * FROM test_sn_invoices ORDER BY id')).json_encode($wpdb->get_results('SELECT * FROM test_sn_payments ORDER BY id')));
$rows=$report->for_ids(range(1,12));
ok(count($rows)===12,'one projection per invoice despite multiple transactions');
ok((float)$rows[1]['confirmed_amount']===2000.0 && (float)$rows[1]['remaining_amount']===3000.0,'recontact keeps its confirmed partial receipt and true balance');
ok((float)$rows[2]['confirmed_amount']===5000.0 && (int)$rows[2]['confirmed_count']===2 && SN_Invoice_Financial_Report::settlement($rows[2])==='تسویه کامل','two installments settle one invoice without double counting');
ok((float)$rows[2]['card_amount']===2000.0 && (float)$rows[2]['online_amount']===3000.0,'mixed payment methods retain their actual amounts');
ok((float)$rows[4]['pending_amount']===1000.0 && (int)$rows[4]['pending_count']===1,'unfinished online request is excluded from submitted pending money');
ok((float)$rows[5]['confirmed_amount']===0.0 && (float)$rows[5]['discrepancy_amount']===1000.0,'stored paid total never fabricates a ledger receipt');
ok((float)$rows[9]['confirmed_amount']===0.0 && (float)$rows[9]['unapplied_amount']===1000.0,'verified gateway request is separate from finalized finance');
ok((float)$rows[10]['confirmed_amount']===0.0 && (float)$rows[10]['unapplied_amount']===1000.0,'reconcile error is visible without crediting it');
ok((float)$rows[11]['other_amount']===1100.0 && strpos(SN_Invoice_Financial_Report::method_label('admin'),'نامشخص')!==false,'admin is not guessed to be card or online');
ok(strpos(SN_Invoice_Financial_Report::settlement($rows[11]),'بیش از')!==false,'overpayment is flagged without hiding its amount');
$ids=static function(array $f) use($report): array { [$w,$a]=invoke('build_invoices_where',$f); return array_map('intval',array_column($report->invoices($w,$a,0,500),'id')); };
ok($ids(filters(['status'=>'paid']))===[1,2,3,6,11,12],'received tab uses ledger receipts across workflow states');
ok($ids(filters(['status'=>'needs_review']))===[4,8],'review tab includes real manual pending and guarded legacy fallback only');
ok(in_array(7,$ids(filters(['status'=>'pre_invoice'])),true) && !in_array(1,$ids(filters(['status'=>'pre_invoice'])),true),'pre-invoice tab handles NULL compatibility states without swallowing paid contacts');
ok($ids(filters(['status'=>'cancelled']))===[6],'closed tab retains cancellation independently of money');
ok($ids(filters(['status'=>'recontact_requested']))===[1,7],'follow-up filter remains independent of financial status');
ok($ids(filters(['finance'=>'partial']))===[1,6],'partial settlement filter uses confirmed amount');
ok($ids(filters(['finance'=>'settled']))===[2,3,12],'full settlement filter compares invoice and ledger amount');
ok($ids(filters(['finance'=>'mismatch']))===[5,11],'discrepancy filter does not repair historical data');
ok($ids(filters(['finance'=>'unapplied']))===[9,10],'unapplied gateway filter stays separate');
ok($ids(filters(['status'=>"paid' OR 1=1 --"]))===[],'SQL-like status stays a literal prepared value');
ok($ids(filters(['finance'=>"settled' OR 1=1 --"]))===[],'unknown financial filter fails closed');
ok($ids(filters(['seller_ids'=>[20],'status'=>'paid']))===[12],'hierarchy selection is retained in financial filters');
ok($ids(filters(['seller_ids'=>[999]]))===[],'unknown seller selection returns no data');
ok($ids(filters(['date_error'=>'invalid']))===[],'invalid date cannot silently produce an unfiltered report');
$GLOBALS['report_timezone']='UTC';
[$w,$a]=invoke('build_invoices_where',filters(['date_from'=>'2026-11-01','date_to'=>'2026-11-01']));
ok($a===['2026-10-31 20:30:00','2026-11-01 20:29:59'],'admin Tehran day boundaries convert to WordPress storage timezone');
$GLOBALS['report_timezone']='Asia/Tehran';
$summary=$report->summary('i.seller_id=%d',[10]);
ok((int)$summary['invoice_count']===11 && (float)$summary['confirmed_amount']===10100.0,'summary counts unique invoices and sums actual approved receipts');
ok((float)$summary['closed_remaining_amount']===4000.0 && (float)$summary['active_remaining_amount']===(float)$summary['remaining_amount']-4000.0,'cancelled balances are separated from open-document balances');
$groups=$report->groups('i.id<=2',[],'customers');
ok(count($groups)===1 && (int)$groups[0]['invoice_count']===2 && (float)$groups[0]['confirmed_amount']===7000.0,'Persian and international mobile formats aggregate into the same customer');
$payments=$report->payments('i.id=%d',[2]);
ok(count($payments)===2 && (float)$payments[0]['amount']===2000.0 && (float)$payments[1]['amount']===3000.0,'detail export preserves individual transaction and stage amounts');
ok(!array_key_exists('authority',$payments[0]) && !array_key_exists('proof_data',$payments[0]),'projection omits gateway credentials and raw proofs');
$beforeItems=count($wpdb->queries); $itemLabels=$report->item_labels([1,2]);
ok(count($wpdb->queries)===$beforeItems+1 && $itemLabels[1]==='Synthetic A × 2، Synthetic B' && $itemLabels[2]==='Synthetic C','invoice item labels use one bounded query for a whole report/export batch');
foreach(['approved','pending_financial_approval','recontact_requested','pre_invoice','rejected','partial_paid','pending','pending_payment','payment_archived','awaiting_payment','customer_followed','paid','cancelled'] as $status) {
    ok(SN_Invoice_Financial_Report::status_label($status)!==$status,'Persian label '.$status);
}
foreach(['=1+1'," \t=HYPERLINK(1)",'@SUM(1)','+1','-2'] as $value) { ok(str_starts_with(SN_Invoice_Financial_Report::safe_cell($value),"'"),'formula-safe CSV '.json_encode($value)); }
$stream=tmpfile(); SN_Invoice_Financial_Report::write_row($stream,['00123','<script>alert(1)</script>','=1+1','token=synthetic-private'],true); rewind($stream); $xml=stream_get_contents($stream); fclose($stream);
ok(strpos($xml,'ss:Formula')===false && strpos($xml,'&lt;script&gt;')!==false && strpos($xml,'00123')!==false && strpos($xml,'synthetic-private')===false,'XLS preserves identifiers, escapes text and masks sensitive strings');
$stream=tmpfile(); SN_Invoice_Financial_Report::write_row($stream,['00123','25346191975.00','-100.25'],true,[1,2]); rewind($stream); $xml=stream_get_contents($stream); fclose($stream);
ok(str_contains($xml,'ss:Type="String">00123') && str_contains($xml,'ss:Type="Number">25346191975.00') && str_contains($xml,'ss:Type="Number">-100.25'),'spreadsheet amounts/counts are summable numbers without losing decimal text or identifier zeros');
$GLOBALS['report_admin']=false; $_GET=['sn_export'=>'invoices'];
foreach(['render_admin_invoices','handle_admin_export'] as $method) { try { invoke($method); ok(false,'unauthorized '.$method); } catch(RuntimeException $e) { ok(str_starts_with($e->getMessage(),'DENIED:'),'server denies unrelated role '.$method); } }
$GLOBALS['report_admin']=true; $GLOBALS['report_nonce']=false;
try { invoke('handle_admin_export'); ok(false,'invalid nonce'); } catch(RuntimeException $e) { ok(str_contains($e->getMessage(),'nonce'),'export rejects invalid nonce before reading financial data'); }
foreach([['sn_date_from'=>'2026-02-30'],['sn_date_from'=>['2026-11-01']],['sn_date_from'=>'2026-11-02','sn_date_to'=>'2026-11-01']] as $input) { $_GET=$input; ok(invoke('get_invoice_filters')['date_error']!=='','admin rejects invalid or reversed date input'); }
$_GET=['sn_seller_ids'=>[[20],10],'sn_status'=>['paid']]; $f=invoke('get_invoice_filters'); ok($f['seller_ids']===[10] && $f['status']==='','nonscalar request fields do not reach SQL');
ok(hash('sha256',json_encode($wpdb->get_results('SELECT * FROM test_sn_invoices ORDER BY id')).json_encode($wpdb->get_results('SELECT * FROM test_sn_payments ORDER BY id')))===$snapshot,'all report/filter/export projections preserve financial data');
$wpdb->memory->exec('BEGIN'); for($id=10000;$id<=15000;$id++) { invoice($id); } $wpdb->memory->exec('COMMIT');
$large=$report->summary('i.seller_id=10',[]); $seller=$report->groups('i.seller_id=10',[])[0];
ok((int)$large['invoice_count']===5012 && (int)$seller['invoice_count']===5012 && (float)$seller['confirmed_amount']===10100.0,'seller totals include records beyond the former 5000-row cap');
invoice(16001,['status'=>'recontact_requested','payment_status'=>'payment_archived']);
invoice(16002,['status'=>'pre_invoice','payment_workflow_status'=>'archived']);
[$closedWhere,$closedArgs]=SN_Invoice_Financial_Report::condition($wpdb,'cancelled');
$closed=$report->summary('i.id IN (16001,16002) AND '.$closedWhere,$closedArgs);
ok((int)$closed['invoice_count']===2 && (float)$closed['closed_remaining_amount']===2000.0 && (float)$closed['active_remaining_amount']===0.0,'legacy payment-state and workflow archives are closed without becoming open receivables');
[$draftWhere,$draftArgs]=SN_Invoice_Financial_Report::condition($wpdb,'pre_invoice');
ok((int)$report->summary('i.id IN (16001,16002) AND '.$draftWhere,$draftArgs)['invoice_count']===0,'archive aliases cannot reappear in the pre-invoice tab');
$wpdb->last_error='synthetic read failure'; try { $report->summary('1=1',[]); ok(false,'read failure'); } catch(RuntimeException $e) { ok(true,'database read failure never returns misleading zero totals'); }
echo 'PASS '.$checks.' invoice financial reporting checks'.PHP_EOL;
