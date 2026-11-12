/* One invoice, several independent transfers; receipts remain attached to their own row. */
(function (w, d) {
  'use strict';
  if (w.snPaymentBatch) return;
  var modal, rowsHost, message, summary, submit, current, returnFocus, busy = false, loaded = false;
  var inlineHost = null, onInlineDone = null, inlineConfirmed = false, available = 0, serial = 0, retryFingerprint = '', retryKey = '', files = new WeakMap();
  var datePickers = new WeakMap();
  var routes = {
    'sn-seller-manual-payment-open': '#sn-seller-manual-payment-modal',
    'sn-supervisor-payment-open': '#sn-supervisor-manual-payment-modal',
    'sn-converter-payment-open': '#sn-converter-manual-payment-modal',
    'sn-supervisor-receipt-open': '#sn-supervisor-receipt-modal',
    'sn-converter-receipt-open': '#sn-converter-receipt-modal'
  };
  function el(tag, cls, text) { var node = d.createElement(tag); if (cls) node.className = cls; if (text != null) node.textContent = text; return node; }
  function en(value) { return String(value || '').replace(/[۰-۹]/g, function (v) { return '۰۱۲۳۴۵۶۷۸۹'.indexOf(v); }).replace(/[٠-٩]/g, function (v) { return '٠١٢٣٤٥٦٧٨٩'.indexOf(v); }); }
  function cents(value) {
    var str = en(value).replace(/[,٬\s]/g, '');
    if (!/^\d+(?:\.\d{1,2})?$/.test(str)) return null;
    var parts = str.split('.'), result = Number(parts[0]) * 100 + Number(((parts[1] || '') + '00').slice(0, 2));
    return Number.isSafeInteger(result) && result > 0 ? result : null;
  }
  function money(value) { return Number(value || 0).toLocaleString('fa-IR') + ' تومان'; }
  function key() {
    if (w.crypto && w.crypto.getRandomValues) { var bytes = new Uint8Array(16); w.crypto.getRandomValues(bytes); return Array.from(bytes, function (b) { return b.toString(16).padStart(2, '0'); }).join(''); }
    return Date.now().toString(36) + '_' + Math.random().toString(36).slice(2) + '_' + Math.random().toString(36).slice(2);
  }
  function config() { return w.snAjax || w.snData || {}; }
  function request(data) {
    return w.fetch(config().ajaxurl || w.ajaxurl || '/wp-admin/admin-ajax.php', {method: 'POST', credentials: 'same-origin', body: data}).then(function (response) {
      return response.json().catch(function () { throw new Error('پاسخ سرور قابل خواندن نیست؛ پس از بررسی اتصال دوباره تلاش کنید.'); });
    });
  }
  function envelope(action) {
    var fd = new FormData(); fd.append('action', action); fd.append('nonce', config().nonce || '');
    fd.append('invoice_code', current.code || ''); fd.append('invoice_id', current.id || ''); fd.append('access_token', current.token || ''); return fd;
  }
  function payload(res) { return res && res.data && typeof res.data === 'object' ? res.data : res || {}; }
  function fail(text, row) { message.textContent = text; message.className = 'sn-batch-message is-error'; if (row) row.scrollIntoView({block:'nearest'}); }
  function field(row, label, name, type, options) {
    var wrap = el('label', 'sn-batch-field'), input = el(type === 'select' ? 'select' : 'input');
    if (type !== 'select') input.type = type || 'text';
    input.name = name; input.id = 'sn-batch-' + serial + '-' + name; wrap.append(el('span', '', label));
    if (options) options.forEach(function (option) { var node = el('option', '', option[1]); node.value = option[0]; input.append(node); });
    wrap.append(input); row.append(wrap); return input;
  }
  function selected(row) { return files.get(row) || []; }
  function showFiles(row) {
    var host = row.querySelector('.sn-batch-files'); host.replaceChildren();
    selected(row).forEach(function (file, index) {
      var chip = el('div', 'sn-batch-file'), label = el('span', '', file.name), remove = el('button', 'sn-batch-remove-file', 'حذف');
      remove.type = 'button'; remove.setAttribute('aria-label', 'حذف فایل ' + file.name);
      remove.addEventListener('click', function () { if (busy) return; var list = selected(row).slice(); list.splice(index, 1); files.set(row, list); showFiles(row); update(); });
      chip.append(label, remove); host.append(chip);
    });
  }
  function updateRow(row) {
    var receipt = row.querySelector('[name="entry_mode"]').value === 'receipt', card = row.querySelector('[name="manual_transfer_type"]').value === 'card';
    row.querySelector('.sn-batch-info').hidden = receipt;
    var receiptCards = row.querySelector('.sn-batch-receipt-cards'); if (receiptCards) receiptCards.hidden = !receipt;
    row.querySelector('.sn-batch-card').hidden = !card;
    row.querySelector('.sn-batch-bank').hidden = card;
    row.querySelector('.sn-batch-upload-title').textContent = receipt ? 'فیش‌های این واریزی' : 'فیش‌های این واریزی (اختیاری)';
  }
  function addRow(mode) {
    if (busy || rowsHost.children.length >= 10) return;
    serial++;
    var row = el('fieldset', 'sn-batch-row'), title = el('legend', '', 'واریزی'), grid = el('div', 'sn-batch-grid');
    row.append(title, grid); files.set(row, []);
    var amount = field(grid, 'مبلغ این واریزی (تومان)', 'amount', 'text'); amount.inputMode = 'decimal'; amount.placeholder = 'مثلاً ۴۰۰٬۰۰۰';
    var method = field(grid, 'روش ثبت', 'entry_mode', 'select', [['receipt','بارگذاری فیش'],['info','ورود اطلاعات واریزی']]); method.value = mode === 'info' ? 'info' : 'receipt';
    if (current.internal) {
      var receiptCards = el('div','sn-batch-receipt-cards sn-batch-grid'); row.append(receiptCards);
      ['from','to'].forEach(function (side) { var input = field(receiptCards, '۴ رقم آخر کارت ' + (side === 'from' ? 'مبدأ' : 'مقصد') + ' (اختیاری)', 'receipt_card_' + side, 'text'); input.maxLength = 4; input.inputMode = 'numeric'; input.autocomplete = 'off'; });
    }
    var info = el('div', 'sn-batch-info sn-batch-grid'); row.append(info);
    field(info, 'روش انتقال', 'manual_transfer_type', 'select', [['card','کارت‌به‌کارت'],['paya','پایا'],['pol','پل'],['account','حساب‌به‌حساب']]);
    var card = el('div', 'sn-batch-card'); info.append(card);
    var from = field(card, '۴ رقم آخر کارت مبدأ', 'card_from', 'text'); from.maxLength = 4; from.inputMode = 'numeric';
    var bank = el('div', 'sn-batch-bank sn-batch-grid'); info.append(bank);
    var owner = field(bank, 'نام صاحب حساب مبدأ', 'manual_account_owner', 'text'); owner.maxLength = 191;
    ['manual_account_last6','manual_tracking_last6'].forEach(function (name, i) { var input = field(bank, i ? '۶ رقم آخر پیگیری (اختیاری)' : '۶ رقم آخر حساب مبدأ (اختیاری)', name, 'text'); input.maxLength = 6; input.inputMode = 'numeric'; });
    if (current.internal) { var to = field(info, '۴ رقم آخر کارت مقصد (اختیاری)', 'card_to', 'text'); to.maxLength = 4; to.inputMode = 'numeric'; }
    var date = field(info, 'تاریخ شمسی و ساعت تهران واریز', 'paid_at', 'text'); date.placeholder = '۱۴۰۵/۰۸/۰۴ ۱۴:۳۰'; date.dir = 'ltr'; date.maxLength = 16;
    var picker = current.internal && w.snPaymentDateTimePicker && w.snPaymentDateTimePicker(date);
    if (picker) datePickers.set(row, picker);
    var help = el('small', 'sn-batch-date-help', picker ? 'تاریخ و ساعت واقعی واریز را انتخاب کنید.' : 'زمان واقعی واریز را با قالب سال/ماه/روز ساعت:دقیقه وارد کنید.'); info.append(help);
    var uploadLabel = el('label', 'sn-batch-upload'), caption = el('span', 'sn-batch-upload-title'), upload = el('input', 'sn-batch-receipt');
    upload.type = 'file'; upload.multiple = true; upload.accept = '.jpg,.jpeg,.png,.webp,.pdf'; uploadLabel.append(caption, upload);
    row.append(uploadLabel, el('div', 'sn-batch-files'));
    upload.addEventListener('change', function () {
      var incoming = Array.from(upload.files || []), list = selected(row).slice();
      incoming.forEach(function (file) { if (!list.some(function (old) { return old.name === file.name && old.size === file.size && old.lastModified === file.lastModified; })) list.push(file); });
      var all = Array.from(rowsHost.children).reduce(function (out, other) { return out.concat(other === row ? list : selected(other)); }, []);
      var error = w.snReceiptFiles.error(all);
      if (error) fail(error, row); else { files.set(row, list); showFiles(row); }
      upload.value = ''; update();
    });
    var remove = el('button', 'sn-btn sn-btn-secondary sn-batch-remove-row', 'حذف واریزی'); remove.type = 'button'; row.append(remove);
    remove.addEventListener('click', function () { if (busy) return; if (rowsHost.children.length === 1) { fail('حداقل یک واریزی لازم است.'); return; } row.remove(); update(); });
    row.addEventListener('input', update); row.addEventListener('change', function () { updateRow(row); update(); });
    rowsHost.append(row); updateRow(row); update(); amount.focus();
  }
  function update() {
    if (inlineConfirmed && inlineHost && !busy) {inlineConfirmed=false;if(submit)submit.textContent='بررسی و ثبت واریزی‌ها';var review=modal&&modal.querySelector('.sn-batch-inline-review');if(review)review.remove();}
    var total = 0;
    Array.from(rowsHost.children).forEach(function (row, i) { row.querySelector('legend').textContent = 'واریزی ' + (i+1); total += cents(row.querySelector('[name="amount"]').value) || 0; });
    summary.textContent = 'جمع واریزی‌های این نوبت: ' + money(total / 100) + (loaded ? ' · مانده قابل ثبت: ' + money(available / 100) : ' · در حال دریافت مانده...');
    summary.classList.toggle('is-error', loaded && total > available);
    modal.querySelector('.sn-batch-add').disabled = busy || rowsHost.children.length >= 10;
    submit.disabled = busy || !loaded;
    Array.from(rowsHost.children).forEach(function(row){row.disabled = busy || !loaded;});
  }
  function collect() {
    var rows = [], all = [], total = 0;
    Array.from(rowsHost.children).forEach(function (row, index) {
      var data = {}, amount = cents(row.querySelector('[name="amount"]').value);
      if (amount === null) throw new Error('واریزی ' + (index+1) + ': مبلغ معتبر وارد کنید.');
      row.querySelectorAll('[name]').forEach(function (input) { data[input.name] = en(input.value).trim(); });
      data.amount = (amount / 100).toFixed(2); data.receipt_count = selected(row).length;
      if (data.entry_mode === 'receipt') {
        data.card_from = data.receipt_card_from || ''; data.card_to = data.receipt_card_to || '';
        ['card_from','card_to'].forEach(function (name) { if (data[name] && !/^\d{4}$/.test(data[name])) throw new Error('واریزی ' + (index+1) + ': چهار رقم کارت اختیاری است؛ در صورت ورود باید دقیقاً چهار رقم باشد.'); });
      }
      delete data.receipt_card_from; delete data.receipt_card_to;
      if (data.entry_mode === 'receipt' && !data.receipt_count) throw new Error('واریزی ' + (index+1) + ': فیش را انتخاب کنید.');
      if (data.entry_mode === 'info') {
        if (!/^\d{4}\/\d{2}\/\d{2} \d{2}:\d{2}$/.test(data.paid_at)) throw new Error('واریزی ' + (index+1) + ': تاریخ شمسی و ساعت را کامل وارد کنید.');
        if (data.manual_transfer_type === 'card' && !/^\d{4}$/.test(data.card_from)) throw new Error('واریزی ' + (index+1) + ': چهار رقم کارت مبدأ لازم است.');
        if (data.card_to && !/^\d{4}$/.test(data.card_to)) throw new Error('واریزی ' + (index+1) + ': مقصد باید چهار رقم باشد.');
        if (data.manual_transfer_type !== 'card') {
          if (!data.manual_account_owner) throw new Error('واریزی ' + (index+1) + ': نام صاحب حساب را وارد کنید.');
          ['manual_account_last6','manual_tracking_last6'].forEach(function (name) { if (data[name] && !/^\d{6}$/.test(data[name])) throw new Error('واریزی ' + (index+1) + ': حساب و پیگیری باید شش رقم باشند.'); });
        }
      }
      total += amount; all = all.concat(selected(row)); rows.push(data);
    });
    var error = w.snReceiptFiles.error(all); if (error) throw new Error(error);
    if (total > available) throw new Error('جمع واریزی‌ها از مانده قابل ثبت این مرحله بیشتر است.');
    return rows;
  }
  function lock(value) { busy = value; modal.querySelectorAll('input,select,button').forEach(function (input) { input.disabled = value; }); update(); }
  function save() {
    if (busy || !loaded) return;
    var rows;
    try { rows = collect(); } catch (error) { fail(error.message); return; }
    if (inlineHost && !inlineConfirmed) {
      var review=el('div','sn-batch-inline-review');
      review.setAttribute('role','status');
      review.textContent='بررسی نهایی: '+rows.length+' واریزی به مبلغ '+money(rows.reduce(function(t,row){return t+Number(row.amount);},0))+' روی همین فاکتور ثبت می‌شود. پس از تأیید، واریزی‌ها برای بررسی مالی ارسال خواهند شد.';
      message.replaceChildren(review);
      inlineConfirmed=true;submit.textContent='تأیید نهایی و ارسال برای مالی';return;
    }
    var fingerprint = JSON.stringify([current.code,current.id,rows,Array.from(rowsHost.children).map(function (row) { return selected(row).map(function (file) { return [file.name,file.size,file.lastModified]; }); })]);
    if (fingerprint !== retryFingerprint) { retryFingerprint = fingerprint; retryKey = key(); }
    var fd = envelope('sn_submit_payment_batch'); fd.append('batch_key', retryKey); fd.append('payments', JSON.stringify(rows));
    Array.from(rowsHost.children).forEach(function (row, index) { selected(row).forEach(function (file) { fd.append('receipt_' + index + '[]', file); }); });
    lock(true); message.className = 'sn-batch-message'; message.textContent = 'در حال ثبت مجموعه واریزی‌ها...';
    request(fd).then(function (res) {
      var data = payload(res);
      if (!res || !res.success) { var rejected = new Error(data.message || res && res.message || 'ثبت مجموعه انجام نشد.'); rejected.isBusiness = true; throw rejected; }
      message.textContent = data.message || res.message || rows.length + ' واریزی جداگانه برای بررسی مالی ثبت شد.'; message.className = 'sn-batch-message is-success';
      modal.querySelector('.sn-batch-finish').hidden = false;
      if (inlineHost && onInlineDone) onInlineDone(data);
      // A successful response is final. Keep save disabled, including on repeated clicks.
      modal.querySelector('.sn-batch-finish').disabled = false;
      loaded = false; retryFingerprint = ''; retryKey = '';
      w.dispatchEvent(new CustomEvent('sn:payment-batch-saved', {detail: {invoice_code:current.code,invoice_id:current.id}}));
    }).catch(function (error) {
      fail((error && error.message || 'ارتباط با سرور قطع شد.') + (error && error.isBusiness ? '' : ' پاسخ نهایی مشخص نیست؛ ابتدا تراکنش‌ها را بررسی کنید. در صورت نیاز، همین فرم را بدون تغییر دوباره ثبت کنید تا شناسه جلوگیری از تکرار حفظ شود.'));
    }).finally(function () {
      if (!loaded) { busy = false; modal.querySelector('.sn-batch-close').disabled = false; return; }
      lock(false);
    });
  }
  function close() {
    if (busy) return;
    if (loaded && Array.from(rowsHost.children).some(function (row) { return row.querySelector('[name="amount"]').value || selected(row).length; }) && !w.confirm('اطلاعات ثبت‌نشده این مجموعه کنار گذاشته شود؟')) return;
    modal.hidden = true; rowsHost.replaceChildren(); d.body.classList.remove('sn-batch-open');
    if (inlineHost) { modal.remove(); modal = null; inlineHost = null; onInlineDone = null; }
    if (returnFocus && returnFocus.isConnected) returnFocus.focus();
  }
  function ensure() {
    if (modal) return;
    modal = el('div', 'sn-batch-modal'); modal.id = 'sn-payment-batch-modal'; modal.hidden = true; modal.dir = 'rtl'; modal.setAttribute('role','dialog'); modal.setAttribute('aria-modal','true'); modal.setAttribute('aria-labelledby','sn-payment-batch-title');
    var card = el('section', 'sn-batch-card-shell'), head = el('header', 'sn-batch-head'), title = el('h3', '', 'ثبت چند واریزی'); title.id = 'sn-payment-batch-title';
    var closeButton = el('button','sn-batch-close','×'); closeButton.type = 'button'; closeButton.setAttribute('aria-label','بستن'); closeButton.addEventListener('click',close); head.append(title,closeButton);
    rowsHost = el('div','sn-batch-rows'); message = el('div','sn-batch-message'); message.setAttribute('role','status'); message.setAttribute('aria-live','polite'); summary = el('p','sn-batch-summary');
    var add = el('button','sn-btn sn-btn-secondary sn-batch-add','+ افزودن واریزی دیگر'); add.type = 'button'; add.addEventListener('click',function(){ addRow(current.mode); });
    submit = el('button','sn-btn sn-btn-primary sn-batch-submit','ثبت همه واریزی‌ها'); submit.type = 'button'; submit.addEventListener('click',save);
    var finish = el('button','sn-btn sn-btn-primary sn-batch-finish','مشاهده وضعیت واریزی‌ها'); finish.type = 'button'; finish.hidden = true; finish.addEventListener('click',function(){if(inlineHost&&current&&typeof current.onRefresh==='function'){if(busy)return;var refresh=current.onRefresh;unmountInline();refresh();}else w.location.reload();});
    var footer = el('footer','sn-batch-footer'); footer.append(add,submit,finish);
    card.append(head,el('p','sn-batch-help','برای هر واریزی مبلغ جداگانه وارد کنید و فیش‌ها یا مشخصات همان انتقال را اضافه کنید. تا ۱۰ واریزی و مجموعاً ۱۰ فیش؛ هر فایل ۵ و مجموع فایل‌ها ۲۰ مگابایت. مبالغ به تومان است.'),summary,rowsHost,message,footer); modal.append(card); d.body.append(modal);
    modal.addEventListener('keydown',function(event){
      if(event.key==='Escape' && !inlineHost){ event.preventDefault(); event.stopPropagation(); close(); }
      if(event.key==='Tab' && !inlineHost){
        var targets=Array.from(modal.querySelectorAll('button,input,select')).filter(function(input){return !input.disabled && input.getClientRects().length;});
        var first=targets[0],last=targets[targets.length-1];
        if(event.shiftKey&&d.activeElement===first){event.preventDefault();last.focus();}else if(!event.shiftKey&&d.activeElement===last){event.preventDefault();first.focus();}
      }
    });
  }
  function open(context, trigger) {
    ensure(); current = context; returnFocus = trigger; inlineConfirmed=false; busy = false; loaded = false; available = 0; retryFingerprint = ''; retryKey = '';
    rowsHost.replaceChildren(); modal.querySelector('.sn-batch-finish').hidden = true; modal.hidden = false; if (!inlineHost) d.body.classList.add('sn-batch-open');
    modal.querySelectorAll('button').forEach(function(button){button.disabled=false;}); message.textContent = ''; addRow(current.mode);
    if(current.seed){var first=rowsHost.firstElementChild;Object.keys(current.seed).forEach(function(name){var input=first.querySelector('[name="'+name+'"]');if(input&&current.seed[name]!=='')input.value=current.seed[name];});if(datePickers.has(first))datePickers.get(first).sync();if(current.receipts)files.set(first,current.receipts.slice());showFiles(first);updateRow(first);}
    if(context.draft && String(context.draft.id)===String(context.id) && context.draft.code===context.code){
      rowsHost.replaceChildren();context.draft.rows.forEach(function(saved){addRow(current.mode);var row=rowsHost.children[rowsHost.children.length-1];Object.keys(saved.values).forEach(function(name){var input=row.querySelector('[name="'+name+'"]');if(input)input.value=saved.values[name];});if(datePickers.has(row))datePickers.get(row).sync(saved.dateParts);files.set(row,(saved.receipts||[]).slice());showFiles(row);updateRow(row);});retryFingerprint=context.draft.retryFingerprint||'';retryKey=context.draft.retryKey||'';
    }
    update();
    request(envelope('sn_invoice_transactions')).then(function(res){
      if (!modal || modal.hidden || current !== context) return;
      var data=payload(res); if(!res||!res.success) throw new Error(data.message||'مانده قابل ثبت دریافت نشد.');
      if(data.gateway_guard && data.gateway_guard.blocked){loaded=false;update();fail(data.gateway_guard.message+' به وضعیت فاکتور برگردید تا درخواست درگاه بررسی شود.');return;}
      available=Math.round(Number((data.transaction_summary||{}).available_amount||0)*100); loaded=true; update();
      if(available<=0)fail('مانده قابل ثبت این مرحله صفر است؛ تراکنش‌های قبلی را بررسی کنید.');
    }).catch(function(error){if(modal&&current===context&&!modal.hidden)fail(error.message+' فرم را ببندید و دوباره باز کنید.');});
  }
  function getDraft() {
    if(!inlineHost || !loaded || busy || !rowsHost) return null;
    var rows = Array.from(rowsHost.children).map(function(row){
      var values={}, picker=datePickers.get(row);
      row.querySelectorAll('[name]').forEach(function(input){values[input.name]=input.value;});
      return {values:values,receipts:selected(row).slice(),dateParts:picker?picker.parts():undefined};
    });
    return {id:current.id,code:current.code,retryFingerprint:retryFingerprint,retryKey:retryKey,rows:rows};
  }
  function customerContext() {
    var page=d.querySelector('#sn-invoice-page'),display=d.querySelector('#sn-inv-display-code'),input=d.querySelector('#sn-inv-code');
    return {code:(display&&display.textContent.trim())||(input&&input.value)||(page&&page.getAttribute('data-code'))||'',token:page&&(page.getAttribute('data-payment-token')||page.getAttribute('data-token'))||config().invoice_token||'',internal:false,mode:'receipt'};
  }
  function mount(root) {
    if (!root || root.querySelector('.sn-batch-launch')) return;
    var button=el('button','sn-btn sn-btn-secondary sn-batch-launch','ثبت چند واریزی (فیش یا اطلاعات)'); button.type='button';
    button.addEventListener('click',function(){
      var context=root._snBatchContext;
      if(root.id==='sn-card-info')context=customerContext();
      if(root.matches('[data-sn-ops-manual-payment]'))context={code:root.getAttribute('data-invoice-code')||'',internal:true,mode:'info'};
      if(!context||(!context.code&&!context.id)){w.alert('فاکتور مشخص نیست؛ فرم را ببندید و دوباره باز کنید.');return;}
      var next=Object.assign({},context),seed={},prefix={'sn-seller-manual-payment-modal':'sn-seller-manual','sn-supervisor-manual-payment-modal':'sn-supervisor-payment','sn-converter-manual-payment-modal':'sn-converter-payment'}[root.id];
      function value(selector){var input=root.querySelector(selector);return input?input.value:'';}
      var receipt;
      if(prefix){next.mode=root.getAttribute('data-entry-mode')==='receipt'?'receipt':'info';['amount','card_from','card_to','manual_transfer_type'].forEach(function(name){var suffix={card_from:'card-from',card_to:'card-to',manual_transfer_type:'transfer-type'}[name]||name;seed[name]=value('#'+prefix+'-'+suffix);});var date=value('#'+prefix+'-paid-date')||value('#'+prefix+'-date');
        var hour=value('#'+prefix+'-hour'), minute=value('#'+prefix+'-minute');
        seed.paid_at=date&&hour&&minute?date+' '+hour+':'+minute:'';
        ['manual_account_last6','manual_tracking_last6','manual_account_owner'].forEach(function(name){seed[name]=value('[name="'+name+'"]');});receipt=root.querySelector('#'+prefix+'-receipt');}
      else if(root.id==='sn-card-info'){next.mode=value('#sn-card-from4')?'info':'receipt';seed.amount=value(next.mode==='info'?'#sn-card-amount':'#sn-receipt-amount');seed.card_from=value('#sn-card-from4');seed.paid_at=value('#sn-card-paid-at');receipt=root.querySelector(next.mode==='info'?'#sn-card-manual-receipt':'#sn-receipt-file');}
      else if(root.matches('[data-sn-ops-manual-payment]')){seed.amount=value('[name="ops_manual_amount"]');seed.card_from=value('[name="ops_card_last4"]');seed.card_to=value('[name="ops_destination_last4"]');seed.paid_at=value('[name="ops_paid_at_jalali"]')+' '+value('[name="ops_paid_time"]');receipt=root.querySelector('[name="ops_receipt"]');next.mode=seed.card_from?'info':'receipt';}
      else{seed.amount=value('[id$="-amount"]');seed.card_to=value('[id$="-card-to"]');receipt=root.querySelector('input[type="file"]');}
      next.seed=seed;next.receipts=Array.from(receipt&&receipt.files||[]);open(next,button);
    });
    (root.querySelector('.sn-modal-body')||root).append(button);
  }
  function scan() { d.querySelectorAll('#sn-card-info,#sn-seller-manual-payment-modal,#sn-supervisor-manual-payment-modal,#sn-converter-manual-payment-modal,#sn-supervisor-receipt-modal,#sn-converter-receipt-modal,[data-sn-ops-manual-payment]').forEach(mount); }
  d.addEventListener('click',function(event){
    var button=event.target.closest('button,a');if(!button)return;
    Object.keys(routes).forEach(function(cls){if(!button.classList.contains(cls))return;
      var context={code:button.getAttribute('data-invoice-code')||'',id:button.getAttribute('data-invoice-id')||'',internal:true,mode:cls.indexOf('receipt')>=0?'receipt':'info'};
      w.setTimeout(function(){var root=d.querySelector(routes[cls]);if(root){root._snBatchContext=context;mount(root);}},0);
    });
  },true);
  function start(){scan();if(w.MutationObserver)new MutationObserver(function(changes){if(changes.some(function(change){return Array.from(change.addedNodes).some(function(node){return node.nodeType===1&&!node.closest('#sn-payment-batch-modal')&&!node.classList.contains('sn-batch-launch');});}))scan();}).observe(d.body,{childList:true,subtree:true});}
  if(d.readyState==='loading')d.addEventListener('DOMContentLoaded',start);else start();
  function mountInline(host, context, onDone) {
    if (!host || !context || (!context.id && !context.code)) throw new Error('اطلاعات فاکتور ناقص است.');
    if (modal && !modal.hidden) throw new Error('یک فرم واریزی دیگر فعال است.');
    if (modal) { modal.remove(); modal = null; }
    inlineConfirmed=false;
    inlineHost = host; onInlineDone = onDone; ensure();
    host.append(modal); modal.classList.add('sn-batch-inline');
    modal.removeAttribute('role'); modal.removeAttribute('aria-modal'); modal.removeAttribute('aria-labelledby');
    var closeButton=modal.querySelector('.sn-batch-close');
    if(closeButton)closeButton.hidden=true;
    open(context, null);
    submit.textContent='بررسی و ثبت واریزی‌ها';
    var finish=modal.querySelector('.sn-batch-finish');if(finish)finish.textContent='بازخوانی وضعیت فاکتور';
  }
  function unmountInline() {
    if (!inlineHost) return;
    if (busy) return;
    modal.hidden=true; modal.remove(); modal=null; inlineHost=null; onInlineDone=null;
    rowsHost=null; current=null; loaded=false; inlineConfirmed=false;
  }
  w.snPaymentBatch={open:open,cents:cents,getDraft:getDraft,mountInline:mountInline,unmountInline:unmountInline,isBusy:function(){return busy;}};
})(window,document);
