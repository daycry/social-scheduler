"""Bounded private ONNX image worker. Never downloads a model or executes remote code."""
import hashlib
import importlib.util
import json
import os
import re
import sys
import warnings
from pathlib import Path


class ProcessingFailure(Exception):
    def __init__(self, code):
        self.code = code


# IS-Net (isnet-general-use) at its fixed 1024x1024 input needs about 1 GiB. Measured with
# the final script, a 16-megapixel JPEG (the largest accepted image): VmHWM 949 MiB / VmPeak 1264 MiB on Linux (the cap fails below
# ~1 GiB: 896 MiB was not enough, 1024 MiB passed) and 818 MiB peak working set / 797 MiB peak commit on Windows; a 512x512 photo
# peaks at 839 MiB (Linux) / 686 MiB (Windows). Activations, not a virtual reservation (the arena is off). 1.25 GiB leaves ~35 % over
# the Linux resident peak and still stops a runaway process.
MEMORY_LIMIT = 1280 * 1024**2


def process(payload):
    if not isinstance(payload, dict) or set(payload) - {'input', 'output', 'model', 'model_sha256', 'max_pixels', 'max_bytes'}:
        raise ProcessingFailure('invalid_request')
    if any(not isinstance(payload.get(key), str) or not payload[key] for key in ['input', 'output', 'model', 'model_sha256']):
        raise ProcessingFailure('invalid_request')
    source = Path(payload['input'])
    output = Path(payload['output'])
    model = Path(payload['model'])
    if output.exists() or output.is_symlink() or output.resolve() == source.resolve():
        raise ProcessingFailure('output_exists')
    if not output.parent.is_dir():
        raise ProcessingFailure('invalid_output')
    if not model.is_file():
        raise ProcessingFailure('model_unavailable')
    expected = payload['model_sha256']
    if re.fullmatch('[a-f0-9]{64}', expected) is None:
        raise ProcessingFailure('model_checksum')
    with model.open('rb') as stream:
        if hashlib.file_digest(stream, 'sha256').hexdigest() != expected:
            raise ProcessingFailure('model_checksum')
    max_pixels = payload.get('max_pixels', 16000000)
    max_bytes = payload.get('max_bytes', 30 * 1024 * 1024)
    if type(max_pixels) is not int or not 1 <= max_pixels <= 16000000 or type(max_bytes) is not int or not 1 <= max_bytes <= 30 * 1024 * 1024:
        raise ProcessingFailure('invalid_request')
    if not source.is_file() or not 0 < source.stat().st_size <= max_bytes:
        raise ProcessingFailure('invalid_image')

    import numpy as np
    import onnxruntime as ort
    from PIL import Image, ImageOps, UnidentifiedImageError

    Image.MAX_IMAGE_PIXELS = max_pixels
    warnings.simplefilter('error', Image.DecompressionBombWarning)
    try:
        with Image.open(source) as image:
            if image.format not in {'PNG', 'JPEG', 'WEBP'} or getattr(image, 'n_frames', 1) != 1 or image.width * image.height > max_pixels:
                raise ProcessingFailure('invalid_image')
            orientation = image.getexif().get(274, 1)
            orientation = orientation if type(orientation) is int and 1 <= orientation <= 8 else 1
            original = ImageOps.exif_transpose(image).convert('RGBA')
            original.load()
    except (UnidentifiedImageError, OSError, Image.DecompressionBombError, Image.DecompressionBombWarning):
        raise ProcessingFailure('invalid_image') from None

    options = ort.SessionOptions()
    options.enable_cpu_mem_arena = False
    options.enable_mem_pattern = False
    options.intra_op_num_threads = 4
    options.inter_op_num_threads = 1
    options.log_severity_level = 3
    session = ort.InferenceSession(str(model), sess_options=options, providers=['CPUExecutionProvider'])
    inputs = session.get_inputs()
    outputs = session.get_outputs()
    if len(inputs) != 1 or inputs[0].shape != [1, 3, 1024, 1024] or inputs[0].type != 'tensor(float)' or not outputs or outputs[0].name != 'output_image':
        raise ProcessingFailure('model_incompatible')
    # IS-Net (DIS) preprocessing: RGB in [0, 1] minus 0.5, std 1.0 (the reference DIS/IS-Net pipeline; rembg's `isnet-general-use`
    # session uses the same constants). Only the first output (the final saliency map, already a sigmoid in [0, 1]) is requested;
    # the other outputs are intermediate side maps and feature tensors that are not needed.
    image = original.convert('RGB').resize((1024, 1024), Image.Resampling.LANCZOS)
    pixels = np.asarray(image, dtype=np.float32) / 255.0 - 0.5
    tensor = np.transpose(pixels, (2, 0, 1))[None, ...]
    prediction = session.run([outputs[0].name], {inputs[0].name: tensor})[0]
    if prediction.shape != (1, 1, 1024, 1024) or not np.isfinite(prediction).all():
        raise ProcessingFailure('model_incompatible')
    mask = np.clip(prediction[0, 0], 0.0, 1.0)
    span = float(mask.max() - mask.min())
    if span > 1e-6:
        mask = (mask - mask.min()) / span
    alpha = Image.fromarray(np.rint(mask * 255).astype(np.uint8)).resize(original.size, Image.Resampling.LANCZOS)
    alpha = np.rint(np.asarray(alpha, dtype=np.float32) * np.asarray(original.getchannel('A'), dtype=np.float32) / 255).astype(np.uint8)
    original.putalpha(Image.fromarray(alpha))
    with output.open('xb') as stream:
        original.save(stream, format='PNG')
    if output.stat().st_size > 64 * 1024 * 1024:
        output.unlink()
        raise ProcessingFailure('output_too_large')
    return {'ok': True, 'width': original.width, 'height': original.height, 'format': 'png',
            'bytes': output.stat().st_size, 'model_sha256': expected, 'source_orientation': orientation,
            'alpha_min': int(alpha.min()), 'alpha_max': int(alpha.max())}


def main():
    try:
        # Local, shipped parser supplies the same OS-level limits already used for fonts.
        spec = importlib.util.spec_from_file_location('_studio_process_limits', Path(__file__).with_name('font_probe.py'))
        limits = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(limits)
        try:
            _memory_job = limits.limit_memory(MEMORY_LIMIT, 80, resident=True)
        except (OSError, RuntimeError, ValueError):
            raise ProcessingFailure('runtime_unavailable') from None
        # Bound BLAS pools before importing NumPy/ORT; the model uses four ORT threads.
        os.environ['OPENBLAS_NUM_THREADS'] = '1'
        os.environ['MKL_NUM_THREADS'] = '1'
        os.environ['OMP_NUM_THREADS'] = '4'
        request = sys.stdin.read(65537)
        if len(request.encode('utf-8')) > 65536:
            raise ProcessingFailure('invalid_request')
        response = process(json.loads(request))
    except ProcessingFailure as failure:
        response = {'ok': False, 'error_code': failure.code}
    except (ImportError, ModuleNotFoundError):
        response = {'ok': False, 'error_code': 'runtime_unavailable'}
    except Exception:
        response = {'ok': False, 'error_code': 'processing_failed'}
    print(json.dumps(response))
    return 0 if response['ok'] else 1


if __name__ == '__main__':
    sys.exit(main())
