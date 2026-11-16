# Finance approval visibility — local handover

## Scope and evidence

Owner task: investigate and fix Finance approvals missing from seller/supervisor
views, and check whether approved deposits enter reports. The owner supplied the
installed 2.0.236 ZIP; [source provenance](INSTALLED_SOURCE_2.0.236.md) records its
checksum and the four retained runtime changes.

- Branch: `codex/fix-finance-approval-status`.
- Verified base: `bd9a68b4d03bf55ee4b9e33a44fc529d94a3268d` (`main`, 2.0.235).
- Intended PR target: `main`; no merge or deployment is authorized by this report.
- Tracking: [issue #10](https://github.com/mehrdadhafezi/CRMEdits/issues/10).
- Validation date: 2026-11-16. Code is a local development patch, not a release.
- The reported production invoice has not been inspected in an authenticated
  session. No production data access or repair was performed.

OBSERVED: the supplied 2.0.236 source improves Finance's queue classification,
but retains the old seller/supervisor scripts. Its unchanged status resolver can
hide a final approval behind a stale pending compatibility column. Independently
approved receipts can also coexist with a pending, partially funded invoice;
that is different from full invoice settlement. The supervisor list could reuse
its cache indefinitely, while the seller's initial summary could remain stale.

The unmodified supplied source fails the new stale-status regression. Public
website asset comparisons support the ZIP's provenance but do not verify the
running PHP, database state, or the cause of a particular invoice's discrepancy.

## Resulting behavior

The shared label resolver displays the persisted final decision and distinguishes
approved partial deposits from invoice settlement or unfinished stage completion.
Read requests do not repair stored statuses. Panels refresh on return to the
browser/panel; explicit supervisor cache reuse expires after 15 seconds.

An additive `received_amount` displays approved receipts separately from existing
completed-sales figures. Seller totals retain the existing self/visibility scope;
the supervisor receipt figure explicitly covers the displayed filtered list.
Supervisor native invoice states also expose an optional `is_fully_settled`;
legacy successful-status aliases retain their existing fallback.

The successful-payment report's ledger/fallback logic is unchanged. Synthetic
query tests confirm an approved receipt enters that report once, pending receipts
are excluded, and a legacy invoice fallback does not duplicate ledger stages.
This does not establish inclusion of a real production payment.

Runtime paths: `includes/class-sn-plugin.php`, `assets/js/public-seller.js`,
`assets/js/public-supervisor.js`, plus the four installed-source paths documented
in the provenance report (the core class is shared by both changes). Related API
and module contracts and two standalone regressions are updated/added.

## Validation

Environment: Windows; Node.js 24.22.1; Python 3.12.14; PHP 8.3.36 CLI via the
official `@php-wasm/cli` 3.1.64 runtime, with SQLite3 in memory. This is not a
WordPress/MySQL test site. All downloaded tooling, snapshots and logs are ignored
under `.tools/`; no product dependency or CI configuration was added.

| Test | Status | Evidence |
|---|---|---|
| Syntax | PASS | `scripts/check.py all`: 78 PHP and 32 JavaScript files; PHP uses the local WASM CLI wrapper |
| Documentation/Hygiene | PASS | Same check: 2485 relative links and module sections; prohibited operational/credential-file checks |
| Secret Scan | PASS | Gitleaks 8.30.1: all tracked/new nonignored worktree source and all available Git history (21 commits); no findings; synthetic positive/negative controls passed |
| Security | PARTIAL / NOT_RUN | Synthetic seller self-scope, guest/nonce rejection before DB reads, read-only queries, and status HTML escaping pass; real WordPress capability/role, CSRF and supervisor/senior descendant-scope integration remain NOT_RUN |
| Functional | PARTIAL / NOT_RUN | `node .tools/run-php.mjs -n tests/payments/approval-visibility.php`: 47 assertions; `node tests/payments/approval-visibility-ui.cjs`: 19 assertions; real AJAX/database/browser integration NOT_RUN |
| Regression | PASS (synthetic) | Stale status; partial/final/failed-finalization labels; monetary SQL/PHP parity including rounding; old optional-column schema; scoped receipt aggregate; report deduplication; bounded cache and browser return; preserved 2.0.236 Finance styling and receipt dirty guard |
| Scope/Diff | PASS | Full tracked and new-file review against the base; no unrelated formatting, file deletion, role/schema/provider or financial-write changes |
| API/DB/Role Compatibility | PARTIAL / NOT_RUN | Additive response fields documented; no DDL, stored-state, permission or role change; old-schema query regression passes; real MySQL/WordPress integration and both-track runtime verification NOT_RUN |
| GitHub CI | NOT_RUN at local validation | Exact head and Actions results are recorded in the delivery PR after publication |

Gitleaks was obtained from the integrity-verified npm package
`@nogoo9/gitleaks-windows-x64` 8.30.1-post.2 because the upstream release download
was unavailable. The executable reports 8.30.1; its SHA256 is
`17157e2ee8b76fc8b1d8bee607a250e34b8a8023c8bc81822d4b5ee4d78fcb7c`.
The repository config and both `dir`/`git --log-opts=--all` modes were exercised;
no scanner allowlist was changed. Registry integrity validates package delivery,
not an independent comparison to the upstream executable.

Exact local check commands (from the repository root):

```powershell
$env:PHP_BIN = Join-Path (Get-Location).Path '.tools\php.cmd'
$env:PYTHONUTF8 = '1'
& 'C:\Users\user\.cache\codex-runtimes\codex-primary-runtime\dependencies\python\python.exe' scripts/check.py all
node .tools/run-php.mjs -n tests/payments/approval-visibility.php
node tests/payments/approval-visibility-ui.cjs
& .tools\gitleaks-npm\package\bin\gitleaks.exe dir .tools\approval-worktree-scan --config .gitleaks.toml --redact --no-banner
& .tools\gitleaks-npm\package\bin\gitleaks.exe git . --config .gitleaks.toml --log-opts=--all --redact --no-banner
git diff --check
```

The secret-scan snapshot copies every existing path from
`git ls-files --cached --others --exclude-standard -z` and excludes only ignored
tooling/artifacts. It does not omit uncommitted source or new test/report files.

Security controls without a changed entry point are NOT_APPLICABLE to this patch:
upload validation (no upload changes), financial mutation privilege escalation
(no decision/write handler changes), gateway/provider secrets (no provider calls
or credentials), and public invoice tokens (no token route/contract changes).
Dynamic SQL identifiers are restricted to code-owned/cached schema names; filter
values and seller IDs retain prepared existing queries. This static assessment
does not substitute for the missing runtime security checks above.

Reproduction against the supplied source:

```powershell
node .tools/run-php.mjs -n -r 'putenv("SN_APPROVAL_TEST_SOURCE=/workspace/.tools/installed-236/sales-network-v4"); require "/workspace/tests/payments/approval-visibility.php";'
```

Expected: failure of the first stale-status assertion on unmodified 2.0.236.
The same test passes on the patched workspace. All fixture records are synthetic;
the test constructs the plugin without activation and rejects write queries.

## Delivery and remaining gates

Workflow stage: implementation validated locally. The owner explicitly authorized
publication as an incomplete Draft PR on 2026-11-16. Exact delivery commit/PR and
CI results are recorded in that PR; this report alone is not CI evidence.
Required remaining gates: isolated synthetic WordPress/MySQL testing of both
panels and role scopes, visual RTL/browser verification, and exact-head GitHub CI
after separately authorized publication. Production invoice reconciliation is a
separate read-only verification, not evidence supplied by the local fixtures.

Rollback of this read-only display/API patch would restore the prior runtime
files. It needs no schema rollback or payment reversal. Preserve the installed
2.0.236 Finance fixes when reverting only this task's display changes.

BLOCKED — MISSING REQUIRED TEST
