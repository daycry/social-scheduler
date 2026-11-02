---
proposed_by: implementer
estado: propuesta
category: GOTCHA
evidencia: validated_case
fuentes:
  - app/Connectors/Meta/Instagram/InstagramAudio.php
  - tests/Feature/Instagram/InstagramAudioTest.php
  - tests/Feature/Mcp/Tools/McpInstagramAudioTest.php
  - docs/roadmap/2026-10-31-metricool-composer-parity/tasks.md (T-10)
tags:
  - area:connectors
  - feature:instagram
  - iniciativa:2026-10-31-metricool-composer-parity
---

# ig_audio puede devolver un cursor sin paging.next

## Síntoma

Las búsquedas muestran solo la primera página. Una consulta real por artista devolvió 25 pistas aunque había otra página con 25 pistas distintas.

## Causa raíz

El servicio descartaba `paging` y solo devolvía `results`. Las respuestas reales de `ig_audio` incluían `paging.cursors.after` sin la URL `paging.next`. Las búsquedas iniciales usadas para diagnosticar el problema no devolvían cursores; no cubrían el comportamiento general del endpoint.

## Qué hacer en su lugar

Conservar cada cursor `after` por tipo de audio y continuar contra el mismo endpoint verificado. No depender de `paging.next` ni seguir su URL. Ofrecer un cursor de aplicación cifrado, ligado a cuenta, consulta, filtro y caducidad. En Todos, continuar únicamente los tipos no agotados. Mostrar carga explícita de más resultados, deduplicar por ID y reiniciar la paginación al cambiar la búsqueda.

## Evidencia

`InstagramAudioTest::cursor-only pagination keeps both catalog positions and never follows provider URLs`: RED por next_cursor ausente; GREEN con tres llamadas para dos páginas de dos tipos cuando uno se agota. `McpInstagramAudioTest::agent can request the next audio page with the same search` verifica el mismo contrato. La prueba real corregida recuperó 25+25=50 pistas distintas usando el cursor de nuestro servicio. Las pruebas E2E cubren carga adicional, reintento, filtros y respuestas atrasadas. Detalles en T-10 y `testing/search-pagination/`. Propuesta pendiente de knowledge-curator; no se escribe en approved.
