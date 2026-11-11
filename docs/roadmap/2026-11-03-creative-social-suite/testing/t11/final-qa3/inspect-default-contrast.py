"""Prepared QA3 observer; no application imports and no execution before approval.

Keeps QA2's 42 private-font/window/selection checks separate. This series uses
min(R,G,B)>=200 consistently, with a positive unoutlined control, as calibrated
before QA3. No threshold is adjusted from the new run's output.
"""
import collections,hashlib,json,pathlib,subprocess
from PIL import Image
HERE=pathlib.Path(__file__).resolve().parent

def measure(data,width,height):
    if len(data)!=width*height*3:raise ValueError('Incomplete RGB observation')
    light=[min(data[i:i+3]) for i in range(0,len(data),3)]
    return {'width':width,'height':height,'white':sum(v>=200 for v in light),
        'energy':sum(v/255 for v in light),
        'thresholds_inclusive':{str(t):sum(v>=t for v in light) for t in [16,64,128,199,200,201,240]},
        'exact_200':sum(v==200 for v in light),'background':list(data[:3]),
        'rgb_sha256':hashlib.sha256(data).hexdigest()}

def black_canvas_box(image):
    """Observe the large black canvas in a real full-page screenshot, not a DTO."""
    rgb=image.convert('RGB'); w,h=rgb.size; values=rgb.tobytes()
    dark=bytearray(1 if max(values[i:i+3])<=3 else 0 for i in range(0,len(values),3))
    candidates=[]
    for start in range(len(dark)):
        if not dark[start]:continue
        dark[start]=0;queue=collections.deque([start]);count=0
        xmin=xmax=start%w;ymin=ymax=start//w
        while queue:
            point=queue.popleft();x,y=point%w,point//w;count+=1
            xmin=min(xmin,x);xmax=max(xmax,x);ymin=min(ymin,y);ymax=max(ymax,y)
            for nx,ny in [(x-1,y),(x+1,y),(x,y-1),(x,y+1)]:
                if 0<=nx<w and 0<=ny<h and dark[ny*w+nx]:
                    dark[ny*w+nx]=0;queue.append(ny*w+nx)
        cw,ch=xmax-xmin+1,ymax-ymin+1
        # Fixed frozen fixture is320x180; screenshot can uniformly shrink it.
        # Reject page/header regions and white HTML rather than count them as ink.
        if 200<=cw<=360 and 100<=ch<=205 and abs(cw/ch-320/180)<.03 and count/(cw*ch)>.85:
            candidates.append((count,(xmin,ymin,xmax+1,ymax+1)))
    if len(candidates)!=1:raise ValueError(f'Preview ROI ambiguous/missing; need one320:180 black canvas, found{len(candidates)}')
    return candidates[0][1]

def decode(path):
    probe=json.loads(subprocess.check_output(['ffprobe','-v','error','-count_frames',
        '-show_streams','-of','json',str(path)],timeout=30))
    stream=next(s for s in probe['streams'] if s['codec_type']=='video')
    raw=subprocess.check_output(['ffmpeg','-v','error','-i',str(path),'-pix_fmt','rgb24',
        '-f','rawvideo','pipe:1'],timeout=30)
    if len(raw)>16*1024*1024:raise ValueError('Observation budget exceeded; no truncation')
    return raw,stream,probe

def inspect():
    out=HERE/'raw/default-contrast';out.mkdir(parents=True,exist_ok=True)
    rows=[];checks=[]
    for project in ['desktop','mobile','mobile-360']:
        receipts=[p for p in (HERE/'raw/artifacts').rglob('captions-default-contrast-measurements.json')
                  if p.parent.name.endswith(project) and (project!='mobile' or not p.parent.name.endswith('mobile-360'))]
        if len(receipts)!=1:
            checks.append({'project':project,'criterion':'required default/control output receipt','passed':False})
            continue
        folder=receipts[0].parent
        pairs={}
        for name in ['control','outline']:
            sample={'project':project,'variant':name,'formats':{},'preview':None}
            for kind in ['png','mp4','gif']:
                file=folder/f'contrast-{name}.{kind}'
                if not file.is_file():
                    checks.append({'project':project,'variant':name,'format':kind,'criterion':'required output','passed':False});continue
                raw,stream,probe=decode(file);w,h=stream['width'],stream['height'];size=w*h*3
                frame=0 if kind=='png' else 6
                active=raw[frame*size:(frame+1)*size]
                observed=measure(active,w,h)
                observed.update(frame=frame,decoded_frame_count=len(raw)//size,
                    path=file.relative_to(HERE).as_posix(),file_sha256=hashlib.sha256(file.read_bytes()).hexdigest(),probe=probe)
                Image.frombytes('RGB',(w,h),active).save(out/f'{project}-{name}-{kind}-active.png')
                if kind!='png':
                    inactive=measure(raw[:size],w,h);observed['before_cue']=inactive
                    Image.frombytes('RGB',(w,h),raw[:size]).save(out/f'{project}-{name}-{kind}-before.png')
                    checks.append({'project':project,'variant':name,'format':kind,'criterion':'before cue has no neutral white pixels','passed':inactive['white']==0})
                sample['formats'][kind]=observed
            screenshot=folder/f'captions-contrast-{name}.png'
            if screenshot.is_file():
                try:
                    image=Image.open(screenshot);box=black_canvas_box(image)
                    crop=image.crop(box).convert('RGB');crop.save(out/f'{project}-{name}-preview-crop.png')
                    sample['preview']={**measure(crop.tobytes(),crop.width,crop.height),
                        'source':screenshot.relative_to(HERE).as_posix(),'crop_box':list(box),
                        'source_sha256':hashlib.sha256(screenshot.read_bytes()).hexdigest()}
                except ValueError as error:
                    sample['preview_error']=str(error)
            rows.append(sample);pairs[name]=sample
        if set(pairs)!= {'control','outline'}:continue
        for kind in ['png','mp4','gif']:
            if not all(kind in pairs[n]['formats'] for n in pairs):continue
            control=pairs['control']['formats'][kind];actual=pairs['outline']['formats'][kind]
            # Absolute>1000 applies to calibrated PNG control; codec variants keep>500.
            minimum=1000 if kind=='png' else 500
            expected_frames=1 if kind=='png' else 18
            checks.append({'project':project,'format':kind,'criterion':'positive control and default/control coverage and energy',
                'white_ratio':actual['white']/control['white'] if control['white'] else None,
                'energy_ratio':actual['energy']/control['energy'] if control['energy'] else None,
                'passed':control['white']>minimum and actual['white']>=control['white']*.5
                         and actual['energy']>=control['energy']*.5 and actual['background']==[0,0,0]
                         and control['width']==actual['width']==320 and control['height']==actual['height']==180
                         and control['decoded_frame_count']==actual['decoded_frame_count']==expected_frames})
        if all(pairs[n]['preview'] is not None for n in pairs):
            control=pairs['control']['preview'];actual=pairs['outline']['preview']
            checks.append({'project':project,'criterion':'real screenshot preview neutral letters against positive control',
                'white_ratio':actual['white']/control['white'] if control['white'] else None,
                'energy_ratio':actual['energy']/control['energy'] if control['energy'] else None,
                'passed':control['white']>500 and actual['white']>=control['white']*.5
                         and actual['energy']>=control['energy']*.5})
        else:checks.append({'project':project,'criterion':'required unambiguous real preview screenshots',
                           'errors':[pairs[n].get('preview_error','missing screenshot') for n in pairs if pairs[n]['preview'] is None],
                           'passed':False})
    (out/'observations.json').write_text(json.dumps(rows,ensure_ascii=False,indent=2),encoding='utf-8')
    (out/'checks.json').write_text(json.dumps(checks,ensure_ascii=False,indent=2),encoding='utf-8')
    historical=HERE.parent/'final-qa2/raw/mobile-preview-detail.png'
    if historical.is_file():
        image=Image.open(historical);box=black_canvas_box(image)
        crop=image.crop(box).convert('RGB');crop.save(out/'historical-qa2-preview-canvas.png')
        (out/'historical-qa2-reference.json').write_text(json.dumps({
            'source':str(historical),'sha256':hashlib.sha256(historical.read_bytes()).hexdigest(),
            'crop_box':list(box),'observation':measure(crop.tobytes(),crop.width,crop.height),
            'role':'Historical illustration only; includes emoji and is not the oracle or threshold for QA3'},indent=2),encoding='utf-8')
    return checks

if __name__=='__main__':
    raise SystemExit('Prepared observer is invoked only by the approved memory-managed QA3 collector')
