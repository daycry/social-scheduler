from pathlib import Path
import hashlib
import json
import os
import subprocess
import uuid
import xml.etree.ElementTree as ET

root = Path.cwd()
out = root / 'storage/framework/testing/t11-public-av-final'
out.mkdir(parents=True, exist_ok=True)
sources = {entry['path']: entry['sha256'] for entry in json.loads((root / 'docs/roadmap/2026-11-03-creative-social-suite/testing/t11/av-retirement/manifest.json').read_text())['sources']}
review = root / 'storage/framework/testing/t11-public-av-review/attempt2/manifest.json'
sources.update(json.loads(review.read_text()))

def verify():
    for name, sha in sources.items():
        assert hashlib.sha256((root / name).read_bytes()).hexdigest() == sha, name

verify()
names = ['StudioAvStateTest', 'StudioAvRegistrationTest', 'StudioAvTransferTest', 'StudioAvRetirementTest', 'StudioAvFilesTest',
         'StudioAvCleanupTest', 'StudioAvQueueTest', 'StudioAvProcessTest', 'StudioAvDeliveryTest', 'StudioAvPublicTest',
         'StudioRendererTest', 'StudioApiMcpTest', 'StudioBackendTest', 'StudioAutomationTest',
         'StudioFrameTimesTest', 'StudioTimelineDocumentTest', 'StudioAudioTest', 'StudioAudioAbortTest']
command = ['php', 'vendor/bin/pest', *['tests/Feature/Studio/' + name + '.php' for name in names],
           'tests/Feature/Media/MediaUploadTest.php', 'tests/Feature/Media/ImportRemoteMediaTest.php',
           '--log-junit', str(out / 'phpunit.xml')]
env = os.environ.copy()
env['STUDIO_AV_NATIVE_TEST'] = '1'
env['LARAVEL_STORAGE_PATH'] = str(out / ('php-' + str(uuid.uuid4())))
with (out / 'php.stdout').open('wb') as stdout, (out / 'php.stderr').open('wb') as stderr:
    child = subprocess.Popen(command, cwd=root, env=env, stdout=stdout, stderr=stderr)
    (out / 'php-process.json').write_text(json.dumps({'pid': child.pid, 'command': command, 'native_optin': True}, indent=2))
    print(json.dumps({'pid': child.pid, 'stdout': str(out / 'php.stdout')}), flush=True)
    code = child.wait()
verify()
tree = ET.parse(out / 'phpunit.xml')
cases = tree.findall('.//testcase')
native_prefixes = ('AV actual database worker processes native deliveries',
                   'AV database worker snapshots and mixes a private real tone',
                   'AV native Laravel bridge renders and assembles an actual MP4',
                   'AV public request crosses the actual database worker')
native = [case for case in cases if case.get('name', '').startswith(native_prefixes)]
result = {'exit': code, 'terminal': True, 'source_hashes_match': True, 'sources': len(sources), 'native_optin': True,
          'tests': len(cases), 'failures': len(tree.findall('.//failure')), 'errors': len(tree.findall('.//error')),
          'skipped': len(tree.findall('.//skipped')), 'assertions': sum(int(case.get('assertions', '0')) for case in cases),
          'native_cases': len(native), 'native_assertions': sum(int(case.get('assertions', '0')) for case in native),
          'native_case_names': [case.get('name') for case in native]}
(out / 'php-result.json').write_text(json.dumps(result, indent=2))
(out / 'sources.json').write_text(json.dumps({'sources': [{'path': name, 'sha256': sha} for name, sha in sources.items()]}, indent=2))
print(json.dumps(result), flush=True)
raise SystemExit(code)
