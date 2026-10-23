---
id: ADR-005
titulo: Texto por destino resuelto al publicar y Publicar ahora sobre las defensas existentes del núcleo de publicación
estado: propuesta
fecha: 2026-10-23
iniciativa: 2026-10-23-post-composer
---

# ADR-005: Texto por destino resuelto al publicar y Publicar ahora sobre las defensas existentes del núcleo de publicación

## Contexto

El nuevo compositor permite un texto propio por destino (`post_targets.text` nullable; `null` = vinculado a la plantilla `posts.text`) y la acción Publicar ahora. Las dos cosas tocan el núcleo de publicación:

- La idempotencia de Instagram y Facebook compara el texto publicado con `$request->text` (`InstagramPublisher.php:395-408` y `FacebookPublisher.php:105-128`). Hoy `PublishRequestFactory` pasa siempre `$post->text`.
- Publicar ahora convive con el despachador `dispatch-due-posts`, que corre cada minuto. Hay que garantizar que un destino no se publica dos veces.

Fuente: [`docs/roadmap/2026-10-23-post-composer/design.md`](../../roadmap/2026-10-23-post-composer/design.md), opción O1, validada por el usuario el 2026-10-23.

## Decisión

**El texto efectivo se resuelve al leer.** `PostTarget::effectiveText()` devuelve `text ?? post.text`.

- `PublishRequestFactory::make()` lo pasa como `$request->text`, así que publicar y comparar usan el mismo valor.
- `PostService::fill()` detecta los cambios por destino.
- `freshAttempt()` guarda en `progress.reconcile.text` el texto efectivo **anterior** de ese destino.

**Publicar ahora no cambia `PublishPostTarget`.**

- `PostService::publishNow()` deja los destinos en `programada` con `scheduled_at = now()`.
- Tras el commit (con `afterCommit`, porque la cola tiene `after_commit => false`), encola los destinos vencidos del post con la consulta `dueTargets()` extraída de `DispatchDuePosts`.
- Las defensas contra el doble despacho son las que ya existen: `ShouldBeUnique` y el `claim()` con `lockForUpdate`.

La misma ruta sirve para el compositor, para la acción del post en el calendario y en la lista (`POST posts/{post}/publish-now`) y para el MCP (`publish_post_now`).

## Alternativas descartadas

- **Texto enviado congelado en `post_targets.sent_text`, fijado en `claim()`.** Toca `claim()`, la pieza con más revisiones, para cubrir un hueco que no existe: toda escritura del texto pasa por `update()` y `fill()`. Coste M.
- **Estado nuevo `en_cola`, asignado en la petición.** Recorre la máquina de estados, `PublishRecovery`, la interfaz y el MCP. Es un valor persistido difícil de retirar y desborda la evaluación. Coste L.

## Consecuencias

El contrato `Publisher` y `PublishPostTarget` quedan intactos. La garantía de no duplicar depende de que `fill()` calcule el texto efectivo anterior antes de escribir y de que `schedule()` y `publishNow()` copien `text` al reconstruir los destinos. Lo fijan tests de reintento con un destino desvinculado y una edición entre intentos, y un test con el despacho inmediato y el del minuto a la vez. Durante unos segundos tras Publicar ahora, el destino se ve `programada`, hasta que el worker lo reclama.

## Estado

`propuesta` — a validar por la revisión de dos lentes o el usuario en la puerta. Pasa a `aceptada` cuando se valida; a `obsoleta` si una decisión posterior la reemplaza (enlaza aquí a la que la sustituye, nunca se borra el rastro).
