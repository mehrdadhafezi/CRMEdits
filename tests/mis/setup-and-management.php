<?php
require __DIR__.'/bootstrap.php';
function rc3_ok($condition,$label){if(!$condition)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label.PHP_EOL;}
$p=$wpdb->prefix;$original=[];foreach(['mis_data_rows','mis_import_batches','mis_events','mis_claims','distribution_items','distribution_item_logs','mis_distribution_plans','mis_distribution_plan_items','leads','invoices'] as $table)$original[$table]=$wpdb->get_results('SELECT * FROM '.$p.'sn_'.$table.' ORDER BY '.($table==='mis_claims'?'row_id':'id'),ARRAY_A);
foreach(['mis_row_index','mis_index_dirty','mis_jobs','mis_job_rows','mis_import_runs'] as $table)$wpdb->query('DROP TABLE IF EXISTS '.$p.'sn_'.$table);
delete_option('sn_mis_setup_v4');$setup=new SN_MIS_Setup($wpdb);$calls=0;$ddl=[];
do{
    $start=$wpdb->num_queries;$capture=function($sql)use(&$ddl){if(preg_match('/^(?:ALTER|CREATE|UPDATE|DELETE|DROP)\s/i',$sql))$ddl[]=$sql;return $sql;};add_filter('query',$capture);$before=count($ddl);$state=$setup->step();remove_filter('query',$capture);$created=0;foreach(array_slice($ddl,$before) as $q)if(str_starts_with($q,'CREATE TABLE'))$created++;
    rc3_ok($created<=1,'setup request creates at most one additive table, step '.(++$calls));if($calls>8)throw new RuntimeException('setup stalled');
}while(!$state['ready']);
$alter=false;foreach($ddl as $q)if(str_starts_with($q,'ALTER TABLE'))$alter=true;rc3_ok(!$alter && $state['completed']===8,'web setup never alters operational tables or creates source indexes');
$state2=$setup->step();rc3_ok($state2['ready'] && $state2['completed']===8,'interrupted and repeated web setup is idempotent');
$unchanged=true;foreach($original as $table=>$rows)$unchanged=$unchanged && $rows===$wpdb->get_results('SELECT * FROM '.$p.'sn_'.$table.' ORDER BY '.($table==='mis_claims'?'row_id':'id'),ARRAY_A);rc3_ok($unchanged,'web setup preserves every old source row event claim item lead and invoice byte');
$plugin=new SN_Plugin();$page_id=wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>'MIS RC3','post_content'=>'[sn_mis_panel]']);$GLOBALS['post']=get_post($page_id);
$pages=['dashboard','upload','return','reports','tools','cases','plans','rules','branches','health'];
foreach($pages as $page){
    $_GET=['sn_mis_page'=>$page];$queries=[];$trace=function($q)use(&$queries){$queries[]=$q;return $q;};add_filter('query',$trace);$html=$plugin->render_mis_workspace();remove_filter('query',$trace);
    $scan=false;foreach($queries as $q)if(preg_match('/FROM\s+`?'.preg_quote($p,'/').'sn_(?:invoices|leads|distribution_item_logs)\b/i',$q))$scan=true;
    rc3_ok(str_contains($html,'data-view-api') && !str_contains($html,'is-error') && !$scan,'new '.$page.' renders without invoice/lead/log evidence scans');
    $dom=new DOMDocument();libxml_use_internal_errors(true);$dom->loadHTML('<?xml encoding="utf-8"?>'.$html);$xpath=new DOMXPath($dom);rc3_ok($xpath->query('//main')->length===1 && $xpath->query('//aside')->length===1,'new '.$page.' keeps one portal shell');
    $html=preg_replace('~<link rel="stylesheet"[^>]*>~','<style>'.file_get_contents(dirname(__DIR__,2).'/assets/css/mis-portal.css').'</style>',$html);$html=preg_replace('~<script src="[^"]*mis-portal.js[^>]*></script>~','',$html);
    sn_mis_test_write('ui-'.$page.'.html','<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>body{font-family:DejaVu Sans,sans-serif;background:#eef2f6;margin:20px}</style>'.$html.'</html>');
}
$_GET=['sn_mis_advanced'=>1];$html=$plugin->render_mis_workspace();rc3_ok(str_contains($html,'sn-mis-subnav') && !str_contains($html,'الان باید چه کار کنم؟') && !str_contains($html,'sn_mis_advanced=1'),'old advanced URL resolves to redesigned management without rendering the old panel');
$_GET=['sn_mis_page'=>'tools','section'=>'display'];$html=$plugin->render_mis_workspace();rc3_ok(str_contains($html,'تنظیمات نمایش شخصی') && !str_contains($html,'data-job-api'),'display preferences are separate from the processing screen');
// A regular MIS operator cannot gain access to administrator screens by changing the URL.
$uid=username_exists('mis_fixture');wp_set_current_user($uid);$plugin=new SN_Plugin();$_GET=['sn_mis_page'=>'plans'];$html=$plugin->render_mis_workspace();rc3_ok(!str_contains($html,'create_distribution_plan') && str_contains($html,'این بخش برای مدیرکل است.'),'administrator workflows remain unavailable to normal MIS users');wp_set_current_user(1);$_GET=[];
// The chosen case must survive both focused workflow tabs.
$case=(int)$wpdb->get_var('SELECT MAX(id) FROM '.$p.'sn_mis_import_batches');
foreach(['import'=>'upload','assign'=>'preview_assign'] as $section=>$operation){$_GET=['sn_mis_page'=>'upload','section'=>$section,'batch_id'=>$case];$html=(new SN_Plugin())->render_mis_workspace();$dom=new DOMDocument();$dom->loadHTML('<?xml encoding="utf-8"?>'.$html);$xp=new DOMXPath($dom);$selected=$xp->query('//form[.//input[@name="operation" and @value="'.$operation.'"]]//select[@name="batch_id"]/option[@selected]');$correct=$selected->length===1;foreach($selected as $option)$correct=$correct && (int)$option->getAttribute('value')===$case;rc3_ok($correct,'focused '.$section.' workflow retains the selected case');}$_GET=[];
