<?php
/** Test-only provider for the guarded native HTTP suite; never packaged. */
if(defined('SN_APPROVAL_TEST_SANDBOX')&&SN_APPROVAL_TEST_SANDBOX===true){
    if(!preg_match('/^sn_approval_[a-z0-9_]+_test$/D',DB_NAME)||DB_HOST!=='localhost'||wp_get_environment_type()!=='local')throw new RuntimeException('Synthetic HTTP guard');
    $gateway240_root=realpath((string)getenv('SN_APPROVAL_ROOT'));
    if(!$gateway240_root||!str_starts_with($gateway240_root,'/tmp/sn-approval-'))throw new RuntimeException('Generated native root required');
}elseif(defined('SN_GATEWAY240_PRIVATE_TEST')&&SN_GATEWAY240_PRIVATE_TEST===true){
    $gateway240_root=dirname(rtrim(ABSPATH,'/'));
    if(posix_geteuid()!==1073||DB_NAME!=='crmstg1_restore237'||DB_USER!=='crmstg1_rst237'||!preg_match('#^/home/crmstg1/private/hr239-[a-z0-9_]{8}$#D',$gateway240_root))throw new RuntimeException('Private HTTP guard');
}else throw new RuntimeException('Synthetic HTTP only');
add_filter('pre_http_request',static function($pre,$args,$url)use($gateway240_root){
    if(!in_array($url,['https://gateway.zibal.ir/v1/inquiry','https://gateway.zibal.ir/v1/verify'],true))return $pre;
    $file=$gateway240_root.'/gateway240-fixture.json';if(!is_file($file)||is_link($file))return new WP_Error('synthetic_missing','Synthetic fixture missing');
    $f=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);$body=json_decode((string)($args['body']??''),true);
    if(!is_array($body)||($body['merchant']??null)!=='zibal')return new WP_Error('synthetic_merchant','Test merchant only');
    $key=array_search((string)($body['trackId']??''),$f['tracks'],true);
    if(!in_array($key,['browser','mobile'],true))return new WP_Error('synthetic_track','Only reviewed browser tracks permitted');
    return ['headers'=>[],'response'=>['code'=>200],'body'=>wp_json_encode(['result'=>100,'status'=>str_ends_with($url,'/verify')?1:-1,'amount'=>19800000,'orderId'=>$f['batch'].'-'.$key,'refNumber'=>'SYN-HTTP-'.$key]),'cookies'=>[]];
},900,3);
