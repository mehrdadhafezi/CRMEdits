# GitHub governance — actual rulesets (verified 2026-11-15)

مخزن `mehrdadhafezi/CRMEdits` در ۲۰۲۶-۱۱-۱۵ **Public** و شاخه‌های
`main` و `experimental` دارای Ruleset فعال هستند. گزارش خصوصی‌بودن مخزن،
خطای 403 و `NOT_ENFORCED` متعلق به ممیزی ۲۰۲۶-۱۱-۱۴ و منسوخ است.

## Active branch rulesets

| هدف | Ruleset | نام Checkهای الزامی |
|---|---|---|
| `main` (default branch) | [Main Rulsets](https://github.com/mehrdadhafezi/CRMEdits/rules/27308483) | `PHP and JavaScript syntax`، `Documentation and repository hygiene`، `Secret scan`، `Stable PR branch boundary` |
| `experimental` | [Experimental Rulsets](https://github.com/mehrdadhafezi/CRMEdits/rules/27309275) | `PHP and JavaScript syntax`، `Documentation and repository hygiene`، `Secret scan` |

- Enforcement هر دو: `active`؛ Require PR: فعال؛ Required Approvals: **0**.
- Code Owner Review: غیرفعال؛ Require last push approval: غیرفعال.
- Dismiss stale approvals: روشن (با 0 approval، الزام تأیید انسانی ایجاد نمی‌کند).
- Require conversation resolution: روشن؛ strict required checks (up-to-date): روشن.
- جلوگیری از حذف و Force Push: روشن؛ `Restrict updates`: خاموش؛ Bypass List: خالی.
- Allowed merge methods: merge، squash و rebase. برای sync `main → experimental`
  طبق [Git workflow](GIT_WORKFLOW.md) فقط merge commit مجاز است.
- Check مرز شاخه آزمایشی فقط روی PR مقصد `main` اجرا می‌شود؛
  به همین دلیل فقط Ruleset `main` آن را لازم می‌داند.

این موارد از API خوانده شده‌اند. **آزمون رفتار Merge در PR واقعی هنوز باید انجام شود**.
وضعیت `protected: true` ناشی از Ruleset، الزاماً به معنی فعال‌بودن classic
Branch Protection نیست. نام Checkها و Head/Base هر PR را قبل از Merge دوباره بررسی کنید.

## Process: PR + CI, zero mandatory human approvals

توسعه‌دهنده تسک را روی شاخه مخصوص انجام می‌دهد؛ سپس تست‌های مرتبط و اسکن Secret
را اجرا و Draft PR باز می‌کند. Codex می‌تواند Diff را بررسی کند و یافته‌ها را
در PR گزارش دهد؛ **بازبینی Codex هنوز به‌عنوان Required Check نصب/تأیید نشده**.
مبنای enforce شده فعلی GitHub Actions است، نه ادعای خودکار Codex.
پس از Checkهای موفق مربوط به آخرین نسخه، شواهد تست‌های ضروری، رفع گفتگوهای باز
و کنترل نهایی Diff، فرد دارای دسترسی می‌تواند بدون Approval انسانی Merge کند.
برای تست ضروری فاقد محیط (WordPress/DB) وضعیت `BLOCKED` باقی می‌ماند و CI سبز
آن را رفع نمی‌کند. مجوز Merge کد، مجوز نصب Production یا اجرای Migration نیست.

## Access and CODEOWNERS

افراد از [Collaborators settings](https://github.com/mehrdadhafezi/CRMEdits/settings/access)
با حساب شخصی GitHub دعوت می‌شوند. `CODEOWNERS` فعلاً مالک مخزن را معرفی می‌کند،
اما الزام Code Owner Review در Rulesetها **خاموش** است؛ بنابراین
**معرفی Reviewer یا Code Owner دوم شرط Merge نیست**.
محدودیت دسترسی و مجوزهای عملیات حساس مستقل از تعداد Approval هستند.
حساب مشترک، token یا credential اصلی توزیع نشود.

## Deployment and testing limitations

CI فعلی Syntax، Documentation/Hygiene، Secret Scan و مرز PR پایدار را بررسی
می‌کند؛ تست کامل WordPress/WooCommerce/DB، مالی، MIS و UI را اجرا نمی‌کند.
تا محیط disposable با داده ساختگی، نسخه‌های تأییدشده و خروجی شبکه مهارشده
آماده نشده، تست‌های runtime موردنیاز `BLOCKED/NOT_RUN` هستند. آزمون ساخت
شبکه داخلی Docker روی میزبان مشترک به خطای زنجیره iptables برخورد کرده است؛
پس نصب CRM در آن محیط هنوز `NO-GO` است. هیچ‌یک از این اسناد مجوز تغییر Firewall،
Production یا اطلاعات مشتریان نیست.

## Historical artifacts

[FINAL_HANDOVER.md](audit/FINAL_HANDOVER.md)، PRهای قدیمی و
[main-ruleset.json](../.github/main-ruleset.json) شواهد/طرح‌های تاریخی هستند؛
فایل JSON تاریخی **پیکربندی جاری Ruleset نیست** و نباید بدون تطبیق API و مجوز
به‌صورت خودکار اعمال شود.
