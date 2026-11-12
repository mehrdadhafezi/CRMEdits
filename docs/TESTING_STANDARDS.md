# Testing standards — R1

گزارش هر کنترل فقط PASS، FAIL، BLOCKED، NOT_RUN یا NOT_APPLICABLE است؛ ابزار
فاقد زیرساخت با NOT_IMPLEMENTED مشخص می‌شود. خروجی تست‌های نسخه‌های قدیمی تحت
`docs/TEST_REPORT_*` شواهد تاریخی‌اند و در این جلسه دوباره اجرا نشده‌اند.

| کنترل | زیرساخت موجود | دستور / شرط |
|---|---|---|
| PHP syntax | scripts/check.py و CLI | `python3 scripts/check.py syntax`؛ همه 77 PHP فعلی |
| JavaScript parse | node --check | همان دستور؛ همه 32 JS فعلی |
| Markdown/قراردادها | scripts/check.py | `python3 scripts/check.py docs`؛ مسیر لینک و ۱۱ بخش قرارداد |
| مخزن/Secret | hygiene و Gitleaks | طبق SECURITY_POLICY؛ worktree و تمام history |
| PHPCS/WordPressCS | NOT_IMPLEMENTED | composer config و baseline legacy موجود نیست |
| PHPStan/Psalm | NOT_IMPLEMENTED | WP/WooCommerce stubs و baseline لازم است |
| Unit مستقل | NOT_IMPLEMENTED | runner مستقل PHPUnit موجود نیست |
| MIS integration/DB/race | tests/mis | WordPress + PHP ZIP + InnoDB یک‌بارمصرف و guard اجباری |
| Payment regression | tests/payments | WordPress test bootstrap؛ پاسخ HTTP ساختگی |
| Auth/API واقعی | PARTIAL | fixture MIS نقش‌ها دارد؛ بقیه نیازمند سناریوی سایت ایزوله |
| UI/mobile/RTL/accessibility | NOT_IMPLEMENTED | browser harness قابل حمل در ZIP نیست |
| Migration/upgrade | PARTIAL | fixture MIS setup؛ کل upgrade نیازمند snapshot ساختگی |
| Dependency audit | NOT_APPLICABLE برای manifest غایب | WP/WooCommerce و provider جداگانه بررسی شوند |

پیش‌نیاز check.py: Python 3.10+، PHP CLI، Node.js. `PHP_BIN` می‌تواند مسیر PHP CLI را
تعیین کند. CI روی PHP 8.3 lint می‌کند؛ بررسی این جلسه روی PHP 8.4 انجام شده است.
هیچ‌کدام به تنهایی سازگاری PHP 8.0، runtime وردپرس یا امنیت endpoint را ثابت نمی‌کند.

تست‌های MIS **جدول‌ها را reset می‌کنند**. پیش‌نیازها و حالت classic را در
[tests/mis/README.md](../tests/mis/README.md) بخوانید. فقط پس از ساخت سایت آزمایشی
با DB_NAME ختم‌شونده به `_test` و بدون داده مشتری، تنظیم Cron/HTTP/mail ایزوله و
نصب افزونه روی همان سایت، مقادیر مسیر واقعی زیر را جایگزین کنید:

```bash
SN_MIS_TEST_ALLOW_RESET=YES \
SN_MIS_TEST_BOOTSTRAP=/absolute/path/to/disposable-wordpress/wp-load.php \
SN_MIS_TEST_OUTPUT=/absolute/path/to/test-results \
php tests/mis/run-all.php
```

guard نام DB و flag لازم است، اما به تنهایی مجوز کار روی دیتابیس را اثبات نمی‌کند.
bootstrap وردپرس پیش از filterهای خود fixture اجرا می‌شود؛ غیرفعال‌سازی شبکه،
WP-Cron، provider و mail باید در سطح محیط نیز پیشاپیش انجام شده باشد. benchmark
`tests/mis/performance-volume.php` جدا، حجیم و تخریبی است؛ خودکار اجرا نشود.

تست‌های پرداخت با همان سایت disposable و متغیر
`SN_PAYMENT_TEST_BOOTSTRAP=/absolute/path/to/disposable-wordpress/wp-load.php`:

```bash
php tests/payments/gateway-inquiry.php
php tests/payments/receipt-last4.php
```

دو دستور بالا را با متغیر تنظیم‌شده در محیط اجرا کنید. فایل اول پاسخ gateway را
stub می‌کند و فایل دوم فیش آپلود نمی‌کند؛ خود راه‌اندازی WordPress همچنان side effect
دارد و بدون محیط ایزوله مجاز نیست.

برای هر ماژول سناریوهای مثبت، منفی، permission/scope، failure و رگرسیون در بخش ۸
[قرارداد آن ماژول](modules/README.md) آمده است. برای تغییر UI، صفحه واقعی shortcode،
عرض موبایل/دسکتاپ، RTL، ارقام فارسی، focus و console بررسی شوند. برای پول و history،
تکرار/همزمانی، rollback خطای SQL و عدم double-credit ضروری‌اند.

شواهد PR باید runtime، commit، دستور دقیق، نتیجه، assertionهای معنادار و دلیل
NOT_RUN را ثبت کند. CI این مخزن فقط Syntax/Secret/Documentation/Hygiene است؛ سبز
شدن آن جایگزین معیار پذیرش، regression مالی یا تأیید release نیست.
