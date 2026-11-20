# Product invoice SMS, custom catalog discount and settings transport (2.0.241)

Activity: PR Delivery. Owner chat task, 2026-11-20. After reviewing the concrete
local source, full diff, tests and limited CI proposal, the owner authorized public
Commit/Push/Draft PR and conditional merge, official RC and independent Staging
validation after all required exact-revision checks pass. Production deployment,
real SMS and changes to provider credentials/protocol remain outside this task.

Verified canonical public remote: https://github.com/mehrdadhafezi/CRMEdits.git.
Selected stable base: `3e5ea9a950b8ec0c936ba4188ddb6118fe3b1cc6`, current main,
verified with GitHub and fetch before editing. Target for a future authorized PR:
main. Local branch: `codex/product-invoice-sms-discounts` in an independent worktree.
Existing checkouts were clean and are preserved.

Owner decisions:
- Product SMS switch is global across the CRM, on the product edit page. Preserve
  existing send behavior by default. Enabling does not silently start bulk sends.
- Optional historical sends include only open, unpaid pre-invoices with known
  unsent history, preview/count and separate explicit confirmation. Exclude
  accepted, ambiguous/in-flight, cancelled, paid or unknown legacy sends. Recheck
  eligibility and all products in a mixed invoice before each send.
- Custom per-unit discount is enabled per manager/product alongside fixed amounts
  on `sn-sales-catalog`; default off. Currency: toman. Keep fixed choices and
  enforce permission, current server price, bounds, totals and immutable snapshots.
- Investigate/fix HTTP 400 while saving settings such as SMS. Current code sends
  all non-invoice tabs as form fields and appends action/nonce last; truncation by
  max_input_vars was reproduced through real synthetic WordPress HTTP (400).
  The bounded JSON save succeeded with the same PHP limit of 1000. This does not
  claim a fresh diagnosis of the Production host.

Allowed: targeted catalog PHP/admin/staff JS; invoice-SMS policy service and product
UI; targeted canonical/Dot/operations invoice-SMS callers; generic settings save
handler/admin JS; version, relevant contracts and synthetic tests. No unrelated
formatting/refactoring, financial posting changes, role/capability grants, public
token changes, provider configuration/protocol changes, DDL or historical repair.
Use existing postmeta/options for policy/delivery evidence; no customer text,
phone, public URL/token or credential in new audit records or logs.

Validation: syntax/docs/secrets, real WordPress authorization/nonce and input
negative cases, global product and mixed-invoice SMS suppression/re-enable,
accepted/uncertain/racing batch idempotency and unknown legacy exclusion, custom
discount off/on/forged/cross-team/decimal/quantity and total/snapshot regression,
actual HTTP large-form settings save and unrelated-tab preservation, desktop and
mobile UI, existing financial/HR/invoice regressions. All data must be synthetic
and disposable. Hosted CI is NOT_RUN until separately authorized publication.

Rollback: new policy/attempt records are persistent. An older version ignores the
SMS switches and custom-discount grants; do not claim that restoring code alone
preserves these controls. Existing invoice discount snapshots must remain intact.

Local implementation and private synthetic validation are complete. The proposed
additive CI mapping pins all 12 changed runtime files against the selected base,
retains every Finance matrix/HR/ledger/schema/MIS gate, and adds product policy,
native WordPress/WooCommerce, actual HTTP/mobile and concurrent SMS evidence.
The owner approved the concrete CI/security proposal and publication on 2026-11-20.
Committed preflight additionally maps the three existing shared SMS/Dot/Operations
files as HIGH/shared, selecting every dependent suite without raising the generic
blob limit. Runtime certification still requires the exact reviewed payload; a
different payload cannot select the bounded native proof. Real-Git tests cover it.
The native HTTP test server also routes parser/startup warnings to the disposable
VM syslog with an explicit UTC timezone before
WordPress starts, matching the intended WP_DEBUG_DISPLAY=false boundary. The
input limit remains 1000 and the HTTP 400 reproduction is still mandatory; no
network failure is accepted as a substitute for that response.
The old hosted CLI web process exits during that warning; the safe startup-log
configuration avoids the file-timestamp path described in PHP's
[upstream advisory](https://github.com/php/php-src/security/advisories/GHSA-gr4f-wgx5-jpfv).
This is a test-server mitigation, not a claim that the advisory is the proven
root cause of that exit or a Production PHP remediation.
The disposable web server runs without opcode caching or debugger instrumentation
so parser-boundary behavior can be separated from those optional extensions.
All native HTTP assertions, the input limit, and retained regressions remain.
At the end of local validation no commit, push, PR, merge, official RC or canonical
Stage deployment had been made. Subsequent GitHub metadata records those steps.

Environment: WordPress 7.1.4, WooCommerce 10.4.3, MariaDB 10.11.19, PHP 8.1.34 and
8.3.36; local PHP 8.3.37/SQLite, Node 24.22.3, Edge 157, Python 3.12.14 and
Gitleaks 8.30.1. Revision is the approved base plus the reviewed uncommitted
content pinned in `scripts/product_invoice_ci.py`; no invented tested commit.

| Test | Status | Evidence |
| --- | --- | --- |
| Syntax | PASS | `python3 scripts/check.py all`: 107 PHP, 34 JS; additional 6 Python AST and 4 CJS checks |
| Secret Scan | PASS | Gitleaks worktree and all 48 available commits; one fixed synthetic test key is narrowly annotated, no scanner rule/allowlist changed |
| Security | PASS | Native capability/nonce/type/scope/stale-revision checks; actor/product-bound batches, POST/confirmation, paid/moved invoice recheck, XSS DOM checks and private provider guard |
| Functional | PASS | `php tests/products/invoice-product-policy.php`: 40; `php tests/products/native-product-settings.php`: 55 on each PHP; actual HTTP/browser: 32 desktop/mobile |
| Regression | PASS (private subset) | HR 43, invoice creation 23, ledger 24, Finance 49, gateway 64 plus 18 concurrent payment assertions; approval 47/UI 19, reports 34/refresh 10, HR memory 46, gateway memory 43 |
| Concurrent SMS | PASS | `python3 tests/products/product-sms-races.py`: 16 assertions, two real PHP/MySQL races including stale WordPress option cache |
| Scope/Diff | PASS | All changed/new files reviewed against the task/base; 12 runtime changes, 109 unchanged runtime files, no removed runtime files; staged diff empty |
| API/DB/Role Compatibility | PASS (current stable) | Additive scoped APIs/settings; six original SMS arguments preserved; no DDL/role/token changes; immutable discount and sale-policy regression passed |
| Documentation/Hygiene | PASS | Updated SMS/catalog/API contracts and release/task notes; repository link and operational-file checks passed |
| GitHub CI | NOT_RUN at publication | Approved publication must run the complete four-entry Finance matrix, HR browser, rollback and isolated MIS on the exact Head/Base |
| Official RC / canonical Stage | NOT_RUN | Requires separately authorized publication, successful exact-revision CI and immutable official artifact |

The verified experimental tip remains `3f544028bf83b641caade9e5859aa4efd4b3769a`
(runtime 2.0.235). Its stored-data/API compatibility was reviewed, not certified
by installing this feature there. It lacks the later HR/payment feature chain;
do not blindly port the feature or merge that branch into main. Older code
ignores the new product switches/grants and must not be used as proof that these
controls remain enforced after rollback. No experimental branch was changed.

Security applicability: authentication/authorization/nonce/CSRF, scoped record
access, SQL input validation, XSS/escaping, malformed values, concurrent replay
and sensitive exposure were tested. Upload changes are N/A because this feature
adds no upload endpoint/parser; existing upload regression remains mandatory in
the retained Finance CI. New role grants, token redesign, DDL and real provider
operations are N/A because none is introduced or authorized.

Workflow: authorized Draft PR delivery. At publication, required hosted
CI/official RC/Stage are NOT_RUN and prohibit merge/readiness; the local snapshot
is not an installable release. Final exact-revision results belong in the PR and
release provenance. Never infer completion from this historical local table.

BLOCKED — MISSING REQUIRED TEST
