---
proposed_by: architect
category: DECISION
evidencia: human_confirmed_rule
fuentes:
  - docs/roadmap/2026-10-24-metricool-parity-2/design.md (opción A1, validada por el usuario el 2026-10-24)
  - docs/roadmap/2026-10-24-metricool-parity-2/spec.md (§Decisiones de diseño, primer comentario)
  - app/Services/PublishOutcomes.php:38
  - app/Jobs/PublishPostTarget.php (claim y findPublished)
tags:
  - area:publicacion
  - feature:primer-comentario
  - iniciativa:2026-10-24-metricool-parity-2
---

# Primer comentario: estado en columnas de `post_targets` y job `PostFirstComment` aparte, con reclamo propio y búsqueda antes de comentar

## Contexto

El primer comentario (IG, FB y YT) es un efecto externo que va después de publicar. No debe duplicarse si el job se repite, ni degradar un destino ya publicado. Había que decidir dónde vive su estado y cómo se ejecuta sin tocar el núcleo de publicación (ADR-005).

## Decisión

- El estado vive en columnas nulas de `post_targets`: `first_comment`, `first_comment_status`, `first_comment_attempts`, `first_comment_external_id`, `first_comment_error` y `first_comment_at`. Lo modela el enum `FirstCommentStatus`, con los estados `pendiente`, `comentando`, `publicado`, `fallido` y `no_admitido`, independiente de `PostStatus`.
- `PublishOutcomes::published()` es el único punto de despacho, con `DB::afterCommit`.
- El job `PostFirstComment` es `ShouldBeUnique` y reclama la fila con `lockForUpdate`. Siempre llama a `FirstCommenter::findOwnComment()` antes de `comment()`.
- `PublishRecovery` retoma los comentarios colgados.
- Un fallo deja el comentario en `fallido` con un aviso in-app; el destino sigue `Published`.
- El usuario puede editar el texto de un comentario `fallido` antes de Reintentar. Es la única edición permitida en un destino publicado.

## Alternativas descartadas

- **Tabla aparte `post_target_comments`.** Obliga a cargar la relación en `plan()`/`fill()`, `GetPost`, la biblioteca y las autolistas, con riesgo de perder el comentario sin aviso. Solo compensaría con varios comentarios por destino, y eso está fuera de alcance.
- **Estado en `post_targets.progress` y comentar dentro de la pasada de publicación.** `published()`, `processing()` y `PublishPostTarget` sobrescriben `progress`. Además, mete efectos del comentario en el núcleo de publicación.

## Consecuencias

`post_targets` crece en 6 columnas aditivas, y la función se quita borrando el despacho en `published()`. Un cambio de `first_comment` no reinicia el intento de publicación (`fill()` no llama a `freshAttempt`). Varios comentarios por destino exigirían migrar a una tabla.
