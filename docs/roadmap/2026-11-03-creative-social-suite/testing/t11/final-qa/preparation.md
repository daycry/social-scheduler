# Preparación de QA independiente T11

2026-11-05. No se han ejecutado suites. Pendiente de señal interna del orquestador: «revisión aprobada; ejecuta».

## Alcance

Una pasada integral de Chromium en desktop 1440×900, mobile 375×812 y mobile-360 360×740: studio, studio-automation, studio-timeline, studio-audio-editor, studio-audio-resources, studio-frame-export, studio-native-export, studio-av-composite, metrics-quality, brand-reporting y all-brands. El conteo se tomará del JSON real, no de la previsión de63.

Runtime existente `tests/e2e/isolated` con SQLite, storage y cuentas propias, localhost:8080; reuseExistingServer=false. Se comprueba puerto libre antes de arrancar. No se leen credenciales .env ni se usa MySQL, HTTP externo, VPS, ONNX/test_background/studio-imaging. El runtime exige su directorio UUID bajo testing/t43; no se altera el runner para cambiar esa restricción.

## Puertas y evidencias previstas

- Guardrail oficial validado para http://localhost:8080. Primera invocación bash en sandbox falló CreateFileMapping/Win32error5; invocación específica escalada terminó exit0/GUARDRAIL_LOCAL_OK.
- Ledger-lint:0incoherencias,16avisos Changelog; salida real conservada en raw/ledger-lint.txt.
- Coverage-check:0referencias rotas; T11 aparece sin cobertura declarada y existen IDs E2E13/14 duplicados. Aviso remitido al orquestador; no se exonera T11 ni se declara verde global. Otras tareas abiertas se mantienen fuera de esta QA de subbloque.
- Sin constitución de proyecto. Consulta knowledge-find sin aciertos; no se promueve conocimiento.
- Congelar SHA256 antes/después de código T11 y dependencias, contratos, criterios, tests/runner y public/build. Registrar comandos, PID/sesión, root UUID y tiempos. Un timeout de observación no inicia otra pasada.
- Conservar JSON real completo, stdout/stderr y artefactos. Ejecutar qa-gate sobre ese JSON sin transformación ni exclusiones. Comprobar además skipped/interrupted/errores runner/retries.
- Auditar resultados MP4 H264/AAC100×80/42frames/3,5s y GIF50×40/42frames/3,5s silencioso; píxeles de overlays/fade, movimiento RMS, amplitud440/880 y cola silenciosa, revisión fijada y borrador con marca/medio correctos. Medidas del test se contrastan contra artefactos reales.
- Inspección visual de capturas de los tres viewports. Informe MD+PDF mediante to-pdf, PDF renderizado e inspeccionado; manifiesto de fuentes/resultados.

## Límites y cierre

Backend369/2046 incl8nativos y frontend161/E2E48 son evidencia histórica del orquestador, no ejecución independiente propia. Windows aplica capCOMMIT agregado3GiB, noRSS; los probes Linux/cgroup anteriores con Python no son multimediaLinux/VPS. R2simulado no acredita bucketreal. No se modifica código, tests, ledger ni estados; T11 local se recomendará solo si hay evidencia suficiente. El plan completo sigue abierto,7/23cerradas al inicio.

Manuales: VPS/scheduler/worker y Bluesky/Threads a cargo del usuario; Node/Chromium/fuentes/cola/media/R2 reales del servidor pendientes; ClaudeDesktop aplazado. Auditoría ClaudeCLI final posterior a todo el roadmap. Sin Jira/Confluence ni handoff documenter/curator.
