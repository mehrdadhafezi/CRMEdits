# Native approval integration

Run the **Finance approval integration** GitHub Actions workflow on the task
branch. `scripts/test-finance-integration.sh` refuses other hosts; it provisions a
new database and OS user on a disposable GitHub-hosted Ubuntu VM. It never accepts
an existing site URL, database credentials or customer-data snapshot.

The two matrix jobs install WordPress 7.1.4 (official archive/checksum), PHP 8.3,
native MySQL and Chromium through pinned Playwright 1.58.2. They activate the
unmodified repository 2.0.235 or owner-supplied 2.0.236 baseline, create synthetic
accounts/HR descendants/invoices/payments, and install the exact candidate ZIP
produced by `scripts/package.py`. Code rollback and reinstallation must preserve
the original financial rows and role/capability definitions.

Before WordPress bootstrap, OS owner rules block external IPv4/IPv6 for the PHP
and browser account. Database access is a local socket restricted to the generated
test schema, HTTP listens only on loopback, Cron/updaters are disabled, and mail/
WordPress HTTP are intercepted. The helper MU plugin and randomly generated local
configuration/fixture credentials live only in the disposable sandbox; the ZIP
packager excludes all test infrastructure.

Coverage:

- Actual MySQL aggregates and report queries; ledger/fallback deduplication.
- Real WordPress login cookies/nonces; guest, missing/invalid/cross-user nonce,
  unrelated role, forged role/seller IDs, array and SQL-like search input.
- Seller ownership, supervisor team and senior HR descendants; invoice access
  tokens omitted from responses; escaped synthetic customer markup.
- Real concurrent financial receipt approvals, partial then final settlement,
  idempotence, native transaction rollback after an injected SQL failure, and
  unchanged wallet count on repeated decisions.
- Actual seller/supervisor/senior shortcodes with desktop/mobile screenshots,
  RTL, table scrolling, keyboard focus, uncaught errors and refresh after Finance.
- Existing gateway inquiry (stubbed provider) and receipt last-four regressions.

Each run uploads safe synthetic assertion logs, runtime summaries, screenshots,
and the exact install ZIP/checksum. It excludes wp-config, fixture/admin JSON,
authentication cookies, browser traces and raw server/debug logs. CI evidence is
specific to the tested head. A passing synthetic site does not prove production
schema parity or reconcile a real invoice, and does not authorize deployment.
