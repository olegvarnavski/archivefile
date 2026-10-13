#!/usr/bin/env python3
"""Build a MODX/xPDO 1.1 script-vehicle transport package (no server credentials)."""
from pathlib import Path
import hashlib
import shutil
import zipfile

ROOT = Path(__file__).resolve().parents[1]
SIGNATURE = 'archivefile-1.4.0-pl'
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

def build():
    if STAGE.exists():
        shutil.rmtree(STAGE)
    vehicle_dir = STAGE / 'xPDOScriptVehicle'
    vehicle_dir.mkdir(parents=True)
    for tree in ['core', 'assets']:
        shutil.copytree(ROOT / tree, vehicle_dir / 'payload' / tree)
    shutil.copyfile(ROOT / 'build/install.php', vehicle_dir / 'install.script')
    # Include rebuildable source and tests as an inert folder; the installer ignores it.
    for tree in ['core', 'assets', 'build', 'tests']:
        shutil.copytree(ROOT / tree, STAGE / 'SOURCE' / tree)
    for filename in ['README.md', 'LICENSE.txt']:
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
            'package_name': 'Archive File', 'package_version': '1.4.0',
            'readme': (ROOT / 'README.md').read_text(),
            'license': (ROOT / 'LICENSE.txt').read_text(),
            'changelog': '1.4.0-pl: Working 1.1.4 controller and loader retained; inline settings and history, icons, source deletion, forced download, vertical alignment and per-source lastfile static URLs. No source removal on upgrade.'
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
                z.write(path, path.relative_to(DIST))
    print(target)
    print('SHA256', hashlib.sha256(target.read_bytes()).hexdigest())

if __name__ == '__main__':
    build()
