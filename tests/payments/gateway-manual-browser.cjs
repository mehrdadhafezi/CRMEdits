'use strict';
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
module.exports=async function run({base,fixture,output,transport='native WordPress HTTP',channel}){
 const modulePath=process.env.SN_PLAYWRIGHT_MODULE;
 const {chromium}=modulePath.endsWith('.mjs')?await import(require('node:url').pathToFileURL(modulePath).href):require(modulePath);let checks=0;const errors=[];
 const ok=(v,label)=>{assert(v,label);checks++;console.log('PASS '+label);};
 const browser=await chromium.launch({headless:true,...(channel?{channel}:{}),args:['--disable-dev-shm-usage']});
 const contexts=[];fs.mkdirSync(output,{recursive:true});
 const unwrap=r=>r&&r.data&&typeof r.data==='object'?{...r,...r.data}:r;
 try{
  for(const [key,width,height]of[['browser',1440,1000],['mobile',390,844]]){
   const context=await browser.newContext({viewport:{width,height}});contexts.push(context);
   await context.route('**/*',route=>{const u=new URL(route.request().url());return u.origin===new URL(base).origin||u.protocol==='data:'?route.continue():route.abort();});
   await context.request.get(base+'/wp-login.php');const u=fixture.users.seller;
   await context.request.post(base+'/wp-login.php',{form:{log:u.login,pwd:u.password,testcookie:'1',redirect_to:base+'/?page_id='+fixture.page_id},maxRedirects:0});
   const session=await(await context.request.get(base+'/?sn_test_nonce=1')).json();ok(session.success&&session.user_id===u.id,'native WordPress cookie login '+key);
   const post=async(action,data={})=>unwrap(await(await context.request.post(base+'/wp-admin/admin-ajax.php',{form:{action,nonce:session.nonce,...data}})).json());
   const invoice=fixture.cases[key];let state=await post('sn_payment_management_state',{invoice_id:String(invoice)});
   ok(state.success&&state.allowed_actions.switch_to_manual&&!state.allowed_actions.register_payment,'server blocks receipt before explicit choice '+key);
   const denied=await post('sn_payment_gateway_check',{invoice_id:String(invoice),operation:'switch_to_manual',nonce:''});ok(!denied.success,'HTTP missing nonce cannot change method '+key);
   const page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));
   await page.goto(base+'/?page_id='+fixture.page_id,{waitUntil:'domcontentloaded'});
   if(width<768)await page.locator('.sn-mobile-sidebar-toggle').first().click();
   await page.locator('[data-tab="invoices"]').first().click();await page.locator('.sn-invoice-status-tabs [data-status="all"]').first().click();
   const trigger=page.locator('.sn-unified-state-open[data-invoice-id="'+invoice+'"]');await trigger.first().click();
   const modal=page.locator('#sn-unified-invoice-modal');await modal.getByRole('button',{name:'انتخاب واریز دستی',exact:true}).waitFor();
   ok(await modal.evaluate(n=>getComputedStyle(n).direction==='rtl'),'RTL payment dialog '+key);
   ok(await page.evaluate(()=>!window.__gwXss),'synthetic customer markup remains escaped '+key);
   await page.screenshot({path:path.join(output,'gateway-before-'+key+'.png'),fullPage:true});
   await modal.getByRole('button',{name:'انتخاب واریز دستی',exact:true}).click();
   ok((await modal.innerText()).includes('لغو بانکی'),'manual-method review explains the live bank request '+key);
   await modal.getByRole('button',{name:'تأیید انتخاب واریز دستی',exact:true}).click();
   await modal.getByRole('button',{name:'ادامه و ثبت فیش',exact:true}).waitFor({timeout:30000});
   state=await post('sn_payment_management_state',{invoice_id:String(invoice)});ok(state.allowed_actions.register_payment&&Number(state.finance.available_to_register)===1980000,'HTTP method selection restores exact receipt capacity '+key);
   await modal.getByRole('button',{name:'ادامه و ثبت فیش',exact:true}).click();
   const form=modal.locator('#sn-ux-payment-inline');await form.locator('.sn-batch-row').waitFor();
   ok(await form.locator('input[type="file"]').isVisible(),'real receipt form opens '+key);
   // Actual multipart parsing and MIME validation run in PHP, without an upload mock.
   const rejected=unwrap(await(await context.request.post(base+'/wp-admin/admin-ajax.php',{multipart:{action:'sn_submit_manual_payment',nonce:session.nonce,invoice_code:fixture.batch+'-'+key,entry_mode:'receipt',amount:'1980000','receipt[]':{name:'synthetic-fake.php',mimeType:'image/png',buffer:Buffer.from('<?php echo "synthetic";')}}})).json());
   ok(!rejected.success,'fake image/PHP multipart rejected after selection '+key);
   await form.locator('[name="amount"]').fill('1980000');
   const png=Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=','base64');
   await form.locator('input[type="file"]').setInputFiles({name:'synthetic-receipt-'+key+'.png',mimeType:'image/png',buffer:png});
   await form.locator('.sn-batch-submit').click();await form.getByRole('button',{name:'تأیید نهایی و ارسال برای مالی',exact:true}).click();
   await page.waitForFunction(()=>document.querySelector('.sn-batch-message.is-success'),null,{timeout:30000});
   state=await post('sn_payment_management_state',{invoice_id:String(invoice)});
   ok(Number(state.finance.pending)===1980000&&Number(state.finance.approved)===0,'native multipart upload creates pending proof without financial credit '+key);
   await page.screenshot({path:path.join(output,'gateway-receipt-'+key+'.png'),fullPage:true});
   const view=await post('sn_receipt_review',{invoice_id:String(invoice),operation:'read'});const manual=(view.payments||[]).filter(p=>!p.is_gateway);
   ok(manual.length===1&&manual[0].receipt_urls.length===1&&manual[0].status==='pending','uploaded proof belongs to exactly one manual transfer '+key);
   const image=await context.request.get(manual[0].receipt_urls[0]);ok(image.ok()&&image.headers()['content-type'].startsWith('image/'),'uploaded synthetic receipt is retrievable '+key);
   await modal.getByRole('button',{name:'بازگشت به وضعیت فاکتور',exact:true}).click();
   await modal.getByRole('button',{name:'تراکنش‌ها و تاریخچه مالی',exact:true}).click();
   await modal.locator('.sn-review-selected').waitFor();
   const choice=modal.locator('.sn-review-sidebar select');await choice.selectOption(String(fixture.payments[key]));
   ok((await modal.innerText()).includes('فایل فیش دستی ندارد')&&!(await modal.innerText()).includes('برای این واریز فایل فیش ثبت نشده'),'gateway intent is not presented as a missing manual receipt '+key);
   await page.screenshot({path:path.join(output,'gateway-history-'+key+'.png'),fullPage:true});
   await page.close();await context.close();
  }
  ok(errors.length===0,'no uncaught payment dialog browser errors');
  const summary={passed:true,synthetic:true,version:'2.0.240',assertions:checks,transport,actual_multipart_upload_tested:true,real_wordpress_auth_cookie:true,chromium:browser.version(),production_touched:false};
  fs.writeFileSync(path.join(output,'gateway-manual-browser.json'),JSON.stringify(summary,null,2)+'\n');console.log('TOTAL '+checks);return summary;
 }finally{for(const c of contexts)await c.close().catch(()=>{});await browser.close();}
};
if(require.main===module){
 const root=process.env.SN_APPROVAL_ROOT;if(process.env.SN_APPROVAL_ALLOW_SYNTHETIC!=='YES'||!/^\/tmp\/sn-approval-/.test(root||''))throw Error('Disposable native HTTP sandbox required');
 module.exports({base:'http://127.0.0.1:8877',fixture:JSON.parse(fs.readFileSync(root+'/gateway240-fixture.json')),output:process.env.SN_APPROVAL_OUTPUT+'/gateway-browser'}).catch(e=>{console.error(e.stack);process.exitCode=1;});
}
