# Installed source 2.0.236 — owner-supplied runtime import

The owner supplied `2.0.236-sales-network-v4.zip` on 2026-11-16 while investigating
Finance approvals missing from seller/supervisor views. Archive SHA256:

`089539ae26c5b0a61672079ee1c9e693bef189860473998b3b9a6a9b90adaaf6`

Comparison base: `bd9a68b4d03bf55ee4b9e33a44fc529d94a3268d`, runtime 2.0.235.
The ZIP contains no added or removed runtime files and changes four runtime files:

| File | Installed change |
|---|---|
| `sales-network.php` | Version 2.0.236; build `2026-11-14-finance-receipt-status` |
| `includes/class-sn-plugin.php` | Finance list bucket/label recognizes independently approved deposits with a balance still due |
| `assets/js/public-manager.js` | Approved finance bucket receives approved styling |
| `assets/js/receipt-review.js` | Explicit refresh updates the parent list and detail, retaining the unsaved-edit guard; current-stage remaining label |

All four installed changes are retained alongside the approval-visibility fix.
The ZIP's older PROJECT_CONTEXT, ARCHITECTURE, DEPLOYMENT and TASK_TEMPLATE copies
are not applied over the repository's current engineering policies.

Public website inspection found version 2.0.236 asset URLs. Its receipt-review.js
matches the supplied ZIP; seller and supervisor scripts match the unmodified
2.0.235 repository files. This is static-asset evidence, not a comparison of the
running PHP files, database schema or actual financial records.

The unmodified supplied ZIP fails the regression for a final invoice approval
with a stale pending compatibility status. Its seller/supervisor scripts do not
contain the corresponding visibility/freshness fixes. Therefore the installed
version does not fully resolve the reported cross-panel inconsistency.

Validation of the local fix uses synthetic data only. No activation, migration,
payment decision, wallet write, real provider call or production repair is part
of this import. This is a development import and has not been deployed.
