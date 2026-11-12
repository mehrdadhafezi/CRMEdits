<?php
// Read-only CLI regression; never uploads a file or mutates financial data.
if(PHP_SAPI!=='cli')exit(2);
$bootstrap=getenv('SN_PAYMENT_TEST_BOOTSTRAP');if(!$bootstrap||!is_file($bootstrap))throw new RuntimeException('WordPress bootstrap required');require_once $bootstrap;if(function_exists('sn_bootstrap_load_core'))sn_bootstrap_load_core();require_once dirname(__DIR__,2).'/includes/class-sn-payment-batch.php';
$checks=0;function card195_ok($value,$name){global $checks;if(!$value)throw new RuntimeException('FAIL '.$name);$checks++;echo 'PASS '.$name.PHP_EOL;}
$callback=static function(){throw new RuntimeException('Receipt must not use info callbacks');};$base=['amount'=>'100000','entry_mode'=>'receipt','receipt_count'=>1];
$r=SN_Payment_Batch::fields($base+['card_from'=>'۰۰۱۲','card_to'=>'٠٠٣٤'],true,$callback,$callback);card195_ok(($r['fields']['manual_card_from']??'')==='0012'&&($r['fields']['manual_card_to']??'')==='0034','normalize digits and preserve leading zeros');
$r=SN_Payment_Batch::fields($base,true,$callback,$callback);card195_ok(array_key_exists('manual_card_from',$r['fields'])&&$r['fields']['manual_card_from']===null&&$r['fields']['manual_card_to']===null,'optional cards remain null');
foreach(['123','12345','12a4','1234-','1111222233334444',[]]as $bad){$r=SN_Payment_Batch::fields($base+['card_from'=>$bad],true,$callback,$callback);card195_ok(!empty($r['error']),'reject malformed source');$r=SN_Payment_Batch::fields($base+['card_to'=>$bad],true,$callback,$callback);card195_ok(!empty($r['error']),'reject malformed destination');}
$r=SN_Payment_Batch::fields(['amount'=>'100000','entry_mode'=>'info','manual_transfer_type'=>'card','card_from'=>''],true,$callback,$callback);card195_ok(!empty($r['error']),'info card source still required');echo 'TOTAL '.$checks.PHP_EOL;
