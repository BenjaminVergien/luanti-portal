import importlib.util
import base64
import hashlib
import io
import json
import os
import subprocess
import tempfile
import unittest
import zipfile
import urllib.error
from pathlib import Path
from unittest.mock import patch, MagicMock

SOURCE = Path(__file__).resolve().parents[2] / 'resources' / 'provisioning'
IMAGE = 'ghcr.io/luanti-org/luanti:5.17.0'
IMAGE_ID = 'sha256:' + 'a' * 64


def load(name):
    spec = importlib.util.spec_from_file_location(name, SOURCE / (name + '.py'))
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


class LuantiProvisioningTest(unittest.TestCase):
    def template_payload(self, identifier='dev', files=None):
        files = files or {'manifest.json': '{}', 'world.mt': 'settings', 'map.sqlite': 'map'}
        buffer = io.BytesIO()
        with zipfile.ZipFile(buffer, 'w', zipfile.ZIP_DEFLATED) as archive:
            for name, value in files.items():
                archive.writestr(name, value)
        raw = buffer.getvalue()
        return {'templates': {identifier: {'revision': 'a' * 64, 'sha256': hashlib.sha256(raw).hexdigest(),
                                           'archive': base64.b64encode(raw).decode()}}}

    def test_portal_templates_are_extracted_validated_and_supplied_to_the_installer(self):
        module = load('install_bridge')
        payload = self.template_payload()
        inspector = MagicMock(return_value={'template_id': 'dev', 'revision': 'a' * 64})
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            templates = module.unpack_templates(payload, root, inspector)
            inspector.assert_called_once_with(root / 'dev', {0})
            self.assertEqual({'dev': str(root / 'dev')}, templates)
            self.assertEqual('map', (root / 'dev/map.sqlite').read_text())
            settings = module.installation_settings({'admin_name': 'Administrator'}, {'image': IMAGE}, root / 'game', templates)
            self.assertEqual(templates, settings['templates'])
        self.assertEqual({'dev': {'revision': 'a' * 64, 'sha256': payload['templates']['dev']['sha256']}}, module.template_identity(payload))

    def test_portal_template_import_rejects_traversal_private_state_and_wrong_identity(self):
        module = load('install_bridge')
        for extra in ('../outside', 'auth.sqlite', 'players/name', 'world.mt/child'):
            with self.subTest(extra=extra), tempfile.TemporaryDirectory() as temporary:
                payload = self.template_payload(files={'manifest.json': '{}', 'world.mt': 'settings', 'map.sqlite': 'map', extra: 'unsafe'})
                inspector = MagicMock()
                with self.assertRaises(ValueError):
                    module.unpack_templates(payload, Path(temporary), inspector)
                inspector.assert_not_called()
        with self.assertRaises(ValueError):
            module.template_identity(self.template_payload(identifier='../escape'))
        with tempfile.TemporaryDirectory() as temporary, self.assertRaises(ValueError):
            module.unpack_templates(self.template_payload(), Path(temporary), lambda *args: {'template_id': 'dev', 'revision': 'b' * 64})

    def test_portal_template_upload_checksum_and_member_types_are_enforced(self):
        module = load('install_bridge')
        payload = self.template_payload()
        payload['templates']['dev']['sha256'] = 'b' * 64
        with tempfile.TemporaryDirectory() as temporary, self.assertRaises(ValueError):
            module.unpack_templates(payload, Path(temporary), MagicMock())
        buffer = io.BytesIO()
        with zipfile.ZipFile(buffer, 'w') as archive:
            archive.writestr('manifest.json', '{}')
            archive.writestr('world.mt', 'settings')
            entry = zipfile.ZipInfo('map.sqlite')
            entry.create_system = 3
            entry.external_attr = 0o120777 << 16
            archive.writestr(entry, '/outside')
        raw = buffer.getvalue()
        payload = {'templates': {'dev': {'revision': 'a' * 64, 'sha256': hashlib.sha256(raw).hexdigest(),
                                        'archive': base64.b64encode(raw).decode()}}}
        with tempfile.TemporaryDirectory() as temporary, self.assertRaises(ValueError):
            module.unpack_templates(payload, Path(temporary), MagicMock())

    def test_portal_template_identity_retains_all_worlds_and_existing_no_template_payloads(self):
        module = load('install_bridge')
        self.assertEqual({}, module.template_identity({}))
        payload = self.template_payload()
        payload['templates'].update(self.template_payload(identifier='test')['templates'])
        self.assertEqual({'dev', 'test'}, set(module.template_identity(payload)))
        for invalid in ([], {'dev': {'archive': '', 'sha256': 'a' * 64, 'revision': '../revision'}}):
            with self.subTest(invalid=invalid), self.assertRaises(ValueError):
                module.template_identity({'templates': invalid})

    def test_public_bridge_uses_real_hostname_without_development_proxy(self):
        module = load('install_bridge')
        payload = {'admin_name': 'Administrator', 'hostname': 'amber-otter.bensfoundry.de'}
        settings = module.installation_settings(payload, {'image': IMAGE}, Path('/trusted/game'))
        self.assertEqual('amber-otter.bensfoundry.de', settings['hostname'])
        self.assertFalse(settings['development'])
        del payload['hostname']
        settings = module.installation_settings(payload, {'image': IMAGE}, Path('/trusted/game'))
        self.assertEqual('localhost', settings['hostname'])
        self.assertTrue(settings['development'])

    def test_https_dns_gate_requires_only_the_expected_ipv4_and_no_ipv6(self):
        module = load('install_https')
        address = (2, 1, 6, '', ('192.0.2.42', 80))
        ipv6 = (23, 1, 6, '', ('2001:db8::1', 80, 0, 0))
        with patch.object(module.socket, 'getaddrinfo', side_effect=[[address], module.socket.gaierror()]):
            self.assertTrue(module.dns_ready('host.example.test', '192.0.2.42'))
        with patch.object(module.socket, 'getaddrinfo', side_effect=[[address], [ipv6]]):
            self.assertFalse(module.dns_ready('host.example.test', '192.0.2.42'))
        with patch.object(module.socket, 'getaddrinfo', side_effect=[[address], module.socket.gaierror()]):
            self.assertFalse(module.dns_ready('host.example.test', '192.0.2.99'))
        with patch.object(module.socket, 'getaddrinfo', side_effect=module.socket.gaierror()):
            self.assertFalse(module.dns_ready('host.example.test', '192.0.2.42'))

    def test_https_input_rejects_configuration_injection(self):
        module = load('install_https')
        payload = {'hostname': 'amber-otter.bensfoundry.de', 'reference': 'a' * 36,
                   'ipv4': '192.0.2.42', 'email': 'admin@example.test'}
        module.validate(payload)
        for field, value in [('hostname', 'host.test;include /tmp/evil;'), ('email', 'a@example.test\n--debug'),
                             ('ipv4', '192.0.2.42/24'), ('reference', '../outside')]:
            with self.subTest(field=field), self.assertRaises(ValueError):
                module.validate(dict(payload, **{field: value}))

    def test_nginx_only_proxies_api_over_tls_and_preserves_authentication(self):
        module = load('install_https')
        http = module.nginx_configuration('host.example.test', False).decode()
        self.assertIn('listen 80;', http)
        self.assertIn('/.well-known/acme-challenge/', http)
        self.assertIn('root /var/www/luanti-portal-acme;', http)
        self.assertNotIn('/var/lib/luanti-portal/', http)
        self.assertIn('location / { return 404; }', http)
        self.assertNotIn('proxy_pass', http)
        config = module.nginx_configuration('host.example.test', True).decode()
        self.assertIn('listen 443 ssl;', config)
        self.assertIn('location /v1/', config)
        self.assertIn('proxy_pass http://127.0.0.1:8765;', config)
        self.assertIn('proxy_set_header Authorization $http_authorization;', config)
        self.assertIn('proxy_set_header Content-Type $http_content_type;', config)
        self.assertIn('client_max_body_size 256k;', config)
        self.assertIn('access_log off;', config)
        self.assertNotIn('access_log on', config)

    def test_dns_propagation_wait_does_not_request_a_certificate_or_change_services(self):
        module = load('install_https')
        command = MagicMock()
        with patch.object(module, 'dns_ready', return_value=False), patch.object(module, 'activate') as activate:
            self.assertEqual({'status': 'dns_pending'}, module.provision({'hostname': 'host.example.test', 'ipv4': '192.0.2.42'}, command))
        command.assert_not_called()
        activate.assert_not_called()

    def test_certificate_failure_remains_retryable_without_exposing_diagnostics(self):
        module = load('install_https')
        payload = {'hostname': 'host.example.test', 'ipv4': '192.0.2.42', 'email': 'admin@example.test'}
        command = MagicMock(side_effect=subprocess.CalledProcessError(1, 'certbot', stderr='private-details'))
        with patch.object(module, 'dns_ready', return_value=True), patch.object(module.Path, 'is_file', return_value=True), \
                patch.object(module, 'trusted_directory'), patch.object(module, 'certificate_ready', return_value=False), \
                patch.object(module, 'activate'), patch.object(module, 'write_owned') as write:
            result = module.provision(payload, command)
        self.assertEqual({'status': 'certificate_pending'}, result)
        write.assert_not_called()
        args = command.call_args.args
        self.assertEqual('/usr/bin/certbot', args[0])
        self.assertIn('--webroot', args)
        self.assertEqual('/var/www/luanti-portal-acme', args[args.index('--webroot-path') + 1])
        self.assertIn('--non-interactive', args)
        self.assertNotIn('--force-renewal', args)

    def test_valid_certificate_is_reused_and_renewal_reloads_validated_nginx(self):
        module = load('install_https')
        payload = {'hostname': 'host.example.test', 'ipv4': '192.0.2.42', 'email': 'admin@example.test', 'reference': 'a' * 36}
        command = MagicMock()
        with patch.object(module, 'dns_ready', return_value=True), patch.object(module.Path, 'is_file', return_value=True), \
                patch.object(module, 'trusted_directory'), patch.object(module, 'certificate_ready', return_value=True), \
                patch.object(module, 'activate') as activate, patch.object(module, 'write_owned') as write:
            result = module.provision(payload, command)
        self.assertEqual('ready', result['status'])
        self.assertEqual('host.example.test', result['hostname'])
        self.assertEqual(2, activate.call_count)
        hook = write.call_args.args
        self.assertIn('/renewal-hooks/deploy/', hook[0].as_posix())
        self.assertIn(b'/usr/sbin/nginx -t\n/usr/bin/systemctl reload nginx.service', hook[1])
        self.assertEqual(0o755, hook[2])
        command.assert_called_once_with('/usr/bin/systemctl', 'enable', '--now', 'certbot.timer')

    def test_first_certificate_is_issued_before_tls_virtual_host_activation(self):
        module = load('install_https')
        payload = {'hostname': 'host.example.test', 'ipv4': '192.0.2.42', 'email': 'admin@example.test', 'reference': 'a' * 36}
        command = MagicMock()
        with patch.object(module, 'dns_ready', return_value=True), patch.object(module.Path, 'is_file', return_value=True), \
                patch.object(module, 'trusted_directory'), patch.object(module, 'certificate_ready', side_effect=[False, True]), \
                patch.object(module, 'activate') as activate, patch.object(module, 'write_owned'):
            result = module.provision(payload, command)
        self.assertEqual('ready', result['status'])
        self.assertNotIn(b'listen 443', activate.call_args_list[0].args[0])
        self.assertIn(b'listen 443 ssl', activate.call_args_list[1].args[0])
        self.assertEqual('/usr/bin/certbot', command.call_args_list[0].args[0])
        self.assertIn('host.example.test', command.call_args_list[0].args)

    def test_nginx_validation_failure_restores_existing_configuration_without_reload(self):
        module = load('install_https')
        path = MagicMock()
        path.exists.return_value = True
        path.is_symlink.return_value = False
        previous = b'# Managed by luanti-portal;\nprevious configuration'
        command = MagicMock(side_effect=subprocess.CalledProcessError(1, 'nginx -t'))
        with patch.object(module, 'Path', return_value=path), patch.object(module, 'read_owned', return_value=previous), \
                patch.object(module, 'write_owned') as write, self.assertRaises(subprocess.CalledProcessError):
            module.activate(b'new configuration', command)
        self.assertEqual(b'new configuration', write.call_args_list[0].args[1])
        self.assertEqual(previous, write.call_args_list[1].args[1])
        command.assert_called_once_with('/usr/sbin/nginx', '-t')

    def bridge_archive(self, names):
        stream = io.BytesIO()
        hashes = {name: hashlib.sha256(raw).hexdigest() for name, raw in names.items()}
        digest = hashlib.sha256(json.dumps(hashes, sort_keys=True, separators=(',', ':')).encode()).hexdigest()
        files = dict(names, **{'bundle.json': json.dumps({'files': hashes, 'sha256': digest, 'git_head': 'a' * 40}).encode()})
        with zipfile.ZipFile(stream, 'w') as archive:
            for name, raw in files.items():
                entry = zipfile.ZipInfo(name)
                entry.filename = name
                entry.external_attr = 0o100644 << 16
                archive.writestr(entry, raw)
        return stream.getvalue()

    def test_bridge_upload_checks_checksum_and_manifest_before_installation(self):
        module = load('install_bridge')
        raw = self.bridge_archive({'init.lua': b'reviewed lua', 'api/serve.py': b'reviewed python'})
        with tempfile.TemporaryDirectory() as temporary:
            target = Path(temporary) / 'source'
            manifest = module.unpack(raw, hashlib.sha256(raw).hexdigest(), target, True)
            self.assertEqual('a' * 40, manifest['git_head'])
            self.assertEqual(b'reviewed lua', (target / 'init.lua').read_bytes())
            with self.assertRaises(ValueError):
                module.unpack(raw, 'b' * 64, Path(temporary) / 'wrong', True)
            self.assertFalse((Path(temporary) / 'wrong').exists())

    def test_bridge_upload_rejects_traversal_and_links_before_writing_any_files(self):
        module = load('install_bridge')
        for name in ('../outside.py', 'api/../../outside.py', '.env', 'api\\outside.py'):
            raw = self.bridge_archive({name: b'unsafe'})
            with tempfile.TemporaryDirectory() as temporary:
                target = Path(temporary) / 'source'
                with self.assertRaises(ValueError):
                    module.unpack(raw, hashlib.sha256(raw).hexdigest(), target, True)
                self.assertFalse(target.exists())
        stream = io.BytesIO()
        with zipfile.ZipFile(stream, 'w') as archive:
            link = zipfile.ZipInfo('api/serve.py')
            link.external_attr = 0o120777 << 16
            archive.writestr(link, '/etc/passwd')
        raw = stream.getvalue()
        with tempfile.TemporaryDirectory() as temporary:
            with self.assertRaises(ValueError):
                module.unpack(raw, hashlib.sha256(raw).hexdigest(), Path(temporary) / 'source', True)

    def test_private_api_request_verifies_certificate_and_preserves_body_and_rejected_token(self):
        module = load('bridge_request')
        payload = {'ca_certificate': 'fictitious public ca', 'path': '/v1/jobs', 'body': '{"version":1}\n',
                   'method': 'POST', 'token': 'incorrect-fictitious-token', 'timeout': 8}
        response = MagicMock()
        response.code = 401
        response.read.return_value = b'{"error":"unauthorized"}'
        response.__enter__.return_value = response
        client = MagicMock()
        client.open.return_value = response
        with patch.object(module.ssl, 'create_default_context') as context, \
                patch.object(module.urllib.request, 'build_opener', return_value=client):
            result = module.request(payload)
        context.assert_called_once_with(cadata='fictitious public ca')
        operation = client.open.call_args.args[0]
        self.assertEqual('https://localhost:8443/v1/jobs', operation.full_url)
        self.assertEqual(payload['body'].encode(), operation.data)
        self.assertEqual('Bearer incorrect-fictitious-token', operation.get_header('Authorization'))
        self.assertEqual(401, result['status'])
        self.assertEqual(b'{"error":"unauthorized"}', base64.b64decode(result['body']))

    def test_private_api_rejects_redirects_and_bounds_response_reading(self):
        module = load('bridge_request')
        self.assertIsNone(module.NoRedirect().redirect_request(None, None, 302, '', {}, 'https://elsewhere.example'))
        payload = {'ca_certificate': 'fictitious ca', 'path': '/v1/status', 'body': None,
                   'method': 'GET', 'token': 'test-token', 'timeout': 8}
        response = MagicMock()
        response.__enter__.return_value = response
        response.read.return_value = b'x' * 262145
        client = MagicMock()
        client.open.return_value = response
        with patch.object(module.ssl, 'create_default_context'), patch.object(module.urllib.request, 'build_opener', return_value=client):
            self.assertEqual({'too_large': True}, module.request(payload))
        response.read.assert_called_once_with(262145)

    def test_installer_pulls_selected_image_and_executes_engine_by_immutable_id(self):
        module = load('install_luanti')
        calls = []

        def run(arguments, timeout=300):
            calls.append(arguments)
            if arguments[:3] == ['docker', 'image', 'inspect']:
                return json.dumps([{'Id': IMAGE_ID}])
            if arguments[:2] == ['docker', 'run']:
                return 'Luanti 5.17.0 (Linux)\nBuild details\n'
            return ''

        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            config = root / 'config.json'
            config.write_text(json.dumps({'image': IMAGE, 'reference': 'test-reference'}))
            with patch.object(module, 'ROOT', root), patch.object(module, 'CONFIG', config), \
                    patch.object(module, 'run', side_effect=run), patch.dict(os.environ):
                module.main()
            result = json.loads((root / 'installation.json').read_text())

        self.assertEqual('installed', result['status'])
        self.assertEqual(IMAGE_ID, result['image_id'])
        self.assertEqual('Luanti 5.17.0 (Linux)', result['engine_version'])
        self.assertIn(['docker', 'pull', IMAGE], calls)
        engine_call = next(call for call in calls if call[:2] == ['docker', 'run'])
        self.assertEqual([IMAGE_ID, '--version'], engine_call[-2:])
        self.assertIn('--network=none', engine_call)
        self.assertIn('--read-only', engine_call)
        self.assertIn('--pull=never', engine_call)

    def test_failed_image_pull_is_recorded_without_raw_command_output(self):
        module = load('install_luanti')

        def run(arguments, timeout=300):
            if arguments[:2] == ['docker', 'pull']:
                raise subprocess.CalledProcessError(1, arguments, stderr='private-details')
            return ''

        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            config = root / 'config.json'
            config.write_text(json.dumps({'image': IMAGE, 'reference': 'test-reference'}))
            with patch.object(module, 'ROOT', root), patch.object(module, 'CONFIG', config), \
                    patch.object(module, 'run', side_effect=run), patch.dict(os.environ):
                module.main()
            result = json.loads((root / 'installation.json').read_text())

        self.assertEqual('failed', result['status'])
        self.assertEqual('image', result['step'])
        self.assertNotIn('private-details', json.dumps(result))

    def test_verifier_reexecutes_engine_instead_of_trusting_the_bootstrap_marker(self):
        module = load('verify_luanti')
        calls = []

        def run(arguments):
            calls.append(arguments)
            if arguments[:3] == ['docker', 'image', 'inspect']:
                return json.dumps([{'Id': IMAGE_ID}])
            return 'Luanti 5.17.0 (Linux)\n'

        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / 'installation.json').write_text(json.dumps({
                'version': 1, 'status': 'installed', 'image': IMAGE, 'image_id': IMAGE_ID,
                'reference': 'test-reference', 'engine_version': 'cached-version',
            }))
            with patch.object(module, 'ROOT', root), patch.object(module, 'run', side_effect=run):
                result = module.verify()

        self.assertEqual('verified', result['status'])
        self.assertEqual('Luanti 5.17.0 (Linux)', result['engine_version'])
        self.assertEqual(2, len(calls))
        self.assertEqual([IMAGE_ID, '--version'], calls[1][-2:])

    def test_changed_image_id_is_refused_before_running_another_image(self):
        module = load('verify_luanti')
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / 'installation.json').write_text(json.dumps({
                'version': 1, 'status': 'installed', 'image': IMAGE, 'image_id': IMAGE_ID,
            }))
            with patch.object(module, 'ROOT', root), \
                    patch.object(module, 'run', return_value=json.dumps([{'Id': 'sha256:' + 'b' * 64}])) as run:
                result = module.verify()
            run.assert_called_once_with(['docker', 'image', 'inspect', IMAGE])

        self.assertEqual('failed', result['status'])
        self.assertEqual('verification', result['step'])

    def test_missing_marker_reports_waiting_without_running_docker(self):
        module = load('verify_luanti')
        with tempfile.TemporaryDirectory() as directory:
            with patch.object(module, 'ROOT', Path(directory)), patch.object(module, 'run') as run:
                result = module.verify()
            run.assert_not_called()

        self.assertEqual({'version': 1, 'status': 'waiting'}, result)

    def test_engine_timeout_during_verification_reports_failure(self):
        module = load('verify_luanti')

        def run(arguments):
            if arguments[:3] == ['docker', 'image', 'inspect']:
                return json.dumps([{'Id': IMAGE_ID}])
            raise subprocess.TimeoutExpired(arguments, 8, stderr='private-details')

        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / 'installation.json').write_text(json.dumps({
                'version': 1, 'status': 'installed', 'image': IMAGE, 'image_id': IMAGE_ID,
            }))
            with patch.object(module, 'ROOT', root), patch.object(module, 'run', side_effect=run):
                result = module.verify()

        self.assertEqual('failed', result['status'])
        self.assertEqual('verification', result['step'])
        self.assertNotIn('private-details', json.dumps(result))


if __name__ == '__main__':
    unittest.main()
