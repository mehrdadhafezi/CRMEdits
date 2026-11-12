# R2 — Bounded security and workflow review

این بررسی اسناد، policy، ابزارها و source موجود را می‌خواند؛ تست نفوذ، اصلاح runtime
یا تأیید سلامت Production نیست. R1 [Security Audit](../audit/SECURITY_AUDIT.md)
حفظ شده و یافته‌های قدیمی با نتیجه تست جدید اشتباه گرفته نمی‌شوند.

| موضوع | وضعیت | شاهد، حد نتیجه و اقدام لازم |
|---|---|---|
| یکپارچگی source زمان اجرا | PASS | manifest 116 فایل از SHA ثابت؛ PHP/JS/CSS/templates در R2 تغییر نمی‌کنند |
| فایل عملیاتی و Secret شناخته‌شده | PASS | check.py hygiene و Gitleaks در validation R2؛ کنترل محدود است و اثبات نبود PII نیست |
| استثنای scanner | PASS | [.gitleaks.toml](../../.gitleaks.toml) exact path + exact line برای public localStorage؛ rule عمومی خاموش نشده |
| قواعد نگهداری داده | PASS | .gitignore برای config/dump/key و سیاست synthetic-only؛ ورود داده با نام دلخواه همچنان نیازمند review است |
| binary/sample و log | NOT_RUN | بازبینی کامل محتوای binary جدیدی انجام نشده؛ R1 داده نمونه را بررسی کرده است؛ scanner متن جایگزین بررسی PII نیست |
| dependency vulnerability/SBOM | NOT_RUN | manifest Composer/npm و lockfile موجود نیست؛ نسخه‌های واقعی WP/WooCommerce/provider و runtime host نامعلوم‌اند |
| تأمین ابزار CI | PASS | checkout به SHA ثابت، Gitleaks نسخه ثابت و checksum؛ این PASS محدود به کنترل مشاهده‌شده integrity است |
| provenance مستقل و pin همه runtimeها | NOT_RUN | checksum از همان release است؛ Node runner و PHP APT pin دقیق پروژه ندارند |
| دسترسی و Secret تنظیم‌شده GitHub | BLOCKED | Private/admin metadata مثبت است؛ settings واقعی، collaborator inventory و secrets/environment policy قابل خواندن کامل نبود |
| review مستقل و مالکیت | FAIL | صفر review برای PR #1 و فقط مالک در CODEOWNERS؛ برنامه جانشین لازم است |
| enforcement جریان Git | BLOCKED | قواعد واقعی main قابل خواندن نبود؛ policy نوشتاری معادل protection نیست |
| authorization و امنیت runtime | NOT_RUN | lint endpoint، SQL injection، XSS، CSRF، IDOR یا leak را اثبات نمی‌کند |
| Receipt hosting privacy | NOT_RUN | [receipt_upload_dir](../../includes/class-sn-helpers.php#L700) URL uploads می‌سازد؛ ACL host و دریافت anonymous آزموده نشده |
| محدودیت نرخ همزمان | NOT_RUN | [rate_limit](../../includes/class-sn-helpers.php#L715) get/increment/set transient است؛ atomicity تضمین نمی‌کند؛ load test آینده |
| حفظ محدوده R2 | PASS | بدون تغییر کد عملیاتی، CODEOWNERS، workflow، تنظیم امنیتی، دعوت یا Production |

## ریسک‌های اصلی پیش از کار تیمی

1. صلاحیت و ظرفیت reviewer مستقل هنوز تعیین نشده است. UI `mergeable` و CI سبز
   جایگزین آن نیستند؛ PR مالک به alternate Code Owner نیاز دارد.
2. check.py، workflow و allowlist نیز قابل تغییر در PR هستند. reviewer باید تغییر
   خود gateها را بررسی کند؛ «سبزشدن پس از حذف تست» نشانه آمادگی نیست. هیچ تست spoof
   یا تغییر check در R2 انجام نشده است.
3. `git --all` فقط refs حاضر در checkout را اسکن می‌کند؛ ignored files، storage
   خارج repo و credentialهای provider با آن پوشش داده نمی‌شوند. private بودن مخزن
   اجازه نگهداری PII، log خام یا رسید واقعی را نمی‌دهد.
4. فایل‌های تاریخی حاوی «current task»، نسخه قبلی و ابزارهای APPLY هستند؛ آنها
   شاهد تاریخی‌اند. دستور معتبر فعلی کاربر، از جمله منع تغییر امنیت در R2، مقدم است.
5. activation و init می‌توانند schema، نقش و داده را تغییر دهند. حتی تست پرداخت
   با پاسخ HTTP ساختگی، اگر wp-load ناایمن باشد ممکن است پیش از نصب stub اثر داشته
   باشد. T0/T1 در [برنامه تست](R2_TEST_INFRASTRUCTURE_PLAN.md) پیش‌نیاز اجرای fixture است.

هیچ آسیب‌پذیری exploitable جدیدی با صرف تطبیق متن اعلام نمی‌شود. ریسک receipt و
rate-limit قبلاً در R1 ثبت شده‌اند و نیاز به سناریوی ایزوله و تسک اصلاح مستقل دارند.
تغییر مالی، توکن، مجوز، migration و تنظیمات GitHub در این مرحله صرفاً پیشنهاد می‌شود.
