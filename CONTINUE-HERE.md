# Continuar la suite social y creativa

Actualizado: 2026-11-07. El [ledger canónico](docs/roadmap/2026-11-03-creative-social-suite/tasks.md) manda sobre este resumen.

## Objetivo completo y autorizaciones

Implementar las **23 tareas** de la suite que reúne las funciones solicitadas de Metricool y Canva, incluidas capacidades de pago, marcas y servicios web/API/MCP. Después ejecutar **Claude Code CLI sobre toda la aplicación y documentación**, corregir y verificar sus hallazgos y completar commits, push y PR. El usuario autoriza trabajo independiente; no volver a pedir permisos rutinarios. No cerrar el objetivo por una fase parcial.

**8/23 tareas cerradas:** T02, T04, T05, T06, T07, T08, T09 y T11. T12 está en progreso. La auditoría global Claude CLI y la nueva entrega Git siguen pendientes. Rama `feature/creative-social-suite`; no confundir la disponibilidad de Claude CLI con una auditoría realizada.

## Siguiente acción vigente

**Gráficos build12: perfiles de fuente listos para revisión actual.** Clientqa3(71/0+AST5/0),serverqa4(86/0+AST7/0),renewal2(20/0puros,grafo9/11) conservan casos/oráculos/guardas ybuild12actual95/78. Root publica snapshots completos ycongela fullmap en `t12-chart-labels-current-review1/attempt1`; SourceReady/ACK/runtime aún pendientes. Tras A+B frescas yC/D condicionalresueltas, `labels-ready3/prepare-ready-root.py --compose` crea datos/config/ACK actual; sólo entonces ejecutar renewal2/coordinator.py hostlocal. Foco6+cliente3+server3 compartenUN900inclinicio/bundle/oráculos/cierre; no resets,ni recortes. Reutilizarcolección3+3 sólo porSHAigual, no repetir. No tocar fuentes/ledger/checkpoint entreflat yfinfase. Preservar negativa ready2 yfalloP3original. Gráficos no valida presentaciones.


**Build12 actual y revisado**, `storage/framework/testing/t12-presentation-build12/result.json`: exit0,95entradas/78assets,drift[],clase corregida enJSgenerado,post-build-root.json exacto. No recompilar sin cambio/fallo nuevo. Build11 archivado porbytes en build12/build11-artifacts, ahora histórico.

**QA integrada P2+P3 NO-PASS:** qa2/run-1794027861823829800 terminó16pass/1fail/16sin ejecutar. Oncecasosdesktop ycincomobile pasan. Móvil tools.spec83:91 recibe azul envezdeláserrojo. Causa probada: grid flex min-h-0 se comprime yfooterinterceptacanvas conherramientasabiertas. Diagnóstico SFCmarkup+CSSreal375: grid70.39<canvas188.44,hitFOOTER; trasminfixshrink-0,grid468.875,hitCANVAS;768/1440CANVASpreservado. SourceDone yA+B aprobados0C/I/M, única claseDialog234, SHA8e4f8013…550d6. No se modificaronJS/tests/aserciones. Diagnóstico no aceptaapp. [Informe/captura/traza originales](docs/roadmap/2026-11-03-creative-social-suite/testing/t12/presentation-p3-native-2026-11-07/report.md).

El runterminal conserva origenc común1794027586.7066417/deadline1794028486.7066417.419.478s cumulative/143.131srunner,peak734769152B,minphysical9527115776B,guardfailureNone,drift[],noownedchildren,puertos8080libres. EPERM previo cero casos/reporter metadata sólo. La reparación/revisión/buildconsumieronelresto: no cabeotra tanda33completa. **No resetear ese900 ni relanzar perfilesqa1/qa2 que apuntanbuild11; fallaránporSHAactual.** No presentar16pasadoscomoaceptación33. PDF yaceptaciónvisual/nativafinal pendientes.

**Próximo trabajo independiente permitido:** renovar la aceptación pendiente de gráficos para build12, manteniendo su propio scope común foco6+cliente3+servidor3/900, o continuar otra capacidad funcional real pendiente de T12 según ledger. Gráficos es una fase distinta ya contratada; no valida presentaciones. No usar nuevasetiquetas/cambiosficticios para resetearlaQAfallida. Una futura aceptación de presentaciones exige contexto/build/manifest actuales yfase autorizada completa; conservar siempre el fallo original yfixpendientedeGREENnativo.

Todos los implementadores/revisores actuales terminaron. Root mantiene docs/ledger yúnicoslotpesado. T12yroadmap8/23siguenabiertos;live/grabación/Office/docs/pizarras/gráficos/XLSX/rest23,ClaudeCodeCLIglobal/fixes+commits-push-PRpendientes. Sin.env/BD/R2/proveedoresreales.

## Producto P2 y última compilación anterior a P3

Notas opcionales de página v1/v2: texto exacto, máximo 20.000 puntos Unicode, límite global 2 MiB, persistencia web/API/MCP, duplicados e historial. El test de backend ya funciona desde Pest estándar mediante `tests/Support/StudioPresentationTestApplication.php`, con SQLite en memoria, almacenamiento aleatorio y cache ficticia antes del Kernel. No necesita helpers privados ignorados ni lee .env.

La UI implementa audiencia/presentador, notas y siguiente diapositiva, reproducción/navegación/loop/blackout, popup sólo de píxeles, teclado/foco y limpieza. Usa el renderer real en código, copia propia del documento, reloj de timeline con cuantización/solapes y staging canvas. El siguiente preview es offline y acotado, sin audio. **Su aceptación nativa sigue pendiente.**

La carrera de vídeo quedó corregida: `timeline-preview.pause()` invalida la generación; un error de seek antiguo no pausa una petición nueva. Regresión integrada con player y factory reales: RED cuatro fallos y un positivo, GREEN 5/5; vecinos de presentación 39/39 y timeline 13/13. Es prueba pura, no observación audiovisual.

Revisión final P2:

- [Resultado review3](storage/framework/testing/t12-presentation-p2-review3/attempt3/result.json): `SOURCE-REVIEW-PASS_P2`, 0C/0I/0M.
- Veredicto SHA `6163aaf6616c3d1c9321b48b424378825cc188dd1a7e71379a52ad2f437c88d0`.
- Dos intentos anteriores preservados: portabilidad del test y cancelación real de medios; ambos corregidos y aprobados. No reiniciar su contador ni reabrir otros juicios sin evidencia nueva.

**Build10 real terminado0, histórico tras P3**, no recompilar parcialmente:

- [Recibo](storage/framework/testing/t12-presentation-build10/result.json), SHA `b793c048f6d9e5c2bde0ec03564ac7bd7d7f502c7a8c3d06f3ed78efd6a141f8`.
- 83 entradas,78assets,changed[],1.8098438s; COMMIT pico1.239.216.128B bajo3GiB,killOnClose/sinbreakaway; físico inicial6.670.274.560B,mínimo6.213.079.040B.
- [Comprobación root](storage/framework/testing/t12-presentation-build10/post-build-root.json): SHA actuales y conjunto exacto de assets comprobados. Sus fuentes incluyen el fix aprobado; cambios posteriores son sólo documentación y perfil contextual QA3.
- Los78assetsbuild9 quedaron archivados por bytes en build10/build9-artifacts y su índice. Build9 es histórico; sus79/78 yreceipt6af1…d46f3 no acreditan código posterior. Los77assetsbuild8 siguen en su archivo anterior.

No usar coordinadores/perfiles build8/build9 obsoletos para probar build10. Renovación de gráficos requiere preparación actual nueva sin reetiquetar resultados históricos.

## Guardas y límites de ejecución

Un único slot pesado para root: build, Chromium, render y PDF. Sólo datos ficticios y loopback; no .env, BD/R2/proveedores reales, posts, despliegues ni merge.

- QA nativa: Windows Job **COMMIT agregado 3 GiB**, killOnClose, sin breakaway; físico libre >=6 GiB antes de UUID/semilla y piso >=3 GiB cada100 ms; Node512/PHP256; **900 s totales** con inicio, casos, oráculos y cierre, sin reinicios por viewport o comando.
- Build: Node1536, Job3GiB, 330 s total/300 s child, OS whitelist y envDir vacío para Vite/Laravel; guardas y recibos existentes; nueva compilación sólo tras SourceDone/revisión de P3.
- No duplicar oráculos pesados ni rasterizar de nuevo para un recuento. Comprobar procesos propios terminales y puerto8080 libre IPv4/IPv6. PDF sólo con evidencia real y páginas inspeccionadas.
- Pruebas puras: Node512; Node22 usa `--experimental-test-isolation=none`. Para estrés previo se usó `--expose-gc`; no repetir sin motivo.
- No escribir acentos mediante here-string de PowerShell enviado por STDIN; usar archivo UTF-8 o escapes Unicode ASCII.

## Gráficos: pendiente independiente que se conserva

VL1/VL2 corrigieron etiquetas ocultas y porcentajes ilegibles. Cliente build7 tuvo verde automático pero **NO-PASS visual**: ese resultado histórico no se convierte en aceptación actual. Fuentes y nuevas guardas de visibilidad fueron revisadas; falta la ejecución con la compilación actual.

La renovación conserva **foco6 + cliente01×3 + servidor02×3 dentro de un único common900**. No usar P2 para fraccionar ese plazo. La fase P2 es otra funcionalidad y no ejecuta esos grupos. Actualizar los perfiles y el manifiesto de gráficos para la compilación vigente, preservando recibos anteriores, antes de relanzar nada.

El intento `labels-ready2` se rechazó antes de crear Job por físico <6GiB. Conserva su config, ACK y [rechazo](storage/framework/testing/t12-chart-labels-ready2/refusal-root.json); cero bundle privado y casos nativos. `ready1` conserva un rebase administrativo parcial con originales archivados. No relanzar sus composers ni sobrescribirlos.

Errores QA ya resueltos: opacidad XML, protocolo metadata público, normalización Vite/Windows y baselines del servidor. Sus revisiones/archivos históricos permanecen íntegros. Las colecciones originales cliente3+servidor3 no se repiten por cambios sólo administrativos. El renderer ahora cambió con P2, así que una nueva preparación debe corresponder al código actual, sin presentar pines históricos como actuales.

Las máscaras alfa del foco siguen siendo diagnóstico `accepted:null/threshold:null`; no hay aceptación99% de glifos. Las matrices agotadas, consumidor, densidad y título Y conservan sus alcances históricos; no reejecutarlos por rutina ni ampliar sus conclusiones.

## Alcance que todavía falta

T12 no se cierra por P2: quedan aceptación nativa de presentación y gráficos, otras familias/configuraciones de gráficos, AVcharts, XLSX/conexiones, documentos refluibles, pizarras/conectores, Office editable y grabación según el inventario canónico. T10 mantiene su aceptación integrada pendiente; no repetir modelos ONNX ni bajar sus guardas.

T01/T03/T13–T23 conservan inventario, métricas/proveedores, colaboración, IA, sitios, integración oficial Canva, profundidad social, reporting/Ads, QA global y entrega. Leer sus criterios en el ledger; no sustituirlos por este resumen.

Después de implementar todo: auditoría Claude Code CLI sobre app y docs completos, corregir/verificar y entregar commits/push/PR. No ejecutarla ahora como si la implementación estuviera terminada.

## Decisiones del usuario y coordinación

El usuario prueba VPS/cron/workers y smokes reales Bluesky/Threads; no volver a pedir SSH. Claude Desktop queda aplazado. Las autorizaciones previas de herramientas persisten. No comprar planes ni publicar en redes, desplegar o mergear automáticamente.

Root mantiene docs/ledger y el slot pesado. Cada agente sólo sus archivos; no revertir trabajo concurrente. TDD, revisión fresca y verificaciones apropiadas; sin reruns generales sin cambio/fallo nuevo. Ningún Jira externo habilitado. Conocimiento sólo en candidatos; no promover sin curador. No escribir memorias personales sin petición explícita.

## Historia preservada

El [checkpoint previo completo](docs/roadmap/2026-11-03-creative-social-suite/continuation-history/2026-11-07-before-p2-native-qa.txt) queda archivado por bytes. Sus pasos antiguos de “siguiente” no son instrucciones vigentes. El ledger y los recibos citados preservan la evidencia detallada.
