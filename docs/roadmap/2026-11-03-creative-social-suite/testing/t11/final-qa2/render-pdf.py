"""Run only through QA2's memory-managed observer wrapper."""
import json,pathlib,subprocess,sys
from PIL import Image,ImageOps,ImageDraw
ROOT=pathlib.Path('C:/laragon/www/metricool')
OUT=pathlib.Path(__file__).resolve().parent
marker='C:/Users/daycr/.codex/plugins/cache/openai-primary-runtime/pdf/26.1030.10813/skills/pdf/container_tools/mark_artifact_operation_started.mjs'
subprocess.run(['node',marker,'--operation-kind','create','--expected-output-count','1','--output-format','pdf'],cwd=ROOT,check=True)
subprocess.run(['php',str(OUT/'render-report.php')],cwd=ROOT,check=True)
subprocess.run(['node',str(OUT/'render-report.mjs')],cwd=ROOT,check=True)
sys.path.insert(0,str(ROOT/'storage/framework/testing/t43-pdf-tools'))
import pymupdf
pdf=pymupdf.open(OUT/'report.pdf')
pages=OUT/'raw/pdf-pages';pages.mkdir(exist_ok=True)
info=[]
for index,page in enumerate(pdf):
    page.get_pixmap(matrix=pymupdf.Matrix(1.1,1.1)).save(pages/f'page-{index+1:02}.png')
    info.append({'page':index+1,'chars':len(page.get_text()),'width':page.rect.width,'height':page.rect.height})
files=sorted(pages.glob('page-*.png'))
sheet=Image.new('RGB',(840,320*((len(files)+2)//3)),'#d0d0d0')
draw=ImageDraw.Draw(sheet)
for index,file in enumerate(files):
    sheet.paste(ImageOps.contain(Image.open(file),(270,295)),(index%3*280,index//3*320+20))
    draw.text((index%3*280+5,index//3*320+3),file.name,fill='black')
sheet.save(OUT/'raw/pdf-contact.png')
(OUT/'raw/pdf-validation.json').write_text(json.dumps({'pages':len(pdf),'page_info':info,
    'rendered_from_pdf':True,'visually_inspected':False},indent=2),encoding='utf-8')
print(json.dumps({'PDF_pages':len(pdf),'rasterized':True}))
