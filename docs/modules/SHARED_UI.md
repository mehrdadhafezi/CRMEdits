# SHARED_UI — ابزارهای مشترک و رابط فارسی

Status: OBSERVED static source, baseline 2.0.235 / 2026-11-12. Human specialist: UNASSIGNED; repository owner: @mehrdadhafezi.

## 1. Purpose & Scope

نرمال‌سازی، آپلود، نرخ محدود و قالب‌های مشترک. نرمال‌سازی رقم فارسی نباید صفر اول را حذف کند. escape متناسب با HTML/attribute/URL انجام شود. helper احراز مجوز درخواست‌کننده نیست. این قرارداد برای توسعه ماژول موجود است؛ بازطراحی فرآیند تجاری خارج از محدوده است.

## 2. File Structure

- [includes/class-sn-helpers.php:1](../../includes/class-sn-helpers.php#L1): `SN_Helpers`
- [templates/mis-portal.php:1](../../templates/mis-portal.php#L1):
- [templates/admin-patra-console.php:1](../../templates/admin-patra-console.php#L1):

متدهای راهنما (نام و محل واقعی؛ قرارداد کامل را در declaration بخوانید):

- [includes/class-sn-helpers.php:10](../../includes/class-sn-helpers.php#L10) — `customer_address`
- [includes/class-sn-helpers.php:17](../../includes/class-sn-helpers.php#L17) — `customer_name_parts`
- [includes/class-sn-helpers.php:32](../../includes/class-sn-helpers.php#L32) — `panel_access_denied`
- [includes/class-sn-helpers.php:41](../../includes/class-sn-helpers.php#L41) — `normalize_invoice_code`
- [includes/class-sn-helpers.php:51](../../includes/class-sn-helpers.php#L51) — `generate_invoice_code`
- [includes/class-sn-helpers.php:62](../../includes/class-sn-helpers.php#L62) — `invoice_code_exists`
- [includes/class-sn-helpers.php:72](../../includes/class-sn-helpers.php#L72) — `generate_access_token`
- [includes/class-sn-helpers.php:80](../../includes/class-sn-helpers.php#L80) — `invoice_access_token_exists`
- [includes/class-sn-helpers.php:89](../../includes/class-sn-helpers.php#L89) — `generate_unique_access_token`
- [includes/class-sn-helpers.php:98](../../includes/class-sn-helpers.php#L98) — `format_price`
- [includes/class-sn-helpers.php:104](../../includes/class-sn-helpers.php#L104) — `status_label`
- [includes/class-sn-helpers.php:141](../../includes/class-sn-helpers.php#L141) — `pay_method_label`

## 3. Execution Flow

مسیر مشاهده‌شده در سطح ماژول: bootstrap وردپرس، ثبت hook یا caller هسته، سپس متد ماژول. شکل زیر نمای ساختاری است؛ ترتیب همه callbackها را تضمین نمی‌کند.

```mermaid
flowchart LR
  W[WordPress bootstrap] --> H[Registered hook or core caller]
  H --> M[SHARED_UI]
  M --> G[Existing authorization and validation]
  G --> D[wpdb or WordPress API]
  D --> O[Existing response or render]
```

## 4. Coding Standards

[استاندارد توسعه](../DEVELOPMENT_STANDARDS.md) لازم است. فقط مسیرهای این ماژول که در تسک مجاز شده‌اند تغییر کنند. متدهای عمومی و hookهای موجود rename نشوند. اشتراک در SN_Plugin به معنی مجوز تغییر تمام هسته نیست. نرمال‌سازی رقم فارسی نباید صفر اول را حذف کند. escape متناسب با HTML/attribute/URL انجام شود. helper احراز مجوز درخواست‌کننده نیست.

## 5. Component & Interface Contracts

کلاس‌ها و متدهای بخش ۲ interface فعلی‌اند؛ امضا، مقدار پیش‌فرض و return type از سورس مرجع حفظ شوند. برای ورودی و خروجی کامل هر درخواست [API_CONTRACTS.md](../API_CONTRACTS.md) و handler متناظر را با هم بخوانید. شِمای exhaustively verified برای همه payloadها: UNVERIFIED؛ lexical index جایگزین بررسی handler نیست. خطاهای کاربر فارسی و امن باشند و رفتار فعلی status/redirect حفظ شود.

## 6. Adding or Modifying Features

1. رفتار فعلی و معیار پذیرش را از تسک و caller اثبات کنید.
2. مجوز مسیر و بازبینی مالک برای نقاط حساس را بررسی کنید.
3. تغییر محدود انجام دهید؛ migration، Role، API یا side effect جدید بدون شرح و تأیید وارد نشود.
4. سناریوهای بخش ۸ و Syntax را اجرا کنید؛ تست اجرا‌نشده را NOT_RUN بنویسید.
5. این قرارداد، API/schema مرتبط و گزارش PR را به‌روز کنید.

## 7. Data Ownership & Integrity

نرمال‌سازی رقم فارسی نباید صفر اول را حذف کند. escape متناسب با HTML/attribute/URL انجام شود. helper احراز مجوز درخواست‌کننده نیست.

نام‌های جدول literal مشاهده‌شده و traceشده در DDL/اتصال prefix (فهرست runtime کامل نیست): `sn_invoices`

DDL و PK/indexها: [DATABASE_SCHEMA_AUDIT.md](../audit/DATABASE_SCHEMA_AUDIT.md). اطلاعات مشتری، مالی، token و snapshot داده حساس‌اند. رابطه‌ای که فقط در کد وجود دارد Foreign Key فرض نشود. حذف history، double-credit و استفاده از رکورد خارج از scope ممنوع است.

## 8. Testing & Preview

سناریوهای ضروری این ماژول: ارقام فارسی/عربی؛ ورودی array؛ آپلود نامعتبر؛ rate limit؛ صفحه‌کلید؛ موبایل و RTL؛ JS بدون خطا.

Syntax و Secret Scan در CI فعال‌اند. [ماتریس تست](../TESTING_STANDARDS.md) وضعیت زیرساخت را مشخص می‌کند. تست MIS فقط در محیط disposable با guard مجاز است؛ سایر سناریوها که fixture ندارند NOT_IMPLEMENTED هستند. پیش‌نمایش UI و تست authorization واقعی نیازمند سایت آزمایشی ایزوله است.

## 9. API & Integration Contracts

ثبت‌های literal مرتبط (اگر action با حلقه یا نام پویا ساخته شود باید caller را نیز بررسی کنید):

- `add_filter`: `upload_dir` — [includes/class-sn-helpers.php:681](../../includes/class-sn-helpers.php#L681)

فهرست ثبت کامل در [HOOK_API_INDEX.md](../audit/HOOK_API_INDEX.md) و امضاها در [SOURCE_SYMBOLS.json](../audit/SOURCE_SYMBOLS.json). auth، nonce، scope، ورودی/خروجی و کد خطا باید برای handler تغییرکرده مستقیماً مستند شوند؛ endpoint جدید در این سند ساخته نشده است.

## 10. Administrative & Role Behavior

مجوز جاری از handler، SN_HR_Service/SN_Scope_Service و capabilityهای ثبت‌شده خوانده می‌شود. مخفی‌کردن دکمه مجوز backend نیست. تغییر cap، role، نگاشت HR یا scope نیازمند تسک مستقل و تأیید مالک است. ماتریس تست‌شده نقش‌ها در Production: UNVERIFIED. درخواست فاقد احراز هویت، nonce لازم یا scope باید با الگوی موجود رد شود.

## 11. Compatibility & Change History

R1 در 2026-11-12 فقط کد موجود نسخه 2.0.235 را مستند کرده است؛ کد PHP/JS/CSS تغییر نکرده است. Header: WordPress >=6.0 و PHP >=8.0؛ سازگاری runtime تمام نسخه‌ها در این جلسه UNVERIFIED. تاریخچه Releaseهای قدیمی شواهد تاریخی است و به معنی PASS این نسخه نیست. Merge و Deployment این مأموریت انجام نمی‌شود.
