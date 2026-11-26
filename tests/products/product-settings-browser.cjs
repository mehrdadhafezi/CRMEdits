'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
module.exports=async function({base,fixture,output,channel}){
 const {chromium}=require(process.env.SN_PLAYWRIGHT_MODULE);const browser=await chromium.launch({headless:true,channel});
 fs.mkdirSync(output,{recursive:true});let checks=0;const errors=[];
 function ok(v,label){assert.ok(v,label);checks++;console.log('PASS '+label);}
 async function context(user,mobile=false){const c=await browser.newContext({viewport:mobile?{width:390,height:844}:{width:1440,height:1000},isMobile:mobile,hasTouch:mobile});
  await c.route('**/*',r=>{const u=new URL(r.request().url());return u.origin===new URL(base).origin?r.continue():r.abort();});
  await c.request.get(base+'/wp-login.php');await c.request.post(base+'/wp-login.php',{form:{log:user.login,pwd:user.password,'wp-submit':'Log In',redirect_to:base+'/',testcookie:'1'}});
  const n=await(await c.request.get(base+'/?p241_nonce=1')).json();ok(n.success&&n.user_id===user.id,'real WordPress cookie login '+(mobile?'mobile ':'' )+user.login.split('_').slice(0,2).join('_'));
  return {c,n};
 }
 function page(c){const p=c.newPage();return p.then(p=>{p.on('pageerror',e=>{const symbol=String(e.message).match(/([A-Za-z_$][A-Za-z0-9_$]*) is not defined/);const frames=[...String(e.stack).matchAll(/\/([A-Za-z0-9._-]+\.(?:js|php))(?:\?[^:\n]*)?:([0-9]+):([0-9]+)/g)].map(m=>[m[1],Number(m[2]),Number(m[3])]);errors.push({name:e.name,message:String(e.message).replace(/https?:\/\/\S+|[a-f0-9]{24,}/gi,'[redacted]').slice(0,250),symbol:symbol?symbol[1]:'',frames:frames.slice(0,3)});});return p;});}
 async function state(c){const r=await c.request.get(base+'/?p241_test_state=1&product='+fixture.product_id);return r.json();}
 async function post(c,action,nonce,data={}){const r=await c.request.post(base+'/wp-admin/admin-ajax.php',{form:{action,nonce,...data}});let body=null;try{body=await r.json();}catch{}return {status:r.status(),body};}
 try{
  const admin=await context(fixture.admin);const ap=await page(admin.c);await ap.goto(base+'/wp-admin/admin.php?page=sn-settings',{waitUntil:'domcontentloaded'});
  // Compare equivalent, initialized admin requests: legacy admin_init installs
  // missing baseline roles/caps even without a settings save.
  let before=await state(admin.c);ok(before.version==='2.0.245'&&before.max_input_vars===1000,'actual PHP CGI enforces max_input_vars=1000');
  const fields=[];for(let i=0;i<1100;i++)fields.push(['sn_padding_'+i,'synthetic']);fields.push(['action','sn_save_settings'],['nonce',admin.n.sn_admin]);
  let truncated;
  try{truncated=await ap.evaluate(async body=>(await fetch('/wp-admin/admin-ajax.php',{method:'POST',credentials:'same-origin',headers:{'content-type':'application/x-www-form-urlencoded'},signal:AbortSignal.timeout(15000),body})).status,new URLSearchParams(fields).toString());}
  catch(error){
   console.log('BROWSER_HTTP_ORIGIN_MATCH '+String(new URL(ap.url()).origin===new URL(base).origin));
   try{const response=await admin.c.request.post(base+'/wp-admin/admin-ajax.php',{timeout:10000,headers:{'content-type':'application/x-www-form-urlencoded',connection:'close'},data:new URLSearchParams(fields).toString()});console.log('BROWSER_HTTP_API_STATUS '+response.status());}
   catch(probe){const message=String(probe.message).split(/\r?\n/,1)[0].replace(/https?:\/\/\S+|[a-f0-9]{24,}/gi,'[redacted]').replace(/[^A-Za-z0-9 _/()+,.:\[\]-]/g,'');console.log('BROWSER_HTTP_API_ERROR '+message.slice(0,180));}
   throw error;
  }
  ok(truncated===400,'legacy action-at-end oversized form reproduces HTTP 400');
  const template='Synthetic "quotes" \\ slash متن';const payload={sn_sms_provider:'kavenegar',sn_sms_invoice_template:template};for(let i=0;i<1100;i++)payload['sn_padding_'+i]='synthetic';
  let r=await post(admin.c,'sn_save_settings',admin.n.sn_admin,{payload:JSON.stringify(payload)});ok(r.status===200&&r.body.success,'large JSON settings payload survives native PHP input limit');
  let after=await state(admin.c);ok(after.template===template,'actual HTTP preserves quoted localized SMS template');
  if(after.protected_hash!==before.protected_hash)console.log('CHANGED_COMPONENTS '+Object.keys(before.protected_components).filter(k=>before.protected_components[k]!==after.protected_components[k]).join(','));
  ok(after.protected_hash===before.protected_hash,'SMS save preserves wallet commission roles and unrelated settings');
  for(const bad of ['{broken',JSON.stringify({sn_sms_invoice_template:['wrong']}),JSON.stringify({nonce:'forged'})]){
   r=await post(admin.c,'sn_save_settings',admin.n.sn_admin,{payload:bad});ok(r.body&&!r.body.success&&(await state(admin.c)).template===template,'malformed settings input cannot alter saved template');
  }
  r=await post(admin.c,'sn_save_settings','invalid',{payload:JSON.stringify({sn_sms_invoice_template:'forged'})});ok(r.body&&!r.body.success&&(await state(admin.c)).template===template,'actual HTTP settings save requires nonce');
  await ap.goto(base+'/wp-admin/admin.php?page=sn-settings',{waitUntil:'domcontentloaded'});await ap.locator('.sn-admin-tab[data-tab=sms]').click();
  await ap.evaluate(()=>{const box=document.querySelector('#sn-settings-projects');for(let i=0;i<1100;i++){const input=document.createElement('input');input.name='sn_inactive_'+i;input.required=true;box.appendChild(input);}});
  await ap.locator('[name=sn_sms_invoice_template]').fill('Saved through actual SMS tab');
  const request=ap.waitForRequest(q=>q.url().includes('admin-ajax.php')&&q.postData()?.includes('action=sn_save_settings'));
  await ap.locator('#sn-settings-form button[type=submit]:visible,#sn-settings-form input[type=submit]:visible').first().click();const q=await request;const form=new URLSearchParams(q.postData());
  ok([...form.keys()].sort().join(',')==='action,nonce,payload','settings UI sends routing nonce and one JSON field');
  const sent=JSON.parse(form.get('payload'));ok(!Object.keys(sent).some(k=>k.startsWith('sn_inactive_'))&&!('sn_commission_rates'in sent),'SMS UI excludes other tabs and hidden required fields');
  await ap.waitForFunction(()=>document.querySelector('#sn-settings-notice')?.textContent.includes('ذخیره شد'));ok((await state(admin.c)).template==='Saved through actual SMS tab','real SMS tab save completes without HTTP 400');
  await ap.screenshot({path:path.join(output,'sms-settings-desktop.png'),fullPage:true});
  await ap.goto(base+'/wp-admin/admin.php?page=sn-sales-catalog',{waitUntil:'domcontentloaded'});await ap.locator('.sn-catalog-manager').selectOption(String(fixture.manager_id));await ap.locator('.sn-catalog-load').click();await ap.locator('.sn-catalog-editor').waitFor({state:'visible'});
  const row=ap.locator('tr[data-product-id="'+fixture.product_id+'"]'),flag=row.locator('.sn-catalog-custom-allowed');ok(await flag.isChecked(),'custom discount switch is on the existing manager-product catalog row');
  await flag.uncheck();await ap.locator('.sn-catalog-save').click();await ap.waitForFunction(()=>document.querySelector('.sn-catalog-notice')?.textContent.includes('ذخیره شد'));
  const seller=await context(fixture.seller);r=await post(seller.c,'sn_sales_catalog_staff',seller.n.sn_sales_catalog_staff);ok(!r.body.data.products.find(p=>p.id===fixture.product_id).allow_custom_discount,'catalog toggle off reaches actual seller API');
  await flag.check();await ap.locator('.sn-catalog-save').click();await ap.waitForFunction(()=>document.querySelector('.sn-catalog-notice')?.textContent.includes('ذخیره شد'));
  ok(!await ap.evaluate(()=>window.__p241Xss),'catalog product markup stays escaped');await ap.screenshot({path:path.join(output,'catalog-desktop.png'),fullPage:true});
  for(const mobile of [false,true]){
   const login=mobile?await context(fixture.seller,true):seller,p=await page(login.c);
   await p.goto(base+'/?page_id='+fixture.seller_page_id,{waitUntil:'domcontentloaded'});
   if(mobile)await p.locator('.sn-mobile-sidebar-toggle').first().click();
   await p.locator('#sn-seller-panel .sn-tab[data-tab="new-invoice"]').click();
   const product=p.locator('#sn-products-multi .sn-product-select').first();await product.waitFor({state:'attached'});
   await product.selectOption(String(fixture.product_id));
   await p.waitForFunction(()=>document.querySelector('#sn-products-multi .sn-catalog-discount-select option[data-custom="1"]'));
   const discount=p.locator('#sn-products-multi .sn-catalog-discount-select').first();await discount.selectOption({label:'تخفیف دلخواه'});
   const input=p.locator('#sn-products-multi .sn-catalog-custom-amount').first();await input.fill('۱۲۵٫۲۵');
   const value=await p.evaluate(()=>({choice:window.snCatalogSelections(jQuery('#sn-products-multi'))[0],amount:window.snCatalogLineDiscount(jQuery('#sn-products-multi .sn-product-row').first())}));
   if(value.choice!=='custom:12525'||value.amount!==125.25)console.log('SYNTHETIC_CUSTOM_VALUE '+JSON.stringify(value));
   ok(value.choice==='custom:12525'&&value.amount===125.25,'localized per-unit custom discount in real seller DOM '+(mobile?'mobile':'desktop'));
   await p.screenshot({path:path.join(output,'seller-discount-'+(mobile?'mobile':'desktop')+'.png'),fullPage:true});
   await product.selectOption(String(fixture.second_product_id));ok(await discount.locator('option[data-custom="1"]').count()===0&&await input.isDisabled(),'another product cannot retain custom input '+(mobile?'mobile':'desktop'));
   if(mobile)await login.c.close();else await p.close();
  }
  r=await post(seller.c,'sn_save_settings',seller.n.sn_admin,{payload:JSON.stringify({sn_sms_invoice_template:'forged'})});ok(r.body&&!r.body.success&&(await state(admin.c)).template==='Saved through actual SMS tab','seller cookie cannot save admin settings');
  await ap.goto(base+'/wp-admin/post.php?post='+fixture.product_id+'&action=edit',{waitUntil:'domcontentloaded'});
  const enabled=ap.locator('[name=sn_invoice_sms_product_enabled]'),previous=ap.locator('[name=sn_invoice_sms_product_previous]');ok(!await enabled.isChecked(),'global product SMS setting rendered on actual Woo product edit page');
  ok(!await ap.locator('.sn-product-sms-send').isVisible()&&!await ap.locator('.sn-product-sms-stop').isVisible(),'send and stop controls stay visually hidden before preview');
  await enabled.check();await previous.check();ok(await ap.locator('.sn-product-sms-preview').isDisabled(),'unsaved product change cannot start historical sends');
  const callBefore=(await state(admin.c)).provider_calls;
  await Promise.all([ap.waitForNavigation({waitUntil:'domcontentloaded'}),ap.locator('#publish').click()]);
  after=await state(admin.c);ok(after.policy.enabled&&after.policy.previous&&after.provider_calls===callBefore,'real Woo product save re-enables without automatically sending old SMS');
  ok(!(await ap.locator('body').innerText()).includes('تنظیم پیامک ذخیره نشد'),'successful Woo save has no policy revision conflict');
  await ap.locator('.sn-product-sms-preview').click();await ap.waitForFunction(()=>!document.querySelector('.sn-product-sms-send').hidden);
  ok((await state(admin.c)).provider_calls===callBefore,'historical preview has no provider side effect');
  ap.once('dialog',d=>d.accept());await ap.locator('.sn-product-sms-send').click();await ap.waitForFunction(()=>document.querySelector('.sn-product-sms-notice')?.textContent.includes('پذیرفته‌شده:')&&document.querySelector('.sn-product-sms-stop').hidden);
  ok((await state(admin.c)).provider_calls===callBefore+1,'separate confirmation sends the single definitely-unsent browser fixture');
  ok(!await ap.locator('.sn-product-sms-send').isVisible()&&!await ap.locator('.sn-product-sms-stop').isVisible(),'completed batch hides inactive send and stop controls');
  await ap.locator('#sn-invoice-sms-policy').screenshot({path:path.join(output,'product-sms-desktop.png')});
  fs.writeFileSync(path.join(output,'product241-browser-errors.json'),JSON.stringify({errors},null,2));
  if(errors.length)console.log('BROWSER_ERROR_META '+JSON.stringify(errors));
  ok(errors.length===0,'no uncaught browser JavaScript errors');
  const result={passed:true,version:'2.0.245',assertions:checks,actual_wordpress_cookie:true,actual_admin_http:true,actual_woocommerce_product_save:true,legacy_400_reproduced:true,json_large_form_passed:true,desktop_mobile:true,synthetic:true,production_touched:false,real_sms_sent:false};fs.writeFileSync(path.join(output,'product241-browser.json'),JSON.stringify(result,null,2));console.log('TOTAL '+checks);
 }finally{await browser.close();}
};
