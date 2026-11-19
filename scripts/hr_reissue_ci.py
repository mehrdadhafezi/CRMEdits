"""Bound native HR evidence to the reviewed 2.0.239 runtime, never arbitrary core edits."""
import hashlib
import json
from pathlib import Path

from package import committed_members
from risk_report import git

BASE = "3660fe46dcb79f7a96f0b3f04326f119a4d78441"
RUNTIME = {
    "assets/css/hr-invoice-reissue.css": "478e472cee6117bd3335b240ead011067e61dd475040584a8798253e3a7738da",
    "assets/js/hr-invoice-reissue.js": "72457535ef370ea1d3395dad9188ce5625186ae31f0c4ff72de672780a090b98",
    "includes/class-sn-hr-front-panel.php": "d582359f022c3b631944a56f8b1f5e7484614e1e92aa89241203761a9b7d3ed9",
    "includes/class-sn-hr-invoice-reissue.php": "1ad645e71cfdaeb63fb9bb81d12646515d2ee626b947099930f88ba7dfb683d8",
    "includes/class-sn-plugin.php": "b736a10fbfdef91c40e5c6d7da96a1c78fc888475a3a8c521490e49938afec52",
    "sales-network.php": "46a5fb399668cb5bbe0262d5c48f5286019f649176519e47684c05d08d546912",
}
NON_RUNTIME = {
    "docs/API_CONTRACTS.md", "docs/BUSINESS_RULES.md", "docs/modules/HR_HIERARCHY_SCOPE.md",
    "docs/modules/INVOICES.md", "docs/RELEASE_2.0.239_HR_INVOICE_REISSUE.md", "docs/tasks/HR_INVOICE_REISSUE_239.md",
    "tests/payments/integration/verify.php", ".github/workflows/ci.yml",
    "scripts/fast_track_ci.py", "scripts/hr_reissue_ci.py", "scripts/test-hr-integration.sh",
    "tests/fast_track/test_hr_reissue_ci.py",
}
HR_TESTS = {
    "README.md", "browser.cjs", "invoice-reissue.php", "native-support.php",
    "native-fixtures.php", "native.php", "native-woo.php", "native-invoices.php",
    "native-finance.php", "private-browser-bridge.php", "ci-browser.cjs",
    "native-ledger.php", "ci-code-state.php", "ci-mis-bootstrap.php", "ci-mis-install.php",
}
JOB = "HR reissue native regression"
STEPS = {
    "Verify exact PR merge revision", "Run isolated HR, ledger, schema and MIS regressions",
    "Verify bounded HR evidence", "Upload sanitized HR evidence",
}
SUITES = {"ui", "access", "ledger", "schema", "mis"}


def bounded_payload(before, after):
    changed = {name.removeprefix("sales-network-v4/"): body for name, body in after.items()
               if before.get(name) != body}
    return (len(before) == 116 and len(after) == 119 and before.keys() <= after.keys()
            and changed.keys() == RUNTIME.keys()
            and all(hashlib.sha256(body).hexdigest() == RUNTIME[name] for name, body in changed.items()))


def scope(base, head, paths):
    allowed = set(RUNTIME) | NON_RUNTIME | {"tests/hr/" + name for name in HR_TESTS}
    if base != BASE or not set(RUNTIME) <= paths or not paths <= allowed:
        return False
    return bounded_payload(committed_members(Path.cwd(), base), committed_members(Path.cwd(), head))


def job_passed(jobs):
    matches = [job for job in jobs if job.get("name") == JOB]
    return len(matches) == 1 and matches[0].get("conclusion") == "success" and STEPS <= {
        step["name"] for step in matches[0].get("steps", []) if step.get("conclusion") == "success"
    }


def verify_output(output, merge):
    root = Path(output)
    expected = {
        "hr239-native-summary.json": 43, "hr239-invoices-summary.json": 23,
        "hr239-finance-summary.json": 49, "hr239-ledger-summary.json": 24,
        "browser/summary.json": 32, "code-rollback.json": 27, "mis-summary.json": 100,
    }
    for name, minimum in expected.items():
        data = json.loads((root / name).read_text(encoding="utf-8"))
        if (data.get("passed") is not True or data.get("synthetic") is not True
                or type(data.get("assertions")) is not int or data["assertions"] < minimum):
            raise ValueError("Missing or incomplete HR native evidence: " + name)
        if name.startswith("hr239-") and any(data.get(key) != RUNTIME[path] for key, path in (
                ("core_sha256", "includes/class-sn-plugin.php"),
                ("policy_sha256", "includes/class-sn-hr-invoice-reissue.php"))):
            raise ValueError("HR evidence belongs to a different runtime")
    browser = json.loads((root / "browser/summary.json").read_text(encoding="utf-8"))
    if browser["transport"] != "native WordPress HTTP login, shortcode and admin-ajax":
        raise ValueError("A CLI proxy cannot substitute for native HTTP browser evidence")
    mis = json.loads((root / "mis-summary.json").read_text(encoding="utf-8"))
    if mis.get("scripts") != 10 or mis.get("isolated_database") is not True:
        raise ValueError("MIS must run all ten default scripts in its own disposable database")
    if git("rev-parse", "HEAD").decode().strip() != merge:
        raise ValueError("HR output checkout no longer matches the tested merge")
    proof = {"merge": merge, "runtime": RUNTIME, "synthetic": True, "suites": sorted(SUITES)}
    (root / "verified-revision.json").write_text(json.dumps(proof, indent=2) + "\n", encoding="utf-8")
