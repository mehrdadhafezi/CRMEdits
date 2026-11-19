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
