# R2 — Review ownership and responsibility proposal

مبنا: `d037fe8bde4aadbb189c055114683fccf22bb0dc`. این سند پیشنهاد سازمان کار است؛
هیچ کاربر، تیم، Permission یا CODEOWNERS جدیدی اعمال نشده است.

| موضوع | وضعیت | شاهد / نتیجه |
|---|---|---|
| مالک واقعی مخزن | PASS | metadata حساب شخصی `mehrdadhafezi`؛ فقط این هویت تأیید شده است |
| پوشش static مسیرها | PASS | [.github/CODEOWNERS](../../.github/CODEOWNERS#L3) با `*` تمام مسیرها را پوشش می‌دهد |
| تأیید مستقل PR #1 | FAIL | نویسنده PR همان مالک است و reviews ثبت‌شده صفر است |
| امکان Code Owner review مستقل برای PRهای خود مالک | FAIL | تنها Code Owner همان نویسنده است؛ نویسنده نمی‌تواند PR خودش را approve کند |
| CODEOWNERS مؤثر بر PR #1 | FAIL | base یعنی main در SHA `534cbf4…` فقط BOOTSTRAP.md دارد؛ CODEOWNERS موجود در head، قاعده review همین PR نیست |
| مالک تخصصی / جانشین | BLOCKED | نام و اختیار حساب‌های مسئول ارائه یا تأیید نشده‌اند |
| enforce شدن review در GitHub | BLOCKED | تنظیمات واقعی قابل خواندن نبودند؛ [شواهد](../audit/r2/GITHUB_REVIEW_EVIDENCE.json) |

`mergeable: true` فقط نبود conflict قابل محاسبه را نشان می‌دهد؛ معادل approval یا
آمادگی Merge طبق سیاست شرکت نیست. نبود discussion باز نیز جایگزین review نمی‌شود.

## نقش‌های پیشنهادی، بدون هویت فرضی

| نقش | مسئولیت | حداقل دسترسی پیشنهادی | تعیین هویت |
|---|---|---|---|
| مالک محصول/مخزن | تصویب scope و سیاست، تصمیم release و مالک داده | مالک تأییدشده فعلی | @mehrdadhafezi |
| مسئول فنی CRM | تصمیم سازگاری، بازبینی core و ارتباط ماژول‌ها | امکان review مجاز؛ سطح دقیق تابع نوع حساب GitHub | UNASSIGNED |
| Reviewer مستقل و جانشین | بررسی PR فرد دیگر؛ پوشش PRهای مالک و زمان غیبت | دسترسی لازم برای approval معتبر و در صورت انتخاب Code Owner، write صریح | UNASSIGNED |
| مسئول امنیت و GitHub | auth/scope، CI، Secret، ownership و rules | فقط اختیارات لازم؛ admin صرفاً برای اپراتور تنظیمات | UNASSIGNED |
| مسئول مالی فنی | invariant فاکتور، پرداخت، ledger، پورسانت و اثر WooCommerce | review کد؛ بدون دسترسی Production به‌صورت خودکار | UNASSIGNED |
| مسئول QA / Test Infrastructure | fixture، تکرارپذیری، evidence و failure injection | محیط تست ساختگی و CI | UNASSIGNED |
| اپراتور release | artifact، backup، smoke و incident با مجوز جدا | جدا از دسترسی عادی توسعه | UNASSIGNED |
| اپراتور تطبیق Production | تهیه شاهد فایل از snapshot فقط خواندنی | فقط دسترسی مصوب همان بررسی | UNASSIGNED |

این جدول assignment واقعی یا دعوت به مخزن نیست. در حساب شخصی، نقش granular یا
تیم Organization را موجود فرض نکنید؛ گزینه‌های واقعی حساب باید ابتدا بررسی شوند.
هیچ placeholder این جدول نباید عیناً داخل CODEOWNERS قرار بگیرد.

## ماتریس پیشنهادی ماژول‌ها

R = انجام تغییر، A = پاسخ‌گوی scope/تصمیم، C = مشورت/بازبینی تخصصی، V = کنترل QA.
Reviewer تأییدکننده باید از R مستقل باشد؛ اگر A نویسنده است، جانشین مستقل لازم است.

| حوزه واقعی | R | A پیشنهادی | C ضروری برای تغییر حساس | V |
|---|---|---|---|---|
| CORE_AUTH، HR_HIERARCHY_SCOPE، CUSTOMER_PORTAL | توسعه‌دهنده تسک | مسئول فنی | امنیت؛ مسئول کسب‌وکار HR برای تغییر دسترسی | QA: auth، نقش و scope |
| MIS، SELLER_DISTRIBUTION، DOT_CONVERSION | توسعه‌دهنده تسک | مسئول فنی توزیع | مالک فرآیند؛ مالی اگر invoice/اعتبار متأثر شود | QA: history، race و rollback |
| INVOICES، PAYMENTS_FINANCE، COMMISSIONS_WALLETS، SUBSCRIPTIONS_PLUS | توسعه‌دهنده تسک | مسئول مالی فنی | مالک مالی + امنیت در token/callback/ledger | QA مالی مستقل |
| PRODUCTS_CATALOG، PROJECTS، OPERATIONS | توسعه‌دهنده تسک | مسئول فنی حوزه | مالک فرآیند؛ مالی برای قیمت/عضویت/اعتبار | QA قرارداد و UI |
| CAMPAIGNS، INTEGRATIONS، SMS | توسعه‌دهنده تسک | مسئول یکپارچه‌سازی | امنیت + مالک provider؛ مالی برای رخداد پرداخت | QA با stub و شبکه بسته |
| REPORTING، SUPPORT_AUDIT | توسعه‌دهنده تسک | مسئول فنی داده/گزارش | امنیت و مالک داده؛ مالی برای totals | QA scope، زمان و خروجی |
| MIGRATIONS_IMPORT | توسعه‌دهنده تسک | مسئول فنی | مالک داده + مالی/HR مرتبط؛ اپراتور انتشار | QA نصب/upgrade/idempotence |
| SHARED_UI | توسعه‌دهنده تسک | مسئول رابط | مالک ماژول caller؛ امنیت برای output/upload | QA RTL، موبایل و accessibility |
| CI، scripts، AGENTS/CLAUDE و سیاست‌ها | توسعه‌دهنده تسک | مسئول فنی | مسئول امنیت مستقل | QA ابزار/مستندات |

نام همه ماژول‌ها از [قراردادهای موجود](../modules/README.md) است؛ ستون‌ها سمت سازمانی
پیشنهادی‌اند و هنوز به افراد نگاشت نشده‌اند. یک فرد می‌تواند چند نقش داشته باشد،
اما استقلال نویسنده و تأییدکننده با جمع‌شدن عنوان‌ها از بین نمی‌رود.

## تصمیم‌های لازم برای رفع بن‌بست

1. مالک، حساب واقعی reviewer مستقل و حداقل یک جانشین را معرفی و مسئولیت را تصویب کند.
   invitation یا افزایش دسترسی در مأموریت R2 انجام نمی‌شود.
2. برای PR #1 که base فاقد CODEOWNERS است، review مستقل انسانی و سیاست ورود اولیه
   را صریح ثبت کنید؛ CODEOWNERS پیشنهادی head را enforce‌شده فرض نکنید. اگر الزام
   فنی Code Owner برای اولین ورود لازم است، برنامه governance پایه جداگانه باید
   با اختیار مالک و بدون دورزدن review طراحی و اجرا شود.
3. برای PRهای بعدی، پس از تأیید هویت‌ها، پوشش مالک و جانشین مستقل در CODEOWNERS
   **پیشنهاد و review** شود؛ این فایل در R2 تغییر نکرده است.
4. چند مالک در یک pattern به معنی نیاز به تأیید همه آنها نیست؛ معمولاً approval
   یکی کافی است. الزام همزمان review فنی و امنیت/مالی را فقط با افزودن دو username
   یا بالا بردن تعداد approval ثابت‌شده فرض نکنید. تا ایجاد کنترل قابل آزمون، هر
   دو تصمیم در checklist و شواهد review مستقل ثبت و توسط فرد مجاز کنترل شوند.
5. author، آخرین pusher، reviewer، مالک بخش حساس و مسئول Merge در PR ثبت شوند؛
   هیچ استثنای خودتأییدی یا bypass دائمی پیشنهاد نمی‌شود.
