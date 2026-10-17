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
