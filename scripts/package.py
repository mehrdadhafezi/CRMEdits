#!/usr/bin/env python3
"""Build and verify the tracked CRM plugin package without running WordPress."""
import argparse
import hashlib
from io import BytesIO
import json
from pathlib import Path, PurePosixPath
import re
import subprocess
import zipfile


ROOT = Path(__file__).resolve().parents[1]
PLUGIN = "sales-network-v4"
ENTRY = "sales-network.php"
SOURCE = (ENTRY, "assets", "includes", "templates")
SHA = re.compile(r"[0-9a-f]{40}\Z")
VERSION = re.compile(rb"^ \* Version:[ \t]*([0-9]+\.[0-9]+\.[0-9]+)\r?$", re.M)
BLOCKED_NAME = re.compile(
    r"(^|[._-])(?:credentials?|secrets?|backups?|dumps?|wp[-_]config|(?:customer|client)[-_]?(?:data|export)|uploads?)(?=$|[._-])",
    re.I,
)


def git(root, *args, data=None):
    return subprocess.run(["git", "-C", str(root), *args], input=data,
                          check=True, stdout=subprocess.PIPE).stdout


def json_bytes(value):
    return (json.dumps(value, sort_keys=True, indent=2, ensure_ascii=True) + "\n").encode("utf-8")


def sha256(data):
    return hashlib.sha256(data).hexdigest()


def member_allowed(name):
    parts = name.split("/")
    if any(not re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9._-]*", p) or p.endswith(".")
           or BLOCKED_NAME.search(p) for p in parts):
        return False
    if name == ENTRY or name == "assets/fonts/patra-vazirmatn-OFL.txt":
        return True
    suffix = PurePosixPath(name).suffix
    return len(parts) > 1 and (
        (parts[0] in ("includes", "templates") and suffix == ".php")
        or (parts[0] == "assets" and suffix in (".css", ".js", ".woff2"))
    )


def committed_members(root, commit):
    """Read canonical Git blobs; checkout filters and file timestamps cannot affect the ZIP."""
    entries = {}
    lower = set()
    listing = git(root, "ls-tree", "-r", "-z", "--full-tree", commit, "--", *SOURCE)
    for entry in listing.split(b"\0")[:-1]:
        meta, raw_name = entry.split(b"\t", 1)
        mode, kind, oid = meta.split()
        name = raw_name.decode("utf-8")
        if mode not in (b"100644", b"100755") or kind != b"blob" or not member_allowed(name):
            raise ValueError(f"Forbidden package input: {name}")
        if name.lower() in lower:
            raise ValueError("Case-insensitive package path collision")
        lower.add(name.lower())
        entries[name] = oid
    if ENTRY not in entries:
        raise ValueError("Plugin entry point is missing")
    blobs = BytesIO(git(root, "cat-file", "--batch", data=b"\n".join(entries.values()) + b"\n"))
    members = {}
    for name, oid in entries.items():
        header = blobs.readline().split()
        if len(header) != 3 or header[:2] != [oid, b"blob"]:
            raise ValueError("Unexpected Git blob")
        size = int(header[2])
        body = blobs.read(size)
        if len(body) != size or blobs.read(1) != b"\n":
            raise ValueError("Incomplete Git blob")
        members[f"{PLUGIN}/{name}"] = body
    return members


def package_manifest(commit, members, archive):
    match = VERSION.search(members.get(f"{PLUGIN}/{ENTRY}", b""))
    if not match:
        raise ValueError("Plugin version is missing or invalid")
    version = match[1].decode("ascii")
    return {
        "schema": 1, "commit": commit, "version": version,
        "zip": f"{PLUGIN}-{version}-{commit[:12]}.zip", "sha256": sha256(archive),
        "files": {name: sha256(data) for name, data in sorted(members.items())},
    }


def checksum(manifest):
    return f"{manifest['sha256']}  {manifest['zip']}\n".encode()


def verify_package(archive, manifest, sums, expected_commit):
    """Check ZIP bytes and every member against the canonical manifest."""
    if not SHA.fullmatch(expected_commit):
        raise ValueError("Expected a full commit SHA")
    declared = json.loads(manifest)
    if declared.get("commit") != expected_commit or declared.get("sha256") != sha256(archive):
        raise ValueError("ZIP digest or commit does not match the manifest")
    with zipfile.ZipFile(BytesIO(archive)) as zip_file:
        names = zip_file.namelist()
        prefix = PLUGIN + "/"
        if len({name.lower() for name in names}) != len(names) or any(
            not name.startswith(prefix) or not member_allowed(name.removeprefix(prefix)) for name in names
        ):
            raise ValueError("Unexpected or duplicate ZIP member")
        members = {name: zip_file.read(name) for name in names}
    verified = package_manifest(expected_commit, members, archive)
    if manifest != json_bytes(verified) or sums != checksum(verified):
        raise ValueError("Manifest or ZIP checksum does not match the package")
    return verified


def build(root, output, commit=None):
    if git(root, "status", "--porcelain", "--untracked-files=all", "--ignore-submodules=none").strip():
        raise ValueError("Refusing to package a dirty checkout. Commit reviewed changes first.")
    head = git(root, "rev-parse", "HEAD").decode().strip()
    if commit is not None and (not SHA.fullmatch(commit) or commit != head):
        raise ValueError("Requested full SHA must equal the checked-out commit")

    members = committed_members(root, head)
    buffer = BytesIO()
    with zipfile.ZipFile(buffer, "w", compression=zipfile.ZIP_STORED) as archive:
        for name, data in sorted(members.items()):
            entry = zipfile.ZipInfo(name, (1980, 1, 1, 0, 0, 0))
            entry.create_system = 3
            entry.external_attr = 0o100644 << 16
            archive.writestr(entry, data)
    zipped = buffer.getvalue()
    manifest = package_manifest(head, members, zipped)
    package = output / manifest["zip"]
    output.mkdir(parents=True, exist_ok=True)
    package.write_bytes(zipped)
    package.with_suffix(".zip.sha256").write_bytes(checksum(manifest))
    package.with_suffix(".zip.manifest.json").write_bytes(json_bytes(manifest))
    return manifest


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--commit", help="Require this full SHA to match HEAD")
    parser.add_argument("--output", type=Path, default=ROOT / "dist")
    args = parser.parse_args()
    try:
        manifest = build(ROOT, args.output, args.commit)
    except (ValueError, OSError, subprocess.CalledProcessError) as exc:
        parser.exit(1, f"{exc}\n")
    print(f"Commit: {manifest['commit']}\nArtifact: {args.output / manifest['zip']}\nSHA256: {manifest['sha256']}")


if __name__ == "__main__":
    main()
