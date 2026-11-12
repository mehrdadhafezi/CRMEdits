/* Paginated HR filters are server-side. Other tables keep their existing local filters. */
(function () {
  'use strict';
  var menu = null, trigger = null;
  function state(table) { try { var value = JSON.parse(table.getAttribute('data-sn-column-filters') || '{}'); return value && !Array.isArray(value) && !value.invalid ? value : {}; } catch (_) { return {}; } }
  function navigate(url) {
    if (document.querySelector('#sn-hr-panel .sn-row-saving')) { window.alert('تغییرات نیرو در حال ذخیره است؛ چند لحظه بعد فیلتر را اعمال کنید.'); return; }
    window.location.assign(url.href);
  }
  function target(columns, sort, order) {
    var url = new URL(window.location.href);
    url.searchParams.delete('sn_hr_page'); url.searchParams.delete('sn_msg'); url.searchParams.delete('sn_ui_active_tab');
    url.searchParams.set('sn_hr_tab', 'hr-workforce');
    if (Object.keys(columns).length) url.searchParams.set('sn_hr_columns', JSON.stringify(columns)); else url.searchParams.delete('sn_hr_columns');
    if (sort) { url.searchParams.set('sn_hr_sort', sort); url.searchParams.set('sn_hr_order', order); }
    else if (sort === '') { url.searchParams.delete('sn_hr_sort'); url.searchParams.delete('sn_hr_order'); }
    url.hash = 'hr-workforce'; return url;
  }
  function close(restore) {
    if (menu) menu.remove(); menu = null;
    if (trigger) { trigger.classList.remove('is-open'); trigger.setAttribute('aria-expanded', 'false'); if (restore) trigger.focus(); } trigger = null;
  }
  function node(tag, text, cls) { var el = document.createElement(tag); if (text) el.textContent = text; if (cls) el.className = cls; return el; }
  function open(button, table, column) {
    close(false); trigger = button; button.classList.add('is-open'); button.setAttribute('aria-expanded', 'true');
    var columns = state(table), current = columns[column] || {query: '', values: null}, th = button.closest('th');
    menu = node('div', '', 'sn-hr-excel-filter-menu sn-hr-server-menu'); menu.dir = 'rtl'; menu.setAttribute('role', 'dialog');
    var title = 'فیلتر «' + (th.getAttribute('data-sn-label') || 'ستون') + '»'; menu.setAttribute('aria-label', title);
    menu.appendChild(node('strong', title, 'sn-hr-server-menu-title'));
    menu.appendChild(node('p', 'روی همه نیروها در تمام صفحات اعمال می‌شود.', 'sn-hr-server-menu-hint'));
    var form = node('form'), label = node('label', 'جستجو در این ستون'), query = node('input');
    query.type = 'search'; query.value = current.query || ''; query.maxLength = 100; query.placeholder = 'نام یا مقدار موردنظر'; label.appendChild(query); form.appendChild(label);
    var choices = (th.getAttribute('data-sn-filter-values') || '').split('||').filter(Boolean);
    (current.values || []).forEach(function (value) { if (choices.indexOf(value) < 0) choices.push(value); });
    var boxes = [], values = node('div', '', 'sn-hr-server-values');
    if (choices.length) {
      var allLabel = node('label', '', 'sn-hr-server-choice'), all = node('input'); all.type = 'checkbox'; all.checked = current.values === null || current.values.length === choices.length;
      allLabel.appendChild(all); allLabel.appendChild(node('span', 'انتخاب همه')); form.appendChild(allLabel);
      choices.forEach(function (value) {
        var line = node('label', '', 'sn-hr-server-choice'), check = node('input'); check.type = 'checkbox'; check.value = value;
        check.checked = current.values === null || current.values.indexOf(value) >= 0;
        check.addEventListener('change', function () { all.checked = boxes.every(function (b) { return b.checked; }); all.indeterminate = !all.checked && boxes.some(function (b) { return b.checked; }); });
        boxes.push(check); line.appendChild(check); line.appendChild(node('span', value)); values.appendChild(line);
      });
      all.addEventListener('change', function () { boxes.forEach(function (b) { b.checked = all.checked; }); all.indeterminate = false; }); form.appendChild(values);
    }
    var actions = node('div', '', 'sn-hr-server-menu-actions'), submit = node('button', 'اعمال فیلتر', 'sn-btn'), clear = node('button', 'پاک کردن', 'sn-btn sn-btn-secondary'), cancel = node('button', 'بستن', 'sn-btn sn-btn-secondary');
    submit.type = 'submit'; clear.type = cancel.type = 'button'; actions.appendChild(submit); actions.appendChild(clear); actions.appendChild(cancel); form.appendChild(actions); menu.appendChild(form);
    form.addEventListener('submit', function (event) {
      event.preventDefault(); var checked = boxes.filter(function (b) { return b.checked; }).map(function (b) { return b.value; });
      var selected = choices.length && checked.length !== choices.length ? checked : null;
      var term = query.value.trim();
      if (term || selected !== null) columns[column] = {query: term, values: selected}; else delete columns[column];
      navigate(target(columns));
    });
    clear.addEventListener('click', function () { delete columns[column]; navigate(target(columns)); }); cancel.addEventListener('click', function () { close(true); });
    var sorts = node('div', '', 'sn-hr-server-sorts');
    [['asc','صعودی'],['desc','نزولی'],['','ترتیب پیش‌فرض']].forEach(function (item) {
      var btn = node('button', item[1], 'sn-btn sn-btn-secondary'); btn.type = 'button'; btn.addEventListener('click', function () { navigate(target(columns, item[0] ? String(column) : '', item[0])); }); sorts.appendChild(btn);
    }); menu.appendChild(sorts);
    document.body.appendChild(menu);
    var rect = button.getBoundingClientRect(), width = Math.min(340, window.innerWidth - 24);
    menu.style.width = width + 'px'; menu.style.left = Math.max(12, Math.min(window.innerWidth - width - 12, rect.right - width)) + 'px';
    menu.style.top = Math.max(12, Math.min(rect.bottom + 8, window.innerHeight - Math.min(menu.scrollHeight, window.innerHeight - 24) - 12)) + 'px';
    query.focus();
  }
  // Window capture precedes the legacy table's document handlers, regardless of load order.
  window.addEventListener('click', function (event) {
    var button = event.target.closest && event.target.closest('.sn-hr-excel-filter-btn');
    var table = button && button.closest('table[data-sn-server-filters="1"]');
    if (table) { event.preventDefault(); event.stopImmediatePropagation(); open(button, table, Number(button.getAttribute('data-sn-col'))); return; }
    if (menu && menu.contains(event.target)) return;
    if (menu) close(false);
  }, true);
  window.addEventListener('keydown', function (event) { if (menu && event.key === 'Escape') { event.preventDefault(); close(true); } });
  window.addEventListener('resize', function () { close(false); });
  window.addEventListener('submit', function (event) {
    var form = event.target;
    if (form.matches('.sn-hr-global-filter-form') && document.querySelector('#sn-hr-panel .sn-row-saving')) { event.preventDefault(); window.alert('تغییرات نیرو در حال ذخیره است؛ چند لحظه بعد فیلتر را اعمال کنید.'); }
    if (!form.matches('.sn-hr-create-person-form')) return;
    var password = form.elements.initial_password, repeat = form.elements.initial_password_repeat;
    if (!password || !repeat) return;
    repeat.setCustomValidity(password.value !== repeat.value ? 'تکرار رمز ورود یکسان نیست.' : '');
    if (!form.checkValidity()) { event.preventDefault(); event.stopImmediatePropagation(); form.reportValidity(); }
  }, true);
  document.addEventListener('input', function (event) {
    var form = event.target.closest('.sn-hr-create-person-form');
    if (form && form.elements.initial_password_repeat) form.elements.initial_password_repeat.setCustomValidity('');
  });
  function ready() {
    document.querySelectorAll('table[data-sn-server-filters="1"]').forEach(function (table) {
      var columns = state(table);
      table.querySelectorAll('.sn-hr-excel-filter-btn').forEach(function (button) { var col = button.getAttribute('data-sn-col'); button.toggleAttribute('data-sn-server-filtered', !!columns[col]); });
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', ready); else ready();
})();
