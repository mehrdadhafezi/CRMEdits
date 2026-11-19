<?php
require __DIR__.'/native-support.php';hr239_bootstrap();
global $wpdb;
$file=$GLOBALS['hr239_root'].'/hr239-fixture.json';
$existing=file_exists($file)?hr239_data():[];
$admin=(int)$wpdb->get_var("SELECT ID FROM {$wpdb->users} ORDER BY ID LIMIT 1");wp_set_current_user($admin);
$plugin=sn_bootstrap_plugin_instance();
$method=new ReflectionMethod(SN_Plugin::class,'sn_ensure_hr_profile_schema_runtime');$method->setAccessible(true);$method->invoke($plugin);
$f=$existing?:['users'=>[],'synthetic'=>true];$profiles=$f['profiles']??[];
$roles=['manager_a'=>'sn_sales_manager','senior_a'=>'sn_senior_supervisor','supervisor_a'=>'sn_supervisor','seller_a'=>'sn_seller',
    'manager_b'=>'sn_sales_manager','senior_b'=>'sn_senior_supervisor','supervisor_b'=>'sn_supervisor','seller_b'=>'sn_seller',
    'senior_c'=>'sn_senior_supervisor','supervisor_c'=>'sn_supervisor','seller_c'=>'sn_seller',
    'hr'=>'sn_hr','subscriber'=>'subscriber','revoked'=>'sn_hr'];
foreach($roles as $key=>$role){
    if(isset($f['users'][$key])){continue;}
    $password=bin2hex(random_bytes(20));$id=wp_insert_user(['user_login'=>'hr239_'.$key,'user_pass'=>$password,'user_email'=>'hr239-'.$key.'@example.invalid','display_name'=>'آزمایشی '.$key,'role'=>$role]);
    hr239_ok(!is_wp_error($id),'isolated synthetic HR account '.$key);
    $f['users'][$key]=['id'=>(int)$id,'login'=>'hr239_'.$key,'password'=>$password];
    if(in_array($key,['hr','subscriber','revoked'],true))continue;
    $base=substr($key,0,-2);$position=['manager'=>'sales_manager','senior'=>'senior_supervisor','supervisor'=>'supervisor','seller'=>'seller'][$base];
    $position_id=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}sn_hr_positions WHERE slug=%s",$position));
    hr239_ok($position_id>0,'existing HR position '.$key);
    $row=['user_id'=>$id,'position_id'=>$position_id,'is_active'=>1,'employment_status'=>'active','hr_display_name'=>'آزمایشی '.$key];
    if($key==='seller_a')$row['hr_display_name']='آزمایشی <img src=x onerror=window.__hr239Xss=1>';
    hr239_ok($wpdb->insert($wpdb->prefix.'sn_hr_profiles',$row)!==false,'synthetic profile '.$key);
    $profiles[$key]=(int)$wpdb->insert_id;
}
$hierarchy=new SN_Hierarchy_Service();
$edges=[];foreach(['a','b','c'] as $team){foreach([['seller','supervisor'],['supervisor','senior'],['senior','manager']] as [$child,$parent]){$edges[]=[$child.'_'.$team,$parent==='manager'&&$team==='c'?'manager_a':$parent.'_'.$team];}}
foreach($edges as [$child,$parent]){
    $exists=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}sn_hr_assignments WHERE child_profile_id=%d AND parent_profile_id=%d AND is_current=1 AND relationship_type='reports_to'",$profiles[$child],$profiles[$parent]));
    if(!$exists){hr239_ok(!empty($hierarchy->assign_parent($profiles[$child],$profiles[$parent],$admin,'synthetic_hr239')['success']),'synthetic HR edge '.$child);}
}
$page=$f['page_id']??wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>'آزمون منابع انسانی ۲۳۹','post_content'=>'[sn_hr_panel]']);
hr239_ok(!is_wp_error($page)&&$page>0,'synthetic HR shortcode page');$f['page_id']=(int)$page;$f['profiles']=$profiles;$f['admin_id']=$admin;
hr239_private('hr239-fixture.json',$f);
echo 'TOTAL '.$GLOBALS['hr239_checks'].PHP_EOL;
