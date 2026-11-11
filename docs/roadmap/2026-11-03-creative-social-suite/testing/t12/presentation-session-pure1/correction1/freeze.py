"""Freeze correction1 only, preserving the entire initial TDD/source report."""
import datetime,hashlib,json,pathlib
HERE=pathlib.Path(__file__).resolve().parent;ROOT=HERE.parents[4]
sha=lambda p:hashlib.sha256(p.read_bytes()).hexdigest()
load=lambda p:json.loads(p.read_text(encoding='utf-8-sig'))
initial=load(HERE.parent/'SOURCE_DONE.json')
assert sha(HERE.parent/'SOURCE_DONE.json')==sha(HERE/'baseline/SOURCE_DONE.json')
for source,base in [('resources/js/Studio/presentation.js','presentation.js'),('tests/frontend/studio-presentation.test.mjs','studio-presentation.test.mjs')]:
    assert sha(HERE/'baseline'/base)==initial['sources'][source]
dependencies={path:sha(ROOT/path) for path in initial['loaded_static_dependencies']}
for path,h in dependencies.items():
    if path!='resources/js/Studio/presentation.js':assert h==initial['loaded_static_dependencies'][path]
def log(name):
    data=(HERE/name).read_bytes()
    return data.decode('utf-16') if data.startswith(b'\xff\xfe') else data.decode('utf-8-sig')
assert '# pass 16' in log('green-focal.txt') and '# fail 0' in log('green-focal.txt')
assert '# pass 29' in log('green-neighbors.txt') and '# fail 0' in log('green-neighbors.txt')
for name in ['red-fractional-clock.txt','red-zero-delta.txt','red-exact-boundary.txt']:assert '# fail 1' in log(name)
result={'schema':'presentation-session-correction-source-done-v1','state':'SOURCE_DONE_PENDING_FRESH_REVIEW_ATTEMPT2','captured_utc':datetime.datetime.now(datetime.timezone.utc).isoformat(),
    'task':'T-12/P1 correction1 B1 fractional clock','sources':{path:sha(ROOT/path) for path in initial['sources']},'loaded_static_dependencies':dependencies,
    'baseline':{'initial_source_done_sha256':sha(HERE.parent/'SOURCE_DONE.json'),'files':{p.name:sha(p) for p in (HERE/'baseline').iterdir()}},
    'tdd':{'red_exit_codes':[1,1,1],'red_cases':3,'red':['512 fractional duration triples fail final completion','advance(0) from p2 moves backwards to p1/123.39999999999998','advance(page.durationMs) from p1 remains p1 instead of p2'],
        'red_date':'2026-11-07','red_sequence':'All three RED logs captured before correction1 production edit; both original dedicated regressions are retained.',
        'green_focal_cases':16,'green_neighbor_cases':29,'failures':0,'skips':0,'cancellations':0,
        'coverage':'512 triples x3startpages x2loop modes with zero/small positive/exact remaining delta; explicit v1/v2 zero/boundary; fractions/full loop/huge safe delta from noninitial pages; initial12cases retained unchanged.',
        'commands':['node --max-old-space-size=512 --test --experimental-test-isolation=none --test-name-pattern=fractional duration clocks tests/frontend/studio-presentation.test.mjs',
            'node --max-old-space-size=512 --test --experimental-test-isolation=none --test-name-pattern=zero delta preserves tests/frontend/studio-presentation.test.mjs',
            'node --max-old-space-size=512 --test --experimental-test-isolation=none --test-name-pattern=exact fractional page duration tests/frontend/studio-presentation.test.mjs',
            'node --max-old-space-size=512 --test --experimental-test-isolation=none tests/frontend/studio-presentation.test.mjs',
            'node --max-old-space-size=512 --test --experimental-test-isolation=none tests/frontend/studio-presentation.test.mjs tests/frontend/studio-document.test.mjs tests/frontend/studio-timeline.test.mjs'],
        'syntax_checks':'node --max-old-space-size=512 --check on both current sources, exit0'},
    'fix':'Consume remaining duration forward from current index/local elapsed; modulo removes full cycles before adding any fractional local offset. No prefix sum reconstruction, epsilon, timers or delta-proportional iteration.',
    'evidence':{name:{'sha256':sha(HERE/name),'bytes':(HERE/name).stat().st_size} for name in ['red-fractional-clock.txt','red-zero-delta.txt','red-exact-boundary.txt','green-focal.txt','green-neighbors.txt']},
    'preserved_initial_evidence':initial['evidence'],'limits':initial['limits'],'measurement':'No measured hours/tokens claimed. PureNode512, each command <1s, no browser/build/DB/env/network/models.'}
for name,record in initial['evidence'].items():assert sha(HERE.parent/name)==record['sha256']
(HERE/'SOURCE_DONE.json').write_text(json.dumps(result,ensure_ascii=False,indent=2),encoding='utf-8')
print(json.dumps({'sources':result['sources'],'red_cases':3,'green_focal':16,'green_neighbors':29,'baseline_preserved':True,'sha256':sha(HERE/'SOURCE_DONE.json')}))
