<?php
if (!defined('ABSPATH')) { exit; }

/** User-facing recovery messages shared by forms, AJAX and resumable work. */
final class SN_MIS_Feedback {
    public static function error(Throwable $error): array {
        $message=trim($error->getMessage());
        $rules=[
            'شماره‌ای مطابق'=>['no_matches','شماره‌ای برای بررسی پیدا نشد','فیلترها را کمتر کنید یا آماده‌سازی آمار را از «پردازش‌ها و تنظیمات» کامل کنید.',''],
            'کد فایل'=>['file_reference_mismatch','کد فایل و پرونده یکسان نیست','به انتخاب پرونده برگردید و پرونده‌ای با همان کد فایل انتخاب کنید؛ کد داخلی مشتری را به کد فایل متصل نکنید.','batch_id'],
            'پیش‌نمایش'=>['preview_expired','پیش‌نمایش را دوباره بسازید','ابتدا نتیجه را در سابقه بررسی کنید. اگر ثبت نشده است، با همان فیلترها پیش‌نمایش تازه بسازید.','preview_token'],
            'دلیل'=>['reason_required','دلیل عملیات را بنویسید','یک توضیح کوتاه بنویسید تا دلیل این تغییر در سابقه بماند.','reason'],
            'شمسی'=>['jalali_date_invalid','تاریخ شمسی را اصلاح کنید','نمونه تاریخ: ۱۴۰۵/۰۸/۱۰. برای زمان منبع از ۱۴۰۵/۰۸/۱۰ ۱۴:۳۰ استفاده کنید.','source_datetime_raw'],
            'تاریخ'=>['date_invalid','بازه تاریخ را بررسی کنید','تاریخ شروع و پایان را به شمسی وارد کنید؛ تاریخ پایان نمی‌تواند قبل از شروع باشد.','from'],
            'نگاشت'=>['mapping_invalid','تطبیق ستون‌ها کامل نیست','ستون شماره تماس باید مشخص باشد و هر فیلد فقط به یک ستون متصل شود.',''],
            'ستون'=>['columns_invalid','ستون‌های فایل را بررسی کنید','عنوان ستون‌ها باید یکتا باشد. ستون شماره تماس را در مرحله تطبیق مشخص کنید.',''],
            'شماره تماس'=>['phone_invalid','شماره تماس را اصلاح کنید','شماره موبایل ایران را با ۰۹ وارد کنید. از تغییر شماره دارای سابقه تخصیص یا مصرف خودداری کنید.','normalized_phone'],
            'فایل'=>['file_invalid','فایل قابل پردازش نیست','فقط CSV یا XLSX تا ۵ مگابایت و ۱۰هزار ردیف قابل ورود است. فایل اصلاح‌شده را دوباره انتخاب کنید.','mis_csv'],
            'مدیر'=>['manager_unavailable','مدیر فروش را بررسی کنید','مدیر باید فعال و دارای سمت فروش باشد. اگر فهرست تغییر کرده است، صفحه را تازه کنید.','target_manager_id'],
            'شعبه'=>['branch_required','شعبه تخصیص را انتخاب کنید','شعبه همین تخصیص در تاریخچه ثبت می‌شود. اگر شعبه‌ای نمی‌بینید، مدیرکل باید آن را اضافه کند.','assignment_branch'],
            'پرونده'=>['case_unavailable','پرونده را بررسی کنید','یک پرونده فعال انتخاب کنید. پرونده بایگانی‌شده برای ورود و تخصیص جدید قابل استفاده نیست.','batch_id'],
            'عملیات دیگری'=>['writer_busy','عملیات دیگری در حال ثبت است','چند لحظه بعد دوباره تلاش کنید. این پیام مجوز ثبت هم‌زمان دوباره نیست.',''],
            'InnoDB'=>['storage_unavailable','ثبت امن فعلاً در دسترس نیست','مدیر سامانه باید موتور جدول‌ها را بررسی کند. تبدیل خودکار جدول یا حذف سابقه انجام نشد.',''],
            'راه‌اندازی'=>['setup_required','آماده‌سازی پرتال لازم است','از «پردازش‌ها و تنظیمات» راه‌اندازی را ادامه دهید؛ این کار شماره‌های قبلی را حذف نمی‌کند.',''],
            'پایگاه داده'=>['database_failed','عملیات کامل نشد','ابتدا نتیجه را در سابقه بررسی کنید. اگر ثبت نشده است، دوباره تلاش کنید؛ تکرار پیام را به مدیر سامانه اطلاع دهید.',''],
            'تأیید'=>['confirmation_required','ثبت نهایی تأیید نشده است','خلاصه عملیات را بررسی کنید و گزینه تأیید را علامت بزنید.','confirm'],
            'مصرف'=>['record_protected','این رکورد قابل تغییر نیست','شماره مصرف‌شده یا دارای سابقه تخصیص را نمی‌توان از این مسیر آزاد یا جایگزین کرد. سابقه را بررسی کنید.',''],
            'متعلق'=>['not_owned','این پردازش در دسترس شما نیست','فقط صاحب پردازش می‌تواند آن را ادامه دهد یا فایلش را دریافت کند.',''],
            'منقضی'=>['expired','مهلت این مورد تمام شده است','فایل خروجی یا پیش‌نمایش تازه بسازید؛ داده عملیاتی قبلی حذف نشده است.','']
        ];
        foreach($rules as $needle=>$rule) {
            if(str_contains($message,$needle))return ['code'=>$rule[0],'title'=>$rule[1],'message'=>$message,'hint'=>$rule[2],'field'=>$rule[3]];
        }
        if($message!=='' && preg_match('/[\x{0600}-\x{06FF}]/u',$message) && mb_strlen($message)<500) {
            return ['code'=>'validation_failed','title'=>'اطلاعات را بررسی کنید','message'=>$message,'hint'=>'ورودی‌ها را اصلاح کنید و دوباره تلاش کنید. اطلاعات فرم تا آن زمان حفظ می‌شود.','field'=>''];
        }
        $reference=strtoupper(substr(hash('sha256',microtime(true).random_bytes(8)),0,10));
        error_log('SN MIS error ['.$reference.'] '.get_class($error).': '.$error->getMessage());
        return ['code'=>'internal_error','title'=>'عملیات کامل نشد','message'=>'پاسخ معتبر از سامانه دریافت نشد.','hint'=>'پیش از تکرار ثبت، نتیجه را در تاریخچه بررسی کنید. شناسه پیگیری: '.$reference,'reference'=>$reference,'field'=>''];
    }
    public static function session(): array { return ['code'=>'session_expired','title'=>'اعتبار صفحه تمام شده است','message'=>'برای ادامه، صفحه را تازه کنید.','hint'=>'قبل از تازه‌سازی، متن‌های واردشده را نگه دارید. پس از ورود دوباره، پیش‌نمایش تازه بسازید.','requires_reload'=>true,'field'=>'']; }
    public static function is_ajax(): bool { return strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH']??''))==='xmlhttprequest'; }
    public static function jalali_datetime(string $raw): array {
        $value=strtr(trim($raw),array_combine(preg_split('//u','۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩',-1,PREG_SPLIT_NO_EMPTY),str_split('01234567890123456789')));
        $value=preg_replace('/\s+/u',' ',$value);
        if($value==='')return ['display'=>'','utc'=>null,'raw'=>$raw];
        if(!preg_match('~^(1[2-5][0-9]{2})[/-]([0-9]{1,2})[/-]([0-9]{1,2})[ T]([0-9]{1,2}):([0-9]{2})(?::([0-9]{2}))?$~',$value,$m))throw new RuntimeException('تاریخ و ساعت منبع باید شمسی باشد؛ نمونه: ۱۴۰۵/۰۸/۱۰ ۱۴:۳۰.');
        $day=sprintf('%04d/%02d/%02d',(int)$m[1],(int)$m[2],(int)$m[3]);$greg=SN_Helpers::jalali_to_gregorian_date($day);
        if(!$greg || SN_Helpers::gregorian_to_jalali_input_value($greg)!==$day || (int)$m[4]>23 || (int)$m[5]>59 || (int)($m[6]??0)>59)throw new RuntimeException('تاریخ یا ساعت شمسی معتبر نیست؛ روز ماه و ساعت را بررسی کنید.');
        $display=$day.' '.sprintf('%02d:%02d',(int)$m[4],(int)$m[5]).(isset($m[6])?':'.$m[6]:'');
        $dt=new DateTimeImmutable($greg.' '.sprintf('%02d:%02d:%02d',(int)$m[4],(int)$m[5],(int)($m[6]??0)),new DateTimeZone('Asia/Tehran'));
        return ['display'=>$display,'utc'=>$dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),'raw'=>$raw];
    }
}
