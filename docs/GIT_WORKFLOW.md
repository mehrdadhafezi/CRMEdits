# Git workflow — R1

Canonical remote: `https://github.com/mehrdadhafezi/CRMEdits.git`، Private.
شاخه اصلی `main` است. وضعیت واقعی enforce شدن در
[GitHub governance](GITHUB_GOVERNANCE.md) ثبت می‌شود؛ وجود این سند به معنای فعال‌بودن
Branch Protection نیست.

برای هر تسک یک Issue و یک شاخه مستقل. در Issue و PR مسیر، SHA مبنا، مقصد PR و
فایل‌های مشترک (به‌ویژه schema، نقش‌ها و فایل‌های مرکزی) را بنویسید تا دو نفر
هم‌زمان همان بخش را بی‌خبر تغییر ندهند. هر توسعه‌دهنده در clone یا worktree جدا
روی شاخه خودش کار و فقط همان شاخه را push می‌کند.

| شاخه | مبنا و مقصد PR |
|---|---|
| `main` | مسیر پایدار. مبنای نسخهٔ Staging و انتشار مصوب |
| `fix/*`، `hotfix/*`، `feature/*` | از `main` به‌روز → PR به `main` |
| `experimental` | شاخهٔ مشترک آزمایشی. مبنای Preview |
| `experiment/*` | از `experimental` به‌روز → PR به `experimental` |

شاخه‌های `docs/*` و `test/*` نیز از مقصد مصوب تسک شروع می‌شوند. برای تسک
آزمایشی، پس از fetch از `git switch -c experiment/123-change origin/experimental`
استفاده کنید. نمونهٔ زیر برای مسیر پایدار است.

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
Direct Push به `main` یا `experimental`، Force Push شاخه مشترک و حذف branch دیگران ممنوع است.
برای PR با GitHub UI یا، در صورت نصب و ورود معتبر `gh`:

```bash
gh pr create --base main --head fix/123-specific-behavior --title "fix(module): specific behavior" --body-file /tmp/crm-pr.md
```

پیش از اجرای این دستور، توضیح واقعی PR را طبق
[قالب PR](../.github/PULL_REQUEST_TEMPLATE.md) در فایل `/tmp/crm-pr.md` بنویسید.

تغییرات همزمان: پس از fetch، روی branch منتشرشده خودتان ref مقصد PR
(`origin/main` یا `origin/experimental`) را merge کنید و conflict را با بررسی هر دو تغییر حل کنید. هیچ فایل را کورکورانه با
ours/theirs جایگزین نکنید. بعد از حل conflict، تست مرتبط و diff را دوباره بررسی کنید.
Rebase برای commitهای **محلی و منتشرنشده** مجاز است؛ اگر branch منتشر شده، merge
روش پیش‌فرض است تا بازنویسی تاریخچه و lost update ایجاد نشود. هنگام push ردشده،
remote را fetch و علت تغییر جدید را بررسی کنید؛ Force Push راه‌حل پیش‌فرض نیست.

ورود به main فقط با PR، حداقل یک review مجاز مستقل از نویسنده، CI موفق، حل تمام
گفتگوها و review مالک بخش حساس مجاز است. نویسنده نمی‌تواند PR خودش را approve کند؛
برای تغییر حساس نویسنده مالک، reviewer مجاز دیگری لازم است. پس از commit جدید،
approval قبلی باید stale شود. Merge طبق اختیار ثبت‌شده مالک انجام می‌شود؛ مجوز
merge/deploy از prompt ثابت AI استخراج نشود.

## همگام‌سازی Hotfix از main به experimental

پس از Merge شدن اصلاح در `main`، یک نفر شاخهٔ sync را از `experimental` به‌روز
بسازد و آخرین `main` را در آن ادغام کند. جهت انتقال همیشه پایدار به آزمایشی است:

```bash
git fetch origin
git switch -c experiment/sync-main-123 origin/experimental
git merge --no-ff origin/main
git diff --check origin/experimental...HEAD
git diff origin/experimental...HEAD
```

قبل از push، conflictها، diff و سازگاری دو مسیر را بازبینی و تست‌های متأثر را
اجرا کنید. PR به `experimental` باز شود و فرد مجاز پس از review و CI، با
**merge commit** آن را ادغام کند تا ancestry اصلاحات پایدار حفظ شود. برای PR
همگام‌سازی از squash یا rebase استفاده نکنید. رفع هم‌زمان یک تضاد روی `main`
به PR پایدار جدا نیاز دارد.

پس از ادغام PR راه‌اندازی این گردش کار در `main`، اولین sync مصوب را برای
رساندن همین مستندات و workflow به `experimental` انجام دهید. تا آن sync،
pushهای شاخهٔ آزمایشی از workflow قبلی همان شاخه استفاده می‌کنند.

## انتقال انتخابی قابلیت آزمایشی به main

مالک باید قابلیت و حدود دقیق انتقال را در Issue یا PR صریحاً تأیید کند. شاخهٔ
جدید از آخرین `origin/main` بسازید. فقط commitهای عادی و بررسی‌شدهٔ همان قابلیت
را یکی‌یکی با `git cherry-pick -x <approved-sha>` منتقل کنید. اگر commit مشترک،
merge commit یا وابسته به آزمایش دیگر است، فقط بخش مصوب را روی مبنای پایدار
بازپیاده‌سازی کنید. منبع و وابستگی‌های هر تغییر را در PR ثبت کنید.

```bash
git fetch origin
git switch -c feature/123-approved-change origin/main
# Apply only the owner-approved changes here.
git log --oneline origin/main..HEAD
git diff --check origin/main...HEAD
git diff origin/main...HEAD
```

کل diff و فهرست commitها را با محدودهٔ مصوب تطبیق دهید، سازگاری با آخرین
Hotfixها را بررسی کنید و تست‌های مرتبط را دوباره اجرا کنید. PR مستقل به
`main` پس از CI و review مستقل نیازمند مجوز Merge مالک است. هیچ PR مستقیم
از `experimental` یا `experiment/*` به `main` و هیچ Merge کل
`experimental` به `main` انجام نشود. هر استثنا برای یکپارچه‌سازی کامل
به تصمیم جداگانهٔ مالک و تغییر بازبینی‌شدهٔ این سیاست نیاز دارد.

کنترل CI روی PRهای `main` نام شاخهٔ مبدأ و اشتراک commit با شاخه‌های آزمایشی
موجود را بررسی می‌کند. این کنترل محتوای cherry-pick، patch دستی یا squash را
تأیید نمی‌کند، پس مقایسهٔ diff با فهرست مالک اجباری است. تا وقتی GitHub
required checks و Branch Protection را enforce نمی‌کند، رعایت این کنترل
و ممنوعیت push مستقیم به افراد دارای دسترسی نوشتن وابسته است.

مخزن خالی: اولین commit فقط با تأیید صریح مالک، محتوای حداقلی bootstrap و بدون
انتقال history قدیمی ساخته می‌شود؛ سپس همه تغییرات با PR. وضعیت approval و SHA
واقعی در گزارش تحویل درج می‌شود.
