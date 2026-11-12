(function () {
	'use strict';


	function opsAjax(formData) {
		return fetch((window.snAjax && snAjax.ajaxurl) ? snAjax.ajaxurl : (window.ajaxurl || ''), {
			method: 'POST',
			credentials: 'same-origin',
			body: formData
		}).then(function (response) {
			return response.json().catch(function () { return { success: false, message: 'پاسخ نامعتبر از سرور' }; });
		});
	}

	function setManualPaymentState(box, message, state) {
		if (!box) { return; }
		box.textContent = message || '';
		box.className = 'sn-ops-manual-payment-state' + (state ? ' is-' + state : '');
	}

	function normalizeDigits(value) {
		return String(value || '').replace(/[۰-۹]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'.indexOf(d); });
	}

	var operationNotices = Array.prototype.slice.call(document.querySelectorAll('.sn-ops-notice'));
	if (operationNotices.length) {
		operationNotices.forEach(function (notice) {
			var close = notice.querySelector('[data-sn-ops-notice-close]');
			if (close) close.addEventListener('click', function () { notice.remove(); });
			if (!notice.classList.contains('is-error')) {
				window.setTimeout(function () { if (notice.isConnected) notice.remove(); }, 10000);
			}
		});
		if (window.history && window.history.replaceState) {
			var operationUrl = new URL(window.location.href);
			operationUrl.searchParams.delete('sn_ops_notice');
			operationUrl.searchParams.delete('sn_ops_kind');
			window.history.replaceState(window.history.state, '', operationUrl.toString());
		}
	}

	document.addEventListener('click', function (event) {
		var nextStageButton = event.target.closest('[data-sn-ops-start-next-stage]');
		if (nextStageButton) {
			var nextCard = nextStageButton.closest('[data-sn-ops-card]');
			var nextSelect = nextCard && nextCard.querySelector('[data-sn-ops-status-select]');
			if (nextSelect) {
				nextSelect.value = 'issue_payment_stage';
				nextSelect.dispatchEvent(new Event('change', {bubbles:true}));
				var nextForm = nextSelect.closest('form');
				var nextAmount = nextForm && nextForm.querySelector('input[name="payment_stage_amount"]');
				if (nextForm) nextForm.scrollIntoView({behavior:'smooth',block:'center'});
				if (nextAmount && !nextAmount.disabled) nextAmount.focus({preventScroll:true});
			}
			return;
		}
		var upgradeButton = event.target.closest('[data-sn-ops-start-upgrade]');
		if (upgradeButton) {
			var card = upgradeButton.closest('[data-sn-ops-card]');
			var select = card && card.querySelector('[data-sn-ops-status-select]');
			if (select) { select.value = 'create_upgrade'; select.dispatchEvent(new Event('change', {bubbles: true})); }
			return;
		}
		var button = event.target.closest('[data-sn-ops-copy-link]');
		if (!button) return;
		var box = button.closest('.sn-ops-payment-link');
		var input = box && box.querySelector('[data-sn-ops-payment-link]');
		var status = box && box.querySelector('[data-sn-ops-copy-status]');
		if (!input || !status) return;
		function fallback() {
			input.focus(); input.select();
			try { status.textContent = document.execCommand('copy') ? 'لینک کپی شد.' : 'لینک را از کادر انتخاب و کپی کنید.'; }
			catch (error) { status.textContent = 'لینک را از کادر انتخاب و کپی کنید.'; }
		}
		if (navigator.clipboard && window.isSecureContext) {
			navigator.clipboard.writeText(input.value).then(function () { status.textContent = 'لینک کپی شد.'; }, fallback);
		} else { fallback(); }
	});

	function normalize(value) {
		return String(value || '')
			.toLocaleLowerCase('fa-IR')
			.replace(/[يى]/g, 'ی')
			.replace(/ك/g, 'ک')
			.replace(/[۰-۹]/g, function (digit) { return String('۰۱۲۳۴۵۶۷۸۹'.indexOf(digit)); })
			.trim();
	}

	function toFaDigits(value) {
		return String(value || '').replace(/\d/g, function (digit) { return '۰۱۲۳۴۵۶۷۸۹'[digit]; });
	}

	function toEnDigits(value) {
		return String(value || '')
			.replace(/[۰-۹]/g, function (digit) { return String('۰۱۲۳۴۵۶۷۸۹'.indexOf(digit)); })
			.replace(/[٠-٩]/g, function (digit) { return String('٠١٢٣٤٥٦٧٨٩'.indexOf(digit)); });
	}

	function pad(value) {
		return String(value).padStart(2, '0');
	}

	function tehranToday() {
		var date = new Date();
		try {
			var parts = new Intl.DateTimeFormat('en-CA', {
				timeZone: 'Asia/Tehran', year: 'numeric', month: 'numeric', day: 'numeric'
			}).formatToParts(date);
			var out = {};
			parts.forEach(function (part) {
				if (part.type !== 'literal') { out[part.type] = Number(part.value); }
			});
			return [out.year, out.month, out.day];
		} catch (error) {
			return [date.getFullYear(), date.getMonth() + 1, date.getDate()];
		}
	}

	function gregorianToJalali(gy, gm, gd) {
		var monthDays = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
		var adjustedYear = gm > 2 ? gy + 1 : gy;
		var days = 355666 + (365 * gy) + Math.floor((adjustedYear + 3) / 4) - Math.floor((adjustedYear + 99) / 100) + Math.floor((adjustedYear + 399) / 400) + gd + monthDays[gm - 1];
		var jy = -1595 + (33 * Math.floor(days / 12053));
		days %= 12053;
		jy += 4 * Math.floor(days / 1461);
		days %= 1461;
		if (days > 365) {
			jy += Math.floor((days - 1) / 365);
			days = (days - 1) % 365;
		}
		var jm = days < 186 ? 1 + Math.floor(days / 31) : 7 + Math.floor((days - 186) / 30);
		var jd = 1 + (days < 186 ? days % 31 : (days - 186) % 30);
		return { jy: jy, jm: jm, jd: jd };
	}

	function currentJalaliDate() {
		var now = tehranToday();
		return gregorianToJalali(now[0], now[1], now[2]);
	}

	function jalaliToGregorian(jy, jm, jd) {
		jy += 1595;
		var days = -355668 + (365 * jy) + (Math.floor(jy / 33) * 8) + Math.floor(((jy % 33) + 3) / 4) + jd + (jm < 7 ? (jm - 1) * 31 : ((jm - 7) * 30) + 186);
		var gy = 400 * Math.floor(days / 146097);
		days %= 146097;
		if (days > 36524) {
			gy += 100 * Math.floor((days - 1) / 36524);
			days = (days - 1) % 36524;
			if (days >= 365) { days += 1; }
		}
		gy += 4 * Math.floor(days / 1461);
		days %= 1461;
		if (days > 365) {
			gy += Math.floor((days - 1) / 365);
			days = (days - 1) % 365;
		}
		var gd = days + 1;
		var leap = (gy % 4 === 0 && gy % 100 !== 0) || gy % 400 === 0;
		var monthLengths = [0, 31, leap ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
		var gm = 1;
		while (gm <= 12 && gd > monthLengths[gm]) {
			gd -= monthLengths[gm];
			gm += 1;
		}
		return { gy: gy, gm: gm, gd: gd };
	}

	function jalaliMonthLength(jy, jm) {
		if (jm <= 6) { return 31; }
		if (jm <= 11) { return 30; }
		var start = jalaliToGregorian(jy, 1, 1);
		var next = jalaliToGregorian(jy + 1, 1, 1);
		var yearDays = Math.round((Date.UTC(next.gy, next.gm - 1, next.gd) - Date.UTC(start.gy, start.gm - 1, start.gd)) / 86400000);
		return yearDays === 366 ? 30 : 29;
	}

	function parseJalali(value) {
		var match = toEnDigits(value).replace(/[-. ]/g, '/').match(/^(\d{4})\/(\d{1,2})\/(\d{1,2})$/);
		if (!match) { return null; }
		var parsed = { jy: Number(match[1]), jm: Number(match[2]), jd: Number(match[3]) };
		if (parsed.jy < 1300 || parsed.jy > 1600 || parsed.jm < 1 || parsed.jm > 12 || parsed.jd < 1 || parsed.jd > jalaliMonthLength(parsed.jy, parsed.jm)) { return null; }
		return parsed;
	}

	function renderJalaliPicker(picker, input, state) {
		var selected = parseJalali(input.value);
		var today = currentJalaliDate();
		var monthNames = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
		picker.dataset.jy = String(state.jy);
		picker.dataset.jm = String(state.jm);
		picker.innerHTML = '<div class="sn-ops-jalali-head"><button type="button" data-jalali-nav="prev" aria-label="ماه قبل">‹</button><strong>' + monthNames[state.jm - 1] + ' ' + toFaDigits(state.jy) + '</strong><button type="button" data-jalali-nav="next" aria-label="ماه بعد">›</button></div><div class="sn-ops-jalali-week"><span>ش</span><span>ی</span><span>د</span><span>س</span><span>چ</span><span>پ</span><span>ج</span></div><div class="sn-ops-jalali-days"></div><div class="sn-ops-jalali-actions"><button type="button" data-jalali-today>امروز</button><button type="button" data-jalali-clear>پاک کردن</button></div>';
		var days = picker.querySelector('.sn-ops-jalali-days');
		var firstGregorian = jalaliToGregorian(state.jy, state.jm, 1);
		var firstOffset = (new Date(Date.UTC(firstGregorian.gy, firstGregorian.gm - 1, firstGregorian.gd)).getUTCDay() + 1) % 7;
		for (var empty = 0; empty < firstOffset; empty += 1) {
			var spacer = document.createElement('span');
			spacer.className = 'is-empty';
			spacer.setAttribute('aria-hidden', 'true');
			days.appendChild(spacer);
		}
		for (var day = 1; day <= jalaliMonthLength(state.jy, state.jm); day += 1) {
			var button = document.createElement('button');
			button.type = 'button';
			button.dataset.jalaliDay = String(day);
			button.textContent = toFaDigits(day);
			var beforeToday = !input.hasAttribute('data-sn-ops-date-any') && (state.jy < today.jy || (state.jy === today.jy && (state.jm < today.jm || (state.jm === today.jm && day < today.jd))));
			if (beforeToday) { button.disabled = true; button.classList.add('is-past'); }
			if (selected && selected.jy === state.jy && selected.jm === state.jm && selected.jd === day) {
				button.classList.add('is-selected');
			}
			days.appendChild(button);
		}
	}

	function updateFollowupForm(form) {
		var date = form.querySelector('input[name="followup_date"]');
		var submit = form.querySelector('[data-sn-ops-followup-submit]');
		if (!date || !submit) { return; }
		submit.disabled = !parseJalali(date.value);
	}


	function moneyNumber(value) {
		var normalized = toEnDigits(value).replace(/[^0-9.]/g, '');
		var parsed = Number(normalized || 0);
		return isFinite(parsed) ? parsed : 0;
	}

	function formatMoneyInput(input) {
		if (!input) { return; }
		var raw = toEnDigits(input.value).replace(/[^0-9]/g, '');
		input.value = raw ? raw.replace(/\B(?=(\d{3})+(?!\d))/g, ',') : '';
	}

	function syncOperationsPaymentFields(form) {
		var plan = form.querySelector('[data-sn-ops-payment-plan]');
		var target = form.querySelector('select[name="target_product_id"]');
		var selected = target && target.options[target.selectedIndex];
		var freeUpgrade = target && !target.disabled && target.value && selected && Number(selected.getAttribute('data-price')) === 0;
		if (plan && freeUpgrade) { plan.value = 'full'; plan.disabled = true; }
		var partial = form.querySelector('[data-sn-ops-partial-payment]');
		var choice = form.querySelector('[data-sn-ops-prepayment-choice]');
		var customWrap = form.querySelector('[data-sn-ops-prepayment-custom-wrap]');
		var custom = form.querySelector('input[name="prepayment_custom"]');
		if (plan && partial) {
			var partialActive = !plan.disabled && plan.value === 'partial';
			partial.hidden = !partialActive;
			if (choice) { choice.disabled = !partialActive; }
			var customActive = partialActive && choice && choice.value === 'custom';
			if (customWrap) { customWrap.hidden = !customActive; }
			if (custom) { custom.disabled = !customActive; }
		}
		var stageMode = form.querySelector('[data-sn-ops-stage-mode]');
		var stageAmountWrap = form.querySelector('[data-sn-ops-stage-amount-wrap]');
		var stageAmount = form.querySelector('input[name="payment_stage_amount"]');
		if (stageMode && stageAmountWrap && stageAmount) {
			var needsAmount = !stageMode.disabled && stageMode.value !== 'remaining';
			stageAmountWrap.hidden = !needsAmount;
			stageAmount.disabled = !needsAmount;
		}
	}

	function updateCaseStatusForm(form) {
		var select = form.querySelector('[data-sn-ops-status-select]');
		var submit = form.querySelector('[data-sn-ops-status-submit]');
		if (!select || !submit) { return; }
		var action = select.value;
		form.querySelectorAll('[data-sn-ops-status-fields]').forEach(function (group) {
			var active = group.getAttribute('data-sn-ops-status-fields') === action;
			group.hidden = !active;
			group.querySelectorAll('input,select,textarea').forEach(function (field) {
				field.disabled = !active;
				field.required = active && field.hasAttribute('data-sn-ops-required');
			});
		});
		syncOperationsPaymentFields(form);
		var valid = action !== '';
		if (action === 'follow_up') {
			var date = form.querySelector('input[name="followup_date"]');
			valid = Boolean(date && parseJalali(date.value));
		} else if (action === 'cancel') {
			var reason = form.querySelector('textarea[name="cancel_reason"]');
			valid = Boolean(reason && reason.value.trim().length >= 3);
		} else if (action === 'create_upgrade') {
			var target = form.querySelector('select[name="target_product_id"]');
			var plan = form.querySelector('[data-sn-ops-payment-plan]');
			valid = Boolean(target && target.value && plan);
			var selected = target && target.options[target.selectedIndex];
			var total = Number(selected && selected.getAttribute('data-price') || 0);
			var addressLabel = form.querySelector('[data-sn-ops-upgrade-address]');
			if (addressLabel) {
				var address = addressLabel.querySelector('textarea');
				addressLabel.hidden = total <= 0;
				address.disabled = total <= 0;
				address.required = total > 0;
				valid = valid && (!address.required || address.value.trim() !== '');
			}
			if (valid && plan.value === 'partial') {
				var choice = form.querySelector('[data-sn-ops-prepayment-choice]');
				var due = choice && choice.value === 'custom' ? moneyNumber((form.querySelector('input[name="prepayment_custom"]') || {}).value) : moneyNumber(choice ? choice.value : 0);
				valid = Boolean(choice && choice.value && due > 0 && due <= total);
			}
		} else if (action === 'issue_payment_stage') {
			var stageMode = form.querySelector('[data-sn-ops-stage-mode]');
			var stageAmount = form.querySelector('input[name="payment_stage_amount"]');
			var remaining = stageAmount ? Number(stageAmount.getAttribute('data-remaining') || 0) : 0;
			var requested = stageAmount ? moneyNumber(stageAmount.value) : 0;
			valid = Boolean(stageMode && (stageMode.value === 'remaining' || (requested > 0 && requested <= remaining)));
		}
		submit.disabled = !valid || form.dataset.snOpsSubmitting === '1';
		submit.textContent = action === 'issue_payment_stage' ? 'صدور پیش فاکتور و ارسال پیامک' : 'ثبت وضعیت';
	}

	document.querySelectorAll('.sn-ops-jalali-date').forEach(function (input) {
		if (input.dataset.snOpsPickerReady) { return; }
		input.dataset.snOpsPickerReady = '1';
		input.readOnly = true;
		input.inputMode = 'none';
		var wrap = document.createElement('span');
		wrap.className = 'sn-ops-jalali-wrap';
		input.parentNode.insertBefore(wrap, input);
		wrap.appendChild(input);
		var trigger = document.createElement('button');
		trigger.type = 'button';
		trigger.className = 'sn-ops-jalali-trigger';
		trigger.textContent = 'تقویم';
		trigger.setAttribute('aria-label', 'باز کردن تقویم شمسی');
		wrap.appendChild(trigger);
		var picker = document.createElement('div');
		picker.className = 'sn-ops-jalali-picker sn-ops-jalali-floating';
		picker.hidden = true;
		picker.setAttribute('role', 'dialog');
		picker.setAttribute('aria-label', 'تقویم شمسی');
		document.body.appendChild(picker);

		function positionPicker() {
			if (picker.hidden) { return; }
			var rect = wrap.getBoundingClientRect();
			var pickerWidth = Math.min(286, window.innerWidth - 24);
			var left = Math.max(12, Math.min(rect.right - pickerWidth, window.innerWidth - pickerWidth - 12));
			var top = rect.bottom + 8;
			if (top + picker.offsetHeight > window.innerHeight - 12 && rect.top > picker.offsetHeight + 20) {
				top = rect.top - picker.offsetHeight - 8;
			}
			picker.style.width = pickerWidth + 'px';
			picker.style.left = left + 'px';
			picker.style.top = Math.max(12, top) + 'px';
		}

		function openPicker() {
			document.querySelectorAll('.sn-ops-jalali-picker').forEach(function (other) {
				if (other !== picker) { other.hidden = true; }
			});
			var state = parseJalali(input.value) || currentJalaliDate();
			renderJalaliPicker(picker, input, state);
			picker.hidden = false;
			positionPicker();
		}

		input.addEventListener('click', openPicker);
		trigger.addEventListener('click', openPicker);
		picker.addEventListener('click', function (event) {
			var nav = event.target.closest('[data-jalali-nav]');
			var dayButton = event.target.closest('[data-jalali-day]');
			var state = { jy: Number(picker.dataset.jy), jm: Number(picker.dataset.jm) };
			if (nav) {
				state.jm += nav.dataset.jalaliNav === 'next' ? 1 : -1;
				if (state.jm < 1) { state.jm = 12; state.jy -= 1; }
				if (state.jm > 12) { state.jm = 1; state.jy += 1; }
				renderJalaliPicker(picker, input, state);
			} else if (dayButton) {
				input.value = toFaDigits(state.jy + '/' + pad(state.jm) + '/' + pad(Number(dayButton.dataset.jalaliDay)));
				input.dispatchEvent(new Event('change', { bubbles: true }));
				picker.hidden = true;
			} else if (event.target.closest('[data-jalali-today]')) {
				var today = currentJalaliDate();
				input.value = toFaDigits(today.jy + '/' + pad(today.jm) + '/' + pad(today.jd));
				input.dispatchEvent(new Event('change', { bubbles: true }));
				picker.hidden = true;
			} else if (event.target.closest('[data-jalali-clear]')) {
				input.value = '';
				input.dispatchEvent(new Event('change', { bubbles: true }));
				picker.hidden = true;
			}
		});
		window.addEventListener('resize', positionPicker);
		window.addEventListener('scroll', positionPicker, true);
	});

	document.querySelectorAll('[data-sn-ops-followup]').forEach(function (form) {
		updateFollowupForm(form);
		form.addEventListener('input', function () { updateFollowupForm(form); });
		form.addEventListener('change', function () { updateFollowupForm(form); });
	});

	document.querySelectorAll('[data-sn-ops-case-status]').forEach(function (form) {
		updateCaseStatusForm(form);
		form.addEventListener('input', function (event) {
			if (event.target && event.target.matches('[data-sn-ops-money]')) { formatMoneyInput(event.target); }
			updateCaseStatusForm(form);
		});
		form.addEventListener('change', function () { updateCaseStatusForm(form); });
		form.addEventListener('submit', function(event){
			var submit = form.querySelector('[data-sn-ops-status-submit]');
			if (form.dataset.snOpsSubmitting === '1') { event.preventDefault(); return; }
			updateCaseStatusForm(form);
			if (!submit || submit.disabled) { event.preventDefault(); return; }
			form.dataset.snOpsSubmitting = '1';
			submit.disabled = true;
			submit.textContent = 'در حال ثبت و ارسال...';
		});
		window.addEventListener('pageshow', function(){delete form.dataset.snOpsSubmitting;updateCaseStatusForm(form);});
	});

	document.addEventListener('click', function (event) {
		if (event.target.closest('.sn-ops-jalali-wrap') || event.target.closest('.sn-ops-jalali-picker')) { return; }
		document.querySelectorAll('.sn-ops-jalali-picker').forEach(function (picker) { picker.hidden = true; });
	});

	document.querySelectorAll('.sn-ops-panel').forEach(function (panel) {
		/* Every Operations case uses the same collapsed-by-default accordion. */
		Array.prototype.slice.call(panel.querySelectorAll('.sn-ops-case')).forEach(function (card) {
			var head = card.querySelector(':scope > .sn-ops-case-head');
			if (!head || head.querySelector('[data-sn-ops-accordion]')) { return; }
			card.classList.add('is-collapsed');
			var toggle = document.createElement('button');
			toggle.type = 'button';
			toggle.className = 'sn-ops-accordion-toggle';
			toggle.setAttribute('data-sn-ops-accordion', '1');
			toggle.setAttribute('aria-expanded', 'false');
			toggle.setAttribute('aria-label', 'باز کردن پرونده');
			toggle.innerHTML = '<span class="sn-ops-accordion-label">مشاهده پرونده</span><span class="sn-ops-accordion-chevron" aria-hidden="true"></span>';
			toggle.addEventListener('click', function () {
				var collapsed = card.classList.toggle('is-collapsed');
				toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
				toggle.setAttribute('aria-label', collapsed ? 'باز کردن پرونده' : 'بستن پرونده');
				var label = toggle.querySelector('.sn-ops-accordion-label');
				if (label) { label.textContent = collapsed ? 'مشاهده پرونده' : 'بستن پرونده'; }
			});
			head.appendChild(toggle);
		});
		var input = panel.querySelector('[data-sn-ops-search]');
		var status = panel.querySelector('[data-sn-ops-status]');
		var contactStatus = panel.querySelector('[data-sn-ops-contact-status]');
		var cardFilter = panel.querySelector('[data-sn-ops-card-filter]');
		var cards = Array.prototype.slice.call(panel.querySelectorAll('[data-sn-ops-card]'));
		var workspaceTabs = panel.querySelector('[data-sn-ops-workspace-tabs]');
		var currentWorkspaceTab = 'all';
		function workspaceMatches(card, tab) {
			var category = card.getAttribute('data-ops-category') || 'action';
			var contact = card.getAttribute('data-contact-status') || '';
			if (tab === 'all') { return true; }
			if (tab === 'followup') { return card.getAttribute('data-followup') === '1' && category !== 'history'; }
			if (tab === 'no_answer') { return contact === 'no_answer' && category !== 'history'; }
			return category === tab;
		}
		function updateWorkspaceCounts() {
			if (!workspaceTabs) { return; }
			workspaceTabs.querySelectorAll('[data-sn-ops-workspace-tab]').forEach(function (button) {
				var key = button.getAttribute('data-sn-ops-workspace-tab');
				var count = cards.filter(function (card) { return workspaceMatches(card, key); }).length;
				var value = button.querySelector('[data-sn-ops-tab-count]');
				if (value) { value.textContent = toFaDigits(count); }
				button.classList.toggle('is-active', key === currentWorkspaceTab);
				button.setAttribute('aria-pressed', key === currentWorkspaceTab ? 'true' : 'false');
			});
		}
		var empty = panel.querySelector('[data-sn-ops-no-results]');
		var bulkForm = panel.querySelector('[data-sn-ops-bulk-form]');
		var bulkTarget = panel.querySelector('[data-sn-ops-bulk-target]');
		var bulkSubmit = panel.querySelector('[data-sn-ops-bulk-submit]');
			var selectedCount = panel.querySelector('[data-sn-ops-selected-count]');
			var selectAll = panel.querySelector('[data-sn-ops-select-all]');
		var selectVisible = panel.querySelector('[data-sn-ops-select-visible]');
		var clearSelection = panel.querySelector('[data-sn-ops-clear-selection]');
		if (!cards.length) { return; }
		function bulkCheckboxes() {
			return Array.prototype.slice.call(panel.querySelectorAll('[data-sn-ops-bulk-checkbox]'));
		}
			function updateBulkState() {
				var selected = bulkCheckboxes().filter(function (checkbox) { return checkbox.checked; });
				var visibleSelectable = cards.map(function (card) { return !card.hidden ? card.querySelector('[data-sn-ops-bulk-checkbox]') : null; }).filter(function (checkbox) { return checkbox && !checkbox.disabled; });
				if (selectedCount) { selectedCount.textContent = toFaDigits(selected.length); }
				if (selectAll) {
					var visibleSelected = visibleSelectable.filter(function (checkbox) { return checkbox.checked; }).length;
					selectAll.checked = visibleSelectable.length > 0 && visibleSelected === visibleSelectable.length;
					selectAll.indeterminate = visibleSelected > 0 && visibleSelected < visibleSelectable.length;
					selectAll.disabled = visibleSelectable.length === 0;
				}
				if (bulkSubmit) { bulkSubmit.disabled = !selected.length || !bulkTarget || !bulkTarget.value; }
			}
		function applyFilters() {
			var query = input ? normalize(input.value) : '';
			var selectedStatus = status ? status.value : '';
			var selectedContactStatus = contactStatus ? contactStatus.value : '';
			var selectedCard = cardFilter ? cardFilter.value : '';
			var visible = 0;
			cards.forEach(function (card) {
				var matchesSearch = !query || normalize(card.getAttribute('data-search')).indexOf(query) !== -1;
				var matchesStatus = !selectedStatus || card.getAttribute('data-status') === selectedStatus;
				var matchesContactStatus = !selectedContactStatus || card.getAttribute('data-contact-status') === selectedContactStatus;
				var matchesCard = !selectedCard || card.getAttribute('data-card-type') === selectedCard;
				var matches = matchesSearch && matchesStatus && matchesContactStatus && matchesCard && workspaceMatches(card, currentWorkspaceTab);
				card.hidden = !matches;
				if (matches) { visible += 1; }
			});
			if (empty) { empty.hidden = visible !== 0; }
			updateBulkState();
		}
		if (workspaceTabs) {
			workspaceTabs.addEventListener('click', function (event) {
				var tab = event.target.closest('[data-sn-ops-workspace-tab]');
				if (!tab || !workspaceTabs.contains(tab)) { return; }
				currentWorkspaceTab = tab.getAttribute('data-sn-ops-workspace-tab') || 'all';
				updateWorkspaceCounts();
				applyFilters();
			});
			updateWorkspaceCounts();
		}
		if (input) { input.addEventListener('input', applyFilters); }
		if (status) { status.addEventListener('change', applyFilters); }
		if (contactStatus) { contactStatus.addEventListener('change', applyFilters); }
		if (cardFilter) { cardFilter.addEventListener('change', applyFilters); }
		bulkCheckboxes().forEach(function (checkbox) { checkbox.addEventListener('change', updateBulkState); });
			if (bulkTarget) { bulkTarget.addEventListener('change', updateBulkState); }
			if (selectAll) {
				selectAll.addEventListener('change', function () {
					cards.forEach(function (card) {
						var checkbox = card.querySelector('[data-sn-ops-bulk-checkbox]');
						if (!card.hidden && checkbox && !checkbox.disabled) { checkbox.checked = selectAll.checked; }
					});
					updateBulkState();
				});
			}
		if (selectVisible) {
			selectVisible.addEventListener('click', function () {
				cards.forEach(function (card) {
					var checkbox = card.querySelector('[data-sn-ops-bulk-checkbox]');
					if (!card.hidden && checkbox && !checkbox.disabled) { checkbox.checked = true; }
				});
				updateBulkState();
			});
		}
		if (clearSelection) {
			clearSelection.addEventListener('click', function () {
				bulkCheckboxes().forEach(function (checkbox) { checkbox.checked = false; });
				updateBulkState();
			});
		}
		if (bulkForm) {
			bulkForm.addEventListener('submit', function (event) {
				var selected = bulkCheckboxes().filter(function (checkbox) { return checkbox.checked; });
				if (!selected.length || !bulkTarget || !bulkTarget.value) { event.preventDefault(); updateBulkState(); }
			});
		}
		updateBulkState();
	});


	document.addEventListener('click', function (event) {
		var manualButton = event.target.closest('[data-sn-ops-manual-submit]');
		if (!manualButton) { return; }
		var box = manualButton.closest('[data-sn-ops-manual-payment]');
		if (!box) { return; }
		event.preventDefault();
		if (manualButton.disabled) { return; }
		var invoiceCode = box.getAttribute('data-invoice-code') || '';
		var amountInput = box.querySelector('[name="ops_manual_amount"]');
		var fromInput = box.querySelector('[name="ops_card_last4"]');
		var toInput = box.querySelector('[name="ops_destination_last4"]');
		var dateInput = box.querySelector('[name="ops_paid_at_jalali"]');
		var noteInput = box.querySelector('[name="ops_manual_note"]');
		var receiptInput = box.querySelector('[name="ops_receipt"]');
		var stateBox = box.querySelector('[data-sn-ops-manual-state]');
		var amount = amountInput ? moneyNumber(amountInput.value) : 0;
		var from4 = fromInput ? normalizeDigits(fromInput.value).replace(/\D/g, '').slice(-4) : '';
		var to4 = toInput ? normalizeDigits(toInput.value).replace(/\D/g, '').slice(-4) : '';
		var timeInput = box.querySelector('[name="ops_paid_time"]');
		var date = dateInput ? normalizeDigits(dateInput.value).trim() : '';
		var time = timeInput ? timeInput.value : '';
		var note = noteInput ? noteInput.value.trim() : '';
		var hasReceipt = !!(receiptInput && receiptInput.files && receiptInput.files[0]);
		var hasInfo = from4 !== '' || date !== '' || time !== '' || to4 !== '';
		if (!invoiceCode) { setManualPaymentState(stateBox, 'کد فاکتور یافت نشد.', 'error'); return; }
		if (amount <= 0) { setManualPaymentState(stateBox, 'مبلغ همین تراکنش را وارد کنید.', 'error'); return; }
		if (!hasInfo && !hasReceipt) { setManualPaymentState(stateBox, 'اطلاعات واریز یا فایل فیش را ثبت کنید.', 'error'); return; }
		if (hasInfo && (from4.length !== 4 || !/^\d{4}\/\d{2}\/\d{2}$/.test(date) || !/^\d{2}:\d{2}$/.test(time))) {
			setManualPaymentState(stateBox, '۴ رقم کارت مبدا، تاریخ شمسی و ساعت تهران را کامل وارد کنید.', 'error'); return;
		}
		manualButton.disabled = true;
		setManualPaymentState(stateBox, 'در حال ثبت...', 'loading');
		// One transaction must contain both its amount and proof. Two requests
		// would reserve the amount twice and can leave an info-only payment behind.
		var paymentData = new FormData();
		paymentData.append('action', 'sn_submit_manual_payment');
		paymentData.append('nonce', (window.snAjax && snAjax.nonce) ? snAjax.nonce : '');
		paymentData.append('invoice_code', invoiceCode);
		paymentData.append('amount', String(amount));
		paymentData.append('entry_mode', hasInfo ? 'info' : 'receipt');
		paymentData.append('manual_transfer_type', 'card');
		paymentData.append('card_from', from4);
		paymentData.append('card_to', to4);
		paymentData.append('paid_at', hasInfo ? date + ' ' + time : '');
		paymentData.append('note', note);
		if (hasReceipt && (!window.snReceiptFiles || !window.snReceiptFiles.append(paymentData, receiptInput))) {
			manualButton.disabled = false; setManualPaymentState(stateBox, 'فایل‌های فیش را بررسی کنید؛ اگر ابزار بارگذاری آماده نیست، صفحه را تازه‌سازی کنید.', 'error'); return;
		}
		var sequence = opsAjax(paymentData).then(function (data) {
			if (!data || !data.success) { throw new Error((data && data.message) ? data.message : 'ثبت واریز / فیش ناموفق بود'); }
			return data;
		});
		sequence.then(function (data) {
			setManualPaymentState(stateBox, (data && data.message) ? data.message : 'ثبت شد.', 'success');
			window.setTimeout(function () { window.location.reload(); }, 1100);
		}).catch(function (error) {
			setManualPaymentState(stateBox, error && error.message ? error.message : 'ثبت اطلاعات ناموفق بود.', 'error');
		}).finally(function () {
			manualButton.disabled = false;
		});
	});

	var activeWalletModal = null;
	var walletReturnFocus = null;
	function openWalletModal(modal, trigger) {
		if (!modal) { return; }
		if (modal.parentNode !== document.body) { document.body.appendChild(modal); }
		walletReturnFocus = trigger || document.activeElement;
		activeWalletModal = modal;
		modal.hidden = false;
		document.body.classList.add('sn-wallet-modal-open');
		var closeButton = modal.querySelector('[data-sn-wallet-close].sn-purpose-wallet-modal-close');
		if (closeButton) { window.setTimeout(function () { closeButton.focus(); }, 0); }
	}
	function closeWalletModal(modal) {
		modal = modal || activeWalletModal;
		if (!modal) { return; }
		modal.hidden = true;
		document.body.classList.remove('sn-wallet-modal-open');
		activeWalletModal = null;
		if (walletReturnFocus && typeof walletReturnFocus.focus === 'function') { walletReturnFocus.focus(); }
		walletReturnFocus = null;
	}
	document.addEventListener('click', function (event) {
		var opener = event.target.closest('[data-sn-wallet-open]');
		if (opener) {
			event.preventDefault();
			openWalletModal(document.getElementById(opener.getAttribute('data-sn-wallet-open')), opener);
			return;
		}
		var closer = event.target.closest('[data-sn-wallet-close]');
		if (closer) {
			event.preventDefault();
			closeWalletModal(closer.closest('[data-sn-wallet-modal]'));
		}
	});
	document.addEventListener('keydown', function (event) {
		if (event.key === 'Escape' && activeWalletModal && !activeWalletModal.hidden) { closeWalletModal(activeWalletModal); }
	});

}());
