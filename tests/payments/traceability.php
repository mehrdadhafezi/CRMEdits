<?php
/** Memory-only regression: references must describe existing records, never manufacture them. */
define('ABSPATH', __DIR__.'/'); define('ARRAY_A', 'ARRAY_A');
function absint($value): int { return abs((int)$value); }
require dirname(__DIR__,2).'/includes/class-sn-payment-transactions.php';
if (!class_exists('SQLite3')) { fwrite(STDERR,"SQLite3 required\n"); exit(2); }
final class Trace_DB {
    public string $prefix='t_'; public string $last_error=''; public array $queries=[]; public bool $fail=false; public SQLite3 $db;
    public function __construct() { $this->db=new SQLite3(':memory:'); }
    public function prepare(string $sql,...$args): string { foreach ($args as $arg) { $sql=preg_replace('/%d/',(string)(int)$arg,$sql,1); } return $sql; }
    public function get_results(string $sql,$format): ?array {
        $this->queries[]=$sql; $this->last_error='';
        if ($this->fail) { $this->last_error='private-database-diagnostic'; return null; }
        $q=$this->db->query($sql); $rows=[]; while ($row=$q->fetchArray(SQLITE3_ASSOC)) { $rows[]=$row; } return $rows;
    }
}
$wpdb=new Trace_DB();
$wpdb->db->exec('CREATE TABLE t_sn_invoice_payment_stages(id INTEGER PRIMARY KEY,invoice_id INTEGER,stage_no INTEGER,status TEXT)');
$wpdb->db->exec('CREATE TABLE t_sn_payments(id INTEGER PRIMARY KEY,invoice_id INTEGER,payment_stage_no INTEGER,status TEXT)');
$wpdb->db->exec("INSERT INTO t_sn_invoice_payment_stages VALUES(1,100,1,'approved'),(2,100,3,'pending'),(3,100,7,'rejected')");
$wpdb->db->exec("INSERT INTO t_sn_payments VALUES(11,100,1,'approved'),(12,100,1,'approved'),(30,100,3,'pending'),(21,200,4,'paid')");
$checks=0;
function trace_ok(bool $yes,string $name): void { if (!$yes) { throw new RuntimeException('FAIL '.$name); } ++$GLOBALS['checks'];echo 'PASS '.$name.PHP_EOL; }
$context=SN_Payment_Transactions::trace_context([100,200,300,100]);
trace_ok($context[100]['stage_count']===3,'issued stages counted, not inferred from highest stage number');
trace_ok($context[100]['transaction_count']===3,'all recorded attempts counted independently of approval');
trace_ok($context[200]['stage_count']===0&&$context[200]['transaction_count']===1,'legacy payment without stage retains its true counts');
trace_ok($context[300]['stage_count']===0&&$context[300]['transaction_count']===0,'empty history is not an invented first stage');
trace_ok(count($wpdb->queries)===2,'metadata uses bounded batch queries');
$first=SN_Payment_Transactions::trace_reference(['id'=>11,'invoice_id'=>100,'payment_stage_no'=>1],$context[100]);
$second=SN_Payment_Transactions::trace_reference(['payment_id'=>12,'invoice_id'=>100,'stage_no'=>1],$context[100]);
trace_ok($first['transaction_code']==='PAY-11'&&$second['transaction_code']==='PAY-12','two transfers on one stage have different stable identifiers');
trace_ok($first['stage_reference']==='STG-1'&&$second['stage_reference']==='STG-1','stage identity shared without changing transaction identity');
trace_ok($first['stage_count']===3,'pending/rejected issued stages remain traceable');
$wpdb->db->exec("UPDATE t_sn_payments SET status='rejected' WHERE id=11");
trace_ok(SN_Payment_Transactions::trace_reference(['id'=>11,'invoice_id'=>100,'payment_stage_no'=>1,'status'=>'rejected'],$context[100])===$first,'decision changes do not regenerate transaction identifiers');
$orphan=SN_Payment_Transactions::trace_reference(['payment_id'=>21,'invoice_id'=>200,'stage_no'=>4],$context[200]);
trace_ok($orphan['transaction_code']==='PAY-21'&&$orphan['stage_reference']===''&&$orphan['stage_count']===0,'missing historical stage is explicit, never guessed');
$legacy=SN_Payment_Transactions::trace_reference(['payment_id'=>0,'invoice_id'=>300,'row_source'=>'invoice_fallback','authority'=>'private','access_token'=>'private'],$context[300]);
trace_ok($legacy['transaction_code']===''&&$legacy['record_reference']==='INV-300','invoice confirmation is not given a fabricated transaction id');
trace_ok(!isset($legacy['authority'],$legacy['access_token']),'trace projection carries no provider authority or public token');
trace_ok(!str_starts_with($first['transaction_code'],'='),'reference is safe as an Excel text value');
$wpdb->queries=[];SN_Payment_Transactions::trace_context([]);trace_ok(!$wpdb->queries,'empty authorized set cannot query all invoices');
$wpdb->queries=[];SN_Payment_Transactions::trace_context(range(1,501));trace_ok(count($wpdb->queries)===4,'large exports chunk identifiers instead of N+1 queries');
foreach ($wpdb->queries as $sql) { trace_ok(str_starts_with($sql,'SELECT '),'trace metadata has no financial write'); }
$wpdb->fail=true;$blocked=false;
try { SN_Payment_Transactions::trace_context([100]); } catch (RuntimeException $e) { $blocked=!str_contains($e->getMessage(),'private-database'); }
trace_ok($blocked,'query failure does not become a false zero count or leak a database error');
$wpdb->fail=false;$wpdb->db->exec("INSERT INTO t_sn_invoice_payment_stages VALUES(9,100,1,'pending')");$blocked=false;
try { SN_Payment_Transactions::trace_context([100]); } catch (RuntimeException $e) { $blocked=true; }
trace_ok($blocked,'ambiguous stage identity is rejected without repair');
echo 'TOTAL '.$checks.PHP_EOL;
