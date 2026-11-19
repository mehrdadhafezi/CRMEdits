<?php
ob_start();require __DIR__.'/native-support.php';hr239_bootstrap();$f=hr239_data();global $wpdb;
$seller=$f['users']['seller_a']['id'];$manager=$f['users']['manager_a']['id'];$supervisor=$f['users']['supervisor_a']['id'];$senior=$f['users']['senior_a']['id'];$actor=$f['users']['hr']['id'];$selection=hr239_selection($f);
$roles_before=get_option($wpdb->prefix.'user_roles');
$financial=[];foreach(['sn_invoices','sn_payments','sn_invoice_payment_stages'] as $table){$financial[$table]=$wpdb->get_results("SELECT * FROM {$wpdb->prefix}{$table} ORDER BY id",ARRAY_A);}
$financial_before=hash('sha256',wp_json_encode($financial));
foreach($f['users'] as $u){delete_user_meta($u['id'],SN_HR_Invoice_Reissue::META_KEY);}
hr239_ok(!SN_HR_Invoice_Reissue::allows($seller),'native default denies reissue');
$view=hr239_request($actor,$selection);hr239_ok($view['success']&&count($view['data']['rows'])===4,'native HR list contains the selected four levels');
$all=hr239_request($actor,['manager_id'=>'0']);hr239_ok($all['success']&&count($all['data']['managers'])>=2&&$all['data']['rows']===[],'native initial selector has managers without implicit targets');
hr239_ok(hr239_save($f,$manager,'allow')['success']&&SN_HR_Invoice_Reissue::allows($seller),'native manager allow inherited');
hr239_ok(!SN_HR_Invoice_Reissue::allows($f['users']['seller_b']['id']),'native other branch stays denied');
hr239_ok(hr239_save($f,$senior,'deny')['success']&&!SN_HR_Invoice_Reissue::allows($seller),'native senior denial overrides manager');
hr239_ok(hr239_save($f,$supervisor,'allow')['success']&&SN_HR_Invoice_Reissue::allows($seller),'native supervisor exception overrides senior');
hr239_ok(hr239_save($f,$seller,'deny')['success']&&!SN_HR_Invoice_Reissue::allows($seller),'native personal denial wins');
hr239_ok(hr239_save($f,$seller,'inherit')['success']&&SN_HR_Invoice_Reissue::allows($seller),'native reset restores inheritance');
hr239_ok(get_user_meta($seller,SN_HR_Invoice_Reissue::META_KEY,true)==='','native inherit removes only the policy override');
$count=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}sn_hr_profile_logs WHERE action='invoice_reissue_policy_updated'");
hr239_ok($count>=5,'native permission changes are audited');
$none=hr239_save($f,$seller,'inherit');hr239_ok($none['success']&&(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}sn_hr_profile_logs WHERE action='invoice_reissue_policy_updated'")===$count,'native unchanged save does not invent an audit change');
foreach([0,$f['users']['seller_a']['id'],$f['users']['manager_a']['id'],$f['users']['subscriber']['id']] as $bad){$denied=hr239_request($bad,array_merge($selection,['role'=>'sn_hr']));hr239_ok(!$denied['success']&&in_array($denied['_status'],[401,403],true),'native guest/unrelated/forged-role actor denied');}
$revoked=get_user_by('id',$f['users']['revoked']['id']);$revoked->set_role('subscriber');
hr239_ok(hr239_request($revoked->ID,$selection)['_status']===403,'native revoked HR role denied');
$revoked->set_role('sn_hr');
$token=bin2hex(random_bytes(32));set_transient('sn_staff_view_'.hash('sha256',$token),['actor'=>$f['admin_id'],'target'=>$actor],60);$_COOKIE['sn_staff_view']=$token;
hr239_ok(hr239_request($actor,$selection)['_status']===403,'native staff-view impersonation cannot edit HR policy');unset($_COOKIE['sn_staff_view']);delete_transient('sn_staff_view_'.hash('sha256',$token));
foreach(['','invalid-synthetic-nonce',[]] as $nonce){hr239_ok(hr239_request($actor,array_merge($selection,['nonce'=>$nonce]))['_status']===403,'native missing/invalid/array nonce denied');}
wp_set_current_user($seller);$other_nonce=wp_create_nonce('sn_hr_invoice_reissue');
hr239_ok(hr239_request($actor,array_merge($selection,['nonce'=>$other_nonce]))['_status']===403,'native cross-user nonce denied');
$bad=$selection;$bad['seller_ids']=[(string)$f['users']['seller_b']['id']];hr239_ok(hr239_request($actor,$bad)['_status']===403,'native cross-manager read selection denied');
$row=hr239_row(hr239_request($actor,$selection),$seller);
hr239_ok(hr239_request($actor,array_merge($selection,['operation'=>'save','user_id'=>(string)$f['users']['seller_b']['id'],'mode'=>'allow','version'=>$row['version']]))['_status']===403,'native cross-manager policy write denied');
foreach([['mode'=>['allow']],['mode'=>'ALLOW'],['user_id'=>"1 OR 1=1"],['version'=>'invalid']] as $bad){$payload=array_merge($selection,['operation'=>'save','user_id'=>(string)$seller,'mode'=>'allow','version'=>$row['version']],$bad);hr239_ok(hr239_request($actor,$payload)['_status']===400,'native malformed or SQL-like save denied');}
$old_version=hr239_row(hr239_request($actor,$selection),$seller)['version'];
hr239_ok(hr239_save($f,$supervisor,'deny')['success'],'native concurrent ancestor setting prepared');
hr239_ok(hr239_save($f,$seller,'allow',null,$old_version)['_status']===409&&!SN_HR_Invoice_Reissue::allows($seller),'native stale inherited version cannot overwrite');
// Only this disposable site's new-prefix metadata table is altered, then restored.
$metadata_before=get_user_meta($seller,SN_HR_Invoice_Reissue::META_KEY,true);
try{
    hr239_ok($wpdb->query("ALTER TABLE {$wpdb->usermeta} ENGINE=MyISAM")!==false,'synthetic nontransactional storage prepared');
    $unsafe=hr239_save($f,$seller,'allow');
    hr239_ok($unsafe['_status']===503&&get_user_meta($seller,SN_HR_Invoice_Reissue::META_KEY,true)===$metadata_before,'native nontransactional storage fails safely without policy mutation');
}finally{
    if($wpdb->query("ALTER TABLE {$wpdb->usermeta} ENGINE=InnoDB")===false){throw new RuntimeException('Synthetic metadata engine restore failed');}
}
// Fail the audit INSERT after metadata mutation; the transaction must undo both.
$log_table=$wpdb->prefix.'sn_hr_profile_logs';$before=get_user_meta($seller,SN_HR_Invoice_Reissue::META_KEY,true);
$fail=static function($sql)use($log_table){return preg_match('/^INSERT\s+INTO\s+`?'.preg_quote($log_table,'/').'`?\s/i',$sql)?'SELECT hr239_injected_failure FROM hr239_nonexistent_table':$sql;};
add_filter('query',$fail);$failed=hr239_save($f,$seller,'allow');remove_filter('query',$fail);
hr239_ok($failed['_status']===500&&get_user_meta($seller,SN_HR_Invoice_Reissue::META_KEY,true)===$before&&!SN_HR_Invoice_Reissue::allows($seller),'native audit failure rolls back metadata and cache');
$hierarchy=new SN_Hierarchy_Service();
hr239_ok(!empty($hierarchy->assign_parent($f['profiles']['supervisor_a'],$f['profiles']['senior_b'],$f['admin_id'],'synthetic_hr239_transfer')['success']),'native controlled transfer prepared');
delete_user_meta($supervisor,SN_HR_Invoice_Reissue::META_KEY);delete_user_meta($senior,SN_HR_Invoice_Reissue::META_KEY);
hr239_ok(!SN_HR_Invoice_Reissue::allows($seller),'native transfer does not keep old manager grant');
update_user_meta($seller,SN_HR_Invoice_Reissue::META_KEY,'allow');hr239_ok(SN_HR_Invoice_Reissue::allows($seller),'native personal exception survives transfer');
$hierarchy->assign_parent($f['profiles']['supervisor_a'],$f['profiles']['senior_a'],$f['admin_id'],'synthetic_hr239_return');
delete_user_meta($seller,SN_HR_Invoice_Reissue::META_KEY);delete_user_meta($manager,SN_HR_Invoice_Reissue::META_KEY);
hr239_ok(!SN_HR_Invoice_Reissue::allows($seller),'native cleanup returns default denial');
update_user_meta($manager,SN_HR_Invoice_Reissue::META_KEY,'allow');update_user_meta($seller,SN_HR_Invoice_Reissue::META_KEY,'allow');
$wpdb->update($wpdb->prefix.'sn_hr_profiles',['is_active'=>0],['id'=>$f['profiles']['seller_a']]);hr239_ok(!SN_HR_Invoice_Reissue::allows($seller),'native inactive HR profile cannot grant reissue');$wpdb->update($wpdb->prefix.'sn_hr_profiles',['is_active'=>1],['id'=>$f['profiles']['seller_a']]);
$edge=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_hr_assignments WHERE child_profile_id=%d AND is_current=1 AND relationship_type='reports_to'",$f['profiles']['seller_a']),ARRAY_A);$duplicate=$edge;unset($duplicate['id']);
hr239_ok($wpdb->insert($wpdb->prefix.'sn_hr_assignments',$duplicate)!==false,'native ambiguous current parent prepared');$duplicate_id=(int)$wpdb->insert_id;
hr239_ok(!SN_HR_Invoice_Reissue::allows($seller),'native ambiguous parents fail closed even with personal allow');$wpdb->delete($wpdb->prefix.'sn_hr_assignments',['id'=>$duplicate_id]);
$wpdb->update($wpdb->prefix.'sn_hr_assignments',['is_current'=>0],['id'=>$edge['id']]);hr239_ok(!SN_HR_Invoice_Reissue::allows($seller),'native missing current parent cannot grant reissue');$wpdb->update($wpdb->prefix.'sn_hr_assignments',['is_current'=>1],['id'=>$edge['id']]);
delete_user_meta($manager,SN_HR_Invoice_Reissue::META_KEY);delete_user_meta($seller,SN_HR_Invoice_Reissue::META_KEY);
foreach(['sn_invoices','sn_payments','sn_invoice_payment_stages'] as $table){$financial[$table]=$wpdb->get_results("SELECT * FROM {$wpdb->prefix}{$table} ORDER BY id",ARRAY_A);}
hr239_ok(hash('sha256',wp_json_encode($financial))===$financial_before,'native HR policy operations never mutate financial rows');
hr239_ok(get_option($wpdb->prefix.'user_roles')===$roles_before,'native WordPress role/capability definitions preserved');
$summary=hr239_source_fingerprint()+['version'=>SN_VERSION,'php'=>PHP_VERSION,'wordpress'=>$GLOBALS['wp_version'],'mysql'=>$wpdb->db_version(),'assertions'=>$GLOBALS['hr239_checks'],'synthetic'=>true,'passed'=>true,'roles_preserved'=>true,'production_touched'=>false];
file_put_contents($GLOBALS['hr239_output'].'/hr239-native-summary.json',wp_json_encode($summary,JSON_PRETTY_PRINT));
echo 'TOTAL '.$GLOBALS['hr239_checks'].PHP_EOL;ob_end_flush();
