## Problem and resulting behavior

Related Issue:
Summary / acceptance criteria:
Files changed and allowed scope:

## Contract and risk

- API / compatibility changes:
- Database / migration changes and approval:
- Role / permission / scope changes and approval:
- Security review (no secrets or customer data):
- Regression risks and affected modules:
- Migration plan (or not applicable):
- Code rollback plan:
- Data rollback limits:
- Documentation updated:

## Validation evidence

| Command / scenario | Environment / commit | Result | Evidence / limitation |
|---|---|---|---|
| | | PASS / FAIL / BLOCKED / NOT_RUN / NOT_APPLICABLE | |

## Reviewer checklist

- [ ] Scope and module contract checked; unrelated edits excluded.
- [ ] Backend authentication, nonce, capability and record scope checked.
- [ ] No secrets, customer records, unsafe test target or unapproved side effects.
- [ ] Required CI passed; meaningful acceptance and regression evidence reviewed.
- [ ] Independent authorized approval; sensitive owner approval where needed.
- [ ] Conversations resolved; API/schema/role compatibility and rollback reviewed.
- [ ] Merge authorization confirmed separately. This PR does not authorize deployment.
