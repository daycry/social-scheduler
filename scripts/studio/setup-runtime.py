"""Explicit installation of Studio's private Python environment and pinned model."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import urllib.request
import venv

ROOT = Path(__file__).resolve().parents[2]
SPEC_PATH = ROOT / 'scripts/studio/background-model.json'
ENVIRONMENT = ROOT / 'storage/framework/studio-python'
MODEL = ROOT / 'storage/app/private/studio-models/isnet-general-use.onnx'
LICENSE = MODEL.parent / 'IS-Net-LICENSE.txt'
# Weights of the model used before the switch to IS-Net; they are never read again and can be deleted (see docs/modulos/despliegue.md).


def verified(path, spec):
    if path.is_symlink() or not path.is_file() or path.stat().st_size != spec['bytes']:
        return False
    with path.open('rb') as stream:
        return hashlib.file_digest(stream, 'sha256').hexdigest() == spec['sha256']


def download_verified(destination, spec):
    destination = Path(destination)
    if destination.is_symlink():
        raise ValueError('Refusing a symlink destination')
    if verified(destination, spec):
        return
    destination.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
    temporary = None
    try:
        with tempfile.NamedTemporaryFile(dir=destination.parent, prefix='.studio-', suffix='.part', delete=False) as output:
            temporary = Path(output.name)
            request = urllib.request.Request(spec['url'], headers={'User-Agent': 'SocialScheduler-Studio-Setup/1'})
            with urllib.request.urlopen(request, timeout=60) as response:
                total = 0
                digest = hashlib.sha256()
                while chunk := response.read(1024 * 1024):
                    total += len(chunk)
                    if total > spec['bytes']:
                        raise ValueError('Model download exceeds pinned size')
                    digest.update(chunk)
                    output.write(chunk)
            if total != spec['bytes'] or digest.hexdigest() != spec['sha256']:
                raise ValueError('Model checksum or size does not match pinned artifact')
            output.flush()
            os.fsync(output.fileno())
        os.replace(temporary, destination)
    finally:
        if temporary is not None and temporary.exists():
            temporary.unlink()


def install_model(destination, spec):
    destination = Path(destination)
    download_verified(destination, spec)
    metadata = destination.with_suffix('.json')
    if metadata.is_symlink():
        raise ValueError('Refusing a symlink metadata destination')
    metadata.write_text(json.dumps(spec, indent=2) + '\n', encoding='utf-8')


def runtime_python():
    return ENVIRONMENT / ('Scripts/python.exe' if os.name == 'nt' else 'bin/python')


def check_runtime():
    python = runtime_python()
    if not python.is_file():
        return False
    script = "import importlib.metadata as m,json,sys; lines=open(sys.argv[1]).read().splitlines(); pairs=[s.split('==') for s in lines if s and not s.startswith('#')]; versions={n:m.version(n) for n,v in pairs}; print(json.dumps(versions)); sys.exit(any(versions[n]!=v for n,v in pairs))"
    result = subprocess.run([str(python), '-c', script, str(ROOT / 'requirements-studio.txt')], capture_output=True, text=True, timeout=30)
    if result.returncode == 0:
        print(result.stdout.strip())
    return result.returncode == 0


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--check', action='store_true', help='Read-only verification of pinned Python packages and model')
    parser.add_argument('--download-model', action='store_true', help='Explicitly download the pinned Apache-2.0 model and license')
    args = parser.parse_args()
    if args.check and args.download_model:
        parser.error('--check cannot download anything')
    spec = json.loads(SPEC_PATH.read_text(encoding='utf-8'))
    if args.check:
        runtime_ok = check_runtime()
        model_ok = verified(MODEL, spec) and verified(LICENSE, spec['license_file'])
        if not model_ok:
            print('The background model %s is missing or does not match the pinned checksum: run `setup-runtime.py --download-model`.' % MODEL.name, file=sys.stderr)
        print(json.dumps({'runtime_ready': runtime_ok, 'background_model_ready': model_ok}))
        return 0 if runtime_ok and model_ok else 1
    if sys.version_info < (3, 12):
        parser.error('Python 3.12 or later is required')
    if not runtime_python().is_file():
        venv.EnvBuilder(with_pip=True).create(ENVIRONMENT)
    subprocess.run([str(runtime_python()), '-m', 'pip', 'install', '--disable-pip-version-check', '-r', str(ROOT / 'requirements-studio.txt')], check=True)
    if args.download_model:
        download_verified(LICENSE, spec['license_file'])
        install_model(MODEL, spec)
    print(json.dumps({'runtime_ready': check_runtime(), 'background_model_ready': verified(MODEL, spec)}))
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
