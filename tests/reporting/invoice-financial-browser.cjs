/* Native browser/HTTP regression. Requires the guarded disposable reporting fixture. */
const fs = require('fs');
const assert = require('assert/strict');
const {chromium} = require(process.argv[2] || '/tooling/node_modules/playwright');
const evidence = process.env.SN_REPORT_EVIDENCE || '/evidence';
const state = JSON.parse(fs.readFileSync(evidence + '/private-state.json','utf8').replace(/^\uFEFF/,''));
const reader = JSON.parse(fs.readFileSync(evidence + '/private-reader.json','utf8'));
const fixture = JSON.parse(fs.readFileSync(evidence + '/fixture.json','utf8'));
const origin = 'http://report-web';
let checks = 0;
function ok(value,label) { assert(value,label); ++checks; console.log('PASS '+label); }
function csv(text) {
  const result=[]; let row=[],cell='',quoted=false;
  for(let i=0;i<text.length;i++) {
    const c=text[i];
    if(c==='"') { if(quoted && text[i+1]==='"') { cell+='"'; ++i; } else { quoted=!quoted; } }
    else if(!quoted && (c===',' || c==='\n')) { row.push(cell); cell=''; if(c==='\n') { result.push(row); row=[]; } }
    else if(c!=='\r' || quoted) { cell+=c; }
  }
  if(cell || row.length) { row.push(cell); result.push(row); }
  return result;
}
async function login(context,username,password) {
  const page=await context.newPage();
  await page.goto(origin+'/wp-login.php');
  await page.locator('#user_login').fill(username); await page.locator('#user_pass').fill(password);
  await Promise.all([page.waitForNavigation(),page.locator('#wp-submit').click()]);
  return page;
}
(async()=>{
  const browser=await chromium.launch({headless:true,args:['--no-sandbox']});
  try {
    const context=await browser.newContext({viewport:{width:1440,height:1000}});
    const page=await login(context,'synthetic_admin',state.admin_password);
    const errors=[]; page.on('pageerror',e=>errors.push(e.message));
    await page.goto(origin+'/wp-admin/admin.php?page=sn-invoices');
    await page.getByRole('heading',{name:'پیش‌فاکتورها، پرداخت‌ها و گزارش مالی',exact:true}).waitFor();
    ok(await page.locator('.wp-list-table tbody tr').count()===6,'native admin page renders all six synthetic invoices');
    ok(!await page.evaluate(()=>Boolean(window.report_xss)),'customer markup cannot execute in admin report');
    ok((await page.locator('.wp-list-table').innerText()).includes('درخواست تماس مجدد'),'Persian follow-up label is visible');
    ok((await page.locator('.wp-list-table').innerText()).includes('تسویه ناقص'),'settlement is visible independently of follow-up');
    await page.screenshot({path:evidence+'/invoice-report-desktop.png',fullPage:true});
    const nonce=await page.locator('#sn-bulk-invoice-filters input[name="_wpnonce"]').inputValue();
    const bulkNonce=await page.locator('#sn-bulk-invoice-filters input[name="_sn_bulk_nonce"]').inputValue();
    async function download(view,format,extra={}) {
      const params=new URLSearchParams({page:'sn-invoices',sn_export:'invoices',sn_invoice_report:view,sn_format:format,_wpnonce:nonce,...extra});
      const response=await context.request.get(origin+'/wp-admin/admin.php?'+params);
      const body=(await response.body()).toString('utf8').replace(/^\uFEFF/,'');
      ok(response.ok() && response.headers()['content-type'].includes(format==='csv' ? 'text/csv' : 'application/vnd.ms-excel'),view+' '+format+' uses the actual authorized download handler');
      ok(!body.includes('synthetic-approved') && !body.includes('synthetic-request') && !body.includes('access_token') && !body.includes('proof_data'),view+' '+format+' omits internal payment metadata');
      if(format==='csv') { return csv(body); }
      const rows=await page.evaluate(xml=>{
        const doc=new DOMParser().parseFromString(xml,'application/xml');
        if(doc.querySelector('parsererror')) { throw new Error('Invalid XLS XML'); }
        return [...doc.getElementsByTagName('Row')].map(r=>[...r.getElementsByTagName('Data')].map(d=>d.textContent));
      },body);
      ok(!body.includes('ss:Formula='),'XLS has literal cells without executable formulas');
      if(view==='invoices') {
        const typed=await page.evaluate(xml=>{
          const doc=new DOMParser().parseFromString(xml,'application/xml');
          const cells=doc.getElementsByTagName('Row')[1].getElementsByTagName('Data');
          return cells[3].getAttributeNS('urn:schemas-microsoft-com:office:spreadsheet','Type')==='String'
            && cells[23].getAttributeNS('urn:schemas-microsoft-com:office:spreadsheet','Type')==='Number';
        },body);
        ok(typed,'XLS phone stays text while receipt amount is a summable number');
      }
      return rows;
    }
    for(const format of ['csv','xls']) {
      const invoices=await download('invoices',format);
      ok(invoices.length===7 && new Set(invoices.slice(1).map(r=>r[0])).size===6,'one '+format+' row per original invoice ID');
      ok(invoices.every(r=>r.length===invoices[0].length),'invoice '+format+' columns remain aligned');
      const confirmed=invoices[0].indexOf('دریافتی تأییدشده (تومان)');
      ok(invoices.slice(1).reduce((sum,r)=>sum+Number(r[confirmed]),0)===8000,'invoice '+format+' receipt total agrees with native ledger');
      ok(invoices[1][2]==="'=SUM(1+1)",'formula-like customer name is literal in '+format);
      ok(invoices[1][3]==='۰۹۱۲۱۲۳۴۵۶۷','customer phone retains its leading zero in '+format);
      const payments=await download('payments',format);
      ok(payments.length===7 && payments.every(r=>r.length===16),'payment '+format+' has one row per ledger record');
      const received=payments.slice(1).filter(r=>['تأیید مالی‌شده','پرداخت‌شده'].includes(r[10])).reduce((sum,r)=>sum+Number(r[9]),0);
      ok(received===8000 && payments.some(r=>r[10]==='درخواست درگاه؛ واریز تأیید نشده'),'payment '+format+' separates pending requests from actual receipts');
      const sellers=await download('sellers',format);
      ok(sellers.length===2 && sellers.every(r=>r.length===sellers[0].length),'seller '+format+' aggregates without invoice duplication');
      const amount=sellers[0].indexOf('دریافتی تأییدشده دفتر (تومان)');
      ok(Number(sellers[1][amount])===8000,'seller '+format+' contains actual received money');
      const customers=await download('customers',format);
      ok(customers.length===6 && customers.every(r=>r.length===customers[0].length),'customer '+format+' merges normalized phone identities');
      const customerAmount=customers[0].indexOf('دریافتی تأییدشده دفتر (تومان)');
      ok(customers.slice(1).reduce((sum,r)=>sum+Number(r[customerAmount]),0)===8000,'customer '+format+' total matches ledger');
    }
    for(const [status,count] of [['paid',3],['needs_review',1],['pre_invoice',2],['cancelled',1],['recontact_requested',2]]) {
      const rows=await download('invoices','csv',{sn_status:status});
      ok(rows.length===count+1,'actual export honors tab '+status);
      await page.goto(origin+'/wp-admin/admin.php?'+new URLSearchParams({page:'sn-invoices',sn_status:status}));
      ok(await page.locator('.wp-list-table tbody tr input[type=checkbox]').count()===count,'page and export agree for tab '+status);
    }
    ok((await download('invoices','csv',{sn_finance:'mismatch'})).length===2,'actual discrepancy filter finds only the mismatched invoice');
    ok((await download('invoices','csv',{'sn_seller_ids[]':'999999'})).length===1,'forged seller selection cannot broaden the export');
    ok((await download('invoices','csv',{sn_status:"paid' OR 1=1 --"})).length===1,'SQL-like status does not broaden actual export');
    const bulkResponse=await context.request.get(origin+'/wp-admin/admin.php?'+new URLSearchParams({page:'sn-invoices',sn_export:'bulk_orders',_sn_bulk_nonce:bulkNonce,sn_status:'paid'}));
    const bulkBody=(await bulkResponse.body()).toString('utf8').replace(/^\uFEFF/,'');
    const bulkRows=await page.evaluate(xml=>{
      const doc=new DOMParser().parseFromString(xml,'application/xml');
      if(doc.querySelector('parsererror')) { throw new Error('Invalid bulk XLS'); }
      return [...doc.getElementsByTagName('Row')].map(r=>[...r.getElementsByTagName('Data')].map(d=>d.textContent));
    },bulkBody);
    ok(bulkResponse.ok() && bulkRows.length===4 && bulkRows.every(r=>r.length===27),'existing bulk XLS preserves its 27 columns and shares the financial tab filter');
    const selectedResponse=await context.request.get(origin+'/wp-admin/admin.php?'+new URLSearchParams({page:'sn-invoices',sn_export:'bulk_orders',_sn_bulk_nonce:bulkNonce,'sn_bulk_ids[]':String(fixture.ids[0])}));
    const selectedXml=await selectedResponse.text();
    ok(selectedResponse.ok() && (selectedXml.match(/<Row(?:\s|>)/g)||[]).length===2,'existing bulk XLS keeps the explicit selected-invoice boundary');
    const invalid=await context.request.get(origin+'/wp-admin/admin.php?'+new URLSearchParams({page:'sn-invoices',sn_export:'invoices',_wpnonce:nonce,sn_date_from:'2026-02-30'}));
    ok(!(invalid.headers()['content-type']||'').includes('text/csv'),'invalid date is rejected instead of exporting all records');
    const badNonce=await context.request.get(origin+'/wp-admin/admin.php?page=sn-invoices&sn_export=invoices&_wpnonce=invalid');
    ok(!badNonce.ok() && !(await badNonce.text()).includes('کد فاکتور ثبت‌شده'),'actual invalid nonce denies financial export');
    const other=await browser.newContext(); await login(other,reader.username,reader.password);
    const denied=await other.request.get(origin+'/wp-admin/admin.php?'+new URLSearchParams({page:'sn-invoices',sn_export:'invoices',_wpnonce:nonce,'sn_seller_ids[]':String(fixture.reader)}));
    ok(!(denied.headers()['content-type']||'').includes('text/csv') && !(await denied.text()).includes('SYN-RPT-'),'unrelated logged-in role cannot export even with copied admin nonce and forged seller');
    await other.close();
    const guest=await browser.newContext();
    const anonymous=await guest.request.get(origin+'/wp-admin/admin.php?page=sn-invoices&sn_export=invoices');
    ok(!(await anonymous.text()).includes('SYN-RPT-'),'anonymous request does not disclose invoice data'); await guest.close();
    await page.goto(origin+'/wp-admin/admin.php?page=sn-invoices');
    await page.setViewportSize({width:390,height:844});
    await page.screenshot({path:evidence+'/invoice-report-mobile.png',fullPage:true});
    ok(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth+1),'mobile page contains wide financial tables inside scrolling containers');
    ok(await page.locator('.wp-list-table tbody td').first().evaluate(cell=>getComputedStyle(cell).display==='table-cell'),'mobile financial table retains aligned cells instead of unlabeled stacked fields');
    await page.setViewportSize({width:1440,height:1000});
    await page.locator('.wp-list-table').evaluate(t=>t.parentElement.scrollLeft=-1400);
    await page.screenshot({path:evidence+'/invoice-report-financial-columns.png',fullPage:true});
    ok(errors.length===0,'report renders without uncaught browser errors');
    fs.writeFileSync(evidence+'/browser-summary.json',JSON.stringify({checks,errors,viewportEvidence:['1440x1000','390x844'],syntheticOnly:true},null,2));
    console.log('PASS '+checks+' native invoice report browser/HTTP checks');
  } finally { await browser.close(); }
})().catch(e=>{ console.error(e.message); process.exitCode=1; });
