# 2.0.192-mis-performance-rc2

- Replace repeated correlated inventory/invoice scans with bounded evidence reads from selected source IDs, plus an additive derived reporting index.
- Keep assignment/return/edit decisions on fresh operational evidence inside the existing transaction and writer lock; the reporting index never grants write permission.
- Add resumable index preparation, read-only audits, private asynchronous XLSX exports with byte checkpoints, frozen row membership, per-owner access and expiry.
- Add missing nonunique invoice lead and distribution-plan source lookup indexes during explicit MIS setup; retain existing source values and histories.
- Add international-prefix lead/invoice phone evidence and fail-closed conflicting claims or mixed item origins.
- Add named personal reports, display preferences, inventory and assignment/return charts, bounded/searchable batch choices and cached statistics.
- Retain small legacy export URLs; move exports exceeding 1000 rows into the resumable workflow.
- Extend CLI and CGI regression coverage, including source checksum preservation under a 100k-row / 120k-invoice volume fixture.

See `docs/MIS-RC2-guide-fa.md` for installation, data semantics, measured validation and limits.
