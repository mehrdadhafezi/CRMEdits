Current 2.0.194: run-all.php also executes ux-and-reporting.php (Jalali validation, file receipts, typed history, safe feedback and receipt transaction rollback). Only disposable *_test databases with SN_MIS_TEST_ALLOW_RESET=YES are permitted.

# MIS regression suite

**Destructive test fixtures. CLI only. Never run against production or a customer-data staging clone.**

Use a disposable WordPress database whose name ends with `_test`, with PHP ZIP and an InnoDB MySQL/MariaDB server. Install the new plugin source first. Disable outbound integrations and Cron in that test site's configuration.

```bash
SN_MIS_TEST_ALLOW_RESET=YES \
SN_MIS_TEST_BOOTSTRAP=/absolute/path/to/test-wordpress/wp-load.php \
SN_MIS_TEST_OUTPUT=/absolute/path/to/test-results \
php /absolute/path/to/sales-network-v4/tests/mis/run-all.php
```

The core suite clears MIS, distribution, lead and invoice fixtures. The guard checks the actual selected database name before resetting. Each test file refuses web execution. The suite creates only synthetic users and records, and suppresses email and HTTP calls during its test operations.

Coverage includes initial assignment, consumption, partial return, reassignment, immutable branch history, legacy ambiguity, cross-pipeline duplication, SQL failure rollback, separate-connection lock contention, stale previews, InnoDB enforcement, 5101-row import/export, source metadata, role revocation, conversion with virtual invoices, safe archival, consistent export snapshots, invalid-record correction and the legacy distribution-plan path.

`fixtures/blank-cell.xlsx` contains a self-closing B2 and populated C2. A multipart CGI upload of that fixture was also tested during development. Its expected values are: blank name, source code `42`, phone `9123456789`. `legacy.csv` and `new.csv` exercise both supported import layouts.

The included tests use normal WordPress `wpdb` APIs. Development executed those SQL statements using WordPress 6.8.3 / PHP 8.3.6 and a real embedded MariaDB 10.11.7 InnoDB engine through a test transport adapter. Production MySQL, authentication cookies, the site's actual role tree, payment provider, browser layout and load must still be checked on staging. Development-only CGI authentication fixtures and runtime binaries are deliberately not part of this installable plugin.


RC2 adds checkpoint/retry tests for asynchronous exports, membership freezing, leading-zero and formula safety, per-owner access, pending legacy records, read-only audits, derived-cache failures, personal preferences, named reports, international-prefix phone evidence, claim conflicts and missing production invoice indexes. The expected injected cache failure emits one generic diagnostic; it does not roll back the committed business record.

`performance-volume.php` is an optional, destructive, synthetic-only benchmark (not part of `run-all.php`). Run it with the same environment variables and disposable database guard. It resets the synthetic business tables and generates 100,000 MIS rows, 100,000 distribution items, 100,000 item logs, 50,000 events, 10,000 leads and 120,000 invoices. It measures each portal page before/after building the derived index, checks expected state totals, exports 11,111 selected rows in resumable steps, and compares source table checksums. Allow enough disk space. Its five-second assertions measure local PHP/SQL rendering and each processing step, not public network latency or a host SLA. Run the main suite afterwards to restore the smaller synthetic fixtures.


RC3 adds staged web-setup preservation/idempotence tests, redesigned management/legacy URL routing, projection-only page evidence-scan checks, legacy approved plans without new assignment events, a continuation cursor past blocked lead batches, and supplementary capture after commit/rollback. No production web setup calls `SN_MIS_Service::install()` or runs operational-table `ALTER TABLE`.

For a volume run that reproduces missing legacy lookup indexes, add `SN_MIS_PERF_NO_LOOKUP_INDEX=YES` to the same guarded, synthetic-only `performance-volume.php` invocation. This option drops only the test fixture's invoice-lead and plan-source lookup indexes before testing the web setup, ten read screens and resumable processing. Never use it on customer data. The full WordPress dispatcher and browser harnesses used in development contain test authentication and are intentionally excluded from this installable package.

## Classic presentation in 2.0.199

The retained redesign/backend regression fixtures call render_mis_workspace() explicitly. The public shortcode now calls render_mis_panel(), which presents the classic MIS tabs. HTTP and browser checks of the actual classic entry point, redirects, selected tabs, authorization, import and safe assignment/return are recorded with the 2.0.199 release evidence. The historical performance claims for the redesigned workspace do not establish classic-panel performance.
