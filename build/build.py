#!/usr/bin/env python3
"""Build a MODX/xPDO 1.1 script-vehicle transport package (no server credentials)."""
from pathlib import Path
import hashlib
import shutil
import zipfile

ROOT = Path(__file__).resolve().parents[1]
SIGNATURE = 'archivefile-1.5.1-pl'
DIST = ROOT / 'dist'
STAGE = DIST / SIGNATURE

def php(value):
    if value is None:
        return 'NULL'
    if value is True:
        return 'true'
    if value is False:
        return 'false'
    if isinstance(value, int):
        return str(value)
    if isinstance(value, str):
        return "'" + value.replace('\\', '\\\\').replace("'", "\\'") + "'"
    if isinstance(value, dict):
        return 'array(\n' + ',\n'.join(php(k) + ' => ' + php(v) for k, v in value.items()) + '\n)'
    if isinstance(value, list):
        return 'array(\n' + ',\n'.join(php(v) for v in value) + '\n)'
    raise TypeError(value)

def source_ignore(directory, names):
    for name in names:
        if (Path(directory) / name).is_symlink():
            raise ValueError('Symlinks must not be bundled: ' + name)
    excluded = {'runtime.php', 'config.core.php', 'storage', 'node_modules', '__pycache__', '.git', '.env'}
    return [name for name in names if name in excluded or name.endswith(('.pyc', '.lock', '.log')) or name.startswith('.env.')]

def build():
    if STAGE.exists():
        shutil.rmtree(STAGE)
    vehicle_dir = STAGE / 'xPDOScriptVehicle'
    vehicle_dir.mkdir(parents=True)
    for tree in ['core', 'assets']:
        shutil.copytree(ROOT / tree / 'components/archivefile', vehicle_dir / 'payload' / tree / 'components/archivefile', ignore=source_ignore)
    shutil.copyfile(ROOT / 'build/install.php', vehicle_dir / 'install.script')
    # Include rebuildable source and tests as an inert folder; the installer ignores it.
    for tree in ['core', 'assets', 'build', 'tests', 'docs', 'licenses']:
        if tree in ('core', 'assets'):
            shutil.copytree(ROOT / tree / 'components/archivefile', STAGE / 'SOURCE' / tree / 'components/archivefile', ignore=source_ignore)
        else:
            shutil.copytree(ROOT / tree, STAGE / 'SOURCE' / tree, ignore=source_ignore)
    for filename in ['README.md', 'LICENSE.txt', 'THIRD_PARTY_NOTICES.md', 'package.json', 'package-lock.json', 'CHANGELOG.md']:
        shutil.copyfile(ROOT / filename, STAGE / 'SOURCE' / filename)
    guid = hashlib.md5((SIGNATURE + '-install').encode()).hexdigest()
    sig = hashlib.md5(('xPDOScriptVehicle_' + guid).encode()).hexdigest()
    filename = sig + '.vehicle'
    payload = {
        'class': 'xPDOScriptVehicle', 'vehicle_class': 'xPDOScriptVehicle',
        'vehicle_package': 'transport', 'package': SIGNATURE,
        'guid': guid, 'signature': sig, 'native_key': guid,
        'abort_install_on_vehicle_fail': True,
        'object': {'source': SIGNATURE + '/xPDOScriptVehicle/install.script'}
    }
    (vehicle_dir / filename).write_text('<?php return ' + php(payload) + ';\n')
    manifest = {
        'manifest-version': '1.1',
        'manifest-attributes': {
            'package_name': 'Archive File', 'package_version': '1.5.1',
            'readme': (ROOT / 'README.md').read_text(),
            'requires': {'modx': '>=2.8.0,<3.0.0', 'php': '>=7.4.0'},
            'license': (ROOT / 'LICENSE.txt').read_text(),
            'changelog': '1.5.1-pl: recoverable publication, retryable deletion, safe installer, bounded journal, input validation and manager draft/date fixes. MODX 2 only.'
        },
        'manifest-vehicles': [{
            'vehicle_package': 'transport', 'vehicle_class': 'xPDOScriptVehicle',
            'class': 'xPDOScriptVehicle', 'guid': guid, 'native_key': guid,
            'filename': 'xPDOScriptVehicle/' + filename
        }]
    }
    (STAGE / 'manifest.php').write_text('<?php return ' + php(manifest) + ';\n')
    target = DIST / (SIGNATURE + '.transport.zip')
    with zipfile.ZipFile(target, 'w', zipfile.ZIP_DEFLATED) as z:
        for path in sorted(STAGE.rglob('*')):
            if path.is_file():
                info = zipfile.ZipInfo(str(path.relative_to(DIST)), date_time=(2026, 10, 23, 0, 0, 0))
                info.compress_type = zipfile.ZIP_DEFLATED
                info.external_attr = 0o100644 << 16
                z.writestr(info, path.read_bytes())
    print(target)
    print('SHA256', hashlib.sha256(target.read_bytes()).hexdigest())

if __name__ == '__main__':
    build()
