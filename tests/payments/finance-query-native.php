<?php
/** Before/after Finance query equivalence on synthetic data; no financial writes. */
ob_start();require dirname(__DIR__).'/hr/native-support.php';hr239_bootstrap();$f=hr239_data();global $wpdb;
wp_set_current_user((int)$f['admin_id']);$plugin=sn_bootstrap_plugin_instance();
$baseline=$GLOBALS['hr239_root'].'/finance-before-source.php';hr239_ok(is_file($baseline),'reviewed before-source supplied for benchmark');
$source=file_get_contents($baseline);$start=strpos($source,'public function ajax_financial_invoices(): void');$end=strpos($source,'/** Read-only, actor-specific invoice payment overview.',$start);hr239_ok($start!==false&&$end!==false,'before-source controller bounds');
$method=substr($source,$start,$end-$start);$body=substr($method,strpos($method,'{')+1);$body=substr($body,0,strrpos($body,'}'));
$old=eval('return function(){'.str_replace('SN_Helpers::send_json','finance244_capture',$body).'};');$old=$old->bindTo($plugin,SN_Plugin::class);
$next=file_get_contents(SN_PLUGIN_DIR.'includes/class-sn-plugin.php');$a=strpos($next,'public function ajax_financial_invoices(): void');$b=strpos($next,'/** Read-only, actor-specific invoice payment overview.',$a);$method=substr($next,$a,$b-$a);$body=substr($method,strpos($method,'{')+1);$body=substr($body,0,strrpos($body,'}'));
$new=eval('return function(){'.str_replace('SN_Helpers::send_json','finance244_capture',$body).'};');$new=$new->bindTo($plugin,SN_Plugin::class);
class Finance244_End extends RuntimeException{}
function finance244_capture(bool $ok,string $message,array $data=[]):void{$GLOBALS['finance244_response']=array_merge(['success'=>$ok,'message'=>$message],$data);throw new Finance244_End();}
$handler=static fn()=>static function(){throw new Finance244_End();};add_filter('wp_die_ajax_handler',$handler);add_filter('wp_die_handler',$handler);
$run=static function(bool $before,array $input)use($old,$new,$plugin,$wpdb):array{
 $_POST=array_replace(['nonce'=>wp_create_nonce('sn_public'),'tab'=>'all','limit'=>30,'page'=>1],$input);$_REQUEST=$_POST;$queries=[];
 $capture=static function($sql)use(&$queries){if(preg_match('/^\s*(INSERT|UPDATE|DELETE|ALTER|CREATE|DROP|REPLACE)\b/i',$sql))throw new RuntimeException('Finance read attempted a write');$queries[]=$sql;return$sql;};add_filter('query',$capture,999);$time=microtime(true);ob_start();
 try{if($before)$old();else$new();}catch(Finance244_End $end){}finally{ob_end_clean();remove_filter('query',$capture,999);}
 hr239_ok(!$wpdb->last_error,'Finance SQL executes without errors');$out=$GLOBALS['finance244_response'];hr239_ok(!empty($out['success']),'authorized Finance API succeeds');
 return ['data'=>$out,'ms'=>round((microtime(true)-$time)*1000,3),'queries'=>count($queries),'aggregate_queries'=>count(array_filter($queries,static fn($q)=>str_contains($q,'FROM '.$wpdb->prefix.'sn_invoices i')&&str_contains($q,'SUM(')))];
};
foreach([['tab'=>'all'],['tab'=>'needs_review'],['tab'=>'approved'],['tab'=>'rejected'],['tab'=>'online_paid'],['tab'=>'manual_deposit'],['tab'=>'receipt_uploaded'],['q'=>'F244','sort'=>'payment_desc'],['sort'=>'seller_asc','page'=>2]]as$filter){
 $before=$run(true,$filter);$after=$run(false,$filter);hr239_ok($before['data']===$after['data'],'identical list, bucket counts, sums, permissions and ordering '.json_encode($filter));
}
$_POST=['nonce'=>'invalid'];$_REQUEST=$_POST;try{$new();}catch(Finance244_End $e){}hr239_ok(empty($GLOBALS['finance244_response']['success']),'invalid nonce remains denied');
$marker=$GLOBALS['hr239_root'].'/finance244-volume.json';
if(!is_file($marker)){
 $tag='V244-'.bin2hex(random_bytes(3)).'-';$seller=(int)$f['users']['seller_a']['id'];$it=$wpdb->prefix.'sn_invoices';$pt=$wpdb->prefix.'sn_payments';
 for($batch=0;$batch<30;$batch++){
  $values=[];for($n=0;$n<1000;$n++){$code=$tag.($batch*1000+$n);$values[]=$wpdb->prepare("(%s,%d,'Synthetic Finance volume','00000000000',100000,100000,100000,0,100000,100000,1,'full','pending_financial_approval','pending_financial_approval','pending_financial_approval','card','1111',50000,'2026-11-01 10:00:00','2026-11-01 10:00:00')",$code,$seller);}
  hr239_ok($wpdb->query("INSERT INTO {$it} (invoice_code,seller_id,customer_name,customer_phone,product_price,final_total,payment_total_amount,paid_total_amount,remaining_amount,current_due_amount,current_payment_stage,payment_plan,status,payment_status,invoice_status,pay_method,manual_card_from,manual_amount,created_at,updated_at) VALUES ".implode(',',$values))===1000,'synthetic volume batch '.$batch);
 }
 hr239_ok($wpdb->query($wpdb->prepare("INSERT INTO {$pt} (invoice_id,payment_stage_no,amount,status,authority,pay_method,payment_source,proof_data,created_at,updated_at) SELECT id,1,50000,'pending','','card','seller_manual','{}','2026-11-01 10:00:00','2026-11-01 10:00:00' FROM {$it} WHERE invoice_code LIKE %s",$wpdb->esc_like($tag).'%'))===30000,'synthetic volume receipt ledger');
 file_put_contents($marker,wp_json_encode(['tag'=>$tag,'invoices'=>30000]));
}
$first=$run(true,['tab'=>'all']);$second=$run(false,['tab'=>'all']);hr239_ok($second['queries']<$first['queries'],'Finance uses fewer real SQL queries');
hr239_ok($first['data']===$second['data'],'large synthetic ledger preserves exact Finance output');
echo 'BENCHMARK '.wp_json_encode(['before_ms'=>$first['ms'],'after_ms'=>$second['ms'],'before_queries'=>$first['queries'],'after_queries'=>$second['queries'],'synthetic_invoices'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}sn_invoices")]).PHP_EOL;
finance244_evidence('query',['before_ms'=>$first['ms'],'after_ms'=>$second['ms'],'before_queries'=>$first['queries'],'after_queries'=>$second['queries'],'synthetic_invoices'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}sn_invoices"),'identical_outputs'=>true,'read_only'=>true]);echo 'TOTAL '.$GLOBALS['hr239_checks'].PHP_EOL;ob_end_flush();
