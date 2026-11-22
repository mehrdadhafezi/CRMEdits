<?php
/** Reuse the synthetic catalog fixture; no WordPress/site/provider. */
require dirname(__DIR__).'/products/invoice-product-policy.php';
require dirname(__DIR__,2).'/includes/class-sn-invoice-edit-quote.php';
function wp_salt($scheme) { return 'synthetic-unit-only-signing-secret'; }
$start=$checks;
$GLOBALS['um'][2]['_sn_sales_catalog_policy']=['mode'=>'all','products'=>[
    101=>['discounts'=>['10000','20000'],'allow_custom_discount'=>true],
    102=>['discounts'=>['30000'],'allow_custom_discount'=>false],
]];
$old=[['id'=>61,'product_id'=>101,'product_name'=>'Synthetic','qty'=>2,'unit_price'=>1000,'total_price'=>2000]];
$priced=SN_Sales_Catalog::instance()->price_items($old,['20000'],3,3);
$invoice=(object)['id'=>41,'seller_id'=>3,'original_total'=>2000,'final_total'=>1600,'discount_total'=>400,'discount_amount'=>400,'catalog_discounts_snapshot'=>json_encode($priced['snapshot']),'catalog_manager_user_id'=>2];
ok(SN_Invoice_Edit_Quote::selections($invoice,$old)===['20000'],'existing selected discount is recovered per original line');
$fresh=$old;$fresh[0]['unit_price']=5000;$fresh[0]['total_price']=10000;
$q=SN_Invoice_Edit_Quote::build($invoice,$old,$fresh,['20000'],1600,3);
ok($q['repriced']===false&&$q['pricing']['final_total']===1600.0&&$q['items']===$old,'payment-only edit preserves frozen net/items despite changed product price');
$q=SN_Invoice_Edit_Quote::build($invoice,$old,$fresh,['20000'],1600,3,true);
ok($q['repriced']===true&&$q['pricing']['final_total']===9600,'explicit price refresh uses current server price without a manual total');
$fresh=$old;$fresh[0]['qty']=3;$fresh[0]['total_price']=3000;
$q=SN_Invoice_Edit_Quote::build($invoice,$old,$fresh,['10000'],1600,3);
ok($q['repriced']===true&&$q['pricing']['original_total']===3000&&$q['pricing']['discount_total']===300&&$q['pricing']['final_total']===2700,'changed quantity and authorized discount are recomputed per unit');
$fresh[0]['product_id']=102;$fresh[0]['unit_price']=2000;$fresh[0]['total_price']=6000;
$q=SN_Invoice_Edit_Quote::build($invoice,$old,$fresh,['30000'],1600,3);
ok($q['pricing']['final_total']===5100&&$q['pricing']['snapshot'][0]['product_id']===102,'changed product uses its own authorized discount and fresh server price');
ok(is_wp_error(SN_Invoice_Edit_Quote::build($invoice,$old,$fresh,['10000'],1600,3)),'another product cannot borrow the original product discount');
$fresh=$old;
$q=SN_Invoice_Edit_Quote::build($invoice,$old,$fresh,['custom:12525'],1600,3);
ok($q['pricing']['final_total']===1749.5&&$q['pricing']['discount_total']===250.5,'authorized custom discount retains hundredths');
foreach([null,[],[['20000']],['20000',''],['custom:0'],['custom:-1'],['custom:1e3'],['custom:100000'],['20000 OR 1=1']] as $bad) {
    ok(is_wp_error(SN_Invoice_Edit_Quote::build($invoice,$old,$fresh,$bad,1600,3)),'malformed/forged/excessive discount fails closed '.json_encode($bad));
}
$GLOBALS['um'][2]['_sn_sales_catalog_policy']['products'][101]['allow_custom_discount']=false;
ok(is_wp_error(SN_Invoice_Edit_Quote::build($invoice,$old,$fresh,['custom:12525'],1600,3)),'new revoked custom discount is rejected');
$GLOBALS['um'][2]['_sn_sales_catalog_policy']['products'][101]['discounts']=[];
ok(SN_Invoice_Edit_Quote::build($invoice,$old,$fresh,['20000'],1600,3)['repriced']===false,'unchanged invoice keeps issued discount after grant revocation');
$fresh[0]['qty']=3;
ok(is_wp_error(SN_Invoice_Edit_Quote::build($invoice,$old,$fresh,['20000'],1600,3)),'repricing cannot reuse a revoked discount');
foreach(['coupon_code'=>'SYNTHETIC','wheel_reward_summary'=>'Synthetic reward'] as $field=>$value){
    $legacy=clone $invoice;$legacy->$field=$value;
    ok(SN_Invoice_Edit_Quote::build($legacy,$old,$old,['20000'],1600,3)['pricing']['final_total']===1600.0,'legacy benefit survives payment-only change '.$field);
    ok(is_wp_error(SN_Invoice_Edit_Quote::build($legacy,$old,$fresh,[''],1600,3)),'legacy benefit cannot be silently removed/repriced '.$field);
}
$revision=SN_Invoice_Edit_Quote::revision($invoice,(object)['id'=>71],$old);
$quote=['pricing'=>['final_total'=>1600],'due'=>300,'payment_plan'=>'partial'];$expires=time()+300;
$token=SN_Invoice_Edit_Quote::token(41,3,$revision,$quote,$expires);
foreach([[42,3,$revision,$quote,$expires],[41,4,$revision,$quote,$expires],[41,3,'changed',$quote,$expires],[41,3,$revision,['pricing'=>['final_total'=>1],'due'=>300,'payment_plan'=>'partial'],$expires],[41,3,$revision,$quote,$expires-1]] as $args){
    ok(!hash_equals($token,SN_Invoice_Edit_Quote::token(...$args)),'preview signature binds invoice, actor, revision, prices and expiry');
}
$changed=clone $invoice;$changed->final_total=1500;
ok($revision!==SN_Invoice_Edit_Quote::revision($changed,(object)['id'=>71],$old),'concurrent invoice change invalidates revision');
$other=$old;$other[0]['qty']=3;
ok($revision!==SN_Invoice_Edit_Quote::revision($invoice,(object)['id'=>71],$other),'concurrent item change invalidates revision');
echo 'PREPAYMENT_PRICING_TOTAL '.($checks-$start).PHP_EOL;
