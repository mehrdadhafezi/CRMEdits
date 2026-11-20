# Hierarchical invoice reissue regression

`php tests/hr/invoice-reissue.php` runs memory-only hierarchy, inheritance, scope,
input and dispatcher regressions. It does not bootstrap WordPress or connect to a
database and is not evidence of runtime authorization or SQL transactions.

`native-fixtures.php`, `native.php`, `native-invoices.php` and `native-finance.php`
use `native-support.php`. They refuse ordinary sites. Supported targets are the
existing strictly guarded disposable Finance CI sandbox, or the owner's private
synthetic test namespace with UID 1073, fixed restore schema/user/socket, a random
`h239_XXXXXXXX_` table prefix, an approved private path and an unchanged config
hash. The private config enforces identity before `wp-settings.php`. Never relax
these checks to run on Production, a customer clone or shared Staging tables.

The private test setup preserves all 114 pre-existing synthetic restore tables
and fingerprints their data/schema before and after. Its isolated WordPress copy
and new table prefix live outside the public document root. Kernel IPv4/IPv6
egress blocking, no-new-privileges, PHP memory/time limits, no mail/exec, blocked
WordPress HTTP, Cron and updaters apply before bootstrap. Config, generated
passwords/cookies, SQL snapshots and detailed logs stay server-private.

Coverage:

- Real HR/admin authorization, guests/unrelated/revoked roles, staff-view
  impersonation, independent nonce checks and strict IDs/enums.
- Current hierarchy and skipped levels, inheritance and four-level exceptions,
  transfer, inactive/missing/ambiguous parents, branch filtering.
- Actual InnoDB transaction and audit rollback, optimistic ancestor conflicts,
  simultaneous saves, financial-row and role preservation.
- Real WooCommerce 10.4.3 product and invoice creation: default/allow/deny,
  normalized phones, different sellers, existing admin/controlled exceptions,
  and independent manual/product/lead ownership gates. No real SMS or gateway.
- Existing receipt approval, received-money SQL/PHP parity, deduplication,
  rollback, wallet idempotence and scoped Jalali reporting on native MySQL.
- `browser.cjs` checks the actual rendered HR tab and shipped JS/CSS in headless
  Edge: signed WordPress login cookies, real AJAX handler, multi-select cascade,
  stored/inherited status, unsaved drafts, scope, concurrency, XSS, RTL and mobile.
  The page uses the normal lightweight shortcode lifecycle; AJAX sends its action
  before bootstrap and verifies automatic hook registration. The bridge supplies
  real logged-in and secure-auth cookies for the respective frontend/admin paths.

The private browser bridge is CLI-only and excluded from installable packages.
Its local loopback transport forwards synthetic cookies to WordPress, then runs
the real handler. It does **not** prove public-host web-server/WAF transport.
The canonical public Staging deployment and official Release Candidate must still
follow merged-main and exact-CI gates; a dirty validation ZIP is not an official RC.

Environment-specific operation wrappers and private evidence are not committed.
Do not run these fixtures without the explicit synthetic test environment and
guards described above. `native-woo.php` only activates the checksum-verified
official WooCommerce dependency inside that private test site.

The owner-approved additive `scripts/test-hr-integration.sh` runs only on a disposable
GitHub-hosted VM. It first executes the unchanged Finance integration runner,
then native HR/nonce/scope/InnoDB failure tests, WooCommerce invoice creation,
wallet/commission snapshot/replay/reversal checks and real WordPress HTTP login,
shortcode and `admin-ajax.php` browser tests. Its separate fresh MIS database runs
all ten default MIS scripts; the optional volume benchmark is excluded. MIS uses
the existing minimum legacy fixture schema without activating the full CRM;
native HR/Finance and code rollback separately exercise the activated schema.

`ci-code-state.php` verifies 2.0.240 → 2.0.238 → 2.0.240 code-only preservation while
preserving financial/HR rows, policy metadata, WordPress role definitions and
table definitions. No fixture is authorized against existing server databases.
`ci-mis-bootstrap.php` and `ci-mis-install.php` independently require the hosted
VM, generated `/tmp/sn-approval-*` root and a separate `*_hrmis_test` database.

The CI mapping is restricted to the reviewed base and six exact runtime
hashes in `scripts/hr_reissue_ci.py`; all 113 other runtime files must remain
identical. It keeps every currently required suite and all four original Finance
jobs. An absent/failed/skipped native job or verification step, changed runtime,
different base, incomplete evidence or stale PR revision still blocks readiness.
Only safe summaries/screenshots are uploaded. Configs, fixture passwords, cookies,
SQL and detailed diagnostic logs remain in the disposable VM. Required native CI
results remain NOT_RUN until the workflow actually completes successfully.

The 2.0.239 mapping remains historical and unchanged. The separate bounded
`scripts/gateway_manual_ci.py` mapping ties the 2.0.240 payment fix to eight exact
runtime changes from 2.0.239 and retains all these suites. Preservation-only
downgrade is not financial rollback approval when manual-held bank requests exist;
follow the payment task's reconciliation/forward-fix requirements.
