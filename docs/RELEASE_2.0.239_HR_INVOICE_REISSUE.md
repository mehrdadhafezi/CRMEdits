# 2.0.239 — HR hierarchical invoice reissue policy (unreleased)

Owner-approved behavior: resources staff can configure reissue for the same
seller/customer phone in **پنل منابع انسانی → صدور مجدد فاکتور**. Choose sales
manager, seniors, supervisors and sellers from the current branch. Each level has
**طبق بالادست / مجاز / غیرمجاز**; the nearest explicit setting wins. The default
prevents duplication. Saving a manager applies through inheritance without
rewriting descendant settings; explicit exceptions survive transfers.

Existing invoice statuses/products do not change duplicate identity. Ordinary
seller authorization, source ownership, manual-issuance permission, product
eligibility and existing admin/controlled-flow exceptions remain. No invoice
token, financial calculation, receipt approval or report behavior is replaced.

Storage is one new user-meta key plus existing HR audit records. No new tables,
role/capability grants, hierarchy changes or data repair. Atomic settings saves
require InnoDB for users, usermeta, HR profiles, assignments and audit logs; a
nontransactional target is rejected safely without automatic conversion. Exact
Production engine compatibility has not been rechecked for this new feature.

See [the task and approved base](tasks/HR_INVOICE_REISSUE_239.md),
[API contract](API_CONTRACTS.md#hr-invoice-reissue-policy-20239) and
[test scenarios](../tests/hr/README.md). A source validation ZIP is not an official
Release Candidate. Publication, GitHub CI, separately authorized merge and the
same official ZIP on canonical public Staging are release gates still to record.
Production installation is not authorized by this task.

Code rollback to 2.0.238 ignores the new meta and restores the previous duplicate
prevention behavior. Retain settings and audit history; code rollback is not a
financial/database restore. Do not roll back invoice data created legitimately
while reissue was allowed.
