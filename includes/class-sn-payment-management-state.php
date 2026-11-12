<?php
if (!defined('ABSPATH')) { exit; }

/** Pure state mapping. Permissions and money remain authoritative in their existing services. */
final class SN_Payment_Management_State {
    public static function describe(object $invoice, array $totals, array $summary, ?object $referral, ?object $stage): array {
        $remaining = (float)$totals['remaining'];
        $due = (float)$totals['due'];
        $pending = (float)($summary['pending_amount'] ?? 0);
        $statuses = [(string)($invoice->status ?? ''), (string)($invoice->invoice_status ?? ''), (string)($invoice->payment_status ?? '')];
        $ref = (string)($referral->status ?? '');
        $closed = array_intersect($statuses, ['cancelled','payment_archived','archived']) || $totals['workflow_status'] === 'archived';
        $state = 'active_payment'; $next = 'manual_payment'; $description = 'مرحله فعال برای دریافت واریزی وجود دارد.';
        if ($closed) { $state='closed'; $next='history'; $description='فاکتور لغو یا بایگانی شده و پرداخت جدید مجاز نیست.'; }
        elseif ($remaining <= 0.5) { $state='fully_paid'; $next='history'; $description='فاکتور به‌طور کامل تسویه شده است.'; }
        elseif ($pending > 0.5) { $state='pending_review'; $next='history'; $description='واریزی‌های ثبت‌شده در انتظار بررسی مالی هستند.'; }
        elseif ($due <= 0.5 && $ref === 'needs_payment_assignment') { $state='assignment_required'; $next='assignment'; $description='مرحله قبلی تأیید شده است؛ مرحله پرداخت فعالی وجود ندارد. ابتدا مسئول ادامه پرداخت را تعیین کنید.'; }
        elseif ($due <= 0.5 && $ref === 'payment_assigned') { $state='stage_ready'; $next='stage'; $description='مرحله قبلی تکمیل و مسئول تعیین شده است؛ مسئول مجاز باید مرحله بعد را صادر کند.'; }
        elseif ($due <= 0.5) { $state='stage_not_issued'; $next='stage'; $description='مانده فاکتور مثبت است، اما مرحله پرداخت فعالی صادر نشده است.'; }
        elseif (array_intersect($statuses, ['rejected','financial_rejected','payment_rejected'])) { $state='rejected'; $description='واریزی قبلی رد شده است؛ دلیل رد و ظرفیت ثبت اصلاحی را بررسی کنید.'; }
        elseif ((float)$totals['paid'] > 0.5) { $state='partial_paid'; $description='بخشی از فاکتور تأیید شده و مرحله فعلی هنوز قابل پرداخت است.'; }
        else { $state='unpaid'; $description='هنوز پرداخت تأییدشده‌ای ثبت نشده؛ مرحله اول قابل پرداخت است.'; }
        return [
            'state'=>$state,'recommended_action'=>$next,'description'=>$description,'closed'=>(bool)$closed,
            'stage'=>['number'=>(int)$totals['stage_no'],'status'=>(string)($stage->status ?? 'not_found'),
                'type'=>(string)($stage->stage_type ?? ''),'amount'=>(float)($stage->requested_amount ?? 0),
                'active'=>!$closed && $remaining>0.5 && $due>0.5,
                'previous_completed'=>$due<=0.5 && in_array((string)($stage->status ?? ''),['paid','approved'],true),
                'next_number'=>(int)$totals['stage_no']+1]
        ];
    }
}
