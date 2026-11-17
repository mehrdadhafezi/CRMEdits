/* Native WP cookies/nonces + actual AJAX handlers and rendered shortcode panels. */
'use strict';
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const { pathToFileURL } = require('node:url');
const root = process.env.SN_APPROVAL_ROOT || '';
if (!/^\/tmp\/sn-approval-/.test(root) || process.env.SN_APPROVAL_ALLOW_SYNTHETIC !== 'YES') throw Error('Disposable sandbox required');
const fixture = JSON.parse(fs.readFileSync(path.join(root, 'fixture.json')));
const output = process.env.SN_APPROVAL_OUTPUT;
const base = 'http://127.0.0.1:8877';
let checks = 0;
function ok(value, name) { assert(value, name); checks++; console.log('PASS ' + name); }
const unwrap = r => r && r.data && typeof r.data === 'object' ? { ...r, ...r.data } : r;
async function responseJson(response) {
  assert(response.status() < 500, 'AJAX endpoint must not return a server error');
  const body = await response.text();
  try { return unwrap(JSON.parse(body)); } catch { throw Error('AJAX response must be JSON'); }
}
async function pollMoney(page, selector, expected) {
  await page.waitForFunction(({ selector, expected }) => {
    const text = document.querySelector(selector)?.textContent || '';
    const digits = text.replace(/[۰-۹]/g, x => '۰۱۲۳۴۵۶۷۸۹'.indexOf(x)).replace(/[٠-٩]/g, x => '٠١٢٣٤٥٦٧٨٩'.indexOf(x)).replace(/[^0-9]/g, '');
    return digits === String(expected);
  }, { selector, expected }, { timeout: 20000 });
}
(async () => {
  const { chromium } = await import(pathToFileURL(process.env.SN_PLAYWRIGHT_MODULE).href);
  const browser = await chromium.launch({ headless: true, args: ['--disable-dev-shm-usage'] });
  const contexts = {}, pages = {}, pageErrors = [];
  const nonces = {}, reportNonces = {};
  try {
    for (const key of ['seller','supervisor','senior','finance','subscriber','revoked','admin']) {
      const context = contexts[key] = await browser.newContext({ viewport: { width:1440, height:1000 } });
      await context.request.get(base + '/wp-login.php');
      const u = fixture.users[key];
      await context.request.post(base + '/wp-login.php', { form: { log:u.login, pwd:u.password, testcookie:'1', redirect_to:base + '/' } });
      const session = await responseJson(await context.request.get(base + '/?sn_test_nonce=1'));
      ok(session.success && session.user_id === u.id, 'real WordPress cookie session ' + key);
      nonces[key] = session.nonce;
      reportNonces[key] = session.report_nonce;
    }
    const post = async (who, action, fields = {}) => responseJson(await contexts[who].request.post(base + '/wp-admin/admin-ajax.php', { form:{ action, nonce:nonces[who], ...fields } }));
    const guest = await browser.newContext();
    const guestResponse = await responseJson(await guest.request.post(base + '/wp-admin/admin-ajax.php', {form:{action:'sn_seller_invoices'}}));
    ok(!guestResponse || guestResponse.success !== true, 'guest cannot read invoice AJAX');
    await guest.close();
    const summary = await post('seller', 'sn_seller_invoices', {summary_only:'1', seller_id:String(fixture.users.other.id)});
    ok(summary.success && Number(summary.received_amount) === 1500000 && summary.items.length === 0, 'seller actual summary is scoped and includes partial approved money');
    const list = await post('seller', 'sn_seller_invoices', {tab:'all'});
    ok(list.success && list.items.some(i => i.id == fixture.invoices.final && !i.status_label.includes('انتظار')), 'seller AJAX shows final approval despite stale compatibility status');
    ok(!list.items.some(i => i.id == fixture.invoices.foreign), 'forged seller input never exposes a foreign invoice');
    for (const token of fixture.tokens) ok(!JSON.stringify(list).includes(token), 'seller response excludes synthetic invoice access token');
    for (const who of ['supervisor','senior']) {
      const result = await post(who, 'sn_supervisor_invoices', {tab:'all'});
      ok(result.success && result.items.some(i => i.id == fixture.invoices.partial) && result.items.some(i => i.id == fixture.invoices.final), 'real HR descendant scope ' + who);
      ok(!result.items.some(i => i.id == fixture.invoices.foreign), 'other-team invoice excluded for ' + who);
      ok(result.items.find(i => i.id == fixture.invoices.final).is_fully_settled === true, 'server settlement decision ' + who);
      for (const token of fixture.tokens) ok(!JSON.stringify(result).includes(token), 'supervisor response excludes synthetic invoice access token');
    }
    for (const who of ['seller','supervisor','senior']) {
      const action = who === 'seller' ? 'sn_seller_invoices' : 'sn_supervisor_invoices';
      for (const nonce of ['', 'invalid-synthetic-nonce', nonces.finance]) {
        const denied = await post(who, action, {nonce});
        ok(!denied || denied.success !== true, 'missing/invalid/cross-user nonce denied for ' + who);
      }
      const injected = await post(who, action, {q:"' OR 1=1 --", tab:'all'});
      ok(injected.success && injected.items.length === 0, 'SQL-like input remains a literal scoped search for ' + who);
      const arrayInput = await responseJson(await contexts[who].request.post(base + '/wp-admin/admin-ajax.php', {form:{action,nonce:nonces[who],'q[]':'invalid',tab:'all'}}));
      ok(arrayInput.success && !arrayInput.items.some(i => i.id == fixture.invoices.foreign), 'array search cannot expand scope for ' + who);
    }
    const unrelated = await post('subscriber', 'sn_supervisor_invoices', {role:'sn_supervisor',seller_id:String(fixture.users.seller.id)});
    ok(!unrelated.success, 'unrelated role and forged role input cannot read supervisor invoices');
    const foreignReceipt = await post('seller', 'sn_receipt_review', {invoice_id:String(fixture.invoices.foreign),operation:'read'});
    ok(!foreignReceipt.success, 'direct receipt context cannot cross seller ownership');
    const approveInput = {invoice_id:String(fixture.invoices.decide),payment_id:String(fixture.payments.decide_first),operation:'approve',revision:'1'};
    for (const who of ['seller','revoked']) {
      const denied = await post(who, 'sn_receipt_review', approveInput);
      ok(!denied.success, 'financial decision denied without capability for ' + who);
    }
    const invalidDecision = await post('finance', 'sn_receipt_review', {...approveInput,nonce:''});
    ok(!invalidDecision.success, 'financial decision with missing nonce is denied');
    for (const who of ['seller','supervisor','senior']) {
      const page = pages[who] = await contexts[who].newPage();
      page.on('pageerror', error => pageErrors.push({role:who,message:error.message}));
      await page.goto(base + fixture.pages[who], {waitUntil:'domcontentloaded'});
      const tab = page.locator(who === 'senior' ? '[data-sn-tab-target="ss-invoices"]' : '[data-tab="invoices"]').first();
      await tab.click();
      if (who === 'seller') {
        await page.locator('.sn-invoice-status-tabs [data-status="all"]').first().click();
      }
      const target = who === 'seller' ? '#sn-invoices-list' : '#sn-supervisor-invoices-list';
      await page.locator(target).getByText('SYN-FINAL', {exact:true}).waitFor();
      ok((await page.locator(target).innerText()).includes('بخشی از واریزی تأیید شده'), 'rendered partial approval label ' + who);
      ok(!(await page.locator(target).innerText()).includes('SYN-FOREIGN'), 'rendered list preserves scope ' + who);
      ok(await page.locator(target).evaluate(el => getComputedStyle(el).direction === 'rtl'), 'actual invoice panel has RTL direction ' + who);
      ok(await page.evaluate(() => !window.__snFixtureXss), 'synthetic customer markup cannot execute ' + who);
      const moneySelector = who === 'seller' ? '#sn-seller-kpi-cards .sn-seller-received-amount' : '#sn-supervisor-invoice-kpi-received-amount';
      await pollMoney(page, moneySelector, 1500000);
      ok(true, 'rendered approved-receipt KPI ' + who);
      for (const [label,width,height] of [['desktop',1440,1000],['mobile',390,844]]) {
        await page.setViewportSize({width,height});
        await page.screenshot({path:path.join(output, who + '-' + label + '.png'), fullPage:true});
        const wraps = page.locator(target + ' .sn-table-wrap');
        ok(await wraps.first().evaluate(el => el.scrollWidth <= el.clientWidth + 1 || ['auto','scroll'].includes(getComputedStyle(el).overflowX)), 'invoice table remains scrollable at ' + label + ' width ' + who);
      }
      await page.setViewportSize({width:1440,height:1000});
      await page.locator(who === 'seller' ? '#sn-seller-invoice-search' : '#sn-supervisor-invoice-search').focus();
      await page.keyboard.press('Tab');
      ok(await page.evaluate(() => document.activeElement !== document.body), 'invoice controls retain keyboard focus ' + who);
      if (who === 'seller') {
        await page.locator('[data-tab="leads"]').first().click();
        const kpi = page.locator('#sn-seller-kpi-cards');
        await kpi.waitFor({state:'visible'});
        for (const [label,width,height] of [['desktop',1440,1000],['mobile',390,844]]) {
          await page.setViewportSize({width,height});
          await pollMoney(page, moneySelector, 1500000);
          ok(await kpi.evaluate(el => {
            const settled = el.querySelector('.sn-seller-settled-count')?.getBoundingClientRect();
            const received = el.querySelector('.sn-seller-received-amount')?.getBoundingClientRect();
            const card = el.querySelector('.sn-kpi-money')?.getBoundingClientRect();
            return !!(settled && received && card && settled.height > 0 && received.height > 0
              && received.top >= settled.bottom - 1 && received.bottom <= card.bottom + 1);
          }), 'visible seller receipt amount and settled count never overlap at ' + label + ' width');
          await kpi.screenshot({path:path.join(output, 'seller-kpi-' + label + '.png'), animations:'disabled'});
        }
        await page.setViewportSize({width:1440,height:1000});
      }
    }
    for (const who of ['seller','supervisor','senior']) {
      const page = pages['reports-' + who] = await contexts[who].newPage();
      page.on('pageerror', error => pageErrors.push({role:'reports-' + who,message:error.message}));
      const url = new URL(base + fixture.pages.reports);
      url.searchParams.set('sn_center_type','invoices_register');
      url.searchParams.set('date_from','۱۴۰۵/۰۸/۱۰');
      url.searchParams.set('date_to','۱۴۰۵/۰۸/۱۰');
      await page.goto(url.href,{waitUntil:'domcontentloaded'});
      const panel = page.locator('#sn-reports-panel');
      await panel.getByText('SYN-FINAL',{exact:true}).waitFor();
      ok(!(await panel.innerText()).includes('SYN-FOREIGN'), 'report page preserves real HR scope ' + who);
      ok(await panel.locator('[name="date_from"]').inputValue() === '1405/08/10', 'report form retains the selected Jalali date ' + who);
      ok(new URL(await panel.locator('form.sn-report-filters').getAttribute('action')).hash === '#sn-reports-panel', 'report form returns to the reporting panel ' + who);
      ok(await page.evaluate(() => !window.__snFixtureXss), 'report customer markup cannot execute ' + who);
      const exportLink = await panel.locator('a[href*="action=sn_reporting_export"]').getAttribute('href');
      const exported = await contexts[who].request.get(exportLink);
      const csv = await exported.text();
      ok(exported.status() === 200 && csv.includes('SYN-FINAL') && !csv.includes('SYN-FOREIGN'), 'CSV and report page use the same scope ' + who);
      ok(csv.includes("'=1+1"), 'report CSV neutralizes a synthetic spreadsheet formula ' + who);
      ok(fixture.tokens.every(token => !csv.includes(token)), 'CSV excludes invoice access tokens ' + who);
      const invalidNonce = new URL(exportLink); invalidNonce.searchParams.set('_wpnonce','invalid-synthetic-nonce');
      ok((await contexts[who].request.get(invalidNonce.href)).status() === 403, 'report export requires its valid nonce ' + who);
      const invalidRange = new URL(exportLink); invalidRange.searchParams.set('date_from','2026-02-30');
      const rejected = await contexts[who].request.get(invalidRange.href);
      ok(rejected.status() === 400 && !(await rejected.text()).includes('SYN-FINAL'), 'invalid export date cannot expand to all invoices ' + who);
      if (who === 'seller') {
        const unrelatedExport = new URL(exportLink); unrelatedExport.searchParams.set('_wpnonce',reportNonces.subscriber);
        ok((await contexts.subscriber.request.get(unrelatedExport.href)).status() === 403, 'unrelated role cannot export even with its own valid reporting nonce');
        const forgedScope = new URL(exportLink); forgedScope.searchParams.set('sn_hierarchy_role','seller'); forgedScope.searchParams.set('sn_hierarchy_user',String(fixture.users.other.id));
        const forgedResult = await contexts.seller.request.get(forgedScope.href);
        ok(forgedResult.status() === 200 && !(await forgedResult.text()).includes('SYN-FOREIGN'), 'forged hierarchy selector never expands export scope');
      }
      const invalidPage = new URL(url); invalidPage.searchParams.set('date_from','<img src=x onerror=window.__snFixtureXss=1>');
      await page.goto(invalidPage.href,{waitUntil:'domcontentloaded'});
      ok(!(await panel.innerText()).includes('SYN-FINAL') && await page.evaluate(() => !window.__snFixtureXss), 'invalid report date is escaped and displays no rows ' + who);
      await page.screenshot({path:path.join(output,'reports-invalid-' + who + '.png'),fullPage:true});
    }
    const managerRange = await post('admin','sn_sales_manager_leads',{date_from:'1405/08/10',date_to:'1405/08/10'});
    ok(managerRange.success, 'native manager report accepts the preserved Jalali date filters');
    const invalidManagerRange = await post('admin','sn_sales_manager_leads',{date_from:'2026-02-30'});
    ok(!invalidManagerRange.success && String(invalidManagerRange.message).includes('معتبر'), 'native manager report rejects an invalid date');
    ok(!(await post('admin','sn_sales_manager_leads',{nonce:''})).success, 'manager report requires a valid nonce');
    ok(!(await post('subscriber','sn_sales_manager_leads',{})).success, 'unrelated role cannot access manager report AJAX');
    // Real concurrent AJAX requests, MySQL advisory/row locks and decision history.
    const concurrent = await Promise.all([post('finance','sn_receipt_review',approveInput),post('finance','sn_receipt_review',approveInput)]);
    ok(concurrent.filter(r => r.success).length === 1, 'concurrent receipt approval succeeds exactly once');
    const already = await post('finance','sn_receipt_review',{...approveInput,revision:'2'});
    ok(already.success && already.already, 'repeat approved receipt is explicitly idempotent');
    for (const who of ['seller','supervisor','senior']) {
      await pages[who].evaluate(() => window.dispatchEvent(new Event('focus')));
      await pollMoney(pages[who], who === 'seller' ? '#sn-seller-kpi-cards .sn-seller-received-amount' : '#sn-supervisor-invoice-kpi-received-amount', 1800000);
      ok(true, 'browser return replaces stale receipt totals ' + who);
    }
    const completed = await post('finance','sn_receipt_review',{...approveInput,payment_id:String(fixture.payments.decide_last)});
    ok(completed.success, 'second real receipt approval completes invoice');
    const completedSummary = await post('seller','sn_seller_invoices',{summary_only:'1'});
    ok(completedSummary.success && Number(completedSummary.received_amount) === 2500000, 'post-approval seller receipt aggregate is exact');
    for (const who of ['supervisor','senior']) {
      await pages[who].evaluate(() => window.snLoadSupervisorInvoicesFinal());
      await pollMoney(pages[who], '#sn-supervisor-invoice-kpi-received-amount', 2500000);
      ok(true, 'reopening panel bypasses stale cached approvals ' + who);
    }
    fs.writeFileSync(path.join(output,'browser-errors.json'), JSON.stringify(pageErrors,null,2));
    ok(pageErrors.length === 0, 'seller/supervisor/senior panels have no uncaught browser errors');
    fs.writeFileSync(path.join(output,'browser-summary.json'), JSON.stringify({assertions:checks,chromium:browser.version(),synthetic:true},null,2));
    console.log('TOTAL ' + checks);
  } catch (error) {
    fs.writeFileSync(path.join(output,'browser-errors.json'), JSON.stringify(pageErrors,null,2));
    for (const [who,page] of Object.entries(pages)) {
      if (!page.isClosed()) await page.screenshot({path:path.join(output,who + '-failure.png'),fullPage:true}).catch(() => {});
    }
    throw error;
  } finally { await browser.close(); }
})().catch(error => { console.error(error.stack); process.exitCode = 1; });
