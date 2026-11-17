"""Prove the orchestrator rejects stale, incomplete or failed GitHub-run evidence."""
from pathlib import Path
import sys
import unittest
from unittest.mock import patch


sys.path.insert(0, str(Path(__file__).resolve().parents[2] / "scripts"))
from fast_track_ci import (  # noqa: E402
    FINANCE_MATRIX, FINANCE_STEPS, IncompleteDiff, finance_matrix_passed, outcome,
    verify_checkout,
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


if __name__ == "__main__":
    unittest.main()
