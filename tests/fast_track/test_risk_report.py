"""Exercise the CLI against real Git histories; no services or credentials required."""
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest


SELECTOR = Path(__file__).resolve().parents[2] / "scripts" / "risk_report.py"
sys.path.insert(0, str(SELECTOR.parent))
from fast_track_ci import JOBS, make_plan, outcome  # noqa: E402


CSS = "assets/css/campaign-analytics.css"
CALLER = "includes/class-sn-campaign-tracking.php"


class RiskReportTest(unittest.TestCase):
    def setUp(self):
        tmp = tempfile.TemporaryDirectory()
        self.addCleanup(tmp.cleanup)
        self.root = Path(tmp.name)
        self.git("init", "--quiet", "-b", "main")
        self.git("config", "user.name", "Fixture")
        self.git("config", "user.email", "fixture@example.invalid")
        self.git("config", "core.autocrlf", "false")
        self.write("README.md", "# Test project\n")
        self.base = self.snapshot("base")

    def git(self, *args):
        return subprocess.run(["git", *args], cwd=self.root, check=True,
                              capture_output=True, text=True).stdout.strip()

    def write(self, file, text):
        path = self.root / file
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(text, encoding="utf-8")

    def snapshot(self, message):
        self.git("add", "--all")
        self.git("commit", "--quiet", "-m", message)
        return self.git("rev-parse", "HEAD")

    def report(self, head, base=None, target="main", *args):
        proc = subprocess.run(
            [sys.executable, "-B", str(SELECTOR), "--base", base or self.base, "--head", head,
             "--target", target, *args],
            cwd=self.root, check=True, capture_output=True, text=True,
        )
        return json.loads(proc.stdout)

    def suites(self, report, applicability="REQUIRED"):
        return {s["id"] for s in report["tests"] if s["applicability"] == applicability}

    def orchestration(self, selection, jobs=None, matrix_verified=False):
        expected = {
            "pr": 1, "base": selection["base_sha"], "head": selection["head_sha"],
            "target": selection["target"], "merge": "a" * 40,
        }
        plan = make_plan(expected, expected["merge"], selection)
        return plan, outcome(plan, expected, expected, jobs or {},
                             "https://github.com/example/repo/actions/runs/1", matrix_verified)

    def test_plain_docs(self):
        self.write("docs/glossary.md", "# Glossary\n\nRecord: One entry.\n")
        head = self.snapshot("docs")
        r = self.report(head)
        self.assertEqual((r["risk"], r["status"]), ("LOW", "NOT_RUN"))
        self.assertEqual(r["base_sha"], self.base)
        self.assertEqual(r["head_sha"], head)
        self.assertEqual(self.suites(r), {"syntax", "docs-hygiene", "secrets", "stable-boundary"})
        self.assertNotIn("stable-boundary", self.suites(self.report(head, None, "experimental")))
        self.assertTrue(all(t["reason"] and t["evidence"] is None for t in r["tests"]))
        plan, result = self.orchestration(r, {job: "success" for job in JOBS.values()})
        self.assertFalse(plan["finance"] or plan["campaign_ui"])
        self.assertEqual(result["status"], "READY FOR MERGE CHECK")

    def test_instruction_docs_are_high(self):
        self.write("docs/notes.md", "# Notes\n\n~~~bash\npython3 scripts/check.py all\n~~~\n")
        r = self.report(self.snapshot("executable docs"))
        self.assertEqual(r["risk"], "HIGH")
        self.assertIn("tooling", self.suites(r))

    def css_baseline(self):
        self.write(CSS, ".sn-campaign-section {\n  padding: 22px;\n}\n")
        self.write(CALLER, f"<?php\nwp_enqueue_style('campaign', '{CSS}');\n")
        return self.snapshot("campaign fixture")

    def test_bounded_ui_with_verified_caller(self):
        base = self.css_baseline()
        self.write(CSS, ".sn-campaign-section {\n  padding: 24px;\n}\n")
        r = self.report(self.snapshot("spacing"), base)
        self.assertEqual((r["risk"], r["status"]), ("MEDIUM", "NOT_RUN"))
        self.assertIn(CALLER, r["changed_files"][0]["reason"])
        self.assertIn("ui", self.suites(r))
        self.assertNotIn("finance", self.suites(r))
        base_jobs = {job: "success" for job in JOBS.values()}
        plan, result = self.orchestration(r, base_jobs)
        self.assertTrue(plan["campaign_ui"])
        self.assertFalse(plan["finance"])
        self.assertEqual(result["status"], "BLOCKED")
        _, result = self.orchestration(r, {**base_jobs, "campaign-ui": "success"})
        self.assertEqual(result["status"], "READY FOR MERGE CHECK")

    def test_ui_visibility_is_not_bounded(self):
        base = self.css_baseline()
        self.write(CSS, ".sn-campaign-section {\n  padding: 24px;\n  display: none;\n}\n")
        r = self.report(self.snapshot("hide panel"), base)
        self.assertEqual((r["risk"], r["status"]), ("UNKNOWN", "BLOCKED"))
        self.assertIn("finance", self.suites(r, "REVIEW"))

    def test_ui_caller_drift_blocks_smaller_risk(self):
        self.css_baseline()
        self.write("includes/another-consumer.php",
                   "<?php\nwp_enqueue_style('other', '../css/campaign-analytics.css');\n")
        base = self.snapshot("extra caller")
        self.write(CSS, ".sn-campaign-section {\n  padding: 24px;\n}\n")
        self.assertEqual(self.report(self.snapshot("spacing"), base)["risk"], "UNKNOWN")

    def test_sensitive_surfaces_and_transitive_suites(self):
        cases = {
            "reporting with financial aggregates": ("includes/reports/class-sn-report-service.php",
                                                    "<?php\n$total = 10;\n", {"reporting", "finance", "access"}),
            "payment UI": ("assets/js/payment-transactions.js", "const amount = 5;\n", {"finance", "ledger"}),
            "invoice": ("includes/class-sn-invoice.php", "<?php\nreturn true;\n", {"finance", "access"}),
            "wallet": ("includes/class-sn-wallet.php", "<?php\nreturn 5;\n", {"ledger", "finance"}),
            "commission": ("includes/class-sn-purpose-commission.php", "<?php\nreturn 7;\n", {"ledger"}),
            "role addition in an unmapped filename": ("includes/new-module.php",
                                                       "<?php\nadd_cap('read_report');\n", {"access"}),
            "schema operation in an unmapped filename": ("includes/bootstrap-new.php",
                                                         "<?php\ndbDelta($sql);\n", {"schema", "finance", "mis"}),
            "migration": ("includes/class-sn-upgrade-importer.php", "<?php\nreturn true;\n", {"schema"}),
            "shared core": ("includes/class-sn-plugin.php", "<?php\nreturn true;\n",
                            {"finance", "access", "ledger", "schema", "reporting", "ui", "mis"}),
            "workflow": (".github/workflows/ci.yml", "name: CI\n", {"tooling"}),
            "packaging": ("scripts/package.py", "print('package')\n", {"tooling"}),
            "financial harness": ("tests/payments/integration/verify.php", "<?php\nreturn true;\n",
                                  {"tooling", "finance"}),
        }
        for case, (file, content, expected) in cases.items():
            with self.subTest(case=case):
                self.git("checkout", "--quiet", "--detach", self.base)
                self.write(file, content)
                r = self.report(self.snapshot(case))
                self.assertEqual(r["risk"], "HIGH")
                self.assertLessEqual(expected, self.suites(r))
                plan, result = self.orchestration(r, {job: "success" for job in JOBS.values()})
                self.assertTrue(plan["finance"])
                self.assertFalse(plan["campaign_ui"])
                self.assertEqual(result["status"], "BLOCKED")

    def test_renaming_sensitive_asset_preserves_old_path(self):
        self.write("assets/css/payment-batch.css", ".pay { display: grid; }\n")
        base = self.snapshot("before rename")
        self.git("mv", "assets/css/payment-batch.css", "assets/css/neutral.css")
        r = self.report(self.snapshot("rename sensitive CSS"), base)
        self.assertEqual((r["risk"], r["highest_known_risk"], r["status"]), ("UNKNOWN", "HIGH", "BLOCKED"))
        self.assertEqual({(f["path"], f["change"]) for f in r["changed_files"]},
                         {("assets/css/payment-batch.css", "D"), ("assets/css/neutral.css", "A")})
        self.assertIn("finance", self.suites(r))

    def test_sensitive_code_removal_is_detected(self):
        self.write("includes/new-module.php", "<?php\n$current = current_user_can('read');\n")
        base = self.snapshot("scope guard")
        self.write("includes/new-module.php", "<?php\n$current = true;\n")
        r = self.report(self.snapshot("remove scope guard"), base)
        self.assertEqual(r["risk"], "HIGH")
        self.assertIn("access", self.suites(r))

    def test_unmapped_or_binary_blocks_fast_track(self):
        self.write("includes/unmapped.php", "<?php\nreturn true;\n")
        asset = self.root / "unknown.bin"
        asset.write_bytes(b"binary\0")
        r = self.report(self.snapshot("unknown"))
        self.assertEqual((r["risk"], r["status"]), ("UNKNOWN", "BLOCKED"))
        self.assertEqual(len(r["changed_files"]), 2)
        self.assertTrue(all(f["risk"] == "UNKNOWN" for f in r["changed_files"]))
        plan, result = self.orchestration(r, {job: "success" for job in JOBS.values()}, True)
        self.assertTrue(plan["finance"])
        self.assertEqual(result["status"], "BLOCKED")

    def test_experimental_runs_full_finance_matrix(self):
        self.write("includes/class-sn-receipt-review.php", "<?php\nreturn true;\n")
        selection = self.report(self.snapshot("review change"), target="experimental")
        jobs = {job: "success" for job in JOBS.values() if job != "stable-pr-boundary"}
        plan, result = self.orchestration(selection, jobs, True)
        self.assertEqual(plan["risk"], "HIGH")
        self.assertTrue(plan["finance"])
        statuses = {row["suite"]: row["status"] for row in result["tests"]}
        self.assertEqual((statuses["finance"], statuses["stable-boundary"]), ("PASS", "SKIPPED"))
        self.assertEqual((statuses["ledger"], result["status"]), ("BLOCKED", "BLOCKED"))

    def test_complete_diff_includes_changes_before_latest_commit(self):
        self.write("includes/class-sn-scope-service.php", "<?php\nreturn false;\n")
        self.snapshot("scope change")
        self.write("docs/glossary.md", "# Glossary\n")
        r = self.report(self.snapshot("documentation on top"))
        self.assertEqual(r["risk"], "HIGH")
        self.assertEqual(len(r["changed_files"]), 2)
        self.assertIn("access", self.suites(r))

    def test_missing_or_diverged_base_blocks(self):
        self.write("docs/info.md", "Glossary\n")
        head = self.snapshot("topic")
        self.git("checkout", "--quiet", "--detach", self.base)
        self.write("docs/other.md", "Other\n")
        base = self.snapshot("base advanced")
        r = self.report(head, base)
        self.assertEqual((r["risk"], r["status"], r["diff_base_sha"]),
                         ("UNKNOWN", "BLOCKED", self.base))
        self.assertEqual([x["path"] for x in r["changed_files"]], ["docs/info.md"])
        self.assertEqual(self.report(head, "not-a-ref")["status"], "BLOCKED")

    def test_evidence_is_bound_to_revision_and_not_inferred(self):
        self.write("docs/info.md", "Glossary\n")
        head = self.snapshot("topic")
        evidence = self.root / "evidence.json"
        initial = self.report(head)
        payload = {key: initial[key] for key in ("base_sha", "head_sha", "diff_base_sha", "target")}
        payload["tests"] = {
            test: {"status": "PASS", "url": "https://example.invalid/fixture"}
            for test in self.suites(initial)
        }
        for expected in ("PASS", "FAIL", "BLOCKED", "NOT_RUN"):
            payload["tests"]["syntax"]["status"] = expected
            evidence.write_text(json.dumps(payload), encoding="utf-8")
            r = self.report(head, None, "main", "--evidence", str(evidence))
            self.assertEqual(r["status"], expected)
            self.assertEqual(r["test_evidence_source"], "caller_supplied_not_verified")
        payload["head_sha"] = "0" * 40
        evidence.write_text(json.dumps(payload), encoding="utf-8")
        stale = self.report(head, None, "main", "--evidence", str(evidence))
        self.assertEqual(stale["status"], "BLOCKED")
        self.assertTrue(all(suite["status"] == "NOT_RUN" for suite in stale["tests"]))


if __name__ == "__main__":
    unittest.main()
