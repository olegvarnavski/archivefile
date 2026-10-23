#!/usr/bin/env python3
"""Build an isolated source copy with private canaries; inspect ZIP without installing it."""
import hashlib
import secrets
from pathlib import Path
import shutil
import subprocess
import tempfile
import zipfile

root = Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory(prefix='archivefile-package-') as temp:
    test = Path(temp)
    for directory in ('core','assets','build','tests','docs','licenses'):
        shutil.copytree(root / directory, test / directory)
    for name in ('README.md','LICENSE.txt','THIRD_PARTY_NOTICES.md','package.json','package-lock.json','CHANGELOG.md'):
        shutil.copyfile(root / name, test / name)
    canary = secrets.token_hex(32)
    core = test / 'core/components/archivefile'
    (core / 'storage').mkdir(exist_ok=True)
    (core / 'storage/private.xml').write_text(canary)
    (core / 'runtime.php').write_text(canary)
    public = test / 'assets/archivefile'
    public.mkdir()
    (public / 'private.xml').write_text(canary)
    command = ['python3', str(test / 'build/build.py')]
    subprocess.run(command, check=True, capture_output=True)
    archive = test / 'dist/archivefile-1.5.1-pl.transport.zip'
    first = hashlib.sha256(archive.read_bytes()).hexdigest()
    subprocess.run(command, check=True, capture_output=True)
    assert hashlib.sha256(archive.read_bytes()).hexdigest() == first, 'Non-deterministic ZIP'
    with zipfile.ZipFile(archive) as package:
        assert package.testzip() is None
        names = package.namelist()
        for name in names:
            assert canary not in package.read(name).decode('utf-8'), name
        for suffix in ('SOURCE/package.json','SOURCE/package-lock.json','SOURCE/docs/USAGE.md','SOURCE/licenses/GPL-2.0.txt','SOURCE/THIRD_PARTY_NOTICES.md'):
            assert any(name.endswith(suffix) for name in names), suffix
        for tree in ('core','assets'):
            for path in (test / tree / 'components/archivefile').rglob('*'):
                if not path.is_file() or 'storage' in path.parts or path.name == 'runtime.php':
                    continue
                suffix = 'xPDOScriptVehicle/payload/' + str(path.relative_to(test))
                name = next(name for name in names if name.endswith(suffix))
                assert package.read(name) == path.read_bytes(), suffix
    print('PASS reproducible ZIP, private canary exclusion, full SOURCE and payload equivalence')
