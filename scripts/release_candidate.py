"""Prepare or verify a GitHub release candidate, or check local Staging evidence."""
import argparse
from io import BytesIO
import json
import os
from pathlib import Path
import subprocess
import tarfile
import tempfile
from urllib.error import URLError
from urllib.request import Request, urlopen
from zipfile import BadZipFile, ZipFile

from package import PLUGIN, ROOT, SHA, SOURCE, build, git, json_bytes, sha256, verify_package


REPO = "mehrdadhafezi/CRMEdits"
WORKFLOW = ".github/workflows/release-candidate.yml"
BASE_CHECKS = {"PHP and JavaScript syntax", "Documentation and repository hygiene", "Secret scan"}
PR_CHECKS = BASE_CHECKS | {"Stable PR branch boundary", "Fast-Track readiness summary"}
STAGING_CHECKS = (
    "canonical_domain", "document_root", "plugin_path", "php_user_pool",
    "database_user", "symlinks_mounts", "cache_sessions_uploads",
    "sms_mail_payment_api_egress", "cron_workers", "resource_limits",
    "synthetic_backup_restore_plan", "disposable_restore_test", "wp_php_mariadb",
    "woocommerce_dependencies",
)


def github(endpoint):
    headers = {"Accept": "application/vnd.github+json", "X-GitHub-Api-Version": "2022-11-28"}
    if token := os.environ.get("GH_TOKEN"):
        headers["Authorization"] = f"Bearer {token}"
    request = Request(f"https://api.github.com/repos/{REPO}/{endpoint}", headers=headers)
    with urlopen(request, timeout=20) as response:
        return json.load(response)


def current_main(api, commit, ref):
    if ref != "refs/heads/main" or not SHA.fullmatch(commit):
        raise ValueError("Release candidates require main and a full commit SHA")
    if api("git/ref/heads/main")["object"]["sha"] != commit:
        raise ValueError("Commit is no longer the current main revision")


def workflow_runs(api, workflow, event, commit, branch=""):
    return api(f"actions/workflows/{workflow}/runs?event={event}&head_sha={commit}"
               f"&per_page=100{branch}")["workflow_runs"]


def latest_success(runs):
    if not runs:
        raise ValueError("No matching CI run")
    latest = max(runs, key=lambda item: item["run_number"])
    if (latest["status"], latest["conclusion"]) != ("completed", "success"):
        raise ValueError("Latest matching CI run did not succeed")
    return latest


def require_jobs(api, run, required):
    jobs = api(f"actions/runs/{run['id']}/attempts/{run['run_attempt']}/jobs?per_page=100")["jobs"]
    for name in required:
        matches = [job for job in jobs if job["name"] == name]
        if len(matches) != 1 or matches[0]["conclusion"] != "success":
            raise ValueError(f"Missing valid CI result for {name}")


def release_evidence(api, commit, ref, pr_number):
    current_main(api, commit, ref)
    pr = api(f"pulls/{pr_number}")
    if not pr["merged"] or pr["base"]["ref"] != "main" or pr["merge_commit_sha"] != commit:
        raise ValueError("PR is not the merge into main that produced this commit")
    base, head = pr["base"]["sha"], pr["head"]["sha"]
    if not all(SHA.fullmatch(sha) for sha in (base, head)):
        raise ValueError("Missing full PR revisions")
    accepted = api(f"git/commits/{commit}")
    if [parent["sha"] for parent in accepted["parents"]] != [base, head]:
        raise ValueError("Expected a merge commit with the exact PR Base and Head")
    prefix = f"Fast-Track full PR #{pr_number} main @"
    runs = workflow_runs(api, "ci.yml", "pull_request", head)
    pr_run = latest_success([run for run in runs if run["event"] == "pull_request"
                             and run["head_sha"] == head and run["display_title"].startswith(prefix)
                             and SHA.fullmatch(run["display_title"][len(prefix):])])
    tested_merge = pr_run["display_title"][len(prefix):]
    tested = api(f"git/commits/{tested_merge}")
    if ([parent["sha"] for parent in tested["parents"]] != [base, head]
            or tested["tree"]["sha"] != accepted["tree"]["sha"]):
        raise ValueError("The merged tree differs from the tested PR revision")
    require_jobs(api, pr_run, PR_CHECKS)
    runs = workflow_runs(api, "ci.yml", "push", commit, "&branch=main")
    push_run = latest_success([run for run in runs if (run["event"], run["head_branch"], run["head_sha"])
                               == ("push", "main", commit)])
    require_jobs(api, push_run, BASE_CHECKS)
    return {
        "status": "PASS", "pr": pr_number, "base": base, "head": head, "tested_merge": tested_merge,
        "tree": accepted["tree"]["sha"], "pr_run": pr_run["id"], "pr_attempt": pr_run["run_attempt"],
        "main_run": push_run["id"], "main_attempt": push_run["run_attempt"],
    }


def prepare(api, commit, ref, pr_number, run_id, attempt, output):
    evidence = release_evidence(api, commit, ref, pr_number)
    runs = workflow_runs(api, "release-candidate.yml", "workflow_dispatch", commit, "&branch=main")
    if any(run["head_sha"] == commit and run["head_branch"] == "main" and run["conclusion"] == "success"
           for run in runs):
        raise ValueError("A candidate already exists for this revision; reuse its original artifact")
    manifest = build(ROOT, output, commit)
    archive = output / manifest["zip"]
    verify_package(archive.read_bytes(), archive.with_suffix(".zip.manifest.json").read_bytes(),
                   archive.with_suffix(".zip.sha256").read_bytes(), commit)
    receipt = {"schema": 1, "repository": REPO, "ref": ref, "commit": commit,
               "version": manifest["version"], "zip": manifest["zip"], "sha256": manifest["sha256"],
               "ci": evidence, "run_id": run_id, "run_attempt": attempt}
    (output / "candidate.json").write_bytes(json_bytes(receipt))
    return receipt


def verify_candidate(api, data, artifact_id):
    artifact = api(f"actions/artifacts/{artifact_id}")
    if artifact["id"] != artifact_id or artifact["expired"] or artifact.get("digest") != f"sha256:{sha256(data)}":
        raise ValueError("Artifact is expired or does not match the GitHub archive digest")
    owner_run = artifact["workflow_run"]
    run = api(f"actions/runs/{owner_run['id']}")
    commit = run["head_sha"]
    if (run["id"] != owner_run["id"] or run["path"].split("@", 1)[0] != WORKFLOW
            or run["event"] != "workflow_dispatch" or run["head_branch"] != "main"
            or run["status"] != "completed" or run["conclusion"] != "success"
            or run["head_repository"]["full_name"] != REPO
            or owner_run["head_branch"] != "main" or owner_run["head_sha"] != commit
            or artifact["name"] != f"release-main-{commit}"):
        raise ValueError("Artifact has no successful main Release Candidate provenance")
    current_main(api, commit, "refs/heads/main")
    with ZipFile(BytesIO(data)) as outer:
        receipt = json.loads(outer.read("candidate.json"))
        if (receipt["schema"], receipt["repository"], receipt["ref"], receipt["commit"],
                receipt["run_id"], receipt["run_attempt"], receipt["ci"]["status"]) != (
                    1, REPO, "refs/heads/main", commit, run["id"], run["run_attempt"], "PASS"):
            raise ValueError("Candidate provenance does not match its GitHub run")
        name = receipt["zip"]
        filenames = [name, name + ".manifest.json", name + ".sha256", "candidate.json"]
        if sorted(outer.namelist()) != sorted(filenames):
            raise ValueError("Unexpected or duplicate release file")
        manifest = verify_package(outer.read(name), outer.read(name + ".manifest.json"),
                                  outer.read(name + ".sha256"), commit)
    if any(receipt[key] != manifest[key] for key in ("zip", "version", "sha256")):
        raise ValueError("Candidate metadata differs from the verified plugin ZIP")
    if receipt["ci"] != release_evidence(api, commit, "refs/heads/main", receipt["ci"]["pr"]):
        raise ValueError("The recorded release CI is no longer current")
    return {**receipt, "artifact_id": artifact_id}


def staging_report(evidence):
    """Summarize attestations only. Keep operator notes and locations out of the output."""
    checks = {}
    for key in STAGING_CHECKS:
        item = evidence.get(key, {})
        confirmed = (isinstance(item, dict) and item.get("status") == "VERIFIED"
                     and isinstance(item.get("record"), str) and bool(item["record"].strip()))
        checks[key] = "VERIFIED" if confirmed else "BLOCKED"
    return {"status": "STAGING DEPLOYMENT BLOCKED", "checks": checks,
            "blockers": [key for key, status in checks.items() if status == "BLOCKED"]
            + ["Independent Phase 3B installation authorization required"]}


def package_parity(root, base):
    """Compare two real builds and the original plugin payload from the PR base."""
    if not SHA.fullmatch(base):
        raise ValueError("Package parity requires a full PR Base SHA")
    baseline = git(root, "archive", "--format=tar", base, "--", *SOURCE)
    with tarfile.open(fileobj=BytesIO(baseline)) as archive:
        expected = {f"{PLUGIN}/{entry.name}": sha256(archive.extractfile(entry).read())
                    for entry in archive if entry.isfile()}
    with tempfile.TemporaryDirectory() as temp:
        a, b = Path(temp) / "first", Path(temp) / "second"
        first, second = build(root, a), build(root, b)
        filenames = (first["zip"], first["zip"] + ".manifest.json", first["zip"] + ".sha256")
        if first != second or any((a / name).read_bytes() != (b / name).read_bytes() for name in filenames):
            raise ValueError("Two builds from the same commit differ")
        actual = verify_package(*((a / name).read_bytes() for name in filenames), first["commit"])
        if actual["files"] != expected:
            raise ValueError("Packaged plugin payload differs from the base commit")
    return {"commit": first["commit"], "base": base, "members": len(expected), "sha256": first["sha256"]}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    sub = parser.add_subparsers(dest="mode", required=True)
    check = sub.add_parser("ci-evidence")
    check.add_argument("--commit", required=True)
    check.add_argument("--pr", type=int, required=True)
    make = sub.add_parser("prepare")
    make.add_argument("--pr", type=int, required=True)
    make.add_argument("--commit", required=True)
    make.add_argument("--output", type=Path, required=True)
    validate = sub.add_parser("verify")
    validate.add_argument("--artifact-id", type=int, required=True)
    validate.add_argument("--bundle", type=Path, required=True)
    staging = sub.add_parser("staging-evidence")
    staging.add_argument("--file", type=Path)
    parity = sub.add_parser("package-parity")
    parity.add_argument("--base", required=True)
    args = parser.parse_args()
    try:
        if args.mode == "ci-evidence":
            result = release_evidence(github, args.commit, "refs/heads/main", args.pr)
        elif args.mode == "prepare":
            if (os.environ.get("GITHUB_REPOSITORY") != REPO
                    or os.environ.get("GITHUB_EVENT_NAME") != "workflow_dispatch"
                    or args.commit != os.environ["GITHUB_SHA"]):
                raise ValueError("Expected an exact-SHA manual dispatch in the canonical repository")
            result = prepare(github, args.commit, os.environ["GITHUB_REF"], args.pr,
                             int(os.environ["GITHUB_RUN_ID"]), int(os.environ["GITHUB_RUN_ATTEMPT"]), args.output)
            current_main(github, args.commit, os.environ["GITHUB_REF"])
        elif args.mode == "verify":
            result = verify_candidate(github, args.bundle.read_bytes(), args.artifact_id)
        elif args.mode == "package-parity":
            result = package_parity(ROOT, args.base)
        else:
            result = staging_report(json.loads(args.file.read_text(encoding="utf-8")) if args.file else {})
    except (ValueError, OSError, TypeError, KeyError, AttributeError, URLError, BadZipFile,
            subprocess.CalledProcessError) as exc:
        reason = "Invalid local Staging evidence" if args.mode == "staging-evidence" else str(exc)
        parser.exit(1, f"BLOCKED — {reason}\n")
    print(json_bytes(result).decode(), end="")
    return int(args.mode == "staging-evidence")


if __name__ == "__main__":
    raise SystemExit(main())
