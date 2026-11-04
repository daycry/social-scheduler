"""Copy only public test outputs. Never copy SQLite, auth state, headers or trace bodies."""
import hashlib,json,pathlib,shutil,sys,zipfile
root=pathlib.Path(__file__).resolve().parent
run=pathlib.Path((root/'raw/run-root.txt').read_text(encoding='utf-8-sig').strip())
destination=root/('raw/'+sys.argv[1] if len(sys.argv)>1 else 'raw/final')
destination.mkdir(parents=True,exist_ok=True)
shutil.copy2(run/'results.json',destination/'results.json')
failures=[]
for trace in (run/'artifacts').glob('*/trace.zip'):
    with zipfile.ZipFile(trace) as archive:
        for name in archive.namelist():
            if not name.endswith('.network'): continue
            for line in archive.read(name).decode().splitlines():
                snapshot=json.loads(line).get('snapshot',{})
                response=snapshot.get('response',{})
                if response.get('_failureText'):
                    failures.append({'test':trace.parent.name,'url':snapshot.get('request',{}).get('url','').split('?')[0],'failure':response['_failureText'],'status':response.get('status')})
(destination/'network-failures.json').write_text(json.dumps(failures,indent=2),encoding='utf-8')
manifest=[]
for source in (run/'artifacts').glob('*/*'):
    if source.name=='trace.zip' or source.suffix not in ['.png','.jpeg','.webp','.svg','.pdf','.json','.md']:continue
    target=destination/'artifacts'/source.parent.name/source.name
    target.parent.mkdir(parents=True,exist_ok=True)
    shutil.copy2(source,target)
    manifest.append({'path':str(target.relative_to(root)).replace('\\','/'),'sha256':hashlib.sha256(target.read_bytes()).hexdigest(),'bytes':target.stat().st_size})
(destination/'sha256.json').write_text(json.dumps(manifest,indent=2),encoding='utf-8')
print(destination)
