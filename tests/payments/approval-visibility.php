<?php
/** Standalone regression: synthetic SQLite memory only; no WordPress bootstrap or network. */
if (PHP_SAPI !== 'cli') { exit(2); }
if (!class_exists('SQLite3')) { throw new RuntimeException('SQLite3 is required for this synthetic regression.'); }
define('ABSPATH', '/synthetic-test/');
define('ARRAY_A', 'ARRAY_A');
define('OBJECT', 'OBJECT');

$root = getenv('SN_APPROVAL_TEST_SOURCE') ?: dirname(__DIR__, 2);
require_once $root . '/includes/class-sn-helpers.php';
require_once $root . '/includes/class-sn-plugin.php';

$checks = 0;
function approval_ok($condition, string $name): void {
    global $checks;
    if (!$condition) { throw new RuntimeException('FAIL ' . $name); }
    $checks++;
    echo 'PASS ' . $name . PHP_EOL;
}
function absint($value): int { return abs((int)$value); }
function sanitize_key($value): string { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string)$value)); }
function sanitize_text_field($value): string { return trim(strip_tags((string)$value)); }
function wp_unslash($value) { return $value; }
function wp_json_encode($value, $flags = 0): string { return json_encode($value, $flags); }
function get_current_user_id(): int { return $GLOBALS['test_user_id']; }
function wp_get_current_user(): object { return (object)['ID'=>get_current_user_id(), 'roles'=>['sn_seller']]; }
function is_user_logged_in(): bool { return get_current_user_id() > 0; }
function check_ajax_referer(...$args): bool { return $GLOBALS['test_nonce']; }
function current_user_can($cap): bool { return !empty($GLOBALS['test_admin']); }
class ApprovalJsonResponse extends RuntimeException {
    public array $payload;
    public function __construct(array $payload) { parent::__construct('Synthetic JSON response'); $this->payload = $payload; }
}
function wp_send_json(array $payload): void { throw new ApprovalJsonResponse($payload); }

class ApprovalMemoryDB {
    public string $prefix = 'test_';
    public string $users = 'test_users';
    public string $last_error = '';
    public array $queries = [];
    public SQLite3 $memory;
    public function __construct() {
        $this->memory = new SQLite3(':memory:');
        $this->memory->enableExceptions(true);
        $this->memory->createFunction('LEAST', 'min');
        $this->memory->createFunction('GREATEST', 'max');
    }
    public function prepare(string $sql, ...$args): string {
        $i = 0;
        $out = preg_replace_callback('/%[dsf]/', static function($m) use (&$i, $args) {
            if (!array_key_exists($i, $args)) { throw new RuntimeException('Missing prepared argument'); }
            $value = $args[$i++];
            return $m[0] === '%s' ? "'" . SQLite3::escapeString((string)$value) . "'" : (string)(0 + $value);
        }, $sql);
        if ($i !== count($args)) { throw new RuntimeException('Unused prepared argument'); }
        return $out;
    }
    public function esc_like(string $value): string { return addcslashes($value, '_%\\'); }
    public function get_results(string $sql, $format = OBJECT): array {
        $this->queries[] = $sql;
        if (!preg_match('/^\s*SELECT\b/i', $sql)) { throw new RuntimeException('Production query must be read-only: ' . $sql); }
        $query = $this->memory->query($sql);
        $rows = [];
        while ($row = $query->fetchArray(SQLITE3_ASSOC)) { $rows[] = $format === ARRAY_A ? $row : (object)$row; }
        return $rows;
    }
    public function get_var(string $sql) {
        if (preg_match("/^SHOW TABLES LIKE '([^']+)'$/", $sql, $m)) {
            $rows = $this->get_results("SELECT name FROM sqlite_master WHERE type='table' AND name='" . SQLite3::escapeString($m[1]) . "'", ARRAY_A);
        } else { $rows = $this->get_results($sql, ARRAY_A); }
        return $rows ? array_values($rows[0])[0] : null;
    }
    public function get_col(string $sql, int $column = 0): array {
        if (preg_match('/^SHOW COLUMNS FROM ([a-z_]+)$/', $sql, $m)) {
            $rows = [];
            $q = $this->memory->query('PRAGMA table_info(' . $m[1] . ')');
            while ($r = $q->fetchArray(SQLITE3_ASSOC)) { $rows[] = $r['name']; }
            return $rows;
        }
        return array_map(static fn($row) => array_values($row)[$column], $this->get_results($sql, ARRAY_A));
    }
    public function get_row(string $sql, $format = OBJECT) { return $this->get_results($sql, $format)[0] ?? null; }
}

$wpdb = new ApprovalMemoryDB();
$columns = [
    'id INTEGER PRIMARY KEY', 'seller_id INTEGER', 'invoice_code TEXT', 'customer_name TEXT', 'customer_phone TEXT', 'product_id INTEGER',
    'status TEXT', 'invoice_status TEXT', 'payment_status TEXT', 'financial_return_state TEXT', 'payment_plan TEXT',
    'payment_total_amount REAL', 'final_total REAL', 'product_price REAL', 'paid_total_amount REAL', 'remaining_amount REAL',
    'current_due_amount REAL', 'current_payment_stage INTEGER', 'payment_workflow_status TEXT',
    'catalog_discounts_snapshot TEXT', 'discount_total REAL', 'discount_amount REAL',
    'pay_method TEXT', 'payment_source TEXT', 'created_at TEXT', 'updated_at TEXT', 'paid_at TEXT', 'approved_at TEXT',
    'financial_reviewed_at TEXT', 'payment_completed_at TEXT',
];
$wpdb->memory->exec('CREATE TABLE test_sn_invoices (' . implode(',', $columns) . ')');
$wpdb->memory->exec('CREATE TABLE test_users (ID INTEGER PRIMARY KEY, display_name TEXT)');
$wpdb->memory->exec("INSERT INTO test_users VALUES(1,'Synthetic seller'),(2,'Other synthetic seller')");
$wpdb->memory->exec('CREATE TABLE test_sn_payments (id INTEGER PRIMARY KEY,invoice_id INTEGER,payment_stage_no INTEGER,amount REAL,status TEXT,pay_method TEXT,payment_source TEXT,created_at TEXT,updated_at TEXT)');
$plugin = (new ReflectionClass(SN_Plugin::class))->newInstanceWithoutConstructor();
function approval_call(string $method, ...$args) {
    global $plugin;
    $m = new ReflectionMethod(SN_Plugin::class, $method);
    $m->setAccessible(true);
    return $m->invoke($plugin, ...$args);
}
function approval_invoice(array $changes = []): array {
    return array_replace([
        'id'=>1,'seller_id'=>1,'invoice_code'=>'SYN-PARTIAL','customer_name'=>'Synthetic','customer_phone'=>'','product_id'=>0,
        'status'=>'pending_financial_approval','invoice_status'=>'pending_financial_approval','payment_status'=>'pending_financial_approval',
        'financial_return_state'=>'','payment_plan'=>'partial','payment_total_amount'=>1000,'product_price'=>1000,'final_total'=>1000,
        'paid_total_amount'=>0,'remaining_amount'=>1000,'current_due_amount'=>1000,'current_payment_stage'=>1,
        'payment_workflow_status'=>'awaiting_financial_approval','catalog_discounts_snapshot'=>'','discount_total'=>0,'discount_amount'=>0,
        'pay_method'=>'card','payment_source'=>'seller_manual','created_at'=>'2026-01-01 10:00:00','updated_at'=>'2026-01-01 11:00:00',
    ], $changes);
}
function approval_insert(array $row): void {
    global $wpdb;
    $values = array_map(static fn($v) => $v === null ? 'NULL' : "'" . SQLite3::escapeString((string)$v) . "'", array_values($row));
    $wpdb->memory->exec('INSERT INTO test_sn_invoices (' . implode(',', array_keys($row)) . ') VALUES (' . implode(',', $values) . ')');
}

$stale = approval_invoice(['status'=>'approved','invoice_status'=>'approved','paid_total_amount'=>1000,'remaining_amount'=>0,'payment_workflow_status'=>'completed']);
approval_ok(approval_call('sn_invoice_review_status_label', $stale) === SN_Helpers::status_label('approved'), 'final approval is visible despite a stale pending compatibility column');
approval_ok(approval_call('sn_invoice_review_status_label', (object)$stale) === SN_Helpers::status_label('approved'), 'object and array invoice labels agree');
foreach (['paid','rejected','cancelled','payment_archived'] as $status) {
    approval_ok(approval_call('sn_invoice_review_status_label', array_replace($stale, ['status'=>$status])) === SN_Helpers::status_label($status), 'current terminal decision wins: ' . $status);
}
$partial = approval_invoice(['paid_total_amount'=>300,'remaining_amount'=>700,'current_due_amount'=>700]);
approval_ok(strpos(approval_call('sn_invoice_review_status_label', $partial), 'بخشی از واریزی تأیید شده') !== false, 'approved independent receipt remains visible while another receipt awaits review');
approval_ok(strpos(approval_call('sn_invoice_review_status_label', array_replace($partial, ['status'=>'approved'])), 'مانده فاکتور تسویه نشده') !== false, 'legacy approved stage with a remaining balance is not labelled as a fully paid invoice');
$notFinalized = array_replace($partial, ['paid_total_amount'=>1000,'remaining_amount'=>0,'current_due_amount'=>0]);
approval_ok(strpos(approval_call('sn_invoice_review_status_label', $notFinalized), 'تکمیل مرحله در انتظار') !== false, 'saved receipts do not claim invoice completion after failed finalization');
approval_ok(strpos(approval_call('sn_invoice_review_status_label', approval_invoice(['approved_at'=>'2026-01-01 11:00:00'])), 'تأیید شده') === false, 'stale approval date alone does not approve pending money');

$cases = [
    'independent partial'=>[$partial, 300],
    'pending receipt'=>[approval_invoice(['remaining_amount'=>0]), 0],
    'rejected receipt'=>[approval_invoice(['status'=>'rejected']), 0],
    'prior approved and new rejected'=>[approval_invoice(['status'=>'rejected','paid_total_amount'=>100,'remaining_amount'=>900]), 100],
    'fully approved'=>[$stale, 1000],
    'legacy complete'=>[approval_invoice(['status'=>'approved','paid_total_amount'=>0,'remaining_amount'=>null]), 1000],
    'legacy partial'=>[approval_invoice(['status'=>'partial_paid','paid_total_amount'=>0,'remaining_amount'=>600]), 400],
    'legacy partial below rounding tolerance'=>[approval_invoice(['status'=>'partial_paid','paid_total_amount'=>0,'remaining_amount'=>999.8]), 0],
    'legacy empty remaining'=>[approval_invoice(['status'=>'partial_paid','paid_total_amount'=>0,'remaining_amount'=>'']), 0],
    'cancelled after receipts'=>[array_replace($partial, ['status'=>'cancelled']), 0],
    'archived workflow'=>[array_replace($partial, ['payment_workflow_status'=>'archived']), 0],
    'discounted complete'=>[array_replace($stale, ['catalog_discounts_snapshot'=>'{}','discount_total'=>200,'final_total'=>800,'paid_total_amount'=>1000]), 800],
    'invalid negative snapshot'=>[array_replace($partial, ['paid_total_amount'=>-50,'remaining_amount'=>1000]), 0],
];
$amountSql = approval_call('sn_invoice_received_amount_sql', 'i');
foreach ($cases as $name => [$invoice, $expected]) {
    $wpdb->memory->exec('DELETE FROM test_sn_invoices');
    approval_insert($invoice);
    approval_ok(abs(approval_call('sn_invoice_received_amount', $invoice) - $expected) < 0.005, 'received amount: ' . $name);
    $sqlAmount = (float)$wpdb->get_var('SELECT ' . $amountSql . ' FROM test_sn_invoices i');
    approval_ok(abs($sqlAmount - $expected) < 0.005, 'SQL aggregate agrees: ' . $name);
}

$wpdb->memory->exec('DELETE FROM test_sn_invoices');
approval_insert($partial);
approval_insert(approval_invoice(['id'=>2,'invoice_code'=>'SYN-COMPLETE','status'=>'approved','invoice_status'=>'approved','payment_status'=>'approved','payment_total_amount'=>1200,'product_price'=>1200,'final_total'=>1200,'paid_total_amount'=>1200,'remaining_amount'=>0,'payment_workflow_status'=>'completed']));
approval_insert(approval_invoice(['id'=>3,'seller_id'=>2,'paid_total_amount'=>999,'remaining_amount'=>1]));
$test_user_id = 1; $test_nonce = true; $test_admin = false;
$_POST = ['summary_only'=>1,'seller_id'=>2];
function approval_summary(): array {
    global $plugin;
    try { $plugin->ajax_seller_invoices(); } catch (ApprovalJsonResponse $r) { return $r->payload; }
    throw new RuntimeException('No AJAX response');
}
$response = approval_summary();
approval_ok($response['success'] && abs($response['received_amount'] - 1500) < 0.005, 'seller summary includes approved partial money and excludes another seller');
approval_ok($response['summary']['paid'] === 1, 'partial receipt does not inflate completed invoice count');
approval_ok(abs($response['paid_amount'] - 1200) < 0.005, 'existing completed-sales amount retains its contract');
approval_ok($response['invoices'] === [] && $response['items'] === [], 'summary response exposes no customer rows');
$test_nonce = false; $before = count($wpdb->queries); $response = approval_summary();
approval_ok(!$response['success'] && count($wpdb->queries) === $before, 'invalid nonce is rejected before database reads');
$test_nonce = true; $test_user_id = 0; $before = count($wpdb->queries); $response = approval_summary();
approval_ok(!$response['success'] && count($wpdb->queries) === $before, 'guest is rejected before database reads');

$test_user_id = 1; $test_admin = true;
$wpdb->memory->exec("INSERT INTO test_sn_payments VALUES(1,1,1,300,'approved','card','seller_manual','2026-01-01 10:00:00','2026-01-01 11:00:00'),(2,1,1,700,'pending','card','seller_manual','2026-01-01 10:00:00','2026-01-01 11:00:00')");
$filters = ['seller_id'=>1,'q'=>'SYN-PARTIAL','pay_method'=>'','product_type'=>'','date_from'=>'','date_to'=>''];
$report = approval_call('sn_successful_payment_report_stats', $filters);
approval_ok((int)$report['payment_count'] === 1 && abs((float)$report['total_amount'] - 300) < 0.005, 'successful-payment report already includes the approved receipt once, not the pending balance or full invoice price');
$filters['q'] = 'SYN-COMPLETE';
$report = approval_call('sn_successful_payment_report_stats', $filters);
approval_ok((int)$report['payment_count'] === 1 && abs((float)$report['total_amount'] - 1200) < 0.005, 'legacy approved invoice fallback is counted once without a ledger row');
$wpdb->memory->exec("INSERT INTO test_sn_payments VALUES(3,2,1,400,'approved','card','seller_manual','2026-01-01 10:00:00','2026-01-01 11:00:00'),(4,2,2,800,'approved','card','seller_manual','2026-01-01 10:00:00','2026-01-01 11:00:00')");
$report = approval_call('sn_successful_payment_report_stats', $filters);
approval_ok((int)$report['payment_count'] === 2 && abs((float)$report['total_amount'] - 1200) < 0.005, 'multiple approved stages never double-count the legacy invoice fallback');
approval_ok(!array_filter($wpdb->queries, static fn($q) => !preg_match('/^\s*SELECT\b/i', $q)), 'all queries executed by production methods were read-only');
$wpdb->prefix = 'legacy_';
$wpdb->memory->exec('CREATE TABLE legacy_sn_invoices (id INTEGER PRIMARY KEY,status TEXT,product_price REAL)');
$wpdb->memory->exec("INSERT INTO legacy_sn_invoices VALUES(1,'approved',200),(2,'pending_financial_approval',400)");
$legacySql = approval_call('sn_invoice_received_amount_sql', 'i');
approval_ok(abs((float)$wpdb->get_var('SELECT SUM(' . $legacySql . ') FROM legacy_sn_invoices i') - 200) < 0.005, 'optional modern columns are not required by legacy schema aggregates');
echo 'TOTAL ' . $checks . PHP_EOL;
