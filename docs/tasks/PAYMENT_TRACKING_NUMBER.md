# Optional bank tracking number — 2026-11-23

Tracking: [Issue #28](https://github.com/mehrdadhafezi/CRMEdits/issues/28).
The prior prepayment correction is already delivered in
[PR #27](https://github.com/mehrdadhafezi/CRMEdits/pull/27); it remains separate.

## Owner request and activity

Add «شماره پیگیری» when uploading a receipt or entering transfer information in
Payment Management, persist it in the database, and support invoice search.
The owner explicitly chose optional input and subsequently authorized a separate
PR, exact-revision CI and an installable ZIP. The owner will install it personally;
no deployment by the agent is authorized. Branch `codex/payment-tracking-number`,
verified `origin/main` base `d0ca8e8bc9c12fb79187dea0c1a0f0ad0079685d`.
The delivered ZIP must retain the prior PR #27 editor correction. Its reviewed
head `6ae2170688325d48a44614f57607682f300647d1` is a release dependency;
incorporating that code in this candidate does not merge either PR into main.


## Resulting behavior and storage

- The shared Payment Management form adds a visible optional tracking field for
  every transfer in both receipt and information modes. Corrections expose it;
  authorized receipt details and transaction history display the full value.
- `manual_tracking_number` is a string in that payment's existing
  `sn_payments.proof_data` JSON. Each independent transfer retains its own value,
  including older stages. No new table, column, DDL, migration or backfill.
- Omission/blank is accepted; full references up to 64 ASCII letters/digits and
  `-`, `_`, `.`, `/` are accepted (first character alphanumeric). Persian/Arabic
  digits normalize to English and leading zeros remain intact. Letters normalize
  to uppercase. Arrays, booleans, floats, excessive length, markup and control
  characters are rejected. Values are identifiers, never amounts.
- Existing `manual_tracking_last6` metadata is preserved. Old clients correcting
  a payment without the new key do not erase its existing full reference.
  An explicit empty value in a current correction clears it and retains audit.
- Seller, supervisor/manager, administrator invoice list/export, and Finance
  invoice searches match full or partial current references. A correlated EXISTS
  reads each payment's top-level reference (or legacy six-digit proof), with
  existing ownership/HR, date and status predicates kept outside the search OR.
  Multiple matching transfers never duplicate the invoice row. References inside
  superseded review snapshots are not current search values.
- Prepared values, strict internal SQL identifiers and explicit LIKE escaping
  keep `_` literal. Invalid legacy JSON is replaced with `{}` inside a CASE
  before extraction, so evaluation order cannot make old invalid JSON fail.
  A missing proof column adds no tracking matches and keeps prior text search.

The current reference is user-entered metadata, not a unique bank verification.
Identical references may legitimately match more than one authorized invoice.
No gateway `authority`/`ref_id`, public token, approval, amount, stage, wallet,
commission or role-policy contract changes. Manual tracking search does not
reinterpret gateway rows. The normal existing financial workflow still applies.
The three changed JS assets use content hashes in both enqueue paths to prevent
same-version cached scripts from hiding the new field.

## Scope and compatibility

Allowed runtime: `class-sn-payment-tracking.php`, shared batch validation,
transaction proof whitelist, manual-save metadata, receipt correction preservation,
four invoice-search paths and related labels, and the three payment/receipt JS
assets. Related API/module contracts, this report and synthetic tests are included.
Unrelated customer/profile/report-center search labels and queries were excluded.
Provider requests/callbacks, financial decisions, identity, schema and invoice
creation are unchanged. This is a `main`-based change with the explicit prior editor dependency, not an
experimental promotion. Production parity/other supported engine versions remain
UNVERIFIED; no operational data was inspected or repaired.

See [test guide](../../tests/payments/TRACKING_NUMBER.md). The SQL design follows
[MySQL JSON search](https://dev.mysql.com/doc/refman/8.0/en/json-search-functions.html)
and [JSON validity](https://dev.mysql.com/doc/refman/8.0/en/json-attribute-functions.html)
contracts: malformed JSON must not reach JSON_EXTRACT directly.

## Executed local validation

Synthetic WordPress 7.1.4 / WooCommerce 10.4.3 / PHP 8.3.36 / MySQL 8.0.46;
Playwright 1.56.1, 1440×1000 and 390×844. Fresh internal Docker network, no
published ports, read-only plugin mount, new temporary MySQL and WP volume,
HTTP/mail/cron disabled before activation. Real WordPress cookies, nonces,
AJAX, multipart PNG upload, MySQL persistence and actual production modal scripts.
No customer export/backup/production clone, real provider or SMS was used.

| Test | Status | Evidence |
|---|---|---|
| Syntax | PASS | PHP syntax and Node checks; real PHP/browser execution |
| Documentation / Hygiene | PASS | `py -3 -X utf8 scripts/check.py all`; 2549 links and repository hygiene |
| Secret Scan | PASS | Gitleaks 8.24.3 exact tracked/new source snapshot and all 70 Git commits; scanner policy unchanged |
| Security | PASS | Guest/foreign seller/nonce denial, scoped searches, malformed and XSS input, escaped output, SQL identifiers/wildcards, invalid legacy JSON |
| Functional | PASS | 29 actual AJAX/setup checks, 14 real management-browser checks, 7 post-browser invariants |
| Regression | PASS | 18 classic-route/legacy checks, tracking unit/SQL 39; traceability 21, gateway guard 43, invoice report 63, approval 47, approval UI 19, date filters 34 |
| Scope / Diff | PASS | Compared with approved main; only this task's runtime/tests/contracts; prior branch untouched |
| API / DB / Role Compatibility | PASS (scoped) | Additive optional key, no DDL/role/provider/token changes; legacy correction, real upload and wallet invariants |
| GitHub CI for this revision | NOT_RUN | Pending exact combined-candidate CI; PR #27 CI cannot certify this feature |

Native checks prove separate references, leading zeros, retries without duplicate
payments, all four search paths, foreign-scope exclusion, optional blank, authorized
history, old-client preservation, current correction and no stale-audit match.
Classic compatibility checks also cover JSON null versus a literal identifier and
repeat all four searches on the current typed expression. They prove receipt/info endpoints, full slash references,
unauthorized history denial, invalid JSON tolerance, explicit clearing, and
unchanged wallets. Browser checks use actual Payment Management controls and
multipart parsing; the surrounding page is a minimal authenticated synthetic
button fixture, not every complete panel. The file-mode and upload-directory
ownership are fixture setup responsibilities; only the synthetic uploads directory
was assigned to Apache after a CLI-created directory blocked the first browser
attempt. No production handler or upload assertion was relaxed.

Upload coverage PASS for actual multipart PNG registration and its attachment;
no upload policy/type/path was changed. Provider/callback changes N/A (none);
public-token changes N/A (none introduced, guest submission without a token is
denied). No comprehensive certification of unrelated CRM paths.

Sanitized result JSONs, screenshots and exact source hashes are retained outside
Git in the task evidence directory. Generated credentials, env config, full
fixtures and raw logs are private and excluded from delivery. Task-labelled
resources are cleaned after completion. The final ZIP will be taken from successful CI for the exact combined candidate.

Rollback: revert only this task's code. Existing JSON keys remain stored and
older code ignores them; no data erase is required. Code rollback does not undo
any legitimate receipt corrections or payments. Normal plugin lifecycle still
has existing installation side effects and was not run on Production.

Stage: PR Delivery authorized; exact combined-candidate CI pending. No main merge
or website installation is performed as part of this delivery. CI orchestration
adds bounded exact runtime/fixture proof and retains all older native gates.

BLOCKED — MISSING REQUIRED TEST
