# Testing standards — mandatory security and regression validation

**No verified required tests, no merge. CI green alone is not runtime proof.**

این الزامات برای انسان، Codex و Claude یکسان‌اند. پس از هر تغییر کد، از جمله اصلاح
Review یا حل conflict، تست‌های مرتبط دوباره اجرا شوند؛ برای تغییرات جدید به نتیجه
قدیمی اتکا نشود. قبل از PR و قبل از Merge دو مرحله مستقل بررسی وجود دارد.

گزارش هر کنترل فقط PASS، FAIL، BLOCKED، NOT_RUN یا NOT_APPLICABLE (N/A) است؛ ابزار
فاقد زیرساخت با NOT_IMPLEMENTED مشخص می‌شود. خروجی تست‌های نسخه‌های قدیمی تحت
`docs/TEST_REPORT_*` شواهد تاریخی‌اند و در این جلسه دوباره اجرا نشده‌اند.

## Scope و انتخاب تست ضروری

فایل‌های تغییرکرده را با تسک، مسیر مجاز و Branch مبنا مقایسه کنید؛ Diff کامل
commitها، staged/unstaged و فایل‌های جدید را بخوانید. نمونه با base=`origin/main`:

```bash
git fetch origin
git status --short --branch
git diff --stat origin/main...HEAD
git diff origin/main...HEAD
git diff
git diff --cached
git ls-files --others --exclude-standard
git diff --check
```

اگر base مصوب دیگری دارید آن را جایگزین کنید. `...HEAD` تغییر commit‌نشده را نشان
نمی‌دهد؛ دو diff بعدی و فهرست فایل جدید لازم‌اند. تغییر تصادفی، formatting نامرتبط،
حذف ناخواسته و تغییر قرارداد را بررسی و فقط تغییر خارج از scope متعلق به خودتان
را اصلاح کنید؛ کار دیگران را reset/clean یا خودسرانه حذف نکنید.

اثر بر API، Database، Roles، Permissions و Business Logic را صریح ثبت کنید. از
caller/handler تا service، ذخیره‌سازی، UI/export و side effectهای وابسته را trace
کنید؛ فقط فایل و مسیر موفقی که مستقیم تغییر کرده کافی نیست. تست‌های موجود و بخش ۸
قرارداد ماژول را شناسایی و اجرا کنید. برای باگ اصلاح‌شده/رفتار جدید، در صورت امکان
regression دارای assertion اضافه کنید؛ دلیل و خطر نبودن آن را گزارش دهید.

در تغییر Schema، API، Roles/Permissions یا سازگاری داده، روی هر دو مسیر
`main` و `experimental` بررسی سازگاری اجباری است. SHA هر دو مبنا، قراردادهای
قدیم و جدید، خواندن/نوشتن دادهٔ موجود و اثر تغییر نقش و مجوز را ثبت کنید. تست
ارتقا، برگشت و Hotfix متأثر را با fixture ساختگی مستقل برای هر مسیر اجرا کنید.
در انتقال قابلیت به `main`، تست‌های مرتبط را برای همان PR و آخرین `main`
دوباره اجرا کنید. ناسازگاری حل‌نشده یا نبود تست ضروری مانع آمادگی است.

## Fast-Track risk report

انتخاب‌گر محلی فقط پیشنهاد تست می‌دهد. در checkout با history کامل، SHAها را از PR بردارید:

```bash
python3 -B scripts/risk_report.py --base <base-sha> --head <head-sha> --target main
```

برای PR به شاخهٔ آزمایشی، `--target experimental` بدهید. گزارش فایل‌های Diff
از merge base تا Head (rename به شکل حذف/افزودن)، ماژول‌های وابسته، دلیل انتخاب
یا عدم انتخاب هر suite و وضعیت شواهد را برمی‌گرداند. تغییر مبنا، تاریخچهٔ ناقص یا
اثر نامشخص به UNKNOWN/BLOCKED منجر می‌شود. کلاس MEDIUM فعلاً فقط برای تغییر
محدود فاصله‌گذاری چند selector کمپین با مصرف‌کنندهٔ تأییدشده است. گزارش مالی
به‌خاطر مبالغ و Scope، HIGH است. توسعهٔ نقشه نیازمند شواهد وابستگی و تست مثبت و منفی است.

بدون شاهد بیرونی، همهٔ تست‌ها NOT_RUN ثبت می‌شوند. گزینهٔ `--evidence <file.json>`
شواهد واردشده توسط فراخوان را به‌صورت مشورتی نشان می‌دهد. فایل باید
`base_sha`، `head_sha`، `diff_base_sha` و `target` مطابق گزارش، و یک شیء
`tests` با کلید شناسهٔ suite و مقادیر `status` و `url` معتبر HTTPS داشته باشد.
وضعیت‌ها PASS، FAIL، BLOCKED و NOT_RUN هستند. انتخاب‌گر اعتبار محتوای URL را
تأیید نمی‌کند؛ برای پذیرش نتیجه، CI و شواهد همان revision طبق قواعد فعلی بررسی شوند.

در PRهای `main` و `experimental`، CRM CI خلاصهٔ Phase 2 را بعد از Jobها می‌نویسد
و جزئیات را در Log نگه می‌دارد. نتیجه به Head، Base و والدهای Commit آزمایشی
همان PR وابسته است. ورودی `--evidence` محلی در تصمیم CI استفاده نمی‌شود.
ویرایش صرف توضیحات Checkهای فعلی و Summary را دوباره اجرا می‌کند. خلاصه فقط
از آخرین اجرای کامل و موفق همان PR، مقصد و Commit آزمایشی برای نتیجهٔ تست‌های
اختیاری استفاده می‌کند. شواهد غایب، نامعتبر یا ناموفق به BLOCKED منجر می‌شوند.
تغییر مقصد یا Head اجرای کامل را شروع می‌کند.
اگر Base بدون رویداد جدید تغییر کند، پیش از استفاده از نتیجه شاخهٔ PR را با
Base جدید به‌روز کنید و CI تازه بگیرید.

| ریسک | اجرای خودکار |
| --- | --- |
| LOW | Checkهای پایهٔ مقصد |
| MEDIUM | Checkهای پایه و تست Chromium با دادهٔ ساختگی برای فاصله‌گذاری UI کمپین، در موبایل و RTL |
| HIGH | Checkهای پایه و Jobهای دارای پوشش معتبر. اثر مالی یا مسیرهای تحت پوشش قبلی، هر چهار سناریوی Finance را اجرا می‌کند |
| UNKNOWN | Checkهای پایه و ماتریس Finance به‌شکل محافظه‌کارانه. Summary تا تعیین اثر و تست‌های لازم BLOCKED است |

Finance برای پرداخت و گزارش مالی پوشش دارد. برای تغییرهای گستردهٔ Role/Scope،
Wallet/Commission، Schema، MIS یا UI، نبود پوشش معتبر مرتبط در Summary ثبت می‌شود.
در تغییرهای HIGH که کل Diff محدود به فایل‌های مشخص هماهنگ‌کننده، گزارش یا
تست‌های آن است، پوشش از آزمون‌های ابزار، اجرای واقعی ماتریس مرتبط و شواهد همان
Commit به دست می‌آید. هر تغییر ترکیبی در افزونه، بسته‌بندی یا fixture اجرایی
تست‌های مرتبط خود را حفظ می‌کند.
وضعیت READY FOR MERGE CHECK به‌معنی آمادگی برای بازبینی نهایی است و روند تأیید
مخزن همچنان برقرار است.

## تست امنیتی اجباری بر اساس ارتباط با تغییر

### Bounded payment-method regression (2.0.240)

The additional mapping in `scripts/gateway_manual_ci.py` accepts only base
`b0f9db99be57411cae6f9497f95ea5b26730f99f`, eight exact reviewed runtime hashes
and named test/documentation files. The other 111 runtime files must be identical.
It retains all four Finance jobs and the existing HR/UI, access, wallet/commission,
schema/rollback and ten-script disposable MIS job. No required suite is waived.
Each Finance job additionally runs the manual-method memory/native regressions,
real parallel PHP/MySQL races and actual HTTP/multipart desktop/mobile browser
tests. Missing, stale, failed, skipped or proxy-only evidence still blocks CI
readiness. Native tests never accept a Production or customer-data target.

برای **هر کنترل** زیر نتیجه و شاهد یا دلیل مشخص N/A ثبت شود؛ گروه‌بندی جدول به
معنی حذف کنترل‌های درون گروه نیست. مطالعه کد می‌تواند شاهد review باشد ولی جای
تست runtime موردنیاز را نمی‌گیرد.

| کنترل | نمونه سناریوی لازم در تغییر مرتبط |
|---|---|
| Authentication / Authorization | guest، session/token معتبر و نامعتبر؛ عملیات مجاز و غیرمجاز |
| Role & Capability / Privilege Escalation | cap غایب یا لغوشده، نقش نامرتبط، ورودی role جعلی؛ عدم اعطای دسترسی ناخواسته |
| Record-level Access / API Access Control | رکورد خود/تیم دیگر، شناسه جعلی و دسترسی مستقیم به endpoint/export |
| Nonce / CSRF | nonce غایب، غلط یا نامعتبر و عدم mutation پس از رد درخواست |
| SQL Injection / Input Validation | مقدار/نوع نامعتبر، ورودی array، identifier خارج allowlist، عدم اثر SQL ناخواسته |
| XSS / Output Escaping | داده ساختگی دارای markup در HTML/attribute/URL/JS و escape متناسب با context |
| File Upload Security | MIME/extension جعلی، اندازه، مجوز آپلود/دریافت و عدم ذخیره فایل مردود |
| Sensitive Data Exposure / Secret Scanning | خروجی UI/API/log/export بدون token/اطلاعات محرمانه؛ Gitleaks و بررسی داده حساس |

N/A فقط یعنی کنترل به تغییر مربوط نیست؛ مثلاً تغییر صرفاً متنی مستندات با عدم
تغییر runtime اثبات‌شده، مسیر اجرایی upload یا SQL را عوض نمی‌کند. «وردپرس/DB آماده
نیست» دلیل N/A برای تغییر مرتبط نیست: تست ضروری در این حالت BLOCKED یا NOT_RUN است.
Secret Scan و scope review حتی برای تغییر مستندات انجام شوند. هیچ اطلاعات مشتری
واقعی، payload مالی یا Credential در شاهد، screenshot، PR یا prompt وارد نشود.

## عملکرد و رگرسیون مسیرهای متأثر

| حوزه | سناریوهای پایه در صورت اثرپذیری از تغییر |
|---|---|
| کاربران و دسترسی‌ها | ورود/خروج، تغییر/لغو cap، scope و پنل نقش مرتبط و نامرتبط |
| MIS و تخصیص پرونده | import/assign/return، duplicate، history، race و rollback؛ ورودی classic جدا |
| فروش و مدیریت مشتری | لید خود/دیگران، transition وضعیت، حفظ مشتری/تخصیص و مسیرهای downstream |
| فاکتور | کد جدید/legacy، token، مبلغ/اقلام/تخفیف، وضعیت و مراحل |
| پرداخت و تراکنش | روش‌های متأثر، callback تکراری، manual همزمان، خطای provider ساختگی، عدم double-credit |
| مالی و کیف پول | approve/reject، ledger، بازگشت/رد و ثبت دقیق یک‌بار |
| پورسانت | rule و تاریخ مؤثر، dry-run بدون ledger، posting با flag/approval و عدم ثبت تکراری |
| منابع انسانی | hierarchy/transfer، active state، history و حفظ تفکیک HR Position از WordPress Role |
| گزارش و خروجی | scope ردیف/خروجی، delegation، totals، بازه زمانی و pagination |
| API و یکپارچه‌سازی | ورودی/خروجی/خطای سازگار، auth، retry/idempotence، timeout و عدم اثر بیرونی واقعی |

سناریوی مثبت، منفی، خطا و داده/قرارداد قبلی مرتبط را اجرا کنید. تغییر helper مشترک،
قیمت، scope یا history می‌تواند چند ماژول را متأثر کند؛ تحلیل dependency باید دلیل
انتخاب یا N/A هر حوزه را توضیح دهد. همه WordPress/DB fixtureها فقط روی محیط کاملاً
ایزوله، disposable و synthetic اجرا شوند؛ هیچ تست مخرب یا تغییر داده روی Production
مجاز نیست. بیرون‌رفت شبکه، SMS، mail و درگاه واقعی پیش از bootstrap مسدود باشند.

## Pre-PR، Pre-Merge و تصمیم آمادگی

قبل از PR و هر Push اصلاحی: Syntax، docs/hygiene، Secret Scan، امنیت مرتبط، عملکرد
و رگرسیون لازم اجرا، diff کامل و staged files بازبینی، سازگاری قراردادها بررسی و
شواهد ثبت شوند. نبود تست ضروری با اعتماد به مدل، توضیح شفاهی یا CI سبز جبران نمی‌شود.

قبل از Merge توسط فرد دارای مجوز مستقل همان عمل: آخرین Head SHA، base و Diff را
بخوانید و تأیید کنید required CI موفق به همان Head فعلی مرتبط است. اگر job روی
merge ref تست می‌کند، parentهای head/base آزموده‌شده هم با PR فعلی تطبیق داده شوند.
بعد از commit جدید، حل conflict یا تغییر base مؤثر بر نتیجه، تست مربوط دوباره اجرا
و evidence تازه ثبت شود. نتیجه بررسی Diff (Codex یا دستی)، گفتگوهای باز و وضعیت Checkها کنترل شوند.
Approval انسانی در Rulesetهای فعلی لازم نیست و Codex Review هنوز Gate اجباری
نیست. READY FOR MERGE CHECK یعنی آمادگی کنترل نهایی، نه اختیار Merge.

| وضعیت نهایی تسک | شرط |
|---|---|
| READY FOR MERGE CHECK | تمام تست‌های ضروری این مرحله و کنترل scope/سازگاری موفق؛ N/Aها مستدل؛ بدون blocker شناخته‌شده |
| BLOCKED — TEST FAILURE | حداقل یک تست ضروری FAIL است |
| BLOCKED — MISSING REQUIRED TEST | تست ضروری غایب، BLOCKED یا NOT_RUN است؛ از جمله زیرساخت ناقص |
| BLOCKED — UNRELATED CHANGES | تغییر خارج از scope هنوز رفع/تعیین تکلیف نشده است |
| BLOCKED — SECURITY RISK | خطر امنیتی مرتبط حل‌نشده وجود دارد |

اگر چند مانع وجود دارد همه را ثبت و یک وضعیت اصلی اعلام کنید. PR دارای تست ضروری
ناموفق یا اجرا‌نشده نباید Ready معرفی شود. انتشار نسخهٔ ناقص برای بررسی مانع
فقط با درخواست صریح مالک، Push ایمن و Draft با توضیح نقص مجاز است.
مرحلهٔ گردش کار و علت مانع را جداگانه ثبت کنید. ترتیب بررسی محلی، Draft و CI در
[راهنمای PR](DEVELOPER_HANDBOOK.md#15-pull-request) آمده است.
موفقیت Checkهای فعلی (سه Check عمومی، به‌علاوه مرز شاخه برای main)
تضمین سلامت کل CRM نیست و تست runtime موردنیاز را جایگزین نمی‌کند.

## جدول اجباری تحویل و PR

در پایان هر تسک و داخل PR جدول زیر را با نتیجه **واقعی**، دستور، محیط، revision،
خروجی/assertion و محدودیت تکمیل کنید؛ برای N/A دلیل مرتبط با همین diff لازم است.
بعد از Push، Head و لینک run/jobهای GitHub و زمان بررسی نهایی را هم ثبت کنید.

| Test | Status | Evidence |
|---|---|---|
| Syntax | PASS/FAIL/BLOCKED/NOT_RUN | command، runtime و تعداد/خروجی واقعی |
| Documentation / Hygiene | PASS/FAIL/BLOCKED/NOT_RUN | command و نتیجه |
| Secret Scan | PASS/FAIL/BLOCKED/NOT_RUN | ابزار/نسخه، worktree/history scope، نتیجه redacted |
| Security | PASS/FAIL/BLOCKED/NOT_RUN/N/A | هر کنترل مرتبط، سناریو، شاهد؛ دلیل کنترل‌های نامرتبط |
| Functional | PASS/FAIL/BLOCKED/NOT_RUN/N/A | معیار پذیرش و مسیرهای مثبت/منفی |
| Regression | PASS/FAIL/BLOCKED/NOT_RUN/N/A | تست موجود/جدید و ماژول‌های وابسته؛ دلیل شکاف |
| Scope/Diff | PASS/FAIL | base، فایل‌های مقایسه‌شده، staged/unstaged/new و انحراف‌ها |
| API/DB/Role Compatibility | PASS/FAIL/BLOCKED/NOT_RUN/N/A | اثر روی قرارداد، permissions و business logic یا دلیل عدم اثر |
| GitHub CI / Pre-Merge | PASS/FAIL/BLOCKED/NOT_RUN | Head SHA و run مرتبط؛ بررسی Diff و بحث‌های باز جداگانه |

## زیرساخت موجود و محدودیت واقعی CI

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
| UI/mobile/RTL/accessibility | PARTIAL | پنل‌های تأیید مالی فروشنده/سرپرست/ارشد در tests/payments/integration؛ سایر مسیرها NOT_IMPLEMENTED |
| Finance approval native integration | tests/payments/integration | workflow قابل استفاده در CRM CI یا اجرای دستی؛ WordPress/MySQL واقعی و داده ساختگی، چهار ترکیب نسخه و مبنای ارتقا/برگشت |
| Migration/upgrade | PARTIAL | fixture MIS setup؛ کل upgrade نیازمند snapshot ساختگی |
| Dependency audit | Composer/npm manifest موجود نیست؛ audit خودکار جامع NOT_IMPLEMENTED | WP/WooCommerce و provider جدا بررسی شوند؛ تست ضروری مرتبطِ اجرا‌نشده BLOCKED/NOT_RUN است |

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

برای اصلاح نمایش تأیید مالی، [آزمون یکپارچه اختصاصی](../tests/payments/integration/README.md)
با workflow `Finance approval integration` روی Commit آزمایشی PR اجرا می‌شود. runner فقط VM
یک‌بارمصرف GitHub را می‌پذیرد و پیش از bootstrap، خروجی شبکه حساب PHP/مرورگر را
می‌بندد. نتیجهٔ Commit آزموده‌شده، runtime، assertionها، تصاویر ساختگی و ZIP آزموده‌شده در
artifact همان اجرا ثبت می‌شود؛ وجود workflow به‌تنهایی شاهد PASS نیست.

برای هر ماژول سناریوهای مثبت، منفی، permission/scope، failure و رگرسیون در بخش ۸
[قرارداد آن ماژول](modules/README.md) آمده است. برای تغییر UI، صفحه واقعی shortcode،
عرض موبایل/دسکتاپ، RTL، ارقام فارسی، focus و console بررسی شوند. برای پول و history،
تکرار/همزمانی، rollback خطای SQL و عدم double-credit ضروری‌اند.

شواهد PR باید runtime، commit، دستور دقیق، نتیجه، assertionهای معنادار و دلیل
NOT_RUN را ثبت کند. workflow عمومی CRM CI، Syntax/Secret/Documentation/Hygiene و مرز
شاخه را بررسی می‌کند؛ workflow اختصاصی بالا پوشش runtime تأیید مالی را اضافه می‌کند.
سبزشدن آنها جایگزین معیار پذیرش ماژول‌های خارج از پوشش یا تأیید release نیست.
