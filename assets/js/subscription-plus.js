/* Product-specific cash options are UI hints; price and credit are always resolved on the server. */
(function($,w){
 'use strict';
 var config=w.snSubscriptionPlus||{}, options=config.options||{};
 function chosen(root){var $root=$(root),$product=$root.find('.sn-product-select,.sn-converter-product-select').first();return String($product.val()||'');}
 function selection(root){var $select=$(root).find('.sn-plus-cash-option').first();return String($select.val()||'none');}
 function cashPrice(root){var id=chosen(root),key=selection(root),rows=options[id]||[];for(var i=0;i<rows.length;i++)if(rows[i].id===key)return Number(rows[i].price)||0;return 0;}
 function render(){
  $('.sn-product-select,.sn-converter-product-select').each(function(){
   var $product=$(this),id=String($product.val()||''),$row=$product.closest('.sn-product-row'),$box=$row.find('.sn-plus-choice');
   var type=String($product.find('option:selected').attr('data-sn-product-type')||'');
   if(type!=='subscription_plus'){ $box.remove();return; }
   if($box.attr('data-product-id')===id)return;
   $box.remove();$box=$('<label class="sn-plus-choice" style="display:flex;flex-direction:column;gap:8px;grid-column:1/-1;margin:10px 0"></label>').attr('data-product-id',id);
   $box.append($('<span></span>').text('اعتبار نقدی'));
   var $select=$('<select class="sn-plus-cash-option" name="plus_cash_option"></select>');$select.append($('<option></option>').val('none').text('بدون اعتبار نقدی'));
   (options[id]||[]).forEach(function(row){$select.append($('<option></option>').val(row.id).text(row.title+' — قیمت '+Number(row.price).toLocaleString('fa-IR')+' تومان'));});
   $box.append($select,$('<small class="sn-plus-price-note"></small>'));$row.append($box);
  });
  describe();
 }
 function describe(){
  $('.sn-plus-choice').each(function(){var $row=$(this).closest('.sn-product-row'),price=cashPrice($row.parent());$(this).find('.sn-plus-price-note').text(price>0?'قیمت اعتبار نقدی: '+price.toLocaleString('fa-IR')+' تومان؛ به قیمت اشتراک+ اضافه می‌شود.':'پس از پرداخت خود اشتراک+، پرونده وارد صف تبدیل می‌شود.');});
 }
 w.snSubscriptionPlusUI={selection:selection,cashPrice:cashPrice};
 $(document).on('change.snPlus','.sn-product-select,.sn-converter-product-select',render);
 $(document).on('change.snPlus','.sn-plus-cash-option',function(){describe();$(this).closest('.sn-product-row').find('.sn-product-qty,.sn-converter-product-qty').trigger('change');});
 $(document).on('ajaxComplete.snPlus',render);
 $(function(){render();});
})(jQuery,window);
