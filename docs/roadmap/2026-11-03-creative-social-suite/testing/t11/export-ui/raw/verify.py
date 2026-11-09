from pathlib import Path
import hashlib
import json
import os
import subprocess
import sys
import uuid

root = Path.cwd()
out = root / 'storage/framework/testing/t11-export-ui-final'
out.mkdir(parents=True, exist_ok=True)
mode = sys.argv[1]
freeze = root / 'storage/framework/testing/t11-export-ui-review/attempt2/manifest.json'
sources = json.loads(freeze.read_text())
build = {p.relative_to(root).as_posix(): hashlib.sha256(p.read_bytes()).hexdigest()
         for p in (root / 'public/build').rglob('*') if p.is_file()}
guard = out / 'guard.json'
if not guard.exists():
    guard.write_text(json.dumps({'sources': sources, 'generated': build}, indent=2))
expected = json.loads(guard.read_text())
assert expected['sources'] == sources
assert expected['generated'] == build

def verify():
    for name, sha in {**expected['sources'], **expected['generated']}.items():
        assert hashlib.sha256((root / name).read_bytes()).hexdigest() == sha, name

verify()
env = os.environ.copy()
if mode == 'frontend':
    files = sorted(p.as_posix() for p in Path('tests/frontend').glob('*.test.mjs'))
    command = ['node', '--test', '--experimental-test-isolation=none', *files]
elif mode == 'e2e':
    names = ['studio', 'studio-automation', 'studio-timeline', 'studio-audio-editor', 'studio-frame-export', 'studio-native-export']
    command = ['node', 'node_modules/@playwright/test/cli.js', 'test', *['tests/e2e/' + name + '.spec.ts' for name in names]]
    env['E2E_GENERAL_RUN_ROOT'] = str(root / 'storage/framework/testing/t43' / ('run-general-' + str(uuid.uuid4())))
else:
    raise SystemExit('frontend or e2e')
with (out / (mode + '.stdout')).open('wb') as stdout, (out / (mode + '.stderr')).open('wb') as stderr:
    child = subprocess.Popen(command, cwd=root, env=env, stdout=stdout, stderr=stderr)
    receipt = {'pid': child.pid, 'command': command, 'run_root': env.get('E2E_GENERAL_RUN_ROOT') if mode == 'e2e' else None}
    (out / (mode + '-process.json')).write_text(json.dumps(receipt, indent=2))
    print(json.dumps(receipt), flush=True)
    code = child.wait()
verify()
result = {'exit': code, 'terminal': True, 'source_hashes_match': True, 'sources': len(sources), 'generated': len(build)}
if mode == 'frontend':
    result['summary'] = [line for line in (out / 'frontend.stdout').read_text(encoding='utf-8').splitlines()
                         if line.startswith(('# tests ', '# pass ', '# fail ', '# cancelled ', '# skipped ', '# duration_ms'))]
else:
    results = json.loads((Path(env['E2E_GENERAL_RUN_ROOT']) / 'results.json').read_text(encoding='utf-8'))
    result['stats'] = results['stats']
    result['runner_errors'] = results.get('errors', [])
    result['results_path'] = str(Path(env['E2E_GENERAL_RUN_ROOT']) / 'results.json')
(out / (mode + '-result.json')).write_text(json.dumps(result, indent=2))
print(json.dumps(result), flush=True)
raise SystemExit(code)
