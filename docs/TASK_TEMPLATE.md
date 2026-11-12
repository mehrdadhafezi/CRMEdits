# Task template — R1

- Task ID / Issue:
- عنوان و هدف تجاری:
- رفتار فعلی با مسیر و شاهد:
- رفتار مورد انتظار و معیار پذیرش قابل آزمون:
- ماژول و قرارداد مرتبط:
- مسیرهای مجاز تغییر:
- فایل‌ها/توابع/داده‌های ممنوع:
- وابستگی‌ها و تسک‌های همزمان:
- API impact / compatibility:
- Database impact / نیاز به migration و مجوز:
- Role/permission/scope impact و مجوز:
- ریسک و Security review:
- تست‌های لازم و محیط مجاز:
- شواهد تحویل مورد انتظار:
- Reviewer مستقل و مالک بخش حساس:
- Deadline با timezone مشخص:
- Rollback کد و داده، محدودیت بازیابی:

خانه خالی در مجوزهای حساس به معنی «مجاز» نیست. شناسه و reviewer واقعی وارد شود.

---

## Historical task template (preserved)

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
