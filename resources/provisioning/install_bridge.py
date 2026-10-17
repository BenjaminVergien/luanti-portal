"""Install a pinned GitHub snapshot using the reviewed Docker installer, over pinned SSH."""
import base64
import hashlib
import io
import json
import os
from pathlib import Path, PurePosixPath
import stat
import subprocess
import sys
import traceback
import uuid
import zipfile


def directory(path):
    if not path.exists():
        directory(path.parent)
        path.mkdir(mode=0o700)
    info = path.lstat()
    if not stat.S_ISDIR(info.st_mode) or info.st_uid != 0 or info.st_mode & 0o022:
        raise ValueError('Untrusted installation directory')


def unpack(raw, digest, destination, source):
    if len(raw) > 67108864 or hashlib.sha256(raw).hexdigest() != digest:
        raise ValueError('Upload checksum mismatch')
    with zipfile.ZipFile(io.BytesIO(raw)) as archive:
        entries = archive.infolist()
        if len(entries) > 10000 or len({entry.filename for entry in entries}) != len(entries):
            raise ValueError('Invalid upload member count')
        size = 0
        for entry in entries:
            path = PurePosixPath(entry.filename)
            if (path.is_absolute() or '..' in path.parts or '\\' in entry.filename or entry.orig_filename != entry.filename or str(path) != entry.filename
                    or not path.parts or any(part.startswith('.') for part in path.parts)
                    or entry.external_attr >> 16 & 0o170000 != 0o100000):
                raise ValueError('Unsafe upload member')
            if source and not (entry.filename in {'init.lua', 'bridge.lua', 'mod.conf', 'README.md', 'LICENSE', 'bundle.json'}
                               or (len(path.parts) == 2 and path.parts[0] in {'api', 'lifecycle', 'tools'}
                                   and (path.suffix == '.py' or entry.filename == 'api/requirements.txt'))):
                raise ValueError('Unexpected source upload member')
            size += entry.file_size
            if entry.file_size > 16777216 or size > 134217728:
                raise ValueError('Upload exceeds extraction bounds')
        destination.mkdir(mode=0o700 if source else 0o755)
        destination.chmod(0o700 if source else 0o755)
        for entry in entries:
            target = destination / entry.filename
            target.parent.mkdir(parents=True, exist_ok=True, mode=0o755)
            target.write_bytes(archive.read(entry))
            target.chmod(0o644)
    if source:
        manifest = json.loads((destination / 'bundle.json').read_text())
        actual = {entry.filename: hashlib.sha256((destination / entry.filename).read_bytes()).hexdigest()
                  for entry in entries if entry.filename != 'bundle.json'}
        if (actual != manifest['files'] or manifest['sha256'] != hashlib.sha256(
                json.dumps(actual, sort_keys=True, separators=(',', ':')).encode()).hexdigest()):
            raise ValueError('Uploaded source manifest mismatch')
        return manifest


def installation_settings(payload, metadata, game):
    public = bool(payload.get('hostname'))
    return {'image': metadata['image'], 'game_source': str(game), 'admin_name': payload['admin_name'],
            'hostname': payload['hostname'] if public else 'localhost', 'development': not public}


def main(payload):
    import fcntl
    os.umask(0o022)
    metadata = json.loads(Path('/var/lib/luanti-portal/installation.json').read_text())
    if metadata['reference'] != payload['reference'] or metadata['image_id'] != payload['image_id']:
        raise ValueError('VM installation identity differs from the portal record')
    base = Path('/var/lib/luanti-portal/bridge-distribution')
    directory(base)
    lock = os.open(base / 'deployment.lock', os.O_CREAT | os.O_NOFOLLOW | os.O_RDWR, 0o600)
    log = None
    try:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        active = base / 'active.json'
        identity = {key: payload[key] for key in ('commit', 'source_sha256', 'game_archive_sha256', 'image_id', 'admin_name')}
        if payload.get('hostname'):
            identity['hostname'] = payload['hostname']
        if active.exists() and json.loads(active.read_text()) != identity:
            raise ValueError('Existing bridge installation differs; automatic upgrades are not permitted')
        stage = base / ('install-' + uuid.uuid4().hex)
        stage.mkdir(mode=0o700)
        log = (stage / 'installation.log').open('w')
        manifest = unpack(base64.b64decode(payload['source'], validate=True), payload['source_archive_sha256'], stage / 'source', True)
        if manifest['git_head'] != payload['commit'] or manifest['sha256'] != payload['source_sha256']:
            raise ValueError('Source does not match the pinned GitHub commit')
        unpack(base64.b64decode(payload['game'], validate=True), payload['game_archive_sha256'], stage / 'game', False)
        subprocess.run(['/usr/bin/apt-get', 'update', '-qq'], check=True, stdout=log, stderr=log, timeout=180)
        subprocess.run(['/usr/bin/apt-get', 'install', '-y', '-qq', 'python3-venv', 'acl', 'sudo', 'openssl'],
                       check=True, stdout=log, stderr=log, timeout=300, env=dict(os.environ, DEBIAN_FRONTEND='noninteractive'))
        sys.path.insert(0, str(stage / 'source'))
        from lifecycle.install_docker import install
        from api.security import read_regular, atomic_private
        public = bool(payload.get('hostname'))
        settings = installation_settings(payload, metadata, stage / 'game')
        if public and active.exists():
            result = {'image_id': metadata['image_id']}
        else:
            install(settings)
            result = install(settings, apply=True)
        if result['image_id'] != payload['image_id']:
            raise ValueError('Installed image differs from the verified image')
        certificate = None
        if not public:
            from tools.deploy_development import tls
            certificate = tls()
        token = read_regular(Path('/etc/civicrm-bridge-api/development.token'), 4096, {0}, forbidden=0o077).decode().strip()
        import ssl
        import urllib.error
        import urllib.request
        handlers = [urllib.request.ProxyHandler({})]
        if not public:
            handlers.append(urllib.request.HTTPSHandler(context=ssl.create_default_context(cadata=certificate)))
        class NoRedirect(urllib.request.HTTPRedirectHandler):
            def redirect_request(self, *args, **kwargs):
                return None
        handlers.append(NoRedirect())
        client = urllib.request.build_opener(*handlers)
        origin = 'http://127.0.0.1:8765' if public else 'https://localhost:8443'
        probe = urllib.request.Request(origin + '/v1/lifecycle/jobs/install-check-' + uuid.uuid4().hex,
                                       headers={'Authorization': 'Bearer ' + token})
        ready = False
        import time
        for attempt in range(10):
            try:
                with client.open(probe, timeout=3):
                    pass
            except urllib.error.HTTPError as error:
                with error:
                    raw = error.read(262145)
                    data = json.loads(raw) if len(raw) <= 262144 else {}
                    ready = error.code == 404 and data.get('version') == 1 and data.get('error', {}).get('code') == 'unknown_job'
                break
            except (OSError, urllib.error.URLError):
                time.sleep(1)
        if not ready:
            raise ValueError('The installed bridge API did not pass its authenticated HTTPS check')
        atomic_private(active, (json.dumps(identity) + '\n').encode(), owner=0, group=0)
        return dict(ok=True, commit=payload['commit'], source_sha256=payload['source_sha256'],
                    image_id=result['image_id'], token=token, ca_certificate=certificate)
    except Exception:
        if log is not None:
            traceback.print_exc(file=log)
        raise
    finally:
        if log is not None:
            log.close()
        os.close(lock)


if __name__ == '__main__':
    try:
        print(json.dumps(main(PAYLOAD)))
    except Exception:
        print(json.dumps({'ok': False, 'error': 'Bridge installation not confirmed; inspect private VM diagnostics.'}))
