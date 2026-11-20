# 2.0.242 — invoice and receipt traceability

This candidate keeps the invoice code stable across installments of **new classic
Dot purchases**. Each real receipt has its own `PAY-<payment id>` reference and its
canonical `STG-<stage id>` reference. History and CSV exports associate receipts
with their invoice and actual stage; an unmatched provider row never invents a
local receipt identity. A separately issued purchase still has a separate code.

New Dot purchases use the existing adapter row marked `staged_receipt`; no new
table or column is introduced. Approved receipts release their own proportional
commission immediately, using frozen recipients/rates and the existing wallet
rules. Retried approvals cannot credit twice. A failed wallet write preserves
the approved receipt and exposes an authorized Finance retry action.

There is no historical invoice merge, financial reconciliation, table conversion
or automatic rewrite of old Dot cases. Old child invoices, their tokens and their
continuation behavior remain compatible; their physical invoice consolidation
requires a separately reviewed conversion. No new service-delivery/cancellation
business status is introduced.

## Installation prerequisites and recovery

Use only the official immutable Release Candidate tied to its merged commit,
manifest, SHA-256 and successful exact-revision CI, followed by testing those same
ZIP bytes on the isolated public Staging. A branch source ZIP is not that release.
Production installation requires its own explicit authorization and a current
compatibility review and consistent database/plugin backup.

The new purchase/receipt/wallet paths require transactional InnoDB tables and the
existing named/row locks. Login, nonce, capability, record scope and public invoice
tokens remain independent checks. No role/capability grant or token weakening is
part of the release. Existing export columns remain in place; trace columns are
appended, so consumers that assume a fixed column count must be checked.

**Code-only rollback does not restore business compatibility after new marked
purchases have been written.** Old code can preserve the stored rows without
understanding the new adapter semantics. Recovery must restore the matching
consistent database snapshot and plugin version, with writes fenced and all
receipts/provider events since that snapshot accounted for. Do not delete wallet
history, reassign receipts or merge customer invoices as a rollback technique.
Blindly restoring an old snapshot after accepting new payments can lose records.

## Reproducible proof

The additive CI mapping in `scripts/traceability_ci.py` accepts only the reviewed
runtime hashes and named task files from the pinned 2.0.241 base. All existing
four Finance matrix jobs and HR, product, wallet, schema, browser and MIS gates
remain mandatory. Additional proof covers 21 unit assertions, 50 native receipt
assertions, 30 controller security assertions, six real public controller
requests, two parallel-process races, and desktop/mobile escaping and retry UI.

`traceability-lifecycle.py` creates fresh synthetic WordPress namespaces from
both pinned main 2.0.241 and experimental 2.0.235. It checks legacy identities,
new receipts/commissions, a read-only code swap, resuming the candidate, full
database/plugin restore and repeated upgrade. It compares every preexisting
table outside its generated namespaces before/after. It is never a Production
repair tool and must not run on customer-data clones.

Actual completed checks, exact GitHub revisions, artifact identifiers and final
Staging results belong in the PR/delivery evidence. This document alone grants
neither merge nor installation permission.
