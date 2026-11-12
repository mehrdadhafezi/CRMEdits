# Sales Network 2.0.190 — lead time, staged-purchase warnings, denied-panel navigation

Based on 2.0.189.1, retaining the default-off Plus API and exhausted-worker recovery.

- Seller lead rows receive an additive assigned_at_jalali display field formatted with the existing WordPress-storage-to-Tehran helper. Both seller JavaScript renderers prefer the escaped server display. Stored timestamps and sort values are preserved.
- Purchase-history warnings require completed settlement for tracked invoices. Early membership cards and paid individual stages do not establish a completed purchase. Confirmed legacy rows without staging metadata remain supported. Exclusion follows the current Dot invoice/case family, and continuation payments at stage 2+ suppress the new-purchase warning.
- Existing front-panel authorization failures use a shared denial card with the user's configured panel and a nonce-protected WordPress logout URL. Authorization checks, API errors and roles/capabilities are preserved.

No new database migration or financial balance/rate changes. Executed checks cover JavaScript display selection, syntax, extracted SQL on SQLite, and source preservation. PHP runtime/lint, real WordPress/MySQL, UI/theme and gateway testing remain required on staging. Development build; not Production approval.
