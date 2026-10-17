"""Provision an owned Nginx virtual host and automatically renewed ACME certificate."""
import ipaddress
import json
import os
from pathlib import Path
import re
import socket
import stat
import subprocess
import uuid

ACME_WEBROOT = Path('/var/www/luanti-portal-acme')


def trusted_directory(path):
    if path.parent != path:
        trusted_directory(path.parent)
    if not path.exists():
        path.mkdir(mode=0o755)
    info = path.lstat()
    if not stat.S_ISDIR(info.st_mode) or info.st_uid != 0 or info.st_mode & 0o022:
        raise ValueError('Untrusted directory')


def read_owned(path, maximum=65536):
    descriptor = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    with os.fdopen(descriptor, 'rb') as source:
        info = os.fstat(source.fileno())
        if not stat.S_ISREG(info.st_mode) or info.st_uid != 0 or info.st_nlink != 1 or info.st_mode & 0o022 or info.st_size > maximum:
            raise ValueError('Untrusted configuration file')
        return source.read(maximum + 1)


def write_owned(path, content, mode=0o644):
    trusted_directory(path.parent)
    if path.exists() or path.is_symlink():
        read_owned(path)
    temporary = path.with_name(path.name + '.tmp-' + uuid.uuid4().hex)
    descriptor = os.open(temporary, os.O_CREAT | os.O_EXCL | os.O_WRONLY | os.O_NOFOLLOW, mode)
    try:
        with os.fdopen(descriptor, 'wb') as target:
            target.write(content)
        os.replace(temporary, path)
    finally:
        if temporary.exists():
            temporary.unlink()


def validate(payload):
    hostname = payload['hostname']
    if (not isinstance(hostname, str) or len(hostname) > 253 or '.' not in hostname
            or any(not re.fullmatch(r'[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?', label) for label in hostname.split('.'))):
        raise ValueError('Invalid hostname')
    if not re.fullmatch(r'[0-9a-f-]{36}', payload['reference']):
        raise ValueError('Invalid VM reference')
    ipaddress.IPv4Address(payload['ipv4'])
    if not re.fullmatch(r'[A-Za-z0-9.!#$%&\x27*+/=?^_`{|}~-]+@[A-Za-z0-9.-]+', payload['email']) or len(payload['email']) > 254:
        raise ValueError('Invalid certificate contact')


def dns_ready(hostname, ipv4):
    try:
        addresses = {row[4][0] for row in socket.getaddrinfo(hostname, 80, socket.AF_INET, socket.SOCK_STREAM)}
        try:
            ipv6 = socket.getaddrinfo(hostname, 80, socket.AF_INET6, socket.SOCK_STREAM)
        except socket.gaierror:
            ipv6 = []
        return addresses == {ipv4} and not ipv6
    except socket.gaierror:
        return False


def nginx_configuration(hostname, tls):
    header = '# Managed by luanti-portal; do not reuse for unrelated virtual hosts.\n'
    http = f'''server {{
    listen 80;
    server_name {hostname};
    access_log off;
    location ^~ /.well-known/acme-challenge/ {{
        root {ACME_WEBROOT.as_posix()};
        default_type text/plain;
        try_files $uri =404;
    }}
    location / {{ return 404; }}
}}
'''
    https = f'''server {{
    listen 443 ssl;
    server_name {hostname};
    ssl_certificate /etc/letsencrypt/live/{hostname}/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/{hostname}/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    access_log off;
    if ($host != {hostname}) {{ return 444; }}
    client_max_body_size 256k;
    location /v1/ {{
        proxy_pass http://127.0.0.1:8765;
        proxy_set_header Host $host;
        proxy_set_header Authorization $http_authorization;
        proxy_set_header Content-Type $http_content_type;
        proxy_http_version 1.1;
        proxy_cache off;
        proxy_request_buffering off;
        proxy_read_timeout 60s;
    }}
    location / {{ return 404; }}
}}
'''
    return (header + http + (https if tls else '')).encode()


def certificate_ready(hostname, command):
    certificate = Path('/etc/letsencrypt/live') / hostname / 'fullchain.pem'
    if not certificate.is_file():
        return False
    try:
        command('/usr/bin/openssl', 'x509', '-in', str(certificate), '-noout', '-checkend', '86400')
        command('/usr/bin/openssl', 'verify', '-verify_hostname', hostname, '-CApath', '/etc/ssl/certs',
                '-untrusted', str(certificate), str(certificate))
        return True
    except subprocess.CalledProcessError:
        return False


def activate(configuration, command):
    path = Path('/etc/nginx/conf.d/luanti-portal-api.conf')
    previous = read_owned(path) if path.exists() else None
    if path.is_symlink() or (previous is not None and not previous.startswith(b'# Managed by luanti-portal;')):
        raise ValueError('Refusing to replace an unrelated virtual host')
    write_owned(path, configuration)
    try:
        command('/usr/sbin/nginx', '-t')
        command('/usr/bin/systemctl', 'enable', '--now', 'nginx.service')
        command('/usr/bin/systemctl', 'reload', 'nginx.service')
    except Exception:
        if previous is None:
            path.unlink()
        else:
            write_owned(path, previous)
        raise


def provision(payload, command):
    if not dns_ready(payload['hostname'], payload['ipv4']):
        return {'status': 'dns_pending'}
    if not Path('/usr/sbin/nginx').is_file() or not Path('/usr/bin/certbot').is_file():
        command('/usr/bin/apt-get', 'update', '-qq', timeout=180)
        command('/usr/bin/apt-get', 'install', '-y', '-qq', 'nginx', 'certbot', timeout=300)
    trusted_directory(ACME_WEBROOT)
    ready = certificate_ready(payload['hostname'], command)
    activate(nginx_configuration(payload['hostname'], ready), command)
    if not ready:
        try:
            command('/usr/bin/certbot', 'certonly', '--webroot', '--webroot-path', ACME_WEBROOT.as_posix(),
                    '--preferred-challenges', 'http', '--non-interactive', '--agree-tos', '--email', payload['email'],
                    '--cert-name', payload['hostname'], '--domain', payload['hostname'], '--keep-until-expiring', timeout=180)
        except (subprocess.CalledProcessError, subprocess.TimeoutExpired):
            return {'status': 'certificate_pending'}
        if not certificate_ready(payload['hostname'], command):
            return {'status': 'certificate_pending'}
    hook = Path('/etc/letsencrypt/renewal-hooks/deploy/luanti-portal-nginx')
    write_owned(hook, b'#!/bin/sh\nset -eu\n/usr/sbin/nginx -t\n/usr/bin/systemctl reload nginx.service\n', 0o755)
    activate(nginx_configuration(payload['hostname'], True), command)
    command('/usr/bin/systemctl', 'enable', '--now', 'certbot.timer')
    return {'status': 'ready', 'hostname': payload['hostname'], 'reference': payload['reference']}


def main(payload):
    import fcntl
    os.umask(0o022)
    validate(payload)
    metadata = json.loads(read_owned(Path('/var/lib/luanti-portal/installation.json')))
    if metadata['reference'] != payload['reference']:
        raise ValueError('VM identity mismatch')
    base = Path('/etc/luanti-portal')
    trusted_directory(base)
    descriptor = os.open(base / 'https.lock', os.O_CREAT | os.O_NOFOLLOW | os.O_RDWR, 0o600)
    try:
        fcntl.flock(descriptor, fcntl.LOCK_EX | fcntl.LOCK_NB)
        manifest = base / 'https.json'
        if manifest.exists() and json.loads(read_owned(manifest)) != payload:
            raise ValueError('Existing HTTPS configuration differs')
        write_owned(manifest, (json.dumps(payload, sort_keys=True) + '\n').encode(), 0o600)
        log_path = base / 'https.log'
        if log_path.exists() or log_path.is_symlink():
            read_owned(log_path, maximum=2097152)
        log_descriptor = os.open(log_path, os.O_CREAT | os.O_TRUNC | os.O_NOFOLLOW | os.O_WRONLY, 0o600)
        with os.fdopen(log_descriptor, 'wb') as log:
            def command(*args, timeout=30):
                subprocess.run(args, stdin=subprocess.DEVNULL, stdout=log, stderr=log, check=True, timeout=timeout,
                               env={'PATH': '/usr/sbin:/usr/bin:/sbin:/bin', 'HOME': '/root', 'LC_ALL': 'C',
                                    'DEBIAN_FRONTEND': 'noninteractive'})
            return provision(payload, command)
    finally:
        os.close(descriptor)


if __name__ == '__main__':
    try:
        print(json.dumps(main(PAYLOAD)))
    except (subprocess.CalledProcessError, subprocess.TimeoutExpired):
        print(json.dumps({'status': 'retry_pending'}))
    except Exception:
        print(json.dumps({'status': 'failed'}))
