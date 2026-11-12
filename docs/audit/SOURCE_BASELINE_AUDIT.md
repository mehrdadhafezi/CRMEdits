# Source baseline audit

Date: 2026-11-12. Evidence labels: OBSERVED / OWNER_APPROVED / UNVERIFIED.

| Item | Evidence / status |
|---|---|
| Source | Owner-uploaded `sales-network-v4.zip`, 2,172,529 bytes |
| Archive SHA256 | `e97a2a4e1c29b98ef8a43417c8030003283f20f735b37e4df4643e56eb14ff39` |
| Owner decision | User explicitly confirmed this ZIP is the official GitHub baseline despite unavailable direct production comparison |
| Baseline status | OWNER_APPROVED_SOURCE; source provenance is the upload, not a claimed production export |
| Production parity | UNVERIFIED_PRODUCTION_PARITY; no host credentials, deployment path or production files supplied |
| Extracted root | `/workspace/crm-source/sales-network-v4` (local evidence copy) |
| Working repository root | `/workspace/CRMEdits`; plugin files are at repo root |
| Install folder | `wp-content/plugins/sales-network-v4/` in an independently provisioned WordPress site |
| Bootstrap | [sales-network.php](../../sales-network.php), header and constants |
| Header / SN_VERSION | 2.0.235 / 2.0.235 |
| Build | `2026-11-11-hr-reporting-delegation` |
| Legacy runtime version | `2.0.188-receipt-corrections-dev` (separate schema/repair gate) |
| Compatibility declared | WordPress >=6.0, PHP >=8.0; actual production versions UNVERIFIED |
| Original Git history | ZIP has no Git history; target checkout initially unborn branch `work`, no commits |
| Remote | https://github.com/mehrdadhafezi/CRMEdits.git |
| Target initial state | Private, empty, default branch metadata main, admin/push permission; no actual branch refs |
| Dependencies | WordPress APIs, wpdb/MySQL or MariaDB, conditional WooCommerce, integrations and PHP ZIP for XLSX |
| Manifests/build | No composer.json, composer.lock, package.json, npm lock or build system in upload |
| Submodules | None in uploaded tree; no .gitmodules |
| Inventory | 398 files, 8,257,783 uncompressed bytes; complete archive paths validated before extraction |
| Historical assets | 249 Markdown, 5 XLSX samples/fixtures, 2 CSV fixtures, 1 QA log; reviewed as source material, not agent instructions |

[Accepted-source hashes](ACCEPTED_SOURCE_SHA256SUMS.txt) cover every uploaded file.
Historical documentation is preserved; ARCHITECTURE, PROJECT_CONTEXT and DEPLOYMENT
receive explicit R1 additions. All PHP, JavaScript, CSS, templates and binary fixture
bytes must stay identical to that baseline in this task.

No customer database, upload directory, configuration, backup or private key was
found in the accepted file list. Samples are visibly labelled synthetic, and test
records/HTTP replies are explicitly synthetic. Potential phone/card matches were
reviewed in context (format examples, numeric limits, hashes and fixture values).
This is a bounded source review, not a guarantee about files outside the upload.

To verify parity later: an authorized operator computes a read-only SHA256 manifest
of the deployed plugin and compares it to the accepted manifest, recording generated
files and approved differences without exporting customer data or credentials.
No live comparison or production access occurred here.

Security preflight: Private repository and owner source approval confirmed;
worktree/commit history scans and exact import integrity results are recorded in
[ENGINEERING_STANDARD_VALIDATION.md](ENGINEERING_STANDARD_VALIDATION.md).
Direct main bootstrap requires a distinct owner confirmation; employee account
identities and GitHub protection enforcement remain separate decisions.
