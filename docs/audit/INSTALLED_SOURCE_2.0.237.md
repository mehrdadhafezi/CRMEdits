# Installed reporting baseline and 2.0.238 reconciliation

The owner requested preservation of reporting changes while integrating the
finance approval visibility fix. Work starts at main
`f17cd8b542a9e0a1e45110647b981d47ed69398c` on
`codex/reconcile-installed-237-finance`, targeting `main`.

The installed runtime was captured as 116 allowlisted code/assets files, checked
for stable hashes before and after capture, and secret-scanned in an isolated
process before transfer. A second local scan also passed. Configuration, database
content, receipts, credentials, operational logs and private backup paths are
excluded. The exact runtime is recorded locally in baseline commit
`ebed298fa21c2cd91731ae050fe295b105d213df`; all 116 committed runtime file hashes
were compared with the captured manifest.

Relative to the accepted 2.0.236 baseline, the installed source changes five paths:

- `assets/js/public-manager.js`: abort superseded report requests, reset stale
  totals while loading, and ignore intentional-abort errors.
- `includes/reports/class-sn-report-service.php`: normalize Persian/Arabic digits
  and Jalali/Gregorian dates, validate times and reject invalid/reversed ranges.
- `includes/reports/class-sn-report-executive.php`: validate the active asof
  fields and return an unavailable report for invalid dates.
- `includes/class-sn-plugin.php`: share manager date conditions across legacy
  and distribution reports, retain Tehran-to-site day boundaries, exclude
  returned distribution records, format distribution dates in Jalali, preserve
  the reporting panel form anchor and display validation errors safely.
- `sales-network.php`: installed version/build identifier.

The previously tested finance patch from 2.0.236 to merged main applies cleanly
over this baseline using a three-way merge. It changes the core and seller/
supervisor JavaScript. The reporting service, executive service and manager
JavaScript remain identical to the captured source. Version/build are bumped to
2.0.238 to distinguish the integrated candidate from the two 2.0.237 variants.
The legacy runtime and performance-index versions remain unchanged.

The finance API retains its additive `received_amount` and conditional
`is_fully_settled` fields. Completed-sales totals remain distinct from approved
receipts. Authentication, scope, nonce, financial decisions, gateways, wallet
posting, database schema and existing role/capability definitions are unchanged.
The installed reporting behavior is preserved rather than reimplemented.

| Test | Status | Evidence |
|---|---|---|
| Syntax | PASS | `scripts/check.py all`: 85 PHP and 32 JS; explicit Node checks for CJS tests; 64 candidate/test PHP files also parse on actual PHP 8.1.34 and 8.3.36 |
| Documentation / Hygiene | PASS | 2491 relative links and all module sections; operational-file hygiene passed |
| Secret Scan | PASS | Captured runtime passed isolated Gitleaks 8.24.3; final code export (483 files) and available Git history passed 8.30.1; ignored-tool findings were verified checksums/public examples/synthetic control, without allowlist changes |
| Security | BLOCKED | Local SQL-like input, array/date validation, scope, escaping and token omission pass; native cookie/nonce/role/CSV gates pending |
| Functional | PASS | 47 PHP finance assertions and 34 memory-only reporting assertions |
| Regression | PASS | 19 invoice UI assertions and 10 manager transport/escaping assertions |
| Scope/Diff | PASS | Three reporting files remain identical; reversing only the finance patch reproduces all captured bytes for its three changed files; final diff is scoped to runtime integration and related tests/docs |
| API/DB/Role Compatibility | BLOCKED | Legacy runtime/index versions and schema/role/financial-write code unchanged; native upgrade/rollback from all three baselines pending |
| GitHub CI / Pre-Merge | NOT_RUN | Final head not published; current green main does not validate this candidate |

The four native matrix jobs cover WordPress 7.1.4 from 2.0.235, 2.0.236 and the
installed 2.0.237 baseline, plus WordPress 6.9.11 from installed 2.0.237. The
disposable-host, synthetic-data and egress guards remain mandatory. See
[native integration](../../tests/payments/integration/README.md).

Workflow stage: local candidate under verification. No Production installation is
authorized. Hosted Staging acceptance follows the reviewed merge and exact ZIP.
The PHP 8.1/8.3 checks ran from a private temporary code directory with in-memory
fixtures; they neither installed this candidate nor bootstrapped WordPress or
connected to a database. They do not replace native WordPress/Staging acceptance.

BLOCKED — MISSING REQUIRED TEST
