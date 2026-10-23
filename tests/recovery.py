#!/usr/bin/env python3
"""Kill a real PHP child before/after DB commit; use only generated SQLite fixtures."""
import json
import os
from pathlib import Path
import selectors
import subprocess
import tempfile

command = json.loads(os.environ.get('ARCHIVEFILE_TEST_PHP', '["php"]'))
fixture = str(Path(__file__).with_suffix('.php').resolve())
for point in ('before-commit', 'after-commit'):
    with tempfile.TemporaryDirectory(prefix='archivefile-crash-') as root:
        args = command + [fixture, root]
        subprocess.run(args + ['prepare', point], check=True)
        child = subprocess.Popen(args + ['crash', point], stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
        try:
            with selectors.DefaultSelector() as selector:
                selector.register(child.stdout, selectors.EVENT_READ)
                if not selector.select(15) or child.stdout.readline().strip() != 'READY':
                    raise RuntimeError('PHP did not reach the crash point')
            child.kill()  # SIGKILL: PHP finally handlers do not run.
            child.wait(timeout=5)
        finally:
            if child.poll() is None:
                child.kill()
                child.wait(timeout=5)
        subprocess.run(args + ['verify', point], check=True)
