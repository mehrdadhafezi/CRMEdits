'use strict';
// Browser checks against an explicitly guarded, loopback synthetic test transport.
const fs=require('node:fs');const path=require('node:path');
const {chromium}=require(process.env.SN_PLAYWRIGHT_MODULE||'playwright');
module.exports=async function({base,output}){
 if(!/^http:\/\/127[.]0[.]0[.]1:[0-9]+$/.test(base))throw Error('Synthetic loopback browser target required');
 const native=process.env.SN_HR_BROWSER_NATIVE==='YES';
 const sandbox=process.env.SN_APPROVAL_ROOT||'';
 if(native&&(!/^\/tmp\/sn-approval-[A-Za-z0-9]+$/.test(sandbox)||process.env.SN_APPROVAL_ALLOW_SYNTHETIC!=='YES'))throw Error('Generated disposable native browser sandbox required');
 fs.mkdirSync(output,{recursive:true});let checks=0;const errors=[];
 function ok(value,label){if(!value)throw Error(label);checks++;console.log('PASS '+label);}
 const browser=await chromium.launch({...(native?{args:['--disable-dev-shm-usage']}:{channel:'msedge'}),headless:true});const contexts=[];
 const open=async()=>{const context=await browser.newContext({viewport:{width:1440,height:1000},serviceWorkers:'block'});contexts.push(context);await context.route('**/*',route=>new URL(route.request().url()).origin===base?route.continue():route.abort());return context;};
 try{
  const hr=await open(),seller=await open(),guest=await open();
  const fixture=native?JSON.parse(fs.readFileSync(path.join(sandbox,'hr239-fixture.json'))):await(await hr.request.get(base+'/test/session?who=hr')).json();
  if(native){
   for(const [context,key] of [[hr,'hr'],[seller,'seller_a']]){
    const u=fixture.users[key];await context.request.get(base+'/wp-login.php');
    await context.request.post(base+'/wp-login.php',{form:{log:u.login,pwd:u.password,testcookie:'1',redirect_to:base+'/'}});
    const session=await(await context.request.get(base+'/?sn_test_nonce=1')).json();
    ok(session.success&&session.user_id===u.id,'real HTTP WordPress login cookie '+key);
   }
  }else{await seller.request.get(base+'/test/session?who=seller_a');}
  const users=fixture.users;
  const id=key=>String(users[key].id);
  const form=fields=>{const body=new URLSearchParams({action:'sn_hr_invoice_reissue',operation:'list',manager_id:'0',...fields});return body.toString();};
  const post=async(context,fields)=>{const response=await context.request.post(base+(native?'/wp-admin/admin-ajax.php':'/ajax'),{data:form(fields),headers:{'Content-Type':'application/x-www-form-urlencoded'}});return {status:response.status(),...await response.json()};};
  const page=await hr.newPage();page.on('pageerror',error=>errors.push(error.message));await page.goto(base+(native?'/?page_id='+fixture.page_id+'&sn_hr_tab=hr-invoice-reissue':'/'));
  const root=page.locator('#sn-hr-invoice-reissue'),status=root.locator('[role=status]');
  const select=level=>root.locator('[data-reissue-level="'+level+'"]');
  const wait=async()=>{await status.filter({hasText:/فهرست آماده است|مجوز ذخیره شد/}).waitFor();await page.waitForFunction(()=>!document.querySelector('[data-reissue-refresh]').disabled);};
  await wait();ok(native?String(await page.evaluate(()=>window.snAjax.current_user))===String(users.hr.id):await page.locator('body').getAttribute('data-verified-user')===String(fixture.id),'browser session cookie resolves to actual WordPress HR user');
  ok(native?await page.locator('body').evaluate(el=>!el.hasAttribute('data-light-bootstrap')):await page.locator('body').getAttribute('data-light-bootstrap')==='true','HR policy renders through actual lightweight shortcode bootstrap');
  ok(await root.count()===1&&await root.evaluate(el=>getComputedStyle(el).direction)==='rtl','actual HR tab renders the RTL policy component');
  ok(await page.locator('body').evaluate(el=>el.classList.contains('sn-portal-page')),'actual public panel script initializes the shared portal context');
  ok(await root.locator('[data-reissue-level]').count()===4,'four cascading selectors are present');
  ok(await select('manager').locator('option').count()>=3,'manager list is loaded by actual authenticated handler');
  const nonce=await page.evaluate(()=>window.SNHRInvoiceReissue.nonce);
  ok((await post(guest,{nonce})).status===401,'guest browser request cannot list HR policy');
  ok((await post(seller,{nonce})).status===403,'seller cookie cannot gain HR access with HR nonce');
  ok((await post(hr,{nonce:''})).status===403,'browser request without nonce is rejected');
  await select('manager').selectOption(id('manager_a'));await wait();
  ok(await root.locator('tr[data-user-id]').count()===1,'manager selection edits only the explicitly selected manager');
  const options=async level=>select(level).locator('option').evaluateAll(nodes=>nodes.map(el=>el.value));
  ok((await options('senior')).includes(id('senior_a'))&&(await options('senior')).includes(id('senior_c'))&&!(await options('senior')).includes(id('senior_b')),'senior choices remain inside selected manager branch');
  await select('senior').selectOption([id('senior_a')]);await wait();
  ok((await options('supervisor')).includes(id('supervisor_a'))&&!(await options('supervisor')).includes(id('supervisor_c')),'senior selection narrows supervisors');
  await select('supervisor').selectOption([id('supervisor_a')]);await wait();
  ok((await options('seller')).includes(id('seller_a'))&&!(await options('seller')).includes(id('seller_c')),'supervisor selection narrows sellers');
  await select('seller').selectOption([id('seller_a')]);await wait();
  ok(await root.locator('tr[data-user-id]').count()===4,'all four explicitly selected levels have editable rows');
  const row=key=>root.locator('tr[data-user-id="'+id(key)+'"]');
  const save=async(key,mode)=>{await row(key).locator('[data-reissue-mode]').selectOption(mode);await row(key).locator('[data-reissue-save]').click();await wait();};
  await save('manager_a','allow');
  ok((await row('seller_a').locator('td').nth(3).innerText())==='مجاز','saving manager allow updates inherited seller status');
  await save('seller_a','deny');ok((await row('seller_a').locator('td').nth(3).innerText())==='غیرمجاز','saved seller exception overrides parent grant');
  await save('seller_a','inherit');ok((await row('seller_a').locator('td').nth(3).innerText())==='مجاز','inherit immediately restores parent permission');
  ok(await root.locator('img').count()===0&&await page.evaluate(()=>window.__hr239Xss)===undefined,'hostile HR display label stays text without executing HTML');
  await row('supervisor_a').locator('[data-reissue-mode]').selectOption('deny');await save('manager_a','deny');
  ok(await row('supervisor_a').locator('[data-reissue-mode]').inputValue()==='deny'&&(await row('supervisor_a').innerText()).includes('ذخیره نشده'),'saving an ancestor preserves another row unsaved draft');
  page.once('dialog',dialog=>dialog.dismiss());await select('manager').selectOption(id('manager_b'));
  ok(await select('manager').inputValue()===id('manager_a'),'cancelled filter change preserves branch and draft');
  page.once('dialog',dialog=>dialog.accept());await select('manager').selectOption(id('manager_b'));await wait();
  ok(await root.locator('tr[data-user-id]').count()===1&&!(await options('seller')).includes(id('seller_a')),'confirmed branch change discards drafts and excludes previous team');
  await select('manager').selectOption(id('manager_a'));await wait();
  await select('senior').selectOption([id('senior_a'),id('senior_c')]);await wait();
  await select('supervisor').selectOption([id('supervisor_a'),id('supervisor_c')]);await wait();
  await select('seller').selectOption([id('seller_a'),id('seller_c')]);await wait();
  ok(await root.locator('tr[data-user-id]').count()===7,'multiple seniors supervisors and sellers can be selected within the manager branch');
  const selection={nonce,manager_id:id('manager_a'),'senior_ids[]':id('senior_a'),'supervisor_ids[]':id('supervisor_a'),'seller_ids[]':id('seller_a')};
  const view=await post(hr,selection);const manager=view.data.rows.find(x=>x.id===users.manager_a.id);
  const racing=await Promise.all(['allow','inherit'].map(mode=>post(hr,{...selection,operation:'save',user_id:id('manager_a'),mode,version:manager.version})));
  ok(racing.filter(x=>x.success).length===1&&racing.filter(x=>x.status===409).length===1,'two simultaneous native saves accept exactly one revision');
  const mismatch=await post(hr,{...selection,'seller_ids[]':id('seller_b')});ok(mismatch.status===403,'forged cross-manager browser selection is rejected on server');
  await root.locator('[data-reissue-refresh]').click();await wait();
  await page.screenshot({path:path.join(output,'hr-reissue-desktop.png'),fullPage:true});
  await page.setViewportSize({width:390,height:844});
  try{await page.waitForFunction(()=>{const panel=document.querySelector('#sn-hr-invoice-reissue'),intro=document.querySelector('.sn-hr-front-context');return panel.getBoundingClientRect().top-intro.getBoundingClientRect().bottom<100;},{},{timeout:3000});}
  catch(error){
   const layout=await page.evaluate(()=>Array.from(document.querySelector('#sn-hr-panel').children).map(el=>{const css=getComputedStyle(el),box=el.getBoundingClientRect();return {tag:el.tagName,id:el.id,class:el.className,top:box.top,height:box.height,display:css.display,position:css.position,gridRow:css.gridRow,minHeight:css.minHeight,marginTop:css.marginTop};}));
   fs.writeFileSync(path.join(output,'layout-diagnostic.json'),JSON.stringify(layout,null,2)+'\n');await page.screenshot({path:path.join(output,'mobile-diagnostic.png'),fullPage:true});throw error;
  }
  ok(await page.locator('.sn-mobile-sidebar-toggle').isVisible(),'actual shared shell supplies mobile navigation without an empty sidebar gap');
  await page.locator('.sn-mobile-sidebar-toggle').click();
  ok(await page.locator('#sn-hr-panel').evaluate(el=>el.classList.contains('sn-mobile-sidebar-open')),'mobile sidebar still opens from new policy tab');
  await page.keyboard.press('Escape');
  ok(await page.locator('#sn-hr-panel').evaluate(el=>!el.classList.contains('sn-mobile-sidebar-open')),'mobile sidebar closes with keyboard Escape');
  ok(await root.locator('.sn-hr-reissue-filters').evaluate(el=>getComputedStyle(el).gridTemplateColumns.split(' ').length)===1,'mobile selector grid has one column');
  const overflow=await page.evaluate(()=>({viewport:innerWidth,document:document.documentElement.scrollWidth,elements:Array.from(document.querySelectorAll('body,body *')).filter(el=>{const box=el.getBoundingClientRect();return box.width>0&&(box.right>innerWidth+2||box.left < -2);}).slice(0,45).map(el=>{const box=el.getBoundingClientRect(),css=getComputedStyle(el);return {tag:el.tagName,id:el.id,class:el.className,left:box.left,right:box.right,width:box.width,overflowX:css.overflowX,display:css.display,minWidth:css.minWidth,position:css.position,parent:el.parentElement?.className};})}));
  if(overflow.document>overflow.viewport+2){fs.writeFileSync(path.join(output,'overflow-diagnostic.json'),JSON.stringify(overflow,null,2)+'\n');await page.screenshot({path:path.join(output,'hr-reissue-mobile-overflow.png'),fullPage:true});}
  ok(overflow.document<=overflow.viewport+2,'mobile page has no horizontal overflow');
  ok(await root.locator('.sn-table-wrap').evaluate(el=>el.scrollWidth>el.clientWidth),'wide policy table scrolls within its mobile container');
  await select('manager').focus();ok(await select('manager').evaluate(el=>el===document.activeElement),'policy selector supports keyboard focus');
  await page.screenshot({path:path.join(output,'hr-reissue-mobile.png'),fullPage:true});
  // Leave this isolated fixture with the secure default again, without deleting data.
  const final=await post(hr,selection);const current=final.data.rows.find(x=>x.id===users.manager_a.id);
  if(current.mode!=='inherit'){ok((await post(hr,{...selection,operation:'save',user_id:id('manager_a'),mode:'inherit',version:current.version})).success,'synthetic policy restored to default through authenticated handler');}
  ok(errors.length===0,'browser has no uncaught JavaScript errors');
  const summary={version:'2.0.245',passed:true,assertions:checks,real_wordpress_auth_cookie:true,real_wordpress_ajax_handler:true,transport:native?'native WordPress HTTP login, shortcode and admin-ajax':'private CLI via loopback proxy; public web transport not exercised',synthetic:true,production_touched:false};
  fs.writeFileSync(path.join(output,'summary.json'),JSON.stringify(summary,null,2)+'\n');console.log('TOTAL '+checks);
 }finally{for(const context of contexts)await context.close();await browser.close();}
};
