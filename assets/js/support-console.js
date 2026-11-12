(function () {
  'use strict';
  const config = document.getElementById('sn-support-config');
  if (!config) return;

  document.querySelectorAll('[data-sn-support-action]').forEach(function (form) {
    form.addEventListener('submit', async function (event) {
      event.preventDefault();
      const button = form.querySelector('button');
      const result = form.querySelector('[data-sn-result]');
      if (button.disabled) return;
      button.disabled = true;
      result.textContent = 'در حال ثبت…';
      try {
        const response = await fetch(config.dataset.ajax, {
          method: 'POST',
          credentials: 'same-origin',
          body: new FormData(form)
        });
        const data = await response.json();
        result.textContent = data.message || (data.success ? 'ثبت شد.' : 'عملیات انجام نشد.');
        if (data.success || data.decision_saved) {
          const reload = document.createElement('button');
          reload.type = 'button';
          reload.textContent = 'بازخوانی وضعیت';
          reload.addEventListener('click', function () { window.location.reload(); });
          result.after(reload);
          return;
        }
      } catch (error) {
        result.textContent = 'نتیجهٔ عملیات خوانده نشد. وضعیت فاکتور را بازخوانی کنید.';
      }
      button.disabled = false;
    });
  });

  const plus = document.querySelector('[data-sn-support-plus]');
  if (plus) {
    plus.addEventListener('click', function () {
      const reason = document.getElementById('sn-support-plus-reason');
      reason.setCustomValidity(reason.value.trim() ? '' : 'دلیل اقدام را وارد کنید.');
      if (!reason.reportValidity()) return;
      window.snPlusInvoiceEditor.open(plus, {
        invoice_id: Number(plus.dataset.snSupportPlus),
        support: { ajax: config.dataset.ajax, nonce: config.dataset.nonce, reason: reason.value.trim() }
      });
    });
  }

  const caseToggle = document.querySelector('.sn-dot-case-toggle[aria-expanded="false"]');
  if (caseToggle) {
    caseToggle.click();
    if (caseToggle.getAttribute('aria-expanded') === 'false') {
      const content = document.getElementById(caseToggle.getAttribute('aria-controls'));
      if (content) content.hidden = false;
    }
  }
})();
