# Finance receipt capacity and query performance

Activity: PR Delivery. Owner requested review of the newly installed
update, then an urgent fix for slow Finance lists and failing approve/reject.
No Production installation or historical financial repair is authorized here.

Verified canonical remote: https://github.com/mehrdadhafezi/CRMEdits.git.
Base/PR target: current main `5f922cca8b99736f3242e4ba3e192eeb1f245080` / main.
Installed source: version string 2.0.243; seven differing runtime members match
the official prepayment-edit commit `8361ba9` exactly; other installed runtime
members match current main. The newer bank-tracking changes on main are retained.
Branch: codex/finance-review-hotfix. Owner subsequently explicitly authorized
publication, GitHub checks, merge after successful checks and delivery of the
installation artifact. Staging deployment was explicitly waived. No Production
installation or data repair is authorized.

Allowed: receipt-review, catalog stage-normalization guard, Finance list SQL in
class-sn-plugin.php, directly related tests/contracts. Preserve scope, nonce,
separation of uploader/reviewer, bank checks, transaction locks, immutable receipt
IDs, commissions, unrelated product/SMS/tracking and reporting behavior.

Observed: a full first stage with two receipts can have its requested capacity
reduced to its unpaid balance. Receipt review then compares all approved receipts
with that reduced capacity, preventing both approval and rejection. Only a
provable full first-stage gross/net mismatch may be reconciled inside an existing
authorized receipt decision, with an audit event and rollback on failure.
Ambiguous partial stages and real overpayments must continue to fail closed.

Finance repeatedly computes correlated receipt predicates for lists, counts and
amounts. Replace them with one grouped ledger projection and grouped KPI sums;
preserve exact bucket priority, sorting, filters and fresh results without cache.

Required: actual synthetic WP/MySQL approve/reject/retry/rollback, negative amount
and authorization controls, existing approval/reporting regressions, old/new bucket
equivalence and meaningful query-count/performance evidence, syntax/secrets and
complete diff. CI not run until separately authorized publication.
