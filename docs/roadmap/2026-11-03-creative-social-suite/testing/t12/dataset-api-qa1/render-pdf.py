"""Report-only PDF renderer, separate bounded job, no app server."""
import hashlib, importlib.util, json, pathlib, subprocess, sys
ROOT=pathlib.Path(__file__).resolve().parents[5]
OUT=pathlib.Path(__file__).resolve().parent
spec=importlib.util.spec_from_file_location('t12_pdf_job',ROOT/'scripts/studio/av-runtime.py')
runtime=importlib.util.module_from_spec(spec);spec.loader.exec_module(runtime)
job=runtime.enforce_memory();before=runtime.observed_memory(job)
if before['limit']!=3*1024**3 or not before['kill_on_close'] or before['breakaway']:raise RuntimeError('Expected3GiB COMMIT private job')
subprocess.run(['php','-d','memory_limit=128M',str(OUT/'render-report.php')],cwd=ROOT,check=True,timeout=30)
subprocess.run(['node',str(OUT/'render-report.mjs')],cwd=ROOT,check=True,timeout=90)
sys.path.insert(0,str(ROOT/'storage/framework/testing/t43-pdf-tools'))
import pymupdf
pdf=pymupdf.open(OUT/'report.pdf')
pages=OUT/'pdf-pages';pages.mkdir(exist_ok=True)
info=[]
for index,page in enumerate(pdf):
 page.get_pixmap(matrix=pymupdf.Matrix(1.2,1.2)).save(pages/f'page-{index+1:02}.png')
 info.append({'page':index+1,'chars':len(page.get_text()),'width':page.rect.width,'height':page.rect.height})
(OUT/'pdf-validation.json').write_text(json.dumps({'pages':len(pdf),'page_info':info,'rendered_from_pdf':True,'visually_inspected':False,'job_memory_after':runtime.observed_memory(job),'pdf_sha256':hashlib.sha256((OUT/'report.pdf').read_bytes()).hexdigest()},indent=2),encoding='utf-8')
print(json.dumps({'pages':len(pdf),'rendered_from_pdf':True,'job_memory_after':runtime.observed_memory(job)}))
