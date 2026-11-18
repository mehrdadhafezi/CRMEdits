# ممیزی خواندنی Phase 3B

هدف ممیزی `crmstage.payasetareh.ir` است. اسکریپت [staging-audit.sh](../scripts/staging-audit.sh)
۱۴ کنترل رسمی را در یک گزارش JSON جمع می‌کند. شواهد WHM-01، CFG-01، ISO-01،
NET-01، FS-01، HIST-13 و DB-01 در گزارش حفظ می‌شوند. DB-01 نسخهٔ MariaDB
`10.11.19` و اتصال WordPress را ثبت کرده است. انزوای Production همچنان نیازمند
بررسی حساب احرازشده و همهٔ Grants مؤثر است.

در DB-01، ۹ حساب و ۱۲ رکورد مجوز سطح دیتابیس بررسی شدند. underscore در هر ۱۲
رکورد Escape شده بود و مجوزهای سراسری SELECT، INSERT، UPDATE، DELETE، SUPER،
CREATE USER، GRANT، FILE، PROCESS و SHOW DATABASES در نمونهٔ حساب‌ها صفر بود.
نشست WordPress هیچ schema غیرسیستمی اضافه‌ای نشان نداد. این نتایج نمونه و
مشاهدهٔ همان نشست را توصیف می‌کنند. تطبیق نهایی با Production در DB-01-FINAL
ثبت می‌شود.

## اجرا

اپراتور مجاز روی **همان میزبان**، با دسترسی‌های موجود و در محیط فرمان مورد اعتماد،
از یک نسخهٔ بازبینی‌شدهٔ اسکریپت که بیرون DocumentRoot و مخزن اصلی قرار دارد
استفاده کند. اجرای محلی به Bash و Python 3.8 یا بالاتر نیاز دارد. WHM CLI،
`id`، `ps` و `nft` در صورت دسترسی، شواهد کمکی جمع می‌کنند.
اسکریپت فقط روی stdout گزارش می‌دهد. ورودی شواهد و گزارش خصوصی را اپراتور
در محل خصوصی مصوب نگه می‌دارد. اسکریپت برای اجرای دوره‌ای یا استقرار فراخوانی نشود.

```bash
bash /approved/tools/staging-audit.sh --audit-evidence /approved/private/phase3b-evidence.json
```

اجرای بدون `--audit-evidence` شواهد قبلی و مشاهده‌های محدود فعلی را گزارش
می‌کند. `--offline` خواندن WHM، فرایندها، mountها، قواعد و cron میزبان را
غیرفعال می‌کند و برای بازبینی پروندهٔ خصوصی یا تمرین با دادهٔ ساختگی مناسب است.
[نمونهٔ Sanitized](examples/staging-audit.sanitized.json) خروجی حالت offline
با شواهد قبلی است. کد خروج `1` پس از تولید گزارش یعنی وضعیت کلی
`STAGING DEPLOYMENT BLOCKED`. کد `2` یعنی ورودی یا آرگومان رد شده است.

جمع‌آوری محلی به فرادادهٔ WHM، فرایند PHP محتمل، mount و namespace همان
نمونه، قواعد خروجی در namespace قابل مشاهده، تنظیم‌های محدود cgroup،
اشاره‌های cron سیستم و وجود drop-in در مسیر پیش‌فرض محدود است. نبود پردازش
یا مشاهدهٔ موفق یک قاعده، نشانهٔ کامل بودن کنترل نیست. قواعد `nft` فقط با
فرمان `list ruleset` خوانده می‌شوند و متن آن‌ها در خروجی منتشر نمی‌شود.
اسکریپت SQL یا WordPress اجرا نمی‌کند، به سرویس بیرونی درخواست نمی‌فرستد و
فایل Snapshot یا محتوای مشتری را باز نمی‌کند.

## ورودی شواهد خصوصی

فایل JSON باید عادی و متعلق به کاربر اجراکننده باشد، و دسترسی گروه یا سایر
کاربران نداشته باشد. فایل فقط شامل شناسهٔ خصوصی، digest گزارش بازبینی، زمان UTC و نتیجهٔ کنترل‌های
تعریف‌شده است. مسیر، متن تنظیمات، grants خام، رمز، دادهٔ مشتری و آرشیو
پشتیبان وارد این فایل نشوند. نام شناسهٔ خصوصی، زمان و digest در گزارش عمومی
چاپ نمی‌شوند. نمونهٔ زیر قالب یک رکورد **ناقص** است.

```json
{
  "schema": 1,
  "host": "crmstage.payasetareh.ir",
  "records": {
    "DB-01-FINAL": {
      "private_ref": "EXAMPLE-DB-REVIEW",
      "review_sha256": "<sha256-of-the-existing-private-review>",
      "checked_at": "2026-11-17T12:00:00Z",
      "checks": {
        "wordpress_account_and_endpoint_identified": true,
        "production_accounts_and_endpoints_compared": null
      }
    }
  }
}
```

هر `private_ref` نام گزارش خلاصهٔ بازبینی همان کنترل، با مسئول، روش، تاریخ
و نتیجه است. گزارش متنی موجود با نام `<private_ref>.review.txt` کنار JSON، با
همان مالک و دسترسی خصوصی نگهداری شود. `review_sha256` باید SHA-256 محتوای
همان گزارش باشد. اسکریپت فایل عادی، مالک، دسترسی، محتوای غیرخالی و digest را
کنترل می‌کند. گزارش غایب، تغییرکرده یا شناسهٔ بدون مدرک، ورودی را رد می‌کند.
گزارش بازبینی فقط نتیجه و روش بررسی را داشته باشد و شامل credential،
دادهٔ مشتری، خروجی خام Grants یا تنظیمات نشود. مثال بالا با placeholder ناقص است.
قبل از ثبت، اپراتور ارتباط شاهد با محیط و تنظیم فعلی را
تأیید کند. `true` یعنی مشاهدهٔ مؤثر با مدرک تأیید شده، `false` یعنی نقض
واقعی ثبت شده و `null` یا کلید غایب یعنی بررسی ناقص. صرف مشاهدهٔ وجود فایل،
rule، تنظیم یا شناسه، مجوز درج `true` نیست. اسکریپت ساختار، پوشش و سازگاری
شواهد را بررسی می‌کند. تأیید هویت نویسنده و صحت مشاهدات گزارش، و مطابقت آن
با محیط فعلی، مسئولیت اپراتور است. digest تطبیق محتوای گزارش را بررسی می‌کند.
گزارش ساختگی با digest بازمحاسبه‌شده همچنان می‌تواند از کنترل یکپارچگی عبور
کند. بنابراین `ACCEPTED` فقط پذیرش ساختار و یکپارچگی ورودی را نشان می‌دهد.
اسکریپت برای ادعاهای خصوصیِ کامل هم وضعیت `UNVERIFIED` می‌دهد، چون منبعی
برای احراز مستقل اصالت نویسنده و مشاهدات تنظیم نشده است. مقادیر ادعایی ENV-02
نسخهٔ تأییدشدهٔ محیط تلقی نمی‌شوند. نقض صریح همچنان `BLOCKED` گزارش می‌شود.

| شاهد | کلیدهای لازم | شاهدی که اپراتور خصوصی بررسی می‌کند |
|---|---|---|
| DB-01-FINAL | `wordpress_account_and_endpoint_identified`, `production_accounts_and_endpoints_compared`, `direct_and_wildcard_grants_exclude_production`, `global_and_proxy_grants_exclude_production`, `active_available_nested_and_public_roles_exclude_production` | تطبیق endpoint و حساب احرازشدهٔ WordPress، مجوزهای مستقیم، wildcardهای schema و host، مجوزهای سراسری، PROXY و Roleهای مؤثر در هر نمونهٔ مربوط. |
| FS-02 | `effective_plugin_isolated` | مسیر واقعی افزونه با درنظرگرفتن تنظیم WordPress و handler فعلی و استقلال آن از Production. |
| FS-02 | `runtime_mounts_and_symlinks_isolated`, `production_file_access_denied` | نمای mount و symlinkهای runtime و ACL و دسترسی مؤثر PHP و Worker به فایل‌های Production. |
| PHP-02 | `vhost_handler_identified`, `effective_uid_and_process_verified` | نگاشت vhost به PHP Handler واقعی و پردازه و هویت مؤثر همان vhost. پردازه با UID مشترک، فقط نامزد بررسی است. |
| CACHE-02 | `effective_cache_isolated`, `effective_session_storage_and_cookies_isolated`, `effective_uploads_isolated` | backend، دامنه و namespace کش صفحه و object cache، session handler و cookie scope و مسیر uploads مؤثر، در مقایسه با Production. |
| NET-02 | `php_and_workers_covered`, `ipv4_ipv6_namespaces_covered`, `local_relays_and_proxies_covered`, `mail_sms_payment_and_api_denied_in_network_and_services` | بررسی خواندنی قواعد مؤثر IPv4 و IPv6، مسیر OUTPUT یا FORWARD، هویت و namespace PHP و Worker و mailer، proxy یا relay محلی. تنظیمات سرویس نباید امکان ارسال واقعی ایجاد کند. |
| JOB-02 | `effective_wp_cron_reviewed`, `system_cron_timers_and_workers_reviewed` | WP-Cron، فراخوانی مستقیم، Cron حساب و سیستم، timerها و Workerها، همراه با هویت و مقصد هر job مرتبط. |
| RES-02 | `effective_cpu_memory_disk_and_db_limits_verified`, `shared_host_production_protected` | سقف مؤثر همهٔ workloadها با لحاظ والدهای cgroup، کنترل میزبان، دیسک و ظرفیت اتصال DB و اثر بر Production. |
| BAK-02 | `current_snapshot_is_synthetic`, `snapshot_manifest_and_digest_verified` | Manifest و digest ثبت‌شدهٔ Snapshot معتبر و ساختگی، از روی مدارک موجود. |
| RST-02 | `successful_disposable_restore_documented`, `independent_synthetic_destination_verified`, `restored_integrity_checked` | گزارش موفق **قبلی** Restore در مقصد disposable مستقل برای همان دادهٔ ساختگی، شامل نتیجهٔ کنترل یکپارچگی. |
| ENV-02 | `actual_wordpress_php_and_database_versions_verified` | نسخهٔ WordPress روی دیسک و نسخهٔ دقیق PHP از handler فعال و MariaDB از endpoint تطبیق‌داده‌شده. |
| ENV-02 | `woocommerce_state_verified`, `active_dependency_versions_reviewed` | نسخه و وضعیت WooCommerce و فهرست خصوصی نسخه‌های افزونه‌ها و وابستگی‌های فعال. |

در BAK-02 و RST-02، کلید سطح رکوردِ `snapshot_sha256` باید یک digest
SHA-256 یکسان و معتبر برای همان Snapshot باشد. نبود گزارش Restore، نبود digest
یا مغایرت آن، کنترل Restore را `UNVERIFIED` نگه می‌دارد. این مرحله گزارش‌های
موجود را می‌خواند.

در ENV-02، شیء اختیاری `versions` با کلیدهای `wordpress`، `php`، `mariadb`
و `woocommerce` پذیرفته می‌شود. نسخه‌ها قالب عددی سه‌جزئی مثل `10.11.19`
دارند. اگر عدم نصب WooCommerce تأیید شد، مقدار `NOT_INSTALLED` قابل ثبت
است. نسخه‌های قبلی با شناسهٔ قبلی ثبت می‌شوند. پذیرش نسخه در پروندهٔ خصوصی به
تأیید نسخهٔ واقعی در خروجی عمومی منجر نمی‌شود. فهرست خصوصی وابستگی‌ها نزد
اپراتور باقی می‌ماند.

## تکمیل DB-01-FINAL با دسترسی موجود

DBA فقط از دسترسی خواندنی موجود یا شواهد نشست مجازِ WordPress استفاده کند.
خروجی SQL زیر خصوصی است. `CURRENT_USER()`، `USER()` و `DATABASE()` در
نشست DBA، مربوط به همان نشست هستند. برای انتساب به WordPress، حساب احرازشده
و endpoint باید با نشست یا مدرک معتبر همان برنامه تطبیق داده شوند.

```sql
SELECT VERSION(), CURRENT_USER(), USER(), DATABASE(), CURRENT_ROLE();
SHOW GRANTS;
SELECT ROLE_NAME FROM information_schema.ENABLED_ROLES;
SELECT ROLE_NAME, IS_DEFAULT FROM information_schema.APPLICABLE_ROLES;
-- DBA با شناسه‌های واقعی، هر حساب و هر Role مرتبط را خصوصی بررسی کند.
-- SHOW GRANTS FOR 'stage_app_user'@'matched_host';
-- SHOW GRANTS FOR role_name;
-- SHOW GRANTS FOR PUBLIC; -- فقط در نسخه‌های پشتیبانی‌کننده
```

مجوزها برای حساب دقیق، Roleهای تودرتو و `PUBLIC` بررسی شوند. برای هر endpoint
که Production دارد، DBA از فرادادهٔ مجاز همان endpoint استفاده کند. تفاوت
مقادیر متنی `DB_HOST` در ISO-01، تعداد grants و نتیجهٔ
`SHOW DATABASES` به‌تنهایی اثبات نهایی نیستند. هیچ Role در جریان ممیزی تغییر نکند.
[MariaDB SHOW GRANTS](https://mariadb.com/docs/server/reference/sql-statements/administrative-sql-statements/show/show-grants) ·
[Roleهای فعال و تو‌در‌تو](https://mariadb.com/docs/server/reference/system-tables/information-schema/information-schema-tables/information-schema-enabled_roles-table)

## معنی وضعیت‌ها

`VERIFIED` برای دامنه و DocumentRoot از شواهد قبلی می‌آید، تا زمانی که
فرادادهٔ محلی با هویت Staging تعارض نداشته باشد. ۱۲ کنترل دیگرِ جدول رسمی
به بررسی اصالت و کفایت شاهد اپراتور نیاز دارند. `UNVERIFIED` یعنی پوشش یا اصالت شاهد ناقص است
و `BLOCKED` برای نقض ثبت‌شده در شاهد خصوصی به کار می‌رود. نتیجهٔ کلی
**STAGING DEPLOYMENT BLOCKED** است. تکمیل کنترل‌ها و مجوز مستقل طبق
[سیاست استقرار](DEPLOYMENT.md) ثبت شوند.

## محدودهٔ Fast-Track ابزار ممیزی

سطح ریسک ابزار، شواهد و CI برابر `HIGH` است. محدودهٔ محدود شامل همین راهنما،
بخش مستنداتی Deployment، اسکریپت، نمونهٔ JSON و تست اختصاصی آن است. JSON
نمونه دادهٔ غیرقابل اعتماد برای مقایسهٔ خروجی است و به‌عنوان شاهد تست یا
ایزولاسیون بارگذاری نمی‌شود.

انتخاب‌گر محتوای دقیق اسکریپت، Harness اختصاصی و نمونه را با pinهای بازبینی‌شده
تطبیق می‌دهد و مصرف‌کنندگان آن‌ها را در Base و Head بررسی می‌کند. رفتار این نسخه
با بررسی کد، فهرست محدود فرمان‌های خواندنی، تست‌های ورودی و دسترسی فایل و
سناریوهای منفی بررسی شده است. محتوای تغییرکرده، نوع فایل نامعتبر یا مصرف‌کنندهٔ
خارج از محدوده `UNKNOWN` می‌شود. تغییر بعدی در این محتوا به بازبینی اثر تغییر،
اجرای مجدد تست‌ها و به‌روزرسانی pin نیاز دارد.

CI برای این مسیر، تست‌های Fast-Track، Bash Syntax، ShellCheck، اجرای offline
و تطبیق خروجی و Package Parity را الزامی می‌کند. Readiness فقط نتیجهٔ مراحل
موفق در همان Run/Attempt و همان PR، Base، Head و Merge Revision را می‌پذیرد.
برای تغییر همراه در انتخاب‌گر یا CI، ماتریس چهارورودی Finance نیز الزامی است.
ورود فایل اجرایی CRM، بسته‌بندی یا وابستگیِ خارج از محدوده، مسیر محدود را
غیرفعال می‌کند و تست‌های دامنهٔ متأثر را حفظ می‌کند. چهار Check اصلی در هر دو
مسیر اجرا می‌شوند.

[رفتار merge revision در رویداد pull_request](https://docs.github.com/en/actions/reference/workflows-and-actions/events-that-trigger-workflows#pull_request)
و [ابزارهای Runner اوبونتو](https://github.com/actions/runner-images/blob/main/images/ubuntu/Ubuntu2404-Readme.md)
مبنای اجرای CI هستند.
