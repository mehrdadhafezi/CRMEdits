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

شاخه‌های `docs/*` و `test/*` نیز از مقصد مصوب تسک شروع می‌شوند.

## ساخت شاخه از SHA تأییدشده

SHA کامل مقصد را در GitHub بخوانید و مبنای مصوب را در تسک ثبت کنید. بلوک را
یکجا در Git Bash اجرا کنید. برای مسیر آزمایشی، `target` را `experimental` و
`topic` را مثلاً `experiment/123-change` بگذارید.

```bash
(
  set -euo pipefail
  target=main
  expected='<approved-full-sha>'
  topic=fix/123-specific-behavior
  fetch_url=$(git remote get-url origin)
  push_url=$(git remote get-url --push origin)
  test "$fetch_url" = https://github.com/mehrdadhafezi/CRMEdits.git
  test "$push_url" = "$fetch_url"
  changes=$(git status --porcelain --untracked-files=all)
  test -z "$changes" || { git status --short; echo 'Stop: worktree is dirty'; exit 1; }
  git fetch origin
  ref="refs/remotes/origin/$target"
  base=$(git rev-parse --verify "$ref^{commit}")
  test "$base" = "$expected" || { echo "Stop: fetched base is $base"; exit 1; }
  unpublished=$(git rev-list HEAD --not --remotes=origin)
  test -z "$unpublished" || { echo 'Stop: current HEAD has unpublished commits'; exit 1; }
  if git show-ref --verify --quiet "refs/heads/$target"; then
    git merge-base --is-ancestor "refs/heads/$target" "$base" ||
      { echo 'Stop: local target is ahead of or diverged from origin'; exit 1; }
  fi
  git switch --no-track -c "$topic" "$base"
)
```

خطا را گزارش دهید و کار وابسته را تا روشن‌شدن وضعیت متوقف کنید. تغییر یا commit
محلی را پاک، reset یا بازنویسی نکنید. با هماهنگی مالک تغییر، از worktree یا clone
تمیز و همان SHA تأییدشده ادامه دهید. `git pull --ff-only` برای تشخیص main محلیِ
جلوتر کافی نیست.

Commitها روشن و قابل ردیابی باشند: `feat(module): ...`، `fix(module): ...`،
`refactor(module): ...`، `test(module): ...` یا `docs(module): ...`؛ Issue در پیام/PR
ذکر شود. برای تست، stage صریح، Commit و Push از
[نمونهٔ fail-fast راهنما](DEVELOPER_HANDBOOK.md#24-نمونه-کامل-از-تسک-تا-pr)
استفاده کنید. Push فقط به branch خودتان مجاز است.
Direct Push به `main` یا `experimental`، Force Push شاخه مشترک و حذف branch دیگران ممنوع است.
ترتیب Draft، CI و Ready در [راهنمای PR](DEVELOPER_HANDBOOK.md#15-pull-request)
آمده است. توضیح PR را طبق [قالب PR](../.github/PULL_REQUEST_TEMPLATE.md) تکمیل کنید.

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
(
  set -euo pipefail
  main_sha='<approved-full-main-sha>'
  experimental_sha='<approved-full-experimental-sha>'
  test "$(git branch --show-current)" = experiment/sync-main-123
  git fetch origin
  test "$(git rev-parse --verify 'refs/remotes/origin/main^{commit}')" = "$main_sha"
  test "$(git rev-parse --verify 'refs/remotes/origin/experimental^{commit}')" = "$experimental_sha"
  test "$(git rev-parse HEAD)" = "$experimental_sha"
  git merge --no-ff "$main_sha"
  git diff --check "$experimental_sha"...HEAD
  git diff "$experimental_sha"...HEAD
)
```

ابتدا شاخهٔ `experiment/sync-main-123` را با نمونهٔ ساخت شاخه در همین سند بسازید.
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
# Create feature/123-approved-change using the verified-base example above.
# After porting only the approved changes, compare with the recorded main SHA.
base='<approved-full-main-sha>'
git log --oneline "$base"..HEAD
git diff --check "$base"...HEAD
git diff "$base"...HEAD
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
