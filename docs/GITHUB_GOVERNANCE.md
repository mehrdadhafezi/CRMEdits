# GitHub governance — proposed configuration and actual status

مشاهده ۲۰۲۶-۱۱-۱۴ به وقت تهران: مخزن `mehrdadhafezi/CRMEdits` خصوصی است.
GitHub برای `main` و `experimental` مقدار `protected: false` و برای Rulesets
پاسخ `403` با محدودیت پلن گزارش داد. وضعیت **NOT_ENFORCED** است. CI و
CODEOWNERS به‌تنهایی Push مستقیم یا Merge را مسدود نمی‌کنند. پیگیری تغییر پلن و
محافظت با مالک مخزن است و در انتظار تصمیم اوست.

## Access

مالک در [Collaborators settings](https://github.com/mehrdadhafezi/CRMEdits/settings/access)
از Add people حساب GitHub واقعی هر کارمند را دعوت می‌کند؛ افراد دعوت را می‌پذیرند
و با credential شخصی clone/push می‌کنند. در repository متعلق به حساب شخصی، سطح
collaborator و گزینه نقش‌ها محدودتر از Organization است؛ اگر UI نقش قابل انتخاب
نشان نداد، نقش `Write` خیالی نسازید. برای تیم‌ها و نقش‌های granular، انتقال به
Organization یک تصمیم جداگانه مالک است و در این مأموریت انجام نمی‌شود.

حساب مشترک، owner credential یا token اصلی توزیع نشود. دسترسی هر نفر هنگام خروج
از تیم حذف و tokenهای مربوط revoke شود. GitHub access با WordPress Role متفاوت است.

## Main ruleset — concrete desired settings

تنظیمات زیر PROPOSED هستند. فقط با اجازهٔ صریح مالک و پس از احراز پشتیبانی پلن در
[Rulesets](https://github.com/mehrdadhafezi/CRMEdits/settings/rules) یا Branch protection
اعمال و دوباره خوانده شوند. Private repository در حساب شخصی ممکن است برای enforcement
به GitHub Pro نیاز داشته باشد. نتیجه خطای plan/permission را ثبت کنید؛ مخزن را
برای فعال‌شدن protection عمومی نکنید.

- Name: `main-reviewed-changes`؛ Target branches: `refs/heads/main`؛ Enforcement: Active.
- Require a pull request before merging؛ حداقل 1 approval مستقل.
- Dismiss stale approvals و Require review from Code Owners.
- Require approval of the most recent reviewable push، اگر در plan/UI موجود است.
- Require conversation resolution.
- Require status checks و Require branches to be up to date.
- Contextهای دقیق پس از اولین اجرای موفق CI:
  `PHP and JavaScript syntax`، `Documentation and repository hygiene`، `Secret scan`
  و برای PR به `main`، `Stable PR branch boundary`.
- Block force pushes و Restrict deletions.
- Bypass list خالی؛ در Branch Protection کلاسیک، Do not allow bypassing settings
  / Include administrators را فعال کنید. هیچ bypass دائمی برای مالک حدس نزنید.
- **Restrict updates** را کورکورانه روشن نکنید؛ این گزینه می‌تواند merge مجاز را
  هم برای افراد بدون bypass ببندد. PR requirement با bypass خالی و سایر rules مسیر
  عادی تغییر main را به merge تحت کنترل محدود می‌کند.
- Auto-merge خاموش بماند؛ هیچ Deploy workflow یا Production secret اضافه نشود.

پس از تنظیم، در UI/API وضعیت active، pattern، approvals، required checks و bypass
را بخوانید. تلاش برای push ممنوع واقعی به main آزمایش مجاز این مأموریت نیست؛
با PR آزمایشی بدون اثر عملیاتی و inspect settings بررسی کنید. اگر plan پشتیبانی
نکرد، وضعیت `NOT_ENFORCED` است؛ توافق تیمی معادل محافظت فنی نیست.

## CODEOWNERS and review capacity

[CODEOWNERS](../.github/CODEOWNERS) مالک واقعی مخزن را پوشش می‌دهد؛ تخصص ماژول‌ها
**UNASSIGNED** است. پیگیری با مالک مخزن و در انتظار تعیین افراد واجد دسترسی است.
Code Owner برای مسیر مربوط به دسترسی نوشتن نیاز دارد. برای هر PR، مالک reviewer
واجد دسترسی و مستقل از نویسنده تعیین می‌کند. اگر نویسنده مالک باشد، reviewer
دیگری لازم است. تا تعیین reviewer و ثبت approval مستقل، Merge متوقف می‌ماند.

## Historical records

[FINAL_HANDOVER.md](audit/FINAL_HANDOVER.md) و PRهای R1/R2 شواهد دورهٔ bootstrap
هستند. درخواست‌ها، دستورهای نوشتن و مجوزهای ثبت‌شده در آن‌ها برای تسک تازه معتبر
نیستند. [main-ruleset.json](../.github/main-ruleset.json) یک طرح پیشنهادی است و وجود
آن نشانهٔ اعمال تنظیمات نیست. تغییر پلن، Ruleset یا دسترسی نیازمند تصمیم و مجوز
صریح مالک برای همان اقدام است.
