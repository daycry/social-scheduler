from pathlib import Path
from concurrent.futures import ThreadPoolExecutor
import os,subprocess,time,json
root=Path(os.environ['LARAVEL_STORAGE_PATH']);script=Path(os.environ['TEMP'])/'metricool-t81-r3-mysql-race.php'
def php(*args):
 r=subprocess.run(['php',str(script),*map(str,args)],capture_output=True,encoding='utf-8',timeout=35)
 if r.returncode:raise RuntimeError(r.stdout+r.stderr)
 return json.loads(r.stdout)
rows=[]
for index in range(5):
 users=php('seed')['users'];ready=[root/f'{index}-{u}-ready' for u in users];gate=root/f'{index}-gate'
 with ThreadPoolExecutor(max_workers=2)as pool:
  futures=[pool.submit(php,'worker',u,marker,gate)for u,marker in zip(users,ready)]
  end=time.monotonic()+20
  while not all(p.exists()for p in ready):
   if time.monotonic()>end:raise RuntimeError('Workers did not reach row-gap barrier.')
   time.sleep(.01)
  gate.write_text('go',encoding='utf-8');workers=[f.result()for f in futures]
 row={'round':index+1,'workers':workers,**php('verify')};rows.append(row);print(json.dumps(row),flush=True)
assert all(r['devices']==2 and r['owners']==2 and all(w['status']=='created'for w in r['workers'])for r in rows),rows
print(json.dumps({'rounds':5,'processes':2,'all_distinct_owner_subscriptions_created':True}),flush=True)