import hashlib, json, re, subprocess
from pathlib import Path
root=Path.cwd(); here=root/'storage/framework/testing/t12-dataset-editor-ui'
files=json.loads((here/'baseline.json').read_text(encoding='utf-8'))
dep=['resources/js/Studio/dataset-table.js','tests/frontend/studio-dataset-table.test.mjs']
def hashes(paths): return {str(p):hashlib.sha256((root/p).read_bytes()).hexdigest() for p in paths}
before=hashes([*files,*dep]); command=['node','--max-old-space-size=512','--experimental-test-isolation=none','--test','tests/frontend/studio-dataset-client.test.mjs','tests/frontend/studio-datasets-dialog.test.mjs','tests/frontend/studio-captions-panel.test.mjs','tests/frontend/studio-export-job-panel.test.mjs']
result=subprocess.run(command,stdout=subprocess.PIPE,stderr=subprocess.STDOUT,timeout=120)
(here/'final-verified.log').write_bytes(result.stdout)
after=hashes([*files,*dep]); changed=[path for path in before if before[path]!=after[path]]
output=result.stdout.decode('utf-8'); counts={key:int(re.search(r'^# '+key+r' (\d+)$',output,re.M).group(1)) for key in ['tests','pass','fail','cancelled','skipped','todo']}
summary={'command':command,'exit_code':result.returncode,'counts':counts,'changed_sources':changed,'before':before,'after':after,'scope':'Node pure UI/client plus captions/export panel neighbors. No native/browser/build/DB/server. Core dependency observed, not approved by this run.'}
(here/'verification.json').write_text(json.dumps(summary,ensure_ascii=False,indent=2)+'\n',encoding='utf-8')
print(json.dumps({k:summary[k] for k in ['exit_code','counts','changed_sources']}))
raise SystemExit(0 if result.returncode==0 and not changed and counts['fail']==counts['cancelled']==counts['skipped']==0 else 1)
