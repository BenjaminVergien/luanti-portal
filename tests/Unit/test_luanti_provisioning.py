import importlib.util
import json
import os
import subprocess
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

SOURCE = Path(__file__).resolve().parents[2] / 'resources' / 'provisioning'
IMAGE = 'ghcr.io/luanti-org/luanti:5.17.0'
IMAGE_ID = 'sha256:' + 'a' * 64


def load(name):
    spec = importlib.util.spec_from_file_location(name, SOURCE / (name + '.py'))
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


class LuantiProvisioningTest(unittest.TestCase):
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
