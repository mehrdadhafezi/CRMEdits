<?php
/** Code-only downgrade/reinstall: preserve all financial, HR, policy and role state. */
require dirname(__DIR__).'/payments/integration/support.php';sn_test_bootstrap();global $wpdb;
$phase=$argv[1]??'';if(!in_array($phase,['save','rollback','restored'],true)){throw new RuntimeException('Explicit code rollback phase required');}
$root=realpath((string)getenv('SN_APPROVAL_ROOT'));$output=(string)getenv('SN_APPROVAL_OUTPUT');
sn_test_ok(SN_VERSION===($phase==='rollback'?'2.0.238':'2.0.241'),'intended code version in phase '.$phase);
$state=['roles'=>get_option($wpdb->prefix.'user_roles'),'meta'=>$wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->usermeta} WHERE meta_key IN (%s,%s) ORDER BY umeta_id",$wpdb->prefix.'capabilities','sn_invoice_reissue_mode'),ARRAY_A),'tables'=>[]];
foreach(['sn_invoices','sn_invoice_items','sn_invoice_payment_stages','sn_payments','sn_payment_review_events','sn_wallets','sn_wallet_transactions','sn_payment_commission_matrix','sn_hr_profiles','sn_hr_positions','sn_hr_assignments','sn_hr_profile_logs'] as $suffix){
    $table=$wpdb->prefix.$suffix;$ddl=$wpdb->get_row("SHOW CREATE TABLE `{$table}`",ARRAY_N);
    sn_test_ok(is_array($ddl)&&!$wpdb->last_error,'existing schema in rollback phase '.$suffix);
    $data=$wpdb->get_results("SELECT * FROM `{$table}` ORDER BY id",ARRAY_A);
    sn_test_ok(!$wpdb->last_error,'read-only rollback snapshot '.$suffix);
    $state['tables'][$suffix]=hash('sha256',wp_json_encode([$ddl[1],$data]));
}
$hash=hash('sha256',wp_json_encode($state));$file=$root.'/hr239-code-state.json';
if($phase==='save'){
    file_put_contents($file,wp_json_encode(['hash'=>$hash,'completed'=>['save']]));chmod($file,0600);
}else{
    $before=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
    sn_test_ok(hash_equals($before['hash'],$hash),'financial data schema policies and roles unchanged during '.$phase);
    $expected=$phase==='rollback'?['save']:['save','rollback'];sn_test_ok($before['completed']===$expected,'rollback phases executed in order');
    $before['completed'][]=$phase;file_put_contents($file,wp_json_encode($before));
    if($phase==='restored'){
        file_put_contents($output.'/code-rollback.json',wp_json_encode(['passed'=>true,'synthetic'=>true,'assertions'=>$GLOBALS['sn_test_checks'],'phases'=>$before['completed'],'financial_hr_schema_roles_preserved'=>true,'production_touched'=>false],JSON_PRETTY_PRINT));
    }
}
echo 'TOTAL '.$GLOBALS['sn_test_checks'].PHP_EOL;
