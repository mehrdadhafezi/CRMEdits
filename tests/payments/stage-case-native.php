<?php
/** Actual admin renderer, only in the existing guarded disposable synthetic site. */
ob_start();require dirname(__DIR__).'/hr/native-support.php';
if (getenv('SN_STAGE_CASE_LOCAL') === 'YES') {
    if (PHP_SAPI !== 'cli' || !defined('SN_STAGE_CASE_TEST_SANDBOX') || !SN_STAGE_CASE_TEST_SANDBOX
        || WP_ENVIRONMENT_TYPE !== 'local' || !preg_match('/^sn_case_[a-f0-9]+_test$/D',DB_NAME)
        || realpath(ABSPATH) !== '/var/www/html' || SN_VERSION !== '2.0.247') { throw new RuntimeException('Fresh local stage sandbox required'); }
    if (!defined('DOING_AJAX')) { define('DOING_AJAX',true); }
    $GLOBALS['hr239_root']='/evidence';$GLOBALS['hr239_output']='/evidence';sn_bootstrap_plugin_instance(true);
} else { hr239_bootstrap(); }
$fixture=hr239_data();global $wpdb;
$plugin=sn_bootstrap_plugin_instance();$admin=(int)$wpdb->get_var("SELECT ID FROM {$wpdb->users} ORDER BY ID LIMIT 1");$seller=(int)$fixture['users']['seller_a']['id'];
$it=$wpdb->prefix.'sn_invoices';$st=$wpdb->prefix.'sn_invoice_payment_stages';$pt=$wpdb->prefix.'sn_payments';$ids=[];$prefix='C247-'.bin2hex(random_bytes(3));
try {
 foreach([false,true] as $settled){
  $code=$prefix.($settled?'-PAID':'-OPEN');$paid=$settled?1000:700;
  hr239_ok($wpdb->insert($it,['invoice_code'=>$code,'seller_id'=>$seller,'issued_by_user_id'=>$seller,'customer_name'=>'Synthetic stage case','customer_phone'=>'00000000000','product_price'=>1000,'original_total'=>1000,'final_total'=>1000,'payment_total_amount'=>1000,'paid_total_amount'=>$paid,'remaining_amount'=>1000-$paid,'current_due_amount'=>1000-$paid,'current_payment_stage'=>3,'payment_plan'=>'partial','status'=>$settled?'paid':'partial_paid','invoice_status'=>$settled?'paid':'partial_paid','payment_status'=>$settled?'approved':'pending_financial_approval','created_at'=>current_time('mysql')])!==false,'synthetic case invoice inserted');
  $id=(int)$wpdb->insert_id;$ids[]=$id;$codes[$id]=$code;
  foreach([[1,400],[2,300],[3,300]] as [$no,$amount]){
   $approved=$settled||$no<3;
   hr239_ok($wpdb->insert($st,['invoice_id'=>$id,'stage_no'=>$no,'requested_amount'=>$amount,'status'=>$approved?'paid':'pending_financial_approval'])!==false,'synthetic issued stage inserted');
   if($approved)hr239_ok($wpdb->insert($pt,['invoice_id'=>$id,'amount'=>$amount,'status'=>'approved','payment_stage_no'=>$no,'pay_method'=>'card','payment_source'=>'synthetic-stage-case','created_at'=>current_time('mysql')])!==false,'synthetic confirmed stage receipt inserted');
  }
 }
 wp_set_current_user($admin);hr239_ok(current_user_can('manage_options'),'synthetic administrator has required permission');
 $_GET=['page'=>'sn-invoices','sn_search'=>$prefix,'sn_status'=>'all','sn_finance'=>'all','sn_date_from'=>'','sn_date_to'=>''];
 $before=hr239_financial_fingerprint();ob_start();$plugin->render_admin_invoices();$html=ob_get_clean();
 hr239_ok(substr_count($html,'data-sn-invoice-main="true"')===2,'actual admin counts two canonical invoice rows');
 hr239_ok(substr_count($html,'data-sn-stage-row="true"')===3,'only unsettled invoice has three additional issued-stage rows');
 hr239_ok(substr_count($html,'<details class="sn-invoice-stage-history">')===1,'settled admin invoice has one expandable history');
 foreach($codes as $code)foreach([1,2,3] as $no)hr239_ok(str_contains($html,$code.'/'.$no),'actual admin renders each issued alias');
 preg_match_all('/<tr class="sn-invoice-stage-row".*?<\/tr>/s',$html,$matches);
 foreach($matches[0] as $row)hr239_ok(!preg_match('/<(input|button|select|form)\b/',$row)&&!str_contains($row,'data-id='),'stage rows have no duplicate bulk selector or financial action');
 $digits=strtr($html,['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9']);
 hr239_ok(str_contains($digits,'400')&&str_contains($digits,'300'),'actual stage amounts are rendered');
 hr239_ok($before===hr239_financial_fingerprint(),'admin rendering preserves every financial row/column and wallet');
 $denied=false;$die=static fn()=>static function(...$args):void{throw new RuntimeException('stage-case-access-denied');};
 wp_set_current_user(0);wp_set_current_user($seller);add_filter('wp_die_ajax_handler',$die);
 try{$plugin->render_admin_invoices();}catch(RuntimeException $e){$denied=$e->getMessage()==='stage-case-access-denied';}finally{remove_filter('wp_die_ajax_handler',$die);}
 hr239_ok($denied,'seller cannot render administrative stage list');
 hr239_ok($before===hr239_financial_fingerprint(),'denied admin request is read-only');
 finance244_evidence('stage-case',['actual_admin_renderer'=>true,'owner_scope'=>true,'read_only'=>true,'canonical_rows'=>2,'unsettled_stage_rows'=>3,'settled_details'=>1,'stage_model_sha256'=>hash_file('sha256',SN_PLUGIN_DIR.'includes/class-sn-payment-transactions.php')]);
 echo 'TOTAL '.(int)$GLOBALS['hr239_checks'].PHP_EOL;
}finally{foreach($ids as $id){$wpdb->delete($pt,['invoice_id'=>$id]);$wpdb->delete($st,['invoice_id'=>$id]);$wpdb->delete($it,['id'=>$id]);}}
$out=ob_get_clean();echo $out;
