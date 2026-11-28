'use strict';
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
module.exports=async function({playwright,jquery,channel,screenshots}){
 const {chromium}=require(playwright),browser=await chromium.launch({headless:true,channel});let checks=0;
 const root=path.resolve(__dirname,'../..'),ok=(v,m)=>{assert(v,m);checks++;console.log('PASS '+m);};
 const seller=fs.readFileSync(path.join(root,'assets/js/public-seller.js'),'utf8'),manager=fs.readFileSync(path.join(root,'assets/js/public-manager.js'),'utf8');
 const sellerBody=seller.slice(seller.indexOf('  function loadSellerInvoices(){'),seller.indexOf('  window.snLoadSellerInvoices ='));
 const a=manager.indexOf('\tfunction renderInvoices(rows, hasMore, summary){'),b=manager.indexOf('\n  function ',a+10);assert(a>0&&b>a);
 const c=manager.indexOf('  function renderRows(rows){'),d=manager.indexOf('  function renderPager(){',c);assert(c>0&&d>c);
 const fixture={id:1,invoice_code:'SYN-100',status:'partial_paid',status_label:'پرداخت ناقص',customer_name:'Synthetic',can_approve_payment:true,remaining_amount_value:300,invoice_total_amount_fmt:'1000 تومان',current_payment_amount_fmt:'300 تومان',payment_summary:{total:1000,paid:700,remaining:300,due:300,stage_no:3},stage_history:{available:true,stages:[{stage_no:1,stage_invoice_code:'SYN-100/1',stage_reference:'STG-1',amount:400,status:'paid'},{stage_no:2,stage_invoice_code:'SYN-100/2',stage_reference:'STG-2',amount:300,status:'approved'},{stage_no:3,stage_invoice_code:'SYN-100/3',stage_reference:'STG-3',amount:300,status:'pending_financial_approval'}]}};
 try{for(const width of [1440,390]){
  const context=await browser.newContext({viewport:{width,height:1000}});await context.route('**/*',r=>r.abort());const page=await context.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
  await page.setContent('<html dir="rtl"><head><style>body{font-family:Arial}table{border-collapse:collapse;width:100%}td,th{border:1px solid #ddd;padding:8px}.sn-table-wrap{overflow:auto;max-width:100%}.sn-invoice-stage-row{background:#f2f6fa}bdi{display:inline-block}small{display:block}</style></head><body><input id="sn-seller-invoice-search"><div id="sn-invoices-loading"></div><h2>فروشنده</h2><div id="sn-invoices-list"></div><h2>تیم</h2><div id="sn-supervisor-invoices-list"></div><h2>مالی</h2><div id="sn-financial-list"></div></body></html>');await page.addScriptTag({path:jquery});
  await page.addScriptTag({path:path.join(root,'assets/js/payment-transactions.js')});
  await page.evaluate(f=>{window.__fixture=f;window.snInvoicePaymentButtons=()=>'<button class="synthetic-action">مدیریت پرداخت</button>';jQuery.post=(url,payload,done)=>{window.__payload=payload;done({success:true,invoices:[window.__fixture],total:1});return {always(fn){fn();}};};},fixture);
  const env='var $=jQuery,ajax="https://synthetic.invalid/ajax",nonce="synthetic",activeInvoiceTab="all",sellerInvoiceXhr=null,sellerInvoiceRequestKey="",state={total:1};function esc(v){return jQuery("<span>").text(v==null?"":String(v)).html();}function money(v){return String(v)+" تومان";}function updateInvoiceKpis(rows){window.__kpiCount=rows.length;}function amountOf(){return 1000;}function fa(v){return String(v);}function financeTag(){return "";}function statusClass(){return "";}function rowActions(){return "<button class=synthetic-action>مدیریت پرداخت</button>";}function renderPager(){}function updateBulk(){}';
  await page.addScriptTag({content:'(function(){'+env+sellerBody+'window.__loadSeller=loadSellerInvoices;'+manager.slice(a,b)+'window.__renderManager=renderInvoices;'+manager.slice(c,d)+'window.__renderFinance=renderRows;window.__renderAll=function(){__loadSeller();__renderManager([__fixture],false,{});__renderFinance([__fixture]);};})();'});
  await page.evaluate(()=>{document.querySelector('#sn-seller-invoice-search').value='SYN-100/3';window.__renderAll();});
  ok(await page.evaluate(()=>window.__payload.q==='SYN-100/3'),'seller loader forwards exact stage alias '+width);
  for(const target of ['#sn-invoices-list','#sn-supervisor-invoices-list','#sn-financial-list']){
   ok(await page.locator(target+' [data-sn-invoice-main]').count()===1,'one canonical invoice retained '+target+' '+width);
   ok(await page.locator(target+' [data-sn-stage-row]').count()===3,'three unsettled stages are separate rows '+target+' '+width);
   ok(await page.locator(target+' tbody tr').count()===4,'main plus stages without duplicate parents '+target+' '+width);
   ok(await page.locator(target+' details').count()===0,'unsettled stages need no expand action '+target+' '+width);
   const text=(await page.locator(target).innerText()).replace(/[۰-۹]/g,c=>'۰۱۲۳۴۵۶۷۸۹'.indexOf(c));
   ok([1,2,3].every(n=>text.includes('SYN-100/'+n)),'all aliases visible immediately '+target+' '+width);
   ok(text.includes('پرداخت مرحله تأیید شده')&&text.includes('در انتظار تأیید مالی'),'stage confirmation remains distinct '+target+' '+width);
   const stageText=(await page.locator(target+' [data-sn-stage-row]').allTextContents()).join(' ').replace(/[۰-۹]/g,c=>'۰۱۲۳۴۵۶۷۸۹'.indexOf(c));
   ok(stageText.includes('400 تومان')&&stageText.includes('300 تومان')&&!stageText.includes('1000 تومان'),'stage amounts never repeat purchase total '+target+' '+width);
   ok(await page.locator(target+' [data-sn-stage-row] :is(input,button,select,form)').count()===0,'no duplicate payment/bulk actions '+target+' '+width);
   ok(await page.locator(target+' .synthetic-action').count()===1,'financial action exists only on parent '+target+' '+width);
  }
  ok(await page.locator('#sn-financial-list .sn-fin-v2-select').count()===1,'Finance bulk selection counts purchase once '+width);
  ok(await page.evaluate(()=>window.__kpiCount===1),'team KPI receives one canonical record '+width);
  ok((await page.locator('.sn-invoice-list-meta').innerText()).includes('1 از 1'),'seller pagination remains invoice-based '+width);
  if(screenshots){fs.mkdirSync(screenshots,{recursive:true});await page.screenshot({path:path.join(screenshots,'stage-case-'+(width===1440?'desktop':'mobile')+'.png'),fullPage:true});}
  await page.evaluate(()=>{__fixture.payment_summary.remaining=0;__fixture.remaining_amount_value=0;__fixture.status='paid';__fixture.stage_history.stages.forEach(s=>s.status='paid');__renderAll();});
  for(const target of ['#sn-invoices-list','#sn-supervisor-invoices-list','#sn-financial-list']){
   ok(await page.locator(target+' tbody tr').count()===1,'settled purchase collapses to one row '+target+' '+width);
   ok(await page.locator(target+' details').count()===1,'settled purchase retains expandable stages '+target+' '+width);
   await page.locator(target+' summary').click();ok((await page.locator(target+' details').innerText()).includes('SYN-100/3'),'settled stage detail remains accessible '+target+' '+width);
  }
  await page.evaluate(()=>{const h=__fixture.stage_history;h.stages[1].stage_invoice_code='<img src=x onerror="window.__xss=1">';h.stages[1].stage_reference='<script>bad()</script>';document.querySelector('#sn-invoices-list').innerHTML='<table><tbody>'+snPaymentTransactions.renderStageRows(h,{remaining:300},8)+'</tbody></table>'+snPaymentTransactions.renderStages(h,{remaining:0});});
  ok(await page.locator('#sn-invoices-list img,#sn-invoices-list script').count()===0&&!await page.evaluate(()=>window.__xss),'aliases/references escaped in both presentations '+width);
  for(const value of [null,'',false,true,'NaN','Infinity',300])ok(await page.evaluate(v=>snPaymentTransactions.renderStages(__fixture.stage_history,{remaining:v})===''&&snPaymentTransactions.renderStageRows(__fixture.stage_history,{remaining:v},8).includes('data-sn-stage-row'),value),'unknown/positive balance never appears settled '+String(value)+' '+width);
  await page.evaluate(()=>{document.querySelector('#sn-invoices-list').innerHTML=snPaymentTransactions.renderStages({available:false,stages:[]},{});});
  ok((await page.locator('#sn-invoices-list').innerText()).includes('دریافت مراحل کامل نشد'),'failed history is explicit '+width);
  ok(await page.evaluate(()=>snPaymentTransactions.renderStageRows({available:true,stages:[]},{remaining:0},8)===''),'no stage invented for empty history '+width);
  ok(errors.length===0,'no runtime errors across seller/team/Finance renderers '+width);await context.close();
 }}finally{await browser.close();}
 const result={passed:true,assertions:checks,synthetic:true,desktop_mobile:true,external_network_blocked:true,unsettled_rows:true,settled_details:true,canonical_counts:true,finance_renderer:true};console.log('TOTAL '+checks);return result;
};
