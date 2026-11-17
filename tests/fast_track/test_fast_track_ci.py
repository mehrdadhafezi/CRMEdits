"""Prove the orchestrator rejects stale, incomplete or failed GitHub-run evidence."""
import hashlib
import json
from pathlib import Path
import sys
import tempfile
import unittest
from unittest.mock import patch


sys.path.insert(0, str(Path(__file__).resolve().parents[2] / "scripts"))
from fast_track_ci import (  # noqa: E402
    FINANCE_MATRIX, FINANCE_STEPS, IncompleteDiff, finance_matrix_passed, full_run_title,
    outcome, read_optional_evidence, reusable_run, verify_checkout, verify_native_output,
)
from risk_report import SUITES  # noqa: E402


class FastTrackCITest(unittest.TestCase):
    def setUp(self):
        self.expected = {"pr": 6, "target": "main", "merge": "a" * 40,
                         "base": "b" * 40, "head": "c" * 40}
        self.plan = {
            **self.expected, "risk": "LOW", "reasons": [], "domains": [], "campaign_ui": False,
            "suites": {suite: "REQUIRED" if domain is None else "NOT_SELECTED"
                       for suite, (domain, _) in SUITES.items()},
        }
        self.jobs = {job: "success" for job in ("syntax", "documentation", "secrets", "stable-pr-boundary")}

    def result(self, live=None, **options):
        return outcome(self.plan, self.expected, live, self.jobs,
                       "https://github.com/example/repo/actions/runs/1", **options)

    def assert_no_pass(self, result):
        self.assertEqual(result["status"], "BLOCKED")
        self.assertNotIn("PASS", {row["status"] for row in result["tests"]})

    def test_merge_checkout_must_match_all_three_shas(self):
        merge, base, head = (self.expected[key] for key in ("merge", "base", "head"))
        with patch("fast_track_ci.git", return_value=f"{merge} {base} {head}\n".encode()):
            verify_checkout(self.expected, merge)
        for bad in (f"{merge} {head} {base}", f"{merge} {base}", f"{base} {base} {head}"):
            with patch("fast_track_ci.git", return_value=bad.encode()):
                with self.assertRaises(IncompleteDiff):
                    verify_checkout(self.expected, merge)

    def test_retarget_same_head_cannot_reuse_previous_run(self):
        self.assert_no_pass(self.result({**self.expected, "target": "experimental", "base": "d" * 40}))

    def test_new_base_same_target_cannot_reuse_previous_run(self):
        self.assert_no_pass(self.result({**self.expected, "base": "d" * 40}))

    def test_new_head_cannot_reuse_previous_run(self):
        self.assert_no_pass(self.result({**self.expected, "head": "e" * 40}))

    def test_changed_test_merge_cannot_reuse_previous_run(self):
        self.assert_no_pass(self.result({**self.expected, "merge": "f" * 40}))

    def test_failure_and_absent_or_cancelled_required_job_block(self):
        for result, status in (("failure", "FAIL"), ("skipped", "BLOCKED"),
                               ("cancelled", "BLOCKED"), (None, "BLOCKED")):
            with self.subTest(result=result):
                self.jobs["secrets"] = result
                actual = self.result(self.expected)
                self.assertEqual(actual["status"], "BLOCKED")
                self.assertTrue(any(row["suite"] == "secrets" and row["status"] == status
                                    for row in actual["tests"]))

    def test_missing_live_or_plan_cannot_use_successful_jobs(self):
        self.assert_no_pass(self.result())
        self.plan["suites"].pop("schema")
        self.assert_no_pass(self.result(self.expected))
        self.plan = None
        self.assert_no_pass(self.result(self.expected))

    def test_finance_needs_job_success_and_all_four_verified_runs(self):
        self.plan["risk"] = "HIGH"
        self.plan["suites"]["finance"] = "REQUIRED"
        self.jobs["finance"] = "success"
        self.assertEqual(self.result(self.expected)["status"], "BLOCKED")
        self.assertEqual(self.result(self.expected, matrix_verified=True)["status"], "READY FOR MERGE CHECK")
        self.jobs["finance"] = "failure"
        self.assertEqual(self.result(self.expected, matrix_verified=True)["status"], "BLOCKED")

    def test_finance_job_details_must_show_every_case_and_step(self):
        jobs = [
            {"name": f"finance / WP {wp} MySQL / upgrade from {baseline}", "conclusion": "success",
             "steps": [{"name": name, "conclusion": "success"} for name in FINANCE_STEPS]}
            for wp, baseline in FINANCE_MATRIX
        ]
        self.assertTrue(finance_matrix_passed(jobs))
        self.assertFalse(finance_matrix_passed(jobs[:-1]))
        jobs[-1]["steps"][0]["conclusion"] = "skipped"
        self.assertFalse(finance_matrix_passed(jobs))

    def full_run(self, **changes):
        return {"id": 10, "run_number": 12, "run_attempt": 1, "event": "pull_request",
                "display_title": full_run_title(self.expected), "head_sha": self.expected["head"],
                "status": "completed", "conclusion": "success", **changes}

    def test_metadata_rejects_prior_results_from_other_pr_head_base_or_target(self):
        for changes in ({"pr": 7}, {"merge": "d" * 40}, {"target": "experimental"}):
            with self.subTest(identity=changes), self.assertRaises(ValueError):
                reusable_run([self.full_run()], {**self.expected, **changes})
        with self.assertRaises(ValueError):
            reusable_run([self.full_run(head_sha="e" * 40)], self.expected)

    def test_metadata_does_not_fall_back_past_failed_cancelled_or_pending_full_run(self):
        prior = self.full_run()
        for status, conclusion in (("completed", "failure"), ("completed", "cancelled"), ("in_progress", None)):
            with self.subTest(status=status, conclusion=conclusion), self.assertRaises(ValueError):
                reusable_run([prior, self.full_run(run_number=13, status=status, conclusion=conclusion)],
                             self.expected)
        self.assertIs(reusable_run([prior], self.expected), prior)

    def test_metadata_requires_successful_summary_and_exact_finance_attempt(self):
        source = self.full_run(run_attempt=2)
        matrix = [
            {"name": f"finance / WP {wp} MySQL / upgrade from {baseline}", "conclusion": "success",
             "steps": [{"name": name, "conclusion": "success"} for name in FINANCE_STEPS]}
            for wp, baseline in FINANCE_MATRIX
        ]
        plan_job = {"name": "Fast-Track test plan", "conclusion": "success"}
        summary_job = {"name": "Fast-Track readiness summary", "conclusion": "success"}
        url = "https://github.com/example/repo/actions/runs/20"
        with patch("fast_track_ci.github_read", return_value={"workflow_runs": [source]}):
            with patch("fast_track_ci.run_jobs", return_value=[plan_job, summary_job, *matrix]) as jobs:
                results, verified, link = read_optional_evidence(self.expected, url)
                jobs.assert_called_once_with(10, 2)
            self.assertTrue(verified)
            self.assertIn("finance", results)
            self.assertTrue(link.endswith("/10/attempts/2"))
            with patch("fast_track_ci.run_jobs", return_value=[plan_job, summary_job, *matrix[:-1]]):
                results, verified, _ = read_optional_evidence(self.expected, url)
            self.assertNotIn("finance", results)
            self.assertFalse(verified)
            with patch("fast_track_ci.run_jobs", return_value=[plan_job, *matrix]):
                with self.assertRaises(ValueError):
                    read_optional_evidence(self.expected, url)

    def test_metadata_missing_full_run_blocks_even_when_base_jobs_succeed(self):
        result = self.result(self.expected, evidence_error="Missing full proof")
        self.assertEqual(result["status"], "BLOCKED")
        self.assertIn("Missing full proof", result["blockers"])

    def test_finance_artifact_mutations_reject_missing_wrong_or_unverified_outputs(self):
        with tempfile.TemporaryDirectory() as root:
            root = Path(root)
            zip_file = root / f"candidate-{self.expected['merge'][:12]}.zip"
            zip_file.write_bytes(b"fixture candidate")
            digest = hashlib.sha256(zip_file.read_bytes()).hexdigest()
            sums = zip_file.with_suffix(".zip.sha256")
            sums.write_text(f"{digest}  {zip_file.name}\n", encoding="utf-8")
            (root / "provisioning.txt").write_text("Baseline: 2.0.235\n", encoding="utf-8")
            native = {"wordpress": "7.1.4", "mysql": "8.0", "synthetic": True, "assertions": 1}
            evidence = {f"mysql-{phase}.json": {**native, "phase": phase}
                        for phase in ("upgrade", "restored", "rollback", "final")}
            evidence.update({"browser-summary.json": {**native, "chromium": "fixture"},
                             "browser-errors.json": [], "reporting-summary.json": native})
            for name, data in evidence.items():
                (root / name).write_text(json.dumps(data), encoding="utf-8")
            args = ("7.1.4", "2.0.235", self.expected["merge"], root, root)
            verify_native_output(*args)
            with self.assertRaises(ValueError):
                verify_native_output("7.1.4", "2.0.236", self.expected["merge"], root, root)
            for name, bad in (("mysql-rollback.json", {}), ("browser-summary.json", {**native, "assertions": 0}),
                              ("browser-errors.json", ["synthetic error"]),
                              ("reporting-summary.json", {**native, "wordpress": "6.9.11"})):
                with self.subTest(file=name), self.assertRaises((KeyError, ValueError)):
                    (root / name).write_text(json.dumps(bad), encoding="utf-8")
                    try:
                        verify_native_output(*args)
                    finally:
                        (root / name).write_text(json.dumps(evidence[name]), encoding="utf-8")
            sums.write_text("wrong checksum", encoding="utf-8")
            with self.assertRaises(ValueError):
                verify_native_output(*args)


if __name__ == "__main__":
    unittest.main()
