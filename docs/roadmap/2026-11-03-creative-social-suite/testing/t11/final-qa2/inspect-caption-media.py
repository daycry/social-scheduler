"""Prepared independent byte/pixel observer. Not run during QA2 preparation.

Input JSON after final freeze:
{"media":[{"path":"samples/...mp4", "frames":[0,3,4,9,15,17]}],
 "svg_archives":["samples/...zip"], "expected_font_sha256":["..."]}
Frame expectations/acceptance are documented separately against frozen contract;
this observer never imports product caption helpers or invents PASS from file names.
"""
import argparse
import base64
import hashlib
import json
import pathlib
import re
import subprocess
import zipfile
import xml.etree.ElementTree as ET
from PIL import Image

HERE = pathlib.Path(__file__).resolve().parent
APPROVAL = 'revisión aprobada; ejecuta intento2'

def owned_file(relative):
    path = (HERE / relative).resolve()
    if HERE not in path.parents or path.is_symlink() or not path.is_file():
        raise ValueError(f'Input must be a real artifact under final-qa2: {relative}')
    return path

def native(command, args, maximum=32 * 1024 * 1024, input_bytes=None):
    result = subprocess.run([command, '-v', 'error', *args], capture_output=True,
                            check=True, timeout=30, input=input_bytes)
    if len(result.stdout) > maximum:
        raise ValueError('Output exceeds observation budget; do not truncate evidence.')
    return result.stdout

def pixels(raw, width, height):
    if len(raw) != width * height * 3:
        raise ValueError('Unexpected decoded frame byte count')
    # White on black fixture: independent multi-threshold luminance, not only >200.
    luma = [(raw[i] + raw[i+1] + raw[i+2]) / 3 for i in range(0, len(raw), 3)]
    foreground = [i for i,value in enumerate(luma) if value > 16]
    bbox = None if not foreground else [min(i % width for i in foreground),
        min(i // width for i in foreground), max(i % width for i in foreground),
        max(i // width for i in foreground)]
    return {'threshold_counts': {str(t):sum(value>t for value in luma)
                                for t in [16,64,128,200,240]},
            'peak_luminance':max(luma), 'sum_luminance':sum(luma),
            'bbox':bbox, 'rgb_sha256':hashlib.sha256(raw).hexdigest()}

def inspect(profile):
    destination = HERE / 'raw/caption-independent'
    destination.mkdir(parents=True, exist_ok=True)
    report = {'observer':'stdlib + ffprobe/FFmpeg + ZIP/XML/SHA; no product helper imports',
              'profile':profile, 'media':[], 'svg_archives':[]}
    for number,entry in enumerate(profile.get('media',[])):
        path = owned_file(entry['path'])
        probe = json.loads(native('ffprobe', ['-count_frames','-show_streams',
                                              '-show_format','-of','json',str(path)]))
        stream = next(s for s in probe['streams'] if s['codec_type']=='video')
        width,height = stream['width'],stream['height']
        frames = []
        decoded = {}
        all_rgb = native('ffmpeg',['-i',str(path),'-pix_fmt','rgb24','-f','rawvideo','pipe:1'])
        frame_bytes = width*height*3
        if len(all_rgb)%frame_bytes:
            raise ValueError('Incomplete decoded media frame')
        # Select by integer decoded frame index to avoid timestamp-seek ambiguity.
        for frame in entry['frames']:
            if not isinstance(frame,int) or frame<0:
                raise ValueError('Frame indices must be nonnegative integers')
            rgb = all_rgb[frame*frame_bytes:(frame+1)*frame_bytes]
            png = destination / f'media-{number}-frame-{frame:05}.png'
            Image.frombytes('RGB',(width,height),rgb).save(png)
            frames.append({'frame':frame, **pixels(rgb,width,height)})
            decoded[frame] = rgb
        comparisons = []
        for first,second in entry.get('compare_frames',[]):
            a,b = decoded[first],decoded[second]
            peak_indices = [i for i in range(0,len(a),3)
                            if min(a[i:i+3])>240]
            base = sum(sum(a[i:i+3]) for i in peak_indices)
            observed = sum(sum(b[i:i+3]) for i in peak_indices)
            comparisons.append({'frames':[first,second],
                'baseline_peak_pixels':len(peak_indices),
                'peak_mask_luminance_ratio':observed/base if base else None,
                'rgb_rmse':(sum((x-y)**2 for x,y in zip(a,b))/len(a))**.5,
                'rgb_max_difference':max(abs(x-y) for x,y in zip(a,b))})
        report['media'].append({'path':entry['path'],'sha256':hashlib.sha256(path.read_bytes()).hexdigest(),
                               'probe':probe,'decoded_frame_count':len(all_rgb)//frame_bytes,
                               'frames':frames,'comparisons':comparisons})
    expected = set(profile.get('expected_font_sha256',[]))
    for relative in profile.get('svg_archives',[]):
        path = owned_file(relative); entries = []
        with zipfile.ZipFile(path) as archive:
            for item in archive.infolist():
                if not item.filename.lower().endswith('.svg'):
                    continue
                if item.file_size > 16*1024*1024:
                    raise ValueError('SVG exceeds independent observation budget')
                text = archive.read(item).decode('utf-8')
                tree = ET.fromstring(text)
                fonts = [base64.b64decode(value) for value in re.findall(
                    r'data:font/woff2;base64,([A-Za-z0-9+/=]+)',text)]
                hashes = [hashlib.sha256(font).hexdigest() for font in fonts]
                entries.append({'name':item.filename,
                    'text_nodes':[''.join(node.itertext()) for node in tree.iter()
                                  if node.tag.rsplit('}',1)[-1]=='text'],
                    'embedded_font_sha256':hashes,
                    'expected_private_font_present':bool(expected.intersection(hashes)),
                    'external_references':re.findall(r'(?:href|src)=[\"\'](https?://[^\"\']+)',text)})
        report['svg_archives'].append({'path':relative,'entries':entries})
    (destination/'observations.json').write_text(json.dumps(report,ensure_ascii=False,indent=2),encoding='utf-8')

if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--approval',required=True)
    parser.add_argument('--profile',required=True)
    args = parser.parse_args()
    if args.approval != APPROVAL:
        raise SystemExit('Prepared observer cannot execute before internal attempt2 approval.')
    inspect(json.loads(owned_file(args.profile).read_text(encoding='utf-8')))
