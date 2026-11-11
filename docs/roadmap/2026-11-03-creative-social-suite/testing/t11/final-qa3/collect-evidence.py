"""Collect the single run's real results and independently decode its media."""
import hashlib, json, math, pathlib, shutil, struct, subprocess
ROOT = pathlib.Path('C:/laragon/www/metricool')
OUT = ROOT / 'docs/roadmap/2026-11-03-creative-social-suite/testing/t11/final-qa3'
RAW = OUT / 'raw'

def save(path, value):
    path.write_text(json.dumps(value, ensure_ascii=False, indent=2), encoding='utf-8')

def native(command, args):
    return subprocess.check_output([command, '-v','error',*args],timeout=25)

def spectrum(samples,channel,start,duration,hz):
    first = round(start*48000); count = round(duration*48000)
    values = [samples[(first+i)*2+channel] for i in range(count)]
    real = sum(value*math.cos(2*math.pi*hz*i/48000) for i,value in enumerate(values))
    imag = sum(value*math.sin(2*math.pi*hz*i/48000) for i,value in enumerate(values))
    return {'amplitude':2*math.hypot(real,imag)/count,'rms':math.sqrt(sum(x*x for x in values)/count)}

results=json.loads((RAW/'results.json').read_text(encoding='utf-8'))
tests=[]
def walk(suite):
    for spec in suite.get('specs',[]):
        for test in spec.get('tests',[]):
            tests.append({'file':spec.get('file'), 'title':spec['title'], 'project':test.get('projectName'), 'outcome':test.get('status'),
                'statuses':[r.get('status') for r in test.get('results',[])], 'retries':[r.get('retry',0) for r in test.get('results',[])],
                'duration_ms':sum(r.get('duration',0) for r in test.get('results',[])),
                'errors':[r.get('error') for r in test.get('results',[]) if r.get('error')]})
    for child in suite.get('suites',[]):walk(child)
for suite in results.get('suites',[]):walk(suite)
save(RAW/'test-index.json',tests)
save(RAW/'extra-checks.json',{'stats':results.get('stats'), 'actual_test_count':len(tests), 'runner_errors':results.get('errors',[]),
    'retried_results':sum(r>0 for t in tests for r in t['retries']), 'non_passed_results':[t for t in tests if t['statuses']!=['passed']],
    'files':sorted({t['file'] for t in tests}), 'projects':sorted({t['project'] for t in tests})})

media=[]
for measurement in sorted((RAW/'artifacts').rglob('measurements.json')):
    folder=measurement.parent
    # Only composite acceptance, not the smaller page-order fixture.
    if not (folder/'composite.mp4').exists():continue
    project=next(p for p in ['mobile-360','mobile','desktop'] if folder.name.endswith(p))
    dest=OUT/'samples'/project; dest.mkdir(parents=True,exist_ok=True)
    for name in ['measurements.json','composite.mp4','composite.gif','composite-ready.png']:
        shutil.copy2(folder/name,dest/name)
    mp4=dest/'composite.mp4'; gif=dest/'composite.gif'
    probes={kind:json.loads(native('ffprobe',['-count_frames','-show_streams','-show_format','-of','json',str(file)])) for kind,file in [('mp4',mp4),('gif',gif)]}
    pcm=native('ffmpeg',['-i',str(mp4),'-vn','-ac','2','-ar','48000','-f','f32le','pipe:1'])
    samples=struct.unpack('<'+'f'*(len(pcm)//4),pcm)
    measured={}
    for channel,name in enumerate(['left','right']):
        measured[name]={key:spectrum(samples,channel,*args) for key,args in {
            'video':(.75,.5,440), 'music':(.75,.5,880), 'attack':(.3,.1,880), 'release':(3,.1,880),
            'afterVideo':(2.1,.5,440), 'musicAlone':(2.1,.5,880), 'tail':(3.35,.1,880),'trimMarker':(.3,.1,1760)}.items()}
    measured['channelDifferenceRms']=math.sqrt(sum((samples[i*2]-samples[i*2+1])**2 for i in range(168000))/168000)
    for channel in ['left','right']:
        for band in ['video','music','musicAlone']:
            assert .04 <= measured[channel][band]['amplitude'] <= .06, (project,channel,band)
        for band in ['attack','release']:
            assert .015 <= measured[channel][band]['amplitude'] <= .025, (project,channel,band)
        assert measured[channel]['afterVideo']['amplitude'] < .002
        assert measured[channel]['trimMarker']['amplitude'] < .003
        assert measured[channel]['tail']['rms'] < .002
    assert measured['channelDifferenceRms'] < .001
    visual=next(s for s in probes['mp4']['streams'] if s['codec_type']=='video')
    audio=next(s for s in probes['mp4']['streams'] if s['codec_type']=='audio')
    assert (visual['codec_name'],visual['width'],visual['height'],int(visual['nb_read_frames']))==('h264',100,80,42)
    assert abs(float(visual['duration'])-3.5)<.001
    assert (audio['codec_name'],audio['sample_rate'],audio['channels'])==('aac','48000',2)
    assert len(probes['gif']['streams'])==1
    gv=probes['gif']['streams'][0]
    assert (gv['codec_name'],gv['width'],gv['height'],int(gv['nb_read_frames']))==('gif',50,40,42)
    assert abs(float(gv['duration'])-3.5)<.01
    save(dest/'independent-probes.json',probes); save(dest/'independent-audio.json',measured)
    for label,time in [('overlay',.75),('fade',1.75),('final',3.25)]:
        native('ffmpeg',['-y','-i',str(mp4),'-ss',str(time),'-frames:v','1',str(dest/f'{label}.png')])
    screenshot=OUT/'screenshots'/f'composite-{project}.png'; shutil.copy2(dest/'composite-ready.png',screenshot)
    media.append({'project':project,'directory':dest.relative_to(OUT).as_posix(), 'measurements':json.loads(measurement.read_text()), 'independent_audio':measured})
save(RAW/'composite-audit.json',media)
for folder in (RAW/'artifacts').iterdir():
    if folder.is_dir() and folder.name.startswith('studio-av-composite-') and (folder/'test-failed-1.png').exists():
        project=next(p for p in ['mobile-360','mobile','desktop'] if folder.name.endswith(p))
        shutil.copy2(folder/'test-failed-1.png',OUT/'screenshots'/f'composite-{project}.png')
print(json.dumps({'test_count':len(tests),'stats':results.get('stats'),'composite_viewports':[m['project'] for m in media]},indent=2))

# Caption oracle: decode downloaded bytes, XML/ZIP and independent rasterization.
import importlib.util, zipfile, base64, sys, re
from PIL import Image
spec=importlib.util.spec_from_file_location('qa3_caption_observer',OUT/'inspect-caption-media.py')
observer=importlib.util.module_from_spec(spec); spec.loader.exec_module(observer)
profile={'media':[],'svg_archives':[],'expected_font_sha256':[]}
caption_sources=[]; static_observations=[]
sys.path.insert(0,str(ROOT/'storage/framework/testing/t43-pdf-tools'))
import pymupdf
for measurement in sorted((RAW/'artifacts').rglob('captions-native-measurements.json')):
    folder=measurement.parent
    project=next(p for p in ['mobile-360','mobile','desktop'] if folder.name.endswith(p))
    dest=OUT/'samples/captions'/project; dest.mkdir(parents=True,exist_ok=True)
    for item in folder.iterdir():
        if item.is_file() and item.name.startswith(('captions-','caption-frame-','contrast-')):
            shutil.copy2(item,dest/item.name)
    receipt=json.loads(measurement.read_text(encoding='utf-8'))
    fonts={f['sha256'] for item in receipt for f in item.get('journal',{}).get('fonts',[])}
    profile['expected_font_sha256'].extend(sorted(fonts))
    for kind in ['mp4','gif']:
        file=dest/f'captions-{kind}.{kind}'
        profile['media'].append({'path':file.relative_to(OUT).as_posix(),
            'frames':[0,2,3,4,6,9,14,15,17], 'compare_frames':[[4,9],[4,14]],
            'expected_window':{'fps':12,'start':.25,'end':1.25,'text':'Hola','background':'black'}})
    selection_frames={'a':[0,53,54,55,58,59],
        'c-a':[0,8,9,10,14,15,113,114,115,117,118,119],
        'a-c':[0,50,52,53,54,55,56,59,60,104]}
    for order,frames in selection_frames.items():
        file=dest/f'captions-selection-{order}.mp4'
        profile['media'].append({'path':file.relative_to(OUT).as_posix(),'frames':frames,
            'expected_selection':order,'expected_source_cues':[{'text':'AA','start':1.8,'end':1.95},
                {'text':'CCCCCCCC','start':3.8,'end':4}]})
    profile['svg_archives'].append((dest/'captions-svg.zip').relative_to(OUT).as_posix())
    with zipfile.ZipFile(dest/'captions-png.zip') as archive:
        if archive.testzip() is not None:raise ValueError('PNG archive CRC failed')
        for index,name in enumerate(archive.namelist()):
            data=archive.read(name)
            target=dest/f'independent-static-{index}.png'; target.write_bytes(data)
            image=Image.open(target).convert('RGB')
            static_observations.append({'project':project,'format':'png','page':index,
                'width':image.width,'height':image.height,**observer.pixels(image.tobytes(),image.width,image.height)})
    with zipfile.ZipFile(dest/'captions-svg.zip') as archive:
        if archive.testzip() is not None:raise ValueError('SVG archive CRC failed')
        for index,name in enumerate(archive.namelist()):
            if not name.lower().endswith('.svg'):continue
            (dest/f'independent-vector-{index}.svg').write_bytes(archive.read(name))
    pdf=pymupdf.open(dest/'captions-pdf.pdf')
    for index,page in enumerate(pdf):
        pix=page.get_pixmap(matrix=pymupdf.Matrix(96/72,96/72),alpha=False)
        pix.save(dest/f'independent-pdf-{index}.png')
        static_observations.append({'project':project,'format':'pdf','page':index,
            'width':pix.width,'height':pix.height,**observer.pixels(pix.samples,pix.width,pix.height)})
    pdf.close()
    caption_sources.append({'project':project,'directory':dest.relative_to(OUT).as_posix(),
        'expected_private_font_sha256':sorted(fonts),'receipt':receipt})
profile['expected_font_sha256']=sorted(set(profile['expected_font_sha256']))
save(RAW/'caption-observation-profile.json',profile)
observer.inspect(profile)
observations=json.loads((RAW/'caption-independent/observations.json').read_text())
checks=[]
for item in observations['media']:
    frames={frame['frame']:frame for frame in item['frames']}
    whites=lambda n:frames[n]['threshold_counts']['200']
    if 'expected_window' in next(m for m in profile['media'] if m['path']==item['path']):
        valid=(item['decoded_frame_count']==18 and whites(0)==0 and whites(2)==0
               and min(whites(n) for n in [3,4,6,9,14])>40 and whites(15)==0 and whites(17)==0
               and all(.94<=c['peak_mask_luminance_ratio']<=1.06 for c in item['comparisons']))
        checks.append({'path':item['path'],'criterion':'caption window and luminance through fade','passed':valid})
    elif 'selection-a.mp4' in item['path']:
        checks.append({'path':item['path'],'criterion':'A alone source1.8-1.95','passed':
            item['decoded_frame_count']==60 and whites(53)==0 and min(whites(n) for n in [54,55,58])>20 and whites(59)==0})
    elif 'selection-c-a.mp4' in item['path']:
        checks.append({'path':item['path'],'criterion':'C then A source windows','passed':
            item['decoded_frame_count']==120 and whites(8)==0 and whites(10)>40 and whites(15)==0
            and whites(113)==0 and min(whites(n) for n in [114,115,117,118])>20 and whites(119)==0})
    elif 'selection-a-c.mp4' in item['path']:
        checks.append({'path':item['path'],'criterion':'A then C transition source window','passed':
            item['decoded_frame_count']==105 and whites(53)==0 and whites(56)>40 and whites(60)==0 and whites(104)==0})
for archive in observations['svg_archives']:
    checks.append({'path':archive['path'],'criterion':'portable vector text and private font bytes',
        'passed':len(archive['entries'])==2 and all(e['expected_private_font_present']
            and any('Hola' in t for t in e['text_nodes']) and not e['external_references'] for e in archive['entries'])})
for item in static_observations:
    checks.append({'path':f"{item['project']}/{item['format']}/page{item['page']}",
                   'criterion':'static raster caption pixels','passed':
                   item['width']==160 and item['height']==120 and item['threshold_counts']['200']>40})
save(RAW/'caption-static-observations.json',static_observations)
save(RAW/'caption-sources.json',caption_sources)
save(RAW/'caption-independent-checks.json',checks)
text_exports=[]
for source in (RAW/'artifacts').rglob('captions.srt'):
    project=next(p for p in ['mobile-360','mobile','desktop'] if source.parent.name.endswith(p))
    for suffix in ['srt','vtt']:
        file=source.with_suffix('.'+suffix)
        text=file.read_text(encoding='utf-8-sig').replace('\r\n','\n')
        parsed=re.findall(r'(\d\d:\d\d:\d\d[,.]\d{3}) --> (\d\d:\d\d:\d\d[,.]\d{3})\n(.*?)(?=\n\n|\Z)',text,re.S)
        normalized=[{'start':a.replace(',','.'),'end':b.replace(',','.'),'text':t.rstrip('\n')} for a,b,t in parsed]
        text_exports.append({'project':project,'format':suffix,'sha256':hashlib.sha256(file.read_bytes()).hexdigest(),'cues':normalized})
        expected=[{'start':'00:00:00.350','end':'00:00:00.850','text':'Modificado 🎵\nsegunda línea'},
                  {'start':'00:00:00.850','end':'00:00:01.350','text':'Adiós'}]
        checks.append({'path':file.relative_to(OUT).as_posix(),'criterion':'SRT/VTT Unicode and shifted cue times',
                       'passed':normalized==expected})
save(RAW/'caption-text-observations.json',text_exports)
caption_screenshot_crops=[]
for source in (RAW/'artifacts').rglob('captions-editor.png'):
    project=next(p for p in ['mobile-360','mobile','desktop'] if source.parent.name.endswith(p))
    shutil.copy2(source,OUT/'screenshots'/f'captions-{project}-full.png')
    image=Image.open(source)
    box=(470,1180,1140,min(image.height,2000)) if project=='desktop' else (0,1800,image.width,min(image.height,2800))
    image.crop(box).save(OUT/'screenshots'/f'captions-{project}.png')
    caption_screenshot_crops.append({'project':project,'source':source.relative_to(OUT).as_posix(),
        'source_size':list(image.size),'crop_box':list(box),'derived':f'screenshots/captions-{project}.png'})
save(RAW/'caption-screenshot-crops.json',caption_screenshot_crops)
subprocess.run(['node',str(OUT/'raster-svg.mjs')],cwd=ROOT,check=True,timeout=90)
vector_rasters=[]
for entry in json.loads((RAW/'svg-raster-observations.json').read_text()):
    image=Image.open(OUT/entry['png']).convert('RGB')
    observation={'path':entry['png'],'width':image.width,'height':image.height,
                 **observer.pixels(image.tobytes(),image.width,image.height),'fonts':entry['fonts']}
    vector_rasters.append(observation)
    checks.append({'path':entry['png'],'criterion':'independent portable SVG raster caption and loaded private font',
        'passed':image.width==160 and image.height==120 and observation['threshold_counts']['200']>40
                 and any(f['status']=='loaded' and f['family'].strip('"\'').startswith('StudioFont_') for f in entry['fonts'])})
save(RAW/'svg-independent-pixel-observations.json',vector_rasters)
save(RAW/'caption-independent-checks.json',checks)
print(json.dumps({'independent_caption_checks':len(checks),'failed':[c for c in checks if not c['passed']]}))
contrast_spec=importlib.util.spec_from_file_location('qa3_default_contrast',OUT/'inspect-default-contrast.py')
contrast_observer=importlib.util.module_from_spec(contrast_spec);contrast_spec.loader.exec_module(contrast_observer)
contrast_checks=contrast_observer.inspect()
save(RAW/'default-contrast-summary.json',{'checks':len(contrast_checks),
    'failed':[c for c in contrast_checks if not c['passed']],
    'private_font_window_selection_checks_preserved':len(checks),
    'private_font_window_selection_failed':[c for c in checks if not c['passed']]})
print(json.dumps({'default_contrast_checks':len(contrast_checks),'failed':[c for c in contrast_checks if not c['passed']]}))
