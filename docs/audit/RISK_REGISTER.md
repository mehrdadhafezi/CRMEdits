# Risk register

| ID | Risk / evidence | Likelihood / impact | Mitigation | Status / owner |
|---|---|---|---|---|
| R01 | Production parity unavailable; source audit | Unknown / high | Owner approved ZIP; later read-only deployed hash comparison | UNVERIFIED parity / owner |
| R02 | Empty main; no PR base | Resolved | Owner-approved minimal main pushed; PR #1 created | CLOSED / owner |
| R03 | Main enforcement unverified; settings access blocked | Unknown / high | Apply prepared JSON through working UI/API, verify plan and enforcement | BLOCKED tool/API access / owner |
| R04 | Developer GitHub handles absent | Certain / blocks invitations and independent review | Owner provides real accounts; invite through settings | BLOCKED identities / owner |
| R05 | Large shared core and general assets | High / high | Narrow changes, trace callers, module review, avoid formatting churn | OPEN / module owner unassigned |
| R06 | Init/activation schema, role and normalization side effects | Observed / high | Isolated synthetic environment and explicit release/migration approval | CONTROLLED: no execution here |
| R07 | Financial concurrency and historical ledgers | Unknown / critical | Locks/idempotence and targeted regression on isolated data | Runtime NOT_RUN / finance owner unassigned |
| R08 | Receipt static-file privacy | Unknown / high | Verify hosting ACL and authenticated retrieval | SEC-01 / owner |
| R09 | Non-atomic transient limiter | Observed / medium | Separate atomic-limiter task and concurrency tests | SEC-02 / owner |
| R10 | Full runtime/UI/security coverage absent | Certain / high | Build approved WP/DB/browser fixture infrastructure | PARTIAL / owner |
| R11 | Old release docs conflict with current version/policy | Observed / medium | R1 superseding notes; preserve history, do not treat old test logs as new PASS | MITIGATED in current docs |
| R12 | Private repo mistaken for permission to store PII | Possible / high | Hygiene + Secret scan + manual synthetic-data review | POLICY active; scan bounded |

Technical module ownership is documented; specialist employee owners, release
operator, confidential security channel, hosting versions and company license
remain decisions for the owner. No invented identity or permission is assigned.
