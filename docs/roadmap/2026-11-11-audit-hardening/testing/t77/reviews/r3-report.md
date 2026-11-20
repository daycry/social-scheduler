# Revisión T-77 · intento 3 de 3 · revisión estática

**Veredicto:** no hay defectos Critical ni Important abiertos contra el contrato aprobado. B-4 de R2 queda cerrado en el análisis estático, y también E-9, E-10 y E-11. E-12 queda aceptado como código defensivo. Solo quedan dos datos faltantes de severidad baja y dos Nits, ninguno bloqueante.

**El cierre no es definitivo.** Faltan las pruebas que se están ejecutando ahora: suite PHP global, MySQL y navegador sobre el diff final. Tampoco está hecha la integración sobre main, que sigue en T-76. No he ejecutado nada. Las cifras r36, r38 y 229/1221 se toman como aportadas, no como verificadas.

No reabro ningún punto de R1. Tampoco reabro políticas ya aceptadas: E-7, el throttle y el reinicio de visibilidad por publicación.

---

## Reevaluación de los puntos de R2

| ID | Estado | Comprobación estática |
|---|---|---|
| **B-4** | **Cerrado** | Detalle en el apartado siguiente. |
| E-9 | Cerrado | `PostController.php:105` lleva las etiquetas con artículo. Los tests de `DuplicatePostTest.php:219,302` siguen buscando subcadenas compatibles. |
| E-10 | Cerrado | `duplicar.md:17`: la frase sobre TikTok aparece una sola vez y el audio de Instagram está en «Al cambiar de marca». Se documenta que el contenido de marca se conserva y que hay que elegir una visibilidad compatible antes de programar. |
| E-11 | Cerrado | `PostPlanner.php:47-48` lleva paréntesis explícitos. |
| E-12 | Aceptado | `DuplicatePost.vue:39` es redundante pero inocuo. No tiene un defecto demostrado. |

### B-4 · detalle del cierre (opción a)

**Cambio:** `PostPlanner.php:175` solo rechaza `brand_content_toggle` cuando `privacy_level === 'SELF_ONLY'`.

**Las cuatro combinaciones relevantes, en cuenta TikTok directa:**

| Caso | Regla que actúa | Resultado |
|---|---|---|
| Borrador con contenido de marca y sin visibilidad (`null` o `''`) | Ninguna: `:175` no aplica y `:358` exige `$schedule` | Aceptado. Es el objetivo del fix. |
| Programar o publicar ya, sin visibilidad | `:358` (`$schedule` incluye `publish_now`, `:263`) | Rechazado |
| Programar con `SELF_ONLY` | `:175` | Rechazado |
| Borrador con `SELF_ONLY` explícito | `:175` | Rechazado |

El último caso es coherente con «una elección privada no admite contenido de marca».

**Cuenta no directa:** la protección no se debilita. El bucle de `:160-164` sigue rechazando `brand_content_toggle` en cuentas no directas, con o sin visibilidad.

**Duplicador:** `PostDuplicator.php:51,58` retira `privacy_level` y el planner ya no invalida el destino. Por tanto se conservan el vídeo, el texto propio y el toggle, y `$resetByTarget` no se borra en `:72`. Así, el aviso dice «Elige de nuevo la visibilidad de TikTok» y no «1 destino sin copiar».

**Cobertura:**

- `DuplicatePostTest.php:200-221` cubre los dos datasets (con y sin contenido de marca) y comprueba que el aviso no contiene «sin copiar». Es exactamente el escenario de R2.
- `:341-363` cubre la matriz del validador canónico.
- El RED de r36 (draft rechazado y destino inexistente) es coherente con el defecto descrito.

**Alcance del cambio en el validador:** la relajación solo afecta a planes con `schedule=false`. Para cualquier ruta que pase por `plan()` con programación (web, API, MCP, `publishNow` en `PostService.php:272`), la protección es equivalente a la anterior: `:358` cubre el caso «falta visibilidad» y `:175` cubre «privado».

---

## A. Aceptación

Cada punto del contrato y su estado:

| Contrato | Estado | Evidencia |
|---|---|---|
| Copia propia como borrador, sin fecha, historial ni métricas | ✔ | Sin cambios respecto a R1 y R2 |
| Cuentas inequívocas | ✔ | `PostDuplicator.php:37-46` |
| Contenido, media y opciones compatibles | ✔ | — |
| Omisiones explícitas | ✔ | — |
| Privacidad de TikTok nueva en cada publicación | ✔ | `:51` |
| Audio de Instagram por cuenta | ✔ | `:50` |
| **Contenido patrocinado conservado** | ✔ | Ya se cumple con el fix de B-4 |
| UI: estado sin guardar, bolsa de errores, guardado y doble envío | ✔ | Cerrado en R1 y R2 |

**Gates pendientes, no superados por esta revisión:**

- PHP global sobre el diff final. El 4337/9 previo no se atribuye a este diff.
- MySQL.
- Navegador. El diff cambia el mensaje del controlador y la documentación enlazada.
- Integración sobre main.

**Riesgo concreto para esas pruebas:** el cambio en `:175` modifica el validador canónico. Si fuera de `tests/Feature/Posts` y `Library` (por ejemplo en MCP, API o conectores de TikTok) existe un test que esperaba rechazar un borrador con contenido de marca y sin visibilidad, fallará. Si ocurre, el fallo se debe a ese test antiguo y no a una regresión, salvo que el test describa un contrato distinto.

## B. Corrección

### B-5 · Dato faltante (Minor) — rutas que programan sin pasar por `plan()`

**Ubicación:** `app/Services/Posts/PostPlanner.php:175` y `:358`.

**Escenario:** una ruta pasa un borrador a programado o publicado sin volver a llamar a `plan()` con `schedule=true`. Candidatas: aprobar un borrador con `requires_approval`, generar series, mover en el calendario o `fill(..., preserveTargetStatuses: true)`. En ese caso, un destino con contenido de marca y sin visibilidad podría llegar al publicador. Si `TikTokPublisher` usa `SELF_ONLY` por defecto, TikTok rechazaría la publicación y fallaría en el worker.

**Por qué no es Important:** el invariante «sin visibilidad no se programa» ya protegía los borradores sin visibilidad y sin contenido de marca antes de T-77. Si existe esa ruta de bypass, ya existía antes. El fix no crea una clase nueva de fallo; solo amplía el conjunto de borradores que pueden llegar a ella.

**Dato que falta:**

- Si toda transición de borrador a programado vuelve a validar con `plan()`.
- Si `TikTokPublisher` comprueba al publicar que hay contenido de marca con visibilidad no privada.

**Corrección, solo si se confirma el bypass:** añadir una comprobación defensiva en el publicador que devuelva un error permanente y explicativo, en lugar de dejar que lo rechace el proveedor.

### B-6 · Dato faltante (Minor) — regla equivalente en el cliente del compositor

**Ubicación:** el componente de opciones de TikTok del compositor (no adjunto), en relación con `PostPlanner.php:175`.

**Escenario:** si el panel de TikTok duplica la regla antigua (vacío = privado), al abrir el borrador duplicado con contenido de marca y sin visibilidad el compositor mostraría un error falso. En el peor caso impediría «Guardar borrador», justo en el flujo que el fix habilita.

**Atenuante:** la validación en vivo usa el servidor (`validatePost`, `PostController.php:198-206`), que ya está corregido. El E2E solo cubre Facebook.

**Dato que falta:** si existe lógica de cliente para `brand_content_toggle` y `privacy_level`.

**Corrección, si existe:** alinearla con el servidor. Opcionalmente, añadir un caso E2E que abra un duplicado de TikTok con contenido de marca y lo guarde como borrador.

### Observaciones sin hallazgo

- **Destino TikTok no directo:** si conservaba un `privacy_level` heredado de cuando la cuenta era directa, el aviso pediría elegir la visibilidad aunque esa opción no se use. Es solo un efecto cosmético sobre datos atípicos y no es un defecto.
- **Visibilidad privada en el origen:** un origen con contenido de marca y `SELF_ONLY` no puede existir, porque ya se rechazaba antes. Además, el duplicador elimina la visibilidad igualmente.

## C. Seguridad introducida

No hay hallazgos.

- El fix no amplía permisos.
- La relajación no permite publicar contenido patrocinado en privado por ninguna ruta que pase por `plan()`.
- La propiedad se sigue comprobando en dos capas: Policy y `UserScope` en el controlador, y `Ownership::assertAll` en el servicio.

## D. Rendimiento

No hay cambios. El fix es una comparación escalar en el validador.

## E. Calidad del código

### E-13 · Nit — falta un caso en la matriz del validador

**Ubicación:** `tests/Feature/Posts/DuplicatePostTest.php:358-363`

**Escenario:** el dataset no incluye un borrador con `SELF_ONLY` explícito. Una regresión que limitara `:175` a `$schedule` pasaría los cuatro casos actuales.

**Corrección:** añadir `'draft private forbidden' => [false, 'SELF_ONLY', false]`. La aserción de `:356` ya cubre el mensaje.

### E-14 · Nit — redacción del CHANGELOG

**Ubicación:** `CHANGELOG.md:10` y `CHANGELOG.es.md:10`

**Escenario:** el texto dice «account-bound choices» / «elecciones ligadas a la cuenta». La visibilidad de TikTok se reinicia por publicación, también en la misma cuenta, como explica `duplicar.md:17`.

**Corrección opcional:** «account-bound and per-publication choices». Es una cuestión de redacción, no un defecto.

---

## Resumen

| ID | Severidad | Ubicación | Bloquea el cierre |
|---|---|---|---|
| B-4 (R2) | — | `PostPlanner.php:175`, `PostDuplicator.php:51,58` | Cerrado |
| E-9, E-10, E-11 | — | indicadas | Cerrados |
| E-12 | Aceptado | `DuplicatePost.vue:39` | No |
| B-5 | Minor (dato faltante, preexistente) | `PostPlanner.php:175,358` / publicador TikTok | No |
| B-6 | Minor (dato faltante) | panel TikTok del compositor | No |
| E-13, E-14 | Nit | indicadas | No |
| E-7, throttle, reinicio de visibilidad | Política aceptada | — | No |

**Para cerrar T-77 hace falta:**

1. Que la suite PHP global y MySQL pasen sobre el diff final. Un fallo atribuible a `:175` fuera de Posts o Library exige revisar si el test antiguo describía otro contrato.
2. Que el navegador pase sobre el diff final.
3. Integrar sobre main una vez cerrado T-76.

Las respuestas a B-5 y B-6 y los Nits pueden resolverse después sin bloquear el cierre.