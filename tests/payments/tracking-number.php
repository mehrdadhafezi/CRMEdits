<?php
/** Memory only. No WordPress bootstrap, external database, upload or provider. */
define('ABSPATH','/synthetic-tracking/');define('ARRAY_A','ARRAY_A');
require dirname(__DIR__,2).'/includes/class-sn-helpers.php';
require dirname(__DIR__,2).'/includes/class-sn-payment-transactions.php';
require dirname(__DIR__,2).'/includes/class-sn-payment-batch.php';
function sanitize_key($v){return preg_replace('/[^a-z0-9_-]/','',strtolower($v));}
function sanitize_text_field($v){return trim(strip_tags($v));}
$checks=0;
function tracking_ok($yes,$label){global $checks;if(!$yes)throw new RuntimeException('FAIL '.$label);$checks++;echo 'PASS '.$label.PHP_EOL;}
foreach([null,'','   '] as $v)tracking_ok(SN_Payment_Tracking::normalize($v)==='','optional blank accepted');
foreach(['۰۰۱٢۳'=>'00123','ab_01/x-2.3'=>'AB_01/X-2.3','0'=>'0','00123456789012345678901234567890'=>'00123456789012345678901234567890'] as $raw=>$expected)tracking_ok(SN_Payment_Tracking::normalize((string)$raw)===$expected,'reference normalization preserves identifier semantics');
tracking_ok(SN_Payment_Tracking::normalize(str_repeat('1',64))===str_repeat('1',64),'full length up to 64 retained');
foreach([[],new stdClass(),true,1.5,str_repeat('1',65),'1 OR 1=1','<img src=x>','12%34',"12\n34",'شماره123'] as $bad)tracking_ok(SN_Payment_Tracking::normalize($bad)===null,'malformed reference rejected');
$destination=static fn()=>['valid'=>true,'last4'=>'1234'];$date=static fn()=>['success'=>true,'mysql'=>'2026-11-01 10:00:00','jalali'=>'1405/08/10 13:30'];
foreach(['receipt','info'] as $mode){
 $base=['amount'=>'100','entry_mode'=>$mode,'receipt_count'=>1,'manual_transfer_type'=>'card','card_from'=>'1234','paid_at'=>'1405/08/10 13:30'];
 $result=SN_Payment_Batch::fields($base,true,$destination,$date);tracking_ok(isset($result['fields'])&&array_key_exists('manual_tracking_number',$result['fields'])&&$result['fields']['manual_tracking_number']===null,'reference remains optional in '.$mode);
 $result=SN_Payment_Batch::fields($base+['manual_tracking_number'=>'۰۰۱٢۳۴۵۶۷۸۹۰'],true,$destination,$date);tracking_ok(($result['fields']['manual_tracking_number']??'')==='001234567890','full reference retained for '.$mode);
 $result=SN_Payment_Batch::fields($base+['manual_tracking_number'=>['bad']],true,$destination,$date);tracking_ok(!empty($result['error']),'invalid tracking rejected before uploads in '.$mode);
}
tracking_ok(in_array('manual_tracking_number',SN_Payment_Transactions::proof_fields(),true),'authorized proof projection includes full reference');
if(!class_exists('SQLite3'))throw new RuntimeException('SQLite3 required');
$db=new SQLite3(':memory:');$db->enableExceptions(true);$db->createFunction('JSON_UNQUOTE',static fn($v)=>$v);$db->createFunction('JSON_TYPE',static fn($v)=>is_string($v)?'STRING':(is_null($v)?null:'OTHER'));
$db->exec('CREATE TABLE invoices(id INTEGER PRIMARY KEY,seller_id INTEGER)');$db->exec('CREATE TABLE payments(id INTEGER PRIMARY KEY,invoice_id INTEGER,proof_data TEXT,authority TEXT)');
foreach([[1,7],[2,8],[3,7],[4,7],[5,7],[6,7],[7,7],[8,7],[9,7]] as [$id,$seller])$db->exec('INSERT INTO invoices VALUES('.$id.','.$seller.')');
$records=[[1,'001234567890',''],[1,'AB_001',''],[2,'001234567890',''],[6,'001234567890','synthetic-authority'],[7,'ABX001',''],[8,null,''],[9,'NULL','']];
foreach($records as [$id,$number,$authority]){$s=$db->prepare('INSERT INTO payments(invoice_id,proof_data,authority) VALUES(:id,:proof,:authority)');$s->bindValue(':id',$id);$s->bindValue(':proof',json_encode(['manual_tracking_number'=>$number]));$s->bindValue(':authority',$authority);$s->execute();}
foreach([[3,['manual_tracking_last6'=>'987654']],[4,'broken-json'],[5,['manual_tracking_number'=>'000888','_review'=>['before'=>['manual_tracking_number'=>'000999']]]]] as [$id,$proof]){$s=$db->prepare("INSERT INTO payments(invoice_id,proof_data,authority) VALUES(:id,:proof,'')");$s->bindValue(':id',$id);$s->bindValue(':proof',is_array($proof)?json_encode($proof):$proof);$s->execute();}
$columns=['id','invoice_id','proof_data','authority'];
function tracking_find($db,$query,$columns):array{
 [$sql,$args]=SN_Payment_Tracking::condition('i.id','payments',$query,$columns);
 foreach($args as $arg)$sql=preg_replace('/%s/',"'".SQLite3::escapeString($arg)."'",$sql,1);
 $r=$db->query('SELECT i.id FROM invoices i WHERE i.seller_id=7 AND ('.$sql.') ORDER BY i.id');$out=[];while($row=$r->fetchArray(SQLITE3_ASSOC))$out[]=(int)$row['id'];return $out;
}
tracking_ok(tracking_find($db,'001234567890',$columns)===[1],'search finds full reference only inside seller scope and manual rows');
tracking_ok(tracking_find($db,'۰۰۱٢۳۴',$columns)===[1],'localized partial search matches stored reference');
tracking_ok(tracking_find($db,'AB_001',$columns)===[1],'underscore is literal rather than SQL wildcard');
tracking_ok(tracking_find($db,'987654',$columns)===[3],'legacy six-digit proof remains searchable');
tracking_ok(tracking_find($db,'000999',$columns)===[],'superseded review history is not current reference');
tracking_ok(tracking_find($db,'000888',$columns)===[5],'current corrected reference is searchable');
tracking_ok(tracking_find($db,'NULL',$columns)===[9],'blank JSON null cannot impersonate a literal tracking identifier');
tracking_ok(tracking_find($db,'anything',[])===[],'schema without proof_data preserves old search');
foreach(['','<img>','1 OR 1=1','%'] as $bad)tracking_ok(tracking_find($db,$bad,$columns)===[],'invalid tracking search never broadens query');
foreach([['i.id;DROP','payments'],['i.id','payments;DROP']] as [$id,$table]){$blocked=false;try{SN_Payment_Tracking::condition($id,$table,'123',$columns);}catch(InvalidArgumentException $e){$blocked=true;}tracking_ok($blocked,'SQL identifiers are allowlisted');}
echo 'TRACKING_TOTAL '.$checks.PHP_EOL;
