(function ($) {
  'use strict';
  $(function () {
    $('.sn-product-invoice-sms').each(function () {
      var $root = $(this), token = '', count = 0, after = 0, busy = false, stopped = false;
      var $notice = $root.find('.sn-product-sms-notice'), $preview = $root.find('.sn-product-sms-preview'), $send = $root.find('.sn-product-sms-send'), $stop = $root.find('.sn-product-sms-stop');
      function say(text) { $notice.text(text); }
      function dirty() { return $root.find('input:checkbox').toArray().some(function (field) { return field.checked !== field.defaultChecked; }); }
      function request(action, data) {
        return $.ajax({url: snProductInvoiceSMS.ajaxUrl, method: 'POST', dataType: 'json', timeout: 60000, data: $.extend({action: action, nonce: snProductInvoiceSMS.nonce, product_id: $root.attr('data-product-id')}, data)});
      }
      function failure(xhr) { return xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message || 'نتیجه دریافت نشد؛ ارسال ادامه پیدا نمی‌کند. دوباره فهرست را بررسی کنید.'; }
      $root.on('change', 'input:checkbox', function () { token = ''; after = 0; $send.prop('hidden', true); $preview.prop('disabled', dirty()); say('ابتدا تغییرات محصول را ذخیره کنید، سپس فهرست قبلی را بررسی کنید.'); });
      $preview.on('click', function () {
        if (busy || dirty()) return;
        busy = true; token = ''; $send.prop('hidden', true); $preview.prop('disabled', true); say('در حال بررسی پیش‌فاکتورهای قبلی…');
        request('sn_product_invoice_sms_preview', {after: String(after)}).done(function (res) {
          if (!res || !res.success) { say(res && res.data && res.data.message || 'بررسی انجام نشد.'); return; }
          token = res.data.token; count = res.data.count;
          after = res.data.more ? res.data.after : 0;
          $preview.text(res.data.more ? 'بررسی بخش بعدی' : 'بررسی دوبارهٔ پیش‌فاکتورهای قبلی');
          $send.prop('hidden', !count); say(count + ' پیش‌فاکتور در این بخش واجد شرایط است. ' + res.data.message + (res.data.more ? ' موارد بیشتری برای بررسی وجود دارد.' : ''));
        }).fail(function (xhr) { say(failure(xhr)); }).always(function () { busy = false; $preview.prop('disabled', false); });
      });
      function next() {
        request('sn_product_invoice_sms_send', {token: token, confirmation: 'SEND_PREVIOUS'}).done(function (res) {
          if (!res || !res.success) { finish(); say(res && res.data && res.data.message || 'ارسال ادامه پیدا نمی‌کند؛ دوباره بررسی کنید.'); return; }
          var d = res.data;
          say('بررسی‌شده: ' + d.processed + ' از ' + d.total + '؛ پذیرفته‌شده: ' + d.sent + '؛ کنارگذاشته‌شده: ' + d.skipped + '؛ ناموفق/نامشخص: ' + d.failed + (stopped ? ' — ادامهٔ ارسال متوقف شد.' : ''));
          if (d.done || stopped) finish(); else next();
        }).fail(function (xhr) { finish(); say(failure(xhr)); });
      }
      function finish() { busy = false; token = ''; $send.prop('hidden', true); $stop.prop('hidden', true); $preview.prop('disabled', dirty()); }
      $send.on('click', function () {
        if (busy || !token || dirty() || !window.confirm('ارسال پیامک برای ' + count + ' پیش‌فاکتور واجد شرایطِ بررسی‌شده تأیید می‌شود؟')) return;
        busy = true; stopped = false; $send.prop('hidden', true); $preview.prop('disabled', true); $stop.prop('hidden', false); next();
      });
      $stop.on('click', function () { stopped = true; $stop.prop('hidden', true); say('درخواست جاری تعیین تکلیف می‌شود؛ پیامک بعدی ارسال نخواهد شد.'); });
    });
  });
})(jQuery);
