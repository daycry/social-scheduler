Modelo confirmado por modelUsage: claude-opus-5-5.

Recibo original: metricool-t68-test-followup-r12-opus55.json

SHA256 del recibo: a786ec57d41e0cd3915d0ed74cf9a8f469c7a7c08ffa8986e432df3eff5c8ade

# Revisión R12: tests de confirmación

**Veredicto: aceptado.** No veo defectos reales en el alcance test-only. Reviso el fichero completo, no solo el hunk, y no he ejecutado nada.

## Lo que queda correcto

- **Primer bucle.** Cada fila sigue generando un único test con el mismo flujo de antes, ahora con la aserción previa `requests === []`. Las filas de `clearMessages` y `clearSubscribers` no se pierden: pasan al bucle escalar con sus componentes reales. `revokeAll` apunta ahora a `SiteAccessPanel.vue` con `otherProps: {}`, lo que es coherente con el movimiento de T64. No hay pérdida de cobertura.
- **El test escalar distingue de verdad entre implementaciones.** `props` es el mismo `reactive(initialProps)` que recibe `setup`. Por tanto, si el handler leyera `props.siteId` o `props.linkId` después del `await ask`, obtendría 9 y el test fallaría. Con `const target = route(...)` antes del `await` obtiene 8. La aserción `panel.state.props[prop] === 9` confirma que el cambio llegó a la vista del componente y descarta un falso verde por mutar un objeto distinto.
- **Cancelación.** Comprueba que no hay peticiones mientras la confirmación está pendiente, y tampoco después de `resolve(false)`. El `answer.resolve(false)` de `t.after` sobre una promesa ya resuelta no tiene efecto, así que no interfiere.
- **Nombres de test.** Son únicos, porque `routeName` no se repite entre filas ni entre bucles.

## Observaciones menores (no bloqueantes)

1. **`panel.state.props[prop]` depende de un detalle interno.** Funciona porque el SFC declara `const props = defineProps(...)` y `compileScript`, en modo no inline, expone ese binding. Si alguien desestructura las props o renombra la variable, el test fallará con un `TypeError` poco claro en lugar de una aserción legible. Se puede aceptar tal cual. Una alternativa es `assert.ok(panel.state.props, '...')` antes de la comparación, o eliminar la aserción, porque la reactividad ya está garantizada por el arnés.
2. **El test de cancelación no comprueba el estado de la UI** (busy o feedback) tras cancelar, a diferencia del test de Studio. Solo hace falta si esos componentes tienen ese estado; si no lo tienen, está bien así.
3. **Cosmético:** hay una línea en blanco doble antes del segundo bucle.

## Fuera de alcance (solo como nota)

Al salir de `Pages/Sites/Edit.vue` y `Pages/SmartLinks/Index.vue`, ningún test comprueba que los padres pasan `siteId` y `linkId` correctos a los hijos. Es cableado de plantilla y estos tests de `setup` no lo cubren. No es un defecto de R12.
