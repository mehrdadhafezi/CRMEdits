<?php
/** Memory-only permission/scope/input regressions; no WordPress bootstrap or DB. */
define('ABSPATH', __DIR__);
class WP_Error {
    private string $message; private array $data;
    public function __construct($code, $message, $data = []) { $this->message=$message; $this->data=$data; }
    public function get_error_message() { return $this->message; }
    public function get_error_data() { return $this->data; }
}
function is_wp_error($value) { return $value instanceof WP_Error; }
function add_action(...$args) {}
function is_user_logged_in() { return $GLOBALS['logged']; }
function check_ajax_referer(...$args) { return $GLOBALS['nonce_valid']; }
function wp_unslash($value) { return $value; }
function nocache_headers() {}
class Reissue_Response extends RuntimeException {
    public array $body; public int $status;
    public function __construct($body, $status) { $this->body=$body; $this->status=$status; }
}
function wp_send_json_error($body, $status) { throw new Reissue_Response($body, $status); }
function wp_send_json_success($body) { throw new Reissue_Response($body, 200); }
require dirname(__DIR__, 2).'/includes/class-sn-hr-invoice-reissue.php';
$checks=0;
function ok($condition, string $label): void { global $checks; if (!$condition) { throw new RuntimeException('FAIL '.$label); } $checks++; echo 'PASS '.$label.PHP_EOL; }
function call_private($method, ...$args) { $m=new ReflectionMethod(SN_HR_Invoice_Reissue::class,$method);$m->setAccessible(true);return $m->invoke(null,...$args); }
$nodes=[];
foreach ([11=>'sales_manager',12=>'senior_supervisor',13=>'supervisor',14=>'seller',21=>'sales_manager',22=>'senior_supervisor',23=>'supervisor',24=>'seller'] as $id=>$position) {
    $nodes[$id]=['position'=>$position,'active'=>true,'profile_id'=>$id+100,'label'=>'synthetic-'.$id];
}
$parents=[12=>[11],13=>[12],14=>[13],22=>[21],23=>[22],24=>[23]];
$cases=[
    [[],false,0,'all unset denies'], [[11=>'allow'],true,11,'manager permission reaches seller'],
    [[11=>'deny'],false,11,'manager denial reaches seller'], [[11=>'allow',12=>'deny'],false,12,'senior overrides manager'],
    [[11=>'deny',12=>'allow'],true,12,'senior exception permits'], [[11=>'allow',13=>'deny'],false,13,'supervisor exception denies'],
    [[11=>'deny',13=>'allow'],true,13,'supervisor exception permits'], [[11=>'allow',14=>'deny'],false,14,'seller exception denies'],
    [[11=>'deny',14=>'allow'],true,14,'seller exception permits'], [[11=>'allow',12=>'inherit',13=>'inherit',14=>'inherit'],true,11,'explicit inherit does not shadow manager'],
    [[11=>'allow',12=>'deny',13=>'allow',14=>'deny'],false,14,'nearest of all four explicit settings wins'],
    [[21=>'allow'],false,0,'another manager cannot grant permission'], [[14=>'unexpected',11=>'allow'],false,14,'malformed local setting fails closed'],
    [[14=>['allow'],11=>'allow'],false,14,'array setting cannot grant permission'],
];
foreach($cases as [$modes,$allowed,$source,$label]) { $d=SN_HR_Invoice_Reissue::evaluate(14,$nodes,$parents,$modes);ok($d['valid']&&$d['allowed']===$allowed&&$d['source_id']===$source,$label); }
$direct=$parents;$direct[14]=[11];ok(SN_HR_Invoice_Reissue::evaluate(14,$nodes,$direct,[11=>'allow'])['allowed'],'skipped intermediate positions remain supported');
$moved=$parents;$moved[13]=[22];ok(!SN_HR_Invoice_Reissue::evaluate(14,$nodes,$moved,[11=>'allow'])['allowed'],'transfer uses the new manager immediately');
ok(SN_HR_Invoice_Reissue::evaluate(14,$nodes,$moved,[14=>'allow'])['allowed'],'explicit personal exception survives transfer');
$broken=$parents;$broken[14]=[];ok(!SN_HR_Invoice_Reissue::evaluate(14,$nodes,$broken,[14=>'allow'])['allowed'],'orphan does not grant even with personal allow');
$broken=$parents;$broken[14]=[13,23];ok(!SN_HR_Invoice_Reissue::evaluate(14,$nodes,$broken,[11=>'allow'])['allowed'],'ambiguous current parents fail closed');
$broken=$parents;$broken[13]=[14];ok(!SN_HR_Invoice_Reissue::evaluate(14,$nodes,$broken,[14=>'allow'])['allowed'],'cycle fails closed');
$broken=$parents;$broken[14]=[999];ok(!SN_HR_Invoice_Reissue::evaluate(14,$nodes,$broken,[14=>'allow'])['allowed'],'missing parent fails closed');
$inactive=$nodes;$inactive[11]['active']=false;ok(!SN_HR_Invoice_Reissue::evaluate(14,$inactive,$parents,[14=>'allow'])['allowed'],'inactive manager cannot authorize a chain');
$inactive=$nodes;$inactive[14]['active']=false;ok(!SN_HR_Invoice_Reissue::evaluate(14,$inactive,$parents,[11=>'allow'])['allowed'],'inactive seller cannot inherit');
$unknown=$nodes;$unknown[13]['position']='subscriber';ok(!SN_HR_Invoice_Reissue::evaluate(14,$unknown,$parents,[14=>'allow'])['allowed'],'unrecognized position cannot grant permission');
$above=$parents;$above[11]=[21];ok(!SN_HR_Invoice_Reissue::evaluate(14,$nodes,$above,[21=>'allow'])['allowed'],'inheritance stops at the selected sales manager');
$graph=['nodes'=>$nodes,'parents'=>$parents,'error'=>false];$selection=['manager'=>11,'senior'=>[12],'supervisor'=>[13],'seller'=>[14]];
ok(!is_wp_error(call_private('context',$graph,$selection)),'valid cascading selection is accepted');
foreach(['senior'=>[22],'supervisor'=>[23],'seller'=>[24]] as $key=>$ids) { $bad=$selection;$bad[$key]=$ids;ok(is_wp_error(call_private('context',$graph,$bad)),'cross-manager '.$key.' rejected'); }
$bad=$selection;$bad['supervisor']=[14];ok(is_wp_error(call_private('context',$graph,$bad)),'forged position in selector rejected');
$bad=$selection;$bad['manager']=0;ok(is_wp_error(call_private('context',$graph,$bad)),'child selection without manager rejected');
foreach(['1 OR 1=1',[],true,'-1','1.5','1e2','2147483648'] as $value) { ok(is_wp_error(call_private('selection',['manager_id'=>$value])),'invalid manager identifier rejected'); }
ok(is_wp_error(call_private('selection',['manager_id'=>'11','seller_ids'=>'14'])),'scalar child list rejected');
ok(is_wp_error(call_private('selection',['manager_id'=>'11','seller_ids'=>array_fill(0,101,'14')])),'oversized selection rejected');
ok(!is_wp_error(call_private('selection',['manager_id'=>'11','senior_ids'=>['12'],'supervisor_ids'=>['13'],'seller_ids'=>['14']])),'strict decimal input accepted');
SN_HR_Invoice_Reissue::register(static fn(): bool => $GLOBALS['authorized']);
foreach([[false,false,false,[],401,'guest denied'],[true,false,true,[],403,'non-HR actor denied'],[true,true,false,[],403,'nonce failure denied'],[true,true,true,['manager_id'=>['11']],400,'array payload denied before storage'],[true,true,true,['manager_id'=>'11','operation'=>['save']],400,'array operation denied before storage']] as [$logged,$authorized,$nonce,$post,$status,$label]) {
    $GLOBALS['logged']=$logged;$GLOBALS['authorized']=$authorized;$GLOBALS['nonce_valid']=$nonce;$_POST=array_merge(['nonce'=>'synthetic-test'], $post);
    try { SN_HR_Invoice_Reissue::ajax(); throw new RuntimeException('No response'); }
    catch(Reissue_Response $response) { ok($response->status===$status,$label); }
}
echo 'TOTAL '.$checks.PHP_EOL;
