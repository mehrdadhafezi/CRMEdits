# Development standards — R1

مبنا WordPress/PHP موجود است، با JS معمولی و CSS؛ Composer/npm manifest یا autoloader
در ZIP وجود ندارد. استانداردهای زیر برای تغییرات جدیدند؛ تفاوت کد قدیمی مجوز بازنویسی نیست.

| حوزه | نمونه مناسب | نمونه نامناسب / علت |
|---|---|---|
| PHP/WP style | سبک مجاور، `sanitize_text_field( wp_unslash( ... ) )` | قالب‌بندی کل فایل هسته در تسک کوچک |
| Naming | کلاس `SN_...`، فایل `class-sn-...php` و نام موجود caller | rename عمومی بدون یافتن callerها |
| سازمان فایل | ماژول محدود در `includes/` و asset وابسته در `assets/` | کپی چندباره منطق مالی در JS و PHP |
| مسئولیت/SOLID | تابع کوچک با ورودی روشن و side effect مشخص | service عمومی که Role، پول و SMS را همزمان تغییر دهد |
| Hooks | حفظ نام و ترتیب hook موجود، پیشوند `sn_` برای جدید | ثبت hook کلی مثل `save` با برخورد نام |
| AJAX | auth + nonce مرتبط + cap + scope قبل از mutation | اعتماد به `user_id` یا مخفی بودن دکمه |
| REST | permission callback یا auth صریح handler موجود، schema ورودی و خطای امن | `__return_true` بدون auth در callback |
| خطا | `WP_Error`/JSON موجود، پیام فارسی امن و status سازگار | echo stack trace یا موفقیت پس از SQL failure |
| Logging | actor، action، نتیجه و شناسه غیرحساس | payload کامل، access_token، OTP یا شماره کارت |
| سازگاری | حفظ کد فاکتور قدیمی و ورودی‌های مستند | حذف پارامتر موجود چون نام آن قدیمی است |
| SQL | `$wpdb->prepare( '... WHERE id=%d', $id )` | چسباندن `$_GET` به SQL |
| شناسه SQL | identifier فقط از allowlist ثابت کد | جدول یا ORDER BY مستقیم از request |
| Schema | migration مجاز، افزایشی، idempotent و قابل dry-run | DDL در render یا DROP/TRUNCATE در عملیات عادی |
| خروجی UI | `esc_html`، `esc_attr`، `esc_url` مطابق context | `.innerHTML` با ورودی کاربر یا echo خام |
| ارقام و RTL | helper فعلی برای ارقام فارسی، حفظ صفر اول، CSS logical | تبدیل تلفن به integer یا چیدمان فقط LTR |
| Performance | pagination، batch محدود، index با شواهد query plan | export نامحدود یا N+1 در هر ردیف |
| Accessibility | label، focus واضح، دکمه واقعی و navigation کیبورد | click روی div بی‌نام یا اعلان فقط با رنگ |
| وابستگی | نسخه قفل‌شده، بررسی مجوز و lockfile در تسک مصوب | افزودن CDN/پکیج latest بدون بررسی |
| Versioning | نسخه Header و SN_VERSION با تصمیم release هماهنگ | bump نسخه عملیاتی در PR صرفاً مستندات |
| Deprecation | قرارداد قدیمی حفظ و بازه حذف پس از تأیید ثبت شود | حذف route/cap/schema بدون مسیر ارتقا |

حداقل‌های اعلام‌شده Header: WordPress 6.0 و PHP 8.0؛ انتخاب syntax جدید بالاتر از
8.0 نیازمند تصمیم سازگاری و تست ماتریس است. صرف lint روی PHP 8.3/8.4 سازگاری 8.0 را
اثبات نمی‌کند. WooCommerce در بخش محصول/سفارش استفاده می‌شود، اما نسخه نصب‌شده و
حداقل نسخه دقیق آن UNVERIFIED است.

قبل از افزودن service، بررسی کنید helper یا قرارداد ماژول موجود همان کار را انجام
می‌دهد. CSS عمومی بزرگ و هسته 2.5MB را در تسک کم‌دامنه بازآرایی نکنید. برای تغییر
عمومی، callerها، shared assets، shortcode و AJAX هر پنل را بررسی کنید.
