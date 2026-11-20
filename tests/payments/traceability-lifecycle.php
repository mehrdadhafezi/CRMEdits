<?php
/** Read/check lifecycle phases; only baseline fixture creation writes synthetic rows. */
$phase=(string)getenv('SN_TRACE_LIFECYCLE_PHASE');$versions=['baseline235'=>'2.0.235','baseline241'=>'2.0.241','bridge'=>'2.0.241','upgrade'=>'2.0.242','snapshot'=>'2.0.242','downgrade'=>'2.0.241','resume'=>'2.0.242','restore235'=>'2.0.235','restore241'=>'2.0.241','reupgrade'=>'2.0.242'];
if(!isset($versions[$phase]))throw new RuntimeException('Explicit lifecycle phase required');
require dirname(__DIR__).'/hr/native-support.php';hr239_bootstrap(true,$versions[$phase]);global $wpdb;$root=$GLOBALS['hr239_root'];$file=$root.'/trace242-lifecycle-state.json';
$state=is_file($file)?json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR):[];
$columns=static fn($table)=>(array)$wpdb->get_col("SHOW COLUMNS FROM `{$table}`",0);
$filter=static fn($table,$data)=>array_intersect_key($data,array_flip($columns($table)));
$legacy=static function()use($wpdb,&$state):array{$out=[];foreach($state['legacy'] as $name=>$records){foreach($records as $record){$row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}{$name} WHERE id=%d",(int)$record['id']),ARRAY_A);if(!$row)throw new RuntimeException('Legacy row removed during upgrade');$out[$name][]=array_intersect_key($row,$record);}}return$out;};
$snapshot=static function()use($wpdb):array{
 $data=['roles'=>get_option($wpdb->prefix.'user_roles'),'user_meta'=>$wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->usermeta} WHERE meta_key IN (%s,%s) ORDER BY umeta_id",$wpdb->prefix.'capabilities','sn_invoice_reissue_mode'),ARRAY_A),'tables'=>[]];
 $tables=$wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($wpdb->prefix.'sn_').'%'));
 foreach($tables as $table){
  if(!preg_match('/^[A-Za-z0-9_]+$/D',$table))throw new RuntimeException('Synthetic table identity');$ddl=$wpdb->get_row("SHOW CREATE TABLE `{$table}`",ARRAY_N);$rows=$wpdb->get_results("SELECT * FROM `{$table}`",ARRAY_A);
  if($wpdb->last_error)throw new RuntimeException('Lifecycle snapshot failed');usort($rows,static fn($a,$b)=>strcmp(wp_json_encode($a),wp_json_encode($b)));$data['tables'][$table]=['ddl'=>$ddl[1],'rows'=>$rows];
 }ksort($data['tables']);return$data;
};
if(str_starts_with($phase,'baseline')){
 hr239_ok($state===[],'fresh lifecycle state');$admin=(int)$wpdb->get_var("SELECT ID FROM {$wpdb->users} ORDER BY ID LIMIT 1");wp_set_current_user($admin);
 $state=['legacy'=>[],'baseline_version'=>SN_VERSION];$it=$wpdb->prefix.'sn_invoices';$now=current_time('mysql');
 foreach(['A','B']as$key){$data=['invoice_code'=>'L242-'.$key.'-'.bin2hex(random_bytes(3)),'access_token'=>bin2hex(random_bytes(24)),'seller_id'=>$admin,'issued_by_user_id'=>$admin,'customer_name'=>'Synthetic legacy lifecycle','customer_phone'=>'00000000000','product_price'=>5000000,'final_total'=>5000000,'payment_total_amount'=>5000000,'paid_total_amount'=>0,'remaining_amount'=>5000000,'current_due_amount'=>5000000,'current_payment_stage'=>1,'payment_plan'=>'full','status'=>'pre_invoice','invoice_status'=>'pre_invoice','payment_status'=>'pre_invoice','created_at'=>$now,'updated_at'=>$now];hr239_ok($wpdb->insert($it,$filter($it,$data))!==false,'legacy independent invoice '.$key);$id=(int)$wpdb->insert_id;$row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$it} WHERE id=%d",$id),ARRAY_A);$state['legacy']['sn_invoices'][]=array_intersect_key($row,$data+['id'=>$id]);}
 $invoice_id=(int)$state['legacy']['sn_invoices'][0]['id'];$pay=$wpdb->prefix.'sn_payments';
 hr239_ok($wpdb->insert($pay,$filter($pay,['invoice_id'=>$invoice_id,'payment_stage_no'=>1,'amount'=>100000,'status'=>'approved','pay_method'=>'card','payment_source'=>'synthetic_legacy','authority'=>'','created_at'=>$now,'updated_at'=>$now]))!==false,'legacy receipt without rewriting its identity');$pid=(int)$wpdb->insert_id;$row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$pay} WHERE id=%d",$pid),ARRAY_A);$state['legacy']['sn_payments'][]=array_intersect_key($row,array_flip(['id','invoice_id','payment_stage_no','amount','status','authority','ref_id']));
 $state['baseline_snapshot']=$snapshot();
}elseif(in_array($phase,['bridge','upgrade','reupgrade','restore235','restore241'],true)){
 hr239_ok($legacy()===$state['legacy'],'legacy invoice codes tokens amounts and payment ids survive '.$phase);
 if($phase==='bridge'){$state['stable_snapshot']=$snapshot();}
 if(in_array($phase,['upgrade','reupgrade'],true)){
  $expected=$state['stable_snapshot']??$state['baseline_snapshot'];hr239_ok($snapshot()===$expected,'accepted stable financial schema data and roles are unchanged by 242 bootstrap');
  $a=$state['legacy']['sn_invoices'][0];$p=$state['legacy']['sn_payments'][0];$invoice=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_invoices WHERE id=%d",(int)$a['id']));$rows=SN_Payment_Transactions::rows($invoice);
  hr239_ok(count($rows)===1&&$rows[0]['transaction_code']==='PAY-'.$p['id'],'legacy actual receipt gets its stable existing payment reference');
  $b=$state['legacy']['sn_invoices'][1];$ref=SN_Payment_Transactions::trace_reference(['payment_id'=>0,'invoice_id'=>(int)$b['id']],[]);hr239_ok($ref['transaction_code']==='','legacy invoice without receipt never gets a fabricated payment');
 }
 if(str_starts_with($phase,'restore')){hr239_ok($snapshot()===$state['baseline_snapshot'],'full backup restore recovers baseline financial schema data and roles');}
}elseif($phase==='snapshot'){
 $marked=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}sn_dot_invoice_links WHERE payment_kind='staged_receipt'");hr239_ok($marked>0,'candidate wrote real new-model synthetic purchases before downgrade');$state['new_snapshot']=$snapshot();$state['marked_count']=$marked;
}elseif(in_array($phase,['downgrade','resume'],true)){
 hr239_ok($snapshot()===$state['new_snapshot'],'code swap preserves financial state and roles during '.$phase);
 hr239_ok((int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}sn_dot_invoice_links WHERE payment_kind='staged_receipt'")===$state['marked_count'],'new and legacy invoice rows retained through '.$phase);
 // Old code is never used to continue marked purchases. A real rollback restores
 // the consistent pre-upgrade database as well as the old plugin code.
}
hr239_private('trace242-lifecycle-state.json',$state);
echo 'SUMMARY '.wp_json_encode(['phase'=>$phase,'passed'=>true,'synthetic'=>true,'version'=>SN_VERSION,'assertions'=>$GLOBALS['hr239_checks']??0,'production_touched'=>false]).PHP_EOL;
echo 'TOTAL '.($GLOBALS['hr239_checks']??0).PHP_EOL;
