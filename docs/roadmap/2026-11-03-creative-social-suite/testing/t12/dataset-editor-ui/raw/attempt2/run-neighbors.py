import hashlib,json,re,subprocess
from pathlib import Path
root=Path.cwd(); here=root/'storage/framework/testing/t12-dataset-editor-ui/attempt2'
reference=json.loads((here/'before-manifest.json').read_text(encoding='utf-8'))
owned={'resources/js/Components/Studio/StudioDatasetsDialog.vue','tests/frontend/studio-datasets-dialog.test.mjs'}
sha=lambda p:hashlib.sha256(p.read_bytes()).hexdigest()
hashes=lambda:{p:sha(root/p) for p in reference}
before=hashes()
assert not any(before[p]!=h for p,h in reference.items() if p not in owned)
command=['node','--max-old-space-size=512','--experimental-test-isolation=none','--test','tests/frontend/studio-dataset-client.test.mjs','tests/frontend/studio-datasets-dialog.test.mjs','tests/frontend/studio-captions-panel.test.mjs','tests/frontend/studio-export-job-panel.test.mjs']
r=subprocess.run(command,stdout=subprocess.PIPE,stderr=subprocess.STDOUT,timeout=120)
(here/'neighbors.log').write_bytes(r.stdout)
after=hashes(); changed=[p for p in before if before[p]!=after[p]]
text=r.stdout.decode('utf-8'); counts={k:int(re.search(r'^# '+k+r' (\d+)$',text,re.M).group(1)) for k in ['tests','pass','fail','cancelled','skipped','todo']}
summary={'command':command,'exit_code':r.returncode,'counts':counts,'before':before,'after':after,'changed_sources':changed,'other_reviewed_sources_unchanged':all(after[p]==h for p,h in reference.items() if p not in owned),'scope':'Node UI/client+captions/exportpanel neighbors; no build/browser/server/DB'}
(here/'verification.json').write_text(json.dumps(summary,ensure_ascii=False,indent=2)+'\n',encoding='utf-8')
print(json.dumps({k:summary[k] for k in ['exit_code','counts','changed_sources','other_reviewed_sources_unchanged']}))
raise SystemExit(0 if r.returncode==0 and not changed and summary['other_reviewed_sources_unchanged'] and counts['fail']==counts['cancelled']==counts['skipped']==0 else 1)
