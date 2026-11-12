# Merge notes — 2026-10-19

Base archive: sales-network-v41-customer-id-sequential (1).zip (user uploaded).

Preserved: sequential persisted aggregate customer ID (a00001, ...), aggregate search/export and registry, as provided in base archive.

Merged from subsequent operations/UI work: two operations panels' workspace tabs, tidy UI stylesheet, operation case payment/receipt section, matching Javascript, HR sales portal bulk controls, position filter static values, seller ordinary invoice duplicate lookup.

Corrections to intermediate builds: operation manual-payment requests use endpoint expected field names (entry_mode, card_from, card_to, paid_at), receipt mode submits to sn_submit_manual_payment, and operations assets localize nonce/ajaxurl.

LIMITATIONS:
- No live WordPress / database / browser runtime test performed.
- Duplicate seller invoice lookup is not atomic against truly simultaneous requests. It is a preliminary guard, not full concurrency protection.
- Current duplicate guard checks invoices belonging to that seller for the normalized customer mobile. It does not enforce a unique phone across all seller accounts.
- Existing AJAX access checks still apply to operations personnel. The payment form requires a valid authorized internal invoice context.
- Existing static audit reports six unrelated/inherited checks as failures (see external report); PHP and JS syntax checks pass.
- Deploy on staging first; take a complete backup.
