# Revisión T-77 · intento 2 de 3 · revisión estática

**Veredicto:** todavía no se puede cerrar. La revisión cierra todos los puntos de R1, incluido el Important condicional B-1. Sin embargo, el `PostPlanner.php` completo, que ahora se adjunta, muestra un defecto Important nuevo en la interacción entre el reinicio de `privacy_level` y la regla de contenido de marca de TikTok (§B-4). Además siguen pendientes la suite global y MySQL sobre este diff. No he ejecutado nada: la evidencia de QA se toma como aportada.

**Por qué abro un punto en una zona que R1 aprobó:** R1 dio por bueno el tratamiento de TikTok sin conocer `optionErrors()`. Solo tenía las líneas 250-395 del planner. El planner completo es evidencia nueva. No reabro la política de reiniciar la visibilidad; señalo un efecto lateral que contradice el contrato.

---

## Reevaluación de los puntos de R1

| ID | Estado | Comprobación estática |
|---|---|---|
| **B-1** | **Cerrado** | `PostDuplicator.php:50` reinicia `instagram_audio` solo entre marcas. Dentro de la misma marca, `:41` fuerza la misma cuenta, así que conservar el audio es correcto. El catálogo y las fuentes adjuntas son coherentes: `location_id`, `place`, `collaborators` y `user_tags` son contenido público, sin identidad de la cuenta publicadora. LinkedIn obtiene el autor de `meta`. `LinkedInPostOptions.php:60` rechaza `CONNECTIONS` en una página y el test de `:128` lo cubre. YouTube no tiene opción de playlist. El cierre se apoya en el catálogo declarado para los publicadores de TikTok, Threads, Bluesky y YouTube, cuyo código no se adjunta. No veo motivo para dudar de él. |
| B-2 | Cerrado | `PostController.php:103` usa ya un texto neutro. Los avisos de reinicio solo se refieren a destinos conservados (`PostDuplicator.php:56,72`) y el test de `:317` lo cubre. |
| B-3 | Cerrado | `duplicate-post.spec.ts:72`. |
| E-1 | Cerrado | `DuplicatePostResult` es `readonly` y el servicio ya no genera texto de presentación. |
| E-2 | Cerrado | Prefijo compartido en `PostPlanner.php:40,305`. Los campos anidados están cubiertos (`DuplicatePostTest.php:302`). |
| E-3 | Cerrado | Ya no hay prefiltros duplicados. Las incompatibilidades llegan al validador canónico como `targets.N`. |
| E-4 / E-5 / E-6 | Cerrado | `DuplicatePost.vue:84` (clave = campo), `:32` con `errorBag`, `PostController.php:92,99` y `aria-describedby` en `:50`. |
| E-7 | Política no adoptada | Aceptable: no era un defecto. |
| E-8 | Cerrado | `DuplicatePostTest.php:80` (valor 42) y `spec.ts:85` (`original_metrics: 1`). |
| Throttle | Política | La explicación es coherente: los borradores ya llevan `Limit::none`. No es un defecto. |

**Índices.** He comprobado la correspondencia de índices entre `$resetByTarget`, `$data['targets']` y las claves de error:

- `$data['targets']` es una lista construida con `[]`.
- `plan()` aplica `array_values`, de modo que `targets.N` del validador coincide con la posición en `$data['targets']`.
- `unset($resetByTarget[$index])` en `:72` elimina el aviso de un destino omitido.

Es correcto.

---

## A. Aceptación

Se cumple el contrato salvo en un caso: un destino TikTok de publicación directa con `brand_content_toggle` no se conserva (§B-4). Esto contradice dos puntos:

- «privacidad de TikTok siempre nueva; vídeo y texto conservados»;
- «omisión explícita de destinos **incompatibles**»: el destino es compatible y lo invalida el propio duplicador.

Gates pendientes, que no doy por cerrados con este análisis estático:

- suite PHP global sobre este diff;
- MySQL sobre este diff;
- integración en main, que sigue en T76.

## B. Corrección

### B-4 · Important (nuevo) — el reinicio de `privacy_level` descarta los destinos TikTok con contenido de marca

**Ubicación:** `app/Services/Posts/PostDuplicator.php:51,58`, en combinación con `app/Services/Posts/PostPlanner.php:175`.

**Mecanismo:** el duplicador elimina `privacy_level` en todo destino TikTok. Después, `optionErrors()` evalúa:

```php
$asked('brand_content_toggle') && $direct && in_array($options['privacy_level'] ?? 'SELF_ONLY', ['SELF_ONLY', null, ''], true)
```

Sin `privacy_level`, el valor por defecto `'SELF_ONLY'` hace que la regla falle siempre. El resultado es un error `targets.N`, y en `:71-72` el destino se omite y se borra su aviso de reinicio.

**Escenario reproducible:**

1. Cuenta TikTok con `meta.direct_post = true`.
2. Publicación de vídeo con `options = ['privacy_level' => 'PUBLIC_TO_EVERYONE', 'brand_content_toggle' => true]`, texto propio y media propia.
3. Se duplica dentro de la misma marca.

**Resultado:**

- la copia no tiene destino TikTok;
- el vídeo, el texto propio y las demás opciones del destino se pierden;
- el aviso dice «1 destino sin copiar: sin una cuenta compatible inequívoca o con contenido u opciones que ya no se admiten»;
- no aparece «Elige de nuevo la visibilidad de TikTok».

La cuenta es la misma y el destino es válido. Es determinista para cualquier publicación de contenido de marca en TikTok, y entre marcas ocurre lo mismo.

**Impacto:** se pierde contenido de forma silenciosa, con una explicación incorrecta, justo en publicaciones patrocinadas, que necesitan la divulgación.

**Corrección.** Hay dos opciones:

- **(a) Preferida:** en `PostPlanner.php:175`, evaluar la regla solo si hay visibilidad elegida (`filled($options['privacy_level'] ?? null) && $options['privacy_level'] === 'SELF_ONLY'`). Es seguro porque `:358` ya obliga a elegir visibilidad antes de programar o publicar en cuentas directas, así que la regla sigue protegiendo toda programación. Conserva la divulgación y es coherente con «un borrador puede guardarse sin ella». Como toca el validador canónico, exige pruebas de regresión del compositor:
  - borrador con `brand_content_toggle` y sin visibilidad: aceptado;
  - programación con `SELF_ONLY`: rechazada;
  - programación sin visibilidad: rechazada.
- **(b) Alternativa:** que el duplicador reinicie también `brand_content_toggle` y avise de ello. Es más local, pero obliga a volver a declarar la colaboración pagada y existe el riesgo de olvidarla. Elegir entre (a) y (b) es política. Dejar el defecto como está no es una opción aceptable.

**Prueba de cobertura:** añadir a `DuplicatePostTest.php` un caso TikTok directo con `brand_content_toggle` que compruebe:

- se conserva el destino con su vídeo y su texto;
- no se copia `privacy_level`;
- el aviso incluye «visibilidad de TikTok»;
- no aparece «1 destino».

Con la opción (b), comprobar además el aviso del toggle.

### Observaciones sin hallazgo

- `targetErrorIndex` (`PostPlanner.php:47-48`). La precedencia es `(A && B) ? x : null`, que es correcta, y `$match` no se lee cuando el cortocircuito evita el `preg_match`. Las claves `targets` o `media` lanzan excepción, como deben.
- El reinicio de Instagram no afecta a otras reglas dependientes, porque `selectionErrors(null)` devuelve `[]`.

## C. Seguridad introducida

No hay hallazgos accionables nuevos.

- Mover los errores al bag `duplicate` reduce la exposición a la página.
- `validateWithBag` mantiene la validación estricta de `brand_id`.
- La propiedad se sigue comprobando en dos capas: Policy y `UserScope` en el controlador, `Ownership::assertAll` en el servicio.

## D. Rendimiento

Sin cambios respecto a R1, y sin hallazgos: se ejecutan dos pasadas de `plan()`, sin colas ni copias físicas. Las colecciones del bucle de reinicio no añaden coste apreciable.

## E. Calidad del código

### E-9 · Minor — redacción del aviso

**Ubicación:** `PostController.php:105-107`

**Escenario:** se generan las frases «Elige de nuevo audio de Instagram.» y «Elige de nuevo visibilidad de TikTok.», sin artículo. La alternativa genérica («las opciones de la cuenta») sí lo lleva, así que el texto es incoherente.

**Corrección:** incluir el artículo en las etiquetas (`'el audio de Instagram'`, `'la visibilidad de TikTok'`). El test de `:298` usa `str_contains('audio de Instagram')` y seguiría pasando.

### E-10 · Minor (documentación) — repetición y orden

**Ubicación:** `docs/usuario/duplicar.md:17` y `:21`

**Escenario:** la frase sobre la visibilidad de TikTok aparece dos veces. Además, el párrafo final sobre el audio de Instagram queda después del párrafo de propiedad y colaboradores, fuera de su sección lógica.

**Corrección:** fusionar ese párrafo en «Al cambiar de marca» y eliminar la repetición. Si se adopta B-4(a), añadir que la indicación de contenido de marca se conserva.

### E-11 · Nit (legibilidad)

**Ubicación:** `PostPlanner.php:47-48`

Añadir paréntesis explícitos alrededor de la condición del ternario mejoraría la lectura. El comportamiento es correcto.

### E-12 · Nit

**Ubicación:** `DuplicatePost.vue:39`

`onSuccess` es redundante, porque un id nuevo provoca que se remonte el componente. No hace daño; se puede quitar o conservar como defensa.

---

## Resumen

| ID | Severidad | Ubicación | Bloquea el cierre |
|---|---|---|---|
| **B-4** | **Important** | `PostDuplicator.php:51,58` + `PostPlanner.php:175` | **Sí** |
| E-9 | Minor | `PostController.php:105-107` | No |
| E-10 | Minor (docs) | `docs/usuario/duplicar.md:17,21` | No |
| E-11, E-12 | Nit | indicadas | No |
| E-7, throttle | Política aceptada | — | No |

**Para cerrar hace falta:**

1. corregir B-4 con su test de regresión, y con los tests del compositor si se elige la opción (a);
2. que la suite PHP global y MySQL pasen sobre el diff final;
3. repetir el navegador si cambia la UI o la documentación enlazada.

Fuera de B-4, los puntos de R1 están resueltos y no reabro ninguno.