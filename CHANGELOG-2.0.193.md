# 2.0.193-mis-unified-rc3

- Replace synchronous web setup with resumable, nonce-protected AJAX steps. Web setup creates at most one additive table per request; it never alters or indexes operational tables.
- Isolate MIS requests from unrelated CRM installers, migrations, page repair and reporting baselines. Retain normal WordPress authentication, MIS authorization, writer locks and JWT compatibility.
- Add content navigation, Back/Forward support, visible retry state and recovery after failed/504 responses. Normal page rendering uses the read projection without fresh invoice/lead/log evidence scans.
- Replace the advanced-panel entry and old URLs with redesigned case, distribution-plan, rule, branch, pool delivery and health screens. Retain source IDs and legacy approved-plan execution with explicit authority and fresh consumption checks.
- Bound plan creation, legacy delivery and lead creation; use bounded evidence reads instead of correlated global candidate scans. Preserve campaign capture when creating a live lead through the isolated transport.
- Refresh visual hierarchy, responsive navigation, focused dashboard actions, collapsed secondary filters, job progress and personal settings. Keep all operational tables and histories.
- Validate real WordPress admin-ajax routing, desktop/mobile Chromium interactions, interrupted setup and 504 recovery, plus a 100k-row volume fixture with missing operational lookup indexes.

Current installation and behavior: `docs/MIS-RC3-guide-fa.md`.
