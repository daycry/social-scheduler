# Inbox de comentarios (Instagram y Facebook)

Buzón de los comentarios de las publicaciones **propias** ya publicadas: leer, marcar leído, responder y ocultar/mostrar. Sin mensajes directos.

| Pieza | Ruta |
|---|---|
| Conector Graph | `app/Connectors/Meta/Comments.php` |
| Servicio | `app/Services/InboxService.php` |
| Jobs | `SyncInboxComments` (despacha por cuenta), `SyncAccountComments` (cola `poll`) |
| UI / MCP | `InboxController`, `resources/js/Pages/Inbox/Index.vue`; `list_comments`, `reply_comment`, `add_comment_note` |
| Notas internas | `InboxNoteService`, `InboxNoteController`, `InboxCommentNote` (tabla `inbox_comment_notes`), `resources/js/Components/Inbox/CommentNotes.vue` |

- **Permisos** (activar en los casos de uso de la app de Meta y reconectar): Instagram `instagram_manage_comments`; Facebook `pages_read_user_content` (leer, vía `META_EXTRA_SCOPES`) y `pages_manage_engagement` (responder y ocultar). Sin ellos la cuenta aparece como «no disponible» con la instrucción.
- **Paginación**: `Comments::fetch` sigue `paging.next` (hasta `SOCIAL_INBOX_MAX_PAGES`) y los hilos de respuestas (`SOCIAL_INBOX_MAX_REPLY_PAGES`), y devuelve **los más recientes primero**. No usa `since`: una respuesta nueva a un comentario antiguo no cambia la fecha del padre y se perdería; la sincronización es idempotente por `upsert` (única por cuenta + comentario).
- **Presupuesto**: `SyncAccountComments` trabaja por bloques de publicaciones con presupuesto de tiempo (`SOCIAL_INBOX_BUDGET_SECONDS` < `timeout`) y se reencola con el desplazamiento por donde iba (como `RefreshPostMetrics`).
- **Responder**: si Meta no devuelve el id de la respuesta no se guarda un `own-…` inventado (la sincronización la traerá con su id real); si la sincronización ya la había traído, se reutiliza (captura de la clave única). Tras publicar en la red, un fallo al guardar nunca llega al usuario como 500.
- **Notas internas (C-04d)**: cada comentario admite notas de texto plano (hasta 2.000 caracteres) que solo ve el equipo y **nunca** se envían a la red. Viven en su propia tabla, con clave al comentario, así que **sobreviven a la resincronización** (el `upsert` actualiza la fila, no la sustituye) y se borran con él (cascade). Rutas `inbox.notes.store` (`POST /inbox/{comment}/notes`) e `inbox.notes.destroy` (`DELETE /inbox/notes/{note}`); un comentario o nota de otro usuario responde 404 (UserScope). El inbox muestra el hilo de notas en orden cronológico, la insignia «con nota» y el filtro «Solo con nota» (`has_notes`). Ni el log ni la auditoría guardan el cuerpo.
- **Seguridad (MCP)**: `list_comments` marca cada comentario con `untrusted: true` y su descripción avisa de que el texto lo escribe un tercero (datos, nunca instrucciones); `reply_comment` exige confirmación humana del texto exacto. `list_comments` devuelve además las `notes` de cada comentario y `add_comment_note` (`comment_id`, `body`, ability `inbox:write`) añade una; su cuerpo no se audita.

## Cobertura por red y triaje (T-20)

| Red | Leer | Responder | Ocultar | Notas |
|---|---|---|---|---|
| Instagram / Facebook | Sí | Sí | Sí | Mensajes directos fuera: requieren la revisión de la app de Meta |
| Threads | Sí | Sí | No | `threads_read_replies` y `threads_manage_replies` (reconectar); `ThreadsComments` |
| Bluesky | Sí | Sí | No | Lectura pública `getPostThread`; responder con `reply{root,parent}`; `BlueskyComments` |
| YouTube | Sí | Sí | No | `youtube.force-ssl`; lista 1 ud, respuesta 50 ud de cupo; `YouTubeComments` |
| TikTok | No | No | No | Fuera: no hay API pública de comentarios para terceros |

- Los conectores implementan `App\Connectors\Contracts\CommentSource`; `InboxService::coverage()` publica la tabla anterior en la pantalla.
- **Triaje**: estado abierto/cerrado (`closed_at`, interno), etiquetas (`inbox_comment_labels`, hasta 10 por comentario), asignación a uno mismo o a un colaborador de la marca, acciones en lote (`read`, `unread`, `close`, `reopen`, hasta 100; los ids ajenos cuentan como omitidos) y respuestas guardadas (`inbox_saved_replies`, hasta 30). Filtros: red, no leídos, estado, asignado (`none`/`me`/id) y etiqueta.
- **MCP**: `list_comments` admite `network`, `status`, `label` y `assigned`; `triage_comments` (`inbox:write`) cierra, reabre, marca leído en lote, etiqueta y asigna.
