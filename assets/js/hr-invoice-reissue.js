(function () {
    'use strict';
    function start() {
        var root = document.getElementById('sn-hr-invoice-reissue');
        var config = window.SNHRInvoiceReissue;
        if (!root || !config || new URL(config.url, window.location.href).origin !== window.location.origin) return;
        var status = root.querySelector('[role="status"]');
        var body = root.querySelector('[data-reissue-rows]');
        var levels = ['manager', 'senior', 'supervisor', 'seller'];
        var selects = levels.map(function (level) { return root.querySelector('[data-reissue-level="' + level + '"]'); });
        var selection = {manager: 0, senior: [], supervisor: [], seller: []};
        var pending = {}; var sequence = 0; var controller = null; var saving = false;
        function node(tag, text, className) {
            var el = document.createElement(tag);
            if (text !== undefined) el.textContent = text;
            if (className) el.className = className;
            return el;
        }
        function message(text, error) { status.textContent = text; status.classList.toggle('sn-hr-reissue-error', !!error); }
        function capture() {
            body.querySelectorAll('tr[data-user-id]').forEach(function (row) {
                var mode = row.querySelector('[data-reissue-mode]').value;
                if (mode !== row.dataset.originalMode) pending[row.dataset.userId] = mode;
                else delete pending[row.dataset.userId];
            });
        }
        function restoreFilters() {
            selects.forEach(function (select, index) {
                Array.from(select.options).forEach(function (option) {
                    option.selected = index === 0 ? Number(option.value) === Number(selection.manager) : selection[levels[index]].indexOf(Number(option.value)) !== -1;
                });
            });
        }
        function fill(select, items, chosen, manager) {
            select.replaceChildren();
            if (manager) { var placeholder = node('option', 'انتخاب مدیر فروش'); placeholder.value = ''; select.appendChild(placeholder); }
            items.forEach(function (item) {
                var option = node('option', item.label); option.value = String(item.id);
                option.selected = chosen.indexOf(item.id) !== -1; select.appendChild(option);
            });
            select.disabled = saving || (!manager && !selection.manager);
        }
        function render(data) {
            selection = data.selection;
            selects.forEach(function (select, index) { fill(select, index === 0 ? data.managers : data[levels[index]], index === 0 ? [selection.manager] : selection[levels[index]], index === 0); });
            body.replaceChildren();
            data.rows.forEach(function (item) {
                var row = node('tr'); row.dataset.userId = String(item.id); row.dataset.originalMode = item.mode;
                row.appendChild(node('td', item.label)); row.appendChild(node('td', item.position));
                var modeCell = node('td'); var mode = node('select'); mode.dataset.reissueMode = ''; mode.setAttribute('aria-label', 'مجوز صدور مجدد برای ' + item.label);
                [['inherit', 'طبق بالادست'], ['allow', 'مجاز'], ['deny', 'غیرمجاز']].forEach(function (value) { var option = node('option', value[1]); option.value = value[0]; mode.appendChild(option); });
                mode.value = pending[item.id] || item.mode; modeCell.appendChild(mode);
                var dirty = node('small', '', 'sn-hr-reissue-dirty'); modeCell.appendChild(dirty); row.appendChild(modeCell);
                function mark() { capture(); dirty.textContent = mode.value !== item.mode ? 'ذخیره نشده' : (item.invalid ? 'تنظیم نیاز به بررسی دارد' : ''); }
                mode.addEventListener('change', mark); dirty.textContent = mode.value !== item.mode ? 'ذخیره نشده' : (item.invalid ? 'تنظیم نیاز به بررسی دارد' : '');
                row.appendChild(node('td', item.allowed ? 'مجاز' : 'غیرمجاز', item.allowed ? 'sn-hr-reissue-allowed' : 'sn-hr-reissue-denied'));
                row.appendChild(node('td', item.source));
                var cell = node('td'); var button = node('button', 'ذخیره', 'sn-btn sn-btn-primary'); button.type = 'button'; button.dataset.reissueSave = '';
                button.addEventListener('click', function () { capture(); request('save', {user_id: item.id, mode: mode.value, version: item.version}, item.id); });
                cell.appendChild(button); row.appendChild(cell); body.appendChild(row);
            });
            if (!data.rows.length) { var row = node('tr'); var cell = node('td', 'ابتدا مدیر فروش و سپس افراد موردنظر را انتخاب کنید.'); cell.colSpan = 6; row.appendChild(cell); body.appendChild(row); }
        }
        function request(operation, extra, savedId) {
            if (saving) return;
            if (controller) controller.abort();
            controller = new AbortController(); var ticket = ++sequence;
            saving = operation === 'save';
            root.querySelectorAll('button, select').forEach(function (el) { el.disabled = true; });
            message(saving ? 'در حال ذخیرهٔ مجوز…' : 'در حال دریافت فهرست…');
            var payload = new URLSearchParams({action: 'sn_hr_invoice_reissue', operation: operation, nonce: config.nonce, manager_id: String(selection.manager || 0)});
            levels.slice(1).forEach(function (level) { selection[level].forEach(function (id) { payload.append(level + '_ids[]', String(id)); }); });
            Object.keys(extra || {}).forEach(function (key) { payload.set(key, String(extra[key])); });
            fetch(config.url, {method: 'POST', credentials: 'same-origin', body: payload, signal: controller.signal})
                .then(function (response) { return response.json(); })
                .then(function (result) {
                    if (ticket !== sequence) return;
                    if (!result.success) { message((result.data && result.data.message) || 'درخواست انجام نشد؛ فهرست را تازه‌سازی کنید.', true); return; }
                    if (savedId) delete pending[savedId];
                    saving = false; render(result.data); message(savedId ? 'مجوز ذخیره شد. وضعیت مؤثر ردیف‌ها به‌روز است.' : 'فهرست آماده است. پیش‌فرض صدور مجدد، غیرمجاز است.');
                }).catch(function (error) { if (error.name !== 'AbortError' && ticket === sequence) message('ارتباط برقرار نشد. دوباره تلاش کنید.', true); })
                .finally(function () {
                    if (ticket !== sequence) return;
                    saving = false; root.querySelectorAll('button, select').forEach(function (el) { el.disabled = false; });
                    selects.slice(1).forEach(function (select) { select.disabled = !selection.manager; });
                });
        }
        selects.forEach(function (select, index) {
            select.addEventListener('change', function () {
                capture();
                if (Object.keys(pending).length && !window.confirm('تغییرات ذخیره‌نشده کنار گذاشته شوند؟')) { restoreFilters(); return; }
                pending = {};
                selection[levels[index]] = index === 0 ? Number(select.value) : Array.from(select.selectedOptions).map(function (option) { return Number(option.value); });
                levels.slice(index + 1).forEach(function (level) { selection[level] = []; });
                request('list');
            });
        });
        root.querySelector('[data-reissue-refresh]').addEventListener('click', function () { capture(); request('list'); });
        request('list');
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
