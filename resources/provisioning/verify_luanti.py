#!/usr/bin/env python3
"""Report bootstrap progress and verify the installed image without pulling it."""

import json
import re
import subprocess
from pathlib import Path

ROOT = Path('/var/lib/luanti-portal')


def run(arguments):
    return subprocess.run(arguments, check=True, capture_output=True, text=True,
                          timeout=8).stdout


def verify():
    path = ROOT / 'installation.json'
    if not path.exists():
        return {'version': 1, 'status': 'waiting'}
    with path.open() as source:
        result = json.loads(source.read(16384))
    if result.get('status') != 'installed':
        return result
    try:
        image = result['image']
        if not re.fullmatch(r'ghcr\.io/luanti-org/luanti:[A-Za-z0-9_][A-Za-z0-9_.-]{0,127}', image):
            raise ValueError('Invalid image')
        details = json.loads(run(['docker', 'image', 'inspect', image]))[0]
        image_id = details['Id']
        if image_id != result['image_id'] or not re.fullmatch(r'sha256:[0-9a-f]{64}', image_id):
            raise ValueError('Image changed')
        version = run(['docker', 'run', '--rm', '--pull=never', '--network=none',
                       '--read-only', '--cap-drop=ALL', '--security-opt=no-new-privileges',
                       image_id, '--version']).splitlines()[0]
        if not re.match(r'^(?:Luanti|Minetest) \d+\.\d+', version) or len(version) > 200:
            raise ValueError('Unexpected engine version')
        result.update(status='verified', engine_version=version)
    except (OSError, ValueError, KeyError, IndexError, subprocess.SubprocessError):
        result.update(status='failed', step='verification')
    return result


if __name__ == '__main__':
    try:
        print(json.dumps(verify()))
    except (OSError, ValueError, TypeError):
        print(json.dumps({'version': 1, 'status': 'invalid'}))
