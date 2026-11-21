# Staged gateway receipt correction — 2.0.243

Owner scope: fix verified stage payments and seller visibility while retaining the
purchase invoice code; display stage aliases as invoice_code/stage_no; test on
independent synthetic Staging and prepare an installable candidate. Inspect the
reported live payment read-only. No Production mutations, repairs or installation.

Mode: implementation. Verified public remote: mehrdadhafezi/CRMEdits.
Base main: 12392d4bf195be0e6c4cf07d127c327de4117d4d. Target: main.
Branch: codex/staged-gateway-receipts. Experimental comparison baseline must be
recorded from fetched origin/experimental before compatibility tests.

Allowed runtime: payment callback/finalizer in class-sn-plugin.php;
class-sn-invoice.php request/inquiry identity; class-sn-payment-gateway-guard.php;
class-sn-payment-transactions.php read projections; receipt/transaction UI;
sales-network.php version. Related tests, CI applicability/evidence and module/API
contracts are in scope. No schema, roles, public token changes or broad core edits.

Observed on 2.0.242: normal new classic Dot gateway requests start pending, but
its finalizer requires verified + matching ref_id. Successful provider callback
does not bridge this transition. Synthetic 10m invoice/5m stage callback failed.
No real customer data belongs in this task, its tests, PR, CI or fixtures.

Acceptance: actual pending request -> successful mocked provider callback ->
paid first stage, invoice partial_paid 5m paid/5m remaining; seller reads this
without Finance capability. Second 5m stage keeps invoice id/code/token and
completes once. Invoice/stage aliases /1 and /2 are additive display/order
references, not additional purchase invoices or public access credentials.
PAY references remain unique per real payment attempt.

Preserve legacy order IDs for pending historical requests and query their stored
identity. Fresh stage order reference must survive inquiry, callback, reporting
and retry. Reusing an unresolved request must not issue another bank session.
Replay, delayed callbacks, forged stage/reference/amount, foreign invoice,
manual-selection conflict, cancelled/rejected state and injected SQL failures
must never double-count or lose approved money/commission.

Required verification: syntax/docs/secrets, memory unit, actual guarded WP/MySQL
public callback and seller access, browser desktop/mobile, concurrent callbacks,
retained financial/HR/product/wallet/MIS, both-track upgrade/restore, exact-head CI
and official package provenance before stable RC. No fixture on canonical Stage
records or Production. Keep preexisting Stage data and restore-test its backup.

Existing live reconcile_error rows are diagnostic only; this task grants no
automatic repair, new verification call, or balance mutation in Production.
Code rollback does not reverse financial events. Preserve coordinated DB/plugin
backups and account for receipts since the snapshot before any authorized repair.
