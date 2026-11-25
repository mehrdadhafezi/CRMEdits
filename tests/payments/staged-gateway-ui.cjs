'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
module.exports=async function({playwright,channel}){
 const {chromium}=require(playwright),browser=await chromium.launch({headless:true,channel});let checks=0;
 const source=fs.readFileSync(path.resolve(__dirname,'../../assets/js/unified-invoice-payments.js'),'utf8');
 function ok(v,label){assert.ok(v,label);checks++;console.log('PASS '+label);}
 try{for(const width of[1440,390])for(const stage of[1,2]){
  const context=await browser.newContext({viewport:{width,height:950}});await context.route('**/*',r=>r.abort());const page=await context.newPage();
  await page.setContent('<html dir="rtl"><body><button class="sn-unified-state-open" data-invoice-id="314">Open</button></body></html>');
  await page.evaluate(({stage})=>{window.__requests=[];window.__xss=0;window.snAjax={nonce:'synthetic'};window.fetch=async(url,args)=>{window.__requests.push(args.body.get('action'));return{json:async()=>({success:true,data:{invoice:{id:314,code:'SYN243',display_code:'SYN243'},finance:{total:10000000,approved:stage===1?5000000:10000000,remaining:stage===1?5000000:0,active_due:0,pending:0,available_to_register:0,stage_invoice_code:'SYN243/'+stage,stage_paid:true,stage_amount:5000000},allowed_actions:{view:true},workflow:{state:stage===1?'stage_ready':'completed',description:'وضعیت ساختگی'},referral:{}}})};};},{stage});
  await page.addScriptTag({content:source});await page.click('.sn-unified-state-open');await page.waitForSelector('.sn-unified-finance-grid');
  const text=await page.locator('#sn-unified-invoice-modal').innerText();
  ok(text.includes('SYN243/'+stage)&&text.includes('پرداخت شده'),'paid slash reference rendered at '+width+' stage '+stage);
  ok(await page.locator('bdi[dir=ltr]').innerText()==='SYN243/'+stage,'RTL slash reference isolated at '+width+' stage '+stage);
  const grid=await page.locator('.sn-unified-finance-grid').innerText();
  ok(grid.includes('۱۰٬۰۰۰٬۰۰۰')&&grid.includes(stage===1?'۵٬۰۰۰٬۰۰۰':'۰'),'purchase total and remaining displayed separately at '+width+' stage '+stage);
  ok(JSON.stringify(await page.evaluate(()=>window.__requests))==='["sn_payment_management_state"]','opening stage view performs only scoped state read at '+width+' stage '+stage);
  ok(await page.locator('.sn-unified-invoice-label').innerText()==='فاکتور SYN243','purchase code remains unsuffixed at '+width+' stage '+stage);
  await context.close();
 }
 }finally{await browser.close();}
 return{passed:true,assertions:checks,version:'2.0.244',desktop_mobile:true,actual_runtime_js:true,external_network_blocked:true,real_provider_calls:0,production_touched:false};
};
