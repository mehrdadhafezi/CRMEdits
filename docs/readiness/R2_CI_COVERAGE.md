# R2 — Actual CI coverage

مبنا [ci.yml](../../.github/workflows/ci.yml)، [check.py](../../scripts/check.py) و
GitHub Actions run `43208523758` روی SHA `d037fe8…` است. نتیجه فعلی هر سه job در
[شاهد JSON](../audit/r2/GITHUB_REVIEW_EVIDENCE.json) ثبت شده است. این بررسی workflow
را تغییر نمی‌دهد و تست runtime جدیدی را اجرا نمی‌کند.

| Check دقیق GitHub | وضعیت مشاهده‌شده | واقعاً چه چیزی اجرا می‌شود | چه چیزی اثبات نمی‌شود |
|---|---|---|---|
| PHP and JavaScript syntax | PASS | PHP 8.3 CLI، `php -n -l` برای 77 فایل `.php` و `node --check` برای 32 فایل `.js` | load وردپرس، type/taint analysis، رفتار، assertion، PHP 8.0 یا افزونه‌های PHP |
| Documentation and repository hygiene | PASS | اعتبار مسیر لینک‌های نسبی Markdown، وجود چند فایل اجباری، ترتیب تیترهای ۱ تا ۱۱ در قراردادهای موجود؛ منع چند نام/پسوند config/dump/key | درستی معنایی سند، existence تیتر anchor، اعتبار URL خارجی، اجرای commandهای مثال، exhaustiveness inventory، کیفیت Mermaid یا محتوای binary/PII |
| Secret scan | PASS | Gitleaks 8.24.3 default rules، scan دایرکتوری و `git --all` روی refs موجود در runner، redact و استثنای دقیق localStorage | نبود همه PIIها یا همه Secretها؛ فایل بیرون repo، تاریخچه حذف‌شده/refs دریافت‌نشده، DB، تنظیمات واقعی host و محتوای همه فایل‌های binary |

## محدودیت‌های مشخص ابزار

- [check.py:21](../../scripts/check.py#L21) فقط parser را صدا می‌زند؛ فایل `.php.example`
  مثل مثال mu-plugin به‌دلیل پسوند نهایی `.example` مشمول lint PHP نیست.
- [check.py:47](../../scripts/check.py#L47) code fence را حذف و scheme/anchor را رد می‌کند؛
  لینک `#heading`، fragment در مسیر فایل و command داخل code block واقعاً تست نمی‌شوند.
- [check.py:65](../../scripts/check.py#L65) قراردادهای **موجود** را بررسی می‌کند؛ حذف
  کامل یک قرارداد یا خالی‌شدن پوشه ماژول‌ها لزوماً با این loop تشخیص داده نمی‌شود.
- [check.py:76](../../scripts/check.py#L76) کنترل نام فایل است؛ `.gitignore` یا این
  check جلوی commit هر فایل حساس با نام دیگر را نمی‌گیرد. بررسی انسان لازم است.
- checksum دانلود Gitleaks در [ci.yml:47](../../.github/workflows/ci.yml#L47) با فایل
  checksum همان release مقایسه می‌شود؛ این کنترل integrity مثبت است، اما بررسی
  vulnerability، provenance مستقل یا signature verification نیست.
- checkout به SHA ثابت pin شده؛ PHP از APT و Node از image runner می‌آیند و نسخه
  دقیق dependency/runtime با lockfile پروژه pin نشده است. هیچ ادعای آسیب‌پذیری
  مشخصی از این موضوع ساخته نشده است.
- `contents: read`، نبود deploy و نبود `pull_request_target` ریسک CI را محدود
  می‌کنند. در YAML هیچ Production secret ارجاع نشده است؛ inventory واقعی secrets،
  Actions permissions و محیط‌های GitHub از این محیط قابل بررسی کامل نبود.

## Trigger و شاخه R2

[ci.yml:3](../../.github/workflows/ci.yml#L3) فقط PR با base=`main` را پوشش می‌دهد؛
push به `docs/**` مستقل از آن فعال است. بنابراین PR R2 که برای diff محدود روی base
شاخه R1 باز می‌شود، PR-trigger این workflow را ندارد؛ push شاخه خودش باید سه job
را اجرا کند. خالی‌بودن خروجی ابزار connector که فقط PR-run را فیلتر می‌کند، اثبات
نشدن push-run نیست. آخرین Check Runs باید به SHA جدید و event صحیح نگاشت شوند.

هم‌نام‌بودن contextها و سبزشدن آنها به معنی required بودن در Branch Protection نیست.
همچنین checkهای branch می‌توانند با تغییر YAML/script ضعیف شوند؛ review مستقل این
فایل‌ها و ارزیابی منبع check بخشی از برنامه governance است.

## آزمون‌های اجرا‌نشده

| حوزه | وضعیت | چرا Checkهای فعلی آن را پوشش نمی‌دهند |
|---|---|---|
| WordPress bootstrap/activation/shortcode | NOT_RUN | وردپرس و fixture runtime بالا نمی‌آید |
| Schema، migration، rollback و InnoDB race | NOT_RUN | service دیتابیس و test-site وجود ندارد |
| Role/capability/nonce/record scope | NOT_RUN | هیچ HTTP dispatcher یا session کاربر تست نمی‌شود |
| MIS suite موجود و classic UI | NOT_RUN | run-all.php در workflow فراخوانی نمی‌شود |
| دو تست پرداخت موجود | NOT_RUN | نیازمند bootstrap وردپرس‌اند و workflow آنها را اجرا نمی‌کند |
| فاکتور/مراحل/ledger/callback کامل | NOT_RUN | fixture و assertion آن در CI موجود نیست |
| گزارش‌های مدیریتی نسخه 2.0.235 | NOT_RUN | syntax سرویس با تست scope، as-of، delegation و totals تفاوت دارد |
| UI، موبایل، RTL، فایل خصوصی و rate-limit همزمان | NOT_RUN | harness قابل حمل مرورگر/شبکه موجود نیست |
| Composer/npm/WordPress vulnerability audit | NOT_RUN | manifest Composer/npm وجود ندارد؛ WP/WooCommerce/providerها جداگانه‌اند |

افزودن gate جدید باید تدریجی و پس از اجرای تکرارپذیر انجام شود؛ job سبزِ placeholder
یا skip شده را required و «تست‌شده» گزارش نکنید. برنامه اجرایی در
[Test Infrastructure Plan](R2_TEST_INFRASTRUCTURE_PLAN.md) آمده است.
