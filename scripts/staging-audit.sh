#!/usr/bin/env bash
# Standalone Phase 3B evidence collector. Python reads metadata and emits only sanitized JSON.
set -euo pipefail
exec python3 -I -B - "$@" <<'PY'
import argparse
import datetime
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import stat
import subprocess


HOST = "crmstage.payasetareh.ir"
BLOCKED = "STAGING DEPLOYMENT BLOCKED"
VERSION = re.compile(r"[0-9]{1,3}(?:\.[0-9]{1,4}){2}\Z")
REFERENCE = re.compile(r"[A-Za-z0-9][A-Za-z0-9._-]{2,79}\Z")
MAX_EVIDENCE = 65536

# The first two controls carry forward the previous verified decisions. The others
# require an approved private record covering all checks listed for that control.
CONTROLS = (
    ("canonical_domain", None, ("WHM-01", "CFG-01"), (),
     "WHM and both WordPress URL settings identify the Staging vhost.",
     "Reconcile the local vhost with the approved Staging record if its identity changed."),
    ("document_root", None, ("WHM-01", "ISO-01"), (),
     "The resolved Staging and compared Production roots are distinct and not nested.",
     "Recheck the resolved Production and Staging roots if the vhost changed."),
    ("plugin_path", "FS-02", ("FS-01",), ("effective_plugin_isolated",),
     "The default CRM path was inside the Staging root.",
     "Verify the effective plugin directory and access under the actual handler."),
    ("php_user_pool", "PHP-02", ("CFG-01", "NET-01"),
     ("vhost_handler_identified", "effective_uid_and_process_verified"),
     "WHM recorded the PHP selection and FPM setting.",
     "Bind the vhost to its active handler, process identity and effective UID."),
    ("database_user", "DB-01-FINAL", ("ISO-01", "DB-01"),
     ("wordpress_account_and_endpoint_identified", "production_accounts_and_endpoints_compared",
      "direct_and_wildcard_grants_exclude_production", "global_and_proxy_grants_exclude_production",
      "active_available_nested_and_public_roles_exclude_production"),
     "DB-01 recorded the WordPress connection and sampled grants. Production isolation is unresolved.",
     "Have the DBA match the authenticated account and audit grants and roles on the applicable endpoints."),
    ("symlinks_mounts", "FS-02", ("ISO-01", "FS-01"),
     ("runtime_mounts_and_symlinks_isolated", "production_file_access_denied"),
     "Root separation and the shell mount sample were recorded.",
     "Review symlink targets, mounts and file access from the effective PHP and worker contexts."),
    ("cache_sessions_uploads", "CACHE-02", ("FS-01",),
     ("effective_cache_isolated", "effective_session_storage_and_cookies_isolated", "effective_uploads_isolated"),
     "Prior checks did not establish the effective uploads path or storage separation.",
     "Compare effective cache namespaces, session and cookie scope, and uploads with Production."),
    ("sms_mail_payment_api_egress", "NET-02", ("NET-01",),
     ("php_and_workers_covered", "ipv4_ipv6_namespaces_covered", "local_relays_and_proxies_covered",
      "mail_sms_payment_and_api_denied_in_network_and_services"),
     "NET-01 recorded a socket-UID rule. Its effect on application and intermediary routes is unresolved.",
     "Review effective routes and rules for PHP, workers and local services without network probes."),
    ("cron_workers", "JOB-02", ("CFG-01", "FS-01"),
     ("effective_wp_cron_reviewed", "system_cron_timers_and_workers_reviewed"),
     "The earlier audit recorded the WP-Cron flag and the account crontab.",
     "Review effective WP-Cron, all applicable system schedules and worker identities and destinations."),
    ("resource_limits", "RES-02", (),
     ("effective_cpu_memory_disk_and_db_limits_verified", "shared_host_production_protected"),
     "Effective workload and shared-host limits require evidence.",
     "Confirm limits against the workload, including host policies, storage and database capacity."),
    ("synthetic_backup_restore_plan", "BAK-02", ("HIST-13",),
     ("current_snapshot_is_synthetic", "snapshot_manifest_and_digest_verified"),
     "Historical evidence covers a code backup.",
     "Inspect the existing synthetic snapshot manifest and verify its identity and digest privately."),
    ("disposable_restore_test", "RST-02", ("HIST-13",),
     ("successful_disposable_restore_documented", "independent_synthetic_destination_verified",
      "restored_integrity_checked"),
     "Historical evidence covers code rollback.",
     "Locate the existing successful disposable restore report for the same synthetic snapshot."),
    ("wp_php_mariadb", "ENV-02", ("HIST-13", "FS-01", "DB-01"),
     ("actual_wordpress_php_and_database_versions_verified",),
     "WordPress was observed on disk and DB-01 recorded MariaDB. Web PHP is unresolved.",
     "Record versions from WordPress files, the actual web handler and the matched MariaDB endpoint."),
    ("woocommerce_dependencies", "ENV-02", ("FS-01",),
     ("woocommerce_state_verified", "active_dependency_versions_reviewed"),
     "The earlier default WooCommerce path check was inconclusive.",
     "Confirm effective WooCommerce availability or version and review active dependency versions privately."),
)

RECORD_FIELDS = {}
for control, evidence_id, prior, checks, reason, action in CONTROLS:
    if evidence_id:
        RECORD_FIELDS.setdefault(evidence_id, set()).update(checks)


def unique_object(pairs):
    result = {}
    for key, value in pairs:
        if key in result:
            raise ValueError("duplicate key")
        result[key] = value
    return result


def read_regular(path, limit, private=False):
    original = path.lstat()
    if not stat.S_ISREG(original.st_mode):
        raise ValueError("regular file required")
    flags = os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0) | getattr(os, "O_NONBLOCK", 0)
    with os.fdopen(os.open(path, flags), "rb") as source:
        mode = os.fstat(source.fileno())
        if (not stat.S_ISREG(mode.st_mode) or (mode.st_dev, mode.st_ino) != (original.st_dev, original.st_ino)
                or private and (
                    stat.S_IMODE(mode.st_mode) & 0o077 or mode.st_uid != getattr(os, "geteuid", lambda: -1)()
                )):
            raise ValueError("file changed or is not private")
        data = source.read(limit + 1)
    if len(data) > limit:
        raise ValueError("size")
    return data


def private_records(path):
    if path is None:
        return {}, "NOT_SUPPLIED"
    try:
        if path.suffix != ".json":
            raise ValueError("JSON evidence required")
        data = read_regular(path, MAX_EVIDENCE, private=True)
        payload = json.loads(data, object_pairs_hook=unique_object)
        if (not isinstance(payload, dict) or payload.keys() != {"schema", "host", "records"}
                or type(payload["schema"]) is not int or payload["schema"] != 1 or payload["host"] != HOST
                or not isinstance(payload["records"], dict)):
            raise ValueError("unexpected envelope")
        records = payload["records"]
        for evidence_id, record in records.items():
            fields = {"private_ref", "review_sha256", "checked_at", "checks"}
            if evidence_id in ("BAK-02", "RST-02"):
                fields.add("snapshot_sha256")
            if evidence_id == "ENV-02":
                fields.add("versions")
            if (evidence_id not in RECORD_FIELDS or not isinstance(record, dict)
                    or not {"private_ref", "review_sha256", "checked_at", "checks"} <= record.keys() <= fields):
                raise ValueError("unexpected record")
            if not REFERENCE.fullmatch(record["private_ref"]):
                raise ValueError("invalid reference")
            if not re.fullmatch(r"[0-9a-f]{64}", record["review_sha256"]):
                raise ValueError("invalid review digest")
            review = read_regular(path.parent / (record["private_ref"] + ".review.txt"), MAX_EVIDENCE, private=True)
            if not review.strip() or hashlib.sha256(review).hexdigest() != record["review_sha256"]:
                raise ValueError("unavailable or inconsistent review")
            checked_at = datetime.datetime.strptime(record["checked_at"], "%Y-%m-%dT%H:%M:%SZ")
            if checked_at.replace(tzinfo=datetime.timezone.utc) > datetime.datetime.now(datetime.timezone.utc):
                raise ValueError("future evidence date")
            checks = record["checks"]
            if (not isinstance(checks, dict) or not checks.keys() <= RECORD_FIELDS[evidence_id]
                    or any(value is not None and type(value) is not bool for value in checks.values())):
                raise ValueError("unexpected check")
            if "snapshot_sha256" in record and not re.fullmatch(r"[0-9a-f]{64}", record["snapshot_sha256"]):
                raise ValueError("invalid digest")
            versions = record.get("versions", {})
            if (not isinstance(versions, dict) or not versions.keys() <= {"wordpress", "php", "mariadb", "woocommerce"}
                    or any(not (VERSION.fullmatch(v) or k == "woocommerce" and v == "NOT_INSTALLED")
                           for k, v in versions.items())):
                raise ValueError("unexpected version")
        return records, "ACCEPTED"
    except (OSError, ValueError, TypeError, RecursionError):
        return {}, "REJECTED"


def output(program, *args):
    executable = shutil.which(program)
    if program == "whmapi1" and not executable and os.access("/usr/local/cpanel/bin/whmapi1", os.X_OK):
        executable = "/usr/local/cpanel/bin/whmapi1"
    if not executable:
        return None
    try:
        return subprocess.check_output(
            [executable, *args], stdin=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
            timeout=15, text=True, encoding="utf-8", errors="replace", env=dict(os.environ, LC_ALL="C"),
        )
    except (OSError, subprocess.SubprocessError):
        return None


def read_metadata(path, limit=262144):
    try:
        return read_regular(path, limit).decode("utf-8", errors="replace")
    except (OSError, ValueError):
        return None


def configured_site():
    try:
        result = json.loads(output("whmapi1", "--output=json", "domainuserdata", "domain=" + HOST) or "{}")
        if str(result["metadata"]["result"]) != "1":
            return None
        site = result["data"]["userdata"]
        if site["servername"] != HOST:
            return "MISMATCH"
        path = Path(site["documentroot"])
        root = path.resolve(strict=True)
        if not path.is_absolute() or root == Path("/") or not root.is_dir():
            return None
        user = site["user"]
        if not re.fullmatch(r"[a-zA-Z0-9][a-zA-Z0-9_-]{0,31}", user):
            return None
        return root, user
    except (OSError, ValueError, KeyError, TypeError, RuntimeError):
        return None


def candidate_process(user):
    uid = (output("id", "-u", "--", user) or "").strip()
    if not uid.isdecimal() or uid == "0":
        return None
    for line in (output("ps", "-eo", "pid=,euid=,comm=") or "").splitlines():
        match = re.fullmatch(r"\s*([1-9][0-9]*)\s+([0-9]+)\s+(?:lsphp|php-fpm|php-cgi)\S*\s*", line)
        if match and match[2] == uid:
            process = Path("/proc") / match[1]
            status = read_metadata(process / "status")
            identity = re.search(r"(?m)^Uid:\s+[0-9]+\s+([0-9]+)\b", status or "")
            if identity and identity[1] == uid:
                return process, int(uid)
    return None


def process_observations(process, uid, root):
    observed = {}
    mounts = read_metadata(process / "mountinfo")
    if mounts is not None:
        targets = (Path(re.sub(r"\\([0-7]{3})", lambda m: chr(int(m[1], 8)), row.split()[4]))
                   for row in mounts.splitlines() if len(row.split()) > 4)
        count = sum(root == mount or root in mount.parents for mount in targets)
        observed["mounts"] = "A PHP candidate's mount view has entries under the root." if count else (
            "A PHP candidate's mount view has no mount target at or below the root."
        )
    try:
        same_namespace = (process / "ns/net").stat().st_ino == Path("/proc/self/ns/net").stat().st_ino
    except OSError:
        same_namespace = None
    if same_namespace is False:
        observed["network"] = "A PHP candidate has a different network namespace from the audit shell."
    if same_namespace is True:
        try:
            items = json.loads(output("nft", "-j", "list", "ruleset") or "{}").get("nftables", [])
            chains = {(c["family"], c["table"], c["name"]) for item in items if (c := item.get("chain"))
                      and c.get("hook") == "output"}
            for item in items:
                rule = item.get("rule", {})
                expr = rule.get("expr", [])
                if ((rule.get("family"), rule.get("table"), rule.get("chain")) in chains
                        and any("reject" in statement for statement in expr)
                        and any(statement.get("match") == {
                            "op": "==", "left": {"meta": {"key": "skuid"}}, "right": uid
                        } for statement in expr)):
                    observed["network"] = "A socket-UID reject rule was observed in the PHP candidate's network namespace."
                    break
        except (ValueError, KeyError, TypeError, AttributeError):
            pass
    cgroups = read_metadata(process / "cgroup") or ""
    group = next((Path(line[3:].strip()) for line in cgroups.splitlines() if line.startswith("0::")), None)
    if group is not None and group.is_absolute() and ".." not in group.parts:
        directory = Path("/sys/fs/cgroup").joinpath(*group.parts[1:])
        cpu = (read_metadata(directory / "cpu.max") or "").split()
        mem = (read_metadata(directory / "memory.max") or "").strip()
        if cpu and cpu[0].isdigit() and mem.isdigit():
            observed["limits"] = "Finite leaf cgroup-v2 CPU and memory limits were observed for a PHP candidate."
    return observed


def observe_host():
    site = configured_site()
    if not isinstance(site, tuple):
        return {"vhost": "MISMATCH" if site == "MISMATCH" else "UNAVAILABLE"}
    root, user = site
    observed = {"vhost": "MATCH"}
    process = candidate_process(user)
    if process:
        observed["php"] = "A PHP candidate under the WHM account was observed."
        observed.update(process_observations(*process, root))
    cron_files = [Path("/etc/crontab")]
    try:
        cron_files.extend(Path("/etc/cron.d").iterdir())
    except OSError:
        pass
    for path in cron_files:
        cron = read_metadata(path)
        if cron and any(HOST in line or str(root) in line for line in cron.splitlines()
                        if line.strip() and not line.lstrip().startswith("#")):
            observed["jobs"] = "A system cron file contains a Staging reference that needs review."
            break
    try:
        content = (root / "wp-content").resolve(strict=True)
        if root in content.parents and any((content / name).is_file() and not (content / name).is_symlink()
                                           for name in ("object-cache.php", "advanced-cache.php")):
            observed["cache"] = "At least one cache drop-in is present at the default path."
    except (OSError, RuntimeError):
        pass
    return observed


def report(records, input_status, observed):
    rows = []
    backup_verified = False
    notes = {"symlinks_mounts": "mounts", "php_user_pool": "php", "cache_sessions_uploads": "cache",
             "sms_mail_payment_api_egress": "network", "cron_workers": "jobs", "resource_limits": "limits"}
    versions = {"wordpress_on_disk": {"value": "7.1.4", "evidence": ["HIST-13", "FS-01"]},
                "mariadb": {"value": "10.11.19", "evidence": ["DB-01"]}}
    env_versions = records.get("ENV-02", {}).get("versions", {})
    for control, evidence_id, prior, checks, reason, pending in CONTROLS:
        evidence = list(prior)
        record = records.get(evidence_id)
        status = "UNVERIFIED"
        action = pending
        if evidence_id is None and observed.get("vhost") != "MISMATCH":
            status = "VERIFIED"
        elif observed.get("vhost") == "MISMATCH":
            reason = "Local WHM metadata disagrees with the Staging identity."
        elif observed.get("vhost") == "UNAVAILABLE" and record:
            reason = "The local Staging vhost could not be corroborated."
        elif record:
            evidence.append(evidence_id)
            values = [record["checks"].get(check) for check in checks]
            if False in values:
                status = "BLOCKED"
                reason = "The approved private record reports a failed control."
            elif all(value is True for value in values):
                status = "VERIFIED"
                reason = "The approved private record covers the effective control."
                if control == "synthetic_backup_restore_plan":
                    status = "VERIFIED" if record.get("snapshot_sha256") else "UNVERIFIED"
                    backup_verified = status == "VERIFIED"
                    if not backup_verified:
                        reason = "The synthetic snapshot digest reference is missing."
                if control == "disposable_restore_test" and (
                    not backup_verified or record.get("snapshot_sha256") != records["BAK-02"].get("snapshot_sha256")
                ):
                    status, reason = "UNVERIFIED", "The restore report lacks a matching verified snapshot."
                if control == "wp_php_mariadb":
                    keys = ("wordpress", "php", "mariadb")
                    if all(key in env_versions for key in keys):
                        versions.update({key: {"value": env_versions[key], "evidence": ["ENV-02"]} for key in keys})
                    else:
                        status, reason = "UNVERIFIED", "The effective version record is incomplete."
                if control == "woocommerce_dependencies":
                    if "woocommerce" in env_versions:
                        versions["woocommerce"] = {"value": env_versions["woocommerce"], "evidence": ["ENV-02"]}
                    else:
                        status, reason = "UNVERIFIED", "WooCommerce status or version is missing."
        note = observed.get(notes.get(control))
        if note:
            reason += " " + note
        if status == "VERIFIED":
            action = "Keep the evidence with the approved Staging record."
        rows.append({"control_id": control, "status": status, "evidence": evidence,
                     "required_record": evidence_id, "reason": reason, "remaining_action": action})
    return {"host": HOST, "deployment_status": BLOCKED, "evidence_input": input_status,
            "local_vhost_metadata": observed.get("vhost", "OFFLINE"),
            "versions": versions, "controls": rows,
            "remaining_authorization": "Independent Staging authorization and completion of all controls are required."}


class AuditArguments(argparse.ArgumentParser):
    def error(self, message):
        self.exit(2, "Invalid audit arguments. Run with --help.\n")


def main():
    parser = AuditArguments(description="Emit a sanitized, read-only Phase 3B audit.")
    parser.add_argument("--audit-evidence", type=Path, metavar="FILE")
    parser.add_argument("--offline", action="store_true", help="Summarize evidence without inspecting the local host.")
    args = parser.parse_args()
    records, input_status = private_records(args.audit_evidence)
    observed = {} if args.offline or input_status == "REJECTED" else observe_host()
    print(json.dumps(report(records, input_status, observed), indent=2, ensure_ascii=True))
    return 2 if input_status == "REJECTED" else 1


if __name__ == "__main__":
    raise SystemExit(main())
PY
