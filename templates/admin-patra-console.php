<?php
if (! defined('ABSPATH')) {
	exit;
}

$status = ! $settings['enabled'] ? 'off' : ($has_key ? 'ready' : 'waiting');
$status_labels = ['off' => 'API غیرفعال', 'ready' => 'API فعال', 'waiting' => 'در انتظار کلید'];
?>
<div class="wrap sn-patra-console" dir="rtl">
	<hr class="wp-header-end">
	<svg class="sn-patra-defs" width="0" height="0" aria-hidden="true">
		<symbol id="sn-patra-copy-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">
			<rect x="8" y="8" width="12" height="12" rx="3"/>
			<path d="M16 8V7a3 3 0 0 0-3-3H7a3 3 0 0 0-3 3v6a3 3 0 0 0 3 3h1"/>
		</symbol>
	</svg>

	<header class="sn-patra-hero">
		<div class="sn-patra-hero-copy">
			<div class="sn-patra-hero-meta">
				<span class="sn-patra-kicker" dir="ltr">PATRA PLUS <span> / </span> CRM API</span>
				<span class="sn-patra-status sn-patra-status--<?php echo esc_attr($status); ?>">
					<span aria-hidden="true"></span><?php echo esc_html($status_labels[$status]); ?>
				</span>
			</div>
			<h1><?php echo esc_html(self::TITLE); ?></h1>
			<p>فاکتورها، سفارش‌ها و مراحل پرداخت شبکه فروش را از یک مسیر دریافت کنید.</p>
			<nav class="sn-patra-hero-nav" aria-label="بخش‌های پاترا پلاس">
				<a class="sn-patra-button sn-patra-button--primary" href="#sn-patra-developer">راهنمای توسعه‌دهنده <span aria-hidden="true">↓</span></a>
				<a class="sn-patra-button sn-patra-button--ghost" href="#sn-patra-settings">تنظیمات دسترسی</a>
			</nav>
		</div>
		<div class="sn-patra-hero-art" aria-hidden="true">
			<svg viewBox="0 0 392 235" fill="none" focusable="false">
				<defs>
					<linearGradient id="sn-patra-orbit" x1="24" y1="53" x2="365" y2="185" gradientUnits="userSpaceOnUse">
						<stop stop-color="#94a3ff" stop-opacity=".16"/><stop offset=".54" stop-color="#bb9fff" stop-opacity=".8"/><stop offset="1" stop-color="#89e5d5" stop-opacity=".28"/>
					</linearGradient>
					<linearGradient id="sn-patra-plate" x1="129" y1="59" x2="259" y2="183" gradientUnits="userSpaceOnUse">
						<stop stop-color="#58449b"/><stop offset="1" stop-color="#211d44"/>
					</linearGradient>
					<radialGradient id="sn-patra-aura" cx="0" cy="0" r="1" gradientTransform="translate(200 118) rotate(90) scale(112 165)" gradientUnits="userSpaceOnUse">
						<stop stop-color="#8663f9" stop-opacity=".4"/><stop offset="1" stop-color="#8663f9" stop-opacity="0"/>
					</radialGradient>
				</defs>
				<ellipse cx="200" cy="118" rx="165" ry="112" fill="url(#sn-patra-aura)"/>
				<ellipse cx="196" cy="118" rx="179" ry="60" transform="rotate(-16 196 118)" stroke="url(#sn-patra-orbit)"/>
				<ellipse cx="196" cy="118" rx="148" ry="75" transform="rotate(19 196 118)" stroke="#a7a1f3" stroke-opacity=".34" stroke-dasharray="3 6"/>
				<ellipse cx="196" cy="118" rx="101" ry="100" stroke="#baaaff" stroke-opacity=".15"/>
				<path d="M196 15v206M69 118h254" stroke="#a69ae2" stroke-opacity=".13"/>
				<rect x="137" y="66" width="118" height="118" rx="32" fill="#110f27" stroke="#b4a0fd" stroke-opacity=".25" transform="rotate(-12 196 125)"/>
				<rect x="137" y="59" width="118" height="118" rx="32" fill="url(#sn-patra-plate)" stroke="#b9a6ff" stroke-opacity=".75" transform="rotate(-12 196 118)"/>
				<path d="M169 146V91h24a15 15 0 0 1 0 30h-23m64-18v27m-13.5-13.5h27" transform="rotate(-12 196 118)" stroke="#e1d7ff" stroke-width="7" stroke-linecap="round" stroke-linejoin="round"/>
				<circle cx="53" cy="142" r="5" fill="#b7a7ff"/><circle cx="341" cy="77" r="5" fill="#a1f2dc"/>
				<circle cx="277" cy="186" r="3" fill="#d9ceff"/><circle cx="110" cy="56" r="3" fill="#f0cbff"/>
				<path d="M60 80h12m-6-6v12m256 69h12m-6-6v12" stroke="#b6b2f8" stroke-opacity=".7"/>
			</svg>
			<span class="sn-patra-art-tag" dir="ltr">GET <i></i> REST / V1</span>
		</div>
	</header>

	<?php if ($this->notice !== '') : ?>
		<div class="sn-patra-notice sn-patra-notice--<?php echo esc_attr($this->notice_type); ?>" role="status"><?php echo esc_html($this->notice); ?></div>
	<?php elseif (isset($_GET['patra_saved'])) : ?>
		<div class="sn-patra-notice sn-patra-notice--success" role="status">تنظیمات ذخیره شد.</div>
	<?php endif; ?>
	<?php if ($this->new_key !== '') : ?>
		<section class="sn-patra-panel sn-patra-key" aria-labelledby="sn-patra-key-label">
			<div>
				<span class="sn-patra-kicker" dir="ltr">ACCESS KEY</span>
				<label id="sn-patra-key-label" for="sn-patra-new-key">کلید جدید پاترا پلاس</label>
				<p>کلید فقط در همین پاسخ نمایش داده می‌شود. آن را در تنظیمات امن برنامه مقصد ذخیره کنید.</p>
			</div>
			<div class="sn-patra-key-value">
				<input id="sn-patra-new-key" dir="ltr" readonly autocomplete="off" spellcheck="false" value="<?php echo esc_attr($this->new_key); ?>">
				<button class="sn-patra-button sn-patra-copy" type="button" data-patra-copy="sn-patra-new-key" hidden>
					<svg aria-hidden="true"><use href="#sn-patra-copy-icon"></use></svg><span>کپی کلید</span>
				</button>
			</div>
		</section>
	<?php endif; ?>

	<div class="sn-patra-intro-grid">
		<section class="sn-patra-panel sn-patra-connect" aria-labelledby="sn-patra-connect-title">
			<div class="sn-patra-panel-heading">
				<div>
					<span class="sn-patra-kicker" dir="ltr">ENDPOINT</span>
					<h2 id="sn-patra-connect-title">مسیر اتصال</h2>
				</div>
				<span class="sn-patra-tag" dir="ltr">HTTPS</span>
			</div>
			<p>پس از فعال‌سازی و ساخت کلید، درخواست را به آدرس همین سایت بفرستید.</p>
			<div class="sn-patra-endpoint">
				<div class="sn-patra-route" dir="ltr">
					<span class="sn-patra-method">GET</span><code id="sn-patra-url"><?php echo esc_html($url); ?></code>
				</div>
				<button class="sn-patra-button sn-patra-copy" type="button" data-patra-copy="sn-patra-url" hidden>
					<svg aria-hidden="true"><use href="#sn-patra-copy-icon"></use></svg><span>کپی آدرس</span>
				</button>
			</div>
			<div class="sn-patra-auth">
				<code dir="ltr">Authorization: Bearer &lt;key&gt;</code>
				<p>اگر هدر <bdi>Authorization</bdi> به وردپرس نمی‌رسد، هدر <code dir="ltr">X-Patra-Key: &lt;key&gt;</code> را بفرستید.</p>
			</div>
		</section>

		<section class="sn-patra-panel sn-patra-settings" id="sn-patra-settings" aria-labelledby="sn-patra-settings-title">
			<div class="sn-patra-panel-heading">
				<div>
					<span class="sn-patra-kicker" dir="ltr">ACCESS CONTROL</span>
					<h2 id="sn-patra-settings-title">تنظیمات دسترسی</h2>
				</div>
				<span class="sn-patra-tag <?php echo $has_key ? 'sn-patra-tag--ready' : ''; ?>"><?php echo $has_key ? 'کلید تنظیم شده' : 'بدون کلید'; ?></span>
			</div>
			<form method="post" action="<?php echo esc_url(admin_url('admin.php?page=' . self::PAGE)); ?>">
				<?php wp_nonce_field('sn_patra_plus_settings'); ?>
				<label class="sn-patra-setting">
					<span><strong>فعال‌سازی API</strong><small>اجازه دریافت فاکتورها و سفارش‌ها</small></span>
					<input class="sn-patra-toggle" type="checkbox" name="patra_enabled" value="1" <?php checked($settings['enabled']); ?>>
				</label>
				<label class="sn-patra-setting" for="sn-patra-page-size">
					<span><strong>اندازه پیش‌فرض صفحه</strong><small>تعداد فاکتور مبنا در هر درخواست</small></span>
					<input type="number" dir="ltr" id="sn-patra-page-size" name="patra_page_size" min="1" max="<?php echo esc_attr((string) self::MAX_PAGE_SIZE); ?>" value="<?php echo esc_attr((string) $settings['page_size']); ?>">
				</label>
				<div class="sn-patra-actions">
					<button class="sn-patra-button sn-patra-button--primary" type="submit" name="patra_action" value="save">ذخیره تنظیمات</button>
					<button class="sn-patra-button" type="submit" name="patra_action" value="rotate" formnovalidate><?php echo $has_key ? 'ساخت کلید جایگزین' : 'ساخت کلید دسترسی'; ?></button>
					<?php if ($has_key) : ?><button class="sn-patra-button sn-patra-button--danger" type="submit" name="patra_action" value="revoke" formnovalidate>لغو کلید</button><?php endif; ?>
				</div>
				<?php if ($has_key) : ?><p class="sn-patra-form-note">ساخت کلید جدید، کلید قبلی را باطل می‌کند.</p><?php endif; ?>
			</form>
		</section>
	</div>

	<section id="sn-patra-developer" class="sn-patra-developer" aria-labelledby="sn-patra-developer-title">
		<div class="sn-patra-doc-header">
			<div>
				<span class="sn-patra-kicker" dir="ltr">DEVELOPER GUIDE</span>
				<h2 id="sn-patra-developer-title">راهنمای توسعه‌دهنده</h2>
			</div>
			<nav class="sn-patra-doc-nav" aria-label="فهرست مستندات">
				<a href="#sn-patra-history">سوابق قبلی</a>
				<a href="#sn-patra-request">نمونه درخواست</a>
				<a href="#sn-patra-response">ساختار پاسخ</a>
			</nav>
		</div>

		<section class="sn-patra-panel sn-patra-history" id="sn-patra-history" aria-labelledby="sn-patra-history-title">
			<div class="sn-patra-panel-heading">
				<div>
					<span class="sn-patra-kicker" dir="ltr">HISTORICAL SYNC</span>
					<h3 id="sn-patra-history-title">اتصال سوابق قبلی</h3>
				</div>
				<span class="sn-patra-tag">خواندن از CRM</span>
			</div>
			<p>فاکتورهای قبلی از همین مسیر خوانده می‌شوند. سفارش‌هایی که در CRM یا ووکامرس به فاکتور مرتبط‌اند با همان پیوند برمی‌گردند.</p>
			<ol class="sn-patra-steps">
				<li><span class="sn-patra-step-no" aria-hidden="true">۰۱</span><div><strong>از اول بخوانید</strong><p>برای دریافت سوابق <code dir="ltr">after_id=0</code> بفرستید.</p></div></li>
				<li><span class="sn-patra-step-no" aria-hidden="true">۰۲</span><div><strong>صفحه‌ها را ادامه دهید</strong><p>اگر <code dir="ltr">has_more</code> فعال است، <code dir="ltr">next_after_id</code> را در درخواست بعدی بفرستید.</p></div></li>
				<li><span class="sn-patra-step-no" aria-hidden="true">۰۳</span><div><strong>در مقصد پیوند دهید</strong><p>با <code dir="ltr">record_id</code> به‌روزرسانی و با <code dir="ltr">invoice_code</code> گروه‌بندی کنید.</p></div></li>
			</ol>
			<p class="sn-patra-footnote">در سوابقی که رابطهٔ سفارش و فاکتور ثبت نشده، تطبیق آن‌ها به شناسهٔ معتبر نیاز دارد. برای خواندن تغییرات یک فاکتور قدیمی، دوباره با کد همان فاکتور درخواست بزنید.</p>
		</section>

		<div class="sn-patra-reference-grid" id="sn-patra-request">
			<section class="sn-patra-panel sn-patra-request" aria-labelledby="sn-patra-request-title">
				<div class="sn-patra-panel-heading">
					<div>
						<span class="sn-patra-kicker" dir="ltr">REQUEST SAMPLES</span>
						<h3 id="sn-patra-request-title">نمونه درخواست</h3>
					</div>
					<span class="sn-patra-tag" dir="ltr">cURL</span>
				</div>
				<p>کلید را در متغیر محیطی <code dir="ltr">PATRA_API_KEY</code> قرار دهید.</p>
				<div class="sn-patra-tabs" role="tablist" aria-label="نمونه‌های درخواست" hidden>
					<?php foreach ($examples as $key => $example) : ?>
						<button type="button" id="sn-patra-tab-<?php echo esc_attr($key); ?>" role="tab" aria-selected="false" aria-controls="sn-patra-sample-<?php echo esc_attr($key); ?>" tabindex="-1"><?php echo esc_html($example['label']); ?></button>
					<?php endforeach; ?>
				</div>
				<?php foreach ($examples as $key => $example) :
					$command = $curl_prefix . ' \\' . "\n  " . escapeshellarg(add_query_arg($example['query'], $url));
					?>
					<div class="sn-patra-sample" id="sn-patra-sample-<?php echo esc_attr($key); ?>">
						<div class="sn-patra-codebox">
							<div class="sn-patra-codebar">
								<span dir="ltr"><i aria-hidden="true"></i> terminal <b>/</b> <?php echo esc_html($key); ?></span>
								<button class="sn-patra-button sn-patra-copy" type="button" data-patra-copy="sn-patra-curl-<?php echo esc_attr($key); ?>" hidden>
									<svg aria-hidden="true"><use href="#sn-patra-copy-icon"></use></svg><span>کپی درخواست</span>
								</button>
							</div>
							<pre dir="ltr" tabindex="0" aria-label="<?php echo esc_attr($example['label']); ?>"><code id="sn-patra-curl-<?php echo esc_attr($key); ?>"><?php echo esc_html($command); ?></code></pre>
						</div>
						<p class="sn-patra-sample-hint"><?php echo esc_html($example['hint']); ?></p>
					</div>
				<?php endforeach; ?>
			</section>

			<section class="sn-patra-panel" aria-labelledby="sn-patra-params-title">
				<div class="sn-patra-panel-heading">
					<div>
						<span class="sn-patra-kicker" dir="ltr">QUERY PARAMETERS</span>
						<h3 id="sn-patra-params-title">پارامترهای درخواست</h3>
					</div>
				</div>
				<table class="sn-patra-table">
					<thead><tr><th scope="col">پارامتر</th><th scope="col">کاربرد</th></tr></thead>
					<tbody>
						<tr><th scope="row"><code dir="ltr">type</code></th><td><code>all</code> هر دو، <code>invoice</code> فاکتور، <code>order</code> سفارش.</td></tr>
						<tr><th scope="row"><code dir="ltr">invoice_code</code></th><td>اختیاری. کد دقیق فاکتور. در دات‌فلو، کد مشترک یا کد قدیمی یک مرحله، همه مراحل همان فروش را پیدا می‌کند.</td></tr>
						<tr><th scope="row"><code dir="ltr">limit</code></th><td>۱ تا <?php echo esc_html((string) self::MAX_PAGE_SIZE); ?> فاکتور مبنا. تعداد آبجکت‌های پاسخ می‌تواند بیشتر باشد.</td></tr>
						<tr><th scope="row"><code dir="ltr">after_id</code></th><td>صفر برای شروع. برای ادامه، مقدار <code dir="ltr">next_after_id</code> را با همان فیلترها بفرستید.</td></tr>
					</tbody>
				</table>
				<p class="sn-patra-footnote">در فیلتر سفارش، صفحه‌ای با <code dir="ltr">items</code> خالی ممکن است هنوز صفحهٔ بعد داشته باشد.</p>
			</section>
		</div>

		<section class="sn-patra-panel sn-patra-response" id="sn-patra-response" aria-labelledby="sn-patra-response-title">
			<div class="sn-patra-panel-heading">
				<div>
					<span class="sn-patra-kicker" dir="ltr">RESPONSE ANATOMY</span>
					<h3 id="sn-patra-response-title">ساختار پاسخ</h3>
				</div>
				<span class="sn-patra-tag sn-patra-tag--ready" dir="ltr">200 / JSON</span>
			</div>
			<p>هر عضو <code dir="ltr">items</code> یک فاکتور یا سفارش و یک مرحله پرداخت را نشان می‌دهد.</p>
			<div class="sn-patra-response-grid">
				<dl class="sn-patra-fields">
					<div><dt>شناسه‌ها</dt><dd><code>invoice_id</code> شناسه فاکتور مشترک و <code>source_invoice_id</code> شناسه رکورد هر مرحله در CRM است. در دات‌فلو این دو می‌توانند متفاوت باشند.</dd></div>
					<div><dt>انواع سفارش</dt><dd><code>woocommerce</code> سفارش ووکامرس، <code>shipping</code> ارسال محصول و <code>operations_shipping</code> ارسال کارت عملیات است.</dd></div>
					<div><dt>مبالغ و تأیید</dt><dd><code>invoice_total</code> جمع فاکتور و <code>payment_stage.amount</code> مبلغ مرحله است. هر دو رشته اعشاری به تومان‌اند. <code>payment_approved</code> وضعیت و <code>approved_at</code> زمان تأیید را نشان می‌دهد.</dd></div>
					<div><dt>تاریخ و حساب مقصد</dt><dd><code>payment_date</code> با قالب <bdi>ISO 8601</bdi> و <code>payment_date_jalali</code> به شمسی است. <code>destination_account</code> حساب مقصد و <code>last_digits</code> ارقام پایانی ثبت‌شده است. برای چند واریز، <code>transactions</code> و <code>destination_accounts</code> را بخوانید. مقدار نامعلوم <code>null</code> است.</dd></div>
					<div><dt>مشتری و سرپرست</dt><dd><code>customer_name</code> نام کامل و <code>call_center</code> نام سرپرست فروش است. اگر فقط نام کامل ثبت شده باشد، واژهٔ اول به <code>first_name</code> و بقیه به <code>last_name</code> می‌رود. آدرس خالی با «<?php echo esc_html(SN_Helpers::MISSING_CUSTOMER_ADDRESS); ?>» نمایش داده می‌شود.</dd></div>
				</dl>
				<div class="sn-patra-codebox sn-patra-json">
					<div class="sn-patra-codebar">
						<span dir="ltr"><i aria-hidden="true"></i> response.json</span>
						<button class="sn-patra-button sn-patra-copy" type="button" data-patra-copy="sn-patra-json-example" hidden>
							<svg aria-hidden="true"><use href="#sn-patra-copy-icon"></use></svg><span>کپی JSON</span>
						</button>
					</div>
					<div class="sn-patra-json-caption"><strong>نمونهٔ دو مرحله‌ای</strong><code dir="ltr">invoice_code: 28460125</code></div>
					<pre dir="ltr" tabindex="0" aria-label="نمونه JSON فاکتور دو مرحله‌ای"><code id="sn-patra-json-example"><?php echo esc_html($example_json); ?></code></pre>
				</div>
			</div>
		</section>

		<section class="sn-patra-panel sn-patra-http" aria-labelledby="sn-patra-http-title">
			<div class="sn-patra-panel-heading">
				<div>
					<span class="sn-patra-kicker" dir="ltr">HTTP STATUS</span>
					<h3 id="sn-patra-http-title">کدهای پاسخ</h3>
				</div>
			</div>
			<dl class="sn-patra-http-codes">
				<div><dt dir="ltr">200</dt><dd>دریافت موفق</dd></div>
				<div><dt dir="ltr">400</dt><dd>پارامتر نامعتبر</dd></div>
				<div><dt dir="ltr">401</dt><dd>کلید نامعتبر</dd></div>
				<div><dt dir="ltr">403</dt><dd>درخواست بدون HTTPS</dd></div>
				<div><dt dir="ltr">429</dt><dd>محدودیت تعداد درخواست</dd></div>
				<div><dt dir="ltr">503</dt><dd>غیرفعال یا موقتاً در دسترس نیست</dd></div>
			</dl>
		</section>
	</section>
	<div class="screen-reader-text" role="status" aria-live="polite" data-patra-copy-status></div>
</div>
