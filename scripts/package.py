#!/usr/bin/env python3
"""Package tracked plugin source; no activation, database access or deployment."""
import hashlib
from pathlib import Path
import re
import subprocess
import zipfile

ROOT = Path(__file__).resolve().parents[1]
if subprocess.check_output(['git', 'status', '--porcelain'], cwd=ROOT).strip():
    raise SystemExit('Refusing to package a dirty checkout. Commit reviewed changes first.')
commit = subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=ROOT, text=True).strip()
version = re.search(r'^ \* Version:\s*(\S+)', (ROOT/'sales-network.php').read_text(), re.M).group(1)
names = subprocess.check_output(['git', 'ls-files', '-z'], cwd=ROOT).decode().split('\0')
out = ROOT/'dist'
out.mkdir(exist_ok=True)
archive = out/f'sales-network-v4-{version}-{commit[:12]}.zip'
allowed_roots = {'assets', 'includes', 'templates'}
with zipfile.ZipFile(archive, 'w', zipfile.ZIP_DEFLATED) as z:
    for name in sorted(filter(None, names)):
        p = Path(name)
        if name == 'sales-network.php' or p.parts[0] in allowed_roots:
            if (ROOT/p).is_symlink():
                raise SystemExit(f'Refusing symlink: {name}')
            info = zipfile.ZipInfo('sales-network-v4/'+name, (1980, 1, 1, 0, 0, 0))
            info.compress_type = zipfile.ZIP_DEFLATED
            info.external_attr = 0o100644 << 16
            z.writestr(info, (ROOT/p).read_bytes())
digest = hashlib.sha256(archive.read_bytes()).hexdigest()
archive.with_suffix('.zip.sha256').write_text(f'{digest}  {archive.name}\n')
print(f'Commit: {commit}\nArtifact: {archive}\nSHA256: {digest}')
