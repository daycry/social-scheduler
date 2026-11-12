import hashlib
import json
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

from PIL import Image

ROOT = Path(__file__).resolve().parents[2]
MODEL = ROOT / 'storage/app/private/studio-models/isnet-general-use.onnx'
MODEL_SHA = '60920e99c45464f2ba57bee2ad08c919a52bbf852739e96947fbb4358c0d964a'
PHOTO = ROOT / 'tests/Fixtures/images/astronaut.png'
SCRATCH = ROOT / 'storage/framework/testing'


def scratch_directory():
    SCRATCH.mkdir(parents=True, exist_ok=True)  # absent on a fresh checkout (CI)
    return tempfile.TemporaryDirectory(prefix='studio-bg-', dir=SCRATCH)


class BackgroundRuntimeTest(unittest.TestCase):
    def invoke(self, payload):
        result = subprocess.run([sys.executable, str(ROOT / 'scripts/studio/process-image.py')],
                                input=json.dumps(payload), text=True, capture_output=True, timeout=100)
        return result, json.loads(result.stdout)

    def request(self, output):
        return {'input': str(PHOTO), 'output': str(output), 'model': str(MODEL),
                'model_sha256': MODEL_SHA, 'max_pixels': 16000000, 'max_bytes': 30 * 1024 * 1024}

    @unittest.skipUnless(MODEL.is_file(), 'pinned background model not installed (python scripts/studio/setup-runtime.py --download-model)')
    def test_real_model_removes_background_and_preserves_subject_pixels(self):
        with scratch_directory() as directory:
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
                alpha = list(image.getchannel('A').get_flattened_data())
                self.assertGreater(len(set(alpha)), 32)
                # IS-Net on this fixture (measured: ~46 % transparent, ~53 % opaque): the cut must neither erase the astronaut nor keep the sky.
                self.assertTrue(0.30 < sum(value > 220 for value in alpha) / len(alpha) < 0.70)
                self.assertTrue(0.30 < sum(value < 30 for value in alpha) / len(alpha) < 0.70)
            self.assertEqual(hashlib.sha256(PHOTO.read_bytes()).hexdigest(), before)
            self.assertEqual(response['model_sha256'], MODEL_SHA)

    def test_unverified_model_is_rejected_before_output_is_created(self):
        with scratch_directory() as directory:
            output = Path(directory) / 'result.png'
            # Independent of the real model: a small stand-in file whose digest cannot match the (valid-format) expected one.
            stand_in = Path(directory) / 'model.onnx'
            stand_in.write_bytes(b'not a real model')
            payload = self.request(output)
            payload['model'] = str(stand_in)
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
