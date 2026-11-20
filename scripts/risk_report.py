"""Describe PR risk and test evidence without running or bypassing any check."""
import argparse
import ast
import difflib
import hashlib
import json
from pathlib import Path
import re
import subprocess
from urllib.parse import urlsplit


MAX_BLOB = 256 * 1024
LEVELS = ("LOW", "MEDIUM", "HIGH")
STATUSES = {"PASS", "FAIL", "BLOCKED", "NOT_RUN"}

# Content reviewed together with the command/file-access safety tests. These pins
# bound this one standalone collector; they are not a general shell/JSON analyzer.
# Any source/harness change needs renewed review and actual CI proof before use.
AUDIT_PINS = {
    "scripts/staging-audit.sh": "a9788273ed7f14b942f85a6bef8ef79271c0796f80045c98e462a08463bdfa55",
    "tests/fast_track/test_staging_audit.py": "7d384f9a49cc39b27ed133cd66b4a4f25b587dba211ba3070108f6360ff0dab5",
    "docs/examples/staging-audit.sanitized.json": "97e0fa3f1979673da825f3a200b05ca84b8a8598fff75a5f90c0aba2d65de654",
}
AUDIT_FILES = set(AUDIT_PINS) | {"docs/STAGING_READ_ONLY_AUDIT.md", "docs/DEPLOYMENT.md"}
AUDIT_CONSUMERS = {
    "scripts/risk_report.py", "scripts/fast_track_ci.py", ".github/workflows/ci.yml",
    "tests/fast_track/test_staging_audit.py", "tests/fast_track/test_risk_report.py",
    "tests/fast_track/test_fast_track_ci.py",
}

# Follow the module contracts, including the consumers of money and scoped data.
DEPENDENCIES = {
    "shared": {"ui", "finance", "reporting", "access", "ledger", "schema", "mis"},
    "finance": {"access", "reporting", "ledger"},
    "reporting": {"access", "finance"},
    "ledger": {"finance"},
    "schema": {"finance", "access", "ledger", "mis"},
    "mis": {"access", "finance", "reporting"},
    "campaign-ui": {"ui"},
}
SUITES = {
    "syntax": (None, "PHP and JavaScript syntax"),
    "docs-hygiene": (None, "Documentation and repository hygiene"),
    "secrets": (None, "Secret scan (worktree and Git history)"),
    "stable-boundary": (None, "Stable PR branch boundary"),
    "tooling": ("tooling", "Affected CI, packaging or harness checks; verify failure behavior"),
    "ui": ("ui", "Synthetic browser smoke for affected views, mobile and RTL"),
    "finance": ("finance", "Finance integration matrix; invoice decisions, payments, retry and rollback"),
    "reporting": ("reporting", "Reporting date/filter/export tests and scoped totals on synthetic WordPress"),
    "access": ("access", "Synthetic role/Scope, nonce and allowed/denied record checks"),
    "ledger": ("ledger", "Synthetic wallet and commission invariants, replay, reversals and history"),
    "schema": ("schema", "Disposable install, upgrade, repeat, failed migration and rollback checks"),
    "mis": ("mis", "MIS regression in a disposable database only"),
}
CONTROL_DOCS = {
    "AGENTS.md", "CLAUDE.md", "docs/DEVELOPER_HANDBOOK.md", "docs/GIT_WORKFLOW.md",
    "docs/TESTING_STANDARDS.md", "docs/TASK_TEMPLATE.md", "docs/DEPLOYMENT.md",
    "docs/LOCAL_SETUP.md", "docs/GITHUB_GOVERNANCE.md", "docs/SECURITY_POLICY.md",
    "docs/DEVELOPMENT_STANDARDS.md", "docs/FORBIDDEN_PATHS.md",
    "docs/QA_CHECKLIST.md", "docs/PULL_REQUEST_TEMPLATE.md", "docs/CURRENT_TASKS.md",
}
CORE_FILES = {
    "sales-network.php", "includes/class-sn-plugin.php", "includes/class-sn-helpers.php",
    "assets/css/public.css", "assets/css/public-performance.css", "assets/js/public.js",
    "assets/js/public-shell.js", "assets/js/public-manager.js", "assets/js/admin.js",
    "includes/class-sn-sms.php", "includes/class-sn-dot-flow.php",
    "includes/class-sn-operations-flow.php",
}
SENSITIVE_PATHS = {
    "finance": r"finance|payment|invoice|receipt|gateway|seller|subscription|conversion|converter|catalog|project",
    "ledger": r"wallet|commission|cash-credit|operations-execution",
    "access": r"auth|scope|access|role|hierarchy|hr-|customer-portal",
    "schema": r"migration|upgrade|activator|schema|\.sql$",
    "reporting": r"report|export",
    "mis": r"(?:^|[-/])mis(?:[-/.]|$)",
}
# Use changed code, including deletions, to catch sensitive operations in novel filenames.
SENSITIVE_EDITS = {
    "finance": r"\b(?:sn_(?:payments|invoices|invoice_payment_stages|payment_review_events)|approve_(?:invoice|receipt|payment)|payment_status|amount_(?:paid|due))\b",
    "ledger": r"\b(?:sn_wallet_transactions|wallet_balance|commission_(?:rule|amount|ledger)|ledger_entry)\b",
    "access": r"\b(?:current_user_can|user_can|map_meta_cap|add_cap|remove_cap|add_role|remove_role|set_role|check_ajax_referer|wp_verify_nonce|permission_callback|register_rest_route|wp_ajax(?:_nopriv)?_|seller_ids|scope_ids)",
    "schema": r"\b(?:dbDelta|register_activation_hook|SN_LEGACY_RUNTIME_VERSION|SN_PERF_INDEX_VERSION)\b|\b(?:CREATE|ALTER|DROP|TRUNCATE)\s+(?:TABLE|INDEX)\b",
    "reporting": r"\b(?:SN_Report_(?:Service|Executive)|fputcsv|export_csv)\b",
}
CAMPAIGN_CSS = "assets/css/campaign-analytics.css"
CAMPAIGN_CALLER = "includes/class-sn-campaign-tracking.php"
LAYOUT_RULE = re.compile(
    r"(?m)^(\.sn-campaign-(?:section|admin > \.description|portal-header) *\{[^{}]*\})"
)
SPACING = re.compile(r"([;{]\s*(?:margin-bottom|margin|padding|gap)\s*:\s*)([0-9]{1,2}px|0)(\s*[;}])")
SHA = re.compile(r"[a-f0-9]{40}(?:[a-f0-9]{24})?\Z")


class IncompleteDiff(Exception):
    """The selected revisions or file contents do not support a decision."""


def git(*args):
    result = subprocess.run(
        ["git", *args], capture_output=True, check=False, timeout=30,
    )
    if result.returncode:
        raise IncompleteDiff(f"Git {args[0]} failed; check refs and repository history")
    return result.stdout


def commit(ref):
    return git("rev-parse", "--verify", "--end-of-options", f"{ref}^{{commit}}").decode().strip()


def diff_records(base, head):
    # Treat renames as delete/add so a move cannot discard risk from its old path.
    records = git("diff", "--raw", "-z", "--no-abbrev", "--no-renames", "--no-relative",
                  "--ignore-submodules=none", base, head, "--").split(b"\0")
    if records.pop() != b"" or len(records) % 2:
        raise IncompleteDiff("Incomplete Git diff")
    for meta, path in zip(records[::2], records[1::2]):
        old_mode, new_mode, old_oid, new_oid, change = meta[1:].decode().split()
        yield path.decode("utf-8"), change, (old_mode, new_mode), (old_oid, new_oid)


def read_side(mode, oid):
    if mode == "000000":
        return ""
    if mode not in ("100644", "100755"):
        raise IncompleteDiff("Non-regular Git object (symlink or gitlink)")
    if int(git("cat-file", "-s", oid)) > MAX_BLOB:
        raise IncompleteDiff("Blob exceeds analysis limit")
    content = git("cat-file", "blob", oid).decode("utf-8")
    if "\0" in content:
        raise IncompleteDiff("Binary content")
    return content


def changed_lines(before, after):
    left, right = before.splitlines(), after.splitlines()
    for tag, i, j, k, end in difflib.SequenceMatcher(None, left, right).get_opcodes():
        if tag != "equal":
            yield from left[i:j]
            yield from right[k:end]


def layout_signature(text):
    def mask_rule(match):
        def mask_value(item):
            value = int(item[2].removesuffix("px"))
            return item[1] + "<spacing>" + item[3] if value <= 48 else item[0]
        return SPACING.sub(mask_value, match[0])
    return LAYOUT_RULE.sub(mask_rule, text)


def verified_campaign_caller(revision):
    result = subprocess.run(
        ["git", "grep", "-z", "-l", "-F", "-e", CAMPAIGN_CSS.rsplit("/", 1)[-1], revision,
         "--", ":(top,glob)**/*.php", ":(top,glob)**/*.js", ":(top,glob)**/*.css",
         ":(top,glob)**/*.html", ":(top,glob)**/*.json"],
        capture_output=True, check=False, timeout=30,
    )
    return result.returncode == 0 and result.stdout == f"{revision}:{CAMPAIGN_CALLER}\0".encode()


def audit_consumers_bounded(revision, path):
    result = subprocess.run(
        ["git", "grep", "-z", "-l", "-F", "-e", path.rsplit("/", 1)[-1], revision,
         "--", ".", ":(exclude,glob)**/*.md", ":(exclude,glob)*.md"],
        capture_output=True, check=False, timeout=30,
    )
    if result.returncode not in (0, 1):
        raise IncompleteDiff("Unable to bound audit consumers")
    consumers = {name.decode().split(":", 1)[1] for name in result.stdout.split(b"\0") if name}
    return consumers <= AUDIT_CONSUMERS | {path}


def previous_audit_pin(base, path):
    # Read the accepted base's literal review record without importing its code.
    oid = git("rev-parse", "--verify", f"{base}:scripts/risk_report.py").decode().strip()
    try:
        tree = ast.parse(read_side("100644", oid))
        for node in tree.body:
            if isinstance(node, ast.Assign) and any(
                isinstance(target, ast.Name) and target.id == "AUDIT_PINS" for target in node.targets
            ):
                pins = ast.literal_eval(node.value)
                return pins.get(path) if isinstance(pins, dict) else None
    except (SyntaxError, ValueError, TypeError, RecursionError):
        return None
    return None


def assess(path, change, modes, oids, base, head):
    """Return risk, affected test domains and one concise reason."""
    name = path.lower()
    if any(mode not in {"000000", "100644", "100755"} for mode in modes):
        return "UNKNOWN", set(), "Non-regular Git object; manual impact review required"
    if path in AUDIT_PINS:
        before, after = (read_side(mode, oid) for mode, oid in zip(modes, oids))
        expected_mode = "100755" if path == "scripts/staging-audit.sh" else "100644"
        if modes[1] != expected_mode or not after or hashlib.sha256(after.encode()).hexdigest() != AUDIT_PINS[path]:
            return "UNKNOWN", {"tooling", "shared"}, "Audit artifact differs from the reviewed read-only boundary"
        if before:
            previous = hashlib.sha256(before.encode()).hexdigest()
            if previous != AUDIT_PINS[path] and previous != previous_audit_pin(base, path):
                return "UNKNOWN", {"tooling", "shared"}, "Previous audit behavior needs review before changing test scope"
        if not all(audit_consumers_bounded(revision, path) for revision in (base, head)):
            return "UNKNOWN", {"tooling", "shared"}, "Audit artifact has a consumer outside the reviewed tooling boundary"
        return "HIGH", {"tooling"}, "Reviewed audit content; require safety tests, offline CLI and unchanged package proof"
    if path in AUDIT_FILES:
        before, after = (read_side(mode, oid) for mode, oid in zip(modes, oids))
        if modes[1] != "100644" or not after:
            return "UNKNOWN", {"tooling"}, "Audit guidance is missing or has an unsupported file mode"
        return "HIGH", {"tooling"}, "Audit operator guidance; require review and unchanged plugin payload"
    if path in CORE_FILES or name.startswith("templates/"):
        return "HIGH", {"shared"}, "Shared bootstrap, helper or UI affects multiple operational modules"
    if (name.startswith((".github/", "scripts/", "tests/"))
            or name in {".gitleaks.toml", ".gitattributes", ".gitignore", ".gitmodules"}
            or re.search(r"(?:^|/)(?:composer|package)(?:-lock)?\.json$|(?:^|/)dockerfile", name)):
        domains = {"tooling"}
        if name.startswith(("tests/payments/", "scripts/test-finance-", ".github/workflows/finance-")):
            domains.add("finance")
        if name.startswith("tests/reporting/"):
            domains.add("reporting")
        if name.startswith("tests/mis/"):
            domains.add("mis")
        is_shared_tooling = name.startswith((".github/workflows/", "tests/", "scripts/"))
        advisory_code = (name in {".github/workflows/fast-track-risk.yml", "scripts/risk_report.py",
                                  "scripts/fast_track_ci.py", "scripts/check.py"}
                         or name.startswith("tests/fast_track/"))
        if is_shared_tooling and domains == {"tooling"} and not advisory_code:
            domains.add("shared")
        return "HIGH", domains, "CI, policy, packaging or test harness can change validation behavior"

    is_doc = name.endswith(".md") and (name.startswith("docs/") or "/" not in name)
    if is_doc:
        if path in CONTROL_DOCS or name.startswith(("docs/modules/", "docs/audit/", "docs/readiness/")):
            return "HIGH", {"tooling"}, "Governance, evidence or module contract needs direct review"
    else:
        domains = {key for key, pattern in SENSITIVE_PATHS.items() if re.search(pattern, name)}
        if domains:
            return "HIGH", domains, f"Sensitive contract: {', '.join(sorted(domains))}"

    before, after = (read_side(mode, oid) for mode, oid in zip(modes, oids))
    edits = "\n".join(changed_lines(before, after))
    domains = {key for key, pattern in SENSITIVE_EDITS.items() if re.search(pattern, edits, re.I)}
    if domains:
        return "HIGH", domains, "Changed content affects financial, access, report or schema operations"
    if "100755" in modes:
        return "UNKNOWN", set(), "Executable content outside the mapped validation paths"
    if is_doc:
        if (re.search(r"(?m)^\s*(?:\x60{3,}|~{3,}|\t| {4})", before + "\n" + after)
                or re.search(
                    r"<|->|\b(?:deploy|migration|permission|schema|payment|invoice|wallet|commission|"
                    r"roles?|scope|gitleaks|secrets?|required checks|rulesets?|authorization)\b|"
                    r"پرداخت|فاکتور|کیف پول|پورسانت|مجوز|مهاجرت|دسترسی|کنترل اجباری", edits, re.I
                )):
            return "HIGH", {"tooling"}, "Documentation contains instructions or sensitive contract language"
        return "LOW", set(), "Plain documentation diff with no mapped runtime or policy effect"
    if (path == CAMPAIGN_CSS and change == "M" and modes == ("100644", "100644")
            and before != after and layout_signature(before) == layout_signature(after)
            and verified_campaign_caller(base) and verified_campaign_caller(head)):
        return "MEDIUM", {"campaign-ui"}, f"Bounded spacing only; verified consumer: {CAMPAIGN_CALLER}"
    return "UNKNOWN", set(), "Change or dependencies could not be bounded; review affected callers"


def expand(domains):
    pending = list(domains)
    while pending:
        for dependency in DEPENDENCIES.get(pending.pop(), ()):
            if dependency not in domains:
                domains.add(dependency)
                pending.append(dependency)
    return domains


def https(url):
    if not isinstance(url, str):
        return False
    try:
        parsed = urlsplit(url)
    except ValueError:
        return False
    return parsed.scheme == "https" and bool(parsed.hostname) and parsed.username is None


def load_evidence(path, identity):
    if path is None:
        return {}
    payload = json.loads(path.read_text(encoding="utf-8"))
    if not isinstance(payload, dict) or any(payload.get(key) != value for key, value in identity.items()):
        raise ValueError("Evidence revision or target does not match")
    entries = payload.get("tests")
    if not isinstance(entries, dict):
        raise ValueError("Evidence tests must be keyed by suite ID")
    for key, data in entries.items():
        if (key not in SUITES or not isinstance(data, dict) or data.get("status") not in STATUSES
                or not https(data.get("url"))):
            raise ValueError("Invalid suite status or evidence URL")
    return entries


def report(base_ref, head_ref, target, evidence_path=None, report_url=None):
    identity = {"base_sha": None, "head_sha": None, "target": target, "diff_base_sha": None}
    files, blockers, domains = [], [], set()
    highest = "LOW"
    try:
        base, head = commit(base_ref), commit(head_ref)
        identity.update(base_sha=base, head_sha=head)
        if git("rev-parse", "--is-shallow-repository").strip() == b"true":
            raise IncompleteDiff("Shallow history; fetch full PR history")
        ancestors = git("merge-base", "--all", base, head).decode().split()
        if len(ancestors) != 1 or not SHA.fullmatch(ancestors[0]):
            raise IncompleteDiff("No unique merge base")
        identity["diff_base_sha"] = ancestors[0]
        if ancestors[0] != base:
            blockers.append("PR head lacks the current base; combined impact needs revalidation")
        for path, change, modes, oids in diff_records(ancestors[0], head):
            try:
                risk, affected, reason = assess(path, change, modes, oids, ancestors[0], head)
            except (UnicodeError, IncompleteDiff, subprocess.TimeoutExpired) as exc:
                risk, affected, reason = "UNKNOWN", set(), (
                    str(exc) if isinstance(exc, IncompleteDiff) else "Unreadable Git blob"
                )
            if risk == "UNKNOWN":
                blockers.append(f"Unresolved impact: {path}")
            else:
                highest = max(highest, risk, key=LEVELS.index)
            domains.update(affected)
            files.append({"path": path, "change": change, "risk": risk,
                          "reason": reason, "domains": sorted(affected)})
    except (UnicodeError, IncompleteDiff, OSError, subprocess.TimeoutExpired) as exc:
        blockers.append(str(exc) if isinstance(exc, IncompleteDiff) else "Unable to read full Git diff")
    if not files and not blockers:
        blockers.append("No reviewable changes between the supplied revisions")

    domains = expand(domains)
    try:
        evidence = load_evidence(evidence_path, identity)
    except (ValueError, OSError, TypeError):
        blockers.append("Invalid or stale evidence; no submitted results credited")
        evidence = {}
    tests = []
    required_statuses = set()
    for suite, (domain, description) in SUITES.items():
        common = domain is None and (suite != "stable-boundary" or target == "main")
        required = common or domain in domains
        applicability = "REQUIRED" if required else "REVIEW" if blockers and domain else "NOT_SELECTED"
        reason = ("Existing CI policy" if common else f"Affected domain: {domain}" if required else
                  "Impact unresolved; do not omit pending review" if applicability == "REVIEW" else
                  "Only required for PRs to main" if suite == "stable-boundary" else
                  f"No mapped impact on {domain}")
        entry = evidence.get(suite, {})
        status = entry.get("status", "NOT_RUN")
        if required:
            required_statuses.add(status)
        tests.append({"id": suite, "test": description, "applicability": applicability,
                      "reason": reason, "status": status, "evidence": entry.get("url")})
    status = next((item for item in ("FAIL", "BLOCKED", "NOT_RUN") if item in required_statuses), "PASS")
    return {"mode": "REPORT_ONLY", **identity, "risk": "UNKNOWN" if blockers else highest,
            "highest_known_risk": highest if files else None,
            "status": "BLOCKED" if blockers else status, "blockers": blockers,
            "changed_files": files, "affected_domains": sorted(domains),
            "tests": tests, "report_evidence": report_url,
            "test_evidence_source": "caller_supplied_not_verified" if evidence else None}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--base", required=True, help="Current PR base revision")
    parser.add_argument("--head", required=True, help="Current PR head revision")
    parser.add_argument("--target", required=True, choices=("main", "experimental"))
    parser.add_argument("--evidence", type=Path, help="Optional revision-bound JSON of external results")
    parser.add_argument("--report-url", help="HTTPS link to this report's workflow run")
    args = parser.parse_args()
    if args.report_url and not https(args.report_url):
        parser.error("--report-url must be HTTPS")
    print(json.dumps(report(args.base, args.head, args.target, args.evidence, args.report_url),
                     indent=2, ensure_ascii=True))


if __name__ == "__main__":
    main()
