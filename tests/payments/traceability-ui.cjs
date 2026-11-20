'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
module.exports=async function({playwright,channel='msedge'}){
 const {chromium}=require(playwright);const browser=await chromium.launch({headless:true,channel});let checks=0;
 const source=fs.readFileSync(path.resolve(__dirname,'../../assets/js/payment-transactions.js'),'utf8');
 function ok(v,label){assert.ok(v,label);checks++;console.log('PASS '+label);}
 try{for(const viewport of [{width:1440,height:1000},{width:390,height:844}]){
  const context=await browser.newContext({viewport});await context.route('**/*',r=>r.abort());const page=await context.newPage();
  await page.setContent('<html dir="rtl"><body><main id="history"></main></body></html>');
  await page.evaluate(()=>{window.jQuery=()=>({on(){return this;}});window.jQuery.ajaxPrefilter=()=>{};window.snReceiptFiles={links:()=>'',urls:()=>[]};});
  await page.addScriptTag({content:source});
  await page.evaluate(()=>{const common={amount:100,status:'approved',status_label:'تأیید شده',stage_count:2,source_label:'ساختگی',proof:{}};
   document.querySelector('#history').innerHTML=window.snPaymentTransactions.render([
    {...common,id:11,transaction_code:'PAY-11',stage_no:1,stage_reference:'STG-3'},
    {...common,id:12,transaction_code:'PAY-12',stage_no:1,stage_reference:'STG-3'},
    {...common,id:13,transaction_code:'PAY-13',stage_no:2,stage_reference:'STG-7',source_label:'<img src=x onerror="window.__traceXss=1">',transaction_code:'<script>window.__traceXss=1</script>PAY-13'}],{pending_amount:0,available_amount:0});});
  const text=await page.locator('#history').innerText();
  ok(await page.locator('.sn-transaction-row').count()===3,'three independent receipts render at width '+viewport.width);
  ok(text.includes('PAY-11')&&text.includes('PAY-12')&&text.includes('PAY-13'),'stable receipt identifiers visible at width '+viewport.width);
  ok(text.includes('مرحله 1 از 2')&&text.includes('مرحله 2 از 2'),'stage count and payment stage visible at width '+viewport.width);
  ok(await page.locator('bdi').count()===6,'Latin transaction/stage ids have explicit bidi isolation at width '+viewport.width);
  ok(!await page.evaluate(()=>window.__traceXss)&&await page.locator('#history script,#history img').count()===0,'untrusted references and labels remain escaped text at width '+viewport.width);
  await page.evaluate(()=>document.querySelector('#history').innerHTML=window.snPaymentTransactions.render([],{}));
  ok((await page.locator('#history').innerText()).includes('هنوز تراکنشی ثبت نشده'),'empty history creates no invented transaction at width '+viewport.width);
  await page.addScriptTag({content:fs.readFileSync(path.resolve(__dirname,'../../assets/js/receipt-review.js'),'utf8')});
  await page.evaluate(async()=>{
   window.__requests=[];window.snAjax={ajaxurl:'/synthetic',nonce:'synthetic-nonce'};
   window.fetch=async(url,args)=>{const request=Object.fromEntries(args.body.entries());window.__requests.push(request);return{json:async()=>request.operation==='read'?{success:true,invoice_code:'TRACE-SYN',invoice:{stage_no:1},totals:{total:5000000,paid:750000,remaining:4250000,due:1250000},payments:[{id:11,amount:750000,status:'approved',status_label:'تأیید شده',stage_no:1,actions:{retry_commission:true},review:{revision:2,document_revision:1},proof:{}}]}:{success:true,already:true,message:'Synthetic commission reviewed'}};};
   const h=document.createElement('section');h.id='review';document.body.appendChild(h);await window.snReceiptReview.mount(h,99);
  });
  ok(await page.getByRole('button',{name:'بازبینی پورسانت این واریز',exact:true}).count()===1,'approved partial receipt offers commission retry at width '+viewport.width);
  await page.getByRole('button',{name:'بازبینی پورسانت این واریز',exact:true}).click();
  await page.waitForFunction(()=>window.__requests.some(r=>r.operation==='approve'));
  const retry=await page.evaluate(()=>window.__requests.find(r=>r.operation==='approve'));
  ok(retry.invoice_id==='99'&&retry.payment_id==='11'&&retry.revision==='2'&&retry.nonce==='synthetic-nonce'&&!('access_token'in retry),'commission retry preserves invoice, receipt, revision and nonce contract at width '+viewport.width);
  await context.close();
 }}finally{await browser.close();}
 console.log('TOTAL '+checks);return checks;
};
