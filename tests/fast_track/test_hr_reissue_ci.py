"""HR CI proof is additive, exact-revision-bound, and cannot bless arbitrary core edits."""
import copy
import json
from pathlib import Path
import sys
import tempfile
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / "scripts"))
import hr_reissue_ci as hr
from fast_track_ci import outcome
from package import committed_members
from risk_report import SUITES

ROOT = Path(__file__).resolve().parents[2]


class HRProofTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.before = committed_members(ROOT, hr.BASE)
        cls.after = committed_members(ROOT, "58fca1ad8de49ec1cf296cdcb4edad1df26b7ac7")
        # Exercise the reviewed scoped CSS fix before committing it; other runtime
        # blobs remain those of the original feature. The explicit pin still binds it.
        cls.after["sales-network-v4/assets/css/hr-invoice-reissue.css"] = (ROOT / "assets/css/hr-invoice-reissue.css").read_bytes()

    def test_exact_reviewed_runtime_and_unchanged_existing_files(self):
        self.assertTrue(hr.bounded_payload(self.before, self.after))
        for name in self.after:
            mutated = {**self.after, name: self.after[name] + b"\n"}
            with self.subTest(path=name):
                self.assertFalse(hr.bounded_payload(self.before, mutated))
        self.assertFalse(hr.bounded_payload(self.before, {**self.after, "sales-network-v4/includes/new.php": b"<?php"}))
        removed = dict(self.after)
        removed.pop("sales-network-v4/assets/js/public.js")
        self.assertFalse(hr.bounded_payload(self.before, removed))

    def test_scope_never_waives_future_base_or_out_of_scope_changes(self):
        paths = set(hr.RUNTIME) | {"tests/hr/native-ledger.php", "scripts/hr_reissue_ci.py"}
        with patch("hr_reissue_ci.committed_members", side_effect=[self.before, self.after]):
            self.assertTrue(hr.scope(hr.BASE, "h" * 40, paths))
        with patch("hr_reissue_ci.committed_members") as blobs:
            self.assertFalse(hr.scope("b" * 40, "h" * 40, paths))
            self.assertFalse(hr.scope(hr.BASE, "h" * 40, paths | {"includes/class-sn-purpose-commission.php"}))
            self.assertFalse(hr.scope(hr.BASE, "h" * 40, paths | {"scripts/unknown.py"}))
            self.assertFalse(hr.scope(hr.BASE, "h" * 40, paths - {"sales-network.php"}))
            blobs.assert_not_called()

    def test_every_named_native_step_and_single_job_must_succeed(self):
        job = {"name": hr.JOB, "conclusion": "success", "steps": [
            {"name": name, "conclusion": "success"} for name in hr.STEPS]}
        self.assertTrue(hr.job_passed([job]))
        self.assertFalse(hr.job_passed([]))
        self.assertFalse(hr.job_passed([job, job]))
        for state in ("failure", "skipped", "cancelled", None):
            self.assertFalse(hr.job_passed([{**job, "conclusion": state}]))
            for index in range(len(job["steps"])):
                changed = copy.deepcopy(job)
                changed["steps"][index]["conclusion"] = state
                self.assertFalse(hr.job_passed([changed]))
        self.assertFalse(hr.job_passed([{**job, "steps": job["steps"][:-1]}]))

    def test_all_existing_suites_remain_required_and_hr_proof_cannot_replace_finance(self):
        revision = {"pr": 19, "base": hr.BASE, "head": "c" * 40, "merge": "a" * 40, "target": "main"}
        plan = {**revision, "risk": "HIGH", "reasons": [], "domains": ["shared"],
                "campaign_ui": False, "hr_native": True, "suites": {name: "REQUIRED" for name in SUITES}}
        jobs = {key: "success" for key in ("syntax", "documentation", "secrets", "stable-pr-boundary", "plan", "finance", "hr-native")}
        def result(**options):
            return outcome(plan, revision, revision, jobs, "https://github.com/example/run", **options)
        self.assertEqual(result()["status"], "BLOCKED")
        self.assertEqual(result(matrix_verified=True)["status"], "BLOCKED")
        self.assertEqual(result(hr_verified=True)["status"], "BLOCKED")
        green = result(matrix_verified=True, hr_verified=True)
        self.assertEqual(green["status"], "READY FOR MERGE CHECK")
        self.assertTrue(all(row["applicability"] == "REQUIRED" for row in green["tests"]))
        for state in ("failure", "skipped", "cancelled", None):
            jobs["hr-native"] = state
            self.assertEqual(result(matrix_verified=True, hr_verified=True)["status"], "BLOCKED")
        jobs["hr-native"] = "success"
        plan["hr_native"] = False
        self.assertEqual(result(matrix_verified=True, hr_verified=True)["status"], "BLOCKED")

    def test_native_output_requires_all_suites_actual_http_and_current_runtime(self):
        fingerprints = {"core_sha256": hr.RUNTIME["includes/class-sn-plugin.php"],
                        "policy_sha256": hr.RUNTIME["includes/class-sn-hr-invoice-reissue.php"]}
        data = {"passed": True, "synthetic": True, "assertions": 150, **fingerprints}
        files = {name: dict(data) for name in (
            "hr239-native-summary.json", "hr239-invoices-summary.json", "hr239-finance-summary.json",
            "hr239-ledger-summary.json", "browser/summary.json", "code-rollback.json", "mis-summary.json")}
        files["browser/summary.json"]["transport"] = "native WordPress HTTP login, shortcode and admin-ajax"
        files["mis-summary.json"].update(scripts=10, isolated_database=True)
        with tempfile.TemporaryDirectory() as folder, patch("hr_reissue_ci.git", return_value=b"a" * 40 + b"\n"):
            root = Path(folder)
            for name, value in files.items():
                target = root / name
                target.parent.mkdir(parents=True, exist_ok=True)
                target.write_text(json.dumps(value), encoding="utf-8")
            hr.verify_output(root, "a" * 40)
            for name in files:
                original = files[name]
                for field, value in (("passed", False), ("synthetic", False), ("assertions", 0)):
                    (root / name).write_text(json.dumps({**original, field: value}), encoding="utf-8")
                    with self.subTest(file=name, field=field), self.assertRaises(ValueError):
                        hr.verify_output(root, "a" * 40)
                (root / name).write_text(json.dumps(original), encoding="utf-8")
            for name, field, value in (("hr239-native-summary.json", "core_sha256", "0" * 64),
                                       ("hr239-ledger-summary.json", "policy_sha256", "0" * 64),
                                       ("browser/summary.json", "transport", "CLI proxy"),
                                       ("mis-summary.json", "scripts", 9),
                                       ("mis-summary.json", "isolated_database", False)):
                (root / name).write_text(json.dumps({**files[name], field: value}), encoding="utf-8")
                with self.subTest(field=field), self.assertRaises(ValueError):
                    hr.verify_output(root, "a" * 40)
                (root / name).write_text(json.dumps(files[name]), encoding="utf-8")
            with self.assertRaises(ValueError):
                hr.verify_output(root, "b" * 40)

    def test_sandbox_and_public_artifact_boundary(self):
        runner = (ROOT / "scripts/test-hr-integration.sh").read_text(encoding="utf-8")
        self.assertIn('test "${RUNNER_ENVIRONMENT:-}" = github-hosted', runner)
        self.assertIn('source scripts/test-finance-integration.sh', runner)
        self.assertIn('sn_approval_${GITHUB_RUN_ID}_hrmis_test', runner)
        self.assertNotIn('performance-volume.php', runner)
        workflow = (ROOT / ".github/workflows/ci.yml").read_text(encoding="utf-8")
        for name in hr.STEPS:
            self.assertIn("name: " + name, workflow)
        upload = workflow.split("name: Upload sanitized HR evidence", 1)[1].split("  summary:", 1)[0]
        self.assertNotIn("private", upload)
        self.assertNotIn("test-results/hr/**", upload)
        self.assertIn("needs.plan.outputs.hr_native == 'true'", workflow)


if __name__ == "__main__":
    unittest.main()
