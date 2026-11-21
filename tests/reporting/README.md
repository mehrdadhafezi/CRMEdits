# Invoice reporting regression

`invoice-financial.php` runs entirely in SQLite memory. It never boots WordPress,
uses a customer database, calls a provider, or writes operational data. Windows:

```powershell
php -n -d extension_dir=C:\php-8.4.4\ext -d extension=sqlite3 tests/reporting/invoice-financial.php
php -n -d extension_dir=C:\php-8.4.4\ext -d extension=sqlite3 tests/reporting/date-filters.php
php -n -d extension_dir=C:\php-8.4.4\ext -d extension=sqlite3 tests/payments/approval-visibility.php
node tests/reporting/manager-refresh.cjs
node tests/payments/approval-visibility-ui.cjs
```

Use the installed PHP extension directory on another machine. The new standalone
suite covers NULL statuses, partial receipts during follow-up, multiple/mixed
payments, unfinished gateway requests, reconciliation discrepancies, normalized
customer groups, more than 5000 invoices, prepared filters, selected ownership,
date rejection/Tehran conversion, export formula safety, numeric XLS cells,
permission/nonce rejection, SQL failure and unchanged financial fixture rows.

## Disposable native site only

`invoice-financial-native.php` is a **fixture installer** when explicitly passed
`setup`. It refuses environments without `SN_REPORTING_TEST_SANDBOX=true`,
`WP_ENVIRONMENT_TYPE=local`, and a DB matching `sn_reporting_<random>_test`.
These flags must only be defined in a newly allocated disposable synthetic site.
Never add them to an existing site, staging owner's database or customer clone.

The local verified runner uses existing Docker images, an internal network with
no published ports, a fresh temporary MySQL data filesystem and a new WordPress
volume. The candidate source and test code are mounted read-only. A test-only MU
plugin rejects WordPress HTTP and mail; Cron/updaters are disabled before plugin
activation. No credentials, configs, downloads or generated financial rows enter
the repository. `/evidence` is a private external scratch mount.

The image versions actually executed for this task are WordPress 7.1.4 / PHP
8.3.36 / MySQL 8.0.46. Browser: Playwright 1.56.1, matching the preinstalled
`mcr.microsoft.com/playwright:v1.56.1-noble` image. This separate local regression
does not change the pinned versions or guards of the existing Finance CI runner.

After fresh core installation, plugin activation, Tehran timezone selection and
admin bootstrap, run inside that new WordPress container:

```bash
php /tools/wp-cli.phar eval 'do_action("admin_init");' --user=synthetic_admin --path=/var/www/html --allow-root
php /tools/wp-cli.phar eval-file /var/www/html/wp-content/plugins/sales-network-v4/tests/reporting/invoice-financial-native.php setup --path=/var/www/html --allow-root
```

The source includes no WordPress/database installer. The operator must create the
guarded environment first. Admin bootstrap belongs before fixture creation:
legacy activation/init/admin-init may provision schema or normalize old data;
those lifecycle operations are not the report's read-only contract.

Run `invoice-financial-browser.cjs` using Node from an isolated browser container
on the **same internal network**, where `report-web` resolves only to this new
synthetic WordPress service. Mount Playwright at `/tooling/node_modules/playwright`,
tests read-only at `/tests`, and the same private scratch at `/evidence`:

```bash
node /tests/reporting/invoice-financial-browser.cjs /tooling/node_modules/playwright
```

The private scratch must have `private-state.json` with the synthetic admin's
`admin_password`; `setup` generates `private-reader.json` and `fixture.json`.
The browser logs in with actual WordPress cookies and exercises the actual admin
page, all four CSV/XLS handlers, page/export filter parity, formula and markup
escaping, numeric amount cells, anonymous/unrelated roles and copied/invalid
nonces. It captures synthetic desktop/mobile screenshots and a sanitized summary.
It never clicks payment approval, rejection or invoice mutation controls.

After browser execution, run the same native command with `verify` instead of
`setup`. It compares full invoice, payment, stage and wallet snapshots; on failure
only changed table/row/column names are printed. Raw snapshots and generated
credentials remain private and outside Git. Only sanitized evidence may be shared.

Clean up only the newly created, task-labelled containers, volume and network.
Do not prune Docker or touch containers belonging to another project.
