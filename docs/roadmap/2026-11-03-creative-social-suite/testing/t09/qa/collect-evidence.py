"""Collect only disposable test outputs; never collect auth state, SQLite or trace bodies."""
import hashlib
import json
import pathlib
import shutil
import struct

root = pathlib.Path(__file__).resolve().parent
run = pathlib.Path((root / 'raw/run-root.txt').read_text(encoding='utf-8-sig').strip())
destination = root / 'raw/artifacts'
shutil.copy2(run / 'results.json', root / 'raw/results.json')
allowed = {
    'studio-export.png', 'studio-export.jpeg', 'studio-export.webp', 'studio-export.svg', 'studio-pages.pdf',
    'template-instance.png', 'portrait-variant.png', 'studio-editor.png', 'metrics-quality.png',
    'template-instance-editor.png', 'portrait-variant-editor.png', 'test-failed-1.png', 'error-context.md',
}
manifest = []
for source in sorted((run / 'artifacts').glob('*/*')):
    if source.name not in allowed or not source.is_file():
        continue
    target = destination / source.parent.name / source.name
    target.parent.mkdir(parents=True, exist_ok=True)
    shutil.copy2(source, target)
    data = target.read_bytes()
    record = {'path': target.relative_to(root).as_posix(), 'bytes': len(data), 'sha256': hashlib.sha256(data).hexdigest()}
    if data.startswith(b'\x89PNG\r\n\x1a\n'):
        record['dimensions'] = list(struct.unpack('>II', data[16:24]))
    if source.name in {'studio-editor.png', 'metrics-quality.png', 'template-instance-editor.png', 'portrait-variant-editor.png'}:
        project = 'mobile-360' if source.parent.name.endswith('mobile-360') else 'mobile' if source.parent.name.endswith('mobile') else 'desktop'
        short = source.stem + '-' + project + '.png'
        shutil.copy2(source, root / 'screenshots' / short)
    manifest.append(record)
(root / 'raw/artifact-manifest.json').write_text(json.dumps(manifest, ensure_ascii=False, indent=2) + '\n', encoding='utf-8')
print(json.dumps({'run': str(run), 'copied_artifacts': len(manifest), 'screenshots': len(list((root / 'screenshots').glob('*.png')))}, ensure_ascii=False))
