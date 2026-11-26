(function ($) {
  'use strict';
  var products = {}, loaded = false, loading = false, generation = 0;
  var rowSelector = '#sn-products-multi .sn-product-row,#sn-converter-products-multi .sn-product-row';
  function selectIn($row) { return $row.find('.sn-product-select,.sn-converter-product-select').first(); }
  function amountKey(raw) {
    var value = String(raw || '').replace(/[۰-۹]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'.indexOf(d); }).replace(/[٠-٩]/g, function (d) { return '٠١٢٣٤٥٦٧٨٩'.indexOf(d); }).replace(/[,٬\s]/g, '').replace(/٫/g, '.');
    if (!/^\d{1,12}(?:\.\d{1,2})?$/.test(value)) return '';
    var parts = value.split('.'), cents = Number(parts[0]) * 100 + Number(((parts[1] || '') + '00').slice(0, 2));
    return cents > 0 ? String(cents) : '';
  }
  function customInput($label) {
    var $input = $label.find('.sn-catalog-custom-amount');
    if (!$input.length) $input = $('<input type="text" inputmode="decimal" class="sn-catalog-custom-amount" aria-label="تخفیف دلخواه هر واحد به تومان" placeholder="تخفیف هر واحد به تومان" hidden disabled>').appendTo($label);
    return $input;
  }
  function syncCustom($label) {
    var $select = $label.find('select').first(), active = $select.find('option:selected').attr('data-custom') === '1', $input = customInput($label);
    $input.prop('hidden', !active).prop('disabled', !active).prop('required', active);
    var key = active ? amountKey($input.val()) : '';
    $input[0].setCustomValidity(active && !key ? 'مبلغ تخفیف را به تومان و بیشتر از صفر وارد کنید.' : '');
    if (active) $select.find('option:selected').val('custom:' + (key || 'invalid'));
  }
  window.snCatalogLineDiscount = function ($row) {
    var $select = selectIn($row), $discount = $row.find('.sn-catalog-discount-select');
    if (String($discount.attr('data-product-id')) !== String($select.val())) return 0;
    return $discount.find('option:selected').attr('data-custom') === '1' ? Number(amountKey($row.find('.sn-catalog-custom-amount').val()) || 0) / 100 : Number($discount.find('option:selected').attr('data-amount') || 0);
  };
  window.snCatalogSelections = function ($container) {
    var values = [];
    $container.find('.sn-product-row').each(function () { var $row = $(this); if (selectIn($row).val()) values.push(String($row.find('.sn-catalog-discount-select').val() || '')); });
    return values;
  };
  function updateRow($row) {
    var $select = selectIn($row);
    if (!loaded) {
      if (!$select.prop('disabled')) $select.attr('data-sn-catalog-loading', '1').prop('disabled', true);
    } else {
      var selected = String($select.val() || '');
      $select.find('option').filter(function () { return this.value && !products[String(this.value)]; }).remove();
      if ($select.attr('data-sn-catalog-loading') === '1') $select.removeAttr('data-sn-catalog-loading').prop('disabled', false);
      if (selected && !products[selected]) { $select.val(''); $select.trigger('change'); }
    }
    var $product = selectIn($row), id = String($product.val() || ''), product = products[id];
    var $label = $row.find('.sn-catalog-discount-field');
    if (!$label.length) $label = $('<label class="sn-catalog-discount-field"><span>تخفیف هر واحد</span><select class="sn-catalog-discount-select" aria-label="تخفیف ثابت هر واحد محصول"></select></label>').appendTo($row);
    var $discount = $label.find('select'), key = id + ':' + loaded + ':' + generation, previous = String($discount.val() || '');
    if ($discount.attr('data-catalog-key') === key) return;
    if ($discount.attr('data-product-id') !== id) { previous = ''; customInput($label).val(''); }
    $discount.attr({'data-product-id': id, 'data-catalog-key': key}).empty().append($('<option value="">').text(loaded ? 'بدون تخفیف' : 'در حال دریافت تخفیف‌ها…'));
    ((product && product.discounts) || []).forEach(function (d) { $('<option>').val(d.id).attr('data-amount', d.amount).text(d.label).appendTo($discount); });
    if (product && product.allow_custom_discount === true) $('<option>').val(previous.indexOf('custom:') === 0 ? previous : 'custom:invalid').attr('data-custom', '1').text('تخفیف دلخواه').appendTo($discount);
    $discount.val(previous); if ($discount.val() === null) $discount.val('');
    $label.prop('hidden', !id); $discount.prop('disabled', !loaded || !product || !product.discounts.length && product.allow_custom_discount !== true);
    syncCustom($label);
    $discount.trigger('change');
  }
  function updateCase($label) {
    var $form = $label.closest('form'), key = String($form.find('[name="option_key"]').val() || ''), $select = $label.find('select');
    if ($label.attr('data-option-key') === key) return;
    var previous = $label.attr('data-option-key') == null ? String($label.attr('data-selected') || '') : '';
    var map; try { map = JSON.parse($label.attr('data-discounts') || '{}'); } catch (e) { map = {}; }
    $label.attr('data-option-key', key); $select.empty().append('<option value="">بدون تخفیف</option>');
    (map[key] || []).forEach(function (d) {
      var $option = $('<option>').val(d.custom ? previous.indexOf('custom:') === 0 ? previous : 'custom:invalid' : d.id).text(d.label);
      if (d.custom) $option.attr('data-custom', '1');
      $option.appendTo($select);
    });
    var savedKey = previous.indexOf('custom:') === 0 ? previous.slice(7) : '';
    customInput($label).val(/^\d+$/.test(savedKey) ? String(Number(savedKey) / 100) : '');
    $select.val(previous); if ($select.val() === null) $select.val('');
    $label.prop('hidden', !key);
    syncCustom($label);
  }
  function refresh() { $(rowSelector).each(function () { updateRow($(this)); }); $('.sn-catalog-case-discount[data-discounts]').each(function () { updateCase($(this)); }); }
  window.snCatalogRefresh = refresh;
  function load(force) {
    if (loading || loaded && !force || !$(rowSelector).length) return;
    loading = true;
    $(rowSelector).each(function () { var $select = selectIn($(this)); if (!$select.prop('disabled')) $select.attr('data-sn-catalog-loading', '1').prop('disabled', true); });
    $.post(snSalesCatalog.ajaxUrl, {action: 'sn_sales_catalog_staff', nonce: snSalesCatalog.nonce}, null, 'json').done(function (res) {
      if (!res || !res.success || !res.data || !Array.isArray(res.data.products) || res.data.products.some(function (p) { return !p || !Number.isInteger(Number(p.id)) || Number(p.id) < 1 || !Array.isArray(p.discounts); })) { loaded = false; return; }
      products = {}; (res.data.products || []).forEach(function (p) { products[String(p.id)] = p; }); loaded = true; generation++; refresh();
      $(rowSelector).find('.sn-catalog-retry').remove();
    }).fail(function () { loaded = false; }).always(function () {
      loading = false;
      if (!loaded) refresh();
      if (!loaded) $(rowSelector).each(function () {
        var $row = $(this);
        $row.find('.sn-catalog-discount-select option:first').text('فهرست محصولات و تخفیف‌های مجاز دریافت نشد');
        if (!$row.find('.sn-catalog-retry').length) $('<button type="button" class="sn-btn sn-btn-secondary sn-catalog-retry">دریافت دوباره محصولات و تخفیف‌ها</button>').appendTo($row);
      });
    });
  }
  $(document).on('change.snCatalog', '.sn-product-select,.sn-converter-product-select', function () { updateRow($(this).closest('.sn-product-row')); load(); });
  $(document).on('click.snCatalog', '.sn-catalog-retry', function () { load(); });
  $(document).on('click.snCatalog', '.sn-tab[data-tab="new-invoice"]', function () { load(true); });
  $(document).on('change.snCatalog', '.sn-catalog-discount-select,.sn-catalog-case-discount select', function () { syncCustom($(this).closest('.sn-catalog-discount-field,.sn-catalog-case-discount')); });
  $(document).on('input.snCatalog', '.sn-catalog-custom-amount', function () {
    var $label = $(this).closest('.sn-catalog-discount-field,.sn-catalog-case-discount'); syncCustom($label); $label.find('select').trigger('change');
  });
  $(document).on('change.snCatalog', '.sn-dot-converter-update [name="option_key"]', function () { updateCase($(this).closest('form').find('.sn-catalog-case-discount[data-discounts]')); });
  $(document).ajaxComplete(function (_, __, settings) { if (String(settings.data || '').indexOf('action=sn_create_invoice') !== -1) refresh(); });
  $(function () {
    refresh(); load();
    var scheduled = false;
    new MutationObserver(function (records) {
      var relevant = records.some(function (r) { return Array.prototype.some.call(r.addedNodes, function (n) { return n.nodeType === 1 && (n.matches('.sn-product-row,.sn-catalog-case-discount') || n.querySelector('.sn-product-row,.sn-catalog-case-discount')); }); });
      if (relevant && !scheduled) { scheduled = true; setTimeout(function () { scheduled = false; refresh(); load(); }, 0); }
    }).observe(document.body, {childList: true, subtree: true});
  });
})(jQuery);
