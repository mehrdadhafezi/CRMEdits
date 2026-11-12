# Git workflow — R1

Canonical remote: `https://github.com/mehrdadhafezi/CRMEdits.git`، Private.
شاخه اصلی `main` است. وضعیت واقعی enforce شدن در
[GitHub governance](GITHUB_GOVERNANCE.md) ثبت می‌شود؛ وجود این سند به معنای فعال‌بودن
Branch Protection نیست.

یک Issue برای هر تسک و یک branch مستقل:
`feature/<issue-id>-<description>`، `fix/...`، `hotfix/...`، `docs/...` یا `test/...`.
استثنای bootstrap این مأموریت `docs/crm-engineering-standard-r1` است.

```bash
git status --short --branch
git remote -v
git fetch origin
git switch main
git pull --ff-only origin main
git switch -c fix/123-specific-behavior
```

این دستورات فرض می‌کنند worktree تمیز و main موجود است؛ در غیر این صورت تغییر
دیگران را stash/reset/clean نکنید. از worktree مستقل یا هماهنگی با مالک تغییر استفاده کنید.

Commitها روشن و قابل ردیابی باشند: `feat(module): ...`، `fix(module): ...`،
`refactor(module): ...`، `test(module): ...` یا `docs(module): ...`؛ Issue در پیام/PR
ذکر شود. قبل از commit فایل‌ها را صریح stage کنید و diff نهایی را ببینید:

```bash
python3 scripts/check.py all
git diff --check
git diff
git add docs/DEVELOPER_HANDBOOK.md
git diff --cached
git commit -m "docs(handbook): clarify setup steps (#123)"
git push -u origin HEAD
```

فایل stage نمونه را با فایل‌های واقعی تسک جایگزین کنید. push فقط به branch خودتان؛
Direct Push به main، Force Push شاخه مشترک/محافظت‌شده و حذف branch دیگران ممنوع است.
برای PR با GitHub UI یا، در صورت نصب و ورود معتبر `gh`:

```bash
gh pr create --base main --head fix/123-specific-behavior --title "fix(module): specific behavior" --body-file /tmp/crm-pr.md
```

پیش از اجرای این دستور، توضیح واقعی PR را طبق
[قالب PR](../.github/PULL_REQUEST_TEMPLATE.md) در فایل `/tmp/crm-pr.md` بنویسید.

تغییرات همزمان: پس از fetch، روی branch منتشرشده خودتان `git merge origin/main`
انجام دهید و conflict را با بررسی هر دو تغییر حل کنید. هیچ فایل را کورکورانه با
ours/theirs جایگزین نکنید. بعد از حل conflict، تست مرتبط و diff را دوباره بررسی کنید.
Rebase برای commitهای **محلی و منتشرنشده** مجاز است؛ اگر branch منتشر شده، merge
روش پیش‌فرض است تا بازنویسی تاریخچه و lost update ایجاد نشود. هنگام push ردشده،
remote را fetch و علت تغییر جدید را بررسی کنید؛ Force Push راه‌حل پیش‌فرض نیست.

ورود به main فقط با PR، حداقل یک review مجاز مستقل از نویسنده، CI موفق، حل تمام
گفتگوها و review مالک بخش حساس مجاز است. نویسنده نمی‌تواند PR خودش را approve کند؛
برای تغییر حساس نویسنده مالک، reviewer مجاز دیگری لازم است. پس از commit جدید،
approval قبلی باید stale شود. Merge طبق اختیار ثبت‌شده مالک انجام می‌شود؛ مجوز
merge/deploy از prompt ثابت AI استخراج نشود.

مخزن خالی: اولین commit فقط با تأیید صریح مالک، محتوای حداقلی bootstrap و بدون
انتقال history قدیمی ساخته می‌شود؛ سپس همه تغییرات با PR. وضعیت approval و SHA
واقعی در گزارش تحویل درج می‌شود.
