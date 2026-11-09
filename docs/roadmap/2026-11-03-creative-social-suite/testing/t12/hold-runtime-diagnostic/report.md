# T12: ruta del hold y cancelación del consumidor

Diagnóstico real3/3, sin aceptación de la aplicación ni de noLateApply. Chromium ejecutó las declaraciones reales de registry/parser; frontend y hook originales sirvieron289bytes de fixture mediante dos puertos propios de loopback.

| Condición | Petición real | Resultado del consumidor | Transporte / release |
|---|---|---|---|
| Hold armado/table | GET/export?format=json&revision=1 | Resuelve; segunda lectura usa caché | Sin captura del hold; un solo GET |
| Hold correcto/export, cancelar única espera | Mismo GET | AbortError | Sin requestfailed; release con cancelled_delivery=false; caché completada y consumidor original sigue rechazado |
| Hold correcto/export, dispose del registry | Mismo GET | AbortError | ERR_ABORTED real; client-cancelled y release con cancelled_delivery=true |

Cada condición originó exactamente un GET con query observada en el backend. Captura y release preservan289bytes/SHA5f1de6c41cb5f63b6e94feb9f59edbe0aba82f90b71f165efc4edeaf4e727054. No se simuló éxito HTTP ni se cambió el matcher del hook. La extracción AST sólo retiró imports/exports y mantuvo los cuerpos utilizados; las funciones de preparación geométrica no se invocaron.

La prueba confirma que el test original armaba otra ruta y confundía cancelar un consumidor con disponer el recurso compartido. El contrato C2 actual mantiene vivo el fetch aunque se cancele su última espera. La revisión del harness deberá preservar esa política y verificar cierre/foco/noLateApply en UI, con respuesta real terminada y caché comprobada. Este diagnóstico no sustituye esas verificaciones.

Duración1,0371s, pico COMMIT227221504B, mínimo físico7689179136B; Job3GiB/preflight6/piso3/100ms/90s, Node512.16fuentes iguales antes/después. Browser cerrado; frontend62974/backend62973 cerrados y rebind verificado dentro de la sonda. Supervisor34048/nodo25908 terminales. Sin Laravel, PHP, DB, build,.env o red externa. Los tres casos y sus recibos quedan inmutables.
