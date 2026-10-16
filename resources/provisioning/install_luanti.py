#!/usr/bin/env python3
"""Install the selected engine on a fresh portal-managed Ubuntu VM."""

import json
import os
import re
import subprocess
from pathlib import Path

ROOT = Path('/var/lib/luanti-portal')
CONFIG = Path('/etc/luanti-portal/installation.json')


def run(arguments, timeout=300):
    return subprocess.run(arguments, check=True, capture_output=True, text=True,
                          timeout=timeout).stdout


def save(result):
    temporary = ROOT / 'installation.tmp'
    temporary.write_text(json.dumps(result))
    temporary.replace(ROOT / 'installation.json')


def main():
    os.umask(0o077)
    ROOT.mkdir(mode=0o700, parents=True, exist_ok=True)
    config = json.loads(CONFIG.read_text())
    image = config['image']
    if not re.fullmatch(r'ghcr\.io/luanti-org/luanti:[A-Za-z0-9_][A-Za-z0-9_.-]{0,127}', image):
        raise ValueError('Invalid engine image')
    result = {'version': 1, 'reference': config['reference'], 'image': image,
              'status': 'installing', 'step': 'packages'}
    save(result)
    try:
        os.environ['DEBIAN_FRONTEND'] = 'noninteractive'
        run(['apt-get', '-o', 'DPkg::Lock::Timeout=120', 'update'])
        run(['apt-get', '-o', 'DPkg::Lock::Timeout=120', 'install', '-y',
             '--no-install-recommends', 'docker.io', 'docker-compose-v2'])
        result['step'] = 'docker'
        save(result)
        run(['systemctl', 'enable', '--now', 'docker'], timeout=60)
        run(['docker', 'info'], timeout=20)
        result['step'] = 'image'
        save(result)
        run(['docker', 'pull', image])
        details = json.loads(run(['docker', 'image', 'inspect', image], timeout=20))[0]
        image_id = details['Id']
        if not re.fullmatch(r'sha256:[0-9a-f]{64}', image_id):
            raise ValueError('Invalid image ID')
        result['step'] = 'engine'
        save(result)
        version = run(['docker', 'run', '--rm', '--pull=never', '--network=none',
                       '--read-only', '--cap-drop=ALL', '--security-opt=no-new-privileges',
                       image_id, '--version'], timeout=30).splitlines()[0]
        if not re.match(r'^(?:Luanti|Minetest) \d+\.\d+', version) or len(version) > 200:
            raise ValueError('Unexpected engine version')
        result.update(status='installed', image_id=image_id, engine_version=version)
    except (OSError, ValueError, KeyError, IndexError, subprocess.SubprocessError):
        result['status'] = 'failed'
    save(result)


if __name__ == '__main__':
    main()
