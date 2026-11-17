<?php
/** Native read-only report regression on the guarded disposable synthetic site. */
require __DIR__ . '/support.php';
sn_test_bootstrap();
sn_bootstrap_load_core();
sn_bootstrap_plugin_instance(true);
global $wpdb, $wp_version;
$fixture = sn_test_data();
$before = sn_test_financial_hash();
$seller = (int)$fixture['users']['seller']['id'];
$other = (int)$fixture['users']['other']['id'];
$filters = SN_Report_Service::filters(['date_from'=>'۱۴۰۵/۰۸/۱۰','date_to'=>'۱۴۰۵/۰۸/۱۰']);
$service = new SN_Report_Service($wpdb, [$seller]);
$report = $service->query('invoices', $filters, 1, 2);
sn_test_ok($wpdb->last_error === '' && $report['total'] === 5 && (float)$report['amount'] === 5200000.0, 'native report preserves the installed scoped date filter and totals');
sn_test_ok(count($report['rows']) === 2 && $service->query('invoices',$filters,3,2)['total'] === 5, 'native report pagination retains the full scoped count');
sn_test_ok($service->query('invoices',array_replace($filters,['seller_id'=>$other]))['total'] === 0, 'native forged seller filter cannot expand report scope');
sn_test_ok((new SN_Report_Service($wpdb,[]))->query('invoices',$filters)['total'] === 0, 'native empty seller scope remains empty');
$executive = new SN_Report_Executive($wpdb,[$seller]);
$period = SN_Report_Executive::filters(['date_from'=>'۱۴۰۵/۰۸/۱۰','date_to'=>'۱۴۰۵/۰۸/۱۰']);
$result = $executive->query('invoices_register',$period,1,2);
sn_test_ok($wpdb->last_error === '' && $result['available'] && $result['total'] === 5 && (float)$result['amount'] === 5200000.0, 'native executive date range and totals are preserved');
sn_test_ok(count($result['rows']) === 2 && count($result['breakdown']) === 1, 'native executive rows and hierarchy breakdown remain available');
foreach ($fixture['tokens'] as $token) { sn_test_ok(!str_contains(wp_json_encode($result),$token),'native executive report excludes invoice access tokens'); }
$bad = $executive->query('invoices_register',SN_Report_Executive::filters(['date_from'=>'invalid']));
sn_test_ok(!$bad['available'] && $bad['total'] === 0 && $bad['rows'] === [], 'native invalid period cannot become an unfiltered executive report');
$asof = $executive->query('invoices_register',SN_Report_Executive::filters(['mode'=>'asof','asof_date'=>'1405/08/10','asof_time'=>'10:00']));
sn_test_ok($asof['available'] && $asof['total'] === 5, 'native asof includes rows within the selected minute');
sn_test_ok(sn_test_financial_hash() === $before, 'native reports preserve every invoice, stage and payment row');
file_put_contents(getenv('SN_APPROVAL_OUTPUT').'/reporting-summary.json',wp_json_encode(['assertions'=>$GLOBALS['sn_test_checks'],'wordpress'=>$wp_version,'php'=>PHP_VERSION,'mysql'=>$wpdb->db_version(),'plugin'=>SN_VERSION,'synthetic'=>true],JSON_PRETTY_PRINT));
echo 'TOTAL ' . $GLOBALS['sn_test_checks'] . PHP_EOL;
