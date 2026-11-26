<?php
/** Native tests only: a guarded disposable CI DB or private synthetic HR namespace. */
function hr239_bootstrap(bool $full = true, string $expected_version = '2.0.246'): void {
    if (PHP_SAPI !== 'cli') { throw new RuntimeException('Synthetic CLI required'); }
    if ($full && !defined('DOING_AJAX')) { define('DOING_AJAX', true); }
    if (getenv('SN_APPROVAL_ALLOW_SYNTHETIC') === 'YES') {
        require_once dirname(__DIR__).'/payments/integration/support.php';
        sn_test_bootstrap();
        $GLOBALS['hr239_root'] = realpath((string)getenv('SN_APPROVAL_ROOT'));
        $GLOBALS['hr239_output'] = (string)getenv('SN_APPROVAL_OUTPUT');
    } else {
        $root = realpath((string)getenv('SN_HR239_ROOT'));
        if (getenv('SN_HR239_SYNTHETIC') !== 'YES' || posix_geteuid() !== 1073 || !$root
            || !preg_match('#^/home/crmstg1/private/hr239-[a-z0-9_]{8}$#D', $root)
            || is_link($root) || file_exists('/home/payasetareh')) { throw new RuntimeException('Private synthetic target rejected'); }
        $guard = json_decode(file_get_contents($root.'/guard.json'), true, 512, JSON_THROW_ON_ERROR);
        if (($guard['database']??'') !== 'crmstg1_restore237' || !preg_match('/^h239_[a-f0-9]{8}_$/D', $guard['prefix']??'')
            || !hash_equals($guard['config_sha256'], hash_file('sha256',$root.'/wordpress/wp-config.php'))) { throw new RuntimeException('Private config guard rejected'); }
        require_once $root.'/wordpress/wp-load.php';
        global $wpdb;
        if (!defined('SN_HR239_PRIVATE_TEST') || SN_HR239_PRIVATE_TEST!==true || DB_NAME!=='crmstg1_restore237'
            || DB_USER!=='crmstg1_rst237' || DB_HOST!=='localhost:/var/lib/mysql/mysql.sock'
            || $wpdb->prefix!==$guard['prefix'] || realpath(ABSPATH)!==$root.'/wordpress'
            || $wpdb->get_var('SELECT DATABASE()')!==DB_NAME) { throw new RuntimeException('Private runtime identity rejected'); }
        $GLOBALS['hr239_root']=$root;$GLOBALS['hr239_output']=$root.'/evidence';
    }
    if (!in_array($expected_version,['2.0.235','2.0.241','2.0.242','2.0.243','2.0.244','2.0.245','2.0.246'],true) || !defined('SN_VERSION') || SN_VERSION!==$expected_version) { throw new RuntimeException('Intended candidate version required'); }
    $GLOBALS['hr239_automatic_policy_hook']=has_action('wp_ajax_sn_hr_invoice_reissue')!==false;
    sn_bootstrap_load_core();sn_bootstrap_plugin_instance($full);
    global $wpdb;$wpdb->hide_errors();
    if (!is_dir($GLOBALS['hr239_output'])) { mkdir($GLOBALS['hr239_output'],0700,true); }
}
function hr239_ok($condition,string $label): void {
    if (!$condition) { throw new RuntimeException('FAIL '.$label); }
    $GLOBALS['hr239_checks']=($GLOBALS['hr239_checks']??0)+1;echo 'PASS '.$label.PHP_EOL;
}
function hr239_private(string $name,array $value): void {
    if(!preg_match('/^[a-z0-9-]+\.json$/D',$name)){throw new RuntimeException('Private output name');}
    $path=$GLOBALS['hr239_root'].'/'.$name;if(is_link($path)){throw new RuntimeException('Private output symlink');}
    file_put_contents($path,wp_json_encode($value,JSON_THROW_ON_ERROR));chmod($path,0600);
}
function hr239_data(): array { return json_decode(file_get_contents($GLOBALS['hr239_root'].'/hr239-fixture.json'),true,512,JSON_THROW_ON_ERROR); }
function hr239_source_fingerprint(): array { return ['core_sha256'=>hash_file('sha256',SN_PLUGIN_DIR.'includes/class-sn-plugin.php'),'policy_sha256'=>hash_file('sha256',SN_PLUGIN_DIR.'includes/class-sn-hr-invoice-reissue.php')]; }
/** Hash every financial row/column without retaining the entire test database. */
function hr239_financial_fingerprint(): string {
    global $wpdb;$hash=hash_init('sha256');
    foreach(['sn_invoices','sn_invoice_payment_stages','sn_payments','sn_dot_cases','sn_dot_payments','sn_wallets','sn_wallet_transactions','sn_payment_review_events'] as $table){
        hash_update($hash,$table."\0");$after=0;
        do{$rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}{$table} WHERE id>%d ORDER BY id LIMIT 200",$after),ARRAY_A);
            if($wpdb->last_error||!is_array($rows))throw new RuntimeException('Financial fingerprint query failed');
            foreach($rows as $row){hash_update($hash,wp_json_encode($row)."\n");$after=(int)$row['id'];}
        }while(count($rows)===200);
    }return hash_final($hash);
}
function finance244_evidence(string $name,array $extra=[]): void {
    global $wpdb;$data=array_merge(['passed'=>true,'synthetic'=>true,'version'=>SN_VERSION,'assertions'=>$GLOBALS['hr239_checks'],
        'core_sha256'=>hash_file('sha256',SN_PLUGIN_DIR.'includes/class-sn-plugin.php'),
        'review_sha256'=>hash_file('sha256',SN_PLUGIN_DIR.'includes/class-sn-receipt-review.php'),
        'catalog_sha256'=>hash_file('sha256',SN_PLUGIN_DIR.'includes/class-sn-sales-catalog.php'),
        'wordpress'=>$GLOBALS['wp_version'],'php'=>PHP_VERSION,'mysql'=>$wpdb->db_version(),'production_touched'=>false],$extra);
    file_put_contents($GLOBALS['hr239_output'].'/finance244-'.$name.'.json',wp_json_encode($data,JSON_PRETTY_PRINT));
}
class HR239_Response_End extends RuntimeException {}
function hr239_request(int $actor,array $payload): array {
    wp_set_current_user(0);wp_set_current_user($actor);
    $_POST=array_merge(['action'=>'sn_hr_invoice_reissue','nonce'=>wp_create_nonce('sn_hr_invoice_reissue'),'operation'=>'list'], $payload);
    $_REQUEST=$_POST;http_response_code(200);
    $handler=static fn()=>static function(...$args): void {throw new HR239_Response_End();};
    add_filter('wp_die_ajax_handler',$handler);
    ob_start();
    try {do_action($actor?'wp_ajax_sn_hr_invoice_reissue':'wp_ajax_nopriv_sn_hr_invoice_reissue');}
    catch(HR239_Response_End $ended) {}
    finally {$raw=ob_get_clean();remove_filter('wp_die_ajax_handler',$handler);}
    $result=json_decode($raw,true,512,JSON_THROW_ON_ERROR);$result['_status']=http_response_code();return $result;
}
function hr239_selection(array $f,bool $other=false): array {
    $suffix=$other?'b':'a';
    return ['manager_id'=>(string)$f['users']['manager_'.$suffix]['id'],'senior_ids'=>[(string)$f['users']['senior_'.$suffix]['id']],
        'supervisor_ids'=>[(string)$f['users']['supervisor_'.$suffix]['id']],'seller_ids'=>[(string)$f['users']['seller_'.$suffix]['id']]];
}
function hr239_row(array $view,int $id): array {foreach($view['data']['rows']??[] as $row){if($row['id']===$id)return $row;}throw new RuntimeException('Expected synthetic row absent');}
function hr239_save(array $f,int $id,string $mode,?array $selection=null,?string $version=null,int $actor=0): array {
    $selection=$selection??hr239_selection($f);$actor=$actor?:$f['users']['hr']['id'];
    $view=hr239_request($actor,$selection);$row=hr239_row($view,$id);
    return hr239_request($actor,array_merge($selection,['operation'=>'save','user_id'=>(string)$id,'mode'=>$mode,'version'=>$version??$row['version']]));
}
