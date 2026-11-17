<?php
/** Synthetic memory-only reporting regression. Never bootstraps WordPress. */
if (PHP_SAPI !== 'cli' || !class_exists('SQLite3')) { throw new RuntimeException('CLI with SQLite3 required.'); }
define('ABSPATH', '/synthetic-report-test/');
define('ARRAY_A', 'ARRAY_A');
define('OBJECT', 'OBJECT');
$root = getenv('SN_REPORT_TEST_SOURCE') ?: dirname(__DIR__, 2);
require $root . '/includes/class-sn-helpers.php';
require $root . '/includes/class-sn-plugin.php';
require $root . '/includes/reports/class-sn-report-service.php';
require $root . '/includes/reports/class-sn-report-executive.php';
function absint($value): int { return abs((int)$value); }
function wp_unslash($value) { return $value; }
function sanitize_text_field($value): string { return is_scalar($value) ? trim(strip_tags((string)$value)) : ''; }
function sanitize_key($value): string { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string)$value)); }
function wp_timezone(): DateTimeZone { return new DateTimeZone($GLOBALS['report_timezone'] ?? 'UTC'); }
$checks = 0;
function report_ok($value, string $name): void {
    global $checks;
    if (!$value) { throw new RuntimeException('FAIL ' . $name); }
    $checks++; echo 'PASS ' . $name . PHP_EOL;
}
final class ReportMemoryDB {
    public string $prefix = 'test_';
    public string $users = 'test_users';
    public string $last_error = '';
    public array $queries = [];
    public SQLite3 $memory;
    public function __construct() {
        $this->memory = new SQLite3(':memory:'); $this->memory->enableExceptions(true);
        $this->memory->createFunction('CONCAT', static fn(...$args) => implode('', $args));
    }
    public function prepare(string $sql, ...$args): string {
        $index = 0;
        $result = preg_replace_callback('/%[dsf]/', static function($match) use (&$index, $args) {
            if (!array_key_exists($index, $args)) { throw new RuntimeException('Missing prepared argument'); }
            $value = $args[$index++];
            return $match[0] === '%s' ? "'" . SQLite3::escapeString((string)$value) . "'" : (string)(0 + $value);
        }, $sql);
        if ($index !== count($args)) { throw new RuntimeException('Unused prepared argument'); }
        return $result;
    }
    public function esc_like(string $value): string { return addcslashes($value, '_%\\'); }
    public function get_results(string $sql, $format = ARRAY_A): array {
        if (!preg_match('/^\s*SELECT\b/i', $sql)) { throw new RuntimeException('Report query must remain read-only'); }
        $this->queries[] = $sql; $query = $this->memory->query($sql); $rows = [];
        while ($row = $query->fetchArray(SQLITE3_ASSOC)) { $rows[] = $format === ARRAY_A ? $row : (object)$row; }
        return $rows;
    }
    public function get_row(string $sql, $format = ARRAY_A) { return $this->get_results($sql, $format)[0] ?? null; }
}

foreach (['۱۴۰۵/۰۸/۱۰', '١٤٠٥/٠٨/١٠', '1405-08-10', '2026-11-01'] as $date) {
    $f = SN_Report_Service::filters(['date_from'=>$date, 'time_from'=>'۱۴:۰۵']);
    report_ok($f['date_from'] === '2026-11-01' && $f['time_from'] === '14:05' && $f['date_error'] === '', 'Persian/Arabic/Gregorian date and time normalize: ' . $date);
}
foreach ([['date_from'=>'2026-02-30'], ['date_from'=>'1404/12/30'], ['date_from'=>['2026-11-01']], ['time_to'=>['12:00']], ['time_from'=>'24:00'], ['time_to'=>'09:60'], ['date_from'=>"2026-11-01' OR 1=1 --"], ['date_from'=>'<script>alert(1)</script>']] as $input) {
    report_ok(SN_Report_Service::filters($input)['date_error'] !== '', 'invalid or nonscalar date/time is rejected ' . json_encode($input));
}
foreach ([['date_from'=>'2026-11-02','date_to'=>'2026-11-01'], ['date_from'=>'2026-11-01','date_to'=>'2026-11-01','time_from'=>'12:00','time_to'=>'11:59']] as $input) {
    report_ok(SN_Report_Service::filters($input)['date_error'] !== '', 'reversed date/time range is rejected');
}
report_ok(SN_Report_Service::filters(['date_from'=>'2026-11-01','date_to'=>'2026-11-01','time_from'=>'12:00','time_to'=>'12:00'])['date_error'] === '', 'equal inclusive time endpoints are accepted');
$asof = SN_Report_Executive::filters(['mode'=>'asof','date_from'=>'invalid-period','asof_date'=>'۱۴۰۵/۰۸/۱۰','asof_time'=>'۱۲:۳۰']);
report_ok($asof['date_error'] === '' && $asof['asof_date'] === '2026-11-01' && $asof['asof_time'] === '12:30', 'asof validates the active date fields independently');

$wpdb = new ReportMemoryDB();
$wpdb->memory->exec('CREATE TABLE test_users (ID INTEGER PRIMARY KEY, display_name TEXT)');
$wpdb->memory->exec("INSERT INTO test_users VALUES(10,'Synthetic owner'),(20,'Synthetic other team')");
$wpdb->memory->exec('CREATE TABLE test_sn_invoices (id INTEGER PRIMARY KEY,seller_id INTEGER,invoice_code TEXT,customer_name TEXT,customer_phone TEXT,product_id INTEGER,city TEXT,province TEXT,status TEXT,payment_status TEXT,pay_method TEXT,final_total REAL,product_price REAL,created_at TEXT,paid_at TEXT,approved_at TEXT,access_token TEXT)');
foreach ([[1,10,100,'2026-11-01 10:00:00'],[2,10,200,'2026-11-01 11:00:00'],[3,20,999,'2026-11-01 11:00:00'],[4,10,300,'2026-11-02 11:00:00'],[5,10,400,'2026-11-01 12:00:00']] as [$id,$seller,$amount,$date]) {
    $wpdb->memory->exec($wpdb->prepare("INSERT INTO test_sn_invoices VALUES(%d,%d,%s,'Synthetic customer','00000000000',0,'','','approved','pending_financial_approval','card',%d,%d,%s,NULL,NULL,'synthetic-access-token')", $id,$seller,'REPORT-'.$id,$amount,$amount,$date));
}
$before = hash('sha256', json_encode($wpdb->get_results('SELECT * FROM test_sn_invoices ORDER BY id')));
$filters = SN_Report_Service::filters(['date_from'=>'۱۴۰۵/۰۸/۱۰','date_to'=>'۱۴۰۵/۰۸/۱۰']);
$service = new SN_Report_Service($wpdb, [10]);
$first = $service->query('invoices', $filters, 1, 1);
$second = $service->query('invoices', $filters, 2, 1);
report_ok($first['total'] === 3 && (float)$first['amount'] === 700.0, 'scoped period report totals include only the selected seller and day');
report_ok((int)$first['rows'][0]['id'] === 5 && (int)$second['rows'][0]['id'] === 2 && $second['total'] === 3, 'pagination preserves totals and returns distinct report rows');
report_ok(!array_key_exists('access_token', $first['rows'][0]), 'report rows exclude invoice access tokens');
report_ok($service->query('invoices', array_replace($filters,['seller_id'=>20]))['total'] === 0, 'forged seller selection cannot expand report scope');
report_ok((new SN_Report_Service($wpdb, []))->query('invoices', $filters)['total'] === 0, 'empty authorized scope returns no rows');
report_ok($service->query('invoices', array_replace($filters,['status'=>"approved' OR 1=1 --"]))['total'] === 0, 'SQL-like status remains a literal bound value');
$executive = new SN_Report_Executive($wpdb, [10]);
$period = SN_Report_Executive::filters(['date_from'=>'۱۴۰۵/۰۸/۱۰','date_to'=>'۱۴۰۵/۰۸/۱۰']);
// The SQLite fixture is WordPress-local UTC; business dates are Tehran.
$overview = $executive->query('invoices_register', $period, 1, 2);
report_ok($overview['available'] && $overview['total'] === 3 && (float)$overview['amount'] === 700.0, 'executive report preserves scoped period count and money');
report_ok(count($overview['rows']) === 2 && count($overview['breakdown']) === 1, 'executive rows and per-person breakdown remain available');
report_ok($executive->query('invoices_register', array_replace($period,['seller_id'=>20]))['total'] === 0, 'executive forged seller filter cannot expand scope');
foreach ([['date_from'=>'invalid'], ['date_from'=>'2026-11-02','date_to'=>'2026-11-01'], ['mode'=>'asof','asof_date'=>'invalid']] as $input) {
    $queries = count($wpdb->queries); $blocked = $executive->query('invoices_register', SN_Report_Executive::filters($input));
    report_ok(!$blocked['available'] && $blocked['rows'] === [] && $blocked['total'] === 0 && count($wpdb->queries) === $queries, 'invalid executive range never falls back to an unfiltered SQL report');
}
$queries = count($wpdb->queries); $missing = $executive->query('invoices_register', SN_Report_Executive::filters(['mode'=>'asof']));
report_ok(!$missing['available'] && count($wpdb->queries) === $queries, 'asof without a date does not query all history');

$wpdb->memory->exec('CREATE TABLE test_sn_leads (id INTEGER PRIMARY KEY, imported_at TEXT)');
foreach (['2026-10-31 20:29:59','2026-10-31 20:30:00','2026-11-01 12:00:00','2026-11-01 20:29:59','2026-11-01 20:30:00'] as $index=>$date) {
    $wpdb->memory->exec($wpdb->prepare('INSERT INTO test_sn_leads VALUES(%d,%s)', $index+1,$date));
}
$plugin = (new ReflectionClass(SN_Plugin::class))->newInstanceWithoutConstructor();
$method = new ReflectionMethod(SN_Plugin::class, 'sn_sales_manager_lead_date_conditions'); $method->setAccessible(true);
foreach (['imported_at','di.imported_at'] as $column) {
    $where = []; $args = []; $method->invokeArgs($plugin, [$column,$filters,&$where,&$args]);
    $sql = 'SELECT id FROM test_sn_leads di WHERE ' . implode(' AND ', $where) . ' ORDER BY id';
    report_ok(array_column($wpdb->get_results($wpdb->prepare($sql, ...$args)), 'id') === [2,3,4], 'manager day boundaries retain Tehran-to-WordPress conversion for ' . $column);
    report_ok($args === ['2026-10-31 20:30:00','2026-11-01 20:30:00'], 'manager end date stays exclusive without DATE(column) truncation');
}
report_ok(hash('sha256', json_encode($wpdb->get_results('SELECT * FROM test_sn_invoices ORDER BY id'))) === $before, 'all reporting and manager date regressions preserve financial fixture rows');
echo 'TOTAL ' . $checks . PHP_EOL;
