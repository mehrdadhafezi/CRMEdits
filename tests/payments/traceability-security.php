<?php
/** Real WordPress controllers/nonce/capability checks in a guarded synthetic namespace only. */
ob_start();require dirname(__DIR__).'/hr/native-support.php';hr239_bootstrap();$f=hr239_data();global $wpdb;
$root=$GLOBALS['hr239_root'];$race=json_decode(file_get_contents($root.'/trace242-race.json'),true,512,JSON_THROW_ON_ERROR);
$id=(int)$race['invoice_id'];$case_id=(int)$race['case_id'];$plugin=sn_bootstrap_plugin_instance();$dot=SN_Dot_Flow::instance();
$seller=(int)$race['seller_id'];$other=(int)$f['users']['seller_b']['id'];$hr=(int)$f['users']['hr']['id'];$subscriber=(int)$f['users']['subscriber']['id'];$admin=(int)$f['admin_id'];
$invoice=static fn()=>$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_invoices WHERE id=%d",$id));
hr239_ok($invoice()&&$dot->is_staged_conversion_invoice($id)&&(int)$invoice()->current_payment_stage===2,'security fixture is the explicitly generated new Dot ledger');
$call=static function(string $method,...$args)use($plugin){$r=new ReflectionMethod($plugin,$method);$r->setAccessible(true);return$r->invoke($plugin,...$args);};
class Trace242ResponseEnd extends RuntimeException{}
function trace242_request(int $actor,string $action,array $data=[],?string $nonce=null):array{
 global $plugin,$dot;wp_set_current_user(0);wp_set_current_user($actor);http_response_code(200);$_GET=[];$_FILES=[];
 $_POST=array_merge(['action'=>$action,'nonce'=>$nonce??wp_create_nonce('sn_public')],$data);$_REQUEST=$_POST;$captured=null;$status=200;
 $handler=static function()use(&$captured,&$status){return static function(...$args)use(&$captured,&$status):void{if($captured===null){$captured=ob_get_contents();$status=http_response_code();if($captured==='')$captured=wp_json_encode(['success'=>false,'message'=>is_string($args[0]??null)?$args[0]:'Synthetic request ended']);}throw new Trace242ResponseEnd();};};
 add_filter('wp_die_ajax_handler',$handler);add_filter('wp_die_handler',$handler);ob_start();
 try{if($action==='sn_receipt_review')$plugin->ajax_receipt_review();elseif($action==='sn_invoice_transactions')$plugin->ajax_invoice_transactions();elseif($action==='sn_dot_create_payment')$dot->handle_create_payment();elseif($action==='sn_export_report_csv')$plugin->handle_export_report_csv();else throw new RuntimeException('Unknown synthetic controller');}
 catch(Trace242ResponseEnd $end){}finally{$raw=ob_get_clean();remove_filter('wp_die_ajax_handler',$handler);remove_filter('wp_die_handler',$handler);}
 $out=json_decode($captured??$raw,true,512,JSON_THROW_ON_ERROR);$out['_status']=$status;return$out;
}
$hash=static function()use($wpdb):string{$rows=[];foreach(['sn_invoices','sn_invoice_payment_stages','sn_payments','sn_dot_cases','sn_dot_payments','sn_wallets','sn_wallet_transactions','sn_payment_review_events']as$t){$rows[$t]=$wpdb->get_results("SELECT * FROM {$wpdb->prefix}{$t} ORDER BY id",ARRAY_A);if($wpdb->last_error)throw new RuntimeException('Synthetic state snapshot unavailable');}return hash('sha256',wp_json_encode($rows));};
$base=$hash();
foreach([0,$other,$hr,$subscriber]as$actor){$r=trace242_request($actor,'sn_receipt_review',['invoice_id'=>$id,'operation'=>'read']);hr239_ok(empty($r['success'])&&$hash()===$base,'guest or unrelated role cannot read new Dot receipt ledger '.$actor);}
foreach(['','bad-nonce']as$nonce){$r=trace242_request($admin,'sn_receipt_review',['invoice_id'=>$id,'operation'=>'read'],$nonce);hr239_ok(empty($r['success'])&&$r['_status']===403&&$hash()===$base,'missing or forged receipt nonce fails before financial writes');}
wp_set_current_user($other);$foreign_nonce=wp_create_nonce('sn_public');$r=trace242_request($admin,'sn_receipt_review',['invoice_id'=>$id,'operation'=>'read'],$foreign_nonce);hr239_ok(empty($r['success'])&&$hash()===$base,'nonce from another user cannot authorize Finance read');
wp_set_current_user($admin);$view=trace242_request($admin,'sn_receipt_review',['invoice_id'=>$id,'operation'=>'read']);hr239_ok(!empty($view['success']),'administrator can read the new Dot receipt ledger through the real controller');
hr239_ok(!str_contains(wp_json_encode($view),(string)$invoice()->access_token)&&!str_contains(wp_json_encode($view),'"authority"'),'authorized receipt response does not disclose invoice token or provider authority');
foreach([[],['invoice_id'=>['1']],['invoice_id'=>"0 OR 1=1"],['operation'=>['approve']],['invoice_id'=>PHP_INT_MAX]]as$bad){$r=trace242_request($admin,'sn_receipt_review',array_merge(['invoice_id'=>0,'operation'=>'read'],$bad));hr239_ok(empty($r['success'])&&$hash()===$base,'malformed receipt identity or operation cannot mutate financial state');}
// Public transaction controllers deliberately flush output buffers; exercise them
// in separate real PHP requests via traceability-processes.py rather than mocking that boundary.
foreach([0,$other,$hr,$subscriber]as$actor){wp_set_current_user(0);wp_set_current_user($actor);$nonce=wp_create_nonce('sn_dot_create_payment_'.$case_id);$r=trace242_request($actor,'sn_dot_create_payment',['case_id'=>$case_id,'payment_type'=>'balance','_wpnonce'=>$nonce]);hr239_ok(empty($r['success'])&&$hash()===$base,'Dot case ownership blocks direct next-stage controller access '.$actor);}
foreach(['','bad']as$nonce){$r=trace242_request($admin,'sn_dot_create_payment',['case_id'=>$case_id,'payment_type'=>'balance','_wpnonce'=>$nonce]);hr239_ok(empty($r['success'])&&$hash()===$base,'Dot next-stage CSRF nonce failure preserves every financial row');}
foreach([$seller,$other,$hr,$subscriber]as$actor){$r=trace242_request($actor,'sn_export_report_csv',['report_type'=>'payments']);hr239_ok(empty($r['success'])&&$hash()===$base,'nonadministrator cannot directly download payment CSV '.$actor);}
// Real capability revocation: adding an HR role never grants Finance approval.
$user=get_user_by('id',$subscriber);$user->add_cap('sn_view_finance');$user->add_cap('sn_approve_payment');
hr239_ok($wpdb->insert($wpdb->prefix.'sn_payments',['invoice_id'=>$id,'payment_stage_no'=>2,'amount'=>3000000,'status'=>'pending','authority'=>'','pay_method'=>'card','payment_source'=>'seller_manual','uploaded_by_type'=>'seller','uploaded_by_user_id'=>$seller,'proof_data'=>wp_json_encode(['entry_mode'=>'info','manual_transfer_type'=>'card','_review'=>['revision'=>1]])])!==false,'explicit synthetic pending receipt for capability checks');$pid=(int)$wpdb->insert_id;$base=$hash();
$user->remove_cap('sn_approve_payment');$r=trace242_request($subscriber,'sn_receipt_review',['invoice_id'=>$id,'payment_id'=>$pid,'operation'=>'approve','revision'=>1,'role'=>'administrator']);hr239_ok(empty($r['success'])&&$hash()===$base,'revoked Finance approval cannot be restored by a forged role field');
$user->remove_cap('sn_view_finance');
$r=trace242_request($seller,'sn_receipt_review',['invoice_id'=>$id,'payment_id'=>$pid,'operation'=>'approve','revision'=>1]);hr239_ok(empty($r['success'])&&$hash()===$base,'receipt owner cannot approve their own money or commission');
$files=['name'=>'synthetic.php','type'=>'image/png','tmp_name'=>__FILE__,'error'=>UPLOAD_ERR_OK,'size'=>100];$checked=SN_Helpers::upload_receipt($files);hr239_ok(isset($checked['success'])&&$checked['success']===false&&$hash()===$base,'disguised executable proof is rejected without saving financial state');
$too_large=['name'=>'synthetic.jpg','type'=>'image/jpeg','tmp_name'=>__FILE__,'error'=>UPLOAD_ERR_OK,'size'=>6*1024*1024];$checked=SN_Helpers::upload_receipt($too_large);hr239_ok(isset($checked['success'])&&$checked['success']===false&&$hash()===$base,'oversized proof is rejected before storage or financial writes');
$summary=['passed'=>true,'synthetic'=>true,'assertions'=>$GLOBALS['hr239_checks'],'version'=>SN_VERSION,'php'=>PHP_VERSION,'wordpress'=>$GLOBALS['wp_version'],'mysql'=>$wpdb->db_version(),'core_sha256'=>hash_file('sha256',SN_PLUGIN_DIR.'includes/class-sn-plugin.php'),'commission_sha256'=>hash_file('sha256',SN_PLUGIN_DIR.'includes/class-sn-purpose-commission.php'),'production_touched'=>false,'actual_wordpress_controllers'=>true,'nonce_scope_capability_negative_cases'=>true];
file_put_contents($GLOBALS['hr239_output'].'/trace242-security.json',wp_json_encode($summary,JSON_PRETTY_PRINT));echo 'SUMMARY '.wp_json_encode($summary).PHP_EOL;echo 'TOTAL '.$GLOBALS['hr239_checks'].PHP_EOL;ob_end_flush();
