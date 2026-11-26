'use strict';
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
module.exports=async function({playwright,jquery,baseline,channel}){
 const{chromium}=require(playwright),browser=await chromium.launch({headless:true,channel});let checks=0;
 const root=path.resolve(__dirname,'../..'),old=path.resolve(baseline);
 const ok=(v,m)=>{assert.ok(v,m);checks++;console.log('PASS '+m);};
 async function page(width){const c=await browser.newContext({viewport:{width,height:950}});await c.route('**/*',r=>r.abort());const p=await c.newPage();await p.setContent('<html dir="rtl"><body></body></html>');await p.addScriptTag({path:jquery});return{p,c};}
 async function catalog(p,source){
  await p.evaluate(()=>{document.body.innerHTML='<div id="sn-products-multi"><div class="sn-product-row"><select class="sn-product-select"><option value="">Select</option><option value="1">Allowed</option><option value="2" selected>Forbidden</option></select></div></div><div id="sn-converter-products-multi"><div class="sn-product-row"><select class="sn-converter-product-select" disabled><option value="">Select</option><option value="1">Allowed</option><option value="2">Forbidden</option></select></div></div>';
   window.snSalesCatalog={ajaxUrl:'https://synthetic.invalid/ajax',nonce:'synthetic-catalog'};window.__requests=[];window.__queue=[];
   jQuery.post=function(url,data){window.__requests.push(data);const d=jQuery.Deferred();window.__queue.push(d);return d;};
  });await p.addScriptTag({content:source});await p.waitForFunction(()=>window.__queue.length===1);
 }
 const reply={success:true,data:{products:[{id:1,discounts:[{id:'10000',amount:100,label:'Synthetic fixed 100'}],allow_custom_discount:true}]}};
 try{for(const width of[1440,390]){
  let{p,c}=await page(width);await catalog(p,fs.readFileSync(path.join(old,'assets/js/sales-catalog-staff.js'),'utf8'));
  await p.evaluate(r=>window.__queue[0].resolve(r),reply);
  ok(await p.locator('#sn-products-multi option[value="2"]').count()===1,'baseline leaves forbidden HTML option after authoritative catalog '+width);await c.close();
  ({p,c}=await page(width));const errors=[];p.on('pageerror',e=>errors.push(e.message));await catalog(p,fs.readFileSync(path.join(root,'assets/js/sales-catalog-staff.js'),'utf8'));
  ok(await p.locator('#sn-products-multi .sn-product-select').isDisabled(),'product choice waits for authoritative policy '+width);
  await p.evaluate(()=>{window.__queue[0].reject({status:400});});
  ok(await p.locator('#sn-products-multi .sn-catalog-retry').isVisible(),'HTTP failure offers explicit retry '+width);
  ok(await p.locator('#sn-products-multi .sn-product-select').isDisabled(),'failed catalog cannot silently show unrestricted choices '+width);
  await p.locator('#sn-products-multi .sn-catalog-retry').click();await p.waitForFunction(()=>window.__queue.length===2);await p.evaluate(r=>window.__queue[1].resolve(r),reply);
  ok(await p.locator('#sn-products-multi option[value="2"]').count()===0,'fresh response removes unauthorized product '+width);
  ok(await p.locator('#sn-products-multi .sn-product-select').inputValue()==='','unauthorized stale selection is cleared '+width);
  ok(await p.locator('#sn-products-multi .sn-product-select').isEnabled(),'successful retry restores catalog-owned control '+width);
  ok(await p.locator('#sn-converter-products-multi select').first().isDisabled(),'unrelated disabled control remains disabled '+width);
  await p.locator('#sn-products-multi .sn-product-select').selectOption('1');
  ok(await p.locator('#sn-products-multi .sn-catalog-discount-select option[value="10000"]').count()===1,'fixed discount is displayed for allowed product '+width);
  await p.locator('#sn-products-multi .sn-catalog-discount-select').selectOption('10000');
  ok(await p.evaluate(()=>window.snCatalogLineDiscount(jQuery('#sn-products-multi .sn-product-row')))===100,'fixed discount amount remains correct '+width);
  await p.locator('#sn-products-multi .sn-catalog-discount-select').selectOption('custom:invalid');await p.locator('#sn-products-multi .sn-catalog-custom-amount').fill('25');
  ok(await p.evaluate(()=>window.snCatalogSelections(jQuery('#sn-products-multi'))[0])==='custom:2500','optional custom discount still works '+width);
  await p.evaluate(()=>jQuery('#sn-products-multi').append('<div class="sn-product-row"><select class="sn-product-select"><option value="">Select</option><option value="1">Allowed</option><option value="2">Forbidden</option></select></div>'));
  await p.waitForFunction(()=>document.querySelectorAll('#sn-products-multi option[value="2"]').length===0);
  ok(await p.locator('#sn-products-multi .sn-product-row').count()===2,'new product row receives same policy '+width);
  ok(await p.evaluate(()=>window.__requests.every(r=>r.action==='sn_sales_catalog_staff'&&r.nonce==='synthetic-catalog')),'catalog requests preserve nonce contract '+width);
  await p.evaluate(()=>{jQuery(document.body).append('<button type="button" class="sn-tab" data-tab="new-invoice" id="catalog-open">Open invoice</button>');jQuery('#catalog-open').trigger('click');});
  await p.waitForFunction(()=>window.__queue.length===3);await p.evaluate(()=>{window.__queue[2].resolve({success:true,data:{products:[{id:1,discounts:[{id:'20000',amount:200,label:'Updated fixed'}],allow_custom_discount:false}]}});});
  ok(await p.locator('#sn-products-multi .sn-product-row').first().locator('option[value="20000"]').count()===1&&await p.locator('#sn-products-multi option[value="10000"]').count()===0,'opening form refreshes changed discount policy '+width);
  await p.evaluate(()=>jQuery('#catalog-open').trigger('click'));await p.waitForFunction(()=>window.__queue.length===4);await p.evaluate(()=>{window.__queue[3].resolve({success:true,data:{products:[]}});});
  ok(await p.locator('#sn-products-multi option[value="1"]').count()===0,'refreshed policy removes products from prior in-page cache '+width);
  ok(errors.length===0,'catalog has no browser errors '+width);await c.close();
  ({p,c}=await page(width));
  const source=fs.readFileSync(path.join(root,'assets/js/public-manager.js'),'utf8');const start=source.indexOf('  function openReceipt(id){'),end=source.indexOf('  function ensureHistoryModal(',start);assert(start>0&&end>start);
  await p.evaluate(()=>{window.snReceiptReview={mount:()=>{window.__mounts++;}};window.snPaymentTransactions={render:()=>'<p>Synthetic gateway</p>'};window.snReceiptFiles={urls:()=>[]};jQuery.post=(url,data,cb)=>{cb({success:true,transactions:[],transaction_summary:{independent:true,ready:false},stages:[],activities:[]});return{fail:()=>{}};};window.__mounts=0;});
  const fixture=`var ajax='https://synthetic.invalid/ajax',nonce='synthetic-finance';var state={page:1,rows:{1:{id:1,has_independent_payments:true,review_gateway_stage:true,can_approve_payment:true,can_reject_payment:false,customer_name:'<img src=x>',current_payment_stage:1}}};function loadFinance(){}function esc(x){return String(x||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}function ensureReceiptModal(){if(!document.getElementById('sn-fin-v2-receipt-modal'))document.body.insertAdjacentHTML('beforeend','<div id="sn-fin-v2-receipt-modal"><div id="sn-fin-v2-receipt-body"></div></div>');}function financeTag(){return'';}function receiptGallery(){return'<p>Gateway</p>';}`;
  await p.addScriptTag({content:'(function($){'+fixture+source.slice(start,end)+'window.__openFinance=openReceipt;window.__financeState=state;})(jQuery);'});
  await p.evaluate(()=>window.__openFinance(1));
  ok(await p.evaluate(()=>window.__mounts===0),'verified gateway avoids manual receipt controller '+width);
  ok(await p.locator('.sn-fin-v2-modal-approve').isEnabled(),'gateway approval not disabled by historical rejected manual receipt '+width);
  ok(await p.locator('#sn-fin-v2-receipt-body img').count()===0,'legacy Finance route still escapes customer text '+width);
  await p.evaluate(()=>{window.__financeState.rows[1].review_gateway_stage=false;window.__openFinance(1);});
  ok(await p.evaluate(()=>window.__mounts===1),'active manual receipt retains independent review '+width);
  const a=source.indexOf('  function approveOne(id, cb){'),b=source.indexOf('  function rejectOne(',a);assert(a>0&&b>a);
  await p.addScriptTag({content:'(function(){var state=window.__financeState,ajax="https://synthetic.invalid/ajax",nonce="synthetic-finance";function notify(){}function loadFinance(){}function financePost(url,data,done){window.__approvalPayload=data;done({success:true});return{fail:function(){}};}'+source.slice(a,b)+'window.__approveFinance=approveOne;})();'});
  await p.evaluate(()=>window.__approveFinance(1));
  ok(await p.evaluate(()=>window.__approvalPayload.payment_stage_no===1&&window.__approvalPayload.nonce==='synthetic-finance'),'Finance request pins displayed stage and nonce '+width);
  await p.evaluate(()=>{window.__financeState.rows[1].current_payment_stage=2;window.__approveFinance(1);});
  ok(await p.evaluate(()=>window.__approvalPayload.payment_stage_no===2),'newly displayed stage is sent explicitly '+width);await c.close();
 }}finally{await browser.close();}
 const result={passed:true,assertions:checks,synthetic:true,production_touched:false,desktop_mobile:true,external_network_blocked:true};console.log('TOTAL '+checks);return result;
};
