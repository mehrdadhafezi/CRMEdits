<?php
/** Presentation-only memory regressions, no WordPress/database/provider. */
define('ABSPATH','/synthetic/');
require dirname(__DIR__,2).'/includes/class-sn-payment-transactions.php';
function esc_html($value){return htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
class SN_Helpers {public static function format_price($value){return number_format($value,0,'.',',').' تومان';}}
$checks=0;
function case_ok($yes,$label){global $checks;if(!$yes)throw new RuntimeException('FAIL '.$label);++$checks;echo 'PASS '.$label.PHP_EOL;}
$history=['available'=>true,'stages'=>[]];
foreach([[1,400,'paid'],[2,300,'approved'],[3,300,'pending_financial_approval']] as [$no,$amount,$status])$history['stages'][]=['stage_no'=>$no,'amount'=>$amount,'status'=>$status,'stage_invoice_code'=>'SYN-247/'.$no,'stage_reference'=>'STG-'.$no];
foreach([0,'0',0.5,-1] as $remaining)case_ok(SN_Payment_Transactions::stages_settled(['remaining'=>$remaining]),'known zero/overpaid balance is collapsed');
foreach([null,'',false,true,'NaN','Infinity',NAN,INF,0.51,300] as $remaining)case_ok(!SN_Payment_Transactions::stages_settled(['remaining'=>$remaining]),'unknown/invalid/positive balance never implies settlement');
$copy=$history;$unpaid=SN_Payment_Transactions::render_stage_rows($history,['remaining'=>300],20);
case_ok(substr_count($unpaid,'data-sn-stage-row="true"')===3,'unsettled purchase has three separate stage rows');
case_ok(str_contains($unpaid,'SYN-247/1')&&str_contains($unpaid,'SYN-247/2')&&str_contains($unpaid,'SYN-247/3'),'every issued stage alias remains visible');
case_ok(!str_contains($unpaid,'<details'),'unsettled rows are visible without expanding');
case_ok(str_contains($unpaid,'400 تومان')&&str_contains($unpaid,'300 تومان')&&!str_contains($unpaid,'1,000 تومان'),'stage rows use stage amount, not repeated purchase total');
case_ok(str_contains($unpaid,'پرداخت مرحله تأیید شده')&&str_contains($unpaid,'در انتظار تأیید مالی'),'paid stage does not imply full settlement');
case_ok(!preg_match('/<(input|button|select|form)\b/',$unpaid),'stage rows carry no duplicate selectors or financial actions');
case_ok(!str_contains($unpaid,'data-id=')&&!str_contains($unpaid,'data-sn-invoice-main='),'stage rows cannot join invoice bulk selection or parent count');
case_ok(SN_Payment_Transactions::render_stage_history($history,['remaining'=>300])==='','unsettled purchase does not duplicate stages in another details view');
$paid=SN_Payment_Transactions::render_stage_history($history,['remaining'=>0]);
case_ok(substr_count($paid,'<details')===1&&substr_count($paid,'SYN-247/')===3,'settled purchase has one expandable stage history');
case_ok(SN_Payment_Transactions::render_stage_rows($history,['remaining'=>0],20)==='','settled purchase adds no separate stage rows');
case_ok($copy===$history,'presentation leaves history and money unchanged');
foreach([0,31,-1] as $columns)case_ok(SN_Payment_Transactions::render_stage_rows($history,['remaining'=>300],$columns)==='','invalid column span rejected');
$failed=['available'=>false,'stages'=>[]];case_ok(str_contains(SN_Payment_Transactions::render_stage_history($failed,[]),'دریافت مراحل کامل نشد')&&SN_Payment_Transactions::render_stage_rows($failed,[],20)==='','failed history is explicit, never invented');
case_ok(SN_Payment_Transactions::render_stage_history(['available'=>true,'stages'=>[]],[])===''&&SN_Payment_Transactions::render_stage_rows(['available'=>true,'stages'=>[]],[],20)==='','no issued stage produces no extra rows');
$attack=$history;$attack['stages'][0]['stage_invoice_code']='<img src=x onerror=alert(1)>';$attack['stages'][0]['stage_reference']='<script>bad()</script>';
foreach([SN_Payment_Transactions::render_stage_rows($attack,['remaining'=>300],20),SN_Payment_Transactions::render_stage_history($attack,['remaining'=>0])] as $html)case_ok(!str_contains($html,'<img')&&!str_contains($html,'<script>')&&str_contains($html,'&lt;img'),'legacy display codes and references are escaped');
echo 'STAGE_CASE_TOTAL '.$checks.PHP_EOL;
