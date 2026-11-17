'use strict';
// Runs the deployed manager loader with a deterministic jQuery-style transport.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../../assets/js/public-manager.js'), 'utf8');
const start = source.indexOf('  var managerLeadsRequest = null;');
const end = source.indexOf("  $(document).on('click', '#sn-manager-filter'", start);
assert(start >= 0 && end > start);
const html = new Map(), text = new Map(), requests = [];
let filter = 'first', checks = 0;
const ok = (value, label) => { assert(value, label); checks++; console.log('PASS ' + label); };
const escape = value => String(value).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
function $(selector) { return { length:1, html(value) { html.set(selector, value); return this; }, text(value) { text.set(selector, value); return this; } }; }
$.ajax = options => {
  const callbacks = {done:[],fail:[],always:[]}; let settled = false;
  const request = { options, aborted:false,
    done(fn) { callbacks.done.push(fn); return this; }, fail(fn) { callbacks.fail.push(fn); return this; }, always(fn) { callbacks.always.push(fn); return this; },
    abort() { this.aborted = true; this.reject('abort'); },
    resolve(value) { if(settled) return; settled=true; callbacks.done.forEach(fn=>fn(value)); callbacks.always.forEach(fn=>fn()); },
    reject(status, message) { if(settled) return; settled=true; callbacks.fail.forEach(fn=>fn({status:503,responseJSON:message?{message}:undefined},status)); callbacks.always.forEach(fn=>fn()); }
  }; requests.push(request); return request;
};
const context = vm.createContext({ $, snEsc:escape, snManagerDateLabel:value=>value||'—', snManagerFilters:()=>({date_from:filter}), nonce:'synthetic-nonce', ajax:'/synthetic-ajax', window:{console}, console });
vm.runInContext(source.slice(start,end), context);
context.snLoadManagerLeads();
ok(text.get('#sn-manager-total') === '—', 'starting a new filter clears the previous total');
ok(requests[0].options.data.date_from === 'first' && requests[0].options.data.nonce === 'synthetic-nonce', 'loader sends current date filter and nonce');
filter = 'second'; context.snLoadManagerLeads();
ok(requests[0].aborted && requests.length === 2, 'changing filters aborts the previous report request');
ok(!html.get('#sn-manager-leads-list').includes('خطای سرور'), 'intentional abort does not display a server error');
requests[0].resolve({success:true,total:999,items:[]});
ok(text.get('#sn-manager-total') === '—', 'aborted stale response cannot overwrite the active report');
requests[1].resolve({success:true,data:{total:1,legacy_total:1,items:[{phone:'00000000000',city:'<img src=x onerror=1>',imported_at:'1405/08/10'}]}});
ok(text.get('#sn-manager-total') === 1 && html.get('#sn-manager-leads-list').includes('1405/08/10'), 'latest response supplies report count and displayed date');
ok(html.get('#sn-manager-leads-list').includes('&lt;img') && !html.get('#sn-manager-leads-list').includes('<img'), 'report row markup remains escaped');
context.snLoadManagerLeads(); requests[2].resolve({success:false,message:'<script>invalid range</script>'});
ok(html.get('#sn-manager-leads-list').includes('&lt;script&gt;'), 'validation errors remain escaped');
context.snLoadManagerLeads(); requests[3].reject('error','<img src=x>');
ok(html.get('#sn-manager-leads-list').includes('&lt;img'), 'transport error messages remain escaped');
context.snLoadManagerLeads(); requests[4].resolve({success:true,total:0,items:[]});
ok(text.get('#sn-manager-total') === 0 && html.get('#sn-manager-leads-list').includes('موردی با این فیلتر'), 'a successful empty report replaces the prior error');
console.log('TOTAL ' + checks);
