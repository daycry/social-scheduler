---
estado: propuesta
category: gotcha
project: metricool
scope: testing
source: docs/roadmap/2026-11-03-creative-social-suite/testing/debug-loopback/report.md
confidence: observed
tags: [area:testing, platform:windows, transport:loopback]
---

# El servidor PHP de pruebas y Chromium deben compartir loopback y reutilizar conexiones

**Síntoma:** errores intermitentes `ERR_CONNECTION_REFUSED` y `ERR_ADDRESS_IN_USE` en assets y rutas de aplicación durante Playwright, aunque las aserciones de negocio y la mayoría de peticiones pasan.

**Causa observada:** `php -S localhost:8080` escuchaba solo en IPv6; NetLog mostró un intento IPv4 rechazado. Fijar la familia eliminó ese rechazo, pero PHP cerraba cada respuesta y un recorrido abría 351 sockets, con otro error de dirección en uso. No se ha demostrado agotamiento global de puertos del sistema.

**Qué hacer:** conservar origen/RP ID, fijar el loopback del listener y navegador, y servir assets de prueba mediante un frontend local keep-alive que transmite las rutas de aplicación al PHP aislado. Propagar cortes de streaming; no ocultar console errors ni deshabilitar las guardas de datos.

**Evidencia:** [diagnóstico y resúmenes NetLog saneados](../../../roadmap/2026-11-03-creative-social-suite/testing/debug-loopback/report.md). Veinte assets usan un socket; el recorrido baja a seis y pasa 3/3 repeticiones. Pruebas en `tests/e2e/isolated/http-server.test.cjs`; corte de respuesta RED `1c7a5f` → GREEN `d4605e`, 3/3 incluyendo aislamiento.

Pendiente de curación; no se exporta ni se considera conocimiento aprobado automáticamente.
