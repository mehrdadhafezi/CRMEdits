<?php
require __DIR__.'/bootstrap.php';
add_filter('query',static fn($sql)=>preg_replace('/(?<![A-Za-z0-9_])wp_sn_/', $GLOBALS['wpdb']->prefix.'sn_', $sql));
require_once dirname(__DIR__,2).'/includes/class-sn-hr-service.php';
require_once dirname(__DIR__,2).'/includes/class-sn-hierarchy-service.php';
require_once dirname(__DIR__,2).'/includes/class-sn-scope-service.php';
function check_mis($condition,$name){if(!$condition){throw new RuntimeException('FAIL '.$name);}echo 'PASS '.$name.PHP_EOL;}
$plugin=new SN_Plugin();
$method=new ReflectionMethod($plugin,'sn_parse_csv_content');$method->setAccessible(true);
$parsed=$method->invoke($plugin,"کد,شماره تماس,نام,نام خانوادگی,تاریخ و ساعت\n0001,۰۹۱۲۳۴۵۶۷۸۹,علی,آزمایشی,1405/08/01 10:00\n");
$mapper=new ReflectionMethod($plugin,'sn_mis_v2_import_records');$mapper->setAccessible(true);$records=$mapper->invoke($plugin,$parsed,[]);
check_mis($records[0]['source_code']==='0001' && $records[0]['customer_name']==='علی آزمایشی' && $records[0]['source_datetime_raw']==='1405/08/01 10:00' && $records[0]['normalized_phone']==='09123456789','CSV mapping preserves source code names datetime and Persian phone');
$parsed_old=$method->invoke($plugin,"شماره اول,شماره دوم,نام مشتری,شهر\n09123456789,,آزمایشی,تهران\n");$old=$mapper->invoke($plugin,$parsed_old,[]);check_mis($old[0]['customer_name']==='آزمایشی' && $old[0]['normalized_phone']==='09123456789','legacy CSV template remains readable');
$uid=username_exists('mis_fixture') ?: wp_create_user('mis_fixture','synthetic-only','mis@example.invalid');$user=get_user_by('id',$uid);$user->set_role('sn_mis');
$wpdb->query("INSERT IGNORE INTO wp_sn_hr_positions (slug,label) VALUES ('mis','MIS'),('sales_manager','مدیر فروش')");$pos=(int)$wpdb->get_var("SELECT id FROM wp_sn_hr_positions WHERE slug='mis'");
$wpdb->query($wpdb->prepare('INSERT INTO wp_sn_hr_profiles (user_id,position_id,is_active) VALUES (%d,%d,0) ON DUPLICATE KEY UPDATE position_id=VALUES(position_id),is_active=0',$uid,$pos));
wp_set_current_user($uid);$access=new ReflectionMethod($plugin,'sn_can_access_mis_panel');$access->setAccessible(true);check_mis($access->invoke($plugin)===false,'inactive profile with legacy MIS role is denied');
$wpdb->update('wp_sn_hr_profiles',['is_active'=>1],['user_id'=>$uid]);$plugin=new SN_Plugin();check_mis($access->invoke($plugin)===true,'active MIS profile permitted');
update_user_meta($uid,'sn_is_active','0');check_mis($access->invoke($plugin)===false,'inactive user meta overrides active profile');delete_user_meta($uid,'sn_is_active');
wp_set_current_user(1);update_option('sn_mis_branches_v2',['branch_a'=>'شعبه یک','branch_b'=>'شعبه دو']);
$page_id=wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>'MIS Fixture','post_content'=>'[sn_mis_panel]']);$GLOBALS['post']=get_post($page_id);
foreach(['dashboard','upload','return','reports'] as $page) {
    $_GET=['sn_mis_page'=>$page]; $_SERVER['REQUEST_URI']='/?page_id='.$page_id.'&sn_mis_page='.$page;
    $html=$plugin->render_mis_workspace();check_mis(str_contains($html,'sn-mis-v2') && !str_contains($html,'is-error'),'render '.$page.' without runtime error');
    $html=preg_replace('~<link rel="stylesheet"[^>]*>~','<style>'.file_get_contents(dirname(__DIR__,2).'/assets/css/mis-portal.css').'</style>',$html);
    $html=preg_replace('~<script src="[^"]*mis-portal.js[^>]*></script>~','',$html);
    sn_mis_test_write('ui-'.$page.'.html','<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>body{font-family:DejaVu Sans,sans-serif;background:#f8faf9;margin:16px}a{color:#087f76}h2,h3{line-height:1.8}button,input,select,textarea{font-family:inherit}</style>'.$html.'</html>');
}
$writer=new SN_MIS_XLSX(['کد','شماره','یادداشت']);for($i=0;$i<1505;$i++){$writer->add(['000'.$i,'09123456789',$i===0?'=HYPERLINK("https://example.invalid")':'علی']);}$path=$writer->finish();sn_mis_test_write('test-export.xlsx',file_get_contents($path));unlink($path);check_mis(true,'streamed XLSX written');
$mis=new SN_MIS_Service($wpdb,1);$sample=$mis->query([],1)['rows'][0];$row=(object)$sample;$row->source_type='mis_data_row';$row->source_id=(int)$sample['id'];$row->source_batch_id=(int)$sample['batch_id'];unset($row->current_owner_user_id);
$guard=new ReflectionMethod($plugin,'sn_mis_duplicate_hard_guard_bulk');$guard->setAccessible(true);$result=$guard->invoke($plugin,[$row]);check_mis(!empty($result[$row->id]['duplicate']),'source row ids do not accidentally exclude a distribution item');
echo 'DONE'.PHP_EOL;
