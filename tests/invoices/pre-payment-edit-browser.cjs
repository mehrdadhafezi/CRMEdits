/* Real WordPress cookies/AJAX + production editor script, synthetic isolated fixture only. */
'use strict';
const fs=require('node:fs'),assert=require('node:assert/strict');
const {chromium}=require('/tooling/node_modules/playwright');
const root='/evidence',fixture=JSON.parse(fs.readFileSync(root+'/fixture.json','utf8'));
const privateState=JSON.parse(fs.readFileSync(root+'/private-state.json','utf8'));
const origin='http://prepay-web';let checks=0;
function ok(value,label){assert(value,label);++checks;console.log('PASS '+label);}
async function login(browser,user){
 const context=await browser.newContext({viewport:{width:1440,height:1000}}),page=await context.newPage();
 await page.goto(origin+'/wp-login.php');await page.locator('#user_login').fill(user.username);await page.locator('#user_pass').fill(user.password);
 await Promise.all([page.waitForNavigation(),page.locator('#wp-submit').click()]);await page.goto(origin+'/?sn_prepayment_fixture=1');return {context,page};
}
const modal='#sn-prepayment-edit-modal';
async function settled(page){await page.waitForFunction(selector=>{const button=document.querySelector(selector+' .sn-edit-save');return button&&!button.disabled;},modal);}
async function open(page,id){await page.locator('.sn-edit-invoice[data-invoice-id="'+id+'"]').click();await page.locator(modal).waitFor();await settled(page);}
async function close(page){await page.locator(modal+' .sn-modal-x').click();await page.locator(modal).waitFor({state:'detached'});}
(async()=>{
 const browser=await chromium.launch({headless:true,args:['--no-sandbox']});
 try{
  const {context,page}=await login(browser,fixture.owner),errors=[],alerts=[];let saves=0;
  page.on('pageerror',error=>errors.push(error.message));page.on('dialog',async dialog=>{alerts.push(dialog.message());await dialog.dismiss();});
  page.on('request',request=>{if(request.url().includes('admin-ajax.php')&&/name="edit_mode"\r?\n\r?\nsave\r?\n/.test(request.postData()||''))saves++;});
  await open(page,fixture.browser_invoice);
  ok(alerts.length===0,'discounted invoice opens without the reported alert');
  ok(await page.locator(modal+' .sn-edit-discount').inputValue()==='20000','saved discount is selected on open');
  ok(await page.locator(modal+' .sn-edit-product').isEnabled(),'owner can change the product');
  ok(await page.locator(modal+' .sn-edit-qty').isEnabled(),'owner can change quantity');
  ok(await page.locator(modal+' .sn-edit-reprice').isEnabled()&&!await page.locator(modal+' .sn-edit-reprice').isChecked(),'owner explicitly chooses whether to refresh frozen prices');
  await page.locator(modal+' .sn-edit-reprice').check();await settled(page);
  ok((await page.locator(modal+' .sn-edit-totals').innerText()).includes('۸۰۰'),'explicit price refresh is previewed before saving');
  await page.locator(modal+' .sn-edit-reprice').uncheck();await settled(page);
  ok((await page.locator(modal+' .sn-edit-product option').allTextContents()).some(text=>text.includes('<img')),'synthetic stored markup reaches the product option');
  ok(await page.locator(modal+' img').count()===0&&await page.evaluate(()=>!window.prepaymentXss),'stored product markup remains plain text without script execution');
  await page.locator(modal+' .sn-edit-save').focus();await page.keyboard.press('Tab');
  ok(await page.locator(modal+' .sn-modal-x').evaluate(button=>document.activeElement===button),'keyboard focus stays within the edit dialog');
  ok(await page.locator(modal+' .sn-edit-totals').innerText().then(text=>text.includes('۸۰۰')),'initial signed preview shows discounted net amount');
  await page.locator(modal+' .sn-edit-due').fill('۳۰۰');
  ok(await page.locator(modal+' .sn-edit-save').isDisabled(),'editing invalidates the preceding preview immediately');await settled(page);
  await page.locator(modal+' .sn-edit-save').evaluate(button=>{button.click();button.click();});await page.locator(modal).waitFor({state:'detached'});
  ok(saves===1,'double-click submits exactly one save');
  await open(page,fixture.browser_invoice);
  ok(await page.locator(modal+' .sn-edit-due').inputValue()==='300','localized first-stage amount persists through real AJAX');
  ok(await page.locator(modal+' .sn-edit-discount').inputValue()==='20000','amount-only edit preserves the issued discount');
  await page.locator(modal+' .sn-edit-product').selectOption(String(fixture.products[1]));
  ok(await page.locator(modal+' .sn-edit-discount').inputValue()==='','changing product resets the preceding product discount');
  await page.locator(modal+' .sn-edit-discount').selectOption('30000');await page.locator(modal+' .sn-edit-due').fill('500');await settled(page);
  ok((await page.locator(modal+' .sn-edit-totals').innerText()).includes('۱٬۷۰۰'),'new product net is calculated by server with its authorized discount');
  await page.screenshot({path:root+'/prepayment-edit-desktop.png',fullPage:true});
  const productSaveResponse=page.waitForResponse(response=>response.url().includes('admin-ajax.php')&&/name="edit_mode"\r?\n\r?\nsave\r?\n/.test(response.request().postData()||''));
  await page.locator(modal+' .sn-edit-save').click();const productSave=await (await productSaveResponse).json();
  ok(productSave.success===true,'native product save succeeds: '+String(productSave.message||''));
  await page.locator(modal).waitFor({state:'detached'});await open(page,fixture.browser_invoice);
  ok(await page.locator(modal+' .sn-edit-product').inputValue()===String(fixture.products[1]),'product edit persists on original invoice');
  ok(await page.locator(modal+' .sn-edit-discount').inputValue()==='30000','new selected discount persists');
  await page.locator(modal+' .sn-edit-due').fill('0');await page.waitForFunction(selector=>document.querySelector(selector+' .sn-edit-message').textContent.includes('مبلغ مرحله باید'),modal);
  ok(await page.locator(modal+' .sn-edit-save').isDisabled(),'invalid installment cannot be saved');
  await page.locator(modal+' .sn-edit-due').fill('500');await settled(page);
  await page.locator(modal+' .sn-edit-qty').fill('2');await settled(page);
  ok((await page.locator(modal+' .sn-edit-totals').innerText()).includes('۳٬۴۰۰'),'quantity edits update server-priced total and per-unit discount');
  await close(page);await open(page,fixture.browser_invoice);
  ok(await page.locator(modal+' .sn-edit-qty').inputValue()==='1','cancelled quantity preview creates no item change');
  await page.locator(modal+' .sn-edit-discount').evaluate(select=>{select.add(new Option('Synthetic forged discount','99999'));select.value='99999';select.dispatchEvent(new Event('change',{bubbles:true}));});
  await page.waitForFunction(selector=>document.querySelector(selector+' .sn-edit-message').textContent.includes('مجاز نیست'),modal);
  ok(await page.locator(modal+' .sn-edit-save').isDisabled(),'forged client discount is rejected by native server');
  await close(page);
  const rejected=await context.request.post(origin+'/wp-admin/admin-ajax.php',{form:{action:'sn_invoice_pre_payment_edit',invoice_id:String(fixture.browser_invoice),edit_mode:'read',nonce:'invalid'}});
  ok((await rejected.json()).success===false,'real invalid nonce request is denied');
  await page.setViewportSize({width:390,height:844});await open(page,fixture.browser_invoice);
  await page.screenshot({path:root+'/prepayment-edit-mobile.png',fullPage:true});
  ok(await page.locator(modal+' .sn-modal-card').evaluate(card=>{const r=card.getBoundingClientRect();return r.left>=0&&r.right<=innerWidth+1;}),'mobile dialog stays inside viewport');
  await page.keyboard.press('Escape');await page.locator(modal).waitFor({state:'detached'});
  ok(await page.locator('.sn-edit-invoice[data-invoice-id="'+fixture.browser_invoice+'"]').evaluate(button=>document.activeElement===button),'Escape restores focus to the original button');
  const lockedDialog=page.waitForEvent('dialog');await page.locator('.sn-edit-invoice[data-invoice-id="'+fixture.browser_locked_invoice+'"]').click();
  ok((await lockedDialog).message().includes('درخواست پرداخت'),'outstanding payment request remains locked in actual UI');
  const other=await login(browser,fixture.other);let otherMessage='';other.page.on('dialog',async dialog=>{otherMessage=dialog.message();await dialog.dismiss();});
  const otherDialog=other.page.waitForEvent('dialog');await other.page.locator('.sn-edit-invoice[data-invoice-id="'+fixture.browser_invoice+'"]').click();await otherDialog;
  ok(otherMessage.includes('ویرایش فقط'),'unrelated logged-in seller cannot open invoice editor');await other.context.close();
  const sender=await login(browser,{username:'synthetic_admin',password:privateState.admin_password});await open(sender.page,fixture.browser_sender_invoice);
  ok(await sender.page.locator(modal+' .sn-edit-product').isDisabled()&&await sender.page.locator(modal+' .sn-edit-qty').isDisabled()&&await sender.page.locator(modal+' .sn-edit-discount').isDisabled(),'last sender without ownership cannot edit product/quantity/discount');
  ok(await sender.page.locator(modal+' .sn-edit-reprice').isDisabled(),'nonowner sender cannot request price refresh');
  await close(sender.page);await sender.context.close();
  ok(errors.length===0,'production editor has no uncaught browser errors');
  fs.writeFileSync(root+'/browser-summary.json',JSON.stringify({passed:true,checks,errors,synthetic:true,viewports:['1440x1000','390x844']},null,2));
  console.log('PREPAYMENT_BROWSER_TOTAL '+checks);
 }finally{await browser.close();}
})().catch(error=>{console.error(error.message);process.exitCode=1;});
