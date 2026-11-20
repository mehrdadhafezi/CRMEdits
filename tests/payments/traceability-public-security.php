<?php
/** One real public controller request per PHP process: preserve its output/exit boundary. */
require dirname(__DIR__).'/hr/native-support.php';hr239_bootstrap();$f=hr239_data();global $wpdb;
$case=(string)getenv('SN_TRACE_SECURITY_CASE');$cases=['guest_missing','guest_forged','guest_valid','guest_nonce','foreign_forged','hr_forged'];if(!in_array($case,$cases,true))throw new RuntimeException('Explicit synthetic public case required');
$race=json_decode(file_get_contents($GLOBALS['hr239_root'].'/trace242-race.json'),true,512,JSON_THROW_ON_ERROR);$id=(int)$race['invoice_id'];
$invoice=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_invoices WHERE id=%d",$id));if(!$invoice||!SN_Dot_Flow::instance()->is_staged_conversion_invoice($id))throw new RuntimeException('Synthetic marked invoice required');
$hash=static function()use($wpdb):string{$rows=[];foreach(['sn_invoices','sn_invoice_payment_stages','sn_payments','sn_dot_cases','sn_dot_payments','sn_wallets','sn_wallet_transactions','sn_payment_review_events']as$t){$rows[$t]=$wpdb->get_results("SELECT * FROM {$wpdb->prefix}{$t} ORDER BY id",ARRAY_A);if($wpdb->last_error)throw new RuntimeException('Public test snapshot failed');}return hash('sha256',wp_json_encode($rows));};$before=$hash();
$actor=$case==='foreign_forged'?(int)$f['users']['seller_b']['id']:($case==='hr_forged'?(int)$f['users']['hr']['id']:0);wp_set_current_user(0);wp_set_current_user($actor);
$_GET=[];$_FILES=[];$_POST=['action'=>'sn_invoice_transactions','invoice_code'=>(string)$invoice->invoice_code,'nonce'=>$case==='guest_nonce'?'bad':wp_create_nonce('sn_public')];
if($case!=='guest_missing')$_POST['access_token']=in_array($case,['guest_valid','guest_nonce'],true)?(string)$invoice->access_token:'forged';$_REQUEST=$_POST;http_response_code(200);
$meta=$GLOBALS['hr239_root'].'/trace242-public-'.$case.'.json';$token_hash=hash('sha256',(string)$invoice->access_token);
register_shutdown_function(static function()use($meta,$hash,$before,$case,$token_hash){file_put_contents($meta,wp_json_encode(['case'=>$case,'unchanged'=>hash_equals($before,$hash()),'token_hash'=>$token_hash,'status'=>http_response_code()]));chmod($meta,0600);});
// The actual helper deliberately clears buffers and wp_send_json exits. No fake transport.
sn_bootstrap_plugin_instance()->ajax_invoice_transactions();
