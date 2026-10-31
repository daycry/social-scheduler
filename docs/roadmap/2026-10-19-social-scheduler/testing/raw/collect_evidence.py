"""Copy a completed T43 run without rewriting its reporter JSON."""
from __future__ import annotations

import base64
import hashlib
import json
from pathlib import Path
import re
import shutil
import sys

testing = Path(__file__).resolve().parents[1]
repo = testing.parents[3]
run = Path(sys.argv[1]).resolve()
allowed = (repo / 'storage/framework/testing/t43').resolve()
if not run.is_relative_to(allowed) or not (run / '.t43-run').is_file():
    raise SystemExit('Not an initialized disposable T43 run')
raw = testing / 'raw'
raw.mkdir(exist_ok=True)
source = run / 'results.json'
data = json.loads(source.read_text(encoding='utf-8-sig'))
copied = raw / 'results.json'
if copied.exists():
    raise SystemExit('Immutable raw/results.json already exists; refusing to replace it')
shutil.copy2(source, copied)
digest = hashlib.sha256(source.read_bytes()).hexdigest()
assert hashlib.sha256(copied.read_bytes()).hexdigest() == digest
(raw / 'results.sha256').write_text(digest + '  results.json\n', encoding='utf-8')
shutil.copytree(run / 'artifacts', raw / 'artifacts')
if (run / 'console.log').is_file():
    shutil.copy2(run / 'console.log', raw / 'console.log')
junit = repo / 'storage/framework/testing/t43/phpunit-final.xml'
if junit.is_file():
    shutil.copy2(junit, raw / 'phpunit-final.xml')

items = []

def safe_name(value: str) -> str:
    return re.sub(r'[^a-zA-Z0-9_.-]+', '-', value).strip('-')

def walk(suite):
    for spec in suite.get('specs', []):
        for test in spec.get('tests', []):
            title = spec['title']
            project = test.get('projectName', test.get('projectId', 'unknown'))
            match = re.search(r'\b(?:E2E|API)-\d{2}\b', title)
            item = {'title': title, 'scenario': match.group(0) if match else 'harness',
                    'project': project, 'outcome': test.get('status'),
                    'expectedStatus': test.get('expectedStatus'), 'results': [], 'screenshots': []}
            for result in test.get('results', []):
                item['results'].append({'status': result.get('status'), 'duration': result.get('duration'),
                                        'retry': result.get('retry'), 'errors': result.get('errors', [])})
                for attachment in result.get('attachments', []):
                    name = safe_name(attachment.get('name', 'attachment'))
                    content_type = attachment.get('contentType', '')
                    if content_type == 'image/png' and attachment.get('path'):
                        original = Path(attachment['path'])
                        if not original.is_absolute():
                            original = repo / original
                        dest = testing / 'screenshots' / safe_name(project) / (name + '.png')
                        dest.parent.mkdir(parents=True, exist_ok=True)
                        shutil.copy2(original, dest)
                        item['screenshots'].append(dest.relative_to(testing).as_posix())
                    elif content_type == 'application/json' and attachment.get('body'):
                        dest = raw / 'supplementary' / safe_name(project) / (name + '.json')
                        dest.parent.mkdir(parents=True, exist_ok=True)
                        dest.write_bytes(base64.b64decode(attachment['body']))
            items.append(item)
    for child in suite.get('suites', []):
        walk(child)

for suite in data.get('suites', []):
    walk(suite)
projects = {project: [item for item in items if item['project'] == project]
            for project in sorted({item['project'] for item in items})}
metadata = {'command': 'npx playwright test -c playwright.roadmap.config.ts',
            'source_run': str(run), 'source_results': str(source), 'sha256': digest,
            'stats': data.get('stats'), 'runner_errors': data.get('errors', []),
            'project_counts': {project: len(tests) for project, tests in projects.items()},
            'scenario_counts': {kind: sum(item['scenario'].startswith(kind) for item in items)
                                for kind in ['E2E', 'API', 'harness']}}
for name, value in [('run-metadata.json', metadata), ('case-summary.json', items),
                    ('api-summary.json', [item for item in items if item['scenario'].startswith('API')])]:
    (raw / name).write_text(json.dumps(value, ensure_ascii=False, indent=2) + '\n', encoding='utf-8')
print(json.dumps(metadata, ensure_ascii=False, indent=2))
