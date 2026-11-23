/* Actual Payment Management modal, WordPress cookies/AJAX and multipart upload. */
'use strict';
const fs=require('node:fs'),assert=require('node:assert/strict');
const {chromium}=require('/tooling/node_modules/playwright');
const root='/evidence',fixture=JSON.parse(fs.readFileSync(root+'/fixture.json','utf8'));
const origin='http://tracking-web',modal='#sn-unified-invoice-modal';let checks=0;
function ok(v,label){assert(v,label);++checks;console.log('PASS '+label);}
(async()=>{
 const browser=await chromium.launch({headless:true,args:['--no-sandbox']});
 try{
  const context=await browser.newContext({viewport:{width:1440,height:1000}}),page=await context.newPage(),errors=[];
  page.on('pageerror',error=>errors.push(error.message));
  await page.goto(origin+'/wp-login.php');await page.locator('#user_login').fill(fixture.owner.username);await page.locator('#user_pass').fill(fixture.owner.password);await Promise.all([page.waitForNavigation(),page.locator('#wp-submit').click()]);
  await page.goto(origin+'/?sn_tracking_fixture=1');await page.locator('.sn-unified-state-open').click();
  await page.getByRole('button',{name:'ثبت واریزی مشتری',exact:true}).click();
  const row1=page.locator(modal+' .sn-batch-row').nth(0),submit=page.locator(modal+' .sn-batch-submit');
  await page.waitForFunction(selector=>{const b=document.querySelector(selector+' .sn-batch-submit');return b&&!b.disabled;},modal);
  ok(await row1.locator('[name=manual_tracking_number]').isVisible(),'optional tracking field appears for receipt upload in actual management');
  ok(await row1.locator('[name=manual_tracking_number]').getAttribute('maxlength')==='64','full reference field supports 64 characters');
  await row1.locator('[name=amount]').fill('100');
  const png=await page.evaluate(()=>{const c=document.createElement('canvas');c.width=32;c.height=32;c.getContext('2d').fillRect(0,0,32,32);return c.toDataURL('image/png').split(',')[1];});
  await row1.locator('input[type=file]').setInputFiles({name:'synthetic-receipt.png',mimeType:'image/png',buffer:Buffer.from(png,'base64')});
  await submit.click();ok(await page.locator(modal+' .sn-batch-inline-review').isVisible(),'blank optional tracking allows review without requiring bank number');
  await row1.locator('[name=manual_tracking_number]').fill('۰۰۱۲۳۴۵۶۷۸۹۰');
  ok(await page.locator(modal+' .sn-batch-inline-review').count()===0,'editing reference invalidates the previous confirmation');
  await page.locator(modal+' .sn-batch-add').click();const row2=page.locator(modal+' .sn-batch-row').nth(1);
  await row2.locator('[name=entry_mode]').selectOption('info');await row2.locator('[name=amount]').fill('200');await row2.locator('[name=card_from]').fill('1234');
  for(const [part,value] of [['year','1405'],['month','08'],['day','01'],['hour','10'],['minute','00']])await row2.locator('[data-date-part='+part+']').selectOption(value);
  ok(await row2.locator('[name=manual_tracking_number]').isVisible(),'same field appears for transfer-information mode');
  await row2.locator('[name=manual_tracking_number]').fill('<img src=x>');await submit.click();
  ok(await page.locator(modal+' .sn-batch-message').innerText().then(text=>text.includes('شماره پیگیری معتبر')),'browser rejects malformed reference before sending any upload');
  await row2.locator('[name=manual_tracking_number]').fill('AB_0002');
  await page.screenshot({path:root+'/tracking-desktop.png',fullPage:true});
  await page.setViewportSize({width:390,height:844});await row2.locator('[name=manual_tracking_number]').scrollIntoViewIfNeeded();await page.screenshot({path:root+'/tracking-mobile.png',fullPage:true});
  ok(await page.locator(modal+' .sn-modal-card').evaluate(card=>{const r=card.getBoundingClientRect();return r.left>=0&&r.right<=innerWidth+1;}),'mobile management dialog remains inside viewport');
  await submit.click();ok(await page.locator(modal+' .sn-batch-inline-review').count()===1,'valid transfer inputs reach final confirmation: '+await page.locator(modal+' .sn-batch-message').innerText());const response=page.waitForResponse(r=>r.url().includes('admin-ajax.php')&&r.request().method()==='POST');await submit.click();
  const result=await (await response).json();ok(result.success===true,'receipt/info batch saves through actual multipart AJAX: '+String(result.message||''));
  await page.locator(modal+' .sn-batch-finish').click();await page.getByRole('button',{name:'تراکنش‌ها و تاریخچه مالی',exact:true}).click();
  await page.waitForFunction(selector=>document.querySelector(selector).textContent.includes('001234567890'),modal);
  ok((await page.locator(modal).innerText()).includes('شماره پیگیری'),'authorized receipt detail shows full saved bank reference');
  const nonce=await page.evaluate(()=>window.snAjax.nonce);
  for(const q of ['۰۰۱۲۳۴۵۶۷۸۹۰','AB_0002']){
   const response=await context.request.post(origin+'/wp-admin/admin-ajax.php',{form:{action:'sn_seller_invoices',nonce,q,tab:'all'}}),result=await response.json(),data=result.data||result,rows=data.invoices||data.items||[];
   ok(result.success===true&&rows.filter(row=>Number(row.id)===fixture.browser_invoice).length===1,'real invoice search finds either independent transfer reference without duplicate invoices');
  }
  const none=await context.request.post(origin+'/wp-admin/admin-ajax.php',{form:{action:'sn_seller_invoices',nonce,q:'NO-SUCH-SYNTHETIC-REFERENCE',tab:'all'}});const noResult=await none.json(),data=noResult.data||noResult;
  ok((data.invoices||data.items||[]).length===0,'unmatched reference returns no invoice');
  ok(errors.length===0,'production payment scripts have no uncaught browser errors');
  fs.writeFileSync(root+'/tracking-browser-summary.json',JSON.stringify({passed:true,synthetic:true,checks,errors,viewports:['1440x1000','390x844']},null,2));console.log('TRACKING_BROWSER_TOTAL '+checks);
 }finally{await browser.close();}
})().catch(error=>{console.error(error.message);process.exitCode=1;});
