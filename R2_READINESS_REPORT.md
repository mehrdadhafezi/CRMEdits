# CRM Engineering Governance — R2 Readiness Report

تاریخ بررسی: 2026-11-12، منطقه زمانی Asia/Tehran؛ زمان دقیق مشاهده در
[شواهد GitHub](docs/audit/r2/GITHUB_REVIEW_EVIDENCE.json) ثبت شده است.

Repository: [mehrdadhafezi/CRMEdits](https://github.com/mehrdadhafezi/CRMEdits)
— [PR شماره ۱](https://github.com/mehrdadhafezi/CRMEdits/pull/1).
مبنای ثابت: **`d037fe8bde4aadbb189c055114683fccf22bb0dc`**.

**نتیجه آمادگی Merge شماره ۱: BLOCKED.** PR از نظر Git conflict قابل Merge گزارش
شده و سه Check آن موفق‌اند؛ اما Approval مستقل صفر است، مدل فعلی Code Owner برای
PR خود مالک reviewer مستقل ندارد و enforce شدن قواعد واقعی main قابل اثبات نیست.
این نتیجه مجوز Merge یا تغییر تنظیمات نیست. تست‌های عملیاتی و Production نیز
اجرا نشده‌اند و نباید از سبز بودن CI نتیجه سلامت CRM گرفت.

## 1. دامنه، روش و وضعیت واقعی

این بررسی read-only برای PR #1 و تنظیمات است. تغییرات پیشنهادی مستندات فقط روی
شاخه مستقل **`docs/crm-r2-readiness-review`** از SHA مبنا و worktree جدا قرار می‌گیرند.
شاخه، body، review، تنظیمات یا commitهای PR #1 در R2 تغییر نمی‌کنند. دستور صریح
این مأموریت بر مجوزهای قبلی R1 مقدم است: هیچ Merge، Deploy، Production connection،
دعوت، Role grant یا تغییر امنیتی GitHub انجام نمی‌شود.

| موضوع | وضعیت | شاهد و نتیجه |
|---|---|---|
| تطبیق Head PR #1 با مبنا | PASS | Head واقعی دقیقاً `d037fe8…`؛ نه صرفاً شاخه همنام |
| وضعیت PR #1 | PASS | OPEN، draft=false، merged=false؛ `mergeable=true` صرفاً نتیجه فنی conflict است |
| main واقعی | PASS | `534cbf4ae550cbf278ae3997dc8fe0df6106ad4e`؛ فقط BOOTSTRAP.md |
| شاخه R1 | PASS | `docs/crm-engineering-standard-r1` روی SHA مبنا؛ refs با git ls-remote و metadata تطبیق داده شدند |
| Visibility و دسترسی metadata | PASS | Private، owner حساب شخصی، admin/push برای حساب متصل؛ auto-merge=false |
| سه Check PR #1 | PASS | run `43208523758`، completed/success؛ هر سه job مستقل success |
| Review مستقل PR #1 | FAIL | reviews=[]؛ reviewer درخواست‌شده نیز ثبت نشده؛ «۰ thread باز» جای approval نیست |
| Rulesets / effective main rules / classic protection | BLOCKED | سه GET تازه `Forbidden`؛ مشاهده مرورگر نیز خطای داخلی داد |
| محدودیت پلن | BLOCKED | entitlement قابل مشاهده نیست؛ خطای محیط دلیل نبود GitHub Pro یا عدم پشتیبانی پلن نیست |
| CODEOWNERS برای استقلال و جانشینی | FAIL | تنها مالک، نویسنده PR نیز همان حساب؛ جانشین/متخصص هنوز تعیین نشده |
| onboarding پیش از Merge | FAIL | Handbook دستور دقیق clone شاخه پیش از Merge نداشت و شرط recipe مستقل LOCAL_SETUP مبهم بود؛ اصلاح محدود فقط در R2 پیشنهاد شد، بدون تغییر PR #1 |
| برنامه توسعه Test Infrastructure | PASS | طرح مرحله‌ای مبتنی بر runnerها و محدودیت واقعی تهیه شد؛ اجرای آن ادعا نمی‌شود |
| WordPress / DB / MIS / invoice / payment / report runtime | NOT_RUN | هیچ محیط disposable WordPress/InnoDB provision یا fixture runtime اجرا نشد |
| manifest runtime از Git | PASS | 116 فایل SHA256 و مقایسه offline با checkout؛ نمونه مقایسه روی fixture محلی هم بررسی شد |
| تطبیق واقعی با Production | NOT_RUN | صریحاً ممنوع؛ شماره نسخه یا تأیید ZIP جای witness Production نیست |
| حفاظت از source و مرز مأموریت | PASS | R2 فقط مستندات/manifest/evidence است؛ فایل‌های runtime و تنظیمات GitHub تغییر نمی‌کنند |

وضعیت‌ها: PASS = کنترل مشخص اجرا و موفق شده؛ FAIL = الزام مشاهده‌شده برآورده
نیست؛ BLOCKED = نتیجه به‌علت دسترسی/اطلاعات لازم قابل اثبات نیست؛ NOT_RUN = تست یا
عملیات عمداً اجرا نشده است. PASS تهیه یک برنامه، به معنی PASS اجرای برنامه نیست.

## 2. قابلیت واقعی سه Check

| نام Check | نتیجه | محدوده واقعی | خارج از محدوده |
|---|---|---|---|
| PHP and JavaScript syntax | PASS | PHP 8.3 parser و Node parser؛ 77 PHP و 32 JS در مبنا | اجرای WordPress، رفتار، SQL/permission، PHP 8.0 compatibility |
| Documentation and repository hygiene | PASS | مسیر لینک نسبی، فایل‌های اجباری محدود، تیترهای قراردادهای موجود و چند نام فایل ممنوع | معنای سند، fragment/URL خارجی، commandها، حذف یک قرارداد کامل، PII/binary |
| Secret scan | PASS | Gitleaks نسخه مشخص، worktree و refs حاضر در runner؛ یک استثنای دقیق غیرمحرمانه | تضمین نبود داده مشتری، secret خارج repo، runtime vulnerability یا provenance مستقل |

جزئیات source/line، triggerها، نقاط کور و آزمون‌های اجرا‌نشده در
[R2_CI_COVERAGE.md](docs/readiness/R2_CI_COVERAGE.md) است. در workflow هیچ تست MIS یا
پرداخت، service دیتابیس، مرورگر، PHPCS، PHPStan یا dependency vulnerability audit
فراخوانی نمی‌شود. نبود composer/npm manifest به معنی «وابستگی امن» نیست.

## 3. Rulesets، Branch Protection و محدودیت مشاهده

سه درخواست صرفاً GET به rulesets با inheritedها، قواعد مؤثر main و protection
کلاسیک با exit=1 و `Forbidden` متوقف شدند. وضعیت جاری محیط policy=enforced و
api.github.com خارج allowlist است. ابزار connector می‌تواند PR/CI/metadata را
بخواند اما action خواندن Rulesets/Protection ندارد. تلاش مرورگر تازه R2 نیز با
`Browser Use could not read requirements` شکست خورد.

بنابراین نتیجه **BLOCKED** است: نه «محافظت فعال است»، نه «قطعاً غیرفعال است» و نه
«پلن قطعاً پشتیبانی نمی‌کند». R1 اعلام کرده که خودش هیچ rule اعمال نکرده؛ این
شاهد تاریخی را با خواندن تنظیمات فعلی یکی نگیرید. این مأموریت تنظیمی را عوض نکرد.

فایل [.github/main-ruleset.json](.github/main-ruleset.json) فقط پیشنهاد R1 است.
[دستورهای GET و پیشنهاد اجرایی دستی](docs/readiness/R2_MANUAL_GITHUB_ACTIONS.md)
برای اپراتور مجاز آماده‌اند؛ قبل از هر POST آینده باید ownership، تنظیم موجود،
plan و اختیار همان مأموریت مشخص شوند. مجوز R1 اجازه اجرای POST در R2 نیست.

## 4. CODEOWNERS و استقلال Review

[CODEOWNERS:3](.github/CODEOWNERS#L3) با wildcard و چند مسیر اختصاصی تنها
`@mehrdadhafezi` را معرفی می‌کند. coverage static آن **PASS** است؛ پوشش تخصص و
جانشینی مستقل **FAIL** است. نویسنده PR #1 هم همین حساب است و نمی‌تواند خود را
approve کند. یک reviewer عمومی دیگر لزوماً شرط Code Owner را تأمین نمی‌کند.

CODEOWNERS از base خوانده می‌شود؛ main اولیه اصلاً این فایل را ندارد، پس اضافه‌شدن
آن در head به معنی اعمال requirement برای خود PR #1 نیست. فهرست واقعی سایر
collaboratorها و اختیار آنها هم از metadata موجود قابل اثبات نیست؛ «نام‌ها معلوم
نیست» را با «هیچ collaborator دیگری وجود ندارد» اشتباه نگیرید.

[ماتریس مسئولیت پیشنهادی](docs/readiness/R2_REVIEW_OWNERSHIP.md) هر ۲۰ حوزه واقعی را
به نقش فنی، مالی، امنیت و QA متصل می‌کند؛ همه هویت‌های ناشناخته UNASSIGNED هستند.
هیچ placeholder به CODEOWNERS اضافه نشده و هیچ invitation ارسال نشده است. چند
username در یک pattern الزام تأیید همه آنها را ایجاد نمی‌کند؛ review دو حوزه حساس
باید با شواهد مستقل و کنترل قابل آزمون تعریف شود.

## 5. برنامه تست و نخستین تغییر عملیاتی

[طرح تدریجی T0 تا T6](docs/readiness/R2_TEST_INFRASTRUCTURE_PLAN.md) شامل محیط
ساختگی ایزوله، provision قابل تکرار، bootstrap/role، MIS موجود، مالی/گزارش، HTTP/UI
و سپس gate پایدار CI است. همه اجرای آینده این فازها **NOT_RUN** است؛ تصمیم نسخه‌ها
و مسئولان هم هنوز لازم است.

guard فعلی MIS قبل از نصب فیلتر HTTP/mail، wp-load را اجرا می‌کند. بنابراین قبل
از هر fixture باید مقصد DB مستقل، suffix، sentinel، deny-network و Cron/mail در
سطح محیط بررسی شوند. صرف YES یا پسوند `_test` حفاظت کافی برای سایت عملیاتی نیست.
پوشش workspace MIS نیز ورودی classic shortcode یا reports جدید 2.0.235 را اثبات نمی‌کند.

پیش از **اولین تغییر عملیاتی** حداقل این شواهد لازم‌اند:

1. نصب تازه/upgrade لازم روی test-site ساختگی؛ عدم fatal و بررسی schema/index،
   role/cap و idempotence، با SHA و نسخه runtime واقعی ثبت‌شده.
2. auth/nonce/cap/scope مثبت و منفی برای ماژول تغییرکرده، کاربر تیم دیگر و revoked
   access؛ direct handler و export نیز تست شوند.
3. برای MIS، ده فایل runner موجود با SQL failure، rollback و race دو اتصال؛ ورودی
   classic و حفظ history جدا بررسی شوند.
4. برای فاکتور/مالی، token و legacy code، مبلغ/واحد/مرحله، duplicate callback و
   manual همزمان، عدم double-credit، approval/reject و ledger consistency.
5. برای گزارش، scope/delegation نسخه 2.0.235، برابری export و UI، totals ساختگی،
   بازه تهران/جلالی، as-of و pagination با داده قابل محاسبه.
6. برای UI/API/upload/OTP متأثر، dispatcher واقعی، cookie/nonce، موبایل/RTL،
   خطای provider ساختگی، عدم دسترسی anonymous به فایل حساس و محدودیت نرخ همزمان.

Merge ورود source ثابت و بدون activation با release عملیاتی متفاوت است. runtime
suite اجرا‌نشده را PASS نکنید؛ الزام آزمون‌های بالا پیش از تغییر/انتشار عملیاتی
باقی می‌ماند، حتی اگر مالک بعداً Merge صرف ورود source را مجاز کند.

## 6. برنامه تطبیق Production

[طرح فقط خواندنی](docs/readiness/R2_PRODUCTION_PARITY_PLAN.md) و
[manifest 116 فایل](docs/audit/r2/RUNTIME_SHA256SUMS-2.0.235.txt) آماده‌اند. مبنا از
Git blobهای immutable تولید شده، نه خروجی اجرای افزونه. دامنه runtime از manifest
398 فایل ZIP و اختلاف‌های مستندات R1 جداست.

اپراتور آینده باید مسیر واقعی و provenance snapshot فقط خواندنی را با مالک تعیین،
symlink/فایل ناخوانا/تغییر حین scan را متوقف و inventory فایل اضافه را نیز بررسی کند.
هیچ PHP، WP-CLI، DB query، repair یا اتصال Production در R2 انجام نشده است.
نمونه دستور offline با copy محلی source، در حالت برابر و سه حالت missing/changed/
extra بررسی شد؛ این آزمون، شاهد Production یا اثبات read-only mount نیست.

## 7. امنیت فایل، وابستگی، دسترسی، سند و Git

[بررسی محدود امنیت R2](docs/readiness/R2_SECURITY_REVIEW.md) کنترل مثبت را از
assurance اجرا‌نشده جدا می‌کند. receipt URL در uploads و transient rate counter
ریسک‌های ثبت‌شده R1 هستند؛ exploit عملیاتی آنها در R2 تست یا اصلاح نشده است.
version host، SBOM، vulnerability audit، Secrets settings و collaborator inventory
هنوز NOT_RUN یا BLOCKED هستند. هیچ داده یا log عملیاتی جدید وارد repo نمی‌شود.

R1 به‌صورت کلی بازنویسی نشده است. کاستی اجرایی دریافت سورس پیش از Merge و توضیح
ownership/base در Handbook، و ابهام شرط recipe در LOCAL_SETUP اصلاح محدود شده‌اند.
نمونه main در Handbook از ابتدا پس از Merge بود و «دستور غلط بی‌قید» گزارش نشده است.
Source واقعیت فعلی است؛ متن قدیمی release، log و نمونه APPLY اختیار اجرایی نیست.

## 8. موانع Merge و اقدامات پیش از دعوت توسعه‌دهندگان

| اولویت / gate | وضعیت | اقدام لازم توسط مالک/مسئول آینده |
|---|---|---|
| M1 — review مستقل PR #1 | FAIL | reviewer واقعی با اختیار معتبر و مستقل از نویسنده تعیین؛ diff/source/governance بررسی و approval ثبت شود |
| M2 — پوشش PRهای مالک و جانشین | FAIL | هویت alternate Code Owner و specialistها تعیین؛ مدل base و ورود اولیه با تصمیم قابل ممیزی حل شود |
| M3 — قواعد واقعی main | BLOCKED | اپراتور مجاز settings/plan/effective rules را بخواند، پیشنهاد مصوب را در مأموریت جدا اعمال و verify کند |
| D1 — استفاده تیمی از مستندات اصلاح‌شده | FAIL | پیش از onboarding عمومی، اصلاحات R2 مستقل review و مسیر ورودشان با مالک هماهنگ شود؛ شرط دوری برای Merge شماره ۱ ایجاد نمی‌شود |
| O1 — source parity Production | NOT_RUN | برنامه read-only با snapshot/اپراتور مصوب اجرا شود، پیش از ادعای برابری یا release |
| O2 — نخستین تغییر عملیاتی | NOT_RUN | T1/T2 و regression ماژول متأثر با evidence واقعی؛ صرف lint کافی نیست |

پیش از دعوت عمومی توسعه‌دهندگان: owner matrix و حساب‌های واقعی/جانشین تصویب شوند؛
مسیر حفاظت شاخه و review مستقل حل و verify شود؛ نسخه و شاخه canonical دریافت
source روشن باشد؛ سیاست 2FA/least privilege/credential شخصی و offboarding، کانال
گزارش امنیت و محیط synthetic-only مشخص شوند. ایجاد دسترسی یک reviewer محدود برای
حل gate اولیه نیز عملیات مالک در مأموریت جداست؛ در R2 دعوت انجام نمی‌شود.

اپراتور GitHub آینده باید دستی قواعد main، check source، approval/stale/conversation،
force-push/deletion/bypass، Actions permission و مدل CODEOWNERS را بررسی/اعمال کند.
برای موارد فاقد پشتیبانی plan یا دسترسی، readiness بدون تصمیم صریح مالک بسته نشود.

## 9. تحویل R2 و حفظ PR #1

| فایل | وضعیت کار R2 | هدف |
|---|---|---|
| همین گزارش | PASS | پاسخ به همه gateها با وضعیت و شواهد |
| [Handbook](docs/DEVELOPER_HANDBOOK.md) و [LOCAL_SETUP](docs/LOCAL_SETUP.md) | PASS | فقط اصلاح محدود اثبات‌شده؛ بدون بازنویسی سیاست R1 |
| [Ownership](docs/readiness/R2_REVIEW_OWNERSHIP.md) | PASS | نقش‌های پیشنهادی، ۲۰ ماژول و reviewer مستقل بدون حدس هویت |
| [CI coverage](docs/readiness/R2_CI_COVERAGE.md) | PASS | رفتار واقعی و نقاط کور سه Check |
| [Test plan](docs/readiness/R2_TEST_INFRASTRUCTURE_PLAN.md) | PASS | فازها، مسئول پیشنهادی، dependency و معیار خروج |
| [Parity plan](docs/readiness/R2_PRODUCTION_PARITY_PLAN.md) | PASS | snapshot فقط خواندنی، SHA256 و طبقه‌بندی اختلاف |
| [Manual GitHub actions](docs/readiness/R2_MANUAL_GITHUB_ACTIONS.md) | PASS | شاهد محدودیت و پیشنهاد دستور؛ بدون اجرای mutation |
| [Security review](docs/readiness/R2_SECURITY_REVIEW.md) | PASS | بررسی محدود و شکاف‌های assurance |
| [GitHub evidence](docs/audit/r2/GITHUB_REVIEW_EVIDENCE.json) | PASS | snapshot redacted metadata، reviews، CI و خطاهای GET |
| [Local validation](docs/audit/r2/LOCAL_VALIDATION.json) | PASS | نتایج واقعی کنترل محلی؛ تست Production ندارد |
| [Runtime manifest](docs/audit/r2/RUNTIME_SHA256SUMS-2.0.235.txt) | PASS | hash از Git ثابت و همه 116 فایل زمان اجرا |

PR مستقل R2 با base شاخه R1 فقط برای diff محدود review باز می‌شود؛ **نباید بدون
هماهنگی در شاخه PR #1 Merge شود**. پس از Merge مجاز PR #1، اگر ancestry حفظ شده
باشد می‌توان base را به main تغییر داد و diff/CI را دوباره بررسی کرد. اگر squash یا
rebase ancestry را عوض کرد، branch جدید از main و انتقال فقط commitهای R2 با review
لازم است؛ Force Push شاخه مشترک یا حذف history راه‌حل پیش‌فرض نیست.

workflow فعلی PR-trigger برای base غیرmain ندارد؛ push شاخه `docs/**` همچنان CI را
فعال می‌کند. نبود PR-run در ابزار فیلترشده connector نتیجه push-run را ثابت نمی‌کند.
نتیجه validation محلی R2 از نتیجه CI مبنای PR #1 جدا ثبت می‌شود. در همین R2،
parser همه 77 PHP و 32 JS، بررسی 2,471 لینک نسبی/تیترهای قرارداد، hygiene، اسکن
Secret و نمونه offline SHA256 موفق بودند؛ هیچ runtime suite وردپرس اجرا نشد.

## 10. پاسخ نهایی آمادگی

- **PR #1 آماده Merge نیست — BLOCKED:** review مستقل، جانشینی Code Owner، اثبات
  قواعد main باقی‌اند؛ اصلاحات onboarding پیش از استفاده تیمی جداگانه وارد شوند. `mergeable=true`
  و CI سبز اجازه نادیده‌گرفتن این gateها نیستند.
- **پیش از دعوت توسعه‌دهندگان:** هویت/مسئولیت/جانشین و دسترسی حداقلی، حفاظت و
  مسیر review، source canonical و دستور دریافت، سیاست امنیت و محیط تست تعیین شوند.
- **تنظیم دستی GitHub:** فقط در مأموریت مستقل مجاز، پس از تشخیص واقعی plan/access
  و با خواندن و verify دوباره؛ هیچ تغییری در R2 انجام نشده است.
- **پیش از تغییر عملیاتی:** test-site ایزوله، permission/scope و regression ماژول
  مربوط، به‌ویژه MIS/مالی/گزارش لازم‌اند؛ تطبیق Production نیز هنوز NOT_RUN است.

در این مأموریت هیچ Merge، Deploy، اتصال/تغییر Production، دعوت کارمند یا تغییر
امنیتی GitHub انجام نشده است. همه اقدامات حساس فقط به‌صورت طرح و دستور آینده‌اند.
