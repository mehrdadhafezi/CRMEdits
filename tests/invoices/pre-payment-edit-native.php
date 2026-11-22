<?php
/** wp eval-file only in a newly allocated, network-isolated synthetic site. */
if (PHP_SAPI!=='cli' || !defined('SN_PREPAYMENT_TEST_SANDBOX') || !SN_PREPAYMENT_TEST_SANDBOX
    || WP_ENVIRONMENT_TYPE!=='local' || !preg_match('/^sn_prepaid_[a-z0-9]+_test$/',DB_NAME)) {
    throw new RuntimeException('Disposable prepayment sandbox required');
}
if (!defined('DOING_AJAX')) { define('DOING_AJAX',true); }
sn_bootstrap_plugin_instance(true);
global $wpdb,$fixture,$checks;$wpdb->hide_errors();$checks=0;
function prep_ok($value,$label){global $checks;if(!$value)throw new RuntimeException('FAIL '.$label);++$checks;echo 'PASS '.$label.PHP_EOL;}
function prep_insert($suffix,$data){global $wpdb;if(false===$wpdb->insert($wpdb->prefix.$suffix,$data))throw new RuntimeException('Synthetic insert failed '.$suffix);return (int)$wpdb->insert_id;}
function prep_request(int $actor,array $data,bool $nonce=true,string $failure=''):array{
    static $cookies=[];
    wp_set_current_user(0);wp_set_current_user($actor);
    if($actor&&!isset($cookies[$actor])){
        $expires=time()+3600;$token=WP_Session_Tokens::get_instance($actor)->create($expires);
        $cookies[$actor]=[LOGGED_IN_COOKIE=>wp_generate_auth_cookie($actor,$expires,'logged_in',$token),AUTH_COOKIE=>wp_generate_auth_cookie($actor,$expires,'auth',$token)];
    }
    $_COOKIE=$cookies[$actor]??[];
    $payload=array_merge(['action'=>'sn_invoice_pre_payment_edit','nonce'=>$nonce?wp_create_nonce('sn_public'):'invalid'],$data);
    $curl=curl_init('http://prepay-web/wp-admin/admin-ajax.php');
    curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($payload),CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>30,CURLOPT_COOKIE=>implode('; ',array_map(static fn($key)=>$key.'='.$_COOKIE[$key],array_keys($_COOKIE))),CURLOPT_HTTPHEADER=>$failure!==''?['X-Synthetic-Prepayment-Failure: '.$failure]:[]]);
    $raw=curl_exec($curl);$error=curl_error($curl);curl_close($curl);
    if($raw===false)throw new RuntimeException('Synthetic internal HTTP failed: '.$error);
    $result=json_decode($raw,true);
    if(!$actor&&!is_array($result))return ['success'=>false];
    if(!is_array($result)){file_put_contents('/evidence/invalid-response.private.log',$raw);throw new RuntimeException('Expected native JSON response');}
    return $result;
}
function prep_data(array $result):array{return is_array($result['data']??null)?$result['data']:$result;}
function prep_state(int $id):string{
    global $wpdb;$tables=[];
    foreach(['sn_invoices'=>'id','sn_invoice_items'=>'invoice_id','sn_invoice_payment_stages'=>'invoice_id','sn_payments'=>'invoice_id'] as $table=>$key){$tables[$table]=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}{$table} WHERE {$key}=%d ORDER BY id",$id),ARRAY_A);}
    return hash('sha256',wp_json_encode($tables));
}
function prep_read(int $actor,int $id):array{$result=prep_request($actor,['invoice_id'=>$id,'edit_mode'=>'read']);prep_ok(!empty($result['success']),'actual read opens discounted prepayment editor');return prep_data($result);}
function prep_payload(array $read,array $changes=[]):array{
    return array_replace(['invoice_id'=>$read['invoice_id'],'edit_mode'=>'preview','revision'=>$read['revision'],'product_ids'=>array_column($read['items'],'product_id'),'product_qtys'=>array_column($read['items'],'qty'),'catalog_discount_ids'=>$read['catalog_discount_ids'],'payment_plan'=>$read['payment_plan'],'prepayment_amount'=>'300','customer_phone_secondary'=>''],$changes);
}
function prep_save(int $actor,array $payload,array $preview):array{return prep_request($actor,array_merge($payload,['edit_mode'=>'save','expires'=>$preview['expires'],'preview_token'=>$preview['preview_token']]));}
function prep_invoice(array $change=[]):int{
    global $wpdb,$fixture;$pid=$fixture['products'][0];$owner=$fixture['owner']['id'];
    $pricing=SN_Sales_Catalog::instance()->price_items([['product_id'=>$pid,'product_name'=>'Synthetic A','qty'=>1,'unit_price'=>1000]],['20000'],$owner,$owner);
    if(is_wp_error($pricing))throw new RuntimeException('Native catalog fixture invalid');
    $id=prep_insert('sn_invoices',array_replace(['invoice_code'=>'SYN-EDIT-'.wp_generate_password(10,false),'seller_id'=>$owner,'issued_by_user_id'=>$owner,'current_stage_issued_by_user_id'=>$owner,
        'customer_name'=>'مشتری ساختگی','customer_phone'=>'09120000000','customer_address'=>'آدرس ساختگی','province'=>'تهران','city'=>'تهران','product_id'=>$pid,'product_price'=>800,'original_total'=>1000,'final_total'=>800,'discount_total'=>200,'discount_amount'=>200,'catalog_discounts_snapshot'=>wp_json_encode($pricing['snapshot']),'catalog_manager_user_id'=>$fixture['manager'],
        'status'=>'pre_invoice','invoice_status'=>'pre_invoice','payment_status'=>'pending_payment','payment_plan'=>'partial','payment_total_amount'=>800,'paid_total_amount'=>0,'remaining_amount'=>800,'current_due_amount'=>200,'current_payment_stage'=>1,'payment_workflow_status'=>'awaiting_payment','access_token'=>wp_generate_password(40,false),'created_at'=>'2026-11-01 12:00:00','updated_at'=>'2026-11-01 12:00:00'],$change));
    prep_insert('sn_invoice_items',['invoice_id'=>$id,'product_id'=>$pid,'product_name'=>'Synthetic A','product_type'=>'product','qty'=>1,'unit_price'=>1000,'total_price'=>1000,'is_free'=>0,'created_at'=>'2026-11-01 12:00:00']);
    prep_insert('sn_invoice_payment_stages',['invoice_id'=>$id,'stage_no'=>1,'stage_type'=>(string)($change['payment_plan']??'partial'),'requested_amount'=>200,'status'=>'pending','issued_by_user_id'=>$owner,'created_at'=>'2026-11-01 12:00:00','updated_at'=>'2026-11-01 12:00:00']);return $id;
}
$mode=$args[0]??'setup';
if($mode==='browser-reset'){
    $fixture=json_decode(file_get_contents('/evidence/fixture.json'),true);
    $wallet=new ReflectionMethod(sn_bootstrap_plugin_instance(),'sn_wallet_id');$wallet->setAccessible(true);
    foreach(['owner','other'] as $actor){$wallet->invoke(sn_bootstrap_plugin_instance(),$fixture[$actor]['id']);}
    foreach(['sn_wallets','sn_wallet_transactions'] as $table)$fixture['financial_baseline'][$table]=hash('sha256',wp_json_encode($wpdb->get_results("SELECT * FROM {$wpdb->prefix}{$table} ORDER BY id",ARRAY_A)));
    $fixture['browser_invoice']=prep_invoice();$fixture['browser_sender_invoice']=prep_invoice();
    $fixture['browser_identity']=$wpdb->get_row($wpdb->prepare("SELECT id,invoice_code,access_token FROM {$wpdb->prefix}sn_invoices WHERE id=%d",$fixture['browser_invoice']),ARRAY_A);
    prep_insert('sn_activity_logs',['invoice_id'=>$fixture['browser_sender_invoice'],'user_id'=>$fixture['admin_id'],'action'=>'invoice_sms_resent','description'=>'Synthetic last sender','created_at'=>current_time('mysql')]);
    file_put_contents('/evidence/fixture.json',wp_json_encode($fixture));echo 'BROWSER_FIXTURES_READY_AFTER_ACCOUNT_BOOTSTRAP'.PHP_EOL;return;
}

if($mode==='verify'){
    $fixture=json_decode(file_get_contents('/evidence/fixture.json'),true);$id=$fixture['browser_invoice'];
    $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_invoices WHERE id=%d",$id));
    prep_ok((float)$row->current_due_amount===500.0,'browser saved first-stage amount through native controller');
    prep_ok((float)$row->final_total===1700.0&&(float)$row->discount_total===300.0,'browser product/discount edit persisted correct net total');
    prep_ok((int)$row->product_id===$fixture['products'][1],'browser changed product on same invoice');
    prep_ok(array_intersect_key((array)$row,$fixture['browser_identity'])===$fixture['browser_identity'],'invoice identity/code/public token remain unchanged');
    prep_ok((int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}sn_payments")===count($fixture['synthetic_payment_rows']),'edits create no payment ledger rows');
    foreach($fixture['financial_baseline'] as $table=>$hash)prep_ok(hash('sha256',wp_json_encode($wpdb->get_results("SELECT * FROM {$wpdb->prefix}{$table} ORDER BY id",ARRAY_A)))===$hash,'unrelated financial table unchanged '.$table);
    file_put_contents('/evidence/post-browser-summary.json',wp_json_encode(['passed'=>true,'assertions'=>$checks,'synthetic'=>true]));return;
}
if($mode==='setup'){
prep_ok(class_exists('WooCommerce')&&WC_VERSION==='10.4.3','native WordPress and pinned WooCommerce loaded');
$admin=get_user_by('login','synthetic_admin')->ID;
$fixture=['owner'=>[],'other'=>[],'products'=>[],'synthetic_payment_rows'=>[],'financial_baseline'=>[]];
foreach(['owner','other'] as $kind){$password=wp_generate_password(32,false);$id=wp_insert_user(['user_login'=>'prep_'.$kind,'user_pass'=>$password,'role'=>'sn_seller','display_name'=>'کاربر ساختگی '.$kind]);prep_ok(!is_wp_error($id),'synthetic seller '.$kind);$fixture[$kind]=['id'=>(int)$id,'username'=>'prep_'.$kind,'password'=>$password];}
$manager=wp_insert_user(['user_login'=>'prep_manager','user_pass'=>wp_generate_password(32,false),'role'=>'sn_sales_manager']);prep_ok(!is_wp_error($manager),'synthetic manager');$fixture['manager']=(int)$manager;
foreach([$fixture['owner']['id']=>'seller',$manager=>'sales_manager'] as $user=>$position){$position_id=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}sn_hr_positions WHERE slug=%s",$position));prep_ok($position_id>0,'installed native HR position '.$position);prep_insert('sn_hr_profiles',['user_id'=>$user,'position_id'=>$position_id,'is_active'=>1,'employment_status'=>'active']);}
$profile=static fn($id)=>(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}sn_hr_profiles WHERE user_id=%d ORDER BY id DESC LIMIT 1",$id));
prep_ok(!empty((new SN_Hierarchy_Service())->assign_parent($profile($fixture['owner']['id']),$profile($manager),$admin,'synthetic_test')['success']),'real seller manager hierarchy');
foreach([['محصول ساختگی A',1000],['محصول ساختگی B',2000]] as [$name,$price]){$p=new WC_Product_Simple();$p->set_name($name);$p->set_status('publish');$p->set_regular_price((string)$price);$p->set_virtual(true);$pid=$p->save();update_post_meta($pid,'_sn_enabled','1');update_post_meta($pid,'_sn_sales_product_type','product');$fixture['products'][]=$pid;}
update_user_meta($manager,'_sn_sales_catalog_policy',['mode'=>'all','products'=>[$fixture['products'][0]=>['discounts'=>['10000','20000'],'allow_custom_discount'=>true],$fixture['products'][1]=>['discounts'=>['30000'],'allow_custom_discount'=>false]],'revision'=>'synthetic']);
$wallet=new ReflectionMethod(sn_bootstrap_plugin_instance(),'sn_wallet_id');$wallet->setAccessible(true);foreach(['owner','other'] as $kind)$wallet->invoke(sn_bootstrap_plugin_instance(),$fixture[$kind]['id']);
foreach(['sn_wallets','sn_wallet_transactions'] as $table)$fixture['financial_baseline'][$table]=hash('sha256',wp_json_encode($wpdb->get_results("SELECT * FROM {$wpdb->prefix}{$table} ORDER BY id",ARRAY_A)));
}elseif($mode==='regression'){$fixture=json_decode(file_get_contents('/evidence/fixture.json'),true);$admin=$fixture['admin_id'];}
else{throw new RuntimeException('Unknown synthetic test mode');}
$owner=$fixture['owner']['id'];$id=prep_invoice();$before=prep_state($id);$read=prep_read($owner,$id);
prep_ok(prep_state($id)===$before,'read does not mutate financial data');
$payload=prep_payload($read);$response=prep_request($owner,$payload);prep_ok(!empty($response['success']),'actual signed preview succeeds');$preview=prep_data($response);
prep_ok(prep_state($id)===$before,'preview rolls back and changes no records');
prep_ok((float)$preview['quote']['pricing']['final_total']===800.0&&$preview['quote']['due']===300,'preview retains original discount for amount-only edit');
prep_ok(!empty(prep_save($owner,$payload,$preview)['success']),'actual save accepts bound preview');
$row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_invoices WHERE id=%d",$id));
prep_ok((float)$row->current_due_amount===300.0&&(float)$row->final_total===800.0&&(float)$row->discount_total===200.0,'stage amount changed without losing discount');
prep_ok((float)$wpdb->get_var($wpdb->prepare("SELECT requested_amount FROM {$wpdb->prefix}sn_invoice_payment_stages WHERE invoice_id=%d",$id))===300.0,'initial stage stays consistent with invoice');
foreach([['product_ids'=>[$fixture['products'][1]],'catalog_discount_ids'=>['30000']],['product_qtys'=>[3],'catalog_discount_ids'=>['10000']],['catalog_discount_ids'=>['custom:12525']]] as $changes){
    $id=prep_invoice();$read=prep_read($owner,$id);$payload=prep_payload($read,$changes);$response=prep_request($owner,$payload);prep_ok(!empty($response['success']),'native authorized product/quantity/discount preview');$preview=prep_data($response);prep_ok(!empty(prep_save($owner,$payload,$preview)['success']),'native authorized product/quantity/discount save');
    $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_invoices WHERE id=%d",$id));prep_ok(abs((float)$row->final_total-(float)$preview['quote']['pricing']['final_total'])<.001,'native persisted net equals server preview');
}
$id=prep_invoice();$read=prep_read($owner,$id);$before=prep_state($id);
foreach([['prepayment_amount'=>'0'],['prepayment_amount'=>'-1'],['prepayment_amount'=>'800'],['prepayment_amount'=>'801'],['prepayment_amount'=>'1e3'],['prepayment_amount'=>[]],['catalog_discount_ids'=>['99999']],['product_ids'=>[[]]],['product_ids'=>['1 OR 1=1']],['product_qtys'=>[0]],['product_qtys'=>[1.5]],['payment_plan'=>[]],['product_ids'=>[999999]],['catalog_discount_ids'=>[['20000']]]] as $bad){prep_ok(empty(prep_request($owner,prep_payload($read,$bad))['success']),'native invalid amount/product/discount rejected');prep_ok(prep_state($id)===$before,'invalid request has no partial changes');}
prep_ok(empty(prep_request($owner,['invoice_id'=>$id,'edit_mode'=>'read'],false)['success']),'actual invalid nonce denied');
prep_ok(empty(prep_request(0,['invoice_id'=>$id,'edit_mode'=>'read'])['success']),'actual guest denied');
prep_ok(empty(prep_request($fixture['other']['id'],['invoice_id'=>$id,'edit_mode'=>'read'])['success']),'actual unrelated seller denied');
$payload=prep_payload($read);$preview=prep_data(prep_request($owner,$payload));
foreach([['preview_token'=>'forged'],['expires'=>time()-1],['revision'=>'stale']] as $bad){prep_ok(empty(prep_save($owner,array_merge($payload,$bad),array_merge($preview,$bad))['success']),'native stale/forged preview denied');prep_ok(prep_state($id)===$before,'invalid signature changes no records');}
$wpdb->update($wpdb->prefix.'sn_invoices',['customer_phone_secondary'=>'09120000001'],['id'=>$id]);$changed=prep_state($id);
prep_ok(empty(prep_save($owner,$payload,$preview)['success'])&&prep_state($id)===$changed,'concurrent update cannot be overwritten');
foreach([['status'=>'paid'],['paid_total_amount'=>10],['receipt_url'=>'synthetic-receipt'],['financial_reviewed_at'=>'2026-11-01 12:00:00'],['wc_order_id'=>99],['current_payment_stage'=>2],['invoice_status'=>'approved'],['payment_status'=>'paid'],['receipt_urls'=>'["synthetic-receipt"]'],['status'=>'rejected','payment_total_amount'=>800,'remaining_amount'=>700]] as $paid){$locked=prep_invoice($paid);prep_ok(empty(prep_request($owner,['invoice_id'=>$locked,'edit_mode'=>'read'])['success']),'native payment/fulfillment lock remains active');}
$locked=prep_invoice();$fixture['synthetic_payment_rows'][]=prep_insert('sn_payments',['invoice_id'=>$locked,'payment_stage_no'=>1,'amount'=>800,'status'=>'pending','pay_method'=>'online','authority'=>'synthetic-only-request','created_at'=>'2026-11-01 12:00:00','updated_at'=>'2026-11-01 12:00:00']);prep_ok(empty(prep_request($owner,['invoice_id'=>$locked,'edit_mode'=>'read'])['success']),'native outstanding gateway request locks edit');
$id=prep_invoice();$read=prep_read($owner,$id);$payload=prep_payload($read,['product_qtys'=>[2],'catalog_discount_ids'=>['10000']]);$preview=prep_data(prep_request($owner,$payload));$before=prep_state($id);
$result=prep_request($owner,array_merge($payload,['edit_mode'=>'save','expires'=>$preview['expires'],'preview_token'=>$preview['preview_token']]),true,'stage-write');
prep_ok(empty($result['success'])&&prep_state($id)===$before,'stage write failure rolls back invoice and item repricing');
$id=prep_invoice(['payment_plan'=>'full']);$read=prep_read($owner,$id);$payload=prep_payload($read,['payment_plan'=>'partial']);$preview=prep_data(prep_request($owner,$payload));prep_ok(!empty(prep_save($owner,$payload,$preview)['success']),'last sender may convert full payment to partial');
$id=prep_invoice(['coupon_code'=>'SYNTHETIC-OLD','coupon_discount_amount'=>50]);$read=prep_read($owner,$id);$payload=prep_payload($read);$preview=prep_data(prep_request($owner,$payload));prep_ok(!empty(prep_save($owner,$payload,$preview)['success']),'legacy coupon invoice allows payment-only edit');
prep_ok(empty(prep_request($owner,prep_payload(prep_read($owner,$id),['product_qtys'=>[2]]))['success']),'legacy coupon cannot be silently discarded during repricing');
$refresh=prep_invoice();$product=wc_get_product($fixture['products'][0]);$product->set_regular_price('1200');$product->save();
$read=prep_read($owner,$refresh);$payload=prep_payload($read,['reprice_requested'=>'1']);$response=prep_request($owner,$payload);prep_ok(!empty($response['success']),'explicit price refresh is previewed by actual controller');$preview=prep_data($response);prep_ok((float)$preview['quote']['pricing']['final_total']===1000.0,'price refresh uses current product price and permitted discount');prep_ok(!empty(prep_save($owner,$payload,$preview)['success']),'explicit price refresh persists on same invoice');$product->set_regular_price('1000');$product->save();
$id=prep_invoice();$read=prep_read($owner,$id);$before=prep_state($id);
$payload=prep_payload($read,['final_total'=>1,'product_price'=>1,'total'=>1]);$preview=prep_data(prep_request($owner,$payload));
prep_ok((float)$preview['quote']['pricing']['final_total']===800.0,'posted manual totals cannot override server pricing');
$payload=prep_payload($read,['product_qtys'=>[2],'catalog_discount_ids'=>['10000']]);$preview=prep_data(prep_request($owner,$payload));
$product->set_regular_price('1100');$product->save();
prep_ok(empty(prep_save($owner,$payload,$preview)['success'])&&prep_state($id)===$before,'price change after preview rejects save without mutation');
$product->set_regular_price('1000');$product->save();$preview=prep_data(prep_request($owner,$payload));
$policy=get_user_meta($fixture['manager'],'_sn_sales_catalog_policy',true);$revoked=$policy;$revoked['products'][$fixture['products'][0]]['discounts']=['20000'];update_user_meta($fixture['manager'],'_sn_sales_catalog_policy',$revoked);
prep_ok(empty(prep_save($owner,$payload,$preview)['success'])&&prep_state($id)===$before,'discount revocation after preview rejects save without mutation');
update_user_meta($fixture['manager'],'_sn_sales_catalog_policy',$policy);
$wpdb->update($wpdb->posts,['post_title'=>'<img src=x onerror=window.prepaymentXss=1> محصول ساختگی B'],['ID'=>$fixture['products'][1]]);clean_post_cache($fixture['products'][1]);wc_delete_product_transients($fixture['products'][1]);
$fixture['browser_invoice']=prep_invoice();$fixture['browser_sender_invoice']=prep_invoice();$fixture['browser_locked_invoice']=$locked;
prep_insert('sn_activity_logs',['invoice_id'=>$fixture['browser_sender_invoice'],'user_id'=>$admin,'action'=>'invoice_sms_resent','description'=>'Synthetic last sender','created_at'=>current_time('mysql')]);
$sender_read=prep_read($admin,$fixture['browser_sender_invoice']);$sender_before=prep_state($fixture['browser_sender_invoice']);
foreach([['product_ids'=>[$fixture['products'][1]]],['product_qtys'=>[2]],['catalog_discount_ids'=>['10000']],['reprice_requested'=>'1']] as $forged){
    prep_ok(empty(prep_request($admin,prep_payload($sender_read,$forged))['success']),'nonowner last sender cannot forge product/quantity/discount/price refresh');
    prep_ok(prep_state($fixture['browser_sender_invoice'])===$sender_before,'nonowner forged edit creates no mutation');
}
$fixture['browser_identity']=$wpdb->get_row($wpdb->prepare("SELECT id,invoice_code,access_token FROM {$wpdb->prefix}sn_invoices WHERE id=%d",$fixture['browser_invoice']),ARRAY_A);
$fixture['admin_id']=$admin;
file_put_contents('/evidence/fixture.json',wp_json_encode($fixture));
file_put_contents('/evidence/native-summary.json',wp_json_encode(['passed'=>true,'checks'=>$checks,'wordpress'=>get_bloginfo('version'),'woocommerce'=>WC_VERSION,'php'=>PHP_VERSION,'mysql'=>$wpdb->db_version(),'synthetic'=>true]));
echo 'NATIVE_PREPAYMENT_TOTAL '.$checks.PHP_EOL;
