# T10 — QA integrada no iniciada por capacidad física

2026-11-06. **REFUSED, cero de seis casos ejecutados. T10 sigue abierta.** No hay un fallo funcional de la aplicación demostrado en esta tentativa.

La revisión de preparación A1/B1/D1 encontró un único Important: el informe podía conservar PASS de un recuento anterior si el watchdog abortaba justo después. Se reprodujo con main real y E/S en memoria (seis regresiones: cinco fallos y un control válido), se corrigió y las mismas seis pasaron. A2/B2 frescas verificaron37SHA y cerraron el defecto; D1 no tenía hallazgos. Estas regresiones puras no son los seis casos E2E pendientes.

El supervisor intentó iniciar una sola QA integrada, pero el preflight observó10.086GiB físicos libres (10.829.635.584B), por debajo de11GiB (11.811.160.064B). El recibo declara REFUSED_BEFORE_UUID_OR_SERVER y runtime=null. No se creó UUID, servidor ni runs/attempt1, no hubo ONNX ni reintento. El puerto8080 quedó sin conexiones IPv4/IPv6. El rechazo proviene del guard de capacidad; la revisión automática de autorizaciones no rechazó la acción.

No existen resultadosJSON de Playwright, qa-gate, medidas JobCOMMIT/RSS, PDF ni capturas de esta pasada. El comando observó exit1 sin stdout; se registra esa observación sin sustituirla por el return esperado del código. Los focales Windows anteriores4/4 conservan únicamente su alcance previo.

Root verificó los1378fingerprints del manifiesto aprobado antes de actualizar la documentación:0cambios/0ausentes. SHA del manifiesto f3960812f353319c2dfb74d0746d8f68f8eb11d72c23e89e79d9b2aa50cc9df6; recibo5cc92e5db7d78e4ef570f46900bdaa970373a24f1d1b604de27d999045ffda39. El manifiesto pasa a histórico tras esta actualización administrativa. No reutilizarlo como autorización vigente.

Una próxima ejecución necesita capacidad suficiente y manifiesto actual nuevo, manteniendo Job7GiB agregado/killOnClose/noBreakaway, worker6GiB, preflight11GiB, piso4GiB cada100ms y deadline660s. No reducir protecciones para ejecutar. La UI, raster/export integrado, seis axe y calidad visual aún requieren sus resultados reales. Linux/VPS y revisión subjetiva del usuario siguen pendientes.

Evidencia: [recibo](refusal.json), [comprobación root](root-verification.json), [revisión1](review1.md), [revisión2](review2.md).
