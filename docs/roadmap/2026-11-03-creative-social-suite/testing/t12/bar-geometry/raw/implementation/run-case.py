import datetime, hashlib, json, pathlib, subprocess, sys
ROOT=pathlib.Path(__file__).resolve().parents[4]
HERE=pathlib.Path(__file__).resolve().parent
FILES=['resources/js/Studio/bar-geometry.js','tests/frontend/studio-bar-geometry.test.mjs','resources/js/Studio/chart-binding.js','tests/frontend/studio-chart-binding.test.mjs','resources/js/Studio/dataset-table.js','tests/frontend/studio-dataset-table.test.mjs']
name,pattern=sys.argv[1:3]
cmd=['node','--max-old-space-size=512','--experimental-test-isolation=none','--test']
cmd += ['--test-name-pattern='+pattern] if pattern!='ALL' else []
cmd += ['tests/frontend/studio-bar-geometry.test.mjs']
if pattern=='ALL':cmd += ['tests/frontend/studio-chart-binding.test.mjs','tests/frontend/studio-dataset-table.test.mjs']
hashes=lambda:{p:hashlib.sha256((ROOT/p).read_bytes()).hexdigest() for p in FILES if (ROOT/p).is_file()}
before=hashes();p=subprocess.run(cmd,cwd=ROOT,capture_output=True,text=True,encoding='utf-8',timeout=60)
(HERE/(name+'.stdout.txt')).write_text(p.stdout,encoding='utf-8');(HERE/(name+'.stderr.txt')).write_text(p.stderr,encoding='utf-8')
record={'date':datetime.datetime.now(datetime.UTC).isoformat(),'command':cmd,'exit_code':p.returncode,'before':before,'after':hashes()}
(HERE/(name+'.json')).write_text(json.dumps(record,indent=2),encoding='utf-8')
print(json.dumps({'name':name,'exit':p.returncode,'tail':p.stdout.splitlines()[-9:]}))
