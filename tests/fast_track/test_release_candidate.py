"""Release acceptance tests using disposable Git repositories and GitHub response fixtures."""
from contextlib import redirect_stderr
from io import BytesIO, StringIO
import json
import os
from pathlib import Path
import re
import sys
import tempfile
import unittest
from unittest.mock import patch
from zipfile import ZipFile


sys.path.insert(0, str(Path(__file__).resolve().parents[2] / "scripts"))
from package import ROOT, build, git, json_bytes, sha256, verify_package  # noqa: E402
from release_candidate import (  # noqa: E402
    BASE_CHECKS, PR_CHECKS, REPO, STAGING_CHECKS, WORKFLOW, package_parity,
    main, prepare, release_evidence, staging_report, verify_candidate,
)


class PackageFixture:
    def setUp(self):
        folder = tempfile.TemporaryDirectory()
        self.addCleanup(folder.cleanup)
        self.root = Path(folder.name) / "repo"
        self.root.mkdir()
        self.out = Path(folder.name) / "dist"
        git(self.root, "init", "-b", "main")
        git(self.root, "config", "user.name", "Release Fixture")
        git(self.root, "config", "user.email", "fixture@example.invalid")
        git(self.root, "config", "core.autocrlf", "false")
        self.write("sales-network.php", "<?php\n/*\n * Version: 2.0.238\n */\n")
        self.write("includes/runtime.php", "<?php\n")
        self.write("templates/view.php", "<?php\n")
        self.write("assets/app.js", "'use strict';\n")
        self.write("docs/private-note.md", "Synthetic notes stay out of packages.\n")
        self.commit = self.snapshot()

    def write(self, name, body):
        file = self.root / name
        file.parent.mkdir(parents=True, exist_ok=True)
        file.write_bytes(body.encode())

    def snapshot(self):
        git(self.root, "add", ".")
        git(self.root, "commit", "-m", "Fixture")
        return git(self.root, "rev-parse", "HEAD").decode().strip()

    def package(self):
        manifest = build(self.root, self.out, self.commit)
        name = manifest["zip"]
        return [(self.out / (name + suffix)).read_bytes() for suffix in ("", ".manifest.json", ".sha256")]


class PackageTest(PackageFixture, unittest.TestCase):
    def test_same_commit_builds_identical_zip_and_manifest(self):
        evidence = package_parity(self.root, self.commit)
        self.assertEqual((evidence["commit"], evidence["members"]), (self.commit, 4))

    def test_dirty_untracked_unstaged_and_staged_checkout_is_blocked(self):
        self.write("new-file.txt", "Untracked")
        with self.assertRaisesRegex(ValueError, "dirty"):
            self.package()
        (self.root / "new-file.txt").unlink()
        self.write("includes/runtime.php", "<?php\n// unstaged\n")
        with self.assertRaisesRegex(ValueError, "dirty"):
            self.package()
        git(self.root, "add", "includes/runtime.php")
        with self.assertRaisesRegex(ValueError, "dirty"):
            self.package()

    def test_only_allowed_members_and_private_paths_fail_closed(self):
        manifest = verify_package(*self.package(), self.commit)
        self.assertEqual(len(manifest["files"]), 4)
        for name in ("assets/.env", "includes/wp-config.php", "includes/credentials.php",
                     "assets/backup.zip", "assets/customer-export.js", "assets/data.csv"):
            with self.subTest(name=name):
                self.write(name, "Synthetic forbidden input")
                self.commit = self.snapshot()
                with self.assertRaisesRegex(ValueError, "Forbidden package input"):
                    self.package()
                (self.root / name).unlink()
                self.commit = self.snapshot()

    def test_single_changed_zip_byte_is_detected(self):
        zipped, manifest, checksum = self.package()
        changed = zipped[:-1] + bytes([zipped[-1] ^ 1])
        with self.assertRaisesRegex(ValueError, "ZIP digest"):
            verify_package(changed, manifest, checksum, self.commit)

    def test_manifest_metadata_member_hash_and_single_byte_changes_are_detected(self):
        zipped, manifest, checksum = self.package()
        data = json.loads(manifest)
        mutations = [
            {**data, "version": "9.9.9"},
            {**data, "files": {**data["files"], "sales-network-v4/assets/app.js": "0" * 64}},
        ]
        for forged in [*(json_bytes(item) for item in mutations), manifest + b" "]:
            with self.assertRaisesRegex(ValueError, "Manifest or ZIP checksum"):
                verify_package(zipped, forged, checksum, self.commit)

    def test_package_and_manifest_from_another_commit_are_rejected(self):
        parts = self.package()
        with self.assertRaisesRegex(ValueError, "commit"):
            verify_package(*parts, "f" * 40)
        with self.assertRaisesRegex(ValueError, "must equal"):
            build(self.root, self.out, "f" * 40)


class ReleaseTest(PackageFixture, unittest.TestCase):
    def setUp(self):
        super().setUp()
        self.base, self.head, self.merge, self.tree = ("a" * 40, "b" * 40, "c" * 40, "d" * 40)
        self.parents = [{"sha": self.base}, {"sha": self.head}]
        self.pr = {"merged": True, "base": {"ref": "main", "sha": self.base},
                   "head": {"sha": self.head}, "merge_commit_sha": self.commit}
        self.events = {
            "git/ref/heads/main": {"object": {"sha": self.commit}},
            "pulls/15": self.pr,
            f"git/commits/{self.commit}": {"parents": self.parents, "tree": {"sha": self.tree}},
            f"git/commits/{self.merge}": {"parents": self.parents, "tree": {"sha": self.tree}},
            f"actions/workflows/ci.yml/runs?event=pull_request&head_sha={self.head}&per_page=100":
                {"workflow_runs": [self.fixture_run(51, "pull_request", self.head, "feature/topic",
                                           display_title=f"Fast-Track full PR #15 main @{self.merge}")]},
            f"actions/workflows/ci.yml/runs?event=push&head_sha={self.commit}&per_page=100&branch=main":
                {"workflow_runs": [self.fixture_run(52, "push", self.commit, "main")]},
            "actions/runs/51/attempts/1/jobs?per_page=100": self.jobs(PR_CHECKS),
            "actions/runs/52/attempts/1/jobs?per_page=100": self.jobs(BASE_CHECKS),
        }

    @staticmethod
    def fixture_run(run_id, event, commit, branch, **more):
        return {"id": run_id, "run_number": run_id, "event": event, "head_sha": commit,
                "head_branch": branch, "status": "completed", "conclusion": "success",
                "run_attempt": 1, **more}

    @staticmethod
    def jobs(names):
        return {"jobs": [{"name": name, "conclusion": "success"} for name in names]}

    def api(self, endpoint):
        return self.events[endpoint]

    def bundle(self):
        zipped, manifest, sums = self.package()
        data = json.loads(manifest)
        receipt = {"schema": 1, "repository": REPO, "ref": "refs/heads/main",
                   "commit": self.commit, "version": data["version"], "zip": data["zip"],
                   "sha256": data["sha256"], "run_id": 80, "run_attempt": 1,
                   "ci": release_evidence(self.api, self.commit, "refs/heads/main", 15)}
        buffer = BytesIO()
        with ZipFile(buffer, "w") as archive:
            for suffix, content in zip(("", ".manifest.json", ".sha256"), (zipped, manifest, sums)):
                archive.writestr(data["zip"] + suffix, content)
            archive.writestr("candidate.json", json_bytes(receipt))
        result = buffer.getvalue()
        self.events["actions/artifacts/81"] = {
            "id": 81, "expired": False, "digest": "sha256:" + sha256(result),
            "name": f"release-main-{self.commit}",
            "workflow_run": {"id": 80, "head_branch": "main", "head_sha": self.commit},
        }
        self.events["actions/runs/80"] = self.fixture_run(
            80, "workflow_dispatch", self.commit, "main", path=WORKFLOW, head_repository={"full_name": REPO},
        )
        return result

    def test_experimental_manual_dispatch_and_artifact_are_rejected(self):
        with self.assertRaisesRegex(ValueError, "require main"):
            release_evidence(self.api, self.commit, "refs/heads/experimental", 15)
        data = self.bundle()
        self.events["actions/runs/80"]["head_branch"] = "experimental"
        with self.assertRaisesRegex(ValueError, "main Release Candidate"):
            verify_candidate(self.api, data, 81)

    def test_stale_wrong_pr_tree_and_unverified_ci_are_blocked(self):
        self.assertEqual(release_evidence(self.api, self.commit, "refs/heads/main", 15)["status"], "PASS")
        self.events[f"git/commits/{self.merge}"]["tree"]["sha"] = "f" * 40
        with self.assertRaisesRegex(ValueError, "differs from"):
            release_evidence(self.api, self.commit, "refs/heads/main", 15)
        self.events[f"git/commits/{self.merge}"]["tree"]["sha"] = self.tree
        for event, missing in (("51", "Fast-Track readiness summary"), ("52", "Secret scan")):
            check = next(job for job in self.events[f"actions/runs/{event}/attempts/1/jobs?per_page=100"]["jobs"]
                         if job["name"] == missing)
            check["conclusion"] = "skipped"
            with self.assertRaisesRegex(ValueError, "Missing valid CI"):
                release_evidence(self.api, self.commit, "refs/heads/main", 15)
            check["conclusion"] = "success"
        self.pr["merged"] = False
        with self.assertRaisesRegex(ValueError, "not the merge"):
            release_evidence(self.api, self.commit, "refs/heads/main", 15)

    def test_outer_digest_and_current_main_bind_the_downloaded_artifact(self):
        data = self.bundle()
        self.assertEqual(verify_candidate(self.api, data, 81)["commit"], self.commit)
        with self.assertRaisesRegex(ValueError, "GitHub archive digest"):
            verify_candidate(self.api, data + b"x", 81)
        self.events["git/ref/heads/main"]["object"]["sha"] = self.head
        with self.assertRaisesRegex(ValueError, "no longer"):
            verify_candidate(self.api, data, 81)

    def test_create_candidate_once_and_leave_original_artifact_bytes_alone(self):
        endpoint = ("actions/workflows/release-candidate.yml/runs?event=workflow_dispatch"
                    f"&head_sha={self.commit}&per_page=100&branch=main")
        self.events[endpoint] = {"workflow_runs": []}
        with patch("release_candidate.ROOT", self.root):
            args = self.api, self.commit, "refs/heads/main", 15, 80, 1, self.out
            receipt = prepare(*args)
            original = (self.out / receipt["zip"]).read_bytes()
            self.assertEqual(json.loads((self.out / "candidate.json").read_bytes()), receipt)
            self.events[endpoint]["workflow_runs"] = [self.fixture_run(80, "workflow_dispatch", self.commit, "main")]
            with self.assertRaisesRegex(ValueError, "already exists"):
                prepare(*args)
            self.assertEqual((self.out / receipt["zip"]).read_bytes(), original)


class StagingTest(unittest.TestCase):
    def test_missing_isolation_blocks_and_does_not_echo_notes(self):
        evidence = {key: {"status": "VERIFIED", "record": "private-reference"} for key in STAGING_CHECKS}
        evidence.pop("symlinks_mounts")
        report = staging_report(evidence)
        self.assertIn("symlinks_mounts", report["blockers"])
        self.assertNotIn("private-reference", json.dumps(report))

    def test_unverified_restore_blocks_even_with_isolation(self):
        evidence = {key: {"status": "VERIFIED", "record": "ref"} for key in STAGING_CHECKS}
        evidence["disposable_restore_test"]["status"] = "UNVERIFIED"
        self.assertIn("disposable_restore_test", staging_report(evidence)["blockers"])

    def test_complete_evidence_does_not_grant_staging_or_production_permission(self):
        evidence = {key: {"status": "VERIFIED", "record": "ref"} for key in STAGING_CHECKS}
        report = staging_report(evidence)
        self.assertEqual(report["status"], "STAGING DEPLOYMENT BLOCKED")
        self.assertEqual(report["blockers"], ["Independent Phase 3B installation authorization required"])

    def test_candidate_workflow_has_only_manual_trigger_and_no_deploy_job(self):
        workflow = (ROOT / WORKFLOW).read_text(encoding="utf-8")
        trigger = workflow.split("\non:\n", 1)[1].split("\npermissions:", 1)[0]
        self.assertEqual(re.findall(r"(?m)^  ([a-z_]+):", trigger), ["workflow_dispatch"])
        self.assertEqual(re.findall(r"(?m)^  ([a-z_-]+):", workflow.split("\njobs:\n", 1)[1]), ["candidate"])
        self.assertNotIn("environment:", workflow)

    def test_wrong_event_repository_or_dispatch_sha_cannot_build(self):
        args = ["release_candidate.py", "prepare", "--commit", "a" * 40, "--pr", "15", "--output", "unused"]
        expected = {"GITHUB_REPOSITORY": REPO, "GITHUB_EVENT_NAME": "workflow_dispatch", "GITHUB_SHA": "a" * 40}
        for mutation in ({"GITHUB_EVENT_NAME": "push"}, {"GITHUB_REPOSITORY": "fork/CRMEdits"},
                         {"GITHUB_SHA": "b" * 40}):
            with self.subTest(mutation=mutation), patch.object(sys, "argv", args), \
                    patch.dict(os.environ, {**expected, **mutation}), patch("release_candidate.prepare") as build_candidate, \
                    redirect_stderr(StringIO()):
                with self.assertRaises(SystemExit) as error:
                    main()
                self.assertEqual(error.exception.code, 1)
                build_candidate.assert_not_called()


if __name__ == "__main__":
    unittest.main()
