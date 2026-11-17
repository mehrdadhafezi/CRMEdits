"""Plan CRM PR tests and summarize evidence from the current GitHub Actions run."""
import argparse
import json
import os
from pathlib import Path
from urllib.error import URLError
from urllib.request import Request, urlopen

from risk_report import LEVELS, SUITES, IncompleteDiff, git, report


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
    "Upload synthetic evidence and exact installation candidate",
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
    unknown = (
        selection["risk"] == "UNKNOWN"
        or selection["base_sha"] != pr["base"]
        or selection["head_sha"] != pr["head"]
        or selection["target"] != pr["target"]
    )
    finance = (
        unknown or bool(FINANCE_DOMAINS.intersection(domains))
        or any(legacy_finance_path(change["path"]) for change in files)
    )
    return {
        **pr, "merge": merge_sha, "risk": "UNKNOWN" if unknown else selection["risk"],
        "domains": domains, "files": len(files), "finance": finance,
        "campaign_ui": not unknown and selection["risk"] == "MEDIUM" and set(domains) == {"campaign-ui", "ui"},
        "reasons": sorted({change["reason"] for change in files})[:6],
        "suites": {
            item["id"]: "REQUIRED" if finance and item["id"] == "finance" else item["applicability"]
            for item in selection["tests"]
        },
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


def outcome(plan, expected, live, results, run_url, matrix_verified=False):
    problems = []
    plan_matches = (isinstance(plan, dict) and plan.get("risk") in (*LEVELS, "UNKNOWN")
                    and isinstance(plan.get("suites"), dict) and plan["suites"].keys() == SUITES.keys()
                    and all(plan.get(key) == value for key, value in expected.items()))
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
        result = results.get(job)
        if applicability == "NOT_SELECTED":
            status, reason = "SKIPPED", "Outside the mapped test scope"
        elif applicability == "REVIEW":
            status, reason = "BLOCKED", "Applicability unresolved"
        elif result == "failure":
            status, reason = "FAIL", "GitHub job failed"
        elif not (plan_matches and live_matches):
            status, reason = "BLOCKED", "Base, Head or tested merge revision unverified"
        elif result == "success" and job == "finance" and not matrix_verified:
            status, reason = "BLOCKED", "The four Finance jobs and their test steps were not verified in this run attempt"
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


def verify_finance_run():
    run_id, attempt = os.environ["GITHUB_RUN_ID"], os.environ["GITHUB_RUN_ATTEMPT"]
    try:
        payload = github_read(f"actions/runs/{run_id}/attempts/{attempt}/jobs?per_page=100")
        return finance_matrix_passed(payload["jobs"])
    except (URLError, OSError, ValueError, KeyError, TypeError):
        return False


def markdown(result):
    lines = [
        f"### PR #{result['pr']} · {result['status']}",
        "",
        f"- Base `{result['base']}` (`{result['target']}`) · Head `{result['head']}`",
        f"- Risk **{result['risk']}** · Domains {', '.join(result['domains']) or 'none mapped'}",
        f"- [Run and detailed evidence]({result['run']})",
    ]
    lines.extend(f"- {reason}" for reason in result["reasons"])
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
    parser.add_argument("stage", choices=("plan", "summary", "verify-checkout"))
    stage = parser.parse_args().stage
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
        return 0
    try:
        plan = json.loads(os.environ.get("PLAN") or "null")
    except ValueError:
        plan = None
    results = {job: data["result"] for job, data in json.loads(os.environ["NEEDS"]).items()}
    run_url = f"{os.environ['GITHUB_SERVER_URL']}/{os.environ['GITHUB_REPOSITORY']}/actions/runs/{os.environ['GITHUB_RUN_ID']}"
    matrix_verified = results.get("finance") == "success" and verify_finance_run()
    result = outcome(plan, {**pr, "merge": merge_sha}, live_revision(pr["pr"]),
                     results, run_url, matrix_verified)
    print(json.dumps(result, indent=2, ensure_ascii=True))
    Path(os.environ["GITHUB_STEP_SUMMARY"]).write_text(markdown(result), encoding="utf-8")
    return int(result["status"] == "BLOCKED")


if __name__ == "__main__":
    raise SystemExit(main())
