# 2.0.189.1 — second review (Development)

Keeps the 2.0.188 merge and safeguards from 2.0.189. Corrects an outbox recovery gap: a worker killed after claiming its eighth attempt could leave an expired job forever pending/retry. The worker now marks only expired/exhausted jobs as failed with delivery_uncertain, preserving the original event, revision and payload for an idempotent manual retry. Live leases and acknowledged jobs are unchanged.

Rechecked literal CRM file dependencies, class declarations, original-file preservation, protocol shape and JavaScript syntax. Actual recovery SQL was exercised against SQLite with only table/time syntax adapted. PHP lint/runtime, WordPress/MySQL upgrades and end-to-end delivery/payment remain unexecuted. Development status remains; do not infer Production approval.
