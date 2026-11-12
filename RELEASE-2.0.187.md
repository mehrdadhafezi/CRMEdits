# Sales Network V4 2.0.187-master-checklist-dev

Development build, 2026-10-29. Production acceptance is **NO-GO**.

This continues the actual 2.0.182 source through the local 2.0.186 QA snapshot. The missing 2.0.185 source/diff has not been reconstructed or assumed.

- Complete the actor-specific payment-state response, stage/owner/history explanations, preserved transfer/file drafts, and in-dialog unsaved confirmation.
- Add an independent Subscription Plus pre-payment editor: authorized product/cash/fixed-base-discount/plan/deposit changes, signed old/new preview, coherent transaction and audit, stale/concurrent request protection, strict immutable-state checks.
- Share the existing gateway stage lock and reject obsolete Plus gateway amounts before a provider request.
- Require current scope as well as ownership for ordinary and Plus pre-payment editing; former staff ownership alone cannot authorize editing.
- Correct manager overview distribution count to use the existing log-based counter, and make the existing product-card test use PYTHON_BIN.

No new schema migration, Customer ID allocation/cutover, CRM behavior, commission rates, or Invoice Review feature is introduced. Existing partial/full approval, fulfillment, and commission lifecycle services remain authoritative.

The engineering bundle contains the five audit reports, evidence, source comparisons, local test harness, and rollback guidance. Real historical upgrade data and provider testing remain required; see `audit/sales-network-v4-release-readiness-assessment.md` in the engineering bundle.
