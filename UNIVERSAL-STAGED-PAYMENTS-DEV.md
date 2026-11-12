# Universal staged payment patch — Development Only

Base: 2.0.175-hr-filter-layout
Build: 2.0.176-universal-staged-payment-dev

## Scope
- Seller and converter invoice UI no longer force assessment / subscription_plus invoices to full payment.
- Main invoice handler now permits the existing partial-payment validation and payment-stage flow for all invoice-eligible products.
- DOT invoice context no longer rejects partial plans for assessment / subscription_plus.
- Preserves one-product DOT restriction, quantity restrictions, approved-payment state transitions, financial approvals, and full-settlement activation gate.
- Partial deposit must be > 0 and <= server-calculated total; equality normalizes to full payment.

## Safety evidence from static inspection
- `sn_invoice_paid` fires only after `completed` is true in `class-sn-plugin.php` payment-stage approval routine.
- `SN_Subscription_Plus::source_verified` requires invoice `paid/approved`, completed workflow, <= 0.5 outstanding balance, and paid total >= expected price.
- No historical data migration; no production writes performed.

## Tests executed
- php -l: three changed PHP files PASS.
- node --check: two changed JS files PASS.
- Static assertions for removed product restrictions PASS.

## Not yet validated — deployment blockers
- Complete real workflow integration testing in a non-production WordPress / WooCommerce staging instance.
- Manual, Zibal, Zarinpal and callbacks including duplicate and concurrent payment approvals.
- Assessment DOT SMS and downstream operations only after full settlement.
- Subscription+ with/without cash option, and with/without conversion case.
- Existing product and invoice editing flows, refund/archive/cancel, historical invoices.
- This is NOT a production-approved release. DO NOT upload into live CRM without staging acceptance and formal business sign-off.

## Rollback
1. Save the exact pre-deployment plugin ZIP and database backup.
2. On staging only, deactivate patched build and restore original version 2.0.175 plugin files; avoid database rollback unless changes were made beyond this patch.
3. Verify normal invoices, finance approval and gateway callbacks.

No DB schema changes introduced.
