"""Installation must never publish a partial or unverified model."""
import contextlib
import hashlib
import importlib.util
import io
import json
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
MODULE = importlib.util.spec_from_file_location('studio_setup', ROOT / 'scripts/studio/setup-runtime.py')
setup = importlib.util.module_from_spec(MODULE)
MODULE.loader.exec_module(setup)


class ModelInstallationTest(unittest.TestCase):
    def spec(self, data):
        return {'name': 'test-model', 'url': 'https://example.test/model.onnx', 'bytes': len(data),
                'sha256': hashlib.sha256(data).hexdigest(), 'license': 'MIT'}

    def test_checksum_failure_preserves_existing_model_and_removes_partial(self):
        with tempfile.TemporaryDirectory(dir=ROOT / 'storage/framework/testing') as directory:
            target = Path(directory) / 'model.onnx'
            target.write_bytes(b'existing model')
            with patch('urllib.request.urlopen', return_value=io.BytesIO(b'wrong bytes!')):
                with self.assertRaisesRegex(ValueError, 'checksum|size'):
                    setup.install_model(target, self.spec(b'right model!'))
            self.assertEqual(target.read_bytes(), b'existing model')
            self.assertEqual(sorted(p.name for p in target.parent.iterdir()), ['model.onnx'])

    def test_validated_download_is_published_with_metadata(self):
        with tempfile.TemporaryDirectory(dir=ROOT / 'storage/framework/testing') as directory:
            target = Path(directory) / 'model.onnx'
            data = b'verified model bytes'
            with patch('urllib.request.urlopen', return_value=io.BytesIO(data)):
                setup.install_model(target, self.spec(data))
            self.assertEqual(target.read_bytes(), data)
            self.assertIn(self.spec(data)['sha256'], target.with_suffix('.json').read_text())
            self.assertFalse(list(target.parent.glob('*.part')))

    def test_existing_verified_model_needs_no_network(self):
        with tempfile.TemporaryDirectory(dir=ROOT / 'storage/framework/testing') as directory:
            target = Path(directory) / 'model.onnx'
            target.write_bytes(b'good model')
            with patch('urllib.request.urlopen', side_effect=AssertionError('unexpected network')):
                setup.install_model(target, self.spec(b'good model'))
            self.assertEqual(target.read_bytes(), b'good model')


class PinnedManifestTest(unittest.TestCase):
    def test_manifest_pins_the_isnet_model_and_its_apache_license(self):
        spec = json.loads((ROOT / 'scripts/studio/background-model.json').read_text(encoding='utf-8'))
        self.assertEqual(spec['name'], 'isnet-general-use')
        self.assertEqual(spec['license'], 'Apache-2.0')
        self.assertEqual(spec['bytes'], 178648008)
        self.assertEqual(spec['sha256'], '60920e99c45464f2ba57bee2ad08c919a52bbf852739e96947fbb4358c0d964a')
        self.assertTrue(spec['url'].startswith('https://github.com/danielgatis/rembg/releases/download/'))
        self.assertEqual(setup.MODEL.name, 'isnet-general-use.onnx')

    def test_check_warns_when_only_the_previous_model_is_installed(self):
        with tempfile.TemporaryDirectory(dir=ROOT / 'storage/framework/testing') as directory:
            folder = Path(directory)
            (folder / 'birefnet-general-lite.onnx').write_bytes(b'old weights')
            stderr = io.StringIO()
            patches = [patch.object(setup, 'MODEL', folder / 'isnet-general-use.onnx'), patch.object(setup, 'LICENSE', folder / 'IS-Net-LICENSE.txt'),
                       patch.object(setup, 'check_runtime', return_value=True), patch('sys.argv', ['setup-runtime.py', '--check']),
                       patch('sys.stderr', stderr), patch('sys.stdout', stdout := io.StringIO())]
            with contextlib.ExitStack() as stack:
                for active in patches:
                    stack.enter_context(active)
                self.assertEqual(setup.main(), 1)
            self.assertIn('"background_model_ready": false', stdout.getvalue())
            self.assertIn('--download-model', stderr.getvalue())
            self.assertIn('birefnet-general-lite.onnx', stderr.getvalue())


if __name__ == '__main__':
    unittest.main()
