"""Freeze correction2; preserve prior review sources and both rounds of evidence."""
import datetime,hashlib,json,pathlib
HERE=pathlib.Path(__file__).resolve().parent;ROOT=HERE.parents[4]
sha=lambda p:hashlib.sha256(p.read_bytes()).hexdigest()
load=lambda p:json.loads(p.read_text(encoding='utf-8-sig'))
previous=load(HERE.parent/'correction1/SOURCE_DONE.json');initial=load(HERE.parent/'SOURCE_DONE.json')
assert sha(HERE.parent/'correction1/SOURCE_DONE.json')==sha(HERE/'baseline/SOURCE_DONE.json')
for source,base in [('resources/js/Studio/presentation.js','presentation.js'),('tests/frontend/studio-presentation.test.mjs','studio-presentation.test.mjs')]:
    assert sha(HERE/'baseline'/base)==previous['sources'][source]
assert (ROOT/'tests/frontend/studio-presentation.test.mjs').read_text(encoding='utf-8').startswith((HERE/'baseline/studio-presentation.test.mjs').read_text(encoding='utf-8'))
dependencies={path:sha(ROOT/path) for path in previous['loaded_static_dependencies']}
for path,h in dependencies.items():
    if path!='resources/js/Studio/presentation.js':assert h==previous['loaded_static_dependencies'][path]
def log(name):
    data=(HERE/name).read_bytes()
    return data.decode('utf-16') if data.startswith(b'\xff\xfe') else data.decode('utf-8-sig')
assert '# pass 19' in log('green-focal.txt') and '# fail 0' in log('green-focal.txt')
assert '# pass 32' in log('green-neighbors.txt') and '# fail 0' in log('green-neighbors.txt')
assert '# fail 1' in log('red-total-boundary.txt') and '# fail 2' in log('red-forward-boundaries-valid.txt')
for name,record in previous['evidence'].items():assert sha(HERE.parent/'correction1'/name)==record['sha256']
for name,record in initial['evidence'].items():assert sha(HERE.parent/name)==record['sha256']
result={'schema':'presentation-session-correction-source-done-v1','state':'SOURCE_DONE_PENDING_FRESH_REVIEW_ATTEMPT3','captured_utc':datetime.datetime.now(datetime.timezone.utc).isoformat(),
    'task':'T-12/P1 correction2 B2 cumulative fractional clock boundary','sources':{path:sha(ROOT/path) for path in previous['sources']},'loaded_static_dependencies':dependencies,
    'baseline':{'correction1_source_done_sha256':sha(HERE.parent/'correction1/SOURCE_DONE.json'),'initial_source_done_sha256':sha(HERE.parent/'SOURCE_DONE.json'),'files':{p.name:sha(p) for p in (HERE/'baseline').iterdir()}},
    'tdd':{'valid_red_exit_codes':[1,1],'valid_red_cases':3,'red':['advance(323.4) on durations [0.1,0.1234,0.1] yields final elapsed99.99999999999999 instead of100','Remaining totals from noninitial pages/local offsets fail completion','Exact forward intermediate boundary remains p1 instead ofp2'],
        'red_date':'2026-11-07','red_sequence':'New three test bodies and both valid RED logs precede the correction2 production edit. All sixteen prior test bodies remain an identical prefix.',
        'fixture_correction':'Initial red-forward-boundaries.txt used literal223.4 as exact intermediate boundary, while100+0.1234*1000 evaluates223.39999999999998. Before production change, replaced literal with forward sum of the actual validated page durations and recaptured red-forward-boundaries-valid.txt. Original flawed log retained, not counted as independent valid RED.',
        'green_focal_cases':19,'green_neighbor_cases':32,'failures':0,'skips':0,'cancellations':0,
        'coverage':'Original16 cases unchanged; explicit B2v1/v2;4fractional triples x2schemas x3startpages x3offsets=72remaining-span cases; exact intermediate cumulative boundary.',
        'commands':['node --max-old-space-size=512 --test --experimental-test-isolation=none --test-name-pattern=fractional total duration reaches tests/frontend/studio-presentation.test.mjs',
            'node --max-old-space-size=512 --test --experimental-test-isolation=none --test-name-pattern=forward cumulative boundaries|fractional cumulative crossing tests/frontend/studio-presentation.test.mjs',
            'node --max-old-space-size=512 --test --experimental-test-isolation=none tests/frontend/studio-presentation.test.mjs',
            'node --max-old-space-size=512 --test --experimental-test-isolation=none tests/frontend/studio-presentation.test.mjs tests/frontend/studio-document.test.mjs tests/frontend/studio-timeline.test.mjs'],
        'syntax_checks':'node --max-old-space-size=512 --check on both current sources, exit0'},
    'fix':'After modulo full cycles, compare unchanged delta against cumulative forward durations from current page minus originElapsed. The previous boundary identifies the local residual. No repeated subtraction from delta, no sums of preceding pages, no epsilon, no delta-proportional iteration.',
    'reasoning':['A zero delta/full loop returns the existing state exactly.','For each crossed page the boundary is accumulated forward from the current page, with the same initial local offset subtracted once.','At equality the original delta crosses that boundary; after a nonterminal crossing subtracting that exact previous boundary gives elapsed0.','Nonloop terminal equality uses the full forward boundary instead of a succession of rounded delta remainders.','Cycle modulo bounds the forward scan to O(selected pages); original fractional elapsed is never added to a huge delta.'],
    'evidence':{name:{'sha256':sha(HERE/name),'bytes':(HERE/name).stat().st_size} for name in ['red-total-boundary.txt','red-forward-boundaries.txt','red-forward-boundaries-valid.txt','green-focal.txt','green-neighbors.txt']},
    'preserved_correction1_evidence':previous['evidence'],'preserved_initial_evidence':initial['evidence'],'limits':initial['limits'],
    'measurement':'No measured hours/tokens claimed. PureNode512, each command <1s, no browser/build/DB/env/network/models.'}
(HERE/'SOURCE_DONE.json').write_text(json.dumps(result,ensure_ascii=False,indent=2),encoding='utf-8')
print(json.dumps({'sources':result['sources'],'red_cases':3,'green_focal':19,'green_neighbors':32,'baseline_preserved':True,'sha256':sha(HERE/'SOURCE_DONE.json')}))
