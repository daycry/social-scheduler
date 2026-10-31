"""Link the archived, successful T43 evidence in its Markdown report."""
from pathlib import Path
import hashlib
import json
import shutil

testing = Path(__file__).resolve().parents[1]
raw = testing / 'raw'
items = json.loads((raw / 'case-summary.json').read_text(encoding='utf-8'))
metadata = json.loads((raw / 'run-metadata.json').read_text(encoding='utf-8'))
gate = json.loads((raw / 'qa-gate.json').read_text(encoding='utf-8'))
assert metadata['project_counts'] == {'desktop': 19, 'mobile': 19, 'mobile-360': 19}
assert len(items) == 57 and all(item['outcome'] == 'expected' for item in items)
assert all(result['status'] == 'passed' and result['retry'] == 0 for item in items for result in item['results'])
assert gate['verdict'] == 'VERDE' and gate['counts']['passed'] == 57
assert hashlib.sha256((raw / 'results.json').read_bytes()).hexdigest() == metadata['sha256']

downloads = []
for project in ['desktop', 'mobile', 'mobile-360']:
    destination = raw / 'downloads' / project
    destination.mkdir(parents=True, exist_ok=True)
    for extension in ['pdf', 'csv']:
        matches = [file for file in (raw / 'artifacts').rglob('informe.' + extension)
                   if file.parent.name.endswith('-' + project)]
        assert len(matches) == 1, (project, extension, matches)
        source = matches[0]
        target = destination / source.name
        shutil.copy2(source, target)
        digest = hashlib.sha256(source.read_bytes()).hexdigest()
        assert hashlib.sha256(target.read_bytes()).hexdigest() == digest
        downloads.append({'project': project, 'format': extension, 'bytes': target.stat().st_size,
                          'sha256': digest, 'path': target.relative_to(testing).as_posix()})
(raw / 'downloads.json').write_text(json.dumps(downloads, ensure_ascii=False, indent=2) + '\n', encoding='utf-8')

sections = ['\n## Capturas por escenario\n',
            'Cada bloque muestra una captura de escritorio y enlaza las otras dos anchuras. '
            'Las imágenes son de usuarios y cuentas simulados dentro del run desechable.\n']
for index in range(1, 13):
    scenario = f'E2E-{index:02}'
    by_project = {item['project']: item for item in items if item['scenario'] == scenario}
    assert set(by_project) == {'desktop', 'mobile', 'mobile-360'}
    main = by_project['desktop']
    primary = next((path for path in main['screenshots'] if 'panel-vacio' in path), main['screenshots'][-1])
    sections.append(f"\n### {main['title']} — PASA 3/3\n")
    sections.append(f"![{scenario}: estado comprobado en escritorio]({primary})\n")
    links = []
    for project, label in [('mobile', 'Móvil 375 px'), ('mobile-360', 'Móvil 360 px')]:
        screenshot = by_project[project]['screenshots'][-1]
        links.append(f'[{label}]({screenshot})')
    sections.append(' · '.join(links) + '\n')

sections += ['\n## Informes descargados y conservados\n',
             '| Perfil | PDF | CSV |\n|---|---|---|\n']
for project in ['desktop', 'mobile', 'mobile-360']:
    records = {record['format']: record for record in downloads if record['project'] == project}
    sections.append(f"| {project} | [PDF, {records['pdf']['bytes']} bytes]({records['pdf']['path']}) | "
                    f"[CSV, {records['csv']['bytes']} bytes]({records['csv']['path']}) |\n")
sections.append('\n[Manifiesto de tamaños y huellas de los seis informes](raw/downloads.json). '
                'Las copias enlazadas tienen el mismo SHA-256 que sus artefactos originales.\n')
sections.append('\nSe inspeccionaron visualmente capturas representativas del dashboard en escritorio '
                'y del listado de informes a 360 px: contenido legible, gráficos presentes y botones '
                'accesibles sin recortes observados. Esta inspección de QA no es la aprobación del usuario exigida por M-03.\n')

report = testing / 'report.md'
content = report.read_text(encoding='utf-8')
assert '\n## Capturas por escenario\n' not in content, 'Refusing to append duplicate evidence'
report.write_text(content + ''.join(sections), encoding='utf-8')
print(json.dumps({'tests': len(items), 'screenshots': sum(len(item['screenshots']) for item in items),
                  'downloads': len(downloads), 'report': str(report)}, ensure_ascii=False))
