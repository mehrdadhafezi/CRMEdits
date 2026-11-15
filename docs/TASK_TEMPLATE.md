# Task template — R1

- Task ID / Issue:
- نوع فعالیت: Analysis Only / Local Implementation / PR Delivery
- مسیر پایدار یا آزمایشی، مقصد PR و SHA کامل مبنای تأییدشده:
- عنوان و هدف تجاری:
- رفتار فعلی با مسیر و شاهد:
- رفتار مورد انتظار و معیار پذیرش قابل آزمون:
- ماژول و قرارداد مرتبط:
- مسیرهای مجاز تغییر:
- قراردادها و اسناد موجود متأثر در محدوده:
- فایل‌ها/توابع/داده‌های ممنوع:
- وابستگی‌ها و تسک‌های همزمان:
- API impact / compatibility:
- Database impact / نیاز به migration و مجوز:
- Role/permission/scope impact و مجوز:
- ریسک و Security review:
- تست‌های لازم و محیط مجاز:
- شواهد تحویل مورد انتظار:
- روش بررسی Diff (Codex/دستی؛ بدون Approval انسانی اجباری) و مالک عملیات حساس:
- مرحلهٔ گردش کار، وضعیت آمادگی و دلیل مانع (جداگانه؛ READY FOR MERGE CHECK / BLOCKED):
- موارد UNVERIFIED/UNASSIGNED، نقش پیگیر و وضعیت پیگیری:
- Deadline فقط اگر تعیین شده، با timezone مشخص:
- Rollback کد و داده، محدودیت بازیابی:

خانه خالی در مجوزهای حساس به معنی «مجاز» نیست. reviewer تعیین‌نشده را
UNASSIGNED ثبت کنید. نام یا مهلت حدس نزنید. برای گسترش محدوده، تأیید صریح بگیرید.

---

## Historical task template (preserved)

بخش زیر فقط بایگانی است. هیچ‌یک از دستورها، تسک‌ها یا مجوزهای قدیمی آن را اجرا نکنید.

# Task Template

## Read first
- docs/PROJECT_CONTEXT.md
- docs/ARCHITECTURE.md
- docs/BUSINESS_RULES.md
- docs/DB_SCHEMA.md
- docs/API_CONTRACTS.md
- docs/FORBIDDEN_PATHS.md
- docs/QA_CHECKLIST.md
- docs/CURRENT_TASKS.md

## CURRENT_TASKS.md rule
Update docs/CURRENT_TASKS.md only if it does not already describe this task.
Otherwise keep it unchanged and update it only if scope, status, or acceptance criteria change.

## Active task
Use the active task described in docs/CURRENT_TASKS.md.

## Additional task-specific context
...

## Goal
...

## Allowed scope
...

## Forbidden scope
Use docs/FORBIDDEN_PATHS.md.

## Acceptance criteria
...

## Verification
Use docs/QA_CHECKLIST.md.

## Expected final report
- changes
- files changed
- root cause
- tests
- risks
- next step
