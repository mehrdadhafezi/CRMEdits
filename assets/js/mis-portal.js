(function () {
  'use strict';
  if (window.__snMisV4) { window.__snMisV4.mount(); return; }
  var activeNavigation, sequence = 0, jobRunning = false, pendingNavigation, mountedUrl = location.href;
  function root() { return document.querySelector('.sn-mis-v2'); }
  function node(tag, text, className) { var el = document.createElement(tag); if (text) el.textContent = text; if (className) el.className = className; return el; }
  function error(message, data) { var e = new Error(message); e.data = data || {}; return e; }
  async function request(api, fields, signal) {
    var body = fields instanceof FormData ? fields : new URLSearchParams(fields);
    var response = await fetch(api, { method: 'POST', body: body, credentials: 'same-origin', signal: signal, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
    var text = await response.text(), result;
    try { result = JSON.parse(text); } catch (_) {
      if (text.trim() === '-1' || (response.redirected && /wp-login/.test(response.url))) throw error('اعتبار صفحه تمام شده است؛ پس از نگه‌داشتن متن فرم، صفحه را تازه کنید.', { code: 'session_expired', requires_reload: true });
      throw error(response.status === 504 ? 'پاسخ سرور به‌موقع نرسید.' : 'پاسخ کامل و معتبر از سرور دریافت نشد.', { code: 'incomplete_response', http_status: response.status });
    }
    if (!response.ok || !result || result.success !== true) {
      var data = result && result.data || {};
      throw error(data.message || (response.status === 403 ? 'دسترسی این بخش برای حساب شما مجاز نیست.' : 'درخواست کامل نشد.'), data);
    }
    return result.data;
  }
  function status(message, isError, action) {
    var r = root(), box = r && r.querySelector('.sn-mis-navigation-status'); if (!box) return;
    box.replaceChildren(node('span', message)); box.hidden = false; box.classList.toggle('is-error', !!isError);
    if (action) { var button = node('button', action.label, 'sn-btn sn-btn-secondary'); button.type = 'button'; button.addEventListener('click', action.run); box.append(button); }
  }
  function askLeave(url, push) {
    var r = root(); if (!r) return;
    pendingNavigation = { url: url, push: push };
    status('اطلاعات این فرم هنوز ثبت نشده است. با رفتن به بخش دیگر، تغییرات واردشده از دست می‌رود.', true);
    var box = r.querySelector('.sn-mis-navigation-status'), stay = node('button', 'ادامه در همین فرم', 'sn-btn sn-btn-primary'), leave = node('button', 'رفتن بدون ثبت', 'sn-btn');
    stay.type = leave.type = 'button';
    stay.onclick = function () { box.hidden = true; pendingNavigation = null; };
    leave.onclick = function () { var target = pendingNavigation; pendingNavigation = null; if (target) navigate(target.url, target.push, true); };
    box.append(stay, leave); stay.focus();
  }
  async function navigate(url, push, force) {
    var r = root(); if (!r) return false;
    if (r.dataset.edited === '1' && !force) { askLeave(url, push); return false; }
    if (activeNavigation) activeNavigation.abort();
    var controller = new AbortController(), ticket = ++sequence; activeNavigation = controller;
    r.setAttribute('aria-busy', 'true'); r.querySelectorAll('main form').forEach(function(f){f.inert=true;}); status('در حال بارگیری این بخش…');
    var timer = setTimeout(function () { controller.abort(); }, 15000);
    try {
      var target = new URL(url, location.href);
      var data = await request(r.dataset.viewApi, { action: 'sn_mis_view', nonce: r.dataset.viewNonce, view_params: target.searchParams.toString(), return_url: target.href }, controller.signal);
      if (ticket !== sequence) return false;
      var doc = new DOMParser().parseFromString(data.html, 'text/html'), next = doc.querySelector('.sn-mis-v2');
      if (!next) throw error('محتوای این بخش کامل دریافت نشد.');
      r.replaceWith(next); if (push) history.pushState({ mis: true }, '', target.href); mountedUrl = target.href;
      mount(); if(push)next.scrollIntoView({block:'start',behavior:'instant'});var feedback = next.querySelector('.sn-mis-feedback'), heading = next.querySelector('.sn-mis-title h3');
      if (feedback || heading) (feedback || heading).focus({ preventScroll: true });
      return true;
    } catch (e) {
      if (ticket === sequence) status(e.name === 'AbortError' ? 'بارگیری طول کشید. اطلاعات صفحه فعلی حفظ شده است؛ می‌توانید دوباره همین بخش را باز کنید.' : e.message, true, { label: e.data && e.data.requires_reload ? 'تازه‌سازی صفحه' : 'تلاش دوباره', run: function () { if (e.data && e.data.requires_reload) location.reload(); else navigate(url, push, true); } });
      return false;
    } finally { clearTimeout(timer); var current = root(); if (current && ticket === sequence) {current.removeAttribute('aria-busy');current.querySelectorAll('main form').forEach(function(f){f.inert=false;});} }
  }
  function clearFormError(form) {
    form.querySelectorAll('.sn-mis-form-error,.sn-mis-field-error').forEach(function (el) { el.remove(); });
    form.querySelectorAll('[aria-invalid=true]').forEach(function (el) { el.removeAttribute('aria-invalid'); if (el.dataset.misDescribedBy !== undefined) { if (el.dataset.misDescribedBy) el.setAttribute('aria-describedby', el.dataset.misDescribedBy); else el.removeAttribute('aria-describedby'); delete el.dataset.misDescribedBy; } });
  }
  function formError(form, e, uncertain) {
    clearFormError(form); var data = e.data || {}, box = node('div', '', 'sn-mis-form-error sn-mis-feedback is-error'); box.setAttribute('role', 'alert'); box.tabIndex = -1;
    box.append(node('strong', uncertain ? 'نتیجه ثبت هنوز مشخص نیست' : data.title || 'اطلاعات را بررسی کنید'), node('p', e.message));
    box.append(node('p', uncertain ? 'پیش از ثبت دوباره، سابقه فایل یا گزارش شماره‌ها را بررسی کنید. قطع ارتباط به معنی ثبت‌نشدن نیست.' : data.hint || 'اطلاعات این فرم حفظ شده است. مورد مشخص‌شده را اصلاح کنید و دوباره ادامه دهید.'));
    if (data.requires_reload) { var reload = node('button', 'تازه‌سازی پس از نگه‌داشتن متن فرم', 'sn-btn'); reload.type = 'button'; reload.onclick = function () { location.reload(); }; box.append(reload); }
    if (uncertain) { var check = node('a', 'بررسی نتیجه در گزارش‌ها', 'sn-btn'); var current = new URL(location.href); current.searchParams.set('sn_mis_page', 'reports'); current.searchParams.delete('sn_mis_preview'); check.href = current.href; check.target = '_blank'; check.rel = 'noopener'; box.append(check); }
    form.prepend(box);
    var field = data.field && form.elements.namedItem(data.field);
    if (field && field.setAttribute && field.type !== 'hidden') {
      field.setAttribute('aria-invalid', 'true'); field.dataset.misDescribedBy = field.getAttribute('aria-describedby') || '';
      var hint = node('small', data.message || e.message, 'sn-mis-field-error'); hint.id = 'sn-mis-error-' + Math.random().toString(36).slice(2);
      field.setAttribute('aria-describedby', [field.dataset.misDescribedBy, hint.id].filter(Boolean).join(' ')); field.insertAdjacentElement('afterend', hint);
      var details = field.closest('details'); if (details) details.open = true; field.focus();
    } else box.focus();
  }
  function validate(form) {
    var invalid = Array.from(form.elements).find(function (el) { return el.willValidate && !el.validity.valid; });
    if (invalid) {
      var title = invalid.closest('label'), label = title && title.childNodes[0] && title.childNodes[0].textContent.trim() || 'این فیلد';
      var message = invalid.validity.rangeOverflow ? 'عدد واردشده بیشتر از حداکثر ' + invalid.max + ' است.' : invalid.validity.rangeUnderflow ? 'عدد باید حداقل ' + invalid.min + ' باشد.' : invalid.type === 'checkbox' ? 'برای ثبت، گزینه تأیید را علامت بزنید.' : label + ' را کامل یا اصلاح کنید.';
      formError(form, error(message, { field: invalid.name, title: 'یک مورد نیاز به اصلاح دارد' })); return false;
    }
    var input = form.querySelector('input[type=file]'), file = input && input.files && input.files[0];
    if (file && (!/\.(csv|xlsx)$/i.test(file.name) || file.size > 5 * 1024 * 1024)) { formError(form, error('فقط فایل CSV یا XLSX تا ۵ مگابایت انتخاب کنید.', { field: input.name, title: 'نوع یا حجم فایل مناسب نیست' })); return false; }
    return true;
  }
  async function submit(form) {
    if (form.dataset.submitting === '1' || !validate(form)) return;
    clearFormError(form); var fields = new FormData(form), controller = new AbortController(), timer = setTimeout(function () { controller.abort(); }, 45000);
    var controls=Array.from(form.querySelectorAll('input,select,textarea')).map(function(el){return {el:el,disabled:el.disabled};});
    function restoreControls(){controls.forEach(function(c){c.el.disabled=c.disabled;});}
    var buttons = Array.from(form.querySelectorAll('button[type=submit],button:not([type])'));
    form.dataset.submitting = '1'; form.setAttribute('aria-busy', 'true'); controls.forEach(function(c){c.el.disabled=true;});status('در حال بررسی و ثبت؛ تا دریافت نتیجه منتظر بمانید.');
    buttons.forEach(function (b) { b.dataset.originalLabel = b.textContent; b.disabled = true; b.textContent = 'در حال بررسی و ثبت…'; });
    try {
      var data = await request(new URL(form.getAttribute('action') || location.href, location.href).href, fields, controller.signal); clearTimeout(timer);
      var r = root(); if (r) delete r.dataset.edited;
      if (!data.redirect || !await navigate(data.redirect, true, true)) {
        status('ثبت انجام شد، اما نمایش نتیجه کامل نشد. برای دیدن نتیجه دوباره تلاش کنید.', false, { label: 'نمایش نتیجه ثبت‌شده', run: function () { if (data.redirect) navigate(data.redirect, true, true); } });
      }
    } catch (e) {
      var uncertain = e.name === 'AbortError' || !e.data || ['incomplete_response'].includes(e.data.code);
      if (e.name === 'AbortError') e = error('سرور هنوز نتیجه نهایی را به مرورگر نرسانده است.');
      restoreControls();var r=root();if(r){var globalStatus=r.querySelector('.sn-mis-navigation-status');if(globalStatus)globalStatus.hidden=true;}formError(form, e, uncertain);
    } finally {
      clearTimeout(timer); restoreControls();delete form.dataset.submitting; form.removeAttribute('aria-busy');
      buttons.forEach(function (b) { b.disabled = false; b.textContent = b.dataset.originalLabel; delete b.dataset.originalLabel; });
    }
  }
  async function setup(button) {
    var card = button.closest('[data-setup-api]'), text = card.querySelector('.sn-mis-setup-status'), progress = card.querySelector('progress'); if (button.dataset.running) return;
    button.dataset.running = '1'; button.disabled = true; progress.hidden = false;
    try {
      var data;
      do {
        var controller = new AbortController(), timer = setTimeout(function () { controller.abort(); }, 12000);
        try { data = await request(card.dataset.setupApi, { action: 'sn_mis_setup', nonce: card.dataset.setupNonce }, controller.signal); } finally { clearTimeout(timer); }
        progress.max = Number(data.total || 8); progress.value = Number(data.completed); text.textContent = data.message;
        if (!card.isConnected) return;
        if (!data.ready) await new Promise(function (resolve) { setTimeout(resolve, data.busy ? 700 : 100); });
      } while (!data.ready);
      button.textContent = 'آماده‌سازی کامل شد'; text.textContent = 'اکنون می‌توانید کار با فایل‌ها را ادامه دهید.';
      var url = new URL(location.href); url.searchParams.set('sn_mis_page', 'tools'); await navigate(url.href, true, true);
    } catch (e) { text.textContent = (e.name === 'AbortError' ? 'پاسخ طول کشید. مراحل قبلی حفظ شده‌اند؛ ادامه دهید.' : e.message); button.textContent = 'ادامه آماده‌سازی'; }
    finally { button.disabled = false; delete button.dataset.running; }
  }
  async function runJob(button) {
    var job = button.closest('[data-job-id]'), container = job.closest('[data-job-api]'), pause = job.querySelector('.sn-mis-job-pause'), text = job.querySelector('.sn-mis-job-status');
    if (jobRunning) { text.textContent = 'ابتدا پردازش جاری را متوقف کنید؛ سپس این مورد را ادامه دهید.'; return; }
    jobRunning = true; job.dataset.running = '1'; button.disabled = true; pause.hidden = false;
    try {
      while (job.dataset.running === '1' && job.isConnected) {
        var controller = new AbortController(), timer = setTimeout(function () { controller.abort(); }, 15000), data;
        try { data = await request(container.dataset.jobApi, { action: 'sn_mis_job', nonce: container.dataset.jobNonce, job_id: job.dataset.jobId }, controller.signal); } finally { clearTimeout(timer); }
        job.querySelector('progress').value = Number(data.processed); text.textContent = Number(data.processed).toLocaleString('fa-IR') + ' از ' + Number(data.total).toLocaleString('fa-IR') + ' بررسی شد';
        if (data.status === 'ready') { text.textContent += ' · آماده'; delete job.dataset.running; await navigate(location.href, false, true); break; }
        if (!['queued', 'running'].includes(data.status)) throw error('این پردازش پایان یافته یا لغو شده است.');
        await new Promise(function (resolve) { setTimeout(resolve, 200); });
      }
    } catch (e) { text.textContent = e.name === 'AbortError' ? 'پاسخ مرحله نرسید؛ چند لحظه بعد از آخرین نقطه ثبت‌شده ادامه دهید.' : e.message; }
    finally { jobRunning = false; button.disabled = false; pause.hidden = true; delete job.dataset.running; }
  }
  function mount() {
    var r = root(); if (!r) return; mountedUrl = location.href;
    r.querySelectorAll('form').forEach(function (form) { form.noValidate = true; });
    var names = ['id', 'phone', 'batch', 'manager', 'state', 'date', 'actions'], columns = (r.dataset.columns || names.join(' ')).split(' ');
    r.querySelectorAll('table.sn-mis-records tr').forEach(function (tr) { Array.from(tr.children).forEach(function (cell, i) { if (names[i] && tr.children.length === 7) cell.hidden = columns.indexOf(names[i]) === -1; }); });
    var refresh = Number(r.dataset.refresh || 0); if (refresh >= 300 && !r.querySelector('[data-job-api]')) setTimeout(function () { if (r.isConnected && !document.hidden && r.dataset.edited !== '1') navigate(location.href, false); }, refresh * 1000);
  }
  document.addEventListener('click', function (event) {
    var r = root(); if (!r || !r.contains(event.target)) return;
    var button = event.target.closest('button');
    if (button && button.matches('.sn-mis-setup-run')) { event.preventDefault(); setup(button); return; }
    if (button && button.matches('.sn-mis-job-run')) { event.preventDefault(); runJob(button); return; }
    if (button && button.matches('.sn-mis-job-pause')) { event.preventDefault(); delete button.closest('[data-job-id]').dataset.running; button.closest('[data-job-id]').querySelector('.sn-mis-job-status').textContent += ' · پس از پایان مرحله جاری متوقف می‌شود'; button.hidden = true; return; }
    if (button && button.matches('[data-select-visible]')) { event.preventDefault(); var checks = button.closest('fieldset').querySelectorAll('input[type=checkbox]:not(:disabled)'), all = Array.from(checks).every(function (c) { return c.checked; }); checks.forEach(function (c) { c.checked = !all; }); button.textContent = all ? 'انتخاب شماره‌های این صفحه برای بررسی' : 'برداشتن انتخاب این صفحه'; return; }
    var a = event.target.closest('a[href]'); if (!a || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || a.target === '_blank' || a.hasAttribute('download')) return;
    if ((a.getAttribute('href') || '').charAt(0) === '#') return;
    var url = new URL(a.href, location.href); if (url.origin === location.origin && url.pathname === location.pathname && url.searchParams.has('sn_mis_page')) { event.preventDefault(); event.stopPropagation(); navigate(url.href, true); }
  }, true);
  document.addEventListener('submit', function (event) {
    var r = root(), form = event.target; if (!r || !r.contains(form)) return;
    var submitter = event.submitter;
    if (submitter && submitter.dataset.confirmAction && form.dataset.confirmed !== '1') {
      event.preventDefault(); event.stopPropagation(); clearFormError(form);
      var prompt = node('div', '', 'sn-mis-form-error sn-mis-inline-warning'); prompt.setAttribute('role', 'alert'); prompt.append(node('p', submitter.dataset.confirmAction));
      var yes = node('button', 'بله، انجام شود', 'sn-btn sn-btn-primary'), no = node('button', 'انصراف', 'sn-btn'); yes.type = no.type = 'button';
      yes.onclick = function () { form.dataset.confirmed = '1'; prompt.remove(); form.requestSubmit(submitter); delete form.dataset.confirmed; };
      no.onclick = function () { prompt.remove(); submitter.focus(); }; prompt.append(yes, no); form.prepend(prompt); no.focus(); return;
    }
    if (!validate(form)) { event.preventDefault(); event.stopPropagation(); return; }
    if ((form.method || 'get').toLowerCase() === 'get') {
      event.preventDefault(); event.stopPropagation(); var url = new URL(location.href); url.search = ''; new FormData(form).forEach(function (v, k) { url.searchParams.append(k, v); });
      if (!url.searchParams.has('page_id') && new URL(location.href).searchParams.has('page_id')) url.searchParams.set('page_id', new URL(location.href).searchParams.get('page_id')); navigate(url.href, true); return;
    }
    var action = form.querySelector('[name=action]');
    if (action && ['sn_mis_v2', 'sn_mis_create_batch'].includes(action.value)) { event.preventDefault(); event.stopPropagation(); submit(form); }
  }, true);
  document.addEventListener('change', function (event) {
    var r = root(), field = event.target, form = field.closest('form'); if (!r || !r.contains(field) || !form) return;
    if (form.method.toLowerCase() === 'post') r.dataset.edited = '1';
    if(field.name === 'date_type') form.dataset.dateChosen = '1';
    if(field.name === 'report_mode') { var basis=form.querySelector('[name=date_type]'); if(basis && field.value === 'events' && basis.value === 'imported' && form.dataset.dateChosen !== '1') basis.value='event'; else if(basis && field.value === 'current' && basis.value === 'event') basis.value='imported'; }
    if (field.type === 'file' && field.files && field.files[0]) { var name = field.parentElement.querySelector('.sn-mis-selected-file'); if (!name) { name = node('small', '', 'sn-mis-selected-file'); field.insertAdjacentElement('afterend', name); } name.textContent = field.files[0].name + ' · ' + (field.files[0].size / 1024).toLocaleString('fa-IR', { maximumFractionDigits: 0 }) + ' کیلوبایت'; }
  });
  document.addEventListener('input', function (event) { var form = event.target.closest('form'); if (form && root() && root().contains(form) && form.method.toLowerCase() === 'post') root().dataset.edited = '1'; });
  window.addEventListener('popstate', function () { if (!root()) return; if (root().dataset.edited === '1') { var target = location.href; history.pushState({ mis: true }, '', mountedUrl); askLeave(target, true); } else navigate(location.href, false); });
  window.__snMisV4 = { mount: mount, navigate: navigate }; mount();
})();
