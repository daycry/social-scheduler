import datetime, hashlib, json, pathlib, subprocess, sys
here = pathlib.Path(__file__).resolve().parent
root = here.parents[3]
name, pattern = sys.argv[1:3]
target = here / (name + '.json')
if target.exists():
    raise RuntimeError('Evidence already exists; choose a new label')
command = ['node', '--max-old-space-size=512', '--experimental-test-isolation=none', '--test', '--test-name-pattern=' + pattern, 'tests/frontend/studio-chart-binding.test.mjs']
paths = ['resources/js/Studio/chart-binding.js', 'tests/frontend/studio-chart-binding.test.mjs', 'resources/js/Studio/dataset-table.js']
before = {p: hashlib.sha256((root/p).read_bytes()).hexdigest() for p in paths}
result = subprocess.run(command, cwd=root, capture_output=True, timeout=60)
(here / (name + '.stdout.txt')).write_bytes(result.stdout)
(here / (name + '.stderr.txt')).write_bytes(result.stderr)
record = {'date': datetime.datetime.now().isoformat(), 'command': command, 'exit_code': result.returncode, 'sha256_before': before,
          'sha256_after': {p: hashlib.sha256((root/p).read_bytes()).hexdigest() for p in paths}}
target.write_text(json.dumps(record, indent=2), encoding='utf-8')
print(result.stdout.decode('utf-8', errors='replace'))
print(result.stderr.decode('utf-8', errors='replace'))
print(json.dumps({'evidence': name, 'exit_code': result.returncode}))
sys.exit(result.returncode)
