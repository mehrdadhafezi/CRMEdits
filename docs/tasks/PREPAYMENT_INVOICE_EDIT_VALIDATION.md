# Prepayment editor CI delivery — 2026-11-22

Tracking: [Issue #26](https://github.com/mehrdadhafezi/CRMEdits/issues/26).

The owner requested execution of the missing CI for the already tested local
fix. This authorizes task-branch commit/push, Draft PR and CI to `main` from
`d0ca8e8bc9c12fb79187dea0c1a0f0ad0079685d`. It does not authorize merge or install.
The associated Issue/PR records published head, tested merge/base parents,
run URLs, final evidence table and workflow stage.

## Additive scope

The generic Fast-Track planner could not certify this new runtime payload from
an older profile. `scripts/prepayment_edit_ci.py` therefore accepts only the
three reviewed runtime hashes, the 123→124 package-member set and the five pinned
editor fixtures. Unknown paths, altered runtime, wrong base or changed fixtures
fail closed. No prior profile or required check is bypassed.

The selected HIGH-risk plan requires all suites, the four-entry Finance matrix
and comprehensive HR native job. The latter retains Finance, HR, product, wallet,
commission, schema, MIS, traceability, staged-payment and invoice-report tests,
then runs `scripts/test-prepayment-edit.sh` on a fresh internal Docker network.
The new runner has no existing-host or database argument, exposes no port,
mounts candidate code read-only, pins WordPress/MySQL/browser/WooCommerce, disables
HTTP/mail/cron and cleans only resources with its own matching ownership label.

Only explicitly named sanitized summaries and synthetic screenshots are uploaded.
No fixture credentials, environment config, raw logs or customer data are included.
The editor verifier requires 102 setup/native HTTP checks, 31 browser checks,
34 guard / 30 pricing / 40 catalog checks and seven post-browser identity/payment/
wallet integrity checks, all bound to the tested merge and runtime/fixture hashes.
Missing, stale, forged or partial evidence blocks readiness. The previous report
verifier retains its original defaults; a keyword-only runtime/payload override
allows this new exact payload while preserving all report assertions.

## Verification

Run `py -3 -B -m unittest discover -s tests/fast_track -v`, repository checks,
Bash syntax for all affected runners, and redacted source/history scans before
push. The new six-test CI proof suite exercises payload/member/hash rejection,
scope/fixture rejection, mandatory Finance/native jobs, incomplete/stale editor
evidence, retained prior ledger/MIS/report evidence and sandbox/upload boundaries.
The real runner then executes in GitHub CI; source review is not runtime PASS.

The deployable PHP/JS hashes are unchanged from local runtime validation recorded
in [the task report](PREPAYMENT_INVOICE_EDIT.md). No schema/role/migration, public
invoice token, callback, payment approval or invoice creation changes are added
by CI publication. Rollback remains code-only; no automatic data rollback exists.

At commit time: Draft / CI pending. The associated PR is the live evidence record.
BLOCKED — MISSING REQUIRED TEST


## CI runner correction after first execution

Run 45033367453 passed all four Finance matrix entries and all prior native
HR/ledger/MIS/traceability/report phases. The editor's 102 native checks passed;
its browser could not find the invoice button because Apache could not traverse
the Linux `mktemp` directory (0700). Windows bind mounts had not exposed this
permission difference. Production PHP/JS is unchanged.

The runner now allows directory traversal (0711) while private state, credentials,
env config and the full fixture remain 0600. The MU page reads only a separate
0644 JSON with three synthetic invoice IDs. Native fixture creation writes both
files; runner assertions verify the exact public key set and private file modes.
No private fixture or raw diagnostics are uploaded. All required suites must run
again on the follow-up commit; the first failed run is not readiness evidence.
