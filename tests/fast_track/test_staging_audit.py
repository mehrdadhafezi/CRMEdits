"""Exercise the standalone Bash audit body with synthetic host and evidence inputs."""
import ast
import contextlib
import hashlib
import io
import json
import os
from pathlib import Path
import stat
import subprocess
import sys
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import patch


ROOT = Path(__file__).resolve().parents[2]
SCRIPT = ROOT / "scripts" / "staging-audit.sh"
BODY = SCRIPT.read_text(encoding="utf-8").split("<<'PY'\n", 1)[1].rsplit("\nPY", 1)[0]


def audit():
    module = {"__name__": "staging_audit_test"}
    exec(compile(BODY, str(SCRIPT), "exec"), module)
    return module


def envelope(module, records):
    return {"schema": 1, "host": module["HOST"], "records": records}


def review_bytes(key):
    return ("Synthetic private review for " + key + " at crmstage.payasetareh.ir.\n").encode()


def records_reviewed(module):
    records = {
        key: {"private_ref": "PRIVATE-FIXTURE-" + key, "checked_at": "2020-01-01T00:00:00Z",
              "review_sha256": hashlib.sha256(review_bytes(key)).hexdigest(),
              "checks": dict.fromkeys(fields, True)}
        for key, fields in module["RECORD_FIELDS"].items()
    }
    for key in ("BAK-02", "RST-02"):
        records[key]["snapshot_sha256"] = "a" * 64
    records["ENV-02"]["versions"] = {
        "wordpress": "7.1.4", "php": "8.1.34", "mariadb": "10.11.19", "woocommerce": "NOT_INSTALLED",
    }
    return records


def rows_by_id(result):
    return {row["control_id"]: row for row in result["controls"]}


class AuditReportTest(unittest.TestCase):
    def setUp(self):
        self.audit = audit()
        self.report = self.audit["report"]

    def test_official_controls_and_prior_evidence_are_preserved(self):
        source = ast.parse((ROOT / "scripts" / "release_candidate.py").read_text(encoding="utf-8"))
        keys = next(ast.literal_eval(node.value) for node in source.body
                    if isinstance(node, ast.Assign)
                    and any(isinstance(t, ast.Name) and t.id == "STAGING_CHECKS" for t in node.targets))
        result = self.report({}, "NOT_SUPPLIED", {})
        self.assertEqual(tuple(rows_by_id(result)), keys)
        rows = rows_by_id(result)
        self.assertEqual({key for key, row in rows.items() if row["status"] == "VERIFIED"},
                         {"canonical_domain", "document_root"})
        self.assertEqual({e for row in rows.values() for e in row["evidence"]},
                         {"WHM-01", "CFG-01", "ISO-01", "NET-01", "FS-01", "HIST-13", "DB-01"})
        self.assertEqual(result["versions"]["mariadb"], {"value": "10.11.19", "evidence": ["DB-01"]})
        self.assertNotIn("php", result["versions"])

    def test_passive_observations_cannot_promote_any_effective_control(self):
        observed = dict.fromkeys(("php", "mounts", "network", "limits", "jobs", "cache"),
                                 "Synthetic observation.")
        observed["vhost"] = "MATCH"
        rows = rows_by_id(self.report({}, "NOT_SUPPLIED", observed))
        for key in ("php_user_pool", "symlinks_mounts", "sms_mail_payment_api_egress",
                    "resource_limits", "cron_workers", "cache_sessions_uploads"):
            self.assertEqual(rows[key]["status"], "UNVERIFIED")
            self.assertIn("Synthetic observation.", rows[key]["reason"])

    def test_attested_controls_still_do_not_enable_deployment_or_leak_references(self):
        result = self.report(records_reviewed(self.audit), "ACCEPTED", {"vhost": "MATCH"})
        self.assertEqual({row["status"] for row in result["controls"]}, {"VERIFIED"})
        self.assertEqual(result["deployment_status"], "STAGING DEPLOYMENT BLOCKED")
        data = json.dumps(result)
        self.assertNotIn("PRIVATE-FIXTURE", data)
        self.assertNotIn("a" * 64, data)
        self.assertNotIn("2020-01-01", data)

    def test_missing_grant_and_reported_failure_are_distinct(self):
        records = records_reviewed(self.audit)
        checks = records["DB-01-FINAL"]["checks"]
        for field in (
            "wordpress_account_and_endpoint_identified", "production_accounts_and_endpoints_compared",
            "direct_and_wildcard_grants_exclude_production", "global_and_proxy_grants_exclude_production",
            "active_available_nested_and_public_roles_exclude_production",
        ):
            with self.subTest(field=field):
                checks[field] = None
                result = rows_by_id(self.report(records, "ACCEPTED", {}))
                self.assertEqual(result["database_user"]["status"], "UNVERIFIED")
                checks[field] = False
                result = rows_by_id(self.report(records, "ACCEPTED", {}))
                self.assertEqual(result["database_user"]["status"], "BLOCKED")
                checks[field] = True

    def test_backup_digest_and_matching_existing_restore_report_are_required(self):
        records = records_reviewed(self.audit)
        records["RST-02"]["snapshot_sha256"] = "b" * 64
        rows = rows_by_id(self.report(records, "ACCEPTED", {}))
        self.assertEqual(rows["synthetic_backup_restore_plan"]["status"], "VERIFIED")
        self.assertEqual(rows["disposable_restore_test"]["status"], "UNVERIFIED")
        records["BAK-02"].pop("snapshot_sha256")
        rows = rows_by_id(self.report(records, "ACCEPTED", {}))
        self.assertEqual(rows["synthetic_backup_restore_plan"]["status"], "UNVERIFIED")
        self.assertEqual(rows["disposable_restore_test"]["status"], "UNVERIFIED")

    def test_every_required_fact_fails_closed_when_missing_or_failed(self):
        for control, evidence_id, _, checks, _, _ in self.audit["CONTROLS"]:
            for field in checks:
                for value, status in ((None, "UNVERIFIED"), (False, "BLOCKED")):
                    with self.subTest(control=control, field=field, value=value):
                        records = records_reviewed(self.audit)
                        records[evidence_id]["checks"][field] = value
                        rows = rows_by_id(self.report(records, "ACCEPTED", {}))
                        self.assertEqual(rows[control]["status"], status)

    def test_incomplete_versions_are_not_a_verified_environment(self):
        records = records_reviewed(self.audit)
        records["ENV-02"]["versions"].pop("php")
        result = self.report(records, "ACCEPTED", {})
        self.assertEqual(rows_by_id(result)["wp_php_mariadb"]["status"], "UNVERIFIED")
        self.assertNotIn("php", result["versions"])

    def test_local_vhost_mismatch_prevents_verified_claims(self):
        records = records_reviewed(self.audit)
        rows = rows_by_id(self.report(records, "ACCEPTED", {"vhost": "MISMATCH"}))
        self.assertEqual({row["status"] for row in rows.values()}, {"UNVERIFIED"})
        rows = rows_by_id(self.report(records, "ACCEPTED", {"vhost": "UNAVAILABLE"}))
        self.assertEqual(rows["database_user"]["status"], "UNVERIFIED")

    def test_sample_is_the_exact_offline_output(self):
        sample = ROOT / "docs" / "examples" / "staging-audit.sanitized.json"
        expected = json.dumps(self.report({}, "NOT_SUPPLIED", {}), indent=2, ensure_ascii=True) + "\n"
        self.assertEqual(sample.read_text(encoding="utf-8"), expected)


class AuditSafetyTest(unittest.TestCase):
    def setUp(self):
        self.audit = audit()

    def parse(self, payload, reviews=None):
        if reviews is None:
            reviews = {"PRIVATE-FIXTURE-" + key + ".review.txt": review_bytes(key)
                       for key in self.audit["RECORD_FIELDS"]}

        def read(path, *args, **kwargs):
            if path.name == "private-fixture.json":
                return payload
            if path.name not in reviews:
                raise FileNotFoundError()
            self.assertIs(kwargs["private"], True)
            return reviews[path.name]

        with patch.dict(self.audit, {"read_regular": read}):
            return self.audit["private_records"](Path("private-fixture.json"))

    def test_missing_fabricated_or_modified_review_cannot_verify_claims(self):
        records = {"DB-01-FINAL": records_reviewed(self.audit)["DB-01-FINAL"]}
        payload = json.dumps(envelope(self.audit, records)).encode()
        self.assertEqual(self.parse(payload, {}), ({}, "REJECTED"))
        ref = "PRIVATE-FIXTURE-DB-01-FINAL.review.txt"
        for content in (b"", b"fabricated replacement", review_bytes("DB-01-FINAL") + b"changed"):
            self.assertEqual(self.parse(payload, {ref: content}), ({}, "REJECTED"))
        records["DB-01-FINAL"].pop("review_sha256")
        self.assertEqual(self.parse(json.dumps(envelope(self.audit, records)).encode()), ({}, "REJECTED"))

    def test_input_schema_blocks_sensitive_fields_duplicate_keys_and_fake_statuses(self):
        valid = envelope(self.audit, records_reviewed(self.audit))
        self.assertEqual(self.parse(json.dumps(valid).encode())[1], "ACCEPTED")
        cases = [
            {**valid, "host": "fixture-production.example"},
            {**valid, "schema": True},
            {"schema": 1, "host": self.audit["HOST"], "records": {"DB-01-FINAL": {"status": "VERIFIED"}}},
        ]
        for key, detail in (("raw_grants", "private-fixture-grant"),
                            ("checked_at", "9999-01-01T00:00:00Z"),
                            ("versions", {"mariadb": "10.11.19-private-fixture"})):
            payload = envelope(self.audit, records_reviewed(self.audit))
            payload["records"]["ENV-02"][key] = detail
            cases.append(payload)
        for payload in cases:
            self.assertEqual(self.parse(json.dumps(payload).encode()), ({}, "REJECTED"))
        self.assertEqual(self.parse(b'{"schema":1,"schema":1}'), ({}, "REJECTED"))

    def test_plain_metadata_paths_and_special_files_are_not_read_as_evidence(self):
        with tempfile.TemporaryDirectory() as tmp:
            with patch("os.open", side_effect=AssertionError("unexpected read")):
                for path in (Path(tmp) / "wp-config.php", Path(tmp) / "missing.json"):
                    self.assertEqual(self.audit["private_records"](path), ({}, "REJECTED"))
                with patch.object(Path, "lstat", return_value=SimpleNamespace(st_mode=stat.S_IFIFO)):
                    self.assertEqual(self.audit["private_records"](Path(tmp) / "fifo.json"), ({}, "REJECTED"))

    def test_file_permissions_and_ownership_fail_before_read(self):
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / "evidence.json"
            path.write_bytes(b'{"private_fixture":"do not report"}')
            visible, file_info = path.lstat(), path.stat()
            for uid, mode in ((1000, 0o644), (2000, 0o600)):
                info = SimpleNamespace(st_mode=stat.S_IFREG | mode, st_uid=uid,
                                       st_dev=visible.st_dev, st_ino=file_info.st_ino)
                with self.subTest(uid=uid, mode=mode), patch("os.geteuid", return_value=1000, create=True), \
                        patch("os.fstat", return_value=info):
                    self.assertEqual(self.audit["private_records"](path), ({}, "REJECTED"))

    def test_regular_file_reads_use_read_only_flags_and_reject_size_and_identity_changes(self):
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / "review.txt"
            path.write_bytes(b"synthetic review")
            with patch("os.open", wraps=os.open) as opened:
                self.assertEqual(self.audit["read_regular"](path, 100), b"synthetic review")
            flags = opened.call_args.args[1]
            self.assertEqual(flags & (os.O_WRONLY | os.O_RDWR), os.O_RDONLY)
            self.assertEqual(flags & (os.O_CREAT | os.O_TRUNC | os.O_APPEND), 0)
            with self.assertRaises(ValueError):
                self.audit["read_regular"](path, 1)
            visible = path.stat()
            changed = SimpleNamespace(st_mode=stat.S_IFREG | 0o600, st_dev=visible.st_dev,
                                      st_ino=visible.st_ino + 1)
            with patch("os.fstat", return_value=changed), self.assertRaises(ValueError):
                self.audit["read_regular"](path, 100)

    def test_offline_and_rejected_input_never_inspect_the_host(self):
        for args, state, code in ((["--offline"], "NOT_SUPPLIED", 1),
                                  (["--audit-evidence", "private-fixture.json"], "REJECTED", 2)):
            with self.subTest(state=state), patch.object(sys, "argv", ["audit", *args]), \
                    patch.dict(self.audit, {
                        "private_records": lambda _: ({}, state),
                        "observe_host": lambda: self.fail("unexpected host inspection"),
                    }):
                out = io.StringIO()
                with contextlib.redirect_stdout(out):
                    self.assertEqual(self.audit["main"](), code)
                self.assertEqual(json.loads(out.getvalue())["evidence_input"], state)

    def test_live_probes_use_only_expected_metadata_commands_and_sanitize_outputs(self):
        calls = []
        who = "privatefixture"
        root = Path("/private-fixture/root")
        whm = {"metadata": {"result": 1}, "data": {"userdata": {
            "servername": self.audit["HOST"], "documentroot": str(root),
            "user": who, "anything_else": "PRIVATE-FIXTURE-SECRET",
        }}}
        nft = {"nftables": [
            {"chain": {"family": "inet", "table": "private_net", "name": "egress", "hook": "output"}},
            {"rule": {"family": "inet", "table": "private_net", "chain": "egress", "expr": [
                {"match": {"op": "==", "left": {"meta": {"key": "skuid"}}, "right": 1050}},
                {"reject": None},
            ]}},
        ]}
        responses = {"whmapi1": json.dumps(whm), "id": "1050\n", "ps": " 121 1050 lsphp\n",
                     "nft": json.dumps(nft)}

        def run(argv, **options):
            calls.append(tuple(argv))
            self.assertIs(options["stdin"], subprocess.DEVNULL)
            self.assertIs(options["stderr"], subprocess.DEVNULL)
            for flag in ("shell", "input"):
                self.assertNotIn(flag, options)
            return responses[argv[0]]

        def metadata(path):
            return {"status": "Uid:\t1050\t1050\t1050\t1050\n", "mountinfo":
                    "36 35 98:0 / /private-fixture/root/uploads rw - tmpfs fixture rw\n",
                    "crontab": "* * * * * root " + self.audit["HOST"] + " PRIVATE-FIXTURE-SECRET\n",
                    "cgroup": "0::/fixture-cgroup\n", "cpu.max": "100000 100000\n",
                    "memory.max": "2048\n"}.get(path.name)

        with patch("shutil.which", side_effect=lambda name: name), patch("subprocess.check_output", side_effect=run), \
                patch.dict(self.audit, {"read_metadata": metadata}), patch.object(Path, "is_absolute", return_value=True), \
                patch.object(Path, "resolve", lambda p, **_: p), patch.object(Path, "is_dir", return_value=True), \
                patch.object(Path, "is_file", return_value=False), patch.object(Path, "iterdir", return_value=iter(())), \
                patch.object(Path, "stat", return_value=SimpleNamespace(st_ino=1)):
            observed = self.audit["observe_host"]()
        self.assertEqual(calls, [
            ("whmapi1", "--output=json", "domainuserdata", "domain=" + self.audit["HOST"]),
            ("id", "-u", "--", who), ("ps", "-eo", "pid=,euid=,comm="), ("nft", "-j", "list", "ruleset"),
        ])
        for key in ("php", "mounts", "network", "limits", "jobs"):
            self.assertIn(key, observed)
        result = self.audit["report"]({}, "NOT_SUPPLIED", observed)
        self.assertEqual(rows_by_id(result)["sms_mail_payment_api_egress"]["status"], "UNVERIFIED")
        for value in ("privatefixture", "private_net", "private-fixture", "PRIVATE-FIXTURE-SECRET", "1050"):
            self.assertNotIn(value, json.dumps(result))


if __name__ == "__main__":
    unittest.main()
