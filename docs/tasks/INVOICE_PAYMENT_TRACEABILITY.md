# Invoice, stage and receipt traceability — local implementation

Owner decision: an independently issued invoice retains its own invoice code.
Further installments of that purchase do not create another invoice. A receipt
or gateway attempt has a separate identity, linked to the invoice and stage.
No business deduplication or automatic merging of historical invoices is allowed.

Base: main `dac9617874b5d0a53e7f7b5c1fde3e8066402761`.
Branch: `codex/invoice-payment-traceability`; prospective target: main.
The owner authorized public commit, push, a Draft PR, and completion of security,
compatibility, rollback and CI validation on 2026-11-20. No release version or
installable RC is certified yet. This authorization does not install Production.
Production and public Staging sites are outside the mutation scope.

## Identifiers and read contract

- `invoice_code` and invoice id remain the invoice identity; tokens are unchanged.
- `transaction_code=PAY-<sn_payments.id>` identifies a real existing payment row.
  Pending, verified, approved and rejected statuses do not change that identifier.
  An attempt is not evidence of bank settlement; existing status remains visible.
- `stage_reference=STG-<sn_invoice_payment_stages.id>` identifies an existing
  stage. Multiple receipts can reference the same stage. Missing legacy stage
  rows remain missing; no synthetic stage id is fabricated.
- `stage_count` counts recorded stages, including pending/rejected ones, rather
  than assuming the maximum stage number equals the count.
- `transaction_count` counts all recorded payment rows, including attempts.
- Invoice-only financial confirmations retain an empty transaction code and an
  `INV-<invoice id>` record reference explicitly labelled as a summary. They are
  not invented receipts and do not prove bank settlement.

These additive fields enrich existing authorized transaction/history responses
and invoice/payment/successful-payment CSVs. Gateway CSV appends its columns after
the existing columns. Provider rows without an exact local track match never
borrow a receipt or current stage from another transaction on the same invoice.
Existing report limits, amounts, units, approval semantics and role gates remain.
Payment CSV now honors the existing seller/date filters and literal invoice-code
or exact PAY-id search. No additional endpoint or role is introduced.

## Classic Dot forward model

Ordinary, cash-credit and Operations next-stage paths already reuse invoice id.
Classic Dot previously created physical invoices for individual installments.
For a **new Dot purchase with no previous payment adapter**, a full-price invoice
now owns the ordinary canonical payment stages. Its first due can be partial.
The existing unique Dot payment row remains a current-stage adapter, not a bank
receipt. `sn_dot_invoice_links.payment_kind='staged_receipt'` is the immutable additive
storage marker; `flow_kind='conversion_payment'` remains unchanged. No DDL.

Further stages reuse id/code/token under the shared invoice lock and case-scope
checks. Invoice/stage/adapter updates roll back together. Finance projects the
canonical paid total into the case within receipt/finalization transactions, then
existing hooks can safely replay. Product membership still waits for full completion.

Owner explicitly selected commission **per approved receipt**, without waiting
for full invoice or stage settlement, for these new Dot purchases. Frozen rates,
recipients, wallet family and calculation formulas remain; idempotency includes
the real payment id rather than using the stage as the credit identity. Wallet
metadata preserves both invoice/stage and PAY-id. Only approved/paid rows qualify;
pending, rejected and merely verified provider attempts do not qualify.

The new path serializes an invoice with a named lock and writes all wallet effects
inside an InnoDB transaction. A wallet failure retains the already committed
receipt decision, returns explicit decision_saved/commission_pending, and can be
retried by authorized Finance without another approval or duplicate commission.
Ordinary, cash, Operations and legacy Dot commission timing remains unchanged.
Future service-received/collected/cancelled status policy is explicitly deferred;
this task does not introduce those new business statuses or eligibility rules.

Existing legacy Dot child invoices, approvals, tokens and commission records are
**not converted**. Their old continuation behavior remains a compatibility gap;
this patch does not certify that every future action on an old case uses a single
physical invoice. Such a conversion needs a separate reviewed data/financial plan.
The trace identifiers do apply to actual existing receipt records on those invoices.

## Security and rollout limits

Login, nonce, capability, record scope and public token checks retain their
existing independent gates. SQL values are prepared; ids in trace queries come
only from the caller's authorized result set. Trace metadata writes nothing.
Database failures or ambiguous stage identities fail closed, with generic errors.
New purchases require transactional Dot/invoice/stage/item tables; this is a
preflight requirement, not permission to convert Production tables.

Code-only business rollback to 2.0.241 after writing `staged_receipt` cases is
**unsupported**: old code does not implement the adapter's new meaning. Synthetic
lifecycle tests rehearse preservation during a read-only code swap, resuming the
candidate and full recovery of a consistent matching database/plugin snapshot.
They also test both pinned main and experimental baselines. Do not delete or
remap payment/commission history to roll back. Account for payments accepted
since the backup before restoring it; a stale snapshot can discard those records.

## Validation

Unit: `php -n -d extension=sqlite3 tests/payments/traceability.php` (with the
platform extension directory configured). Native:
`tests/payments/traceability-native.php`, using `tests/hr/native-support.php` and
the explicitly guarded disposable synthetic WordPress namespace. Browser:
`tests/payments/traceability-ui.cjs` exports a runner accepting the installed
Playwright module path and browser channel; all network requests are blocked.

The private native test covers actual multi-receipt approval, same-code next
stage, SQL failure rollback, denied unrelated actor, stale stage, commission
amount/replay, gateway reference matching and CSV association/injection checks.
The additive runner also requires real parallel commission/stage races, actual
public token requests, endpoint security, browser and full upgrade/restore proof.
These do not replace exact-head GitHub CI and official artifact/Staging evidence.
Merge remains a separate operation requiring its explicit authorization and final
exact-revision checks. See `docs/RELEASE_2.0.242_TRACEABILITY.md` for deployment
limits and recovery requirements.
