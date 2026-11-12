# Security policy — R1

مخزن باید Private بماند. همه افراد حساب GitHub شخصی، دسترسی حداقلی و ترجیحاً 2FA
داشته باشند؛ account یا token مشترک توزیع نشود. کارمند توسعه دسترسی کد دریافت
می‌کند؛ این مجوز، دسترسی WordPress، اطلاعات مشتری یا Production نیست.

Credential، WordPress config، `.env`، dump، backup، receipt، invoice URL/token، OTP،
گزارش خام مشتری و کلید خصوصی وارد Git، Issue، PR، screenshot یا prompt سرویس AI نشود.
از Secret Store محیط استفاده کنید؛ هیچ Secret واقعی در اسناد نمونه قرار ندهید.
نمونه‌های موجود ZIP در ممیزی به‌عنوان داده ساختگی بررسی شده‌اند؛ نمونه جدید باید
قابل اثبات ساختگی باشد و هرگز به SMS/درگاه واقعی ارسال نشود.

Backend باید احراز هویت، capability، nonce مرتبط و scope رکورد را مستقل بررسی کند.
شناسه ارسالی کاربر دلیل مالکیت نیست. public invoice نیازمند قرارداد token فعلی
است؛ callback درگاه قرارداد مستقل دارد. auth اختصاصی API را با session کارمند
جایگزین نکنید. توکن فقط در header/مسیر مجاز قرارداد و بدون لاگ قرار بگیرد.

SQL مقدارها prepare، identifierها allowlist، خروجی‌ها context-aware escape و فایل‌ها
بر اساس MIME/extension/اندازه و دسترسی مقصد کنترل شوند. عملیات مالی باید زیر lock
و invariant موجود، با idempotency و کنترل failure باقی بماند.

محیط توسعه کاملاً جدا و دارای اطلاعات ساختگی باشد. تست تخریبی MIS روی کپی داده
مشتری هم ممنوع است. فعال‌سازی Plugin، init، migration، repair و backfill می‌توانند
Role، صفحه، Schema یا داده را تغییر دهند؛ اجرای آنها نیازمند اختیار جداگانه است.
DROP، TRUNCATE، bulk DELETE، reset/clean تغییرات دیگران، تغییر production و ارسال
واقعی پیام/پرداخت خارج از تسک ممنوع‌اند.

تغییر مالی، authentication، scope، Role، migration، token، provider و CI/security
با review مستقل مالک و برنامه تست/rollback انجام شود. فایل CODEOWNERS بدون تنظیم
GitHub enforcement این الزام را خودکار اعمال نمی‌کند.

پیش از push:

```bash
gitleaks dir . --config .gitleaks.toml --redact --no-banner
gitleaks git . --config .gitleaks.toml --log-opts="--all" --redact --no-banner
python3 scripts/check.py hygiene
```

پیش‌نیاز Gitleaks 8.24.3 یا نسخه سازگار بررسی‌شده است. اسکنر Secret، اسکن کامل PII
یا اثبات نبود آسیب‌پذیری نیست. تغییر allowlist باید محدود، مستند و review شود.
در صورت یافتن Secret واقعی push متوقف، Credential از مسیر خصوصی revoke/rotate و
history با طرح مصوب اصلاح شود؛ حذف فایل در commit بعدی کافی نیست.

آسیب‌پذیری را خصوصی به مالک مخزن در کانال داخلی شناخته‌شده گزارش کنید؛ اگر GitHub
Private Vulnerability Reporting فعال است از آن استفاده کنید. مقدار حساس را کپی
نکنید؛ مسیر، سناریو، اثر و شواهد redacted بدهید. کانال اختصاصی امنیت هنوز تعیین
نشده است. severity و exploit اثبات‌نشده را از ریسک بالقوه جدا نگه دارید.

Dependency جدید باید نسخه/منشأ/مجوز مشخص و بررسی vulnerability داشته باشد. manifest
اجرایی Composer/npm فعلاً وجود ندارد؛ این وضعیت به معنی نبود ریسک وابستگی WordPress،
WooCommerce، PHP، فونت و providerها نیست.
