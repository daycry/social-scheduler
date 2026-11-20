from pathlib import Path
from concurrent.futures import ThreadPoolExecutor
import os,subprocess,time,json

root=Path(os.environ['LARAVEL_STORAGE_PATH'])
script=Path(os.environ['TEMP'])/'metricool-t80-sqlite-race.php'
def php(*args):
    result=subprocess.run(['php',str(script),*map(str,args)],capture_output=True,encoding='utf-8',timeout=35)
    if result.returncode:raise RuntimeError(result.stdout+result.stderr)
    return json.loads(result.stdout)
print(json.dumps(php('migrate')),flush=True)
rows=[]
for index in range(5):
    delivery=php('seed')['delivery']
    receipt=root/f'{index}-requests.ndjson'
    gate=root/f'{index}-go'
    ready=[root/f'{index}-{worker}-ready' for worker in range(2)]
    with ThreadPoolExecutor(max_workers=2) as pool:
        futures=[pool.submit(php,'worker',delivery,receipt,marker,gate) for marker in ready]
        deadline=time.monotonic()+15
        while not all(marker.exists() for marker in ready):
            if time.monotonic()>deadline:raise RuntimeError('Workers did not reach barrier.')
            time.sleep(.01)
        gate.write_text('go',encoding='utf-8')
        workers=[future.result() for future in futures]
    # Reintento equivalente al de la cola para cualquier error SQLite recuperable.
    php('worker',delivery,receipt,'-','-')
    count=len(receipt.read_text(encoding='utf-8').splitlines()) if receipt.exists() else 0
    row={'round':index+1,'workers':workers,'simulated_http_requests':count,**php('verify',delivery)}
    rows.append(row)
    print(json.dumps(row),flush=True)
assert all(row['simulated_http_requests']==1 and row['attempts']==1 and row['status']=='delivered' for row in rows),rows
print(json.dumps({'rounds':5,'processes_per_round':2,'one_request_and_committed_attempt_per_round':True}),flush=True)
