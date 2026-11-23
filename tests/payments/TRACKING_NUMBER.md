# Optional bank-reference regression

[Task contract](../../docs/tasks/PAYMENT_TRACKING_NUMBER.md).
All data is synthetic. The native fixture refuses any environment without
`SN_TRACKING_TEST_SANDBOX=true`, `WP_ENVIRONMENT_TYPE=local`, and a newly allocated
database matching `^sn_tracking_[a-z0-9]+_test$`. Never set these on an existing
site, canonical Staging, customer clone or Production. The source includes no
installer/operator for an existing site.

## Standalone

```powershell
php -n -d extension_dir=C:\php-8.4.4\ext -d extension=sqlite3 tests/payments/tracking-number.php
php -n -d extension_dir=C:\php-8.4.4\ext -d extension=sqlite3 tests/payments/traceability.php
php -n -d extension_dir=C:\php-8.4.4\ext -d extension=sqlite3 tests/payments/gateway-manual-selection.php
php -n -d extension_dir=C:\php-8.4.4\ext -d extension=sqlite3 tests/reporting/invoice-financial.php
php -n -d extension_dir=C:\php-8.4.4\ext -d extension=sqlite3 tests/payments/approval-visibility.php
node tests/payments/approval-visibility-ui.cjs
node --check tests/payments/tracking-number-browser.cjs
```

Adapt the SQLite extension path to the installed PHP runtime. Tracking's 39
checks exercise the actual validator/batch fields and SQL under SQLite JSON;
JSON_UNQUOTE is an identity adapter because SQLite already unquotes extracted
scalar strings. Real MySQL JSON behavior is tested separately below.

## Fresh disposable native site

Provision a fresh labelled internal Docker network, no published ports, new
WordPress volume and temporary MySQL data filesystem. Mount source read-only at
`/var/www/html/wp-content/plugins/sales-network-v4` and private external scratch at
`/evidence`. Internal web/database aliases: `tracking-web`, `tracking-db`.
Before activation install [tracking-sandbox.php](tracking-sandbox.php) only as a
MU plugin in that new site. It blocks WordPress HTTP/mail and serves an
authenticated ID-only browser fixture; no production hook is added.

Pinned tested images/dependency:

- WordPress `wordpress@sha256:848b03bb7b4b160e3a670454e8e928e309c9aaeb1c2584e917f42ab5197662e2`
- MySQL `mysql@sha256:7dcddc01f13bab2f15cde676d44d01f61fc9f99fe7785e86196dfc07d358ae2b`
- WP CLI helper `wordpress@sha256:ab5fb76caa861f32c21e1d95a057f52007f4af7130fb16a0f68874dabe0549a4`
- Playwright `mcr.microsoft.com/playwright@sha256:f1e7e01021efd65dd1a2c56064be399f3e4de00fd021ac561325f2bfbb2b837a`, client 1.56.1
- WooCommerce 10.4.3 ZIP SHA256 `3b1bbe109a0ab653863a6c8b4ccbdf75c519fd5ebf51151c54b8ca37b325f242`

Fresh core URL `http://tracking-web`, admin `synthetic_admin`, random password
kept only in private `/evidence/private-state.json`. Install WooCommerce and
candidate source, set Tehran timezone, disable cron/updaters and network before
activation. Warm legacy admin schema with Woo skipped for CLI-only admin screen
initialization; all actual tests load Woo. The task-owned uploads directory must
belong to Apache's account for real multipart uploads; never use world-writable
permissions. On Linux keep private fixtures/config 0600, allow directory traversal
for the separate ID-only `browser-page.json`, and never expose the full fixture.

```bash
php /evidence/wp-cli.phar eval 'do_action("admin_init");' --skip-plugins=woocommerce --user=synthetic_admin --path=/var/www/html --allow-root
php /evidence/wp-cli.phar eval-file /var/www/html/wp-content/plugins/sales-network-v4/tests/payments/tracking-number-native.php setup --path=/var/www/html --allow-root
```

`setup` creates synthetic actors, installed HR positions/hierarchy, one product,
invoices/stages/payments, private actor passwords and full wallet baselines. It
runs 29 real HTTP/setup assertions. Do not repeat `setup` on the same database.
`fixture.json` is private; `browser-page.json` contains only the synthetic invoice
ID. Neither private fixtures nor the MU plugin are production installation files.

## Actual browser, final integrity and compatibility

Run Node in the pinned browser container on the same internal network, with
Playwright at `/tooling/node_modules/playwright`, tests read-only at `/tests`
and the same private scratch `/evidence`:

```bash
node /tests/payments/tracking-number-browser.cjs
php /evidence/wp-cli.phar eval-file /var/www/html/wp-content/plugins/sales-network-v4/tests/payments/tracking-number-native.php verify --path=/var/www/html --allow-root
php /evidence/wp-cli.phar eval-file /var/www/html/wp-content/plugins/sales-network-v4/tests/payments/tracking-number-native.php compatibility --path=/var/www/html --allow-root
```

Browser: 14 checks, actual Payment Management dialog, optional blank, Persian
reference, two independent receipt/info rows, actual generated PNG multipart,
real date-picker selections, input rejection, history, both references searchable,
no duplicate invoice, no console errors, desktop/mobile RTL. `verify`: seven
checks for exact persisted references, amounts, pending status, no fabricated
provider reference, unchanged paid/net totals and full wallet-table hashes.
`compatibility`: 18 checks for classic single info/receipt endpoints, old client
correction, cleared references, unauthorized history and corrupt legacy JSON.
It uses the already uploaded synthetic image and does not contact a provider.
The browser mutates its fixture; use a new disposable environment for a repeat.

Only explicit sanitized summary JSONs, synthetic screenshots and source hashes
may be shared. Full fixture, passwords/config and raw logs stay private outside
Git. Cleanup only resources carrying this task's matching label; never prune
Docker globally. These local results do not replace CI for a newly published PR.
