# REPORTING — گزارش‌ها و خروجی

Status: OBSERVED static source, baseline 2.0.235 / 2026-11-12. Human specialist: UNASSIGNED; repository owner: @mehrdadhafezi.

## 1. Purpose & Scope

گزارش عملیاتی و مدیریتی و export سفارش. scope گزارش و export یکسان بماند. خروجی هویتی/مالی داده حساس است. زمان محلی نمایش با timestamp ذخیره‌سازی یکسان فرض نشود. این قرارداد برای توسعه ماژول موجود است؛ بازطراحی فرآیند تجاری خارج از محدوده است.

## 2. File Structure

- [includes/reports/class-sn-report-service.php:1](../../includes/reports/class-sn-report-service.php#L1): `SN_Report_Service`
- [includes/reports/class-sn-report-executive.php:1](../../includes/reports/class-sn-report-executive.php#L1): `SN_Report_Executive`
- [includes/class-sn-bulk-order-export.php:1](../../includes/class-sn-bulk-order-export.php#L1): `SN_Bulk_Order_Export`

متدهای راهنما (نام و محل واقعی؛ قرارداد کامل را در declaration بخوانید):

- [includes/class-sn-bulk-order-export.php:14](../../includes/class-sn-bulk-order-export.php#L14) — `xml`
- [includes/class-sn-bulk-order-export.php:22](../../includes/class-sn-bulk-order-export.php#L22) — `row_xml`
- [includes/class-sn-bulk-order-export.php:34](../../includes/class-sn-bulk-order-export.php#L34) — `date_parts`
- [includes/class-sn-bulk-order-export.php:42](../../includes/class-sn-bulk-order-export.php#L42) — `values`
- [includes/class-sn-bulk-order-export.php:67](../../includes/class-sn-bulk-order-export.php#L67) — `query`
- [includes/class-sn-bulk-order-export.php:75](../../includes/class-sn-bulk-order-export.php#L75) — `write`
- [includes/class-sn-bulk-order-export.php:80](../../includes/class-sn-bulk-order-export.php#L80) — `download`
- [includes/reports/class-sn-report-executive.php:11](../../includes/reports/class-sn-report-executive.php#L11) — `__construct`
- [includes/reports/class-sn-report-executive.php:18](../../includes/reports/class-sn-report-executive.php#L18) — `under_person`
- [includes/reports/class-sn-report-executive.php:24](../../includes/reports/class-sn-report-executive.php#L24) — `definitions`
- [includes/reports/class-sn-report-executive.php:40](../../includes/reports/class-sn-report-executive.php#L40) — `filters`
- [includes/reports/class-sn-report-executive.php:52](../../includes/reports/class-sn-report-executive.php#L52) — `table_exists`

## 3. Execution Flow

مسیر مشاهده‌شده در سطح ماژول: bootstrap وردپرس، ثبت hook یا caller هسته، سپس متد ماژول. شکل زیر نمای ساختاری است؛ ترتیب همه callbackها را تضمین نمی‌کند.

```mermaid
flowchart LR
  W[WordPress bootstrap] --> H[Registered hook or core caller]
  H --> M[REPORTING]
  M --> G[Existing authorization and validation]
  G --> D[wpdb or WordPress API]
  D --> O[Existing response or render]
```

## 4. Coding Standards

[استاندارد توسعه](../DEVELOPMENT_STANDARDS.md) لازم است. فقط مسیرهای این ماژول که در تسک مجاز شده‌اند تغییر کنند. متدهای عمومی و hookهای موجود rename نشوند. اشتراک در SN_Plugin به معنی مجوز تغییر تمام هسته نیست. scope گزارش و export یکسان بماند. خروجی هویتی/مالی داده حساس است. زمان محلی نمایش با timestamp ذخیره‌سازی یکسان فرض نشود.

## 5. Component & Interface Contracts

کلاس‌ها و متدهای بخش ۲ interface فعلی‌اند؛ امضا، مقدار پیش‌فرض و return type از سورس مرجع حفظ شوند. برای ورودی و خروجی کامل هر درخواست [API_CONTRACTS.md](../API_CONTRACTS.md) و handler متناظر را با هم بخوانید. شِمای exhaustively verified برای همه payloadها: UNVERIFIED؛ lexical index جایگزین بررسی handler نیست. خطاهای کاربر فارسی و امن باشند و رفتار فعلی status/redirect حفظ شود.

## 6. Adding or Modifying Features

1. رفتار فعلی و معیار پذیرش را از تسک و caller اثبات کنید.
2. مجوز مسیر و بازبینی مالک برای نقاط حساس را بررسی کنید.
3. تغییر محدود انجام دهید؛ migration، Role، API یا side effect جدید بدون شرح و تأیید وارد نشود.
4. سناریوهای بخش ۸ و Syntax را اجرا کنید؛ تست اجرا‌نشده را NOT_RUN بنویسید.
5. این قرارداد، API/schema مرتبط و گزارش PR را به‌روز کنید.

## 7. Data Ownership & Integrity

scope گزارش و export یکسان بماند. خروجی هویتی/مالی داده حساس است. زمان محلی نمایش با timestamp ذخیره‌سازی یکسان فرض نشود.

نام‌های جدول literal مشاهده‌شده و traceشده در DDL/اتصال prefix (فهرست runtime کامل نیست): `sn_invoice_items`, `sn_invoice_payment_stages`, `sn_invoices`, `sn_shipping_orders`

DDL و PK/indexها: [DATABASE_SCHEMA_AUDIT.md](../audit/DATABASE_SCHEMA_AUDIT.md). اطلاعات مشتری، مالی، token و snapshot داده حساس‌اند. رابطه‌ای که فقط در کد وجود دارد Foreign Key فرض نشود. حذف history، double-credit و استفاده از رکورد خارج از scope ممنوع است.

## 8. Testing & Preview

سناریوهای ضروری این ماژول: سطح self/team/manager/all؛ بازه تاریخ؛ مجوز export؛ totals با ردیف‌ها؛ UTF-8/RTL؛ XLSX formula safety.

Syntax و Secret Scan در CI فعال‌اند. [ماتریس تست](../TESTING_STANDARDS.md) وضعیت زیرساخت را مشخص می‌کند. تست MIS فقط در محیط disposable با guard مجاز است؛ سایر سناریوها که fixture ندارند NOT_IMPLEMENTED هستند. پیش‌نمایش UI و تست authorization واقعی نیازمند سایت آزمایشی ایزوله است.

## 9. API & Integration Contracts

ثبت‌های literal مرتبط (اگر action با حلقه یا نام پویا ساخته شود باید caller را نیز بررسی کنید):

NOT_APPLICABLE برای endpoint literal مستقل در این فایل‌ها؛ callerهای هسته و فهرست hookها را بررسی کنید.

فهرست ثبت کامل در [HOOK_API_INDEX.md](../audit/HOOK_API_INDEX.md) و امضاها در [SOURCE_SYMBOLS.json](../audit/SOURCE_SYMBOLS.json). auth، nonce، scope، ورودی/خروجی و کد خطا باید برای handler تغییرکرده مستقیماً مستند شوند؛ endpoint جدید در این سند ساخته نشده است.

## 10. Administrative & Role Behavior

مجوز جاری از handler، SN_HR_Service/SN_Scope_Service و capabilityهای ثبت‌شده خوانده می‌شود. مخفی‌کردن دکمه مجوز backend نیست. تغییر cap، role، نگاشت HR یا scope نیازمند تسک مستقل و تأیید مالک است. ماتریس تست‌شده نقش‌ها در Production: UNVERIFIED. درخواست فاقد احراز هویت، nonce لازم یا scope باید با الگوی موجود رد شود.

## 11. Compatibility & Change History

R1 در 2026-11-12 فقط کد موجود نسخه 2.0.235 را مستند کرده است؛ کد PHP/JS/CSS تغییر نکرده است. Header: WordPress >=6.0 و PHP >=8.0؛ سازگاری runtime تمام نسخه‌ها در این جلسه UNVERIFIED. تاریخچه Releaseهای قدیمی شواهد تاریخی است و به معنی PASS این نسخه نیست. Merge و Deployment این مأموریت انجام نمی‌شود.
