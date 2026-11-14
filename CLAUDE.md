# Claude Code

@AGENTS.md

Read and follow the shared [AGENTS.md](AGENTS.md) and the relevant
[module contract](docs/modules/README.md) before editing. All Codex and Claude
contributors use the same repository, task branches, tests and review policy.
There is no separate Claude source tree or permission to merge/deploy.
Use the base and PR target in [Git workflow](docs/GIT_WORKFLOW.md), including
its rules for syncing stable fixes and promoting individually approved experiments.

The mandatory validation gates in AGENTS.md and
[TESTING_STANDARDS.md](docs/TESTING_STANDARDS.md) apply equally to Claude:

- After every code change, check the complete task/base diff, staged/unstaged/new
  files and API/database/role/permission/business-logic compatibility. Preserve
  other contributors' work and resolve your own unrelated changes.
- Before PR/revision delivery, run syntax, docs/hygiene, secret scans and relevant
  security, functional and dependent-path regression tests. N/A needs an explicit
  relevance reason; missing required infrastructure is BLOCKED/NOT_RUN.
- Do not call a PR READY FOR REVIEW with a required failed, blocked or unrun test.
  Use an explicitly incomplete draft when needed. Rerun affected tests after fixes.
- Before a separately authorized merge, recheck the exact latest head/base/diff,
  corresponding GitHub CI, independent approval and open discussions.
- Include the shared `Test | Status | Evidence` table and one of the five readiness
  classifications in AGENTS.md. READY FOR REVIEW is not approval or merge authority.

**No verified tests, no approval. No approval, no merge.**
