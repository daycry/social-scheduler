import argparse,hashlib,importlib.util,json,os,pathlib,shutil,subprocess,threading,time
HERE=pathlib.Path(__file__).resolve().parent
ROOT=HERE.parents[3]
def main():
    p=argparse.ArgumentParser();p.add_argument('--execute',required=True);args=p.parse_args()
    if args.execute!='fuentes UI finales; compila una vez':raise RuntimeError('Explicit root execution signal required')
    record=HERE/'result.json'
    if record.exists():raise RuntimeError('Build already recorded; no automatic retry')
    paths=['resources/js/Studio/dataset-table.js','resources/js/Studio/dataset-client.js','resources/js/Components/Studio/StudioDatasetsDialog.vue','resources/js/Pages/Studio/Index.vue','resources/js/Pages/Studio/Editor.vue','vite.config.js','package.json','package-lock.json','scripts/studio/build.mjs','scripts/studio/av-runtime.py']
    before={s:hashlib.sha256((ROOT/s).read_bytes()).hexdigest() for s in paths}
    spec=importlib.util.spec_from_file_location('bounded_build',ROOT/'scripts/studio/av-runtime.py');runtime=importlib.util.module_from_spec(spec);spec.loader.exec_module(runtime)
    job=runtime.enforce_memory();initial=runtime.observed_memory(job)
    if os.name!='nt' or initial['limit']!=3*1024**3 or not initial['kill_on_close'] or initial['breakaway']:raise RuntimeError('Windows3GiB COMMIT guard required')
    env={k:v for k,v in os.environ.items() if k.lower() in ['path','systemroot','windir','temp','tmp','comspec','pathext']}
    env.update({'APP_ENV':'e2e','APP_URL':'http://localhost:8080','NODE_ENV':'production','CI':'true'})
    record.write_text(json.dumps({'state':'started','pid':os.getpid(),'sources_before':before}),encoding='utf-8')
    timer=threading.Timer(330,lambda:os._exit(124));timer.daemon=True;timer.start();started=time.time();code=1
    try:
        with (HERE/'build.log').open('w',encoding='utf-8') as log:
            code=subprocess.run([shutil.which('node'),'--max-old-space-size=1536',str(HERE/'build.mjs')],cwd=ROOT,env=env,stdout=log,stderr=subprocess.STDOUT,timeout=300).returncode
    finally:
        after={s:hashlib.sha256((ROOT/s).read_bytes()).hexdigest() for s in paths};changed=[s for s in paths if before[s]!=after[s]]
        generated={s.relative_to(ROOT).as_posix():hashlib.sha256(s.read_bytes()).hexdigest() for s in (ROOT/'public/build').rglob('*') if s.is_file()}
        out={'state':'finished','exit_code':code,'seconds':time.time()-started,'sources_before':before,'sources_after':after,'changed_sources':changed,'generated':generated,'memory_after':runtime.observed_memory(job),'metric':'Windows Job aggregate COMMIT; not RSS','env_source':'whitelisted process environment plus empty-env directory for Vite and laravel plugin; no real .env'}
        record.write_text(json.dumps(out,indent=2),encoding='utf-8');timer.cancel()
    print(json.dumps({'exit_code':code,'changed_sources':changed,'generated_count':len(generated),'memory_after':out['memory_after']}))
    if changed:raise RuntimeError('Source drift during build')
    raise SystemExit(code)
if __name__=='__main__':main()
