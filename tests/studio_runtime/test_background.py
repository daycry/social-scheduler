import hashlib
import json
import random
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

    @unittest.skipUnless(MODEL.is_file(), 'pinned background model not installed (python scripts/studio/setup-runtime.py --download-model)')
    def test_largest_admitted_image_fits_the_memory_cap_with_margin(self):
        # Largest input the app admits (config/studio.php: max_pixels 16 000 000, max_bytes 30 MiB), generated here (never versioned).
        # A smooth random field compresses well enough to stay under max_bytes while decoding to a full 4000x4000 RGB raster.
        random.seed(7)
        coarse = Image.new('RGB', (64, 64))
        coarse.putdata([(random.randrange(256), random.randrange(256), random.randrange(256)) for _ in range(64 * 64)])
        big = coarse.resize((4000, 4000), Image.BICUBIC)
        with scratch_directory() as directory:
            source = Path(directory) / 'max.png'
            big.save(source, compress_level=1)
            self.assertLessEqual(source.stat().st_size, 30 * 1024 * 1024)
            output = Path(directory) / 'result.png'
            payload = self.request(output)
            payload['input'] = str(source)
            result, response = self.invoke(payload)
            self.assertEqual(result.returncode, 0, response)
            self.assertTrue(response['ok'], response)
            with Image.open(output) as image:
                self.assertEqual(image.size, (4000, 4000))
            if sys.platform.startswith('linux'):
                import resource
                peak_mib = resource.getrusage(resource.RUSAGE_CHILDREN).ru_maxrss / 1024  # KiB on Linux
                cap_mib = 1280
                print(f'\n[max-image] peak RSS {peak_mib:.0f} MiB of {cap_mib} MiB cap (margin {cap_mib - peak_mib:.0f} MiB)')
                # RLIMIT_DATA bounds VmData, not VmHWM, so the useful guarantee is a real margin below the cap on the runner.
                self.assertLess(peak_mib, cap_mib * 0.85, f'peak RSS {peak_mib:.0f} MiB leaves less than 15 % margin under the {cap_mib} MiB cap')

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
