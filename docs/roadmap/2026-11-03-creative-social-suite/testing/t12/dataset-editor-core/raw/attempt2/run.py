import subprocess,sys,json,time
from pathlib import Path
base=Path(__file__).resolve().parent
name=sys.argv[1]
pattern=sys.argv[2] if len(sys.argv)>2 else None
cmd=['node','--max-old-space-size=512','--experimental-test-isolation=none','--test']
if pattern: cmd+=['--test-name-pattern',pattern]
cmd+=['tests/frontend/studio-dataset-table.test.mjs']
start=time.monotonic(); p=subprocess.run(cmd,capture_output=True)
(base/(name+'.stdout.txt')).write_bytes(p.stdout)
(base/(name+'.stderr.txt')).write_bytes(p.stderr)
(base/(name+'.json')).write_text(json.dumps({'command':cmd,'exit_code':p.returncode,'duration_seconds':time.monotonic()-start},indent=2)+'\n',encoding='utf-8')
print(p.stdout.decode('utf-8',errors='replace'));print(p.stderr.decode('utf-8',errors='replace'));sys.exit(p.returncode)
