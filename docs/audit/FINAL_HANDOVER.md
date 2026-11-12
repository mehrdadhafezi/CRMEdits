# Final handover — CRM engineering standard R1

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

## GitHub status at preparation

| Item | Actual status |
|---|---|
| Visibility / access | Private; admin/push confirmed |
| Initial branches / commits | None; main exists only as default-branch metadata |
| Proposed main bootstrap | BOOTSTRAP.md only; awaiting distinct explicit owner approval |
| Development branch | `docs/crm-engineering-standard-r1` local preparation |
| Source import commit | `e19e1965ce38177e2adac5ce6ac97032dffb063c` (local task branch only) |
| Remote push / PR | NOT_PUSHED / NOT_CREATED; main bootstrap awaits explicit owner approval |
| Merge | Not performed; excluded |
| CI | Workflow prepared; remote execution not yet run |
| Branch protection | Not configured/verified; manual exact steps supplied |
| Employees | No invitations; owner does not yet know account handles |
| Deployment | Not performed; excluded |

## Validation and remaining decisions

77 PHP and 32 JavaScript parsers passed. Imported runtime bytes are identical.
Gitleaks worktree scan passed with a single narrowly scoped reviewed false-positive
exception. All 2,412 relative Markdown links and 20 module section sets passed.
Complete local Git history Secret scan passed. A plugin ZIP was generated from the clean local source import commit; no install or deployment occurred. Database, provider, WordPress UI and production runtime
tests were not executed.

Owner decisions still needed: minimal main bootstrap; employee GitHub usernames;
supported/enforced private-repository main rules; independent reviewer capacity;
module specialists; hosting/runtime versions; release operator and backup tooling;
confidential security reporting channel; company licensing policy. Source approval
is already granted and must not be requested again.

Source import and developer governance do not authorize a main merge or site update.


## Local artifacts

Developer review bundle: `/workspace/CRMEdits-developer-package-R1.zip` (source and
all engineering documents, without .git, tools or operational data). This is a
review/distribution copy; GitHub remains the intended canonical source once the
approved import is pushed and reviewed.

Installable artifact from source commit `e19e1965ce38177e2adac5ce6ac97032dffb063c`:
`dist/sales-network-v4-2.0.235-e19e1965ce38.zip`.
SHA256: `02ed369be3b7d5a0820ad7e5e210da5aa8fdf149bd8c088cecebe4a9c668f9d7`.
Package layout and every included byte were compared with the accepted baseline.
This artifact was built locally and never installed.

Remote refs were checked again after preparation and remained empty. No main ref,
collaborator invitation, ruleset mutation, push, PR, merge or deployment was made.
The pending main confirmation is required by the owner's supplied mission standard,
not by a failed automatic approval review.
