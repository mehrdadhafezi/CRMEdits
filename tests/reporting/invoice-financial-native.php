<?php
/** wp eval-file on a new, network-isolated synthetic WordPress/MySQL site only. */
if (PHP_SAPI!=='cli' || !defined('SN_REPORTING_TEST_SANDBOX') || !SN_REPORTING_TEST_SANDBOX
    || WP_ENVIRONMENT_TYPE!=='local' || !preg_match('/^sn_reporting_[a-z0-9]+_test$/',DB_NAME)) {
    throw new RuntimeException('Refusing invoice report fixtures outside their disposable sandbox');
}
global $wpdb;
$mode=$args[0] ?? 'verify'; $evidence='/evidence';
function native_ok($value,string $label): void { if(!$value) { throw new RuntimeException('FAIL '.$label); } echo 'PASS '.$label.PHP_EOL; }
function native_insert(string $table,array $row): int {
    global $wpdb;
    if(false===$wpdb->insert($wpdb->prefix.$table,$row)) { throw new RuntimeException('Synthetic fixture insert failed: '.$table); }
    return (int)$wpdb->insert_id;
}
function native_financial_rows(): array {
    global $wpdb;
    $rows=[];
    foreach(['sn_invoices','sn_payments','sn_invoice_payment_stages','sn_wallets','sn_wallet_transactions'] as $table) {
        $rows[$table]=$wpdb->get_results("SELECT * FROM {$wpdb->prefix}{$table} ORDER BY id",ARRAY_A);
        if($wpdb->last_error!=='') { throw new RuntimeException('Snapshot read failed'); }
    }
    return $rows;
}
function native_snapshot(): string { return hash('sha256',wp_json_encode(native_financial_rows())); }
if($mode==='setup') {
    native_ok((int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}sn_invoices")===0,'fresh synthetic invoice database');
    $wpdb->query('START TRANSACTION');
    try {
    $password=wp_generate_password(32,false);
    $reader=wp_insert_user(['user_login'=>'synthetic_report_reader','user_pass'=>$password,'role'=>'subscriber','display_name'=>'فروشنده ساختگی']);
    native_ok(!is_wp_error($reader),'created unprivileged synthetic reader');
    $product=wp_insert_post(['post_type'=>'product','post_status'=>'publish','post_title'=>'محصول ساختگی']);
    $ids=[];
    foreach([
        ['recontact_requested',null,'partial_paid',5000,2000,'۰۹۱۲۱۲۳۴۵۶۷'],
        ['paid','paid','paid',5000,5000,'+98 912-1234567'],
        ['pending_financial_approval','pre_invoice','pending_financial_approval',4000,0,'09120000002'],
        ['paid','paid','paid',1000,1000,'09120000003'],
        ['cancelled','cancelled',null,5000,1000,'09120000004'],
        ['recontact_requested',null,null,2000,0,'09120000005'],
    ] as $index=>[$status,$document,$payment,$total,$paid,$phone]) {
        $ids[]=native_insert('sn_invoices',[
            'invoice_code'=>'SYN-RPT-'.($index+1),'seller_id'=>(int)$reader,'product_id'=>(int)$product,
            'customer_name'=>$index===0 ? '=SUM(1+1)' : ($index===1 ? '<script>window.report_xss=true</script>' : 'مشتری ساختگی '.($index+1)),
            'customer_phone'=>$phone,'status'=>$status,'invoice_status'=>$document,'payment_status'=>$payment,
            'payment_plan'=>'partial','payment_total_amount'=>$total,'final_total'=>$total,'product_price'=>$total,
            'paid_total_amount'=>$paid,'remaining_amount'=>max(0,$total-$paid),'current_payment_stage'=>1,
            'pay_method'=>'card','payment_source'=>'seller_manual','access_token'=>wp_generate_password(40,false),
            'created_at'=>'2026-11-01 12:00:00','updated_at'=>'2026-11-01 12:00:00',
        ]);
        native_insert('sn_invoice_items',['invoice_id'=>end($ids),'product_id'=>(int)$product,'product_name'=>'محصول ساختگی','qty'=>1,'unit_price'=>$total,'total_price'=>$total,'created_at'=>'2026-11-01 12:00:00']);
        native_insert('sn_invoice_payment_stages',['invoice_id'=>end($ids),'stage_no'=>1,'stage_type'=>'partial','requested_amount'=>$total,'status'=>'pending','created_at'=>'2026-11-01 12:00:00','updated_at'=>'2026-11-01 12:00:00']);
    }
    foreach([[0,2000,'approved','card',''],[1,2000,'approved','card',''],[1,3000,'paid','online','synthetic-approved'],[2,1000,'pending','card',''],[2,2000,'pending','online','synthetic-request'],[4,1000,'approved','card','']] as [$index,$amount,$status,$method,$authority]) {
        native_insert('sn_payments',['invoice_id'=>$ids[$index],'payment_stage_no'=>1,'amount'=>$amount,'status'=>$status,'pay_method'=>$method,'authority'=>$authority ?: null,'payment_source'=>$method==='online' ? 'zibal' : 'seller_manual','proof_data'=>$method==='online' ? null : wp_json_encode(['entry_mode'=>'manual']),'created_at'=>'2026-11-02 10:00:00','updated_at'=>'2026-11-02 11:00:00']);
    }
    file_put_contents($evidence.'/private-reader.json',wp_json_encode(['username'=>'synthetic_report_reader','password'=>$password]));
    $wpdb->query('COMMIT');
    } catch (Throwable $e) { $wpdb->query('ROLLBACK'); throw $e; }
    file_put_contents($evidence.'/fixture.json',wp_json_encode(['ids'=>$ids,'reader'=>(int)$reader,'snapshot'=>native_snapshot()]));
    file_put_contents($evidence.'/private-financial-baseline.json',wp_json_encode(native_financial_rows()));
    echo 'PASS synthetic fixtures created'.PHP_EOL;
}
$fixture=json_decode(file_get_contents($evidence.'/fixture.json'),true);
$report=new SN_Invoice_Financial_Report($wpdb); $ids=$fixture['ids'];
$rows=$report->for_ids($ids);
native_ok(count($rows)===6,'native MySQL one row per invoice');
native_ok((float)$rows[$ids[0]]['confirmed_amount']===2000.0 && (float)$rows[$ids[0]]['remaining_amount']===3000.0,'native partial receipt survives follow-up state');
native_ok((float)$rows[$ids[1]]['confirmed_amount']===5000.0 && (float)$rows[$ids[1]]['online_amount']===3000.0,'native mixed-method installments');
native_ok((float)$rows[$ids[2]]['pending_amount']===1000.0,'native pending online request excluded from pending receipts');
native_ok((float)$rows[$ids[3]]['confirmed_amount']===0.0 && (float)$rows[$ids[3]]['discrepancy_amount']===1000.0,'native stored-paid discrepancy does not become confirmed money');
$summary=$report->summary('i.seller_id=%d',[(int)$fixture['reader']]);
native_ok((int)$summary['invoice_count']===6 && (float)$summary['confirmed_amount']===8000.0,'native complete summary amount');
native_ok((float)$summary['active_remaining_amount']===10000.0 && (float)$summary['closed_remaining_amount']===4000.0,'native open and cancelled balances remain separate');
$customers=$report->groups('i.id IN ('.implode(',',array_map('intval',array_slice($ids,0,2))).')',[],'customers');
native_ok(count($customers)===1 && (float)$customers[0]['confirmed_amount']===7000.0,'native normalized customer grouping');
native_ok($report->summary('i.seller_id=%d',[-123])['invoice_count']==0,'native selected seller cannot include other sellers');
foreach(['paid'=>3,'needs_review'=>1,'cancelled'=>1,'pre_invoice'=>2,'recontact_requested'=>2] as $status=>$expected) {
    [$where,$params]=SN_Invoice_Financial_Report::condition($wpdb,$status);
    native_ok((int)$report->summary($where,$params)['invoice_count']===$expected,'native tab '.$status);
}
if(native_snapshot()!==$fixture['snapshot']) {
    $baseline=json_decode(file_get_contents($evidence.'/private-financial-baseline.json'),true);
    foreach(native_financial_rows() as $table=>$rows) {
        foreach($rows as $index=>$row) {
            foreach($row as $key=>$value) {
                if(!array_key_exists($key,$baseline[$table][$index] ?? []) || $baseline[$table][$index][$key]!==$value) { echo 'CHANGED '.$table.' row '.($index+1).' column '.$key.PHP_EOL; }
            }
        }
    }
}
native_ok(native_snapshot()===$fixture['snapshot'],'native financial rows and wallets unchanged after reports');
file_put_contents($evidence.'/native-summary.json',wp_json_encode(['wordpress'=>get_bloginfo('version'),'php'=>PHP_VERSION,'mysql'=>$wpdb->db_version(),'summary'=>$summary,'fixture_count'=>6,'unchanged'=>true],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
