'use strict';
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
module.exports=async function({playwright,jquery,channel}){
 const {chromium}=require(playwright),browser=await chromium.launch({headless:true,channel});let checks=0;
 const root=path.resolve(__dirname,'../..'),ok=(v,m)=>{assert(v,m);checks++;console.log('PASS '+m);};
 const seller=fs.readFileSync(path.join(root,'assets/js/public-seller.js'),'utf8'),manager=fs.readFileSync(path.join(root,'assets/js/public-manager.js'),'utf8');
 const sellerBody=seller.slice(seller.indexOf('  function loadSellerInvoices(){'),seller.indexOf('  window.snLoadSellerInvoices ='));
 const a=manager.indexOf('\tfunction renderInvoices(rows, hasMore, summary){'),b=manager.indexOf('\n  function ',a+10);assert(a>0&&b>a);
 const fixture={id:1,invoice_code:'SYN-100',status:'partial_paid',status_label:'پرداخت ناقص',customer_name:'Synthetic',payment_summary:{total:1000,paid:400,remaining:600,due:600,stage_no:2},stage_history:{available:true,stages:[{stage_no:1,stage_invoice_code:'SYN-100/1',stage_reference:'STG-1',amount:400,status:'paid'},{stage_no:2,stage_invoice_code:'SYN-100/2',stage_reference:'STG-2',amount:600,status:'pending_financial_approval'}]}};
 try{for(const width of [1440,390]){
  const context=await browser.newContext({viewport:{width,height:1000}});await context.route('**/*',r=>r.abort());const page=await context.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
  await page.setContent('<html dir="rtl"><body><input id="sn-seller-invoice-search"><div id="sn-invoices-loading"></div><div id="sn-invoices-list"></div><div id="sn-supervisor-invoices-list"></div></body></html>');await page.addScriptTag({path:jquery});
  await page.addScriptTag({path:path.join(root,'assets/js/payment-transactions.js')});
  await page.evaluate(f=>{window.__fixture=f;window.snInvoicePaymentButtons=()=>'';jQuery.post=(url,payload,done)=>{window.__payload=payload;done({success:true,invoices:[window.__fixture],total:1});return {always(fn){fn();}};};},fixture);
  const env='var $=jQuery,ajax="https://synthetic.invalid/ajax",nonce="synthetic",activeInvoiceTab="all",sellerInvoiceXhr=null,sellerInvoiceRequestKey="";function esc(v){return jQuery("<span>").text(v==null?"":String(v)).html();}function money(v){return String(v)+" تومان";}function updateInvoiceKpis(){}function amountOf(){return 1000;}';
  await page.addScriptTag({content:'(function(){'+env+sellerBody+'window.__loadSeller=loadSellerInvoices;'+manager.slice(a,b)+'window.__renderManager=renderInvoices;})();'});
  await page.evaluate(()=>{document.querySelector('#sn-seller-invoice-search').value='SYN-100/2';window.__loadSeller();window.__renderManager([window.__fixture],false,{});});
  ok(await page.evaluate(()=>window.__payload.q==='SYN-100/2'),'actual seller loader forwards exact stage alias '+width);
  for(const target of ['#sn-invoices-list','#sn-supervisor-invoices-list']){
   ok(await page.locator(target+' tbody tr').count()===1,'one canonical invoice row retained '+target+' '+width);
   ok((await page.locator(target+' summary').innerText()).includes('SYN-100/2'),'current alias visible without expanding '+target+' '+width);
   await page.locator(target+' summary').click();const text=(await page.locator(target).innerText()).replace(/[۰-۹]/g,c=>'۰۱۲۳۴۵۶۷۸۹'.indexOf(c));
   ok(text.includes('SYN-100/1')&&text.includes('SYN-100/2'),'all issued stages visible '+target+' '+width);
   ok(text.includes('پرداخت مرحله تأیید شده')&&text.includes('در انتظار تأیید مالی'),'stage receipt and Finance approval are distinct '+target+' '+width);
   ok(text.includes('فاکتور هنوز تسویه کامل نشده')&&text.includes('400 تومان')&&text.includes('600 تومان'),'stage amounts and incomplete purchase remain separate '+target+' '+width);
  }
  await page.evaluate(()=>{const h=window.__fixture.stage_history;h.stages[1].stage_invoice_code='<img src=x onerror="window.__xss=1">';document.querySelector('#sn-invoices-list').innerHTML=window.snPaymentTransactions.renderStages(h,{remaining:600,stage_no:2});});
  ok(await page.locator('#sn-invoices-list img').count()===0&&!await page.evaluate(()=>window.__xss),'stage code escaped in summary and detail '+width);
  await page.evaluate(()=>{document.querySelector('#sn-invoices-list').innerHTML=window.snPaymentTransactions.renderStages({available:false,stages:[]},{});});
  ok((await page.locator('#sn-invoices-list').innerText()).includes('دریافت مراحل کامل نشد'),'failed history is explicit rather than paid or empty '+width);
  ok(errors.length===0,'no runtime errors in actual list renderers '+width);await context.close();
 }}finally{await browser.close();}
 const result={passed:true,assertions:checks,synthetic:true,desktop_mobile:true,external_network_blocked:true};console.log('TOTAL '+checks);return result;
};
