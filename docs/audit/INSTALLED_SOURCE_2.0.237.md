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
| Security | PASS | Native cookie sessions, missing/invalid/cross-user nonces, unrelated roles, forged role/seller/hierarchy IDs, SQL-like/array input, XSS, token omission and CSV formula safety pass; upload handlers are unchanged and N/A for this diff |
| Functional | PASS | 47 PHP finance assertions, 34 memory-only reporting assertions and 279 native assertions per matrix job |
| Regression | PASS | 19 invoice UI and 10 manager transport assertions; native partial/final approval, concurrency, idempotence, SQL-error rollback, wallet deduplication and reporting regressions pass |
| Scope/Diff | PASS | Three reporting files remain identical; reversing only the finance patch reproduces all captured bytes for its three changed files; final diff is scoped to runtime integration and related tests/docs |
| API/DB/Role Compatibility | PASS | Four native upgrade/rollback/reinstall jobs preserve financial rows and role definitions across all three baselines; legacy runtime/index and schema/financial-write code remain unchanged |
| GitHub CI / Pre-Merge | PASS | Runtime/test head `d2680be1165a1d210b1eb08dcd014a727d34c806`: required CI and all four native jobs passed; latest final-head/discussion checks remain mandatory before merge |

The completed evidence above is for the runtime/test revision `d2680be1165a1d210b1eb08dcd014a727d34c806`:
[required CI](https://github.com/mehrdadhafezi/CRMEdits/actions/runs/44051488198)
and [four native jobs](https://github.com/mehrdadhafezi/CRMEdits/actions/runs/44051488187).
Each native job completed 279 assertions, including 127 real-browser assertions,
on PHP 8.3.6/MySQL 8.0.46. All four installation ZIPs have identical bytes and
their 116 runtime files match that Git revision. The two reporting services and
manager JavaScript still match the installed baseline exactly.

The four native matrix jobs cover WordPress 7.1.4 from 2.0.235, 2.0.236 and the
installed 2.0.237 baseline, plus WordPress 6.9.11 from installed 2.0.237. The
disposable-host, synthetic-data and egress guards remain mandatory. See
[native integration](../../tests/payments/integration/README.md).

Workflow stage: runtime validation complete; the latest merge and hosted Staging
acceptance record is maintained in [PR #13](https://github.com/mehrdadhafezi/CRMEdits/pull/13).
No Production installation is authorized. Hosted Staging acceptance follows the
reviewed merge and exact tested ZIP; this source audit is not a deployment log.
The PHP 8.1/8.3 checks ran from a private temporary code directory with in-memory
fixtures; they neither installed this candidate nor bootstrapped WordPress or
connected to a database. They do not replace native WordPress/Staging acceptance.

READY FOR MERGE CHECK
