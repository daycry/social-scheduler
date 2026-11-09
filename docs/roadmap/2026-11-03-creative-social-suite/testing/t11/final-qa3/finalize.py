"""Final read-only source/history checks after codecs/PDF, under QA3 Job cap."""
import datetime,hashlib,importlib.util,json,pathlib
ROOT=pathlib.Path('C:/laragon/www/metricool')
OUT=pathlib.Path(__file__).resolve().parent;RAW=OUT/'raw'
def read(path):return json.loads(path.read_text(encoding='utf-8'))
spec=importlib.util.spec_from_file_location('qa3_helper_snapshot',ROOT/'storage/framework/testing/t11-independent-qa3.py')
helper=importlib.util.module_from_spec(spec);spec.loader.exec_module(helper)
before=read(RAW/'sources-before.json'); final=helper.snapshot()
(RAW/'sources-after-observers.json').write_text(json.dumps(final,indent=2),encoding='utf-8')
changes=[p for p in sorted(before.keys()|final.keys()) if before.get(p)!=final.get(p)]
union=read(RAW/'reviewed-union-manifest.json'); descriptors=read(RAW/'reviewed-descriptors.json')
groups={'sources39':{p:v['sha256_after'] for p,v in union['files'].items()},'generated76':union['generated'],
        'descriptors7':{p:v['sha256'] for p,v in descriptors.items()}}
final_review={k:{'count':len(v),'mismatches':[p for p,h in v.items() if not (ROOT/p).is_file()
    or hashlib.sha256((ROOT/p).read_bytes()).hexdigest()!=h]} for k,v in groups.items()}
history={}
for name in ['final-qa','final-qa2']:
    folder=OUT.parent/name; old=read(folder/'manifest.json')
    history[name]={'artifact_count':len(old['artifacts']),'changed_or_missing':[e['path'] for e in old['artifacts']
        if not (folder/e['path']).is_file() or hashlib.sha256((folder/e['path']).read_bytes()).hexdigest()!=e['sha256']]}
(RAW/'final-integrity-check.json').write_text(json.dumps({'source_count':len(before),'changed_sources':changes,
    'reviewed_after_observers':final_review,'historical_attempts':history},indent=2),encoding='utf-8')
validation=read(RAW/'pdf-validation.json')
validation.update(visually_inspected=True,contact_sheet='raw/pdf-contact.png',detail_pages_inspected=[1,2,3,8],
    findings='All9 PDF pages inspected via contact sheet and selected full-size pages. Text/tables/images readable without clipping. Actual DEFAULT/CONTROL preview and labelled historical QA2 image clear. No new visual gaps in tested local profile.')
(RAW/'pdf-validation.json').write_text(json.dumps(validation,ensure_ascii=False,indent=2),encoding='utf-8')
artifacts=[{'path':f.relative_to(OUT).as_posix(),'sha256':hashlib.sha256(f.read_bytes()).hexdigest(),'bytes':f.stat().st_size}
    for f in sorted(OUT.rglob('*')) if f.is_file() and f.name!='manifest.json']
manifest={'scope':'QA3 independent local T07/T11; automated72PASS; private42PASS; contrast24PASS; visual accepted; root decides task closure, no global initiative closure',
    'captured_utc':datetime.datetime.now(datetime.timezone.utc).isoformat(),'source_count':len(before),'changed_sources':changes,
    'sources':[{'path':p,'sha256_before':h,'sha256_after':final.get(p)} for p,h in before.items()],
    'reviewed_after_observers':final_review,'historical_attempts_immutable':history,'artifacts':artifacts,
    'qa_helper':{'path':'storage/framework/testing/t11-independent-qa3.py','sha256':hashlib.sha256((ROOT/'storage/framework/testing/t11-independent-qa3.py').read_bytes()).hexdigest()},
    'results':'raw/results.json','gate':'raw/qa-gate.txt','acceptance':'raw/acceptance-summary.json',
    'source_integrity':'raw/final-integrity-check.json','report':'report.md','pdf':'report.pdf',
    'memory':'raw/execution.json','observer_memory':'raw/observer-memory-collect-evidence.json','pdf_memory':'raw/observer-memory-render-pdf.json'}
(OUT/'manifest.json').write_text(json.dumps(manifest,ensure_ascii=False,indent=2),encoding='utf-8')
print(json.dumps({'source_count':len(before),'changed_sources':changes,'reviewed_final':final_review,
                  'history':history,'artifacts':len(artifacts),'PDF_pages':validation['pages']}))
if changes or any(v['mismatches'] for v in final_review.values()) or any(v['changed_or_missing'] for v in history.values()):
    raise SystemExit(1)
