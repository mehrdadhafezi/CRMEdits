# R2 — Incremental test infrastructure plan

وضعیت برنامه: **PASS** برای تهیه طرح مبتنی بر فایل‌های موجود؛ اجرای زیرساخت و
سناریوهای runtime زیر در R2 **NOT_RUN** است. provision، نصب dependency، activation،
DB mutation یا اجرای fixture تخریبی بخشی از این مأموریت نیست.

## مرز ایمنی و baseline

مبنای کد `d037fe8…`، Header نسخه 2.0.235 و حداقل PHP 8.0 / WordPress 6.0 است.
نسخه‌های host واقعی هنوز معلوم نیستند. نسخه‌های تاریخی README تست MIS را runtime
فعلی Production فرض نکنید. QA و مسئول فنی باید matrix نسخه‌های مصوب WP/PHP/DB/
WooCommerce را صریح تعیین کنند؛ تا آن زمان compatibility matrix **BLOCKED** است.
نسخه حداقل اعلام‌شده بدون تصمیم مالک از سیاست سازگاری حذف نشود.

فقط داده ساختگی، DB اختصاصی یک‌بارمصرف و خروجی redacted مجاز است. هیچ اتصال،
credential، dump، مشتری، درگاه، SMS یا mail واقعی استفاده نشود. deny-by-default
شبکه در سطح محیط پیش از load وردپرس اعمال شود؛ WordPress filter به‌تنهایی کافی نیست.
فایل [bootstrap تست MIS](../../tests/mis/bootstrap.php#L7) ابتدا wp-load را include
می‌کند و **بعد** DB name و HTTP/mail filters را بررسی می‌کند؛ این ترتیب باید در
provisioner ایزوله جبران و در تسک hardening جداگانه ارزیابی شود.

## فازها و معیار خروج

| فاز | مسئول پیشنهادی | تحویل و dependency | معیار خروج قابل بررسی | وضعیت اجرا |
|---|---|---|---|---|
| T0: قرارداد محیط | مسئول فنی + امنیت + QA | نسخه‌ها و image digest، شبکه بسته، محل outputs، owner و سقف حجم | specification تأییدشده؛ هیچ مسیر/credential Production؛ lifespan و cleanup روشن | NOT_RUN |
| T1: provision تکرارپذیر | QA Infrastructure | test-site WordPress و DB InnoDB یک‌بارمصرف، wp-config خارج repo؛ وابسته به T0 | اجرای دوباره از صفر، assert SHA/SN_VERSION=2.0.235، تست fail-closed روی DB غیرتست و شبکه/SMTP | NOT_RUN |
| T2: bootstrap و role | QA + مسئول فنی/امنیت | smoke activation/init، schema-idempotence و permission fixtures؛ وابسته به T1 | role/cap قبل/بعد قابل انتظار؛ auth/scope منفی؛ صفحات و مسیر سبک/کامل بدون fatal | NOT_RUN |
| T3: MIS موجود | QA داده + مسئول MIS | runner ده فایل موجود؛ وابسته به T1/T2 | suite کامل و دو اجرای متوالی؛ SQL failure، race دو اتصال و preservation assertions | NOT_RUN |
| T4: مالی و گزارش | QA مالی + مالک فنی مالی/گزارش | تست‌های پرداخت موجود + fixtureهای جدید invoice/ledger/report؛ وابسته به T2/T3 مرتبط | مبالغ و scope دقیق، عدم double-credit، rollback/خطای provider و totals معتبر | NOT_RUN |
| T5: HTTP و browser | QA UI + امنیت | dispatcher واقعی WP، cookie/nonce/session، viewport/RTL و uploads؛ وابسته به T2/T4 مرتبط | سناریوهای واقعی کاربر و negative auth، redacted artifacts، عدم اتکا به direct-method تنها | NOT_RUN |
| T6: gate پایدار CI | QA + مسئول GitHub | jobهای آزموده‌شده با نام ثابت و logs محدود؛ وابسته به فاز مربوط | required check فقط پس از run تکرارپذیر، fail معنادار و evidence؛ بدون Secret Production | NOT_RUN |

T1 باید قبل از include وردپرس، مقصد مجاز localhost/service تست، نام DB یکتای
ختم‌شونده به `_test` و sentinel محیط را کنترل کند. بعد از اتصال، `SELECT DATABASE()`
هم تطبیق داده شود. flag `SN_MIS_TEST_ALLOW_RESET=YES` و suffix به‌تنهایی مجوز reset
نیستند. آزمون negative guard نباید وردپرس عملیاتی را برای «اثبات guard» load کند.
حذف fixture فقط در service disposable ساخته‌شده همان run مجاز است.

## بسته تست WordPress و نقش‌ها

- نصب تازه، اجرای دوباره activation/setup روی **داده ساختگی**، upgrade مصوب از
  fixture قدیمی و توقف میانی بررسی شوند؛ idempotence فقط با DB version ثابت اثبات نشود.
- snapshot schema/index، Role/cap و شمار ردیف ساختگی قبل/بعد تهیه شود. اثرهای موجود
  SN_Activator، init bootstrap، catalog normalization و report caps عمداً assert شوند.
- guest، نقش مجاز، نقش نامرتبط، cap لغوشده، HR غیرفعال، خود/تیم/تیم دیگر و hierarchy
  تغییرکرده برای هر read/write/export تست شوند. Role و HR Position یکی فرض نشوند.
- nonce غایب/غلط/منقضی، session غلط، درخواست با user_id جعلی و دسترسی مستقیم به
  handler با دکمه مخفی، نتیجه مجاز/رد و عدم mutation را جداگانه assert کنند.
- shortcode واقعی، route سبک MIS، admin-post، REST و callback بر اساس
  [hook index](../audit/HOOK_API_INDEX.md) پوشش بگیرند. fixture باید نسخه واقعی را
  assert کند؛ fallback `2.0.194-mis-ux-rc1` در bootstrap تست دلیل اجرای 2.0.235 نیست.

## MIS: استفاده از زیرساخت موجود

منبع فعلی [tests/mis/README.md](../../tests/mis/README.md) و
[run-all.php](../../tests/mis/run-all.php) است. ده فایل core، roles-and-render،
large-and-races، conversion-and-history، snapshots-and-edit، advanced-plans،
jobs-and-cache، phone-invoice-and-indexes، setup-and-management و ux-and-reporting
در runner وجود دارند؛ در R2 اجرا نشده‌اند.

پس از T1 و تأیید مقصد fixture، اپراتور QA با مسیرهای **واقعی محیط تست ساخته‌شده**:

```bash
SN_MIS_TEST_ALLOW_RESET=YES \
SN_MIS_TEST_BOOTSTRAP=/absolute/path/to/disposable-wordpress/wp-load.php \
SN_MIS_TEST_OUTPUT=/absolute/path/to/redacted-test-results \
php tests/mis/run-all.php
```

موارد خروج: CSV/XLSX ناسالم، صفر اول، duplicate و formula safety؛ assign/return و
سابقه immutable؛ جلوگیری از double lead؛ error SQL و rollback؛ دو connection برای
قفل و stale preview؛ index مشتق‌شده و مالکیت job/export؛ setup تکراری بدون دست‌زدن
به سابقه. suite طراحی‌شده برای workspace بازطراحی‌شده، رفتار shortcode کلاسیک را
به‌تنهایی اثبات نمی‌کند؛ test dispatcher و browser ورودی classic جدا لازم‌اند.
`performance-volume.php` حجیم و تخریبی است و در run-all نیست؛ پس از correctness،
به‌صورت opt-in در service مستقل اجرا شود. آستانه محلی آن SLA سایت نیست.

## فاکتور، پرداخت و گزارش

دو regression موجود در [tests/payments](../../tests/payments) با bootstrap ایزوله:

```bash
SN_PAYMENT_TEST_BOOTSTRAP=/absolute/path/to/disposable-wordpress/wp-load.php \
php tests/payments/gateway-inquiry.php
SN_PAYMENT_TEST_BOOTSTRAP=/absolute/path/to/disposable-wordpress/wp-load.php \
php tests/payments/receipt-last4.php
```

اولی inquiry با HTTP ساختگی و mismatch مبلغ/order/status را تست می‌کند؛ دومی
last4 اختیاری و نرمال‌سازی رقم را. هیچ‌کدام چرخه کامل پرداخت را پوشش نمی‌دهند.

| حوزه | آزمون جدید ضروری پیش از تغییر عملیاتی | invariant / شواهد خروج |
|---|---|---|
| Invoice | کد شش‌رقمی و legacy، token معتبر/غلط/غایب، اقلام/تخفیف snapshot، پیش‌فاکتور | عدم افشای token و ثبات جمع/واحد ریال-تومان/مراحل |
| Payment | request/inquiry/verify/callback ساختگی، تکرار callback، manual همزمان، SQL failure، receipt چندگانه | عدم paid شدن pending، lock مرحله، عدم double-credit، rollback کامل |
| Finance | view/approve/reject مستقل، دوبار approve، برگشت/رد و سابقه | cap درست برای هر عمل، ledger و invoice سازگار و history محفوظ |
| Wallet/commission/Plus | dry-run بدون ledger، flag خاموش، approved run، snapshot entitlement و retry | ثبت دقیق یک‌بار، effective date و عدم اثر مالی dry-run |
| Reports 2.0.235 | self/team/manager/all و delegation مسئول گزارش، revocation و export | scope ردیف و export یکسان؛ totals با داده ساختگی قابل محاسبه |
| Reports زمان/صفحه‌بندی | as-of pending/rejected، بازه جلالی/تهران، pagination و لغو دسترسی حین export | مرز زمانی دقیق، عدم تکرار/حذف ردیف و عدم نشت تیم دیگر |
| Upload/OTP/API | MIME جعلی و اندازه، فایل خصوصی بدون login، OTP غلط/منقضی/همزمان، API key باطل | عدم ذخیره/نمایش غیرمجاز، throttling قابل اندازه‌گیری و خطای امن |

متدهای report در core و [سرویس‌های reports](../../includes/reports) بررسی شوند؛
نام ux-and-reporting در suite MIS به معنی coverage گزارش‌های مدیریتی جدید نیست.
ریسک receipt URL و شمارنده transient در [بررسی امنیت](R2_SECURITY_REVIEW.md) با
تست hosting/همزمانی جدا سنجیده شود؛ در R2 باگ runtime اصلاح نشده است.

## شواهد و سیاست ورود gate

هر run باید SHA، نسخه runtime/DB/WP/WooCommerce، image digest، seed ساختگی، command،
assertion count، failures و دلیل skip را داشته باشد. logs بدون token/phone واقعی،
و screenshot فقط با fixture ساختگی ذخیره شود. شکست اجرای fixture یا test skip
با PASS جایگزین نشود. Code coverage عددی به‌تنهایی معیار سلامت مالی نیست.

برای اولین تغییر عملیاتی، T1/T2 و آزمون ماژول متأثر از T3/T4/T5 باید با شواهد
واقعی بگذرند؛ بقیه ماژول‌ها با risk-based regression انتخاب شوند. برای Merge صرف
ورود سورس ثابت PR #1، این runtime tests را به‌اشتباه اجراشده فرض نکنید؛ پذیرش آن
Merge باید صریحاً «ورود سورس و مستندات بدون activation/deploy» بماند.
