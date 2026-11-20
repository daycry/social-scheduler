Modelo confirmado por modelUsage: claude-opus-5-5.

Recibo original: metricool-t68-unmount-followup-r14-opus55.json

SHA256 del recibo: ea1d9f811edfc0daaa02000890eb4a62f589258b3e602a4a6f8417373a415ffd

**Veredicto: aceptado. Sin defectos Critical ni Important.**

**Por qué cumple**

- **Ventana de `play`:** `leaving` se marca antes del clic. Con `>=`, cualquier `play`, `playing` o `call:play` que ocurra durante la navegación o la cancelación hace fallar la prueba. El fixture lo demuestra con un `play` en t=10 que es rechazado.
- **Ventana de draw:** `closed` se marca solo después de cuatro condiciones: URL de listado, modal ausente, heading visible y textbox del diseño ausente. Así se tolera un frame pintado por el editor aún vivo (t=10) y se rechaza un frame en el timestamp igual (t=20).
- **Orden causal:** la respuesta sigue retenida hasta después de `mark`. Los asserts del fixture (`released === false` en cada `mark`, `marked === true` en `release`) prueban ese orden sobre el bloque real transpilado, no sobre una copia.
- **`deliverySettled`:** está en `finally`, así que se resuelve tanto si `fulfill` tiene éxito como si se cancela el transporte. Después vienen silencio, pausa y evidencia, en ese orden.
- Los observadores no cambian y no se borra ningún evento.

**Observaciones menores (no bloquean)**

1. `deliverySettled` se resuelve una sola vez. Si la ruta `**/assets/*/file` atiende varias peticiones, solo se espera la primera. El `quietFor(1200)` lo mitiga. Si el flujo puede pedir más de un asset, conviene contar las entregas.
2. El fixture pasa `Promise.resolve()` como `deliverySettled`, así que no verifica que se espere antes de `quietFor`. Es una cobertura aceptable dado lo simple de esa línea.
3. La extracción depende de la indentación exacta y del comentario ancla. Si el bloque se reformatea, el `assert.ok` falla de forma explícita, que es el modo de fallo correcto.

**Pendiente:** la ejecución en navegador de las dos variantes (`close` y `design-unmount`) dentro de la suite serial E2E-29. El cierre no queda confirmado hasta ver esa ejecución en verde.
