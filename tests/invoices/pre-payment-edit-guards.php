<?php
/** Synthetic guard regression; no WordPress, external database or provider. */
define('ABSPATH', '/synthetic-prepayment/');
$root = getenv('SN_PREPAYMENT_SOURCE') ?: dirname(__DIR__, 2);
require_once $root.'/includes/class-sn-plugin.php';
require_once $root.'/includes/class-sn-sales-catalog.php';
class SN_Subscription_Plus {
    public static function instance() { return new self(); }
    public function handles_invoice($id) { return !empty($GLOBALS['is_plus']); }
}
class SN_Support_Console { public static function unlocked() { return true; } }
class SN_Operations_Flow {
    public static function instance() { return new self(); }
    public function is_upgrade_invoice($id) { return false; }
}
class PrepaymentGuardDB {
    public string $prefix='synthetic_';
    public function prepare($sql,...$args) { return preg_replace_callback('/%[sd]/',static function() use (&$args) { return (string)array_shift($args); },$sql); }
    public function get_var($sql) {
        if (str_starts_with($sql,'SHOW TABLES')) { return str_contains($sql,'sn_payments') ? $this->prefix.'sn_payments' : null; }
        return !empty($GLOBALS['active_request']) ? 1 : null;
    }
}
$wpdb=new PrepaymentGuardDB();
$plugin=(new ReflectionClass(SN_Plugin::class))->newInstanceWithoutConstructor();
$guard=new ReflectionMethod($plugin,'sn_invoice_pre_payment_edit_block');$guard->setAccessible(true);
$base=['id'=>41,'status'=>'pre_invoice','invoice_status'=>'pre_invoice','payment_status'=>'pending_payment','payment_purpose'=>'product_sale','payment_workflow_status'=>'awaiting_payment','paid_total_amount'=>0,'current_payment_stage'=>1];
$stage=(object)['stage_no'=>1,'status'=>'pending'];$checks=0;
function check($value,$label) { global $checks;if(!$value)throw new RuntimeException('FAIL '.$label);++$checks;echo 'PASS '.$label.PHP_EOL; }
foreach([
    ['catalog_discounts_snapshot'=>'[{"line_index":0,"option_id":"20000","discount_total":200}]','discount_total'=>200],
    ['discount_amount'=>200], ['coupon_code'=>'SYNTHETIC'], ['coupon_discount_amount'=>200], ['wheel_reward_summary'=>'Synthetic reward'],
] as $discount) {
    $invoice=(object)array_replace($base,$discount);
    check($guard->invoke($plugin,$invoice,$stage,true)==='','discounted first-stage invoice can enter the price-aware editor');
    check($guard->invoke($plugin,$invoice,$stage)!=='','legacy/simple support repricing remains blocked for adjusted prices');
    check(!$plugin->support_invoice_edit_allowed($invoice,$stage),'support console cannot silently discard discounts');
}
foreach(['paid','approved','partial_paid','payment_archived','cancelled'] as $status) {
    check($guard->invoke($plugin,(object)array_replace($base,['status'=>$status]),$stage,true)!=='','financial finality remains locked '.$status);
}
foreach(['paid_total_amount'=>10,'paid_at'=>'2026-11-01','approved_at'=>'2026-11-01','receipt_url'=>'synthetic','manual_amount'=>10,'wc_order_id'=>51,'financial_reviewed_at'=>'2026-11-01','current_payment_stage'=>2] as $key=>$value) {
    check($guard->invoke($plugin,(object)array_replace($base,[$key=>$value]),$stage,true)!=='','existing payment/fulfillment marker stays locked '.$key);
}
foreach([['invoice_status'=>'approved'],['payment_status'=>'paid'],['receipt_urls'=>'["synthetic-receipt"]'],['status'=>'rejected','payment_total_amount'=>800,'remaining_amount'=>700]] as $legacy){
    check($guard->invoke($plugin,(object)array_replace($base,$legacy),$stage,true)!=='','legacy financial evidence blocks editing despite stale general status');
}
$GLOBALS['active_request']=true;
check($guard->invoke($plugin,(object)$base,$stage,true)!=='','issued provider/payment request still blocks editing');
$GLOBALS['active_request']=false;$GLOBALS['is_plus']=true;
check($guard->invoke($plugin,(object)$base,$stage,true)!=='','Subscription Plus stays on its specialized editor');
echo 'TOTAL '.$checks.PHP_EOL;
