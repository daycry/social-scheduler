# Respuesta a B-1 y mapa de cambios de R1

| Red | Opciones consumidas / identidad | Tratamiento al duplicar |
|---|---|---|
| Instagram | share_to_feed, colaboradores por username, is_ai_generated, location_id de lugar y user_tags username/coordenadas son contenido. instagram_audio contiene audio_id y volúmenes del catálogo consultado con user_id de la cuenta autorizada | Se reinicia instagram_audio al cambiar de cuenta/marca, conservando vídeo y texto; aviso explícito. Misma cuenta conserva selección. |
| Facebook | facebook_title y place de página de lugar (búsqueda pages/search); no id de cuenta publicadora en opciones | Validación canónica de tipo/formato; cuenta destino explícita. |
| YouTube | title, privacy, category_id global, tags, made_for_kids, shorts_hashtag, contains_synthetic_media, notify_subscribers | No existe opción de playlist. La playlist de subidas se calcula desde el canal de la cuenta en el lector, no viaja en las opciones. |
| LinkedIn | linkedin_visibility y linkedin_disable_reshare | CONNECTIONS se rechaza en una cuenta destino de tipo página. author_urn procede de account.meta, no de options. |
| Pinterest | pinterest_board_id, pinterest_title, pinterest_link | Board vinculado a cuenta: destino omitido entre marcas, no se elige primer tablero. |
| TikTok | privacy_level, disable_comment/duet/stitch, brand_organic_toggle, brand_content_toggle, is_aigc, auto_add_music | privacy_level reiniciada por publicación; flags revalidados con capacidades de cuenta/type. |
| Threads / Bluesky | Publicador no consume identificadores de recursos propios desde options | Identidad procede de la cuenta seleccionada. |

Google Business no está entre los ocho casos de Network. No se afirma que lugares/usuarios públicos pertenezcan a la cuenta origen: son contenido; la identidad publicadora nunca procede de esos campos. El caso account-contextual adicional detectado es el audio nativo de Instagram: selectionErrors solo verifica forma/disponibilidad de la cuenta, y el publicador vuelve a consultar la pista con la cuenta destino. No se presupone que el catálogo permitido sea idéntico entre cuentas; se pide elegir de nuevo sin consultar proveedor durante el duplicado.

RED r28 reprodujo audio heredado entre cuentas, bag global y aborto por targets.0.media. GREEN r29. RED r31 descubrió aviso de audio para un destino que luego se omitía; ahora los resets se calculan solo sobre destinos retenidos. GREEN focal final56/459 (duplicado17casos incluidos), Posts+Library224/1204. Nuevo navegador6/6: Duplicar habilitado tras guardar y snapshot original count1 tras edición de copia; PHP comprueba valor42. Node789, TS0, estilo/build r30.

| ID R1 | Respuesta |
|---|---|
| B1 | Confirmado caso contextual InstagramAudio y corregido con reset+aviso+test. Resto del catálogo y fuentes completas adjuntos. Espera revalidación del revisor. |
| B2 | Aviso neutro incluye contenido/opciones; los resets solo avisan de destinos retenidos. |
| B3 | E2E comprueba botón enabled tras guardar; pasa en3anchos sin cambiar useComposer (useForm ya restablece defaults). |
| E1 | DuplicatePostResult readonly(post,omitted,resetOptions), sin parámetro de salida ni texto de UI en servicio. |
| E2 | PostPlanner es dueño de TARGET_ERROR_PREFIX y targetErrorIndex para namespace targets.N y campos anidados; mismo prefijo al producir errores. Test de consumidor real con error anidado. |
| E3 | Quitadas comprobaciones de red/oferta repetidas; se delegan al validador canónico. |
| E4 | Vue Object.entries(form.errors), key=campo. |
| E5 | form.post errorBagduplicate, validateWithBagduplicate y withErrorsduplicate para errores de servicio. |
| E6 | Motivo dirty enlazado con aria-describedby. |
| E7 | Sugerencia opcional de warning no adoptada: success existente contiene el aviso y se verifica visible; no se amplía el sistema global de flash. No era defecto. |
| E8 | PHP conserva snapshot fuente42; E2E comprueba count1 después de editar/guardar copia. |
| Throttle opcional | El alta lleva middleware publish-now, pero AppServiceProvider214-216 devuelve Limit::none para guardar borrador/sin publish_now. Duplicado fuerza schedule/publish_now=false. Mantiene la política existente de borradores, sin publicar ni saltar cuota de publicación. |

Sin veredicto de R2 todavía. No integración/cierre. La suite global del nuevo diff se ejecutará tras la revisión; el verde4334/9 de R1 corresponde al padre del fix, no se atribuye al nuevo diff.
