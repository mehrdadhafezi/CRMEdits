/* Runs the production panel functions with a synthetic DOM/transport, never a live CRM. */
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const root = path.resolve(__dirname, '../..');
let checks = 0;
function check(name, run) { run(); checks++; console.log('PASS ' + name); }

function harness() {
  const values = new Map(), handlers = new Map(), text = new Map(), html = new Map();
  const document = { hidden: false }, window = {};
  let requests = 0, now = 1000, response = { success: true, items: [], has_more: false };
  const collection = selector => ({
    length: 1,
    val(v) { if (arguments.length) { values.set(selector, v); return this; } return values.get(selector) || ''; },
    text(v) { if (arguments.length) { text.set(selector, String(v)); return this; } return text.get(selector) || ''; },
    html(v) { if (arguments.length) { html.set(selector, String(v)); return this; } return html.get(selector) || ''; },
    data(key) { return key === 'status' ? 'all' : ''; },
    is() { return true; },
    find(s) { return collection(selector + ' ' + s); },
    on(events, ...args) { for (const event of events.split(' ')) handlers.set(event, args.at(-1)); return this; },
    off() { return this; }, show() { return this; }, hide() { return this; }, append() { return this; }, prepend() { return this; },
    removeClass() { return this; }, addClass() { return this; }, filter() { return this; }, attr() { return this; }
  });
  function $(selector) { return collection(selector); }
  $.ajax = () => {
    requests++;
    const xhr = { readyState: 4, done(fn) { fn(response); return this; }, fail() { return this; } };
    return xhr;
  };
  window.snInvoicePaymentButtons = () => '';
  window.snReceiptFiles = { links: () => '' };
  const context = vm.createContext({ jQuery: $, $, window, document, Date: { now: () => now }, console, setTimeout, clearTimeout });
  return { context, window, document, text, html, handlers, get requests() { return requests; },
    respond(value) { response = value; }, tick(ms) { now += ms; } };
}

const supervisorSource = fs.readFileSync(path.join(root, 'assets/js/public-supervisor.js'), 'utf8');
const start = supervisorSource.indexOf('/* SN 1.0.128 supervisor invoice tab:');
const end = supervisorSource.indexOf('/* SN 1.0.67 finance review hard fix:', start);
assert(start >= 0 && end > start, 'Production supervisor invoice module must exist');
const h = harness();
vm.runInContext(supervisorSource.slice(start, end), h.context);
const partial = { id: 1, invoice_code: 'SYN-PARTIAL', status: 'pending_financial_approval', payment_status: 'pending_financial_approval',
  status_label: 'بخشی از واریزی تأیید شده؛ مانده فاکتور تسویه نشده', final_total: 1000,
  payment_summary: { total: 1000, paid: 300, remaining: 700, due: 700, stage_no: 1 }, received_amount: 300, is_fully_settled: false };
const complete = { id: 2, invoice_code: 'SYN-COMPLETE', status: 'approved', payment_status: 'pending_financial_approval',
  status_label: 'تایید شده مالی', final_total: 1200,
  payment_summary: { total: 1200, paid: 1200, remaining: 0, due: 0, stage_no: 1 }, received_amount: 1200, is_fully_settled: true };
h.respond({ success: true, items: [partial, complete], has_more: false });
h.window.snLoadSupervisorInvoicesFinal();
check('approved partial receipts appear in the visible-list receipt total', () => assert.equal(h.text.get('#sn-supervisor-invoice-kpi-received-amount'), (1500).toLocaleString('fa-IR') + ' تومان'));
check('completed count uses the server decision despite a stale payment_status', () => assert.equal(h.text.get('#sn-supervisor-invoice-kpi-paid-count'), (1).toLocaleString('fa-IR')));
check('completed-sales amount excludes the unfinished invoice', () => assert.equal(h.text.get('#sn-supervisor-invoice-kpi-paid-amount'), (1200).toLocaleString('fa-IR') + ' تومان'));
check('partial and complete approval labels are both visible', () => assert([...h.html.values()].some(s => s.includes(partial.status_label) && s.includes(complete.status_label))));
let before = h.requests;
h.window.snLoadSupervisorInvoicesFinal();
check('reopening the invoice panel fetches fresh financial state', () => assert.equal(h.requests, before + 1));
before = h.requests;
h.window.snLoadSupervisorInvoicesFinal(null, false);
check('explicit cache reuse is bounded to fresh entries', () => assert.equal(h.requests, before));
h.tick(15001);
h.window.snLoadSupervisorInvoicesFinal(null, false);
check('expired invoice cache cannot hide a later finance approval', () => assert.equal(h.requests, before + 1));
h.respond({ success: true, items: [{ ...partial, received_amount: 1000, is_fully_settled: true }, complete], has_more: false });
before = h.requests;
h.handlers.get('focus.snSupInvoiceFresh')();
check('returning to the browser refreshes invoice data', () => assert.equal(h.requests, before + 1));
check('refreshed receipt totals replace stale values', () => assert.equal(h.text.get('#sn-supervisor-invoice-kpi-received-amount'), (2200).toLocaleString('fa-IR') + ' تومان'));
h.document.hidden = true; before = h.requests;
h.handlers.get('visibilitychange.snSupInvoiceFresh')();
check('hidden documents do not generate refresh traffic', () => assert.equal(h.requests, before));
h.document.hidden = false;
h.respond({ success: true, items: [{ ...complete, received_amount: 0, payment_summary: { paid: 999 }, status_label: '<img src=x onerror=alert(1)>' }], has_more: false });
h.window.snLoadSupervisorInvoicesFinal();
check('authoritative zero receipts never fall back to old monetary values', () => assert.equal(h.text.get('#sn-supervisor-invoice-kpi-received-amount'), (0).toLocaleString('fa-IR') + ' تومان'));
check('financial status text remains HTML escaped', () => assert([...h.html.values()].some(s => s.includes('&lt;img')) && ![...h.html.values()].some(s => s.includes('<img src=x'))));
h.respond({ success: true, items: [{id:9,status:'financial_approved',payment_status:'financial_approved',final_total:100}], has_more:false });
h.window.snLoadSupervisorInvoicesFinal();
check('legacy successful-status aliases retain their existing completed count', () => assert.equal(h.text.get('#sn-supervisor-invoice-kpi-paid-count'), (1).toLocaleString('fa-IR')));

const sellerSource = fs.readFileSync(path.join(root, 'assets/js/public-seller.js'), 'utf8');
function productionSellerFunction(name) {
  const start = sellerSource.indexOf('    function ' + name + '(');
  assert(start >= 0, 'Production function exists: ' + name);
  const end = sellerSource.indexOf('\n    }', start);
  assert(end > start);
  return sellerSource.slice(start, end + '\n    }'.length);
}
const seller = harness();
Object.assign(seller.context, { sellerLeadMeta: {}, allLeads: [], sellerInvoiceSummary: {}, sellerInvoices: [], sellerPaidAmount: 0, sellerReceivedAmount: 0,
  snFormatNumber: v => String(v), snFormatMoney: v => String(v), snPercent: () => '0%', sellerLeadHasNoStatus: () => false });
vm.runInContext(productionSellerFunction('renderSellerKpis') + '\n' + productionSellerFunction('updateInvoiceSummary'), seller.context);
seller.context.updateInvoiceSummary({ success: true, summary: { paid: 1, pre_invoice: 1 }, paid_amount: 1200, received_amount: 1500 });
check('seller dashboard shows received money separately from completed sales', () => {
  const markup = seller.html.get('#sn-seller-kpi-cards');
  assert(markup.includes('واریزی تأییدشده: 1500') && markup.includes('<strong>1200</strong>'));
});
seller.context.updateInvoiceSummary({ success: false, received_amount: 99999 });
check('failed responses cannot overwrite the last valid seller summary', () => assert.equal(seller.context.sellerReceivedAmount, 1500));
seller.context.updateInvoiceSummary({ success: true, summary: { paid: 0, pre_invoice: 0 }, paid_amount: 0, received_amount: 0 });
check('seller receipt amount can return to an authoritative zero', () => assert.equal(seller.context.sellerReceivedAmount, 0));

// Keep the installed 2.0.236 finance fixes while adding seller/supervisor fixes.
const managerSource = fs.readFileSync(path.join(root, 'assets/js/public-manager.js'), 'utf8');
const managerStart = managerSource.indexOf('  function statusClass(i){');
const managerEnd = managerSource.indexOf('\n  }', managerStart);
assert(managerStart >= 0 && managerEnd > managerStart);
const finance = vm.createContext({});
vm.runInContext(managerSource.slice(managerStart, managerEnd + '\n  }'.length), finance);
check('installed-version finance bucket styling remains authoritative', () => assert.equal(finance.statusClass({finance_bucket:'approved',status:'pending_financial_approval'}), 'is-approved'));

const receiptSource = fs.readFileSync(path.join(root, 'assets/js/receipt-review.js'), 'utf8');
const refresh = receiptSource.match(/^ function refresh\(\)\{[^\n]+\}$/m);
assert(refresh, 'Installed receipt refresh function must be retained');
let listRefreshes = 0, receiptRefreshes = 0, deferred;
const receipt = vm.createContext({ host: {__snReceiptReview:{canClose: () => true}}, changed: () => listRefreshes++, load: () => receiptRefreshes++ });
vm.runInContext(refresh[0], receipt);
receipt.refresh();
check('installed receipt refresh still refreshes both financial list and receipt detail', () => assert.equal(listRefreshes === 1 && receiptRefreshes === 1, true));
receipt.host.__snReceiptReview.canClose = callback => { deferred = callback; return false; };
receipt.refresh();
check('receipt refresh preserves the existing unsaved-edit guard', () => {
  assert.equal(listRefreshes, 1);
  assert.equal(receiptRefreshes, 1);
  deferred();
  assert.equal(listRefreshes, 2);
  assert.equal(receiptRefreshes, 2);
});
console.log('TOTAL ' + checks);
