# CRMEdits — Sales Network CRM

مخزن مشترک توسعه افزونه WordPress «شبکه فروش». مبنای رسمی تحویلی مالک:
**2.0.235**، فایل `sales-network.php`. این مخزن Private است.

**شروع توسعه‌دهنده:** [راهنمای فارسی Codex و Claude](docs/DEVELOPER_HANDBOOK.md)
→ [قوانین Agent](AGENTS.md) → [قرارداد ماژول](docs/modules/README.md).

```bash
git clone https://github.com/mehrdadhafezi/CRMEdits.git
cd CRMEdits
git switch main
git pull --ff-only origin main
git switch -c feature/123-short-description
python3 scripts/check.py all
```

پیش‌نیاز این نمونه: دسترسی پذیرفته‌شده GitHub، وجود main مصوب، Git، Python 3.10+،
PHP CLI و Node.js. شناسه `123` را با Issue واقعی عوض کنید. تا پیش از Merge شدن PR
ورود اولیه سورس، روش دریافت branch آماده‌سازی در گزارش تحویل درج می‌شود.

کد افزونه در **ریشه مخزن** قرار دارد؛ مسیر نصب WordPress برابر
`wp-content/plugins/sales-network-v4/` است. مخزن، کل WordPress یا دیتابیس را شامل
نمی‌شود. برای سایت محلی فقط با داده ساختگی از [راهنمای محیط](docs/LOCAL_SETUP.md)
استفاده کنید. دریافت یا Merge کد به‌خودی‌خود نسخه نصب‌شده سایت را به‌روز نمی‌کند؛
انتشار یک مرحله جداگانه با تأیید مالک است.

| مرجع | کاربرد |
|---|---|
| [معماری](docs/ARCHITECTURE.md) | مسیر اجرا و مرز ماژول‌ها |
| [Git workflow](docs/GIT_WORKFLOW.md) | branch، PR و review |
| [استاندارد توسعه](docs/DEVELOPMENT_STANDARDS.md) | PHP، JS، RTL و قراردادها |
| [استاندارد تست](docs/TESTING_STANDARDS.md) | تست موجود و محدودیت‌ها |
| [امنیت](docs/SECURITY_POLICY.md) | داده، Secret و گزارش خصوصی |
| [تنظیمات GitHub](docs/GITHUB_GOVERNANCE.md) | دسترسی و محافظت main |
| [ممیزی مبنا](docs/audit/SOURCE_BASELINE_AUDIT.md) | منشأ، نسخه و hash |
| [گزارش تحویل](docs/audit/FINAL_HANDOVER.md) | وضعیت واقعی و کار باقی‌مانده |

هیچ انتشار خودکار یا اتصال Production در CI وجود ندارد. مجوز متن‌باز جدیدی به کد
اضافه نشده است؛ مالک باید سیاست مجوز شرکت را جداگانه تعیین کند. مجوز فونت موجود
در `assets/fonts/patra-vazirmatn-OFL.txt` حفظ شده است.
