# Unpaid gateway requests and manual receipts — 2.0.240

Owner task: fix gateway requests that have not been paid but indefinitely prevent
the seller from uploading a bank receipt. Prepare an update; do not install on
Production. Activity: Local Implementation / isolated synthetic validation.
Public delivery and merge require the owner's separate authorization.

Verified base: `b0f9db99be57411cae6f9497f95ea5b26730f99f`, canonical public
`mehrdadhafezi/CRMEdits`, target `main`. Branch:
`codex/fix-pending-gateway-receipt`. Comparison track:
`experimental` at `3f544028bf83b641caade9e5859aa4efd4b3769a`.

## Acceptance and scope

- An unpaid Zibal request is an intent, not an approved transfer or a missing receipt.
- Existing login, nonce, record scope, manual-payment setting, upload checks,
  stage amount limits and Finance capabilities remain enforced.
- Selecting manual payment is explicit and re-inquires every unresolved request.
  All responses must match the stored amount/order contract and be unpaid or
  one of the existing confirmed-terminal statuses; unknown/paid/error fails closed.
- The local `manual_hold` status preserves the live authority, amount and an
  atomic selection snapshot in existing `proof_data`. It does not cancel a bank
  request. No expiry by age, deleted history or false successful payment.
- Manual proof can then be captured for Finance. Approval re-inquires the held
  request and keeps the existing independent-receipt, amount and approval rules.
- A verified late callback is serialized with selection/submission. It preserves
  its bank reference as `reconcile_error`; Finance must reconcile it. It cannot
  automatically settle or credit the invoice/wallet a second time.
- New gateway requests are refused after selecting manual payment or while
  reconciliation is outstanding. Ordinary bank callbacks keep their current policy.

Allowed implementation paths: gateway guard, payment request lifecycle, transaction
labels, targeted gateway/controller/finalizer callers in the legacy core, receipt
review, their JS, version header, payment/invoice/API contracts and targeted tests.
No unrelated refactoring, migration, role/capability change, token change, wallet
algorithm rewrite, real provider call or Production record repair is authorized.

## Data and rollback

No new table/column/index is added. Safe method selection requires the existing
invoice/payment tables to use InnoDB. A rejected storage operation rolls back
both the local state and selection snapshot. The bank authority remains private
to the backend; internal scoped responses expose existing bank reference fields.

`manual_hold` is additive stored state. Before downgrading, inventory all selected
or reconciliation-required requests and coordinate Finance. An older version does
not implement this guard; blindly reinstalling old code while these requests are
live is not a safe financial rollback. Prefer a forward fix. Do not discard bank
rows, reset statuses or restore a database over later transactions as a shortcut.
The code-only preservation test is not proof of financial downgrade safety.

## Test requirements

Memory guard/inquiry/error regressions; native WordPress sessions/nonces and
own/foreign/guest access; actual MySQL rollback and concurrent PHP processes;
ordinary/late/repeated callbacks; Finance approval and received-money reports;
real multipart MIME rejection and PNG upload, desktop/mobile/RTL; preserved HR
reissue/Finance/reporting behavior; syntax, hygiene, secrets, scope and compatibility.
All data are synthetic and disposable. Canonical Staging owner's records, old
Staging and Production must remain unchanged during private validation.

Exact revision, environment, commands/results and remaining CI/release requirements
belong in the delivery evidence. A local validation ZIP is not an official RC.
