<?php
/** Synthetic memory regression. No WordPress, external connection, real invoice or bank call. */
if (PHP_SAPI !== 'cli' || !class_exists('SQLite3')) { throw new RuntimeException('CLI SQLite3 required'); }
define('ABSPATH', '/synthetic-gateway-test/');
define('ARRAY_A', 'ARRAY_A');
define('OBJECT', 'OBJECT');
function get_current_user_id(): int { return 41; }
function current_time($type) { return $type === 'mysql' ? '2026-11-20 12:00:00' : time(); }
function get_option($name, $default = false) { return $default; }
function absint($value): int { return abs((int)$value); }
function sanitize_key($value): string { return preg_replace('/[^a-z0-9_-]/', '', strtolower((string)$value)); }
function wp_json_encode($value, $flags = 0): string { return json_encode($value, $flags | JSON_THROW_ON_ERROR); }
function wp_remote_post($url, $args) {
    if ($url !== 'https://gateway.zibal.ir/v1/inquiry') { throw new RuntimeException('Non-inquiry provider request rejected'); }
    $GLOBALS['provider_calls']++;
    return ['code'=>200, 'body'=>wp_json_encode($GLOBALS['provider_reply'])];
}
function is_wp_error($value): bool { return false; }
function wp_remote_retrieve_response_code($value): int { return $value['code']; }
function wp_remote_retrieve_body($value): string { return $value['body']; }
require_once dirname(__DIR__, 2).'/includes/class-sn-invoice.php';
require_once dirname(__DIR__, 2).'/includes/class-sn-payment-gateway-guard.php';
require_once dirname(__DIR__, 2).'/includes/class-sn-payment-transactions.php';

final class GatewaySelectionMemoryDB {
    public string $prefix = 'test_';
    public string $last_error = '';
    public SQLite3 $db;
    public string $engine = 'InnoDB';
    public bool $busy = false;
    public int $locks = 0;
    public ?string $fail = null;
    public int $fail_after = 0;
    public function __construct() {
        $this->db = new SQLite3(':memory:');
        $this->db->enableExceptions(true);
        $this->db->createFunction('regexp', static fn($pattern,$value)=>preg_match('~'.$pattern.'~D', (string)$value));
        $this->db->exec('CREATE TABLE test_sn_invoices (id INTEGER PRIMARY KEY,current_payment_stage INTEGER,status TEXT,invoice_code TEXT)');
        $this->db->exec('CREATE TABLE test_sn_invoice_payment_stages (invoice_id INTEGER,stage_no INTEGER,status TEXT,pay_method TEXT)');
        $this->db->exec('CREATE TABLE test_sn_payments (id INTEGER PRIMARY KEY,invoice_id INTEGER,payment_stage_no INTEGER,status TEXT,authority TEXT,amount REAL,proof_data TEXT,ref_id TEXT,created_at TEXT,updated_at TEXT,pay_method TEXT,payment_source TEXT)');
        $this->db->exec("INSERT INTO test_sn_invoices VALUES(1,1,'pending','SYNTHETIC240')");
        $this->db->exec("INSERT INTO test_sn_invoice_payment_stages VALUES(1,1,'pending','online')");
    }
    public function prepare(string $sql, ...$values): string {
        $i=0;
        $out=preg_replace_callback('/%[dsf]/', static function($m)use(&$i,$values){
            $v=$values[$i++];return $m[0]==='%s'?"'".SQLite3::escapeString((string)$v)."'":(string)(0+$v);
        }, $sql);
        if($i!==count($values))throw new RuntimeException('Prepared argument count');return $out;
    }
    private function sql(string $sql): string { return preg_replace('/ FOR UPDATE$/', '', $sql); }
    public function get_results(string $sql, $format = OBJECT): array {
        $this->last_error='';$result=$this->db->query($this->sql($sql));$rows=[];
        while($r=$result->fetchArray(SQLITE3_ASSOC))$rows[]=$format===ARRAY_A?$r:(object)$r;return $rows;
    }
    public function get_row(string $sql, $format = OBJECT) { return $this->get_results($sql,$format)[0]??null; }
    public function get_var(string $sql) {
        $this->last_error='';
        if(str_contains($sql,'GET_LOCK(')){if($this->busy)return 0;$this->locks++;return 1;}
        if(str_contains($sql,'RELEASE_LOCK(')){$this->locks--;return 1;}
        if(str_contains($sql,'information_schema.TABLES'))return $this->engine;
        $row=$this->get_row($sql,ARRAY_A);return $row?array_values($row)[0]:null;
    }
    public function query(string $sql) {
        $this->last_error='';
        if($this->fail!==null&&str_contains($sql,$this->fail)&&$this->fail_after--===0){$this->last_error='Synthetic injected failure';return false;}
        if($sql==='START TRANSACTION')$sql='BEGIN';
        $this->db->exec($this->sql($sql));return $this->db->changes();
    }
}
$checks=0;
function gs_ok($condition,string $label): void {
    global $checks;if(!$condition)throw new RuntimeException('FAIL '.$label);$checks++;echo 'PASS '.$label.PHP_EOL;
}
function gs_fresh(array $statuses=['pending']): void {
    global $wpdb;$wpdb=new GatewaySelectionMemoryDB();
    foreach($statuses as $i=>$status){$wpdb->db->exec($wpdb->prepare("INSERT INTO test_sn_payments VALUES(%d,1,1,%s,%s,1980000,NULL,NULL,'2020-01-01 00:00:00','2020-01-01 00:00:00','online','zibal')",$i+1,$status,'zibal:'.(24000+$i)));}
}
function gs_rows(): array { global $wpdb;return $wpdb->get_results('SELECT * FROM test_sn_payments ORDER BY id',ARRAY_A); }
function gs_switch($status=-1,?callable $inquiry=null,?callable $authorize=null): array {
    return SN_Payment_Gateway_Guard::switch_to_manual(1,1,$inquiry??static fn($r)=>['success'=>true,'status'=>$status],$authorize??static fn($i)=>$i->status==='pending');
}
gs_fresh();$before=gs_rows();
gs_ok(SN_Payment_Gateway_Guard::snapshot(1,1)['blocked'],'old pending request is not proof of payment or age cancellation');
$r=SN_Payment_Gateway_Guard::check(1,1,static fn($row)=>['success'=>true,'status'=>-1]);
gs_ok($r['success']&&$r['gateway_guard']['blocked']&&gs_rows()===$before,'inquiry alone cannot silently choose a new payment method');
$r=gs_switch();$held=gs_rows()[0];
gs_ok($r['success']&&$held['status']==='manual_hold'&&$held['authority']===$before[0]['authority']&&$held['amount']===$before[0]['amount'],'explicit unpaid selection preserves bank request and amount');
$audit=json_decode($held['proof_data'],true)['_gateway_manual_selection'];
gs_ok($audit['actor_user_id']===41&&$audit['provider_status']===-1&&$audit['version']===1,'manual choice has an atomic audit snapshot');
gs_ok(!$r['gateway_guard']['blocked']&&$r['gateway_guard']['manual_selected'],'selection enables receipt capture without treating bank request as cancelled');
gs_ok(!SN_Payment_Transactions::summary(gs_rows(),1,1980000)['independent']&&SN_Payment_Transactions::summary(gs_rows(),1,1980000)['recorded_amount']===0.0,'held gateway intent is neither a receipt nor recorded money');
$heldRows=gs_rows();$r=gs_switch();
gs_ok($r['success']&&!empty($r['already'])&&gs_rows()===$heldRows&&$wpdb->locks===0,'repeat selection is idempotent and releases the stage lock');
$GLOBALS['provider_calls']=0;$GLOBALS['provider_reply']=['result'=>100,'status'=>-1,'amount'=>19800000,'orderId'=>'SYNTHETIC240'];
gs_ok(SN_Payment_Gateway_Guard::manual_approval_error(1,1,'SYNTHETIC240')===''&&$GLOBALS['provider_calls']===1,'manual approval freshly re-inquires the held request');
foreach([1,2] as $status){$GLOBALS['provider_reply']['status']=$status;gs_ok(SN_Payment_Gateway_Guard::manual_approval_error(1,1,'SYNTHETIC240')!=='','bank-reported payment cannot be manually approved');}
$GLOBALS['provider_reply']['status']=-1;$GLOBALS['provider_reply']['amount']=1;
gs_ok(SN_Payment_Gateway_Guard::manual_approval_error(1,1,'SYNTHETIC240')!=='','mismatched approval inquiry fails closed');
foreach([1,2,0,-2,4,999,'-1',null] as $status){gs_fresh();$before=gs_rows();$r=gs_switch($status);gs_ok(!$r['success']&&gs_rows()===$before&&$wpdb->locks===0,'paid/unknown/invalid status cannot switch '.json_encode($status));}
gs_fresh();$before=gs_rows();$r=gs_switch(-1,static fn($row)=>['success'=>false,'status'=>-1]);
gs_ok(!$r['success']&&gs_rows()===$before,'provider failure cannot unlock manual payment');
foreach([3,15,18] as $status){gs_fresh();$r=gs_switch($status);gs_ok($r['success']&&gs_rows()[0]['status']==='failed'&&!$r['gateway_guard']['blocked'],'confirmed terminal selection uses failed rather than a live hold '.$status);}
gs_fresh(['pending','pending']);$seen=[];$r=gs_switch(-1,static function($row)use(&$seen){$seen[]=$row['id'];return ['success'=>true,'status'=>-1];});
gs_ok($r['success']&&count($seen)===2&&array_column(gs_rows(),'status')===['manual_hold','manual_hold'],'all concurrent pending requests must be re-inquired and selected');
gs_fresh(['pending','pending']);$before=gs_rows();$r=gs_switch(-1,static fn($row)=>['success'=>true,'status'=>$row['id']===2?2:-1]);
gs_ok(!$r['success']&&gs_rows()===$before,'one paid request prevents every change in a multi-request switch');
gs_fresh(['pending','pending']);$before=gs_rows();$wpdb->fail='UPDATE test_sn_payments';$wpdb->fail_after=1;$r=gs_switch();
gs_ok(!$r['success']&&gs_rows()===$before&&$wpdb->locks===0,'failure on second write rolls back the first write and its audit');
gs_fresh();$before=gs_rows();$wpdb->fail='COMMIT';$r=gs_switch();
gs_ok(!$r['success']&&gs_rows()===$before,'commit failure preserves the original request');
gs_fresh();$before=gs_rows();$r=gs_switch(-1,null,static fn($invoice)=>false);
gs_ok(!$r['success']&&gs_rows()===$before,'missing current authorization cannot switch');
gs_fresh();$before=gs_rows();$authCalls=0;$r=gs_switch(-1,null,static function($invoice)use(&$authCalls){return ++$authCalls===1;});
gs_ok(!$r['success']&&gs_rows()===$before,'authorization is rechecked under the write transaction');
gs_fresh();$r=gs_switch(-1,static function($row){global $wpdb;$wpdb->db->exec('UPDATE test_sn_invoices SET current_payment_stage=2');return ['success'=>true,'status'=>-1];});
gs_ok(!$r['success']&&gs_rows()[0]['status']==='pending','stage change during inquiry cannot write a stale selection');
gs_fresh();$wpdb->engine='MyISAM';$before=gs_rows();$r=gs_switch();
gs_ok(!$r['success']&&gs_rows()===$before,'nontransactional schema cannot accept an unaudited partial choice');
gs_fresh();$wpdb->busy=true;$r=gs_switch();
gs_ok(!$r['success']&&$wpdb->locks===0,'busy stage does not perform a selection');
gs_fresh(['verified']);gs_ok(!gs_switch()['success'],'a verified payment cannot switch');
gs_fresh();$wpdb->db->exec("UPDATE test_sn_payments SET authority='other-provider'");gs_ok(!gs_switch()['success'],'unsupported provider cannot use Zibal method selection');
gs_fresh(array_fill(0,11,'pending'));gs_ok(!gs_switch()['success'],'bounded inquiry count fails closed');
gs_fresh();gs_switch();$calls=0;
$finalize=static function()use(&$calls){$calls++;return ['success'=>true];};
$r=SN_Payment_Gateway_Guard::verified_callback(1,1,'zibal:24000','SYN-REF240',$finalize);
gs_ok(!$r['success']&&!empty($r['reconciliation_required'])&&$calls===0&&gs_rows()[0]['ref_id']==='SYN-REF240','late verified callback preserves bank reference without automatic credit');
gs_ok(SN_Payment_Gateway_Guard::snapshot(1,1)['blocked']&&gs_rows()[0]['status']==='reconcile_error','late bank payment blocks further manual action pending reconciliation');
$r=SN_Payment_Gateway_Guard::verified_callback(1,1,'zibal:24000','SYN-REF240',$finalize);
gs_ok(!empty($r['reconciliation_required'])&&$calls===0&&count(gs_rows())===1,'duplicate late callback never finalizes or creates another payment');
$wpdb->db->exec('UPDATE test_sn_invoices SET current_payment_stage=2');
gs_ok(SN_Payment_Gateway_Guard::snapshot(1,2)['blocked'],'unreconciled prior-stage bank payment cannot be hidden by advancing a stage');
gs_fresh();$r=SN_Payment_Gateway_Guard::verified_callback(1,1,'zibal:24000','SYN-REF240',$finalize);
gs_ok($r['success']&&$calls===1&&$wpdb->locks===0,'ordinary verified callback preserves the existing finalization policy');
gs_fresh(['expired']);$wpdb->db->exec("INSERT INTO test_sn_payments (id,invoice_id,payment_stage_no,status,authority,amount,pay_method) VALUES(2,1,1,'approved',NULL,1980000,'card')");
$r=SN_Payment_Gateway_Guard::verified_callback(1,1,'zibal:24000','SYN-LATE240',$finalize);
gs_ok(!empty($r['reconciliation_required'])&&$calls===1,'legacy expired request cannot credit again after manual approval');
gs_fresh();gs_switch();$r=SN_Payment_Gateway_Guard::check(1,1,static fn($row)=>['success'=>true,'status'=>2]);
gs_ok($r['gateway_guard']['blocked']&&gs_rows()[0]['status']==='reconcile_error','paid inquiry after method selection requires reconciliation');
gs_ok($wpdb->locks===0,'all completed operations release the stage lock');
echo 'TOTAL '.$checks.PHP_EOL;
