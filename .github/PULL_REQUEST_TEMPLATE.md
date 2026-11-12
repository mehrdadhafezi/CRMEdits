## Problem and resulting behavior

Related Issue:
Summary / acceptance criteria:
Files changed and allowed scope:
Approved base branch / base SHA:
Latest PR head SHA and revision tested:

## Contract and risk

- API / compatibility changes:
- Database / migration changes and approval:
- Role / permission / scope changes and approval:
- Business logic changes and dependent modules/callers:
- Security review (no secrets or customer data):
- Regression risks and affected modules:
- Migration plan (or not applicable):
- Code rollback plan:
- Data rollback limits:
- Documentation updated:

## Validation evidence

Follow docs/TESTING_STANDARDS.md. PASS requires an executed check. N/A needs a
change-specific reason for each unrelated control; unavailable required test
infrastructure is BLOCKED/NOT_RUN, not N/A. Include exact commands, environment,
revision, output/assertions and redacted evidence. List tests added for a new
behavior or bug fix, affected paths, and any coverage gaps.

| Test | Status | Evidence |
|---|---|---|
| Syntax | PASS/FAIL/BLOCKED/NOT_RUN | |
| Documentation / Hygiene | PASS/FAIL/BLOCKED/NOT_RUN | |
| Secret Scan | PASS/FAIL/BLOCKED/NOT_RUN | |
| Security | PASS/FAIL/BLOCKED/NOT_RUN/N/A | |
| Functional | PASS/FAIL/BLOCKED/NOT_RUN/N/A | |
| Regression | PASS/FAIL/BLOCKED/NOT_RUN/N/A | |
| Scope/Diff | PASS/FAIL | |
| API/DB/Role Compatibility | PASS/FAIL/BLOCKED/NOT_RUN/N/A | |
| GitHub CI / Pre-Merge | PASS/FAIL/BLOCKED/NOT_RUN | Head SHA, run/job URL; update after push |

Security applicability: authentication, authorization, role/capability, record/API
access, nonce/CSRF, SQL injection, XSS, input validation, output escaping, uploads,
sensitive exposure, secret scanning and privilege escalation. Record results or
specific N/A reasons; static review is not a substitute for required runtime tests.

Final task status (select one; explain all blockers):
`READY FOR REVIEW` / `BLOCKED — TEST FAILURE` / `BLOCKED — MISSING REQUIRED TEST` /
`BLOCKED — UNRELATED CHANGES` / `BLOCKED — SECURITY RISK`

Required failed, blocked or unrun tests prohibit READY FOR REVIEW. Use an explicitly
incomplete Draft PR when needed. READY FOR REVIEW is not approval or merge authority.

## Pre-PR checklist

- [ ] Full task/base diff, staged/unstaged changes and new files reviewed.
- [ ] Accidental edits, formatting and unrelated deletions resolved; others' work preserved.
- [ ] Syntax, documentation/hygiene and worktree/history secret scans executed.
- [ ] Relevant security, functional and dependent-path regression tests executed.
- [ ] Existing tests identified; new regression coverage or its gap explained.
- [ ] API/DB/roles/permissions/business-logic compatibility assessed.
- [ ] All results and N/A/NOT_RUN/BLOCKED reasons recorded; no required test gap hidden.

## Pre-Merge reviewer checklist

- [ ] Scope and module contract checked; unrelated edits excluded.
- [ ] Backend authentication, nonce, capability and record scope checked.
- [ ] No secrets, customer records, unsafe test target or unapproved side effects.
- [ ] Required CI passed; meaningful acceptance and regression evidence reviewed.
- [ ] CI belongs to the exact latest PR head; tested merge-ref parents/base checked where relevant.
- [ ] Final diff/head rechecked after push; no untested later changes, conflicts or effective base updates.
- [ ] Relevant tests rerun after code changes; required failures/gaps and security risks resolved.
- [ ] Independent authorized approval; sensitive owner approval where needed.
- [ ] Conversations resolved; API/schema/role compatibility and rollback reviewed.
- [ ] Merge authorization confirmed separately. This PR does not authorize deployment.

**No verified tests, no approval. No approval, no merge.**
