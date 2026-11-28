# Invoice stage case display — 2.0.247

Tracking: [Issue #33](https://github.com/mehrdadhafezi/CRMEdits/issues/33).

Workflow: resume the owner's unfinished display request, from verified current
main `59e5ecd64de8855403d21c3bd5bc64d4e2a8bfdc` (2.0.246), branch
`codex/invoice-stage-case-247`, PR target `main`. The owner cannot retrieve the
other account's unfinished work. `RC.zip` contains the complete 622-file 246
source and its installer; all source and 125 installer members match main.
It contains no 247 implementation. The previously reported 630 checks are not
reused as evidence. This implementation and its tests are new.

## Behavior and scope

Change the existing stage presentation, preserving the existing stage projection
and financial workflows. For an invoice with a positive or unknown balance,
show its canonical row followed by separate issued-stage rows (`/1`, `/2`, `/3`).
For a known balance at or below the existing 0.5 tolerance, retain one canonical
row with expandable stage details. Missing, blank, boolean or invalid balances
cannot imply settlement. A confirmed stage never settles a purchase with a balance.

Seller, team, Finance and administrator lists follow this presentation. Stage
rows show their own issued amount/status/reference. Purchase totals, invoice
counts, KPI inputs, pagination, bulk checkboxes and financial actions remain on
the canonical invoice only. No stage row duplicates customer/payment actions.
An empty stage history invents no issued stage. Failed history remains explicit.
Display text is escaped and aliases use LTR isolation within the RTL table.

The administrator page adds the existing batch stage projection for its already
authorized, paginated invoices (one query for at most 50 rows). Its settlement
summary retains the existing confirmed-ledger basis. Seller/team/Finance keep
their existing payment summaries. No new endpoint, wire field, financial write,
DDL, migration, role/capability, gateway/provider/token or commission change.
Previously approved invoice editing, tracking, reporting and Finance fixes remain.

Runtime scope: `sales-network.php`, `includes/class-sn-plugin.php`,
`includes/class-sn-payment-transactions.php`, `assets/js/payment-transactions.js`,
`assets/js/public-seller.js`, `assets/js/public-manager.js`. Version entry,
`SN_VERSION`, build identity and active test evidence advance to 2.0.247.
Historical release profiles remain pinned; their unit tests read historical bytes.

## Validation and evidence

| Test | Status | Evidence |
|---|---|---|
| Syntax / documentation / hygiene | PASS (local) | check.py all; PHP/Node syntax and repository checks |
| Secret Scan | PASS (local) | Gitleaks 8.24.3, full source and all 76 available commits before publication; repository policy unchanged |
| Security | PASS (local, scoped) | actual WordPress administrator renderer and seller denial; complete financial fingerprint unchanged; browser escaping and action/selector isolation |
| Functional | PASS (local) | PHP presentation 32; actual WordPress/MySQL administrator 30; actual seller/team/Finance renderers 102 desktop/mobile checks |
| Regression | PASS (local tooling); full CI pending | 147 tooling tests; 89 actual report browser/HTTP/CSV/XLS checks with canonical and stage rows counted separately; all previous gateway, tracking, editor, reporting, HR, MIS, lifecycle and four Finance matrix entries retained |
| Scope / Diff | PASS (local) | six reviewed runtime members; exact final payload and validation pins; 21 retained fixture changes are version identity only |
| API / DB / Role compatibility | PASS (local, scoped) | no wire/schema/role changes; no mutation to financial rows, invoice counts or bulk actions |
| GitHub CI | NOT_RUN at authoring | must certify this exact 247 revision; earlier 246 CI is not sufficient |

Local actual admin proof uses new internal-network Docker containers, no
published ports, a fresh WordPress volume and temporary MySQL, read-only source,
blocked mail/providers, synthetic users/invoices and ownership-checked cleanup.
WordPress 7.1.4 / WooCommerce 10.4.3 / PHP 8.3.36 / MySQL 8.0.46.
The browser runs actual production render functions with mocked transport,
blocked external traffic, and 1440×1000 / 390×1000 viewports. It is not full HTTP
proof. Screenshots and sanitized JSON are retained; raw fixtures/config are private.
The complete existing HTTP, authorization, restore and financial suites remain
mandatory in CI. No customer clone or existing site is used.

Tests: `php -n tests/payments/stage-case.php`;
`tests/payments/stage-case-native.php` requires its guarded synthetic bootstrap;
the existing stage browser entry delegates to the expanded
`tests/payments/stage-case-browser.cjs`. CI adds bounded case evidence and rejects
wrong bases, unknown paths, changed payload/fixtures and missing prior/new proof.
New upload/token/provider/role-mutation tests: N/A, no such interface is changed;
their existing regression gates remain required.

Code rollback restores the prior presentation; it does not reverse legitimate
financial decisions or accounting. No data migration needs reversal.
The owner installs personally. No Production access, installation or main merge
is performed by this task. A ZIP is delivered only from exact successful CI.

Stage: local implementation, review and source/history scan passed. First CI correctly rejected a historical browser assertion that counted every HTML row as an invoice. The report now independently checks six canonical invoices, five stage rows and no duplicate bulk selection, retains every export assertion, blocks external browser requests, and verifies real login cookies. Its actual HTTP suite passed 89 checks locally. Full CI for this validation update is required. Runtime bytes are unchanged. No main merge or site installation authorized.

BLOCKED — MISSING REQUIRED TEST
