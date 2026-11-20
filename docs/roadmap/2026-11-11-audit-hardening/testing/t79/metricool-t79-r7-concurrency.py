from pathlib import Path
from concurrent.futures import ThreadPoolExecutor
import subprocess, tempfile, os, time, json

script = Path(os.environ['TEMP']) / 'metricool-t79-r7-concurrency.php'
def php(*args):
    result = subprocess.run(['php', str(script), *map(str,args)], capture_output=True, encoding='utf-8', timeout=35)
    if result.returncode:
        raise RuntimeError(result.stdout + result.stderr)
    return json.loads(result.stdout)
print(json.dumps(php('migrations')), flush=True)
directory = Path(tempfile.mkdtemp(prefix='metricool-t79-concurrency-'))
results = []
for iteration in range(5):
    ids = php('seed')
    gate = directory / f'{iteration}-go'
    ready = [directory / f'{iteration}-{worker}-ready' for worker in range(2)]
    with ThreadPoolExecutor(max_workers=2) as executor:
        futures = [executor.submit(php, 'worker', ids['user'], ids['goal'], marker, gate) for marker in ready]
        deadline = time.monotonic() + 15
        while not all(marker.exists() for marker in ready):
            if time.monotonic() > deadline:
                raise RuntimeError('Workers did not reach barrier.')
            time.sleep(.01)
        gate.write_text('go', encoding='utf-8')
        workers = [future.result() for future in futures]
    assert sum(worker['created'] for worker in workers) == 1, workers
    result = {'round':iteration + 1,'workers':workers,**php('verify',ids['user'],ids['goal'])}
    results.append(result)
    print(json.dumps(result), flush=True)
print(json.dumps({'rounds':len(results),'concurrent_processes_per_round':2,'all_single_event_and_notification':True}),flush=True)
