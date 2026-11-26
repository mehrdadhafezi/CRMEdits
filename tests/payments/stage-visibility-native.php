<?php
/** Synthetic WordPress/MySQL only; read controllers never approve money. */
ob_start();require dirname(__DIR__).'/hr/native-support.php';hr239_bootstrap();$f=hr239_data();global $wpdb;
$plugin=sn_bootstrap_plugin_instance();$seller=(int)$f['users']['seller_a']['id'];$other=(int)$f['users']['seller_b']['id'];
$it=$wpdb->prefix.'sn_invoices';$st=$wpdb->prefix.'sn_invoice_payment_stages';$ids=[];
class StageVisibilityResponse extends RuntimeException{}
function stage_visibility_response(bool $ok,string $message,array $data=[]):void{$GLOBALS['sv_response']=compact('ok','message','data');throw new StageVisibilityResponse();}
$m=new ReflectionMethod($plugin,'ajax_seller_invoices');$source=implode('',array_slice(file($m->getFileName()),$m->getStartLine()-1,$m->getEndLine()-$m->getStartLine()+1));$body=substr($source,strpos($source,'{')+1);$body=substr($body,0,strrpos($body,'}'));
$handler=(eval('return function(){'.str_replace('SN_Helpers::send_json','stage_visibility_response',$body).'};'))->bindTo($plugin,SN_Plugin::class);
$request=static function(int $actor,string $q,?string $nonce=null)use($handler):array{wp_set_current_user(0);wp_set_current_user($actor);$_POST=['q'=>$q,'tab'=>'all','nonce'=>$nonce??wp_create_nonce('sn_public')];$_REQUEST=$_POST;try{$handler();}catch(StageVisibilityResponse $e){}return $GLOBALS['sv_response'];};
try {
 foreach([$seller,$other] as $owner){$code='SV-'.bin2hex(random_bytes(5));hr239_ok($wpdb->insert($it,['invoice_code'=>$code,'seller_id'=>$owner,'issued_by_user_id'=>$owner,'customer_name'=>'Synthetic stage visibility','customer_phone'=>'00000000000','product_price'=>1000,'final_total'=>1000,'payment_total_amount'=>1000,'paid_total_amount'=>400,'remaining_amount'=>600,'current_due_amount'=>600,'current_payment_stage'=>2,'payment_plan'=>'partial','status'=>'partial_paid','payment_status'=>'partial_paid','invoice_status'=>'partial_paid'])!==false,'synthetic invoice inserted');$id=(int)$wpdb->insert_id;$ids[]=$id;$codes[$id]=$code;
  foreach([[1,400,'paid'],[2,600,'pending_financial_approval']] as [$no,$amount,$status])hr239_ok($wpdb->insert($st,['invoice_id'=>$id,'stage_no'=>$no,'requested_amount'=>$amount,'status'=>$status])!==false,'synthetic stage inserted');
 }
 $id=$ids[0];$code=$codes[$id];$before=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$it} WHERE id=%d",$id),ARRAY_A);
 $h=SN_Payment_Transactions::stage_history([['id'=>$id,'invoice_code'=>$code]]);
 hr239_ok(array_keys($h)===[$id]&&count($h[$id]['stages'])===2,'batch projection exposes only requested scoped invoices');
 hr239_ok(array_column($h[$id]['stages'],'stage_invoice_code')===[$code.'/1',$code.'/2'],'both issued aliases preserve one canonical invoice');
 hr239_ok(array_column($h[$id]['stages'],'status')===['paid','pending_financial_approval'],'paid stage and pending Finance stage remain separate');
 hr239_ok(array_column($h[$id]['stages'],'amount')===[400.0,600.0],'stage amounts are not replaced by total purchase value');
 hr239_ok(!isset($h[$id]['stages'][0]['payment_ref_id'])&&!isset($h[$id]['access_token']),'projection exposes no provider reference or public token');
 $n=$wpdb->num_queries;SN_Payment_Transactions::stage_history([['id'=>$id,'invoice_code'=>$code],['id'=>$ids[1],'invoice_code'=>$codes[$ids[1]]]]);hr239_ok($wpdb->num_queries-$n===1,'two invoices use one batched stage query');
 $n=$wpdb->num_queries;hr239_ok(SN_Payment_Transactions::stage_history([])===[]&&$wpdb->num_queries===$n,'empty page adds no query');
 foreach([1,2] as $no){$r=$request($seller,$code.'/'.$no);$rows=$r['data']['invoices']??[];hr239_ok($r['ok']&&count($rows)===1&&(int)$rows[0]['id']===$id,'actual seller controller finds an issued alias exactly once');hr239_ok(count($rows[0]['stage_history']['stages'])===2,'seller response retains complete issued stage history');hr239_ok((float)$rows[0]['payment_summary']['remaining']===600.0&&$rows[0]['status']==='partial_paid','approved first stage does not settle purchase');hr239_ok(!isset($rows[0]['access_token']),'seller alias search does not expose public token');}
 foreach([$code.'/3',$code.'/0',$codes[$ids[1]].'/1'] as $q){$r=$request($seller,$q);hr239_ok($r['ok']&&empty($r['data']['invoices']),'absent or out-of-scope stage cannot be found');}
 hr239_ok(!$request(0,$code.'/1')['ok'],'anonymous search denied');hr239_ok(!$request($seller,$code.'/1','invalid')['ok'],'invalid nonce denied');
 [$sql,$args]=SN_Payment_Tracking::stage_condition('i.id',$it,$st,$code.'/2');hr239_ok(count($args)===2&&$args[0]===strtoupper($code)&&$args[1]===2&&str_contains($sql,'si.id=i.id'),'alias uses bound parameters and invoice correlation');
 foreach(['X/0','X/-1','X/1000000000','X/1 OR 1=1','<img>/1','X/%'] as $q)hr239_ok(SN_Payment_Tracking::stage_condition('i.id',$it,$st,$q)===['0=1',[]],'invalid alias cannot broaden search');
 hr239_ok($before===$wpdb->get_row($wpdb->prepare("SELECT * FROM {$it} WHERE id=%d",$id),ARRAY_A),'read projection and searches do not mutate invoice or money');
 finance244_evidence('stage-visibility',['real_wordpress_mysql'=>true,'actual_seller_controller'=>true,'owner_scope'=>true,'read_only'=>true,'batch_query_verified'=>true,'stage_model_sha256'=>hash_file('sha256',SN_PLUGIN_DIR.'includes/class-sn-payment-transactions.php'),'tracking_sha256'=>hash_file('sha256',SN_PLUGIN_DIR.'includes/class-sn-payment-tracking.php')]);
 echo 'TOTAL '.(int)$GLOBALS['hr239_checks'].PHP_EOL;
} finally {foreach($ids as $id){$wpdb->delete($st,['invoice_id'=>$id]);$wpdb->delete($it,['id'=>$id]);}}
$out=ob_get_clean();echo $out;
