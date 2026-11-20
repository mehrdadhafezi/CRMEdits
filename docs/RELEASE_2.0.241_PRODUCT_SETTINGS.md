# 2.0.241 — Product SMS and optional custom discounts

Local implementation; not an official release or deployment authorization.

The product edit page gains a global invoice-link SMS switch, default on. It
applies to initial and repeated invoice-link messages, including Dot/Operations;
mixed invoices require all product switches on. Login OTP and payment receipts
keep their existing behavior. Administrators can explicitly enable and preview
historical sends, then confirm a bounded batch. Re-enabling alone sends nothing.
Only open, unpaid invoices with known unsent evidence qualify. Unknown legacy
history, accepted or ambiguous sends, and already paid/cancelled invoices do not.

The existing manager/product catalog can enable a custom per-unit discount in
toman, default off, alongside fixed choices. Server authorization and price bounds
remain authoritative; invoice totals, payment stage amounts and saved discount
snapshots agree. Product access and HR grants retain their existing boundaries.

SMS and other settings tabs now use an authenticated, bounded JSON payload for
the active tab. This avoids losing action/nonce to PHP max_input_vars on a large
multi-tab form and preserves unrelated settings. Existing form callers remain
compatible. A reproduced synthetic HTTP 400 is evidence for this failure mode;
it is not a fresh diagnosis of the Production host.

No schema migration, public-token change, role/capability grant, payment-provider
change or financial posting algorithm change. New delivery evidence is minimal,
non-autoload options. Full SMS payload logging was removed from the touched
sender path; provider/admin diagnostics retain their existing access boundaries.

Rollback needs review: an older release ignores product SMS/custom-discount
settings and the new evidence. Restoring code does not restore these controls or
undo already accepted messages. Existing invoice discount snapshots must remain
intact. Real SMS and Production installation are outside the current task.

See [task and validation scope](tasks/PRODUCT_INVOICE_SETTINGS_241.md).
