# GitHub governance — proposed configuration and actual status

مشاهده 2026-11-12: `mehrdadhafezi/CRMEdits`، Private، owner حساب شخصی
`mehrdadhafezi` و دسترسی admin/push برقرار. در شروع هیچ branch/commit وجود نداشت.
Visibility حفظ می‌شود. هیچ account کارمند حدس زده نشده است.

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

تنظیمات زیر PROPOSED هستند؛ بعد از ایجاد main و اجازه صریح مالک باید در
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
  `PHP and JavaScript syntax`، `Documentation and repository hygiene`، `Secret scan`.
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
UNASSIGNED است. پس از شناسایی کارمندان، افراد واجد دسترسی و مسئولیت تأییدشده اضافه
شوند. CODEOWNERS به تنهایی enforce نمی‌شود. برای PR نویسنده @mehrdadhafezi لازم
است reviewer دیگری با اختیار کافی وجود داشته باشد؛ خودتأییدی ممکن نیست و protection
نباید برای دورزدن این موضوع ضعیف شود.

## Empty repository bootstrap

محتوای پیشنهادی commit اولیه main فقط `BOOTSTRAP.md` است: معرفی مخزن خصوصی،
وضعیت ورود اولیه و ممنوعیت direct pushهای بعدی. سورس کامل و R1 روی
`docs/crm-engineering-standard-r1` در PR تحویل می‌شود. commit اولیه main فقط با
تأیید صریح مالک مطابق سند مأموریت مجاز است. Merge این PR و نصب سایت انجام نمی‌شود.

## Actual configuration

وضعیت واقعی bootstrap، push، PR، CI و protection در
[FINAL_HANDOVER.md](audit/FINAL_HANDOVER.md) ثبت می‌شود. این سند تنظیمات پیشنهادی
را با تنظیمات اعمال‌شده یکی نمی‌داند. Connector فعلی عملیات collaborator/ruleset
را عرضه نمی‌کند و CLI GitHub احراز معتبر API را در این محیط گزارش نکرده است؛ مسیر
دقیق دستی بالا برای مالک آماده شده است. credential را در chat نفرستید.
