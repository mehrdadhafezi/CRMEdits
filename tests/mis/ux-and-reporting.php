<?php
require __DIR__.'/bootstrap.php';
$checks=[];
function ux_ok(bool $condition,string $label): void { global $checks;if(!$condition)throw new RuntimeException('FAIL '.$label);$checks[]=$label;echo 'PASS '.$label.PHP_EOL; }
function ux_throws(callable $work,string $label): void { $failed=false;try{$work();}catch(RuntimeException $e){$failed=true;}ux_ok($failed,$label); }
wp_set_current_user(1);$plugin=new SN_Plugin();$mis=new SN_MIS_Service($wpdb,1);$p=$wpdb->prefix;
$time=SN_MIS_Feedback::jalali_datetime('۱۴۰۵/۰۸/۱۰ ۱۴:۳۰');
ux_ok($time['display']==='1405/08/10 14:30' && $time['utc']==='2026-11-01 11:00:00','Persian source datetime is Jalali with standard UTC storage');
foreach(['2026-11-01 14:30','46000','1404/12/30 12:00','1405/08/10 25:00','1405/08/10'] as $bad)ux_throws(fn()=>SN_MIS_Feedback::jalali_datetime($bad),'invalid Gregorian serial or incomplete source datetime rejected: '.$bad);
ux_ok(SN_MIS_Feedback::jalali_datetime('')['utc']===null,'legacy template without source datetime remains supported');
$code='UX194-'.bin2hex(random_bytes(4));$wpdb->insert($p.'sn_mis_import_batches',['batch_code'=>$code,'title'=>'UX194 synthetic file','import_status'=>'draft']);$batch=(int)$wpdb->insert_id;
$phone='0996'.str_pad((string)random_int(1000000,8999999),7,'0',STR_PAD_LEFT);$phone2='0996'.str_pad((string)random_int(1000000,8999999),7,'0',STR_PAD_LEFT);
$records=[['customer_phone'=>$phone,'normalized_phone'=>$phone,'customer_name'=>'UX valid','row_status'=>'valid','file_code'=>$code,'source_datetime_raw'=>'۱۴۰۵/۰۸/۱۰ ۱۴:۳۰'],['customer_phone'=>$phone2,'normalized_phone'=>$phone2,'customer_name'=>'UX invalid date','row_status'=>'valid','file_code'=>$code,'source_datetime_raw'=>'2026-11-01 12:00']];
$preview=$mis->import_rows($batch,$records,'ux-valid.csv',false);ux_ok($preview['valid']===1 && $preview['invalid']===1,'preview classifies a bad source date as unavailable invalid data');
$log=new SN_MIS_Import_Log($wpdb,1);$run=$log->start($batch,'ux-valid.csv',128,str_repeat('a',64));$log->progress($run,'preview',$preview);
$result=$mis->import_rows($batch,$records,'ux-valid.csv',true,$run);$count=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.$p.'sn_mis_data_rows WHERE batch_id=%d',$batch));
ux_ok($count===2 && $log->get($run)['status']==='completed','file outcome and imported rows commit together');
$replay=$mis->import_rows($batch,$records,'ux-valid.csv',true,$run);ux_ok(!empty($replay['replayed']) && (int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.$p.'sn_mis_data_rows WHERE batch_id=%d',$batch))===2,'retry of the same committed file run cannot create another import');
$rows=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.$p.'sn_mis_data_rows WHERE batch_id=%d ORDER BY id',$batch),ARRAY_A);$id=(int)$rows[0]['id'];$invalid=(int)$rows[1]['id'];$meta=json_decode($rows[0]['source_meta'],true);
ux_ok($meta['file_code']===$code && $meta['source_datetime_utc']==='2026-11-01 11:00:00','global case code and UTC source instant are retained with original values');
ux_ok($rows[1]['row_status']==='invalid' && $rows[1]['duplicate_reason']==='invalid_source_datetime','a Gregorian source date cannot become allocatable RAW data');
$wrong=$records;$wrong[0]['file_code']='ANOTHER-CASE';ux_throws(fn()=>$mis->import_rows($batch,$wrong,'wrong-case.csv',false),'a file reference belonging to a different case is rejected');
$run2=$log->start($batch,'bad-file.csv',1);$log->progress($run2,'failed',[],'فایل خوانده نشد؛ قالب را اصلاح کنید.');$summary=$log->summary(['batch_id'=>$batch]);
ux_ok($summary['attempts']===2 && $summary['completed']===1 && $summary['failed']===1,'dashboard file totals count attempts and failures rather than case headers');
$wpdb->insert($p.'sn_mis_import_batches',['batch_code'=>'EMPTY-'.bin2hex(random_bytes(4)),'title'=>'No upload']);ux_ok($log->summary(['batch_id'=>$batch])['attempts']===2,'an empty case never fabricates a file import');
$other=new SN_MIS_Import_Log($wpdb,999999);ux_throws(fn()=>$other->get($run),'another actor cannot change or replay an import receipt');
$mis->edit($invalid,['source_datetime_raw'=>'1405/08/10 09:00'],'اصلاح زمان شمسی');$fixed=$wpdb->get_row($wpdb->prepare('SELECT row_status,source_meta FROM '.$p.'sn_mis_data_rows WHERE id=%d',$invalid),ARRAY_A);
ux_ok($fixed['row_status']==='valid' && json_decode($fixed['source_meta'],true)['source_datetime_utc']==='2026-11-01 05:30:00','correcting an invalid Jalali source time restores validity and standard time');
$mis->assign([$id],101,['key'=>'ux_a','label'=>'UX branch A'],'UX date fixture');$item=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}sn_distribution_items WHERE source_type='mis_data_row' AND source_id=%d",$id));$mis->observe_distribution($item,'assigned_to_role',102,'supervisor','UX consumption');
$wpdb->query($wpdb->prepare("UPDATE {$p}sn_mis_events SET created_at=CASE event_type WHEN 'imported' THEN '2026-09-01 00:00:00' WHEN 'assigned' THEN '2026-10-01 00:00:00' WHEN 'consumed' THEN '2026-11-01 00:00:00' ELSE created_at END WHERE row_id=%d",$id));
$filter=['search'=>$phone,'from'=>'2026-11-01 00:00:00','to'=>'2026-11-02 00:00:00'];
ux_ok($mis->query($filter+['date_type'=>'assigned'],50,0,true)['total']===0,'historical assignment-date report excludes a consumption-only day');
$consumed=$mis->query($filter+['date_type'=>'consumed'],50,0,true);ux_ok($consumed['total']===1 && $consumed['rows'][0]['event_type']==='consumed','historical consumption-date report returns the matching event');
ux_ok($mis->query($filter+['date_type'=>'event'],50,0,true)['total']===1,'all-event date mode retains all event kinds on the selected day');
$typed=$mis->history_stats($filter+['date_type'=>'assigned','__typed_history'=>true]);ux_ok((int)$typed['totals']['events']===0,'historical report count follows the same typed date filter');
$_GET=['sn_mis_page'=>'reports','report_mode'=>'events','event_type'=>'assigned','date_type'=>'event'];$pager=new ReflectionMethod($plugin,'sn_mis_v2_pager');$pager->setAccessible(true);ob_start();$pager->invoke($plugin,200,0,'reports');$pager_html=html_entity_decode(ob_get_clean());ux_ok(str_contains($pager_html,'event_type=assigned'),'next-page URL preserves the event-type filter');
$wpdb->insert($p.'sn_mis_distribution_logs',['batch_id'=>$batch,'actor_user_id'=>1,'action'=>'ux194_test_log','old_value'=>'UX194_PRIVATE_SENTINEL','reason'=>'UX history','created_at'=>current_time('mysql')]);
$_GET=['sn_mis_advanced'=>1,'sn_ui_active_tab'=>'mis-logs'];$html=$plugin->render_mis_workspace();ux_ok(($_GET['sn_mis_page']??'')==='logs' && str_contains($html,'لاگ و عیب‌یابی MIS') && str_contains($html,'UX194_PRIVATE_SENTINEL'),'legacy logs URL opens the preserved read-only log interface');
$uid=username_exists('mis_fixture');wp_set_current_user($uid);$_GET=['sn_mis_page'=>'logs'];$html=(new SN_Plugin())->render_mis_workspace();ux_ok(!str_contains($html,'UX194_PRIVATE_SENTINEL'),'ordinary MIS operator cannot read technical administrator logs');wp_set_current_user(1);
$_GET=['sn_mis_page'=>'upload','batch_id'=>$batch];$html=(new SN_Plugin())->render_mis_workspace();ux_ok(str_contains($html,'enctype="multipart/form-data"') && str_contains($html,'کد پرونده'),'file upload retains a functional no-JavaScript multipart fallback and case reference');
$_GET=['sn_mis_page'=>'return','row_id'=>$id];$html=(new SN_Plugin())->render_mis_workspace();$dom=new DOMDocument();libxml_use_internal_errors(true);$dom->loadHTML('<?xml encoding="utf-8"?>'.$html);$xp=new DOMXPath($dom);ux_ok($xp->query('//input[@name="normalized_phone" and @readonly]')->length===1,'consumed identity is explained and read-only before a user submits an edit');
ux_throws(fn()=>$mis->edit($id,['normalized_phone'=>'09961234567'],'forbidden'),'server still blocks a forged consumed-identity edit');
$render=new ReflectionMethod($plugin,'sn_mis_feedback_markup');$render->setAccessible(true);ob_start();$render->invoke($plugin,['assigned'=>0,'blocked'=>2,'reasons'=>['دارای فاکتور'=>2]]);$feedback=ob_get_clean();ux_ok(str_contains($feedback,'is-partial') && str_contains($feedback,'هیچ شماره‌ای تغییر نکرد'),'a completely blocked batch never displays a full success message');
$f=SN_MIS_Feedback::error(new RuntimeException('تاریخ و ساعت منبع باید شمسی باشد'));ux_ok(!empty($f['hint']) && $f['field']==='source_datetime_raw','date error has a Persian recovery action and the relevant field');
$f=SN_MIS_Feedback::session();ux_ok($f['requires_reload']===true && str_contains($f['hint'],'نگه دارید'),'expired-session response explains preserving form text before reload');
$f=SN_MIS_Feedback::error(new RuntimeException('synthetic SQL internal diagnostic'));ux_ok(!str_contains(json_encode($f),'SQL internal') && !empty($f['reference']),'unexpected internal details are replaced with a traceable safe message');
$source_time=new ReflectionMethod($plugin,'sn_mis_source_time');$source_time->setAccessible(true);ux_ok($source_time->invoke($plugin,'2026-11-01 12:30')==='1405/08/10 12:30','unambiguous legacy Gregorian source dates display as Jalali without database rewriting');
$bytes=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.$p.'sn_mis_data_rows WHERE id=%d',$id),ARRAY_A);$_GET=['sn_mis_page'=>'imports','batch_id'=>$batch];$html=$plugin->render_mis_workspace();ux_ok(str_contains($html,'ux-valid.csv') && str_contains($html,'bad-file.csv') && $bytes===$wpdb->get_row($wpdb->prepare('SELECT * FROM '.$p.'sn_mis_data_rows WHERE id=%d',$id),ARRAY_A),'file history shows both successful and failed runs without changing customer data');
// A receipt failure must roll back the rows and imported events in the same transaction.
$atomic_run=$log->start($batch,'ux-atomic.csv',64);$log->progress($atomic_run,'preview',[]);
$before_rows=(int)$wpdb->get_var('SELECT COUNT(*) FROM '.$p.'sn_mis_data_rows');$before_events=(int)$wpdb->get_var('SELECT COUNT(*) FROM '.$p.'sn_mis_events');
$inject=static function($sql)use($p){return str_starts_with($sql,'UPDATE `'.$p.'sn_mis_import_runs`') && str_contains($sql,'completed')?'UPDATE missing_mis_receipt_test_table SET id=1':$sql;};
add_filter('query',$inject,999);$old_suppress=$wpdb->suppress_errors(true);$atomic_failed=false;
try{$atomic_records=[['customer_phone'=>'09969941940','normalized_phone'=>'09969941940','customer_name'=>'Atomic fixture','row_status'=>'valid','file_code'=>$code,'source_datetime_raw'=>'1405/08/10 10:00']];$mis->import_rows($batch,$atomic_records,'ux-atomic.csv',true,$atomic_run);}catch(RuntimeException $e){$atomic_failed=true;}finally{remove_filter('query',$inject,999);$wpdb->suppress_errors($old_suppress);}
ux_ok($atomic_failed && (int)$wpdb->get_var('SELECT COUNT(*) FROM '.$p.'sn_mis_data_rows')===$before_rows && (int)$wpdb->get_var('SELECT COUNT(*) FROM '.$p.'sn_mis_events')===$before_events,'receipt-write failure rolls back imported rows and events');
ux_ok($log->get($atomic_run)['status']==='preview','failed transaction cannot leave a completed file receipt');
sn_mis_test_write('ux194-summary.json',['passed'=>count($checks),'checks'=>$checks,'batch'=>$batch,'case_code'=>$code,'row_id'=>$id,'phone'=>$phone]);
