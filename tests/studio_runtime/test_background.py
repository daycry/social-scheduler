import hashlib
import json
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

from PIL import Image

ROOT = Path(__file__).resolve().parents[2]
MODEL = ROOT / 'storage/app/private/studio-models/birefnet-general-lite.onnx'
MODEL_SHA = '5600024376f572a557870a5eb0afb1e5961636bef4e1e22132025467d0f03333'
PHOTO = ROOT / 'tests/Fixtures/images/astronaut.png'


class BackgroundRuntimeTest(unittest.TestCase):
    def invoke(self, payload):
        result = subprocess.run([sys.executable, str(ROOT / 'scripts/studio/process-image.py')],
                                input=json.dumps(payload), text=True, capture_output=True, timeout=100)
        return result, json.loads(result.stdout)

    def request(self, output):
        return {'input': str(PHOTO), 'output': str(output), 'model': str(MODEL),
                'model_sha256': MODEL_SHA, 'max_pixels': 16000000, 'max_bytes': 30 * 1024 * 1024}

    def test_real_model_removes_background_and_preserves_subject_pixels(self):
        with tempfile.TemporaryDirectory(prefix='studio-bg-', dir=ROOT / 'storage/framework/testing') as directory:
            output = Path(directory) / 'result.png'
            before = hashlib.sha256(PHOTO.read_bytes()).hexdigest()
            result, response = self.invoke(self.request(output))
            self.assertEqual(result.returncode, 0, response)
            self.assertTrue(response['ok'])
            with Image.open(output) as image:
                self.assertEqual(image.mode, 'RGBA')
                self.assertEqual(image.size, (512, 512))
                self.assertLess(image.getpixel((15, 15))[3], 30)
                self.assertGreater(image.getpixel((220, 130))[3], 220)
                self.assertGreater(image.getpixel((200, 350))[3], 220)
                self.assertGreater(len(set(image.getchannel('A').get_flattened_data())), 32)
            self.assertEqual(hashlib.sha256(PHOTO.read_bytes()).hexdigest(), before)
            self.assertEqual(response['model_sha256'], MODEL_SHA)

    def test_unverified_model_is_rejected_before_output_is_created(self):
        with tempfile.TemporaryDirectory(prefix='studio-bg-', dir=ROOT / 'storage/framework/testing') as directory:
            output = Path(directory) / 'result.png'
            payload = self.request(output)
            payload['model_sha256'] = '0' * 64
            result, response = self.invoke(payload)
            self.assertNotEqual(result.returncode, 0)
            self.assertEqual(response['error_code'], 'model_checksum')
            self.assertFalse(output.exists())

    def test_original_cannot_be_overwritten(self):
        result, response = self.invoke(self.request(PHOTO))
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(response['error_code'], 'output_exists')


if __name__ == '__main__':
    unittest.main()
