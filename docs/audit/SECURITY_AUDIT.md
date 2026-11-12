# Security audit — bounded static review

Scope: accepted 2.0.235 source, credential/PII preflight and selected critical
handlers/helpers. No exploit test, production login, live provider call, permission
grant or runtime code fix was performed. This is not a complete penetration test.

## Findings and follow-up

| ID | Severity / classification | Evidence | Impact and proposed action |
|---|---|---|---|
| SEC-01 | HIGH potential deployment risk; access control UNVERIFIED | [SN_Helpers::receipt_upload_dir](../../includes/class-sn-helpers.php#L700) builds ordinary uploads URL under sn-receipts | Financial receipt URLs may be accessible as static assets depending on hosting. Verify unauthenticated retrieval on isolated staging and hosting ACL; plan authenticated delivery/private storage if needed. Exploitability on production not established. |
| SEC-02 | MEDIUM observed concurrency limitation | [SN_Helpers::rate_limit](../../includes/class-sn-helpers.php#L715) uses get_transient + increment + set_transient | Not an atomic counter; concurrent requests can undercount. Assess OTP/API limits under load and plan atomic limiter without changing scope here. |
| SEC-03 | HIGH operational side-effect risk, not an import vulnerability | [SN_Activator](../../includes/class-sn-activator.php#L7), [bootstrap](../../sales-network.php#L65), [catalog ensure_schema](../../includes/class-sn-sales-catalog.php#L37), [Plus setup](../../includes/class-sn-plus-api.php#L27) | Activation/init can grant existing caps, run DDL and normalize existing invoice amounts. Do not activate against live data for audit; release approval must cover these effects. No production operation occurred. |
| SEC-04 | MEDIUM assurance gap | Core is about 2.5MB; [inventory](PLUGIN_INVENTORY.md), [hook index](HOOK_API_INDEX.md) | Record-level authorization and SQL/XSS coverage across all handlers not proven; prioritize sensitive handler permission/IDOR tests before feature changes. |
| SEC-05 | LOW confirmed scanner false positive | [campaign-tracking.js:7](../../assets/js/campaign-tracking.js#L7) | Public localStorage identifier is not a credential. Exact path + exact line exception added; no blanket file or rule suppression. |
| SEC-06 | MEDIUM governance risk until enforcement verified | [GitHub governance](../GITHUB_GOVERNANCE.md) | CODEOWNERS and policy alone cannot prevent direct pushes. Apply supported rules after explicit owner authorization, verify actual configuration and plan support. |

No confirmed exploitable SQL injection, XSS, CSRF, IDOR or privilege-escalation claim
is made from lexical matches. Their absence has not been proved either.

## Observed controls and verification limits

| Control area | Observed source evidence | Limit / next verification |
|---|---|---|
| Customer auth | SN_Customer_Portal OTP nonce and session logic | Runtime expiry, brute-force and customer isolation NOT_RUN |
| Staff authorization | SN_Plugin guards, HR panel routing, SN_Scope_Service | Exhaustive handler matrix and production role tree UNVERIFIED |
| Patra API | authorize: HTTPS, SHA256/hash_equals, rate limit, safe errors | Endpoint tests and proxy HTTPS configuration NOT_RUN |
| Campaign API | authenticate_api_token inside callback; partner-scoped payload | Open permission_callback alone is not a bypass; negative token/partner tests needed |
| CSRF | check_ajax_referer/check_admin_referer/wp_verify_nonce call sites | Some public/token flows have different contracts; verify per handler |
| Input/output | helpers normalize/sanitize, context escaping in UI | Whole-code taint analysis NOT_IMPLEMENTED |
| SQL | wpdb prepare and fixed identifiers in reviewed critical paths | Lexical scan cannot establish all identifier/value safety |
| Races | MIS transaction and named locks; gateway per-stage lock; outbox leases | Two-connection/runtime failure tests NOT_RUN |
| Uploads | MIME/extension/size allowlist, dangerous extension rejection, cleanup | Hosting ACL, content handling and malformed file tests NOT_RUN |
| Secrets | Gitleaks worktree and history scan; config/dump hygiene | Secret scan does not prove PII absence or provider configuration safety |
| Logging | MIS generic failure message; mixed operational diagnostics | Whole-application redaction/retention review UNVERIFIED |

PII review: XLSX XML contents were inspected; sample rows are labelled synthetic.
Phones in fixtures, UI format examples, numeric bounds and hash substrings were
classified without copying values into the audit. No actual customer export or
credential was identified. No original file is silently removed.

Report vulnerabilities privately as [SECURITY_POLICY.md](../SECURITY_POLICY.md)
requires. Follow-up fixes require separate scoped tasks and independent review.
