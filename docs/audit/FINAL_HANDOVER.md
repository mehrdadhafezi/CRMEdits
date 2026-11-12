# Final handover — CRM engineering standard R1

> سابقه ممیزی R1: وضعیت‌ها مربوط به زمان تهیه این گزارش و پیش از ادغام اولیه‌اند.
> مالک سپس ادغام سورس و مستندات را صریحاً مجاز کرد؛ مرجع فعلی کار تیمی [Handbook](../DEVELOPER_HANDBOOK.md) و شاخه main است.

## Architecture and source

Owner-approved Sales Network **2.0.235**, build
`2026-11-11-hr-reporting-delegation`. Canonical target is the private
[CRMEdits repository](https://github.com/mehrdadhafezi/CRMEdits).
Bootstrap at repository root, large legacy SN_Plugin core, 20 documented technical
module areas, WordPress/wpdb and conditional WooCommerce/integrations. No runtime
PHP, JavaScript, CSS or templates were changed. Production parity remains unverified.

## Developer package

Start with [the Persian handbook](../DEVELOPER_HANDBOOK.md), then
[AGENTS.md](../../AGENTS.md), [CLAUDE.md](../../CLAUDE.md) and
[module contracts](../modules/README.md).

Current engineering documents: [development standards](../DEVELOPMENT_STANDARDS.md),
[Git workflow](../GIT_WORKFLOW.md), [security](../SECURITY_POLICY.md),
[testing](../TESTING_STANDARDS.md), [local setup](../LOCAL_SETUP.md),
[deployment](../DEPLOYMENT.md), [task template](../TASK_TEMPLATE.md),
[PR template](../../.github/PULL_REQUEST_TEMPLATE.md),
[module template](../MODULE_CONTRACT_TEMPLATE.md),
[GitHub setup](../GITHUB_GOVERNANCE.md), and
[architecture](../ARCHITECTURE.md).

Audits: [baseline](SOURCE_BASELINE_AUDIT.md), [inventory](PLUGIN_INVENTORY.md),
[declared schema](DATABASE_SCHEMA_AUDIT.md), [security](SECURITY_AUDIT.md),
[dependencies](DEPENDENCY_MAP.md), [risks](RISK_REGISTER.md),
[validation](ENGINEERING_STANDARD_VALIDATION.md),
[hook index](HOOK_API_INDEX.md), [symbols](SOURCE_SYMBOLS.json),
and [original hashes](ACCEPTED_SOURCE_SHA256SUMS.txt).

## Documentation and file disposition

The full original 398-file inventory is in PLUGIN_INVENTORY.md. Original files
unchanged except additive updates to `docs/ARCHITECTURE.md`, `docs/DEPLOYMENT.md`,
`docs/PROJECT_CONTEXT.md` and `docs/TASK_TEMPLATE.md`; their historical text is
preserved. Every new file and disposition is listed in
[DELIVERABLES.md](DELIVERABLES.md). No file was silently excluded from the upload.

## GitHub status at handover

| Item | Actual status |
|---|---|
| Visibility / access | Private; admin/push confirmed |
| Initial state | Empty repository; no branch refs |
| Owner authorization | Explicit approval granted for minimal main, source push, PR and supported main protection |
| Main bootstrap | `534cbf4ae550cbf278ae3997dc8fe0df6106ad4e`; contains only BOOTSTRAP.md; pushed |
| Development branch | `docs/crm-engineering-standard-r1`; pushed |
| Original source import commit | `e19e1965ce38177e2adac5ce6ac97032dffb063c`; retained in branch history |
| PR import head | `ccecba011610fab7c8497e099bc0325a4831f429`; subsequent audit-only commits record final status |
| Pull Request | [PR #1](https://github.com/mehrdadhafezi/CRMEdits/pull/1), OPEN, ready for independent review |
| Merge | NOT MERGED; excluded |
| CI | [PR run 43207834897](https://github.com/mehrdadhafezi/CRMEdits/actions/runs/43207834897) succeeded; all three jobs passed |
| Branch protection | NOT APPLIED by this task; enforcement and private-plan support UNVERIFIED because settings access is blocked |
| Employees | No invitations; account handles not supplied |
| Deployment | NOT PERFORMED; excluded |

## Validation and remaining decisions

77 PHP and 32 JavaScript parsers passed. Imported runtime bytes are identical.
Gitleaks worktree scan passed with a single narrowly scoped reviewed false-positive
exception. All 2,416 relative Markdown links and 20 module section sets passed.
Complete local Git history Secret scan passed. A plugin ZIP was generated from the clean local source import commit; no install or deployment occurred. Database, provider, WordPress UI and production runtime
tests were not executed.

Remaining inputs/actions: employee GitHub usernames; apply and verify supported
private-repository main rules; independent reviewer capacity;
module specialists; hosting/runtime versions; release operator and backup tooling;
confidential security reporting channel; company licensing policy. Source, bootstrap and supported protection approvals are already granted and must not be requested again.

Source import and developer governance do not authorize a main merge or site update.


## Local artifacts

Developer review bundle: `/workspace/CRMEdits-developer-package-R1.zip` (source and
all engineering documents, without .git, tools or operational data). This is a
review/distribution copy; the shared source is now pushed to GitHub on the PR branch; after independent review
and an authorized merge it can become the main baseline.

Installable artifact from source commit `e19e1965ce38177e2adac5ce6ac97032dffb063c`:
`dist/sales-network-v4-2.0.235-e19e1965ce38.zip`.
SHA256: `02ed369be3b7d5a0820ad7e5e210da5aa8fdf149bd8c088cecebe4a9c668f9d7`.
Package layout and every included byte were compared with the accepted baseline.
This artifact was built locally and never installed.

## Main protection blocker and continuation

The GitHub connector exposes no collaborator/ruleset mutation. The authorized
browser attempt failed with `Browser Use could not read requirements`; native
Chrome control also failed with an unavailable AT-SPI provider. A CLI read of
`/repos/mehrdadhafezi/CRMEdits/branches/main/protection` returned `Forbidden`.
No settings mutation was made and no GitHub-plan conclusion can be drawn.

[Ready-to-apply ruleset JSON](../../.github/main-ruleset.json) and exact UI/CLI steps
are in [GITHUB_GOVERNANCE.md](../GITHUB_GOVERNANCE.md). Applying and verifying this
rule requires a working settings UI or authorized API access; further conversational
permission is not needed. Do not change repository visibility or purchase a plan
without a separate owner decision.

Developers can read the shared source on the PR branch now. Employee identities
are required before invitations and specialist/independent review assignments.
No collaborator invitation, ruleset mutation, merge or deployment occurred.
