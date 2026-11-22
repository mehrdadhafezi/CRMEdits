# Ordinary unpaid-invoice editor tests

These regressions cover the existing prepayment action and its new price-aware
preview/save flow. See [task contract](../../docs/tasks/PREPAYMENT_INVOICE_EDIT.md).
All fixtures are synthetic. Never install the sandbox flags or MU fixture on an
existing site, customer clone or production database.

## Standalone

Windows commands (adapt the SQLite3 extension directory on other hosts):

```powershell
php -n tests/invoices/pre-payment-edit-guards.php
php -n -d extension_dir=C:\php-8.4.4\ext -d extension=sqlite3 tests/invoices/pre-payment-edit-pricing.php
node --check tests/invoices/pre-payment-edit-browser.cjs
```

Guard: 34 checks against the real private guard, including its unchanged default
support-console restriction. `SN_PREPAYMENT_SOURCE` may point at an original
checkout for the red regression. Pricing: reuses the 40-check existing synthetic
product-policy fixture, then runs 30 quote/signature assertions. No WordPress,
provider, disk database, real account or external service is used.

## Disposable native environment

The runner is deliberately not an installer for an existing WordPress site.
Provision fresh, labelled Docker resources: internal network, no published ports,
fresh temporary MySQL data, new WordPress volume, candidate plugin mounted
read-only as `/var/www/html/wp-content/plugins/sales-network-v4`. Use a private
external `/evidence` mount for generated credentials and fixture state.

Verified local image digests:

- WordPress: `wordpress@sha256:848b03bb7b4b160e3a670454e8e928e309c9aaeb1c2584e917f42ab5197662e2`
- MySQL: `mysql@sha256:7dcddc01f13bab2f15cde676d44d01f61fc9f99fe7785e86196dfc07d358ae2b`
- CLI helper: `wordpress@sha256:ab5fb76caa861f32c21e1d95a057f52007f4af7130fb16a0f68874dabe0549a4`
- Browser: `mcr.microsoft.com/playwright@sha256:f1e7e01021efd65dd1a2c56064be399f3e4de00fd021ac561325f2bfbb2b837a` (Playwright 1.56.1)
- WooCommerce 10.4.3 ZIP SHA256: `3b1bbe109a0ab653863a6c8b4ccbdf75c519fd5ebf51151c54b8ca37b325f242`

Only in this new site define `SN_PREPAYMENT_TEST_SANDBOX=true`,
`WP_ENVIRONMENT_TYPE=local`, a new database matching
`^sn_prepaid_[a-z0-9]+_test$`, and disable cron/updaters before activation.
Install [sandbox.php](sandbox.php) as its test-only MU plugin before plugin
activation: it blocks WordPress HTTP/mail, exposes an authenticated synthetic
button page and injects a requested stage-write failure for rollback proof.
It must never ship as a production MU plugin.

Install fresh WordPress with URL `http://prepay-web` (internal service alias),
admin login `synthetic_admin`, random password, and Tehran timezone. Store only
that generated password as `admin_password` in private `/evidence/private-state.json`.
Install the pinned WooCommerce and candidate plugin; activate both. Warm legacy
admin schema with WooCommerce skipped for this CLI-only bootstrap (its admin
screen functions need an actual admin request). All test requests load WooCommerce:

```bash
php /evidence/wp-cli.phar eval 'do_action("admin_init");' --skip-plugins=woocommerce --user=synthetic_admin --path=/var/www/html --allow-root
php /evidence/wp-cli.phar eval-file /var/www/html/wp-content/plugins/sales-network-v4/tests/invoices/pre-payment-edit-native.php setup --path=/var/www/html --allow-root
```

`setup` creates new sellers/manager, real HR hierarchy/catalog grants, products,
invoices, first stages and a synthetic pending payment; it writes private
`fixture.json` containing test-only credentials and baseline hashes. Do not rerun
`setup` on the same database. `regression` reuses that generated fixture and
creates fresh invoices (95 assertions on the final revision). `browser-reset`
only makes fresh browser invoices and warms normal zero-balance seller wallets
before taking the full-row baseline. Both must stay inside the original sandbox.

The native suite sends real HTTP requests to fixed internal
`http://prepay-web/wp-admin/admin-ajax.php` using WordPress session cookies and
nonce. No production controller test hooks or fake JSON responder are added.

## Browser and final verification

Run the browser on the same internal network with Playwright 1.56.1 at
`/tooling/node_modules/playwright`, tests read-only at `/tests`, and the same
private `/evidence` scratch:

```bash
node /tests/invoices/pre-payment-edit-browser.cjs
php /evidence/wp-cli.phar eval-file /var/www/html/wp-content/plugins/sales-network-v4/tests/invoices/pre-payment-edit-native.php verify --path=/var/www/html --allow-root
```

The 31 browser assertions exercise the production modal JS and real AJAX with
actual logins, products/quantities/discounts, Persian money, explicit price refresh,
single save on double click, cancellation, keyboard focus, desktop 1440×1000 and
mobile 390×844 layout, nonce/record scope, nonowner controls and stored-markup
escaping. The fixture is a minimal authenticated button page, not the entire
seller/manager panel. Recreate browser fixtures before repeating the browser run.

`verify` expects the browser's final product B, net 1700, discount 300, due 500.
Its seven checks also compare invoice identity/code/public token, payment-row
count and complete wallet/wallet-transaction table hashes. Normal account wallet
initialization occurs before the baseline; checks are not relaxed to ignore rows.
A failed assertion aborts the run. No payment is approved or sent to a provider.

Share only explicit sanitized summary JSONs, synthetic screenshots and source
hashes. Never upload the whole evidence directory, fixture/private-state/env files
or raw logs. Cleanup only containers/network/volume whose ownership label matches
this run; do not use global Docker pruning. Local tests do not substitute for
required GitHub CI on a newly published exact revision.


## GitHub CI

The owner-authorized publication adds `scripts/test-prepayment-edit.sh` after all
existing HR/MIS/ledger/traceability/report phases. The exact-payload profile and
sanitized evidence verifier are `scripts/prepayment_edit_ci.py`; six orchestration
proof tests live in `tests/fast_track/test_prepayment_edit_ci.py`. Fresh `setup`
has 102 native assertions (95 regression assertions plus seven fixture checks).
The Fast-Track summary requires every existing suite, all four Finance entries
and successful new editor evidence. See the
[CI delivery contract](../../docs/tasks/PREPAYMENT_INVOICE_EDIT_VALIDATION.md).
