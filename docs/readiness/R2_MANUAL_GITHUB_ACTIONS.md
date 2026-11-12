# R2 — GitHub settings verification and proposed manual actions

**R2 هیچ مجوزی برای تغییر تنظیمات، دعوت، Merge یا خرید پلن ندارد.** مجوزهای مرحله
R1 نباید منع صریح مأموریت فعلی را کنار بزنند. دستورهای تغییردهنده زیر فقط پیشنهاد
برای اپراتور مجاز در مأموریت جداگانه‌اند؛ در R2 اجرا نشده‌اند.

## شواهد دسترسی فعلی

| کنترل | وضعیت | شاهد |
|---|---|---|
| repo Private و اختیار metadata حساب متصل | PASS | connector: Private و admin/push=true |
| فهرست rulesetها، از جمله inherited | BLOCKED | GET rulesets?includes_parents=true → `Forbidden` |
| قواعد مؤثر main | BLOCKED | GET rules/branches/main → `Forbidden` |
| Branch Protection کلاسیک main | BLOCKED | GET branches/main/protection → `Forbidden` |
| مشاهده از مرورگر | BLOCKED | تلاش تازه R2: `Browser Use could not read requirements` |
| پلن و entitlement حساب شخصی | BLOCKED | پاسخ تنظیمات/plan دریافت نشده؛ نوع حساب شخصی به‌تنهایی پلن را مشخص نمی‌کند |
| inventory اعضا/دعوت‌ها و مجوز هر توسعه‌دهنده | BLOCKED | این اطلاعات از metadata repo و CODEOWNERS استخراج نمی‌شود |

در وضعیت محیط R2، policy اعمال‌شده است و `api.github.com` در allowlist نیست؛
CLI در لایه دسترسی `Forbidden` می‌دهد. این پاسخ **شاهد خطای پلن GitHub یا token
نامعتبر نیست**. connector جداگانه می‌تواند metadata/PR/CI را بخواند، اما ابزار
Rulesets/Protection عرضه نمی‌کند. [شاهد JSON](../audit/r2/GITHUB_REVIEW_EVIDENCE.json)
شامل endpointها، exit code و پاسخ redacted است. هیچ پاسخ معتبر `200`/`404` یا
خطای اختصاصی entitlement GitHub برای تنظیمات نداریم؛ نبود محافظت را هم قطعی اعلام نکنید.

## فقط خواندن توسط اپراتور با دسترسی مجاز

پیش‌نیاز دستورهای زیر: GitHub CLI با احراز معتبر شخصی و دسترسی API به همین مخزن.
`--paginate` برای فهرست به کار می‌رود؛ خروجی تنظیمات با دسترسی محدود نگهداری شود.

```bash
gh api --method GET 'repos/mehrdadhafezi/CRMEdits/rulesets?includes_parents=true' --paginate
gh api --method GET repos/mehrdadhafezi/CRMEdits/rules/branches/main --paginate
gh api --method GET repos/mehrdadhafezi/CRMEdits/branches/main/protection
```

برای هر ruleset، ID **برگشتی همان پاسخ** را انتخاب و detail آن را بخوانید؛ ID حدسی
نسازید. فعال (`active`) بودن با `evaluate`/`disabled` یکسان نیست. قوانین target،
inherited rules، bypassها، approval، checkها و منبع آنها و protection کلاسیک همزمان
بررسی شوند. یک `404` منفرد هم بدون تشخیص visibility/مجوز دلیل قطعی نبود تنظیمات نیست.

در UI: Repository Settings → Rules → Rulesets و Settings → Branches. plan/banner
حساب را بدون تغییر visibility یا خرید بررسی کنید؛ متن دقیق محدودیت، نوع حساب،
زمان، مجوز مشاهده و نتیجه enforcement ثبت شوند. اگر Private به پلن دیگری نیاز
داشت، تصمیم مالی/سازمانی مستقل مالک لازم است؛ Public کردن راه‌حل این مأموریت نیست.

## تنظیم پیشنهادی پس از تصمیم و تأیید مالک

پیش از enforce کردن Code Owner، بن‌بست PR نویسنده مالک را با
[ماتریس review](R2_REVIEW_OWNERSHIP.md) حل کنید. واردکردن JSON R1 به‌تنهایی این مشکل
را حل نمی‌کند. نام افراد و جانشین باید واقعی، تأییدشده و واجد دسترسی کافی باشند.

| تنظیم مطلوب | اقدام دستی آینده | شاهد خروج لازم |
|---|---|---|
| main با PR اجباری، ۱ approval مستقل و stale dismissal | Rulesets یا protection پشتیبانی‌شده | detail فعال و policy بررسی نویسنده/آخرین pusher |
| review مالک و جانشین مستقل | CODEOWNERS مصوب از مسیر PR و required owner review | وجود فایل در base، دسترسی مالک‌ها، امکان reviewer غیرنویسنده |
| سه context موجود CI | Required status checks با up-to-date و منبع GitHub Actions در صورت پشتیبانی | نام و App ID واقعی از Check Run؛ هیچ integration ID فرضی |
| منع حذف و Force Push | Restrict deletions / Block force pushes | تنظیم active برای refs/heads/main |
| بدون bypass دائمی | bypass list خالی یا معادل include admins در روش کلاسیک | detail واقعی؛ استثنای موقت فقط با مأموریت و ممیزی جدا |
| حل گفتگوها | Require conversation resolution | تنظیم و PRهای نمونه بدون mutation به main |
| Actions کم‌اختیار | read-only token، عدم Production secrets/deploy، review فایل workflow | Settings واقعی و YAML همزمان؛ secret را نمایش ندهید |
| دسترسی افراد | پس از تأیید هویت و نقش، دعوت توسط مالک در مأموریت جدا | least privilege، 2FA، پذیرش دعوت، revoke هنگام خروج |

[main-ruleset.json موجود](../../.github/main-ruleset.json) یک پیشنهاد R1 است و در R2
تغییر نکرده است. اگر پس از حل ownership، اپراتور مجاز آن را مناسب تشخیص داد و
ruleset هم‌نام موجود نبود، دستور **تغییردهنده و اجرا‌نشده** پیشنهادی:

```bash
gh api --method POST repos/mehrdadhafezi/CRMEdits/rulesets --input .github/main-ruleset.json
```

اگر هم‌نام وجود داشت، POST تکراری نزنید؛ ابتدا تفاوت و ID واقعی بررسی شود و تغییر
همان rule در طرح مجزا review گردد. پس از هر تغییر مصوب، detail و effective rules
دوباره خوانده شوند. روشن‌کردن Restrict updates بدون بررسی می‌تواند merge مجاز را
نیز ببندد؛ bypass اضافه نکنید تا صرفاً UI از خطا خارج شود.

CODEOWNERS فقط یک لایه است؛ چند نام در یک pattern الزام تأیید همگان نیست. همچنین
سه Check فعلی business test نیستند. اگر دسترسی reviewer یا پشتیبانی فنی enforcement
فراهم نشد، وضعیت readiness بسته نشود؛ تصمیم جایگزین باید صریح و قابل ممیزی باشد.
