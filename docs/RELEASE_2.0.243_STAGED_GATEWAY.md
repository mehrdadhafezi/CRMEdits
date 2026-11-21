# 2.0.243 — independently recorded gateway stages, stable purchase invoice

The 2.0.242 new classic Dot callback could verify the provider successfully but
leave its natural pending request unreconciled: its stage finalizer expected a
verified receipt that the callback had not persisted. The previous online test
started from a verified fixture and missed this boundary.

The callback now carries the verified authority into the existing transaction.
For marked staged Dot purchases it locks and checks the exact invoice, stage,
provider, requested amount and reference, persists that receipt, and commits it
with the stage and invoice projection. A conflicting replay cannot demote posted
money or overwrite its bank reference. A failed write rolls back the stage; the
verified bank reference is retained for reconciliation. Existing reconciliation
rows are not automatically retried or approved by this release.

The purchase keeps its original invoice ID, code and access token. Stage display
references and new Zibal order IDs are `invoice_code/1`, `invoice_code/2`, etc.
Each real request/receipt keeps a separate PAY ID and provider track. Multiple
attempts for one stage share the stage reference, not the transaction identity.
Reusing an unresolved request does not create another provider session.

The exact new order ID is stored in existing payment proof metadata. Historical
requests without it retain their original unsuffixed order ID for inquiry.
Reports resolve stage order aliases back to the purchase invoice while attaching
receipt identity only from its actual matched provider track. No DDL, role grant,
token change, historical invoice merging or financial backfill is introduced.

Seller payment management shows the paid stage separately from purchase totals.
A 10m purchase after a 5m stage reads: stage /1 paid, approved total 5m, outstanding
5m, purchase partial_paid. Only after another issued 5m stage does it settle.
The slash is a display/order reference and cannot authorize access to an invoice.

Tests include the actual pending request/provider callback, seller and foreign
seller controllers, two 5m stages, replay and late callbacks, malformed/mismatched
amount and reference, four SQL failure points, manual-selection conflicts,
historical orders, real parallel callback processes, and desktop/mobile runtime
UI. Retained Finance/HR/product/wallet/schema/MIS and both-track restore checks
remain required. Provider replies are synthetic; live provider availability and
real money are not exercised.

Rollback requires a coordinated DB/plugin snapshot and accounting for receipts
since that snapshot. Older code does not understand the new stored stage order
reference, so code-only continuation after creating such requests is unsafe.
Production installation and repair are separately authorized operations.
