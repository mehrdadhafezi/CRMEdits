# R2 — Read-only SHA256 production parity plan

| موضوع | وضعیت R2 | معنی |
|---|---|---|
| تهیه مبنای offline از Git ثابت | PASS | [manifest زمان اجرا](../audit/r2/RUNTIME_SHA256SUMS-2.0.235.txt)، 116 فایل از `d037fe8…` |
| مقایسه manifest با همین checkout | PASS | باید در شواهد اجرای محلی R2 ثبت شود؛ بدون load PHP/WordPress |
| شناسایی مسیر و نسخه نصب‌شده Production | NOT_RUN | اتصال به Production در این مأموریت ممنوع است |
| مقایسه واقعی Production | NOT_RUN | این سند فقط برنامه و نمونه دستور محلی دارد |
| تأیید اپراتور، مسیر snapshot و زمان شاهد | BLOCKED | باید مالک برای مأموریت مستقل تعیین کند؛ هیچ هویت/مسیر حدس زده نشده است |

## تعریف مقایسه

SHA کامل مبنا `d037fe8bde4aadbb189c055114683fccf22bb0dc` و نسخه Header/SN_VERSION
برابر 2.0.235 است. برابری شماره نسخه، برابری فایل را ثابت نمی‌کند. SHA256 شاهد
برابری byteهاست؛ به‌تنهایی امنیت، اصالت استقرار، schema یا داده مشتری را ثابت نمی‌کند.

دو دامنه را جدا ثبت کنید:

1. **Runtime**: `sales-network.php` و تمام فایل‌های موجود در `assets/`، `includes/`
   و `templates/`؛ manifest آماده 116 فایل دارد و مسیرها relative به ریشه افزونه‌اند.
2. **بسته کامل اولیه**: [manifest 398 فایل ZIP](../audit/ACCEPTED_SOURCE_SHA256SUMS.txt).
   چهار سند تاریخی در Git R1 به‌صورت مستند به‌روز شده‌اند؛ فایل‌های governance و
   تست نیز ممکن است عمداً روی host نصب نشده باشند. اختلاف مستندات را با اختلاف
   PHP/JS/CSS مخلوط نکنید. اختلاف runtime هیچ‌وقت صرفاً با یکسان بودن version پذیرفته نشود.

## برنامه اپراتور مجاز آینده

1. مالک هدف، پنجره زمانی، اپراتور، محل امن evidence و **فقط خواندنی بودن** بررسی
   را تأیید کند. هیچ دسترسی یا credential در Git/PR/chat قرار نگیرد.
2. اپراتور از config/deployment inventory مورد اعتماد، ریشه واقعی افزونه را تعیین
   کند؛ `wp-content/plugins/sales-network-v4` فقط الگوی مسیر است. از `wp-cli`، PHP
   bootstrap، پنل وردپرس، activation، repair یا query دیتابیس برای این کار استفاده نشود.
3. یک snapshot موجود و immutable/read-only از همان مسیر به کار رود. تهیه snapshot
   یا انتقال فایل از host عملیات مستقل تحت اختیار مالک است؛ در R2 دستور اتصال،
   mount، copy زنده یا تغییر Production اجرا نمی‌شود. فایل executable به‌عنوان کد اجرا نشود.
4. operator provenance، زمان و release identifier شاهد را ثبت کند. root یا فایل/پوشه
   symlink را دنبال نکند؛ توقف و رفع ابهام با اپراتور لازم است. فایل ناخوانا حذف یا
   از نتیجه پنهان نشود. snapshot باید از یک وضعیت سازگار زمانی باشد.
5. علاوه بر hash مسیرهای مورد انتظار، inventory کامل relative از فایل‌های snapshot
   تهیه کند تا فایل اضافی یا runtime خارج از مسیرهای معمول پنهان نماند. برای فایل
   ناشناس/حساس ابتدا فقط metadata را در evidence محدود نگه دارد؛ محتوای receipt،
   config، log، backup یا فایل مشتری را به Git انتقال ندهد.
6. hash فایل‌های مجاز runtime را با manifest ثابت مقایسه کند. فایل تغییرکرده، کم‌شده
   و اضافه‌شده جدا گزارش شوند. فایل اجرایی اضافه در ریشه یا هر زیرپوشه نیز نیازمند
   بررسی است؛ صرف محدودشدن جستجو به سه پوشه، inventory کامل نیست.
7. در صورت live-read ناگزیر و مجاز در مأموریت آینده، metadata قبل/بعد و تکرار scan
   باید تغییر حین hash را آشکار کند؛ نتیجه ناپایدار `BLOCKED` است. مسیر ترجیحی
   snapshot فقط خواندنی است تا حتی اثر filesystem مانند atime به source زنده تحمیل نشود.
8. فقط نتیجه redacted شامل SHA مبنا، زمان، دامنه، تعداد match/missing/changed/extra،
   استثناهای تصویب‌شده و تأیید اپراتور/مالک به گزارش مشترک منتقل شود. raw hashها و
   نام فایل‌های حساس احتمالی را قبل از انتشار بازبینی کنید.

## نمونه بررسی محلی، نه اتصال به Production

پیش‌نیاز: checkout صحیح مبنا، manifest آماده، Python 3.10+ و یک **snapshot از قبل
تهیه‌شده و فقط خواندنی**. اپراتور مقدار واقعی را به `CRM_SNAPSHOT_ROOT` می‌دهد؛
مسیر زیر placeholder است و نباید بدون تعیین root واقعی اجرا شود. این مثال هیچ
اتصال شبکه، WordPress load یا write در snapshot ندارد. خروجی اولیه باید در فضای
محدود اپراتور بماند؛ نهایی‌کردن inventory اضافه‌ها و provenance همچنان لازم است.

```bash
CRM_SNAPSHOT_ROOT=/absolute/path/to/already-approved-read-only-plugin-snapshot \
python3 - <<'PY'
import hashlib, os
from pathlib import Path, PurePosixPath

root = Path(os.environ['CRM_SNAPSHOT_ROOT'])
if not root.is_absolute() or root.is_symlink() or not root.is_dir():
    raise SystemExit('STOP: confirm the approved snapshot root')
if any(p.is_symlink() for p in [root, *root.parents]):
    raise SystemExit('STOP: symlink in snapshot root path')
root = root.resolve(strict=True)
manifest = Path('docs/audit/r2/RUNTIME_SHA256SUMS-2.0.235.txt')
expected = {}
for line in manifest.read_text().splitlines():
    digest, name = line.split('  ', 1)
    rel = PurePosixPath(name)
    if rel.is_absolute() or '..' in rel.parts or len(digest) != 64:
        raise SystemExit('STOP: invalid expected manifest')
    expected[name] = digest
if len(expected) != 116:
    raise SystemExit('STOP: wrong expected baseline')

# Inventory first; do not read unknown file contents or follow symlinks.
actual = set()
def visit(directory):
    with os.scandir(directory) as entries:
        for entry in entries:
            p = Path(entry.path)
            if entry.is_symlink():
                raise SystemExit('STOP: symlink in snapshot inventory')
            if entry.is_dir(follow_symlinks=False):
                visit(p)
            elif entry.is_file(follow_symlinks=False):
                actual.add(p.relative_to(root).as_posix())
            else:
                raise SystemExit('STOP: non-regular snapshot entry')
visit(root)
missing = sorted(set(expected) - actual)
changed = []
for name in sorted(set(expected) & actual):
    p = root / name
    before = p.stat()
    h = hashlib.sha256()
    with p.open('rb') as stream:
        for chunk in iter(lambda: stream.read(1024 * 1024), b''):
            h.update(chunk)
    after = p.stat()
    if (before.st_ino, before.st_size, before.st_mtime_ns) != (after.st_ino, after.st_size, after.st_mtime_ns):
        raise SystemExit('STOP: snapshot changed while hashing')
    if h.hexdigest() != expected[name]:
        changed.append(name)
runtime_extra = sorted(name for name in actual - set(expected)
                       if name.split('/')[0] in {'assets', 'includes', 'templates'}
                       or Path(name).suffix.lower() in {'.php', '.phtml', '.phar', '.js'})
other_extra_count = len(actual - set(expected) - set(runtime_extra))
print({'matched': len(expected) - len(missing) - len(changed),
       'missing': missing, 'changed': changed, 'runtime_extra': runtime_extra,
       'other_files_requiring_classification': other_extra_count})
if missing or changed or runtime_extra or other_extra_count:
    raise SystemExit('REVIEW: mismatch or unclassified files; no automatic cleanup')
print('Runtime byte parity only; provenance and owner sign-off still required')
PY
```

read-only/immutable بودن snapshot از این اسکریپت استنباط نمی‌شود؛ شرط محیطی اپراتور
است. این نمونه fail-closed است: حتی docs/testهای قابل انتظار باید جدا با بسته اصلی
یا inventory استقرار **طبقه‌بندی** شوند. در یک snapshot شامل docs، پیام REVIEW
به‌خودی‌خود به معنی تغییر مخرب runtime نیست. هیچ extra را کورکورانه allowlist نکنید.

## نتیجه‌گیری و توقف

| نتیجه شاهد آینده | وضعیت | اقدام |
|---|---|---|
| runtime کامل برابر، inventory اضافه‌ها مستند، provenance و مسیر تأییدشده | PASS | مالک تطبیق همان snapshot و زمان را امضا کند؛ اثبات سلامت DB/امنیت نیست |
| فایل runtime متفاوت/کم‌شده یا executable اضافه تأییدنشده | FAIL | اختلاف را گزارش کنید؛ بدون overwrite، حذف، repair، merge یا redeploy |
| path/provenance/permission/symlink/پایداری snapshot نامعلوم | BLOCKED | نتیجه مساوی اعلام نشود؛ ابهام با اپراتور و مالک حل شود |
| شاهد Production تهیه نشده | NOT_RUN | وضعیت فعلی R2؛ ZIP رسمی با Production برابر فرض نشود |
