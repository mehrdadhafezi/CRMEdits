# CUSTOMER_PORTAL — پرتال و OTP مشتری

Status: OBSERVED static source, baseline 2.0.235 / 2026-11-12. Human specialist: UNASSIGNED; repository owner: @mehrdadhafezi.

## 1. Purpose & Scope

ورود OTP و مشاهده اطلاعات خرید و پرداخت مشتری. OTP، session و رکورد مشتری باید به همان هویت محدود شوند. سابقه پرداخت و فیش را فقط برای صاحب مجاز نمایش دهید. این قرارداد برای توسعه ماژول موجود است؛ بازطراحی فرآیند تجاری خارج از محدوده است.

## 2. File Structure

- [includes/class-sn-customer-portal.php:1](../../includes/class-sn-customer-portal.php#L1): `SN_Customer_Portal`

متدهای راهنما (نام و محل واقعی؛ قرارداد کامل را در declaration بخوانید):

- [includes/class-sn-customer-portal.php:20](../../includes/class-sn-customer-portal.php#L20) — `instance`
- [includes/class-sn-customer-portal.php:25](../../includes/class-sn-customer-portal.php#L25) — `__construct`
- [includes/class-sn-customer-portal.php:27](../../includes/class-sn-customer-portal.php#L27) — `activate`
- [includes/class-sn-customer-portal.php:31](../../includes/class-sn-customer-portal.php#L31) — `register_hooks`
- [includes/class-sn-customer-portal.php:49](../../includes/class-sn-customer-portal.php#L49) — `register_shortcode`
- [includes/class-sn-customer-portal.php:53](../../includes/class-sn-customer-portal.php#L53) — `maybe_upgrade`
- [includes/class-sn-customer-portal.php:57](../../includes/class-sn-customer-portal.php#L57) — `table_exists`
- [includes/class-sn-customer-portal.php:62](../../includes/class-sn-customer-portal.php#L62) — `install_schema`
- [includes/class-sn-customer-portal.php:79](../../includes/class-sn-customer-portal.php#L79) — `portal_page_id`
- [includes/class-sn-customer-portal.php:87](../../includes/class-sn-customer-portal.php#L87) — `portal_url`
- [includes/class-sn-customer-portal.php:93](../../includes/class-sn-customer-portal.php#L93) — `current_page_is_portal`
- [includes/class-sn-customer-portal.php:102](../../includes/class-sn-customer-portal.php#L102) — `enqueue_assets`

## 3. Execution Flow

مسیر مشاهده‌شده در سطح ماژول: bootstrap وردپرس، ثبت hook یا caller هسته، سپس متد ماژول. شکل زیر نمای ساختاری است؛ ترتیب همه callbackها را تضمین نمی‌کند.

```mermaid
flowchart LR
  W[WordPress bootstrap] --> H[Registered hook or core caller]
  H --> M[CUSTOMER_PORTAL]
  M --> G[Existing authorization and validation]
  G --> D[wpdb or WordPress API]
  D --> O[Existing response or render]
```

## 4. Coding Standards

[استاندارد توسعه](../DEVELOPMENT_STANDARDS.md) لازم است. فقط مسیرهای این ماژول که در تسک مجاز شده‌اند تغییر کنند. متدهای عمومی و hookهای موجود rename نشوند. اشتراک در SN_Plugin به معنی مجوز تغییر تمام هسته نیست. OTP، session و رکورد مشتری باید به همان هویت محدود شوند. سابقه پرداخت و فیش را فقط برای صاحب مجاز نمایش دهید.

## 5. Component & Interface Contracts

کلاس‌ها و متدهای بخش ۲ interface فعلی‌اند؛ امضا، مقدار پیش‌فرض و return type از سورس مرجع حفظ شوند. برای ورودی و خروجی کامل هر درخواست [API_CONTRACTS.md](../API_CONTRACTS.md) و handler متناظر را با هم بخوانید. شِمای exhaustively verified برای همه payloadها: UNVERIFIED؛ lexical index جایگزین بررسی handler نیست. خطاهای کاربر فارسی و امن باشند و رفتار فعلی status/redirect حفظ شود.

## 6. Adding or Modifying Features

1. رفتار فعلی و معیار پذیرش را از تسک و caller اثبات کنید.
2. مجوز مسیر و بازبینی مالک برای نقاط حساس را بررسی کنید.
3. تغییر محدود انجام دهید؛ migration، Role، API یا side effect جدید بدون شرح و تأیید وارد نشود.
4. سناریوهای بخش ۸ و Syntax را اجرا کنید؛ تست اجرا‌نشده را NOT_RUN بنویسید.
5. این قرارداد، API/schema مرتبط و گزارش PR را به‌روز کنید.

## 7. Data Ownership & Integrity

OTP، session و رکورد مشتری باید به همان هویت محدود شوند. سابقه پرداخت و فیش را فقط برای صاحب مجاز نمایش دهید.

نام‌های جدول literal مشاهده‌شده و traceشده در DDL/اتصال prefix (فهرست runtime کامل نیست): `sn_dot_cases`, `sn_dot_invoice_links`, `sn_hr_positions`, `sn_hr_profiles`, `sn_invoice_items`, `sn_invoice_payment_stages`, `sn_invoices`, `sn_payments`, `sn_project_actions`, `sn_project_events`, `sn_project_membership_items`, `sn_project_memberships`, `sn_project_operations`

DDL و PK/indexها: [DATABASE_SCHEMA_AUDIT.md](../audit/DATABASE_SCHEMA_AUDIT.md). اطلاعات مشتری، مالی، token و snapshot داده حساس‌اند. رابطه‌ای که فقط در کد وجود دارد Foreign Key فرض نشود. حذف history، double-credit و استفاده از رکورد خارج از scope ممنوع است.

## 8. Testing & Preview

سناریوهای ضروری این ماژول: OTP غلط/منقضی؛ throttle؛ session نامعتبر؛ خرید مشتری دیگر؛ nonce نامعتبر؛ نمایش RTL موبایل.

Syntax و Secret Scan در CI فعال‌اند. [ماتریس تست](../TESTING_STANDARDS.md) وضعیت زیرساخت را مشخص می‌کند. تست MIS فقط در محیط disposable با guard مجاز است؛ سایر سناریوها که fixture ندارند NOT_IMPLEMENTED هستند. پیش‌نمایش UI و تست authorization واقعی نیازمند سایت آزمایشی ایزوله است.

## 9. API & Integration Contracts

ثبت‌های literal مرتبط (اگر action با حلقه یا نام پویا ساخته شود باید caller را نیز بررسی کنید):

- `add_action`: `init` — [includes/class-sn-customer-portal.php:34](../../includes/class-sn-customer-portal.php#L34)
- `add_action`: `init` — [includes/class-sn-customer-portal.php:35](../../includes/class-sn-customer-portal.php#L35)
- `add_action`: `wp_enqueue_scripts` — [includes/class-sn-customer-portal.php:36](../../includes/class-sn-customer-portal.php#L36)
- `add_action`: `sn_project_membership_activated` — [includes/class-sn-customer-portal.php:38](../../includes/class-sn-customer-portal.php#L38)
- `add_action`: `wp_ajax_sn_customer_portal_send_otp` — [includes/class-sn-customer-portal.php:40](../../includes/class-sn-customer-portal.php#L40)
- `add_action`: `wp_ajax_nopriv_sn_customer_portal_send_otp` — [includes/class-sn-customer-portal.php:41](../../includes/class-sn-customer-portal.php#L41)
- `add_action`: `wp_ajax_sn_customer_portal_verify_otp` — [includes/class-sn-customer-portal.php:42](../../includes/class-sn-customer-portal.php#L42)
- `add_action`: `wp_ajax_nopriv_sn_customer_portal_verify_otp` — [includes/class-sn-customer-portal.php:43](../../includes/class-sn-customer-portal.php#L43)
- `add_action`: `wp_ajax_sn_customer_purchased_subscriptions` — [includes/class-sn-customer-portal.php:44](../../includes/class-sn-customer-portal.php#L44)
- `add_filter`: `show_admin_bar` — [includes/class-sn-customer-portal.php:45](../../includes/class-sn-customer-portal.php#L45)
- `add_filter`: `wp_robots` — [includes/class-sn-customer-portal.php:46](../../includes/class-sn-customer-portal.php#L46)
- `add_shortcode`: `sn_biavin_customer_portal` — [includes/class-sn-customer-portal.php:50](../../includes/class-sn-customer-portal.php#L50)

فهرست ثبت کامل در [HOOK_API_INDEX.md](../audit/HOOK_API_INDEX.md) و امضاها در [SOURCE_SYMBOLS.json](../audit/SOURCE_SYMBOLS.json). auth، nonce، scope، ورودی/خروجی و کد خطا باید برای handler تغییرکرده مستقیماً مستند شوند؛ endpoint جدید در این سند ساخته نشده است.

## 10. Administrative & Role Behavior

مجوز جاری از handler، SN_HR_Service/SN_Scope_Service و capabilityهای ثبت‌شده خوانده می‌شود. مخفی‌کردن دکمه مجوز backend نیست. تغییر cap، role، نگاشت HR یا scope نیازمند تسک مستقل و تأیید مالک است. ماتریس تست‌شده نقش‌ها در Production: UNVERIFIED. درخواست فاقد احراز هویت، nonce لازم یا scope باید با الگوی موجود رد شود.

## 11. Compatibility & Change History

R1 در 2026-11-12 فقط کد موجود نسخه 2.0.235 را مستند کرده است؛ کد PHP/JS/CSS تغییر نکرده است. Header: WordPress >=6.0 و PHP >=8.0؛ سازگاری runtime تمام نسخه‌ها در این جلسه UNVERIFIED. تاریخچه Releaseهای قدیمی شواهد تاریخی است و به معنی PASS این نسخه نیست. Merge و Deployment این مأموریت انجام نمی‌شود.
