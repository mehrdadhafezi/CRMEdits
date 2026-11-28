'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
module.exports=async function({playwright,channel}){
 const {chromium}=require(playwright),browser=await chromium.launch({headless:true,channel});let checks=0;
 const source=fs.readFileSync(path.resolve(__dirname,'../../assets/js/receipt-review.js'),'utf8');
 const ok=(v,s)=>{assert.ok(v,s);checks++;console.log('PASS '+s);};
 try{for(const width of[1440,390]){
  const context=await browser.newContext({viewport:{width,height:950}});await context.route('**/*',r=>r.abort());const page=await context.newPage();
  await page.setContent('<html dir="rtl"><body><main id="review"></main></body></html>');
  await page.evaluate(()=>{
   window.__calls=[];window.__xss=0;window.snAjax={nonce:'synthetic-finance'};
   window.__data={invoice_code:'SYN-F244',invoice:{customer_name:'<img src=x onerror="window.__xss=1">',customer_phone:'00000000000',seller_name:'Synthetic',stage_no:1},totals:{total:5970000,paid:5000000,remaining:970000,due:970000},payments:[{id:1,transaction_code:'PAY-1',amount:5000000,status:'approved',stage_no:1,status_label:'تأیید شده',review:{revision:2,document_revision:1},proof:{},actions:{},history:[]},{id:2,transaction_code:'PAY-2',amount:970000,status:'pending',stage_no:1,status_label:'در انتظار بررسی',review:{revision:1,document_revision:1},proof:{},actions:{approve:true,reject:true},history:[]}]};
   window.fetch=async(url,args)=>{const op=args.body.get('operation');window.__calls.push({operation:op,payment:args.body.get('payment_id'),revision:args.body.get('revision'),nonce:args.body.get('nonce'),credentials:args.credentials});
    if(op!=='read'){const row=window.__data.payments[1];row.status=op==='approve'?'approved':'rejected';row.actions={};row.history=[{action:'stage_capacity_reconciled',actor_name:'Synthetic',created_at:'2026-11-25',revision:1,reason:'<img src=x onerror="window.__xss=1">'}];if(op==='approve')window.__data.totals={total:5970000,paid:5970000,remaining:0,due:0};return{json:async()=>({success:true,message:'ثبت شد'})};}
    return{json:async()=>Object.assign({success:true},structuredClone(window.__data))};
   };
  });await page.addScriptTag({content:source});await page.evaluate(()=>window.snReceiptReview.mount(document.querySelector('#review'),42));
  ok(await page.locator('.sn-review-approve').count()===1,'remaining receipt offers Finance approval at '+width);
  await page.locator('.sn-review-approve').click();await page.waitForFunction(()=>window.__calls.filter(c=>c.operation==='read').length===2);
  ok((await page.locator('.sn-review-totals').innerText()).includes('۵٬۹۷۰٬۰۰۰')||(await page.locator('.sn-review-totals').innerText()).includes('5,970,000'),'approval refresh shows full received total at '+width);
  ok((await page.locator('.sn-review-history').textContent()).includes('اصلاح سقف مرحله'),'capacity audit event has a Persian label at '+width);
  ok(await page.evaluate(()=>window.__xss===0&&document.querySelectorAll('#review img').length===0),'customer and audit text remain escaped at '+width);
  ok(await page.evaluate(()=>window.__calls.find(c=>c.operation==='approve').payment==='2'&&window.__calls.find(c=>c.operation==='approve').revision==='1'&&window.__calls.every(c=>c.nonce==='synthetic-finance'&&c.credentials==='same-origin')),'decision preserves receipt revision and nonce contract at '+width);
  await page.evaluate(()=>{window.__data.payments[1].status='pending';window.__data.payments[1].actions={approve:true,reject:true};window.__data.totals={total:5970000,paid:5000000,remaining:970000,due:970000};return window.snReceiptReview.mount(document.querySelector('#review'),42);});
  await page.locator('textarea[name=reason]').fill('Synthetic rejection');await page.locator('.sn-review-reject').click();await page.waitForFunction(()=>window.__calls.some(c=>c.operation==='reject'));
  ok(await page.evaluate(()=>window.__data.totals.paid===5000000&&window.__data.totals.remaining===970000),'rejection preserves prior approved money at '+width);
  await context.close();
 }}finally{await browser.close();}
 console.log('TOTAL '+checks);return{passed:true,assertions:checks,version:'2.0.247',desktop_mobile:true,external_network_blocked:true,synthetic:true,production_touched:false};
};
