#!/usr/bin/env python3
"""Read-only repository checks. Never loads WordPress or runs database fixtures."""
import argparse
import os
from pathlib import Path
import re
import shutil
import subprocess
import sys
from urllib.parse import unquote, urlsplit

ROOT = Path(__file__).resolve().parents[1]
IGNORED = {'.git', '.tools', 'vendor', 'node_modules', 'build', 'dist', '__pycache__'}


def files():
    return sorted(p for p in ROOT.rglob('*') if p.is_file()
                  and not any(part in IGNORED for part in p.relative_to(ROOT).parts))


def syntax():
    runtimes = [('PHP', os.environ.get('PHP_BIN', 'php'), '.php', ['-n', '-l']),
                ('JavaScript', 'node', '.js', ['--check'])]
    failures = 0
    for title, binary, extension, args in runtimes:
        if not shutil.which(binary):
            print(f'FAIL: required runtime missing: {binary}')
            failures += 1
            continue
        paths = [p for p in files() if p.suffix == extension]
        for p in paths:
            result = subprocess.run([binary, *args, str(p)], capture_output=True, text=True)
            if result.returncode:
                print(f'FAIL {p.relative_to(ROOT)}\n{result.stdout}{result.stderr}')
                failures += 1
        print(f'{title}: checked {len(paths)} files')
    return failures


def documentation():
    failures = 0
    checked = 0
    for p in files():
        if p.suffix != '.md':
            continue
        # Link syntax only; code-fenced command examples are not hyperlinks.
        body = re.sub(r'```.*?```', '', p.read_text(encoding='utf-8'), flags=re.S)
        for target in re.findall(r'!?\[[^\]]*\]\(([^)]+)\)', body):
            target = target.strip().strip('<>')
            if not target or target.startswith('#') or urlsplit(target).scheme:
                continue
            path = unquote(urlsplit(target).path)
            resolved = (p.parent / path).resolve()
            checked += 1
            if not resolved.is_relative_to(ROOT) or not resolved.exists():
                print(f'FAIL broken/local-outside-repository link: {p.relative_to(ROOT)} -> {target}')
                failures += 1
    required = ['AGENTS.md', 'CLAUDE.md', 'docs/DEVELOPER_HANDBOOK.md',
                'docs/ARCHITECTURE.md', 'docs/SECURITY_POLICY.md',
                'docs/GIT_WORKFLOW.md', '.github/CODEOWNERS', '.github/workflows/ci.yml']
    for name in required:
        if not (ROOT / name).is_file():
            print(f'FAIL required file missing: {name}')
            failures += 1
    for p in sorted((ROOT / 'docs/modules').glob('*.md')):
        if p.name == 'README.md':
            continue
        headings = re.findall(r'^## (\d+)\.', p.read_text(), re.M)
        if headings != [str(n) for n in range(1, 12)]:
            print(f'FAIL module contract must have sections 1..11: {p.name}')
            failures += 1
    print(f'Documentation: checked {checked} relative links and module sections')
    return failures


def hygiene():
    failures = 0
    for p in files():
        name = p.name.lower()
        if (name in {'wp-config.php', '.env'} or name.startswith('.env.') and name != '.env.example'
                or name.endswith(('.sql', '.sql.gz', '.sqlite', '.sqlite3', '.pem', '.key', '.p12', '.pfx'))):
            print(f'FAIL forbidden operational/secret file: {p.relative_to(ROOT)}')
            failures += 1
    print('Hygiene: operational dump and credential-file checks completed')
    return failures


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('mode', choices=['all', 'syntax', 'docs', 'hygiene'], nargs='?', default='all')
    mode = parser.parse_args().mode
    checks = {'syntax': syntax, 'docs': documentation, 'hygiene': hygiene}
    count = sum(fn() for name, fn in checks.items() if mode in ('all', name))
    print('PASS' if count == 0 else f'FAIL: {count} checks')
    sys.exit(bool(count))
