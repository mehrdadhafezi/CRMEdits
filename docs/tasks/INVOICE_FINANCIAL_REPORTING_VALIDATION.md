# Invoice financial reporting — pre-PR validation checkpoint

Task: `LOCAL-20261121-INVOICE-REPORTING`.
Branch: `codex/invoice-financial-reporting`.
Approved base/target: `main`, `f509bfacc79782f843500c06db7554d5d27d23c2`.
Workflow stage at this checkpoint: **PR Delivery / local checks complete / CI pending**.
Issue: [#24](https://github.com/mehrdadhafezi/CRMEdits/issues/24). Human specialist: `UNASSIGNED`.
The owner authorized Commit/Push and a Draft PR to run the missing exact-revision CI gate.
This file records the pre-PR local checkpoint. The live head, tested merge parents,
run/job URLs and final readiness are maintained in the PR body, without creating a
new code revision solely to record CI results. No merge or deployment is authorized here.

Implementation and changed export semantics: [task contract](INVOICE_FINANCIAL_REPORTING.md).
Execution details: [test guide](../../tests/reporting/README.md).

## Execution environment and applicability

Windows CLI: Python 3.13.17, PHP 8.4.4, Node.js 24.14.0, Git 2.54.0.windows.1,
Gitleaks 8.24.3 (official Windows archive verified against its published SHA-256).
The required scanner lives outside the Git checkout.

Native disposable run: WordPress 7.1.4, PHP 8.3.36, MySQL 8.0.46 and Chromium
through Playwright 1.56.1. Docker network `sn-report-ba86baf0fd` was verified
`internal=true`; database/web containers have no published ports. Only fresh
synthetic data was created, with runtime source mounted read-only. WordPress
HTTP and mail were intercepted, Cron/updaters disabled, and no provider invoked.
No production/staging/customer database or installed site was read or updated.

After one initial fixture error (`line_total` instead of actual `total_price`)
the fixture was corrected and the temporary DB recreated. An initial cold-site
full snapshot comparison also failed without a per-column baseline. A fresh
site was then initialized through `admin_init` **before** data seeding, consistent
with the documented legacy bootstrap side effects. Full browser/export runs and
post-run native snapshot comparisons subsequently passed. This establishes the
report's post-bootstrap read-only behavior; it does not claim activation/init
is read-only or identify every earlier cold-bootstrap difference.

## Test | Status | Evidence

| Test | Status | Evidence |
| --- | --- | --- |
| Syntax | PASS | `py -3 -X utf8 scripts/check.py all`: 119 PHP and 34 JavaScript files. New CJS is parsed/executed by Node/Chromium and separately checked with `node --check`. |
| Documentation / hygiene | PASS | Same checker: 2540 relative links, required module sections and forbidden operational-file checks. Final documentation-only check repeated after this handover update. |
| Secret scan | PASS | Gitleaks `dir .` and `git . --log-opts=--all`, `.gitleaks.toml`, `--redact --no-banner`: no leaks; all 64 fetched commits scanned. |
| Security | PASS | Standalone permission/nonce/prepared-filter tests; actual HTTP guest, subscriber, copied/invalid nonce, forged seller, SQL-like status, customer XSS, formula-safe CSV and typed/escaped XLS tests. No tokens/raw proof/authority exported. |
| Functional | PASS | Native MySQL queries and actual admin page; all invoice/payment/seller/customer CSV and XLS handlers, totals and filter parity; original bulk XLS columns and explicit ID selection. Native synthetic received total is 8000 in all financial summaries. |
| Regression | PASS | New memory suite: 63 checks, including over-5000 totals, archive aliases and bounded item queries. Existing date suite: 34; approval visibility: 47; manager UI: 10; approval UI: 19. Native browser/HTTP: 85; native MySQL verification: 15, including the complete post-HTTP financial snapshot. |
| Scope / Diff | PASS | Complete changed hunks/new files reviewed, staged diff empty and `git diff --check` clean. Twelve task-related files; no invoice issuance, provider, financial decision, schema or role function changed. Runtime/test source SHA-256 manifest retained with local evidence. |
| API / DB / Role compatibility | PASS | Existing GET capability/nonce preserved. Export fields/semantics explicitly documented. No AJAX/REST/public token, DDL, financial write, role/capability, callback, wallet or commission change. Full native invoice/payment/stage/wallet snapshot remains unchanged after the HTTP reports. |
| GitHub CI | NOT_RUN | Pending at this pre-PR checkpoint. The owner has now requested exact-revision GitHub CI. The Issue #24 PR body records the live required Finance matrix/run and final gate result. |

Runtime financial mutation concurrency, provider verification, uploads and real
SMS/payment sending are **N/A to this read-only change**: those handlers and
contracts are unchanged and no such action is invoked. This does not certify
their behavior or replace the Finance CI matrix before PR delivery. Production
parity and the attachment's historical invoice discrepancies are UNVERIFIED.

## Exact local commands

```powershell
py -3 -X utf8 scripts/check.py all
php -n -d extension_dir=C:\php-8.4.4\ext -d extension=sqlite3 tests/reporting/invoice-financial.php
php -n -d extension_dir=C:\php-8.4.4\ext -d extension=sqlite3 tests/reporting/date-filters.php
php -n -d extension_dir=C:\php-8.4.4\ext -d extension=sqlite3 tests/payments/approval-visibility.php
node --check tests/reporting/invoice-financial-browser.cjs
node tests/reporting/manager-refresh.cjs
node tests/payments/approval-visibility-ui.cjs
& 'D:\Work\Coding Projects\.tools\CRMEdits\gitleaks-8.24.3\gitleaks.exe' dir . --config .gitleaks.toml --redact --no-banner
& 'D:\Work\Coding Projects\.tools\CRMEdits\gitleaks-8.24.3\gitleaks.exe' git . --config .gitleaks.toml --log-opts=--all --redact --no-banner
git diff --check
```

Native commands run only inside the guarded disposable containers:

```bash
php /tools/wp-cli.phar eval-file /var/www/html/wp-content/plugins/sales-network-v4/tests/reporting/invoice-financial-native.php setup --path=/var/www/html --allow-root
node /tests/reporting/invoice-financial-browser.cjs /tooling/node_modules/playwright
php /tools/wp-cli.phar eval-file /var/www/html/wp-content/plugins/sales-network-v4/tests/reporting/invoice-financial-native.php verify --path=/var/www/html --allow-root
```

Sanitized native summaries and desktop/mobile screenshots are retained outside
Git at `D:\Work\Coding Projects\.tools\CRMEdits\invoice-report-test-ba86baf0fd`.
Credentials, raw synthetic financial snapshots and environment config are private
test infrastructure, never report artifacts. Final runtime/test source hashes
are recorded separately there to identify the uncommitted revision.

Rollback: restore only this task's patch after preserving later contributors'
edits; no migration or financial data reversal is required by this change.
Local behavior checks passed at this checkpoint; consult the Issue #24 PR body for the current exact-revision GitHub gate result.

The final source also batches invoice item labels per 200 selected invoices;
the old per-invoice lookup is not used by this page/export. No product/catalog
write behavior was changed. All page/export native assertions were repeated on
this final source after that performance correction.

## CI follow-up for Issue #24

The first full PR run [44926325829](https://github.com/mehrdadhafezi/CRMEdits/actions/runs/44926325829)
tested head `e73200ecc06aab86addd4c0447a61a1a6562ccf4` with merge revision
`793ad79c4a2e0137fe8672b417fd71ddf1e4a3f9`. Base syntax/docs/secrets/boundary and
three Finance matrix entries passed. Two failures require a new exact-head run:

- Historical 2.0.243 proof used mutable checkout bytes. Its positive fixture is
  now the original main-ancestor commit `6a8251699d948e584f5f2376a5cc2fa26defb100`.
  Both the original commit and approved main match the existing 122-member/hash
  gate; the new 123-member reporting payload remains rejected by that historical
  gate. Runtime proof pins and production validation logic are unchanged.
- The WP 7.1.4 / installed-2.0.237 keyboard check ran during a list refresh after
  viewport/focus changes. The failure screenshot shows loading placeholders.
  The test now waits for actual fixture rows and AJAX completion after focusing,
  asserts search focus, then retains the original strict Tab-focus assertion.
  No assertion, security case, provider guard or financial scenario is removed.

These follow-ups add only `tests/fast_track/test_traceability_ci.py` and
`tests/payments/integration/browser.cjs` to the original 12-file task scope.
The five focused historical-proof tests pass locally. GitHub reruns the full
orchestration suite and all four Finance entries. The runtime source remains
byte-for-byte identical to the locally native-tested reporting implementation.
Current final results and revision are maintained in PR #25.

Checkpoint classification before the CI run:

BLOCKED — MISSING REQUIRED TEST


## Additive exact-report CI coverage

Run [44927636103](https://github.com/mehrdadhafezi/CRMEdits/actions/runs/44927636103)
tested head `9bead81a3244faf98fe76423e9952e661ddeca48`, merge
`a6cf2f1367fc3c3eb01224429c520dc7370e948a`: all four Finance matrix entries,
110 Fast-Track tests and syntax/docs/secrets/boundary checks passed. The summary
correctly remained BLOCKED: shared UI/access/ledger/schema/MIS required native
coverage, and the older exact-release mapping did not certify this new payload.

The follow-up adds an exact reporting mapping for base
`f509bfacc79782f843500c06db7554d5d27d23c2`, precisely three frozen runtime hashes,
122 -> 123 package members and the enumerated 21-file task scope. Any other
runtime byte, deletion, addition, unknown task path or base is rejected. Every
suite stays REQUIRED. All four Finance entries AND the comprehensive native
HR/product/ledger/schema/ten-script MIS/traceability/lifecycle suite must pass.
The older historical mapping and its default verifier pins are preserved.

The native job also runs `scripts/test-invoice-reporting.sh` in a separate fresh
Docker WordPress 7.1.4 / PHP 8.3 / MySQL 8.0.46 site. Four container images are
pinned by digest. Its internal network publishes no ports; plugin source is
read-only; HTTP/mail/cron/updates are disabled. It accepts no existing host or DB,
requires GitHub-hosted Actions and the current exact merge, and removes only its
own labelled containers/volume/network. Synthetic credentials and snapshots stay
private. Artifacts contain only sanitized JSON and three synthetic screenshots.

The report verifier requires 63 memory, 85 real HTTP/browser and 15 native
post-bootstrap checks, exact source/merge identity, both desktop/mobile viewports,
and an unchanged financial snapshot. It runs after all older native phases;
missing any older or report evidence fails. Six new proof tests cover rejected
payload mutations, scope, required jobs, missing/stale/partial evidence and the
combined verifier. The complete CI result for the final revision is maintained
in PR #25; these runner changes alone are not a claim that CI passed.

Local pre-commit validation: syntax/docs/hygiene PASS (119 PHP / 34 JS / 2542
links), both Bash runners parse, and all 116 Fast-Track tests PASS in an isolated
Linux container with read-only source and no network. The unified Windows run
hit an existing Git environment restoration error after environment-mocking
tests; it is not counted as passing. The new six tests and original five
historical proof tests also pass separately on Windows. Gitleaks worktree scan
passes. Frozen report runtime hashes remain unchanged.
