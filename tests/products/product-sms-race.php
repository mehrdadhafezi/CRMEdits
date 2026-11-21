<?php
/** Two real PHP/MySQL workers; only the guarded synthetic namespace is accepted. */
ob_start();
require dirname(__DIR__).'/hr/native-support.php';hr239_bootstrap();
if(SN_VERSION!=='2.0.243')throw new RuntimeException('Exact product candidate required');
global $wpdb;$root=$GLOBALS['hr239_root'];$mode=$argv[1]??'';$key=$argv[2]??'';
function pr_out(array $data):void{ob_end_clean();echo wp_json_encode($data,JSON_THROW_ON_ERROR);exit;}
function pr_pause(string $name):void{
 $root=$GLOBALS['hr239_root'];file_put_contents($root.'/p241-'.$name.'-ready','ready');$end=microtime(true)+15;
 while(!file_exists($root.'/p241-'.$name.'-go')){if(microtime(true)>$end)throw new RuntimeException('Synthetic SMS barrier timeout');usleep(30000);}
}
if($mode==='fixtures'){
 $f=json_decode(file_get_contents($root.'/product241-fixture.json'),true,512,JSON_THROW_ON_ERROR);$pid=(int)$f['product_id'];
 $old=get_post_meta($pid,'_sn_invoice_sms_policy',true);update_post_meta($pid,'_sn_invoice_sms_policy',['enabled'=>false,'previous'=>true,'revision'=>wp_generate_uuid4()]);
 $template=(array)$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_invoices WHERE id=%d",$f['browser_invoice_id']));unset($template['id']);$ids=[];
 foreach(['overlap','stale']as$case){
  $row=$template;$row['invoice_code']=SN_Helpers::generate_invoice_code();$row['access_token']=SN_Helpers::generate_unique_access_token();$row['short_code']='';
  if(isset($row['wc_order_id']))$row['wc_order_id']=null;
  if(!$wpdb->insert($wpdb->prefix.'sn_invoices',$row))throw new RuntimeException('Synthetic SMS race invoice');$id=(int)$wpdb->insert_id;
  SN_Invoice_SMS_Policy::created($id);$r=SN_Invoice_SMS_Policy::dispatch($id,static fn()=>throw new RuntimeException('Disabled product bypass'));
  if($r['state']!=='blocked'||!SN_Invoice_SMS_Policy::delivery($id)['backlog_eligible'])throw new RuntimeException('Synthetic known-unsent claim');
  $ids[$case]=$id;update_option('p241_race_calls_'.$id,0,false);
 }
 update_post_meta($pid,'_sn_invoice_sms_policy',['enabled'=>true,'previous'=>true,'revision'=>wp_generate_uuid4()]);
 hr239_private('product241-race-fixture.json',['product'=>$pid,'policy'=>$old,'ids'=>$ids]);pr_out(['success'=>true,'cases'=>2]);
}
$f=json_decode(file_get_contents($root.'/product241-race-fixture.json'),true,512,JSON_THROW_ON_ERROR);
if($mode==='restore'){update_post_meta((int)$f['product'],'_sn_invoice_sms_policy',$f['policy']);pr_out(['success'=>true]);}
if(!in_array($key,['overlap','stale'],true))throw new RuntimeException('Fixed synthetic race key required');$id=(int)$f['ids'][$key];
$send=static function()use($id,$mode):bool{
 if($mode==='send-pause')pr_pause('provider');
 update_option('p241_race_calls_'.$id,(int)get_option('p241_race_calls_'.$id,0)+1,false);return true;
};
if(in_array($mode,['send','send-pause','compete','stale-reader'],true)){
 if($mode==='compete')SN_Invoice_SMS_Policy::created($id);
 if($mode==='stale-reader'){
  $primed=json_decode((string)get_option('sn_invoice_sms_delivery_'.$id,''),true);
  if(empty($primed['backlog_eligible']))throw new RuntimeException('Stale-read control was not primed');pr_pause('reader');
 }
 $result=SN_Invoice_SMS_Policy::dispatch($id,$send,static fn()=>false,true);
 pr_out(['sent'=>$result['sent'],'state'=>$result['state'],'busy'=>!empty($result['busy'])]);
}
if($mode==='verify'){
 $record=SN_Invoice_SMS_Policy::delivery($id);$lock='sn_invoice_sms_'.substr(hash('sha256',DB_NAME.':'.$wpdb->prefix.':'.$id),0,44);
 $checks=['one_provider_call'=>(int)get_option('p241_race_calls_'.$id,0)===1,'durable_acceptance'=>($record['state']??'')==='sent'&&!empty($record['ever_sent']),
  'not_backlog_eligible'=>empty($record['backlog_eligible']),'lock_released'=>$wpdb->get_var($wpdb->prepare('SELECT IS_FREE_LOCK(%s)',$lock))==='1'];
 SN_Invoice_SMS_Policy::created($id);$checks['late_creation_cannot_reset_acceptance']=SN_Invoice_SMS_Policy::delivery($id)===$record;
 $again=SN_Invoice_SMS_Policy::dispatch($id,$send,static fn()=>false,true);
 $checks['replay_skipped']=!$again['sent']&&$again['state']==='skipped';$checks['replay_no_provider']=(int)get_option('p241_race_calls_'.$id,0)===1;
 pr_out(['passed'=>!in_array(false,$checks,true),'checks'=>$checks,'assertions'=>count($checks)]);
}
throw new RuntimeException('Fixed synthetic worker mode required');
