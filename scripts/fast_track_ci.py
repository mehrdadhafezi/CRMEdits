"""Plan CRM PR tests and summarize evidence from the current GitHub Actions run."""
import argparse
import hashlib
import json
import os
from pathlib import Path
from urllib.error import URLError
from urllib.request import Request, urlopen

from risk_report import AUDIT_FILES, LEVELS, SHA, SUITES, IncompleteDiff, git, report
import hr_reissue_ci as hr_ci


JOBS = {
    "syntax": "syntax", "docs-hygiene": "documentation", "secrets": "secrets",
    "stable-boundary": "stable-pr-boundary", "tooling": "plan",
    "finance": "finance", "reporting": "finance",
}
FINANCE_DOMAINS = {"finance", "reporting", "access", "ledger", "schema", "mis", "shared"}
FINANCE_MATRIX = {
    ("7.1.4", "2.0.235"), ("7.1.4", "2.0.236"),
    ("7.1.4", "2.0.237-installed"), ("6.9.11", "2.0.237-installed"),
}
FINANCE_STEPS = {
    "Verify exact PR merge revision", "Isolated WordPress, MySQL, AJAX and Chromium regression",
    "Verify native Finance evidence", "Upload synthetic evidence and exact installation candidate",
}
# Only these files are eligible for tooling-only validation. Native fixtures, packaging
# inputs and every deployable path stay outside this set, even in mixed PRs.
CAMPAIGN_TEST = "tests/fast_track/campaign-smoke.cjs"
POLICY_DOCS = {"docs/DEVELOPER_HANDBOOK.md", "docs/TESTING_STANDARDS.md"}
ORCHESTRATION_FILES = {
    ".github/workflows/ci.yml", ".github/workflows/finance-integration.yml",
    ".github/workflows/fast-track-risk.yml", "scripts/risk_report.py", "scripts/fast_track_ci.py",
    "tests/fast_track/test_risk_report.py", "tests/fast_track/test_fast_track_ci.py", CAMPAIGN_TEST,
}
TOOLING_ONLY = ORCHESTRATION_FILES | POLICY_DOCS
RELEASE_VALIDATION = {
    ".github/workflows/release-candidate.yml", "scripts/release_candidate.py",
    "tests/fast_track/test_release_candidate.py",
}
RELEASE_SCOPE = TOOLING_ONLY | RELEASE_VALIDATION | {"scripts/package.py", "docs/DEPLOYMENT.md", "docs/LOCAL_SETUP.md"}
SUMMARY_JOB = "Fast-Track readiness summary"
PLAN_JOB = "Fast-Track test plan"
CAMPAIGN_JOB = "Campaign spacing browser regression"
AUDIT_STEPS = {
    "Validate orchestration and the selector", "Validate read-only audit boundary",
    "Verify package reproducibility and unchanged payload",
}
UNCOVERED = {
    "ui": "No verified browser suite for the affected view and viewport",
    "access": "Finance fixtures cover their own endpoints; review wider Roles and Scope coverage",
    "ledger": "Finance fixtures do not cover the full Wallet and Commission contract",
    "schema": "Finance fixtures cover their upgrade and rollback paths only",
    "mis": "Requires a separately verified disposable MIS database and regression suite",
}


def revision(pr):
    return {
        "pr": pr["number"], "base": pr["base"]["sha"], "head": pr["head"]["sha"],
        "target": pr["base"]["ref"],
    }


def verify_checkout(pr, merge_sha):
    expected = [merge_sha, pr["base"], pr["head"]]
    if git("rev-list", "--parents", "-n", "1", "HEAD").decode().split() != expected:
        raise IncompleteDiff("Checked-out merge commit does not have the expected Base and Head")


def legacy_finance_path(path):
    return (
        path.startswith(("includes/", "assets/js/", "tests/payments/", "tests/reporting/"))
        or path in {
            "sales-network.php", "scripts/test-finance-integration.sh",
            ".github/workflows/finance-integration.yml",
        }
    )


def make_plan(pr, merge_sha, selection):
    domains = selection["affected_domains"]
    files = selection["changed_files"]
    paths = {change["path"] for change in files}
    unbounded_audit = (
        bool(paths & AUDIT_FILES) and not paths <= AUDIT_FILES | TOOLING_ONLY
        and not set(domains) - {"tooling"}
    )
    unknown = (
        selection["risk"] == "UNKNOWN"
        or selection["base_sha"] != pr["base"]
        or selection["head_sha"] != pr["head"]
        or selection["target"] != pr["target"]
        or unbounded_audit
    )
    finance = (
        unknown or bool(FINANCE_DOMAINS.intersection(domains))
        or any(legacy_finance_path(change["path"]) for change in files)
    )
    campaign_ui = not unknown and selection["risk"] == "MEDIUM" and set(domains) == {"campaign-ui", "ui"}
    high = not unknown and selection["risk"] == "HIGH"
    audit_only = (
        high and bool(paths & AUDIT_FILES) and paths <= AUDIT_FILES | TOOLING_ONLY
        and all(change["risk"] == "HIGH" and change["domains"] == ["tooling"]
                for change in files if change["path"] in AUDIT_FILES)
    )
    release_parity = high and bool(paths & RELEASE_VALIDATION) and paths <= RELEASE_SCOPE
    package_parity = release_parity or audit_only
    tooling_only = high and (package_parity or bool(paths) and paths <= TOOLING_ONLY)
    reasons = sorted({change["reason"] for change in files})[:6]
    suites = {item["id"]: item["applicability"] for item in selection["tests"]}
    if unbounded_audit:
        reasons.append("Audit tooling depends on changes outside the reviewed scope")
        suites.update({suite: "REVIEW" for suite, (domain, _) in SUITES.items()
                       if domain is not None and suites[suite] == "NOT_SELECTED"})
    if tooling_only:
        # Keep validation of the orchestrator and its actual native suites. Do not
        # inherit broad plugin-runtime recommendations when all package inputs are untouched.
        finance = finance or release_parity or bool(paths & (ORCHESTRATION_FILES - {CAMPAIGN_TEST}))
        campaign_ui = CAMPAIGN_TEST in paths
        domains = ["tooling"]
        if finance:
            domains.extend(("finance", "reporting"))
        if campaign_ui:
            domains.extend(("campaign-ui", "ui"))
        suites.update({suite: "REQUIRED" if domain in domains else "NOT_SELECTED"
                       for suite, (domain, _) in SUITES.items() if domain is not None})
        reasons = [
            "Reviewed audit scope; require safety/CLI proof and identical plugin payload for this revision"
            if audit_only else
            "Release tooling changed; require identical plugin payload, reproducible builds and native Finance"
            if release_parity else
            "Full diff is limited to named orchestration files; plugin package and native test inputs are unchanged"
        ]
    if finance:
        suites["finance"] = "REQUIRED"
    hr_native = not unknown and not tooling_only and hr_ci.scope(pr["base"], pr["head"], paths)
    return {
        **pr, "merge": merge_sha, "risk": "UNKNOWN" if unknown else selection["risk"],
        "domains": domains, "files": len(files), "finance": finance,
        "campaign_ui": campaign_ui, "tooling_only": tooling_only, "package_parity": package_parity,
        "audit_only": audit_only,
        "hr_native": hr_native,
        "reasons": reasons, "suites": suites,
    }


def plan_pr(pr, merge_sha):
    verify_checkout(pr, merge_sha)
    selection = report(pr["base"], pr["head"], pr["target"])
    print(json.dumps(selection, ensure_ascii=True))
    return make_plan(pr, merge_sha, selection)


def finance_matrix_passed(jobs):
    expected = {
        f"finance / WP {wordpress} MySQL / upgrade from {baseline}"
        for wordpress, baseline in FINANCE_MATRIX
    }
    matrix = [job for job in jobs if job.get("name") in expected]
    if len(matrix) != len(expected) or {job["name"] for job in matrix} != expected:
        return False
    return all(job.get("conclusion") == "success" and FINANCE_STEPS <= {
        step["name"] for step in job.get("steps", []) if step.get("conclusion") == "success"
    } for job in matrix)


def audit_job_passed(jobs):
    plans = [job for job in jobs if job.get("name") == PLAN_JOB]
    return len(plans) == 1 and plans[0].get("conclusion") == "success" and AUDIT_STEPS <= {
        step["name"] for step in plans[0].get("steps", []) if step.get("conclusion") == "success"
    }


def verify_native_output(wordpress, baseline, merge_sha, output, dist):
    if f"Baseline: {baseline}" not in (output / "provisioning.txt").read_text(encoding="utf-8").splitlines():
        raise ValueError("Finance provisioning evidence has the wrong matrix baseline")
    phases = ("upgrade", "rollback", "restored", "final")
    for filename in [*(f"mysql-{phase}.json" for phase in phases),
                     "reporting-summary.json", "browser-summary.json"]:
        data = json.loads((output / filename).read_text(encoding="utf-8"))
        if data["synthetic"] is not True or type(data["assertions"]) is not int or data["assertions"] < 1:
            raise ValueError(f"Missing native synthetic assertions: {filename}")
        if filename == "browser-summary.json":
            if not data["chromium"] or json.loads((output / "browser-errors.json").read_text(encoding="utf-8")) != []:
                raise ValueError("Browser regression evidence is incomplete or has errors")
        elif data["wordpress"] != wordpress or not data["mysql"]:
            raise ValueError(f"Unexpected native test runtime: {filename}")
        if filename.startswith("mysql-") and filename != f"mysql-{data['phase']}.json":
            raise ValueError(f"Unexpected native test phase: {filename}")
    archives = list(dist.glob("*.zip"))
    if len(archives) != 1 or not archives[0].name.endswith(f"-{merge_sha[:12]}.zip"):
        raise ValueError("Finance ZIP is missing or belongs to a different revision")
    archive = archives[0]
    expected = f"{hashlib.sha256(archive.read_bytes()).hexdigest()}  {archive.name}"
    if archive.with_suffix(".zip.sha256").read_text(encoding="utf-8").strip() != expected:
        raise ValueError("Finance ZIP checksum mismatch")


def outcome(plan, expected, live, results, run_url, matrix_verified=False, evidence_run=None, evidence_error=None,
            audit_verified=False, hr_verified=False):
    problems = [evidence_error] if evidence_error else []
    plan_matches = (isinstance(plan, dict) and plan.get("risk") in (*LEVELS, "UNKNOWN")
                    and isinstance(plan.get("suites"), dict) and plan["suites"].keys() == SUITES.keys()
                    and all(plan.get(key) == value for key, value in expected.items())
                    and type(plan.get("audit_only", False)) is bool
                    and (not plan.get("audit_only") or (
                        plan["risk"] == "HIGH" and plan.get("package_parity") is True
                        and plan["suites"].get("tooling") == "REQUIRED"
                    )))
    live_matches = live is not None and all(live.get(key) == value for key, value in expected.items())
    if not plan_matches:
        problems.append("Missing plan or plan revision mismatch")
    if not live_matches:
        problems.append("Unable to verify the live PR revision or Base/Head changed")
    risk = plan["risk"] if plan_matches and live_matches else "UNKNOWN"
    if risk == "UNKNOWN":
        problems.append("Fast-Track disabled; review the complete affected test scope")

    # Preserve the mandatory jobs even if planning failed or returned no suite list.
    suites = {suite: "REQUIRED" for suite in ("syntax", "docs-hygiene", "secrets")}
    if expected["target"] == "main":
        suites["stable-boundary"] = "REQUIRED"
    if plan_matches:
        suites = {**plan["suites"], **suites}
    else:
        suites.update(tooling="REQUIRED", finance="REQUIRED")

    rows = []
    for suite, applicability in suites.items():
        job = ("campaign-ui" if suite == "ui" and plan_matches and plan["campaign_ui"] else JOBS.get(suite))
        if plan_matches and plan.get("hr_native") is True and suite in hr_ci.SUITES:
            job = "hr-native"
        result = results.get(job)
        if applicability == "NOT_SELECTED":
            status, reason = "SKIPPED", "Outside the mapped test scope"
        elif applicability == "REVIEW":
            status, reason = "BLOCKED", "Applicability unresolved"
        elif result == "failure":
            status, reason = "FAIL", "GitHub job failed"
        elif not (plan_matches and live_matches):
            status, reason = "BLOCKED", "Base, Head or tested merge revision unverified"
        elif suite == "tooling" and plan.get("audit_only") and not audit_verified:
            status, reason = "BLOCKED", "Audit safety, offline CLI and package parity steps are unverified in this run attempt"
        elif result == "success" and job == "finance" and not matrix_verified:
            status, reason = "BLOCKED", "The four Finance jobs and their test steps were not verified in this run attempt"
        elif result == "success" and job == "hr-native" and not hr_verified:
            status, reason = "BLOCKED", "Bounded HR native tests and output verification are unverified in this run attempt"
        elif result == "success":
            status, reason = "PASS", "Succeeded in this run"
        else:
            status = "BLOCKED"
            reason = UNCOVERED.get(suite, "Required GitHub job did not succeed")
        if status in {"BLOCKED", "FAIL"}:
            problems.append(f"{suite}: {reason}")
        rows.append({"suite": suite, "applicability": applicability,
                     "job_result": result, "status": status, "reason": reason})
    return {
        **expected, "risk": risk, "reasons": plan["reasons"] if plan_matches else [],
        "domains": plan["domains"] if plan_matches else [], "run": run_url, "tests": rows,
        "evidence_run": evidence_run,
        "blockers": problems, "status": "BLOCKED" if problems else "READY FOR MERGE CHECK",
    }


def github_read(endpoint):
    # The built-in token is read-only and is scoped to the summary step.
    api = os.environ["GITHUB_API_URL"]
    repository = os.environ["GITHUB_REPOSITORY"]
    request = Request(f"{api}/repos/{repository}/{endpoint}", headers={
        "Authorization": f"Bearer {os.environ['GH_TOKEN']}",
        "Accept": "application/vnd.github+json",
        "X-GitHub-Api-Version": "2022-11-28",
    })
    with urlopen(request, timeout=15) as response:
        return json.load(response)


def live_revision(pr):
    try:
        payload = github_read(f"pulls/{pr}")
        if payload["state"] == "open":
            return {**revision(payload), "merge": payload["merge_commit_sha"]}
    except (URLError, OSError, ValueError, KeyError, TypeError):
        return None
    return None


def run_jobs(run_id, attempt):
    return github_read(f"actions/runs/{run_id}/attempts/{attempt}/jobs?per_page=100")["jobs"]


def verify_finance_run():
    try:
        return finance_matrix_passed(run_jobs(os.environ["GITHUB_RUN_ID"], os.environ["GITHUB_RUN_ATTEMPT"]))
    except (URLError, OSError, ValueError, KeyError, TypeError):
        return False


def verify_audit_run():
    try:
        return audit_job_passed(run_jobs(os.environ["GITHUB_RUN_ID"], os.environ["GITHUB_RUN_ATTEMPT"]))
    except (URLError, OSError, ValueError, KeyError, TypeError):
        return False


def full_run_title(pr):
    return f"Fast-Track full PR #{pr['pr']} {pr['target']} @{pr['merge']}"


def reusable_run(runs, expected):
    matching = [run for run in runs if run.get("display_title") == full_run_title(expected)
                and run.get("head_sha") == expected["head"] and run.get("event") == "pull_request"]
    if not matching:
        raise ValueError("No full CRM CI run matches this PR and tested revision")
    latest = max(matching, key=lambda run: run["run_number"])
    if latest["status"] != "completed" or latest["conclusion"] != "success":
        raise ValueError("The latest full CRM CI run for this PR and revision has not succeeded")
    return latest


def read_optional_evidence(expected, run_url):
    if not SHA.fullmatch(expected["head"]):
        raise ValueError("Invalid Head SHA")
    # The fixed workflow endpoint and generated run title bind the source to this
    # PR, target and merge commit. Checkout verification ties the latter to Base/Head.
    runs = github_read(f"actions/workflows/ci.yml/runs?event=pull_request&head_sha={expected['head']}&per_page=100")
    source = reusable_run(runs["workflow_runs"], expected)
    jobs = run_jobs(source["id"], source["run_attempt"])
    successes = {job["name"] for job in jobs if job["conclusion"] == "success"}
    if not {PLAN_JOB, SUMMARY_JOB} <= successes:
        raise ValueError("The matching run attempt has no successful plan and readiness summary")
    matrix_verified = finance_matrix_passed(jobs)
    results = {"plan": "success"}
    if matrix_verified:
        results["finance"] = "success"
    if CAMPAIGN_JOB in successes:
        results["campaign-ui"] = "success"
    if hr_ci.job_passed(jobs):
        results["hr-native"] = "success"
    return (results, matrix_verified, f"{run_url.rsplit('/', 1)[0]}/{source['id']}/attempts/{source['run_attempt']}",
            audit_job_passed(jobs))


def markdown(result):
    lines = [
        f"### PR #{result['pr']} · {result['status']}",
        "",
        f"- Base `{result['base']}` (`{result['target']}`) · Head `{result['head']}`",
        f"- Risk **{result['risk']}** · Domains {', '.join(result['domains']) or 'none mapped'}",
        f"- [Run and detailed evidence]({result['run']})",
    ]
    lines.extend(f"- {reason}" for reason in result["reasons"])
    if result["evidence_run"]:
        lines.append(f"- [Optional test evidence for this exact revision]({result['evidence_run']})")
    lines.extend(["", "| Suite | Applicability | Result |", "| --- | --- | --- |"])
    lines.extend(
        f"| {row['suite']} | {row['applicability']} | {row['status']} |" for row in result["tests"]
    )
    if result["blockers"]:
        lines.extend(["", "**Blockers**", ""])
        lines.extend(f"- {reason}" for reason in result["blockers"])
    return "\n".join(lines) + "\n"


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("stage", choices=("plan", "summary", "verify-checkout", "verify-finance-output", "verify-hr-output"))
    stage = parser.parse_args().stage
    if stage == "verify-hr-output":
        event = json.loads(Path(os.environ["GITHUB_EVENT_PATH"]).read_text(encoding="utf-8"))
        pr = revision(event["pull_request"])
        verify_checkout(pr, os.environ["GITHUB_SHA"])
        selection = report(pr["base"], pr["head"], pr["target"])
        if not hr_ci.scope(pr["base"], pr["head"], {c["path"] for c in selection["changed_files"]}):
            raise ValueError("HR tests cannot certify unreviewed runtime changes")
        hr_ci.verify_output("test-results/hr", os.environ["GITHUB_SHA"])
        return 0
    if stage == "verify-finance-output":
        verify_native_output(os.environ["SN_TEST_WORDPRESS"], os.environ["SN_TEST_BASELINE"], os.environ["GITHUB_SHA"],
                             Path("test-results/finance"), Path("dist"))
        return 0
    event = json.loads(Path(os.environ["GITHUB_EVENT_PATH"]).read_text(encoding="utf-8"))
    pr = revision(event["pull_request"])
    merge_sha = os.environ["GITHUB_SHA"]
    if stage == "verify-checkout":
        verify_checkout(pr, merge_sha)
        return 0
    if stage == "plan":
        plan = plan_pr(pr, merge_sha)
        with Path(os.environ["GITHUB_OUTPUT"]).open("a", encoding="utf-8") as output:
            print(f"plan={json.dumps(plan, ensure_ascii=True, separators=(',', ':'))}", file=output)
            print(f"finance={str(plan['finance']).lower()}", file=output)
            print(f"campaign_ui={str(plan['campaign_ui']).lower()}", file=output)
            print(f"hr_native={str(plan['hr_native']).lower()}", file=output)
        return 0
    try:
        plan = json.loads(os.environ.get("PLAN") or "null")
    except ValueError:
        plan = None
    results = {job: data["result"] for job, data in json.loads(os.environ["NEEDS"]).items()}
    run_url = f"{os.environ['GITHUB_SERVER_URL']}/{os.environ['GITHUB_REPOSITORY']}/actions/runs/{os.environ['GITHUB_RUN_ID']}"
    expected = {**pr, "merge": merge_sha}
    evidence_run = evidence_error = None
    matrix_verified = False
    audit_verified = False
    hr_verified = False
    if os.environ.get("METADATA_ONLY") == "true":
        try:
            plan = plan_pr(pr, merge_sha)
            optional, matrix_verified, evidence_run, audit_verified = read_optional_evidence(expected, run_url)
            results.update(optional)
            hr_verified = optional.get("hr-native") == "success"
        except (URLError, OSError, ValueError, KeyError, TypeError, IncompleteDiff) as exc:
            print(f"Unable to reuse optional evidence ({type(exc).__name__})")
            evidence_error = "Latest exact full CI evidence is missing, pending, failed or unverified"
    elif results.get("finance") == "success":
        matrix_verified = verify_finance_run()
    if os.environ.get("METADATA_ONLY") != "true" and isinstance(plan, dict) and plan.get("audit_only"):
        audit_verified = verify_audit_run()
    if os.environ.get("METADATA_ONLY") != "true" and results.get("hr-native") == "success":
        try:
            hr_verified = hr_ci.job_passed(run_jobs(os.environ["GITHUB_RUN_ID"], os.environ["GITHUB_RUN_ATTEMPT"]))
        except (URLError, OSError, ValueError, KeyError, TypeError):
            hr_verified = False
    result = outcome(plan, expected, live_revision(pr["pr"]), results, run_url,
                     matrix_verified, evidence_run, evidence_error, audit_verified, hr_verified)
    print(json.dumps(result, indent=2, ensure_ascii=True))
    Path(os.environ["GITHUB_STEP_SUMMARY"]).write_text(markdown(result), encoding="utf-8")
    return int(result["status"] == "BLOCKED")


if __name__ == "__main__":
    raise SystemExit(main())
