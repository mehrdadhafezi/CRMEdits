<?php
/** In-memory unit regression only. No WordPress/site/provider bootstrap. */
define('ABSPATH', __DIR__.'/'); define('DB_NAME','synthetic_memory');
if (!class_exists('SQLite3')) { fwrite(STDERR,"SQLite3 required\n"); exit(2); }
class WP_Error { public function __construct(public string $code, public string $message) {} public function get_error_message(){return $this->message;} }
function is_wp_error($v){return $v instanceof WP_Error;}
function absint($v){return abs((int)$v);}
function get_user_by($key,$id){$users=[1=>['administrator'],2=>['sn_sales_manager'],3=>['sn_seller'],4=>['sn_sales_manager'],5=>['sn_seller']];return isset($users[$id])?(object)['ID'=>$id,'roles'=>$users[$id]]:false;}
function user_can($id,$cap){return $id===1&&$cap==='manage_options';}
function get_user_meta($id,$key,$single=true){return $GLOBALS['um'][$id][$key]??'';}
function get_post_meta($id,$key,$single=true){return $GLOBALS['pm'][$id][$key]??'';}
function get_the_title($id){return 'Synthetic product '.$id;}
function get_current_user_id(){return 1;}
function wp_json_encode($v){return json_encode($v);}
function wp_generate_uuid4(){return sprintf('00000000-0000-4000-8000-%012d',++$GLOBALS['uuid']);}
function update_option($key,$value,$autoload=false){
 $GLOBALS['writes']++;if(($GLOBALS['fail_write']??0)===$GLOBALS['writes'])return false;
 global $wpdb;return $wpdb->db->exec("INSERT INTO ut_options(option_name,option_value) VALUES ('".SQLite3::escapeString($key)."','".SQLite3::escapeString($value)."') ON CONFLICT(option_name) DO UPDATE SET option_value=excluded.option_value");
}
class SN_Helpers {
 public static function to_english_nums($s){return strtr($s,array_combine(preg_split('//u','۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩',-1,PREG_SPLIT_NO_EMPTY),str_split('01234567890123456789')));}
 public static function format_price($n){return (string)$n.' تومان';}
}
class MemoryDB {
 public string $prefix='ut_',$options='ut_options',$last_error=''; public SQLite3 $db; public bool $busy=false,$fail_items=false,$fail_delivery=false;
 public function __construct(){
  $this->db=new SQLite3(':memory:');$this->db->enableExceptions(true);
  $this->db->exec("CREATE TABLE ut_options(option_name TEXT PRIMARY KEY,option_value TEXT);CREATE TABLE ut_sn_invoices(id INTEGER PRIMARY KEY,product_id INTEGER,status TEXT,payment_status TEXT,paid_total_amount REAL,paid_amount REAL);CREATE TABLE ut_sn_invoice_items(invoice_id INTEGER,product_id INTEGER);CREATE TABLE ut_sn_payments(id INTEGER PRIMARY KEY,invoice_id INTEGER,status TEXT,authority TEXT)");
 }
 public function prepare($sql,...$args){if(count($args)===1&&is_array($args[0]))$args=$args[0];$i=0;return preg_replace_callback('/%[sdf]/',function($m)use(&$i,$args){$v=$args[$i++];return $m[0]==='%d'?(string)(int)$v:($m[0]==='%f'?(string)(float)$v:"'".SQLite3::escapeString((string)$v)."'");},$sql);}
 public function esc_like($s){return addcslashes($s,'_%\\');}
 private function rows($sql){
  $this->last_error='';if(str_starts_with($sql,'SHOW TABLES'))return [];
  if(($this->fail_items&&str_contains($sql,'sn_invoice_items'))||($this->fail_delivery&&str_contains($sql,'SELECT option_value'))){$this->last_error='synthetic failure';return [];}
  try{$r=$this->db->query($sql);$rows=[];while($row=$r->fetchArray(SQLITE3_ASSOC))$rows[]=$row;return $rows;}catch(Throwable $e){$this->last_error='synthetic query failure';return [];}
 }
 public function get_var($sql){$this->last_error='';if(str_contains($sql,'GET_LOCK('))return $this->busy?'0':'1';if(str_contains($sql,'RELEASE_LOCK('))return '1';$r=$this->rows($sql);return $r?array_values($r[0])[0]:null;}
 public function get_row($sql){$r=$this->rows($sql);return $r?(object)$r[0]:null;}
 public function get_col($sql){return array_map(static fn($r)=>array_values($r)[0],$this->rows($sql));}
}
$GLOBALS['uuid']=0;$GLOBALS['writes']=0;$GLOBALS['um']=[3=>['sn_sales_manager_id'=>2],5=>['sn_sales_manager_id'=>4]];$GLOBALS['pm']=[];
$wpdb=new MemoryDB();
require __DIR__.'/../../includes/class-sn-sales-catalog.php';
require __DIR__.'/../../includes/class-sn-invoice-sms-policy.php';
$checks=0;function ok($v,$label){global $checks;if(!$v)throw new RuntimeException('FAIL '.$label);$checks++;echo 'PASS '.$label.PHP_EOL;}
function policy($enabled){$GLOBALS['pm'][101]['_sn_invoice_sms_policy']=['enabled'=>$enabled,'previous'=>true,'revision'=>'unit'];}
function invoice($id,$new=true,$status='pre_invoice',$paid=0){global $wpdb;$wpdb->db->exec("INSERT INTO ut_sn_invoices VALUES ($id,101,'$status','pre_invoice',$paid,0)");if($new)SN_Invoice_SMS_Policy::created($id);}
function priced($choice,$product=101,$qty=2,$actor=3,$owner=3){return SN_Sales_Catalog::instance()->price_items([['product_id'=>$product,'product_name'=>'Synthetic','unit_price'=>1000,'qty'=>$qty]],[$choice],$actor,$owner);}
$GLOBALS['um'][2]['_sn_sales_catalog_policy']=['mode'=>'all','products'=>[101=>['discounts'=>['10000']]]];
ok(!SN_Sales_Catalog::instance()->custom_discount_allowed(101,3,3),'custom discount defaults off');
ok(is_wp_error(priced('custom:12500')),'forged custom choice rejected when off');
$fixed=priced('10000');ok(!is_wp_error($fixed)&&$fixed['final_total']===1800&&$fixed['discount_total']===200,'fixed choice and quantity unchanged');
$GLOBALS['um'][2]['_sn_sales_catalog_policy']['products'][101]['allow_custom_discount']=true;
$price=priced('custom:12525');ok(!is_wp_error($price)&&$price['discount_total']===250.5&&$price['final_total']===1749.5,'custom cents calculated per unit from server price');
ok($price['snapshot'][0]['option_id']==='custom:12525'&&$price['snapshot'][0]['original_unit_price']===1000,'custom amount preserved in invoice snapshot');
foreach(['custom:0','custom:-1','custom:01','custom:1.5','custom:1e3','custom:100000','custom:999999999999999999999','custom:1 OR 1=1'] as $bad)ok(is_wp_error(priced($bad)),'invalid or excessive custom amount rejected '.$bad);
ok(is_wp_error(priced(['custom'=>12500])),'array discount rejected');
ok(is_wp_error(priced('custom:12500',102)),'another product cannot borrow custom permission');
ok(is_wp_error(priced('custom:12500',101,1,5,5)),'another manager cannot borrow custom permission');
ok(!is_wp_error(priced('custom:12500',101,1,1,3)),'admin issuing for seller uses seller manager grant');
ok(SN_Sales_Catalog::amount_key('۱۲۵٫۲۵')==='12525','localized money retains cents');
$snapshot=json_encode($price['snapshot']);$GLOBALS['um'][2]['_sn_sales_catalog_policy']['products'][101]['allow_custom_discount']=false;
ok(SN_Sales_Catalog::invoice_snapshot((object)['catalog_discounts_snapshot'=>$snapshot])===$price['snapshot'],'old snapshot survives permission revocation');
ok(is_wp_error(priced('custom:12500')),'revoked custom grant enforced');
$sent=0;$send=static function()use(&$sent){$sent++;return true;};
policy(false);invoice(1);$r=SN_Invoice_SMS_Policy::dispatch(1,$send,static fn()=>false);
ok(!$r['sent']&&$r['state']==='blocked'&&$sent===0,'disabled product prevents provider call');
ok(SN_Invoice_SMS_Policy::delivery(1)['backlog_eligible']===true,'known new blocked invoice becomes eligible');
policy(true);$r=SN_Invoice_SMS_Policy::dispatch(1,$send,static fn()=>false,true);
ok($r['sent']&&$sent===1,'re-enable permits confirmed previous known-unsent invoice');
ok(SN_Invoice_SMS_Policy::delivery(1)['ever_sent']===true,'accepted dispatch stored durably');
$r=SN_Invoice_SMS_Policy::dispatch(1,$send,static fn()=>false,true);ok(!$r['sent']&&$r['state']==='skipped'&&$sent===1,'repeat historical send never calls provider twice');
policy(false);invoice(2,false);SN_Invoice_SMS_Policy::dispatch(2,$send);ok(SN_Invoice_SMS_Policy::delivery(2)['backlog_eligible']===false,'unknown legacy history never inferred unsent');
policy(true);SN_Invoice_SMS_Policy::dispatch(2,$send,null,true);ok($sent===1,'legacy ambiguous history excluded after re-enable');
invoice(3);$r=SN_Invoice_SMS_Policy::dispatch(3,static fn()=>false,static fn()=>true);ok($r['state']==='uncertain'&&!SN_Invoice_SMS_Policy::delivery(3)['backlog_eligible'],'unknown provider result excluded');
policy(false);SN_Invoice_SMS_Policy::dispatch(3,$send);ok(SN_Invoice_SMS_Policy::delivery(3)['ever_uncertain']&&!SN_Invoice_SMS_Policy::delivery(3)['backlog_eligible'],'disable does not erase prior delivery ambiguity');
invoice(4);SN_Invoice_SMS_Policy::dispatch(4,$send);policy(true);$wpdb->db->exec("UPDATE ut_sn_invoices SET status='paid',paid_total_amount=1000 WHERE id=4");SN_Invoice_SMS_Policy::dispatch(4,$send,null,true);ok($sent===1,'paid before dispatch skips historical send');
policy(false);invoice(5);SN_Invoice_SMS_Policy::dispatch(5,$send);policy(true);$wpdb->db->exec("UPDATE ut_sn_invoices SET status='cancelled' WHERE id=5");SN_Invoice_SMS_Policy::dispatch(5,$send,null,true);ok($sent===1,'cancelled invoice excluded');
policy(false);invoice(6);SN_Invoice_SMS_Policy::dispatch(6,$send);policy(true);$wpdb->db->exec("INSERT INTO ut_sn_payments VALUES (1,6,'approved','')");SN_Invoice_SMS_Policy::dispatch(6,$send,null,true);ok($sent===1,'approved payment wins over stale invoice flag');
invoice(7);$GLOBALS['pm'][102]['_sn_invoice_sms_policy']=['enabled'=>false];$wpdb->db->exec('INSERT INTO ut_sn_invoice_items VALUES (7,102)');SN_Invoice_SMS_Policy::dispatch(7,$send);ok($sent===1,'mixed invoice blocked by any disabled product');
invoice(8);$wpdb->fail_items=true;SN_Invoice_SMS_Policy::dispatch(8,$send);$wpdb->fail_items=false;ok($sent===1,'item read error cannot bypass product gate');
invoice(9);$wpdb->busy=true;SN_Invoice_SMS_Policy::dispatch(9,$send);$wpdb->busy=false;ok($sent===1,'competing dispatch lock fails closed');
invoice(10);$GLOBALS['writes']=0;$GLOBALS['fail_write']=1;SN_Invoice_SMS_Policy::dispatch(10,$send);$GLOBALS['fail_write']=0;ok($sent===1,'failed durable claim prevents provider call');
invoice(11);$GLOBALS['writes']=0;$GLOBALS['fail_write']=2;SN_Invoice_SMS_Policy::dispatch(11,$send,static fn()=>false);$GLOBALS['fail_write']=0;ok($sent===2&&SN_Invoice_SMS_Policy::delivery(11)['state']==='dispatching','accepted but failed final audit retains uncertain claim');
SN_Invoice_SMS_Policy::dispatch(11,$send,null,true);ok($sent===2,'uncertain final audit cannot cause batch retry');
invoice(12);$wpdb->fail_delivery=true;SN_Invoice_SMS_Policy::dispatch(12,$send);$wpdb->fail_delivery=false;ok($sent===2,'delivery read error fails closed');
invoice(13);$r=SN_Invoice_SMS_Policy::dispatch(13,static fn()=>throw new RuntimeException('synthetic'));ok(!$r['sent']&&SN_Invoice_SMS_Policy::delivery(13)['ever_uncertain'],'provider exception recorded without a retryable unsent claim');
echo 'TOTAL '.$checks.PHP_EOL;
