# T-11: supervisión de procesos Linux

2026-11-05. Subbloque de supervisión verificado en WSL Ubuntu; T-11 continúa en progreso, 7/23 tareas completas. No acredita exportación multimedia nativa de Linux, jobs Laravel ni web/API/MCP.

El supervisor comprueba propietario y límite efectivo del cgroup actual. Crea exclusivamente un hijo UUID y migra el proceso allí antes de exec; después comprueba que el padre siga vivo. Un guardián ligero permanece fuera del subgrupo de fase, dentro del mismo límite heredado. EOF del pipe, deadline o cierre normal ejecutan cgroup.kill, esperan vacío y eliminan solo el grupo propio. Esto incluye descendientes que cambien sesión POSIX. El supervisor tiene limpieza de respaldo si el guardián muere. No se mata por nombre ni se recorre el árbol global de procesos.

La [unidad preparada](../../../../../../deploy/systemd/scheduler-studio.service) separa la futura cola Studio de las existentes, con MemoryMax3G, MemorySwapMax0, OOMPolicykill, KillModecontrol-group y Delegateyes. El usuario de aplicación carece de privilegios; el guardián solo administra su subárbol delegado. La plantilla no se ha instalado ni iniciado en el VPS. No se induce OOM ni se reserva el presupuesto de3GiB para probarlo.

Revisión fresca A/B/D en dos intentos. A1 encontró un descriptor antiguo que seguía afirmando que Linux estaba deshabilitado: actualizado para distinguir la primera tanda Windows del nuevo CLI supervisado. B1 reprodujo stdin cerrado: el pipe obtenía FD0 y la redirección del guardián lo sustituía por/dev/null, eliminando prematuramente el grupo. Root confirmó RED y movió ambos extremos aFD≥3 conCLOEXEC antes de fork. A2/B2/D2 conservan los criterios aprobados y terminan sin gaps pendientes. C=false por selector, sin atribuir una auditoría de seguridad completa.

Regresión posterior ejecutada por root, sin atribuir QA independiente:

- Frontend **149/149**, 30 archivos, cero fallos/cancelados/skips, sesión28410 terminal.
- Windows **4/4**: dos casos reales de JobObject y dos lectores de límites.
- Linux **10 ejecutados y correctos**, otros2 omitidos por ser exclusivos de Windows. Ocho prueban cgroups/dispatch reales y dos son los mismos lectores unitarios; no se suman como14casos distintos a los de Windows.
- `systemd-analyze verify`: exit0, stdout/stderr vacíos, copia temporal idéntica con modo0644. La primera lectura directa desdeNTFS advertía permisos sintéticos ejecutable/world-writable; no se instaló aquella copia ni se alteró el montaje.

Las pruebas Linux se ejecutaron en una unidad transitoria de usuario con nombre UUID, MemoryMax3G y Delegateyes, con STUDIO_AV_CGROUP_TEST=1. Verifican migración antes de exec, deadline, muerte del supervisor, hijos setsid, fallo del guardián, stdin cerrado y limpieza. El dispatch usa un ejecutable Python mínimo que observa su grupo; no simula ni acredita un encoder. WSL carece de Node/FFmpeg nativos para esa aceptación. Los logs registran los skips explícitos.

Siete SHA se mantuvieron iguales antes/después. Los ocho archivos del motor/test/CLI no modificados por este bloque coinciden con el informe Windows anterior. Cuatro fuentes UI y dos bundles también coinciden: la [regresión42/42 anterior](../av-options/raw/playwright.json) conserva aquel alcance y no se repitió. No hubo cambios PHP ni se repitió su suite. No se generó otra muestra: los [MP4/GIF y espectro anteriores](../av-managed/review.md) conservan su snapshot Windows, sin atribuirlos a Linux.

[Manifiesto](manifest.json): siete fuentes y16artefactos con hashes, TAP, pruebas de límites/cgroups, validación de unidad, diffs y estructura. Scope sin archivos fuera/avisos/exclusiones de usuario; ledger-lint cero incoherencias y16avisos anteriores de Changelog en tareas abiertas. OpenAPI sin cambios durante esta tanda.

Siguen pendientes: multimedia Linux real, estado/aceptación de leases y checkpoints en BD, snapshots/contabilidad/recuperación, jobs/progreso/cancel/retry, registro y borrador, web/API/MCP y QA del flujo completo. Sin ONNX, acceso al VPS, publicaciones sociales, nuevos commits o push de T-10/T-11.
