# Local development setup

ریشه همین مخزن پوشه افزونه است و فایل اصلی `sales-network.php` در ریشه قرار دارد.
Header حداقل WordPress 6.0 و PHP 8.0 را اعلام می‌کند. نسخه runtime Production و
نسخه دقیق WooCommerce، MySQL/MariaDB و افزونه‌های همراه هنوز UNVERIFIED است.

1. Git و دسترسی شخصی GitHub، Python 3.10+، PHP CLI و Node.js برای بررسی‌های محلی
   نصب کنید. برای سایت آزمایشی WordPress، وب‌سرور و MySQL/MariaDB InnoDB مستقل
   لازم است؛ PHP ZIP برای MIS XLSX و extensionهای لازم WordPress فراهم شوند.
2. با حساب خود clone کنید. مخزن را به پوشه `sales-network-v4` در plugins یک
   WordPress **یک‌بارمصرف با داده ساختگی** clone کنید یا از package تأییدشده استفاده کنید.
   هیچ `wp-config.php`، uploads، credential یا دیتابیس داخل این مخزن قرار نگیرد.
3. پیش از activation، HTTP خروجی، mail، SMS، درگاه‌ها، Cron و API واقعی را در سطح
   محیط ایزوله کنید. اتصال به مشتری یا provider واقعی ممنوع است. activation و init
   افزونه می‌توانند جدول، Role، صفحه و داده ایجاد/اصلاح کنند.
4. روی این محیط یک‌بارمصرف افزونه را طبق دسترسی تست فعال کنید و فقط کاربر/لید/
   فاکتور ساختگی بسازید. اگر سناریو به WooCommerce نیاز دارد نسخه سازگار مصوب
   پروژه را نصب کنید؛ این نسخه هنوز از Production استخراج نشده است.
5. پنل و shortcode مرتبط را باز کنید، سپس سناریوهای قرارداد ماژول را اجرا کنید.
   برای fixtureهای MIS فقط DB با پسوند `_test` و guard اختصاصی مجاز است.

این مخزن Docker Compose، wp-env یا installer کامل WordPress ندارد؛ `npm install`
یا `composer install` مرحله راه‌اندازی این نسخه نیست. نصب خودکار سایت و اطلاعات
Production از این سند استنباط نشود. برای تست قابل تکرار بعدی، provisioner محلی با
نسخه‌های مصوب به‌عنوان تسک مستقل اضافه شود (PROPOSED).

دریافت سورس، فقط **پس از Merge ورود اولیه به main**؛ وجود main با BOOTSTRAP.md کافی نیست:

```bash
git clone https://github.com/mehrdadhafezi/CRMEdits.git
cd CRMEdits
git switch main
git pull --ff-only origin main
python3 scripts/check.py all
```

پیش از Merge ورود اولیه، branch `docs/crm-engineering-standard-r1` را به‌طور صریح
checkout کنید؛ دستور clone همین branch در [Handbook](DEVELOPER_HANDBOOK.md) آمده است.
این branch نسخه رسمی main تا قبل از approval/merge نیست و امکان review آن مجوز
شروع تغییر عملیاتی پیش از رفع موانع [R2](../R2_READINESS_REPORT.md) نیست.

بسته محلی از checkout تمیز و commitشده:

```bash
python3 scripts/package.py
```

خروجی در `dist/`، شامل `sales-network-v4/sales-network.php` و assets/includes/templates
است؛ اسناد مهندسی، CI، تست‌ها و `.git` وارد ZIP نصب نمی‌شوند. SHA256 و commit در
خروجی ثبت می‌شوند. ساخت package مجوز نصب یا انتشار نیست.
