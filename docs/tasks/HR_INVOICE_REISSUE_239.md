# HR invoice reissue policy — owner task

Activity: authorized implementation, synthetic validation and preparation of 2.0.239.
Production installation is explicitly excluded. Publication/merge remain separate
workflow operations; this task file does not authorize them.

Owner authorization received 2026-11-19 after review of the local source packet:
public source/test publication, Draft PR and CI are approved; merge into main and
official RC are conditionally approved only after every required control passes.
Continue the already authorized public Staging test with that official artifact.
Never deploy to Production or weaken a failing security/test gate.

Owner approved the bounded CI extension and its public publication on 2026-11-19
after reviewing the exact local diff, safety tests and rollback plan. The existing run passed all four Finance
cases and mandatory syntax/docs/secrets/boundary jobs, but the readiness summary
has no verified job mapping for required UI/access/ledger/schema/MIS suites.
Approved additive scope: `.github/workflows/ci.yml`, `scripts/fast_track_ci.py`,
`scripts/hr_reissue_ci.py`, `scripts/test-hr-integration.sh`, the new
`tests/fast_track/test_hr_reissue_ci.py` and related `tests/hr/` adapters/tests.
No required suite is removed; the original mandatory jobs/Finance workflow stay
byte-identical. This mapping is bounded to the six reviewed runtime hashes and
base below. Publish on this task branch and the existing Draft PR; keep conditional
merge authorization subject to every required control. Native HTTP/MIS CI evidence
is NOT_RUN until execution. Production installation remains excluded.

Verified remote: https://github.com/mehrdadhafezi/CRMEdits.git
Base and intended PR target: main, `3660fe46dcb79f7a96f0b3f04326f119a4d78441`.
Compatibility reference: experimental, `3f544028bf83b641caade9e5859aa4efd4b3769a`.
Task branch: `codex/hr-repeat-invoice-policy`.

Owner decision: inheritance with exceptions. Sales manager, senior supervisor,
supervisor and seller each support inherit/allow/deny. The nearest explicit
setting wins; an entirely unset chain denies reissue. Configure from the HR panel
using cascading choices limited to the selected manager's current HR branch.
The setting controls ordinary seller issuance for the same seller and normalized
customer phone. Existing invoice status/product do not change duplicate identity.
Administrator and controlled-workflow exceptions remain as before.

Store only a new user-meta policy and existing HR audit records. Do not change
WordPress roles/capabilities, HR assignments, schema, invoice tokens, gateway,
receipt approval, wallet or commission logic. Transfer follows current HR
assignments; an explicit personal exception stays with that person. Missing,
inactive, ambiguous or cyclic hierarchy cannot grant the permission.

Allowed: a dedicated HR invoice-policy module and scoped assets; small bootstrap,
HR-panel and invoice-guard integration; version/build metadata; related contracts,
release guidance and synthetic tests. Extend the existing native test harness only
as necessary to exercise the feature. Do not rewrite or split the legacy core.

Acceptance: default denial; inherited allow/deny and personal overrides; live
transfer effects; skipped hierarchy levels; no cross-manager selection; actual
server enforcement independent of POST/UI; HR/admin authorization, nonce and
impersonation checks; strict inputs; stale-write protection; audited, failure-safe
updates; no unintended changes to financial rows, roles or other settings; mobile
and RTL UI; regression of existing Finance/reporting. Runtime tests require an
isolated synthetic WordPress/MySQL environment. Recheck current Staging isolation
and backup/restore before any authorized Staging deployment.

Rollback: old code ignores the new meta key and keeps its original duplicate
guard. Preserve policy/audit history; do not restore financial data as a code
rollback. No production mutation is part of this task.
