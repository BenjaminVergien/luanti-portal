"""Pinned-SSH entrypoint. Receives only allowlisted code and explicitly supplied game content."""
import base64
import hashlib
import io
import json
import os
from pathlib import Path, PurePosixPath
import stat
import subprocess
import uuid
import zipfile


def directory(path):
    if not path.exists():
        directory(path.parent)
        path.mkdir(mode=0o700)
    info = path.lstat()
    if not stat.S_ISDIR(info.st_mode) or info.st_uid != 0 or info.st_mode & 0o022:
        raise ValueError('Untrusted development deployment directory')


def unpack(raw, digest, destination, source):
    if len(raw) > 64 * 1024 * 1024 or hashlib.sha256(raw).hexdigest() != digest:
        raise ValueError('Development upload checksum mismatch')
    with zipfile.ZipFile(io.BytesIO(raw)) as archive:
        entries = archive.infolist()
        if len(entries) > 10000 or len({entry.filename for entry in entries}) != len(entries):
            raise ValueError('Invalid upload member count')
        size = 0
        for entry in entries:
            path = PurePosixPath(entry.filename)
            if (path.is_absolute() or '..' in path.parts or '\\' in entry.filename or str(path) != entry.filename
                    or not path.parts or any(part.startswith('.') for part in path.parts)
                    or entry.external_attr >> 16 & 0o170000 != 0o100000):
                raise ValueError('Unsafe development upload member')
            if source and not (entry.filename in {'init.lua', 'bridge.lua', 'mod.conf', 'README.md', 'LICENSE', 'bundle.json'}
                               or (2 <= len(path.parts) <= 4 and path.parts[0] == 'examples' and path.suffix == '.json')
                               or (len(path.parts) == 2 and path.parts[0] in {'api', 'lifecycle', 'tools', 'tests'}
                                   and (path.suffix == '.py' or entry.filename in {'tests/driver.lua', 'api/requirements.txt'}))):
                raise ValueError('Unexpected source upload member')
            size += entry.file_size
            if entry.file_size > 16 * 1024 * 1024 or size > 128 * 1024 * 1024:
                raise ValueError('Development upload exceeds extraction bounds')
        destination.mkdir(mode=0o700)
        if not source:
            destination.chmod(0o755)
        for entry in entries:
            target = destination / entry.filename
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(archive.read(entry))
            target.chmod(0o644)
    if source:
        manifest = json.loads((destination / 'bundle.json').read_text())
        actual = {entry.filename: hashlib.sha256((destination / entry.filename).read_bytes()).hexdigest()
                  for entry in entries if entry.filename != 'bundle.json'}
        if actual != manifest['files'] or manifest['sha256'] != hashlib.sha256(json.dumps(actual, sort_keys=True, separators=(',', ':')).encode()).hexdigest():
            raise ValueError('Uploaded source manifest mismatch')


def main(payload):
    os.umask(0o022)
    metadata = json.loads(Path('/var/lib/luanti-portal/installation.json').read_text())
    if metadata['reference'] != payload['reference'] or metadata['image_id'] != payload['image_id']:
        raise ValueError('VM installation identity differs from the selected portal record')
    if payload['operation'] not in {'test', 'up'}:
        raise ValueError('Unknown development operation')
    base = Path('/var/lib/luanti-portal/bridge-development')
    directory(base)
    lock = os.open(base / 'deployment.lock', os.O_CREAT | os.O_NOFOLLOW | os.O_RDWR, 0o600)
    import fcntl
    fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
    try:
        stage = base / ('upload-' + uuid.uuid4().hex)
        stage.mkdir(mode=0o700)
        code = base64.b64decode(payload['source'], validate=True)
        game = base64.b64decode(payload['game'], validate=True)
        unpack(code, payload['source_archive_sha256'], stage / 'source', True)
        unpack(game, payload['game_archive_sha256'], stage / 'game', False)
        settings = {'image': metadata['image'], 'game_source': str(stage / 'game'), 'game_sha256': payload['game_archive_sha256'],
                    'admin_name': 'Administrator', 'hostname': 'localhost', 'development': True}
        config = stage / 'settings.json'
        config.write_text(json.dumps(settings))
        config.chmod(0o600)
        log = stage / 'preflight.log'
        caddy_was_present = Path('/usr/bin/caddy').is_file()
        with log.open('wb') as output:
            subprocess.run(['/usr/bin/apt-get', 'update', '-qq'], check=True, stdout=output, stderr=subprocess.STDOUT, timeout=180)
            subprocess.run(['/usr/bin/apt-get', 'install', '-y', '-qq', 'python3-venv', 'acl', 'sudo', 'openssl', 'caddy'],
                           check=True, stdout=output, stderr=subprocess.STDOUT, timeout=300,
                           env=dict(os.environ, DEBIAN_FRONTEND='noninteractive'))
            if not caddy_was_present:
                # Only the binary is needed for isolated HTTPS fixtures.
                subprocess.run(['/usr/bin/systemctl', 'disable', '--now', 'caddy.service'],
                               check=True, stdout=output, stderr=subprocess.STDOUT, timeout=30)
        result = subprocess.run(['/usr/bin/python3', '-B', str(stage / 'source/tools/deploy_development.py'), payload['operation'], '--config', str(config)],
                                capture_output=True, timeout=1500, check=False)
        (stage / 'operation.stderr').write_bytes(result.stderr[:65536])
        if len(result.stdout) > 65536:
            raise ValueError('Development result exceeded its output bound')
        response = json.loads(result.stdout)
        if not response.get('ok'):
            return response
        return response
    finally:
        os.close(lock)


try:
    print(json.dumps(main(PAYLOAD)))
except Exception as error:
    print(json.dumps({'ok': False, 'error_type': type(error).__name__, 'error': 'Development deployment failed; inspect private VM diagnostics.'}))
