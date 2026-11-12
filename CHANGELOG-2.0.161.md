# 2.0.161-operations-sms-hotfix — 2026-10-14

## Operations sales expert
- Match displayed upgrade choices to the existing server snapshot validator. Support the `id` alias, configured equal-credit targets, and free upgrades; reject incomplete, negative or malformed rules.
- Add a visible start-upgrade button, a copyable existing payment link, an invoice link, and a direct SMS resend form. No second invoice is created for copying or resending.
- Free upgrades disable partial-payment controls and use the existing no-invoice execution path.
- Display actual action outcomes using short-lived actor-scoped flash notices. Escape notice text and keep errors visible until dismissed; no diagnostic text is placed in URLs.
- Explain empty assignment queues and missing historical upgrade snapshots. Preserve expert ownership checks; this release does not automatically assign or migrate old cards.
- Preserve provider failure details in staff notices when invoice SMS delivery fails.

## Faraz / IranPayamak
- Use one verified request for Faraz pattern sends. Remove the legacy automatic second POST to the same endpoint after uncertain delivery.
- Use a 30-second timeout, verified TLS and no redirects for Faraz simple and pattern sends.
- Mark this plugin's Faraz requests and apply an IPv4-only cURL option to that exact HTTPS host. Other plugin traffic and gateways are unchanged. The `sn_faraz_use_ipv4` filter can disable the workaround.
- Retain sanitized transport messages (including cURL error numbers), redacting credentials, full URLs and mobile numbers. Require explicit provider acceptance even on HTTP 200/201.
- Preserve endpoint, provider credentials, invoice pattern fields and customer-OTP pattern payloads.

## Validation
- PHP 8.0 syntax checks passed for changed production PHP files and the new regression harness.
- New production-method tests: 23 checks passed on PHP 8.0 via PHP.wasm.
- New DOM tests: 11 checks passed, covering the start button, upgrade choices, free/partial controls, clipboard fallback and persistent errors.
- Existing provider-switch tests: 97 checks; Biavin product tests: 82; callback-only tests: 135, all passed on PHP 8.2 via PHP.wasm.
- Existing DOM tests: Biavin product 71; callback-only 24; SMS settings 93, all passed.
- Existing transaction-save, payment-transactions, multi-receipt and invoice-filter checks passed.
- The general static audit retains six pre-existing failures in unrelated legacy Zibal/date/tab checks. It is not a clean release gate.

No live WordPress/MySQL installation or real provider account was tested. IPv6 routing is a possible transport cause, not a confirmed diagnosis of the reported production error. DNS, firewall, outbound policy or CA failures require checking the detailed error on the user's host. If a panel contains zero cards, its supervisor must assign cards to the expert. Newly imported rules do not silently replace historical snapshots; use the existing preview/confirmed product-card synchronization workflow.
