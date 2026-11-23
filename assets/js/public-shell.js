/* Sales Network 1.0.146: unified, role-independent panel shell/navigation. */
(function(){
  'use strict';
  if (window.snPortalShellReady) { return; }
  window.snPortalShellReady = true;
  // Prevent legacy role scripts from registering a second click handler.
  window.snRoleSidebarToggle142 = true;

  function ready(fn){
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', fn, {once:true}); }
    else { fn(); }
  }
  function isMobile(){
    return !!(window.matchMedia && window.matchMedia('(max-width: 900px)').matches);
  }
  function safeClosest(node, selector){
    return node && node.closest ? node.closest(selector) : null;
  }
  function clean(value){ return String(value || '').replace(/[^A-Za-z0-9_-]/g, ''); }
  function panelKey(panel){
    var id = clean(panel && panel.id);
    return 'sn_sidebar_collapsed_' + (id || 'panel');
  }
  function directSidebar(panel){
    if (!panel || !panel.children) { return null; }
    for (var i=0; i<panel.children.length; i++) {
      var child = panel.children[i];
      if (child.classList && (child.classList.contains('sn-panel-toolbar') || child.classList.contains('sn-tabs'))) {
        return child;
      }
    }
    return null;
  }
  function directChildByClass(panel, className){
    if (!panel || !panel.children) { return null; }
    for (var i=0; i<panel.children.length; i++) {
      var child = panel.children[i];
      if (child.classList && child.classList.contains(className)) { return child; }
    }
    return null;
  }
  function updateBodyLock(){
    var hasOpen = !!document.querySelector('.sn-panel.sn-mobile-sidebar-open');
    if (document.body) { document.body.classList.toggle('sn-mobile-sidebar-lock', hasOpen); }
  }
  function setDesktop(panel, collapsed, persist){
    var sidebar = directSidebar(panel);
    if (!sidebar) { return; }
    panel.classList.add('sn-sidebar-collapsible', 'sn-has-sidebar-tabs');
    panel.classList.toggle('sn-sidebar-collapsed', !!collapsed);
    var button = sidebar.querySelector('.sn-sidebar-toggle');
    if (button) {
      button.innerHTML = collapsed ? '☰' : '×';
      button.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
      button.setAttribute('aria-label', collapsed ? 'باز کردن منوی پنل' : 'بستن منوی پنل');
      button.setAttribute('title', collapsed ? 'باز کردن منو' : 'بستن منو');
    }
    if (persist !== false) {
      try { window.localStorage.setItem(panelKey(panel), collapsed ? '1' : '0'); } catch (e) {}
    }
  }
  function setMobile(panel, open){
    if (!panel) { return; }
    panel.classList.toggle('sn-mobile-sidebar-open', !!open);
    var button = directChildByClass(panel, 'sn-mobile-sidebar-toggle');
    if (button) {
      button.innerHTML = open ? '×' : '☰';
      button.setAttribute('aria-expanded', open ? 'true' : 'false');
      button.setAttribute('aria-label', open ? 'بستن منوی پنل' : 'باز کردن منوی پنل');
      button.setAttribute('title', open ? 'بستن منو' : 'باز کردن منو');
    }
    var sidebar = directSidebar(panel);
    var innerButton = sidebar ? sidebar.querySelector('.sn-sidebar-toggle') : null;
    if (innerButton && isMobile()) {
      innerButton.innerHTML = '×';
      innerButton.setAttribute('aria-expanded', open ? 'true' : 'false');
      innerButton.setAttribute('aria-label', 'بستن منوی پنل');
      innerButton.setAttribute('title', 'بستن منو');
    }
    updateBodyLock();
  }
  function ensureButtons(panel){
    var sidebar = directSidebar(panel);
    if (!sidebar) { return; }
    panel.classList.add('sn-sidebar-collapsible', 'sn-has-sidebar-tabs');

    var inner = sidebar.querySelector('.sn-sidebar-toggle');
    if (!inner) {
      inner = document.createElement('button');
      inner.type = 'button';
      inner.className = 'sn-sidebar-toggle';
      sidebar.insertBefore(inner, sidebar.firstChild);
    }

    var mobile = directChildByClass(panel, 'sn-mobile-sidebar-toggle');
    if (!mobile) {
      mobile = document.createElement('button');
      mobile.type = 'button';
      mobile.className = 'sn-mobile-sidebar-toggle';
      panel.insertBefore(mobile, panel.firstChild);
    }

    var stored = '';
    try { stored = window.localStorage.getItem(panelKey(panel)) || ''; } catch (e) {}
    setDesktop(panel, stored === '1', false);
    if (!isMobile()) { setMobile(panel, false); }
    else {
      mobile.innerHTML = panel.classList.contains('sn-mobile-sidebar-open') ? '×' : '☰';
      mobile.setAttribute('aria-expanded', panel.classList.contains('sn-mobile-sidebar-open') ? 'true' : 'false');
      mobile.setAttribute('aria-label', panel.classList.contains('sn-mobile-sidebar-open') ? 'بستن منوی پنل' : 'باز کردن منوی پنل');
      mobile.setAttribute('title', panel.classList.contains('sn-mobile-sidebar-open') ? 'بستن منو' : 'باز کردن منو');
      inner.innerHTML = '×';
    }
  }
  function setup(root){
    var context = root && root.querySelectorAll ? root : document;
    if (context.classList && context.classList.contains('sn-panel')) { ensureButtons(context); }
    var panels = context.querySelectorAll ? context.querySelectorAll('.sn-panel') : [];
    for (var i=0; i<panels.length; i++) { ensureButtons(panels[i]); }
	setupCustomerActions(context);
  }

  function escapeHtml(value){
	return String(value == null ? '' : value).replace(/[&<>"']/g, function(ch){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch]; });
  }
  function faDigits(value){ return String(value == null ? '' : value).replace(/\d/g, function(d){ return '۰۱۲۳۴۵۶۷۸۹'[d]; }); }
  function customerActionsConfig(){ return window.snAjax || window.snData || {}; }
  function customerActionsRequest(data){
	var cfg = customerActionsConfig();
	var form = new FormData();
	Object.keys(data).forEach(function(key){ form.append(key, data[key]); });
	form.append('action', 'sn_seller_customer_actions');
	form.append('nonce', cfg.nonce || '');
	return fetch(cfg.ajaxurl || '', {method:'POST', credentials:'same-origin', body:form}).then(function(response){ return response.json(); });
  }
  function customerActionsPayload(response){
	if (response && response.data && typeof response.data === 'object' && !Array.isArray(response.data)) {
		var merged = {}; Object.keys(response).forEach(function(k){ merged[k]=response[k]; }); Object.keys(response.data).forEach(function(k){ merged[k]=response.data[k]; }); return merged;
	}
	return response || {};
  }
  function customerActionsMessage(response, fallback){
	var payload = customerActionsPayload(response);
	return String(payload.message || (response && response.message) || fallback || 'خطا در دریافت اطلاعات');
  }
  function customerActionsModal(){
	var modal = document.getElementById('sn-staff-customer-actions-modal');
	if (modal) { return modal; }
	modal = document.createElement('div');
	modal.id = 'sn-staff-customer-actions-modal'; modal.className = 'sn-staff-actions-modal'; modal.hidden = true;
	modal.innerHTML = '<div class="sn-staff-actions-modal-backdrop" data-sn-actions-close></div><div class="sn-staff-actions-modal-dialog" role="dialog" aria-modal="true"><button type="button" class="sn-staff-actions-modal-close" data-sn-actions-close aria-label="بستن">×</button><h3>جزئیات رفتار مشتری</h3><div class="sn-staff-actions-modal-body"></div></div>';
	document.body.appendChild(modal); return modal;
  }
  function renderCustomerActions(root, payload){
	var list = root.querySelector('.sn-staff-customer-actions-list');
	var pager = root.querySelector('.sn-staff-customer-actions-pager');
	var items = Array.isArray(payload.items) ? payload.items : [];
	if (!items.length) { list.innerHTML = '<div class="sn-notice sn-info">موردی در محدوده دسترسی شما پیدا نشد.</div>'; }
	else {
		list.innerHTML = items.map(function(item){
			return '<article class="sn-staff-action-row"><div><strong>'+escapeHtml(item.customer_name || '—')+'</strong><span>'+escapeHtml(item.invoice_code || '—')+' · '+escapeHtml(item.customer_phone || '')+'</span></div><div><span>'+escapeHtml(item.latest_action || 'پیش‌فاکتور صادر شده')+'</span><small>'+escapeHtml(faDigits(item.latest_at_jalali || ''))+' · '+faDigits(item.action_count || 0)+' رویداد</small></div><button type="button" class="sn-btn sn-btn-sm sn-btn-secondary sn-staff-action-detail" data-invoice-id="'+Number(item.invoice_id || 0)+'">جزئیات</button></article>';
		}).join('');
	}
	var page = Number(payload.page || root.dataset.page || 1), hasMore = !!payload.has_more;
	root.dataset.page = String(page);
	pager.innerHTML = '<button type="button" class="sn-btn sn-btn-sm sn-btn-secondary sn-staff-actions-prev" '+(page <= 1 ? 'disabled' : '')+'>قبلی</button><span>صفحه '+faDigits(page)+'</span><button type="button" class="sn-btn sn-btn-sm sn-btn-secondary sn-staff-actions-next" '+(!hasMore ? 'disabled' : '')+'>بعدی</button>';
  }
  function loadCustomerActions(root, page){
	if (!root || root.dataset.loading === '1') { return; }
	var loading = root.querySelector('.sn-staff-customer-actions-loading');
	root.dataset.loading = '1'; if (loading) { loading.hidden = false; }
	var search = root.querySelector('.sn-staff-customer-actions-search');
	customerActionsRequest({page:Math.max(1, Number(page || 1)), limit:20, q:search ? search.value.trim() : ''}).then(function(response){
		var payload = customerActionsPayload(response);
		if (!response || !response.success) { throw new Error(customerActionsMessage(response)); }
		renderCustomerActions(root, payload); root.dataset.loaded = '1';
	}).catch(function(error){ var list=root.querySelector('.sn-staff-customer-actions-list'); if(list){ list.innerHTML='<div class="sn-notice sn-error">'+escapeHtml(error.message || 'خطا در دریافت اطلاعات')+'</div>'; } }).finally(function(){ root.dataset.loading='0'; if(loading){ loading.hidden=true; } });
  }
  function setupCustomerActions(context){
	var roots = [];
	if (context && context.classList && context.classList.contains('sn-staff-customer-actions')) { roots.push(context); }
	if (context && context.querySelectorAll) { roots = roots.concat(Array.prototype.slice.call(context.querySelectorAll('.sn-staff-customer-actions'))); }
	roots.forEach(function(root){ if(root.dataset.snActionsReady==='1') return; root.dataset.snActionsReady='1'; customerActionsModal(); });
  }

	function resendInvoiceSms(button){
		if (!button || button.disabled) { return; }
		var invoiceId = Number(button.getAttribute('data-invoice-id') || 0);
		var cfg = window.snAjax || window.snData || {};
		var message = button.parentNode ? button.parentNode.querySelector('.sn-resend-invoice-sms-msg') : null;
		if (!invoiceId || !cfg.ajaxurl || !cfg.nonce) { if(message){message.textContent='اطلاعات فاکتور کامل نیست.';} return; }
		var destination = window.prompt('برای ارسال به شماره اصلی، کادر را خالی بگذارید. برای شماره دوم یا شماره دیگر، شماره موبایل مقصد را وارد کنید:', '');
		if (destination === null) { return; }
		destination = String(destination).trim();
		destination=destination.replace(/[۰-۹]/g,function(d){return String('۰۱۲۳۴۵۶۷۸۹'.indexOf(d));}).replace(/[٠-٩]/g,function(d){return String('٠١٢٣٤٥٦٧٨٩'.indexOf(d));}).replace(/[^0-9]/g,'');
		if (destination && !/^09\d{9}$/.test(destination)) { if(message){message.textContent='شماره مقصد معتبر نیست.';} return; }
		var idleLabel = button.textContent;
		button.disabled = true; button.textContent = 'در حال ارسال...';
		if (message) { message.textContent = ''; message.classList.remove('is-success','is-error'); }
		var form = new FormData();
		form.append('action', 'sn_resend_invoice_sms'); form.append('nonce', cfg.nonce); form.append('invoice_id', String(invoiceId)); form.append('destination_phone', destination);
		fetch(cfg.ajaxurl, {method:'POST', credentials:'same-origin', body:form})
			.then(function(response){
				return response.text().then(function(raw){
					var parsed;
					try { parsed = JSON.parse(raw); } catch (error) {
						throw new Error('پاسخ معتبر از سرور دریافت نشد (HTTP ' + response.status + '). وضعیت پیامک را پیش از تلاش مجدد بررسی کنید.');
					}
					if (parsed === 0 || parsed === -1) { throw new Error('نشست منقضی شده است؛ صفحه را تازه کنید و دوباره وارد شوید.'); }
					return parsed;
				});
			})
			.then(function(response){
				var payload = response && response.data && typeof response.data === 'object' ? response.data : response;
				var text = String((payload && payload.message) || (response && response.message) || (response && response.success ? 'پیامک ارسال شد.' : 'ارسال پیامک انجام نشد.'));
				if (!response || !response.success) { throw new Error(text); }
				if (message) { message.textContent = text; message.classList.add('is-success'); }
			})
			.catch(function(error){ if(message){message.textContent=String(error && error.message || 'خطا در ارسال پیامک');message.classList.add('is-error');} })
			.finally(function(){ button.disabled=false; button.textContent=idleLabel; });
	}

	function copyInvoicePaymentLink(button){
		if (!button || button.disabled) return;
		var invoiceId=Number(button.getAttribute('data-invoice-id')||0), cfg=window.snAjax||window.snData||{};
		var message=button.parentNode ? button.parentNode.querySelector('.sn-copy-invoice-link-msg') : null;
		if (!invoiceId || !cfg.ajaxurl || !cfg.nonce) return;
		button.disabled=true;
		var form=new FormData(); form.append('action','sn_copy_invoice_payment_link'); form.append('nonce',cfg.nonce); form.append('invoice_id',String(invoiceId));
		fetch(cfg.ajaxurl,{method:'POST',credentials:'same-origin',body:form}).then(function(r){return r.json();}).then(function(r){
			var data=r&&r.data&&typeof r.data==='object'?r.data:r;
			if(!r||!r.success||!data.url) throw new Error((data&&data.message)||'لینک دریافت نشد.');
			return navigator.clipboard&&navigator.clipboard.writeText ? navigator.clipboard.writeText(data.url).then(function(){return true;},function(){window.prompt('لینک پرداخت را کپی کنید:',data.url);return false;}) : (window.prompt('لینک پرداخت را کپی کنید:',data.url),false);
		}).then(function(copied){if(message) message.textContent=copied?'لینک کپی شد.':'لینک برای کپی نمایش داده شد.';}).catch(function(err){if(message) message.textContent=err.message||'خطا در دریافت لینک';}).finally(function(){button.disabled=false;});
	}
	function openInvoiceFromCase(button){
		var invoiceId=Number(button.getAttribute('data-invoice-id')||0),cfg=window.snAjax||window.snData||{};
		var message=button.parentNode&&button.parentNode.querySelector('.sn-open-invoice-link-msg');
		if(!invoiceId||!cfg.ajaxurl||!cfg.nonce) return;
		button.disabled=true;
		var form=new FormData();form.append('action','sn_copy_invoice_payment_link');form.append('nonce',cfg.nonce);form.append('invoice_id',String(invoiceId));
		fetch(cfg.ajaxurl,{method:'POST',credentials:'same-origin',body:form}).then(function(r){return r.json();}).then(function(r){
			var data=r&&r.data&&typeof r.data==='object'?r.data:r;
			if(!r||!r.success||!data.url) throw new Error((data&&data.message)||'فاکتور قابل باز کردن نیست.');
			window.location.assign(data.url);
		}).catch(function(err){if(message) message.textContent=err.message||'خطا در باز کردن فاکتور';button.disabled=false;});
	}
	// Primary invoice rows use one dialog for all payment actions.
	window.snInvoicePaymentButtons = function(invoice){
		var id=Number(invoice && invoice.id);
		if(!Number.isSafeInteger(id) || id<1) return '';
		return '<div class="sn-invoice-payment-actions"><button type="button" class="sn-btn sn-btn-sm sn-btn-primary sn-unified-state-open" data-invoice-id="'+id+'">مدیریت پرداخت</button><button type="button" class="sn-btn sn-btn-sm sn-btn-secondary sn-unified-history-open" data-invoice-id="'+id+'">تراکنش‌ها و تاریخچه مالی</button></div>';
	};
	function invoiceEditRequest(data){
		var cfg=window.snAjax||window.snData||{},form=new FormData();
		Object.keys(data).forEach(function(key){
			if(Array.isArray(data[key])) data[key].forEach(function(value){form.append(key+'[]',String(value));});
			else form.append(key,String(data[key]));
		});
		form.append('action','sn_invoice_pre_payment_edit');form.append('nonce',cfg.nonce||'');
		return fetch(cfg.ajaxurl,{method:'POST',credentials:'same-origin',body:form}).then(function(r){return r.json();}).then(function(r){var v=r&&r.data&&typeof r.data==='object'?r.data:r;if(!r||!r.success)throw new Error((v&&v.message)||r.message||'درخواست انجام نشد');return v;});
	}
	function showInvoiceFinanceHistory(button){
		var id=Number(button.getAttribute('data-invoice-id')||0),cfg=window.snAjax||window.snData||{};
		if(!id||!cfg.ajaxurl||!cfg.nonce)return;
		var old=document.getElementById('sn-scoped-finance-history');if(old)old.remove();
		var modal=document.createElement('div');modal.id='sn-scoped-finance-history';modal.dir='rtl';
		modal.innerHTML='<div class="sn-modal-backdrop sn-scoped-history-close"></div><div class="sn-modal-card" role="dialog" aria-modal="true" aria-label="وضعیت و سوابق مالی"><button type="button" class="sn-modal-x sn-scoped-history-close" aria-label="بستن">×</button><h3>وضعیت و سوابق مالی</h3><div class="sn-scoped-history-content">در حال دریافت...</div></div>';
		document.body.appendChild(modal);
		var form=new FormData();form.append('action','sn_financial_invoice_details');form.append('nonce',cfg.nonce);form.append('invoice_id',String(id));
		fetch(cfg.ajaxurl,{method:'POST',credentials:'same-origin',body:form}).then(function(r){return r.json();}).then(function(r){
			var data=r&&r.data&&typeof r.data==='object'?r.data:r;if(!r||!r.success)throw new Error((data&&data.message)||r.message||'سوابق دریافت نشد');
			var inv=data.invoice||{},acts=data.activities||[],content=modal.querySelector('.sn-scoped-history-content');
			var esc=function(v){var n=document.createElement('span');n.textContent=String(v==null?'':v);return n.innerHTML;};
			var html='<p><strong>فاکتور '+esc(inv.invoice_code)+'</strong> · '+esc(inv.customer_name)+' · '+esc(inv.status_label)+'</p><p>مبلغ کل: '+esc(inv.total_amount_fmt)+' | واریزشده: '+esc(inv.paid_amount_fmt)+' | مانده: '+esc(inv.remaining_amount_fmt)+'</p>';
			if(inv.financial_reject_reason)html+='<p class="sn-reject-reason"><strong>دلیل رد فعلی:</strong> '+esc(inv.financial_reject_reason)+'</p>';
			html+='<h4>تاریخچه بررسی</h4>'+(acts.length?acts.map(function(a){return '<article><strong>'+esc(a.label)+'</strong> <small>'+esc(a.actor_name)+' · '+esc(a.created_at_jalali)+'</small><p>'+esc(a.note||a.description)+'</p></article>';}).join(''):'<p>هنوز سابقه‌ای ثبت نشده است.</p>');
			content.innerHTML=html;
		}).catch(function(err){var content=modal.querySelector('.sn-scoped-history-content');if(content)content.textContent=err.message||'خطا در دریافت تاریخچه';});
	}
	function tehranPaymentNow(){
		var parts=new Intl.DateTimeFormat('en-US-u-ca-persian-nu-latn',{timeZone:'Asia/Tehran',year:'numeric',month:'numeric',day:'numeric',hour:'2-digit',minute:'2-digit',hourCycle:'h23'}).formatToParts(new Date()),out={};
		parts.forEach(function(p){out[p.type]=p.value;});
		var pad=function(n){return String(n).padStart(2,'0');};
		return {date:out.year+'/'+pad(out.month)+'/'+pad(out.day),hour:pad(out.hour),minute:pad(out.minute)};
	}
	window.snPaymentTimeInit=function(hourSelector,minuteSelector){
		var h=document.querySelector(hourSelector),m=document.querySelector(minuteSelector);if(!h||!m)return;
		var now=tehranPaymentNow(),pad=function(n){return String(n).padStart(2,'0');};
		if(!h.options.length)for(var i=0;i<24;i++)h.add(new Option(pad(i),pad(i)));
		if(!m.options.length)for(var j=0;j<60;j++)m.add(new Option(pad(j),pad(j)));
		h.value=now.hour;m.value=now.minute;
	};
	window.snPaymentDateTimePicker=function(input){
		var label=input.parentElement, field=document.createElement('div'), controls=document.createElement('div');
		var existing=document.createElement('small');
		field.className=(label.className+' sn-payment-datetime-field').trim();
		controls.className='sn-payment-datetime';
		label.replaceWith(field);
		field.append(...label.childNodes,controls,existing);
		input.hidden=true;

		var persianDigits='۰۱۲۳۴۵۶۷۸۹', arabicDigits='٠١٢٣٤٥٦٧٨٩';
		function pad(value){return String(value).padStart(2,'0');}
		function fill(select,first,last,step){
			select.replaceChildren(new Option('—',''));
			for(var n=first;step>0?n<=last:n>=last;n+=step){
				var value=pad(n);
				select.add(new Option(value.replace(/\d/g,function(d){return persianDigits[d];}),value));
			}
		}
		var selectors=[['year','سال'],['month','ماه'],['day','روز'],['hour','ساعت'],['minute','دقیقه']].map(function(part){
			var caption=document.createElement('label'), select=document.createElement('select');
			caption.textContent=part[1];
			select.dataset.datePart=part[0];
			caption.append(select);
			controls.append(caption);
			return select;
		});
		var year=selectors[0], month=selectors[1], day=selectors[2];
		var latestYear=1600;
		try { latestYear=Number(tehranPaymentNow().date.split('/')[0])||latestYear; } catch(e) {}
		fill(month,1,12,1);
		fill(selectors[3],0,23,1);
		fill(selectors[4],0,59,1);

		function setDays(value){
			var m=Number(month.value), y=Number(year.value)+1595, days=31;
			if(m>6) days=30;
			// Match the 33-year cycle used by the server's Jalali conversion.
			if(m===12&&year.value){
				var leapDays=function(n){return Math.floor(n/33)*8+Math.floor((n%33+3)/4);};
				days=29+leapDays(y+1)-leapDays(y);
			}
			fill(day,1,days,1);
			day.value=Number(value)<=days?value:'';
		}
		function parts(){return selectors.map(function(select){return select.value;});}
		function sync(draftParts){
			var raw=input.value.trim().replace(/[۰-۹٠-٩]/g,function(d){return String(persianDigits.includes(d)?persianDigits.indexOf(d):arabicDigits.indexOf(d));});
			var saved=raw.match(/^(\d{4})\/(\d{2})\/(\d{2})\s+(\d{2}):(\d{2})$/);
			var values=draftParts||(saved?saved.slice(1):[]);
			fill(year,Math.min(1600,Math.max(latestYear,Number(values[0])||0)),1300,-1);
			selectors.forEach(function(select,i){if(select!==day)select.value=values[i]||'';});
			setDays(values[2]||'');
			existing.textContent=input.value&&!parts().every(Boolean)?'زمان ثبت‌شده: '+input.value:'';
			existing.hidden=!existing.textContent;
		}
		controls.addEventListener('change',function(event){
			if(event.target===year||event.target===month)setDays(day.value);
			var values=parts();
			input.value=values.every(Boolean)?values.slice(0,3).join('/')+' '+values.slice(3).join(':'):'';
			existing.hidden=true;
		});
		sync();
		return {parts:parts,sync:sync};
	};
	function invoiceEditMoney(value){return new Intl.NumberFormat('fa-IR',{maximumFractionDigits:2}).format(Number(value)||0)+' تومان';}
	function invoiceEditAmountKey(value){
		value=String(value||'').replace(/[۰-۹]/g,function(d){return '۰۱۲۳۴۵۶۷۸۹'.indexOf(d);}).replace(/[٠-٩]/g,function(d){return '٠١٢٣٤٥٦٧٨٩'.indexOf(d);}).replace(/[,٬\s]/g,'').replace(/٫/g,'.');
		if(!/^\d{1,12}(?:\.\d{1,2})?$/.test(value))return '';
		var parts=value.split('.'),cents=Number(parts[0])*100+Number(((parts[1]||'')+'00').slice(0,2));return cents>0?String(cents):'';
	}
	function invoiceEditPayload(modal,mode){
		var state=modal.snInvoiceEdit,products=[],qtys=[],discounts=[];
		modal.querySelectorAll('.sn-edit-item').forEach(function(row){
			products.push(Number(row.querySelector('.sn-edit-product').value)||0);qtys.push(Number(row.querySelector('.sn-edit-qty').value)||0);
			var value=row.querySelector('.sn-edit-discount').value;
			discounts.push(value==='__custom__'?'custom:'+(invoiceEditAmountKey(row.querySelector('.sn-edit-custom').value)||'invalid'):value);
		});
		return {invoice_id:state.data.invoice_id,edit_mode:mode,revision:state.data.revision,reprice_requested:modal.querySelector('.sn-edit-reprice').checked?'1':'0',product_ids:products,product_qtys:qtys,catalog_discount_ids:discounts,payment_plan:modal.querySelector('.sn-edit-plan').value,prepayment_amount:modal.querySelector('.sn-edit-due').value,customer_phone_secondary:modal.querySelector('.sn-edit-phone-secondary').value};
	}
	function invoiceEditDiscounts(modal,row,selected){
		var state=modal.snInvoiceEdit,product=state.catalog[String(row.querySelector('.sn-edit-product').value)],select=row.querySelector('.sn-edit-discount'),custom=row.querySelector('.sn-edit-custom');
		select.replaceChildren(new Option('بدون تخفیف',''));
		((product&&product.discounts)||[]).forEach(function(d){select.add(new Option(d.label,String(d.id)));});
		if(product&&product.allow_custom_discount===true)select.add(new Option('تخفیف دلخواه مجاز','__custom__'));
		if(String(selected).indexOf('custom:')===0&&product&&product.allow_custom_discount===true){custom.value=String(Number(String(selected).slice(7))/100);selected='__custom__';}
		if(selected&&!Array.from(select.options).some(function(o){return o.value===selected;}))select.add(new Option('تخفیف ثبت‌شده؛ حفظ قیمت فعلی',selected));
		select.value=selected||'';select.disabled=!state.data.can_edit_products||state.data.legacy_adjustments;
		custom.hidden=select.value!=='__custom__';custom.disabled=select.disabled||custom.hidden;
	}
	function previewInvoiceEdit(modal){
		var state=modal.snInvoiceEdit;if(!document.body.contains(modal)||state.saving)return;
		clearTimeout(state.timer);state.inputKey=JSON.stringify(invoiceEditPayload(modal,'preview'));var seq=++state.sequence;state.preview=null;
		var save=modal.querySelector('.sn-edit-save'),message=modal.querySelector('.sn-edit-message');save.disabled=true;message.textContent='در حال محاسبهٔ مبلغ و تخفیف...';
		invoiceEditRequest(invoiceEditPayload(modal,'preview')).then(function(result){
			if(seq!==state.sequence||!document.body.contains(modal))return;
			state.preview=result;var pricing=result.quote.pricing;
			modal.querySelector('.sn-edit-totals').textContent='مبلغ قبل از تخفیف: '+invoiceEditMoney(pricing.original_total)+' — تخفیف: '+invoiceEditMoney(pricing.discount_total)+' — مبلغ نهایی: '+invoiceEditMoney(pricing.final_total)+' — مبلغ مرحلهٔ اول: '+invoiceEditMoney(result.quote.due);
			message.textContent='مبالغ در سرور محاسبه شد. برای ثبت، ذخیره تغییرات را بزنید.';save.disabled=false;
		}).catch(function(error){if(seq===state.sequence&&document.body.contains(modal)){message.textContent=error.message||'محاسبه انجام نشد';}});
	}
	function showInvoiceEdit(button){
		var id=Number(button.getAttribute('data-invoice-id')||0);if(!id)return;button.disabled=true;
		invoiceEditRequest({invoice_id:id,edit_mode:'read'}).then(function(data){
			if(data.editor_kind==='subscription_plus'&&window.snPlusInvoiceEditor){window.snPlusInvoiceEditor.open(button,data);return;}
			var old=document.getElementById('sn-prepayment-edit-modal');if(old)old.remove();
			var modal=document.createElement('div');modal.id='sn-prepayment-edit-modal';modal.className='sn-modal sn-lite-modal';modal.dir='rtl';
			modal.innerHTML='<style>#sn-prepayment-edit-modal .sn-modal-head,#sn-prepayment-edit-modal .sn-modal-actions{flex:0 0 auto}#sn-prepayment-edit-modal .sn-modal-body{overflow:auto;min-height:0}#sn-prepayment-edit-modal label{display:block;margin:8px 0}#sn-prepayment-edit-modal input:not([type=checkbox]),#sn-prepayment-edit-modal select{width:100%;min-height:42px;box-sizing:border-box;font:inherit;padding:8px;border:1px solid #cbd5e1;border-radius:8px}#sn-prepayment-edit-modal .sn-edit-item{display:grid;grid-template-columns:minmax(0,1fr);gap:8px}#sn-prepayment-edit-modal .sn-modal-actions{background:#fff;padding-top:12px;border-top:1px solid #eee}#sn-prepayment-edit-modal h3{font-size:16px}@media(min-width:700px){#sn-prepayment-edit-modal .sn-edit-item{grid-template-columns:2fr 1fr 2fr}}</style><div class="sn-modal-backdrop sn-edit-close"></div><div class="sn-modal-card" role="dialog" aria-modal="true" aria-label="ویرایش پیش‌فاکتور" style="width:calc(100vw - 32px);max-width:680px;max-height:85vh;box-sizing:border-box;display:flex;flex-direction:column;overflow:hidden;font-family:Tahoma,sans-serif;font-size:14px;line-height:1.8"><div class="sn-modal-head"><h3>ویرایش پیش‌فاکتور پیش از پرداخت</h3><button type="button" class="sn-modal-x sn-edit-close" aria-label="بستن">×</button></div><div class="sn-modal-body"><p class="sn-note">قیمت از محصول، تعداد و تخفیف مجاز محاسبه می‌شود. تغییرِ صرفِ مبلغ مرحله، قیمت و تخفیف قبلی را حفظ می‌کند. پس از ثبت پرداخت یا فیش، ویرایش قفل است.</p><p class="sn-edit-legacy sn-note" hidden>کوپن یا جایزهٔ قبلی حفظ می‌شود؛ در این فاکتور شرایط پرداخت قابل تغییر است و اقلام و مزایای قبلی ثابت می‌مانند.</p><div class="sn-edit-items"></div><label><input type="checkbox" class="sn-edit-reprice"> محاسبه با قیمت و تخفیف مجاز فعلی محصولات</label><p class="sn-edit-totals sn-note" aria-live="polite"></p><label>نوع پرداخت<select class="sn-edit-plan"><option value="full">پرداخت کامل</option><option value="partial">پیش‌پرداخت مرحله‌ای</option></select></label><label class="sn-edit-due-field">مبلغ مرحله اول (تومان)<input class="sn-edit-due" type="text" inputmode="decimal"></label><label>شماره دوم مشتری (اختیاری)<input class="sn-edit-phone-secondary" type="tel" inputmode="tel" placeholder="09xxxxxxxxx"></label><div class="sn-edit-message" role="status"></div></div><div class="sn-modal-actions"><button type="button" class="sn-btn sn-btn-secondary sn-edit-close">انصراف</button><button type="button" class="sn-btn sn-btn-secondary sn-edit-preview">محاسبه مجدد</button><button type="button" class="sn-btn sn-btn-primary sn-edit-save" disabled>ذخیره تغییرات</button></div></div>';
			var catalog={};(data.catalog||[]).forEach(function(p){catalog[String(p.id)]=p;});
			modal.snInvoiceEdit={data:data,catalog:catalog,sequence:0,preview:null,saving:false,trigger:button};document.body.appendChild(modal);
			(data.items||[]).forEach(function(item,index){
				var row=document.createElement('div');row.className='sn-edit-item';row.style.cssText='padding:12px 0;border-bottom:1px solid #ddd';
				var productLabel=document.createElement('label');productLabel.textContent='محصول '+(index+1);var product=document.createElement('select');product.className='sn-edit-product';
				if(data.can_edit_products&&!data.legacy_adjustments)(data.catalog||[]).forEach(function(p){product.add(new Option(p.name,String(p.id)));});
				if(!Array.from(product.options).some(function(o){return o.value===String(item.product_id);}))product.add(new Option(item.product_name||'محصول #'+item.product_id,String(item.product_id)));
				product.value=String(item.product_id);product.disabled=!data.can_edit_products||data.legacy_adjustments;productLabel.appendChild(product);row.appendChild(productLabel);
				var qtyLabel=document.createElement('label');qtyLabel.textContent='تعداد';var qty=document.createElement('input');qty.className='sn-edit-qty';qty.type='number';qty.min='1';qty.max='1000000';qty.step='1';qty.value=String(item.qty||1);qty.disabled=product.disabled;qtyLabel.appendChild(qty);row.appendChild(qtyLabel);
				var discountLabel=document.createElement('label');discountLabel.textContent='تخفیف هر واحد محصول';var discount=document.createElement('select');discount.className='sn-edit-discount';discountLabel.appendChild(discount);
				var custom=document.createElement('input');custom.className='sn-edit-custom';custom.type='text';custom.inputMode='decimal';custom.placeholder='تخفیف هر واحد به تومان';custom.setAttribute('aria-label','تخفیف دلخواه هر واحد به تومان');discountLabel.appendChild(custom);row.appendChild(discountLabel);
				modal.querySelector('.sn-edit-items').appendChild(row);invoiceEditDiscounts(modal,row,String((data.catalog_discount_ids||[])[index]||''));
				product.addEventListener('change',function(){custom.value='';invoiceEditDiscounts(modal,row,'');});
				discount.addEventListener('change',function(){custom.hidden=discount.value!=='__custom__';custom.disabled=discount.disabled||custom.hidden;});
			});
			modal.querySelector('.sn-edit-plan').value=data.payment_plan==='partial'?'partial':'full';
			if(data.payment_plan==='full'&&!data.can_make_partial)modal.querySelector('.sn-edit-plan option[value="partial"]').disabled=true;
			modal.querySelector('.sn-edit-due').value=String(data.current_due_amount||'');modal.querySelector('.sn-edit-phone-secondary').value=data.customer_phone_secondary||'';
			modal.querySelector('.sn-edit-due-field').hidden=data.payment_plan!=='partial';modal.querySelector('.sn-edit-legacy').hidden=!data.legacy_adjustments;modal.querySelector('.sn-edit-reprice').disabled=!data.can_edit_products||data.legacy_adjustments;
			modal.querySelector('.sn-edit-totals').textContent='مبلغ نهایی فعلی: '+invoiceEditMoney(data.total_amount);
			function invalidate(){var state=modal.snInvoiceEdit;if(state.saving)return;var key=JSON.stringify(invoiceEditPayload(modal,'preview'));if(key===state.inputKey)return;state.inputKey=key;state.preview=null;++state.sequence;modal.querySelector('.sn-edit-save').disabled=true;modal.querySelector('.sn-edit-due-field').hidden=modal.querySelector('.sn-edit-plan').value!=='partial';clearTimeout(state.timer);state.timer=setTimeout(function(){previewInvoiceEdit(modal);},300);}
			modal.addEventListener('input',invalidate);modal.addEventListener('change',invalidate);
			modal.querySelector('.sn-edit-preview').addEventListener('click',function(){previewInvoiceEdit(modal);});
			modal.addEventListener('keydown',function(event){
                if(event.key==='Escape'&&!modal.snInvoiceEdit.saving){modal.remove();button.focus();}
                if(event.key==='Tab'){var controls=Array.from(modal.querySelectorAll('button,input,select')).filter(function(control){return !control.disabled&&control.offsetParent!==null;});var first=controls[0],last=controls[controls.length-1];if(first&&event.shiftKey&&document.activeElement===first){event.preventDefault();last.focus();}else if(last&&!event.shiftKey&&document.activeElement===last){event.preventDefault();first.focus();}}
            });
			modal.querySelector('.sn-edit-plan').focus();previewInvoiceEdit(modal);
		}).catch(function(error){window.alert(error.message||'امکان ویرایش وجود ندارد');}).finally(function(){button.disabled=false;});
	}
	function saveInvoiceEdit(button){
		var modal=document.getElementById('sn-prepayment-edit-modal');if(!modal)return;
		var state=modal.snInvoiceEdit,message=modal.querySelector('.sn-edit-message');
		if(!state.preview||state.saving){message.textContent='ابتدا محاسبهٔ معتبر تغییرات را دریافت کنید.';return;}
		var payload=invoiceEditPayload(modal,'save');payload.expires=state.preview.expires;payload.preview_token=state.preview.preview_token;
		state.saving=true;var controls=Array.from(modal.querySelectorAll('input,select,button')).map(function(control){return {control:control,disabled:control.disabled};});controls.forEach(function(item){item.control.disabled=true;});button.disabled=true;message.textContent='در حال ذخیره...';
		invoiceEditRequest(payload).then(function(result){
			state.saved=true;state.preview=null;message.textContent=result.message||'ذخیره شد';
			window.setTimeout(function(){modal.remove();if(state.trigger)state.trigger.focus();if(typeof window.snLoadSellerInvoices==='function')window.snLoadSellerInvoices();if(typeof window.snLoadSupervisorInvoicesFinal==='function')window.snLoadSupervisorInvoicesFinal(null,true);var active=document.querySelector('.sn-converter-invoice-status-tabs .sn-subtab.active');if(active)active.click();},450);
		}).catch(function(error){message.textContent=error.message||'خطا در ذخیره';state.preview=null;}).finally(function(){if(!state.saved){state.saving=false;controls.forEach(function(item){item.control.disabled=item.disabled;});}button.disabled=true;});
	}

  document.addEventListener('click', function(event){
	var todayButton=safeClosest(event.target,'.sn-payment-today');if(todayButton){
		event.preventDefault();var today=tehranPaymentNow(),date=document.querySelector(todayButton.dataset.date),hour=document.querySelector(todayButton.dataset.hour),minute=document.querySelector(todayButton.dataset.minute);
		if(date){date.value=today.date;date.dispatchEvent(new Event('change',{bubbles:true}));}if(hour)hour.value=today.hour;if(minute)minute.value=today.minute;return;
	}
	var historyButton=safeClosest(event.target,'.sn-invoice-finance-history');if(historyButton){event.preventDefault();showInvoiceFinanceHistory(historyButton);return;}
	var historyClose=safeClosest(event.target,'.sn-scoped-history-close');if(historyClose){event.preventDefault();var historyModal=document.getElementById('sn-scoped-finance-history');if(historyModal)historyModal.remove();return;}
	var editButton=safeClosest(event.target,'.sn-edit-invoice');if(editButton){event.preventDefault();event.stopImmediatePropagation();showInvoiceEdit(editButton);return;}
	var saveButton=safeClosest(event.target,'.sn-edit-save');if(saveButton){event.preventDefault();saveInvoiceEdit(saveButton);return;}
	var closeButton=safeClosest(event.target,'.sn-edit-close');if(closeButton){event.preventDefault();var editModal=document.getElementById('sn-prepayment-edit-modal');if(editModal){if(editModal.snInvoiceEdit&&editModal.snInvoiceEdit.saving)return;var editTrigger=editModal.snInvoiceEdit&&editModal.snInvoiceEdit.trigger;editModal.remove();if(editTrigger)editTrigger.focus();}return;}
	var openButton=safeClosest(event.target,'.sn-open-invoice-link');
	if(openButton){event.preventDefault();event.stopImmediatePropagation();openInvoiceFromCase(openButton);return;}
	var copyButton=safeClosest(event.target,'.sn-copy-invoice-link');
	if(copyButton){event.preventDefault();event.stopImmediatePropagation();copyInvoicePaymentLink(copyButton);return;}
	var resendSmsButton = safeClosest(event.target, '.sn-resend-invoice-sms');
	if (resendSmsButton) { event.preventDefault(); event.stopImmediatePropagation(); resendInvoiceSms(resendSmsButton); return; }
    var mobileButton = safeClosest(event.target, '.sn-mobile-sidebar-toggle');
    if (mobileButton) {
      var mobilePanel = safeClosest(mobileButton, '.sn-panel');
      if (mobilePanel) {
        event.preventDefault();
        event.stopImmediatePropagation();
        setMobile(mobilePanel, !mobilePanel.classList.contains('sn-mobile-sidebar-open'));
      }
      return;
    }

    var innerButton = safeClosest(event.target, '.sn-sidebar-toggle');
    if (innerButton) {
      var panel = safeClosest(innerButton, '.sn-panel');
      if (panel) {
        event.preventDefault();
        event.stopImmediatePropagation();
        if (isMobile()) { setMobile(panel, false); }
        else { setDesktop(panel, !panel.classList.contains('sn-sidebar-collapsed'), true); }
      }
      return;
    }

    var tab = safeClosest(event.target, '.sn-panel .sn-tab[data-tab], .sn-panel .sn-tab-button, .sn-panel [data-sn-tab-target]');
    if (tab && isMobile()) {
      var tabPanel = safeClosest(tab, '.sn-panel');
      if (tabPanel && tabPanel.classList.contains('sn-mobile-sidebar-open')) {
        window.setTimeout(function(){ setMobile(tabPanel, false); }, 40);
      }
    }
  }, true);

  document.addEventListener('keydown', function(event){
    if (event.key !== 'Escape') { return; }
    var openPanels = document.querySelectorAll('.sn-panel.sn-mobile-sidebar-open');
    for (var i=0; i<openPanels.length; i++) { setMobile(openPanels[i], false); }
  });

  document.addEventListener('click', function(event){
	var tab = safeClosest(event.target, '[data-tab="customer-actions"], [data-sn-tab-target="manager-customer-actions"]');
	if (tab) { window.setTimeout(function(){ var panel=safeClosest(tab,'.sn-panel') || document; var root=panel.querySelector('.sn-staff-customer-actions'); if(root && root.dataset.loaded!=='1') loadCustomerActions(root,1); }, 80); return; }
	var filter = safeClosest(event.target, '.sn-staff-customer-actions-filter');
	if (filter) { var filterRoot=safeClosest(filter,'.sn-staff-customer-actions'); if(filterRoot) loadCustomerActions(filterRoot,1); return; }
	var prev = safeClosest(event.target, '.sn-staff-actions-prev'), next = safeClosest(event.target, '.sn-staff-actions-next');
	if (prev || next) { var pagerRoot=safeClosest(prev||next,'.sn-staff-customer-actions'); if(pagerRoot) loadCustomerActions(pagerRoot, Number(pagerRoot.dataset.page||1)+(next?1:-1)); return; }
	var detail = safeClosest(event.target, '.sn-staff-action-detail');
	if (detail) {
		var modal=customerActionsModal(), body=modal.querySelector('.sn-staff-actions-modal-body'); modal.hidden=false; body.innerHTML='<div class="sn-loading">در حال بارگذاری...</div>';
		customerActionsRequest({invoice_id:Number(detail.getAttribute('data-invoice-id')||0),limit:300}).then(function(response){ var payload=customerActionsPayload(response); if(!response||!response.success) throw new Error(customerActionsMessage(response)); var items=Array.isArray(payload.items)?payload.items:[]; body.innerHTML=items.length?'<div class="sn-staff-actions-timeline">'+items.map(function(item){return '<div><strong>'+escapeHtml(item.description||item.action||'رویداد')+'</strong><span>'+escapeHtml(faDigits(item.created_at_jalali||item.created_at||''))+'</span></div>';}).join('')+'</div>':'<div class="sn-notice sn-info">رویدادی ثبت نشده است.</div>'; }).catch(function(error){body.innerHTML='<div class="sn-notice sn-error">'+escapeHtml(error.message||'خطا')+'</div>';}); return;
	}
	if (safeClosest(event.target, '[data-sn-actions-close]')) { customerActionsModal().hidden=true; }
  });

  document.addEventListener('keydown', function(event){
	if (event.key === 'Enter' && event.target && event.target.classList && event.target.classList.contains('sn-staff-customer-actions-search')) { event.preventDefault(); var root=safeClosest(event.target,'.sn-staff-customer-actions'); if(root) loadCustomerActions(root,1); }
	if (event.key === 'Escape') { var modal=document.getElementById('sn-staff-customer-actions-modal'); if(modal) modal.hidden=true; }
  });

  window.addEventListener('resize', function(){
    if (!isMobile()) {
      var openPanels = document.querySelectorAll('.sn-panel.sn-mobile-sidebar-open');
      for (var i=0; i<openPanels.length; i++) { setMobile(openPanels[i], false); }
    }
    setup(document);
  });

  ready(function(){
    setup(document);
    window.setTimeout(function(){ setup(document); }, 80);
    window.setTimeout(function(){ setup(document); }, 350);
    if (window.MutationObserver && document.body) {
      var queued = false;
      var observer = new MutationObserver(function(mutations){
        if (queued) { return; }
        var relevant = false;
        for (var i=0; i<mutations.length; i++) {
          if (mutations[i].addedNodes && mutations[i].addedNodes.length) { relevant = true; break; }
        }
        if (!relevant) { return; }
        queued = true;
        window.setTimeout(function(){ queued = false; setup(document); }, 30);
      });
      observer.observe(document.body, {childList:true, subtree:true});
    }
  });

  window.snSetupSidebarToggles = function(){ setup(document); };
  window.snSetupMobileDrawerNav = function(){ setup(document); };
})();

/* Multi-receipt transport and accessible selection feedback. */
(function(w,d){
  'use strict';
  function esc(v){return String(v==null?'':v).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c];});}
  function urls(record){
    record=record||{};var list=record.receipt_urls||[];
    if(typeof list==='string'){try{list=JSON.parse(list);}catch(e){list=[];}}
    if(!Array.isArray(list))list=[];list=list.concat([record.receipt_url,record.receipt_file]);
    return list.filter(function(u,i,a){return typeof u==='string'&&/^https?:\/\//i.test(u)&&a.indexOf(u)===i;});
  }
  function error(files){
    if(files.length>10)return 'حداکثر ۱۰ فیش در هر ارسال انتخاب کنید.';
    var total=0;
    for(var i=0;i<files.length;i++){
      var f=files[i];total+=f.size;
      if(!/\.(jpe?g|png|webp|pdf)$/i.test(f.name))return 'فقط JPG، PNG، WEBP یا PDF مجاز است: '+f.name;
      if(f.size>5*1024*1024)return 'حجم هر فیش حداکثر ۵ مگابایت است: '+f.name;
    }
    return total>20*1024*1024?'مجموع حجم فایل‌ها حداکثر ۲۰ مگابایت است.':'';
  }
  w.snReceiptFiles={
    urls:urls,
    links:function(record){return '<div class="sn-receipt-links">'+urls(record).map(function(u,i){return '<a class="sn-btn sn-btn-sm sn-btn-secondary" target="_blank" rel="noopener" href="'+esc(u)+'">فیش '+(i+1)+'</a>';}).join(' ')+'</div>';},
    error:error,
    append:function(form,input){
      var files=Array.prototype.slice.call(input&&input.files||[]), message=error(files);
      if(message){w.alert(message);return false;}
      if(!files.length)return true;
      files.forEach(function(file){form.append('receipt[]',file);});
      form.append('receipt_count',String(files.length));return true;
    }
  };
  d.addEventListener('change',function(e){
    var input=e.target;if(!input.matches('input[type="file"][multiple]')||!/receipt/.test(input.id+' '+input.className))return;
    var box=input.nextElementSibling;
    if(!box||!box.classList.contains('sn-receipt-selection')){box=d.createElement('div');box.className='sn-receipt-selection';box.setAttribute('aria-live','polite');input.insertAdjacentElement('afterend',box);}
    var files=Array.prototype.slice.call(input.files||[]), message=error(files);
    box.textContent=message||(files.length?files.length+' فیش انتخاب شده: '+files.map(function(f){return f.name;}).join('، '):'');
    box.classList.toggle('sn-error',!!message);
  });
  // A reopened modal may clear the input programmatically without a change event.
  d.addEventListener('click',function(){d.querySelectorAll('.sn-receipt-selection').forEach(function(box){var input=box.previousElementSibling;if(input&&input.type==='file'&&!input.files.length)box.textContent='';});});
})(window,document);
