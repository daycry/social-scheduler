# Plan de pruebas — Endurecimiento y ampliación tras la auditoría integral

| | |
|---|---|
| **Estado** | borrador |
| **Plan** | [`improvement-plan.md`](improvement-plan.md) |
| **URL local** | http://localhost:8080 (o el puerto libre de `E2E_PORT`, tras E-03) |

> **Reglas de ejecución:** una sola ejecución de Playwright a la vez y nunca con cambios de código en paralelo (GOT-005); sin red externa ni proveedores reales (todo con fakes); sin tocar `.env` ni los datos del usuario 28. Los bloques E2E los ejecuta `qa`; los M-xx los hace el usuario.

## Automáticos (E2E — los ejecuta `qa` con Playwright)

### E2E-01 — Estado del sistema (F-04)
- **Objetivo**: El administrador ve colas, cron, tokens y cuotas
- **Precondiciones**: app levantada en la URL local con la base SQLite de pruebas y datos de fábrica; proveedores simulados
- **Pasos**:
  1. Entrar como administrador y abrir `/sistema`
  2. Provocar (con datos de prueba) una cola atrasada y un token próximo a caducar
- **Aserciones**:
  - [ ] Se muestran la cola atrasada y el token con su fecha
  - [ ] Un usuario no administrador recibe 403
- **Capturas esperadas**: estado inicial y resultado final de la acción principal
- **Cubre tareas**: T-78

### E2E-02 — Revisiones del estudio acotadas (A-07)
- **Objetivo**: El autoguardado no genera una revisión por cada cambio
- **Precondiciones**: app levantada en la URL local con la base SQLite de pruebas y datos de fábrica; proveedores simulados
- **Pasos**:
  1. Abrir un diseño y editar 5 veces en menos del intervalo mínimo
  2. Abrir el historial
- **Aserciones**:
  - [ ] Se crea una sola revisión en el intervalo
  - [ ] El editor sigue guardando sin errores
- **Capturas esperadas**: estado inicial y resultado final de la acción principal
- **Cubre tareas**: T-04

### E2E-03 — Notificaciones de caducidad y de jobs (B-03, B-13)
- **Objetivo**: Caducidad próxima y fin de jobs generan avisos
- **Precondiciones**: app levantada en la URL local con la base SQLite de pruebas y datos de fábrica; proveedores simulados
- **Pasos**:
  1. Preparar una cuenta con token que caduca en N días y un informe programado
  2. Abrir Notificaciones
- **Aserciones**:
  - [ ] Aparece el aviso de caducidad una sola vez
  - [ ] Aparece la notificación del informe terminado
- **Capturas esperadas**: estado inicial y resultado final de la acción principal
- **Cubre tareas**: T-23

### E2E-04 — Inbox: cobertura y permisos (B-02, B-11, B-14)
- **Objetivo**: El inbox explica lo que falta y lista LinkedIn/Pinterest
- **Precondiciones**: app levantada en la URL local con la base SQLite de pruebas y datos de fábrica; proveedores simulados
- **Pasos**:
  1. Abrir el inbox con una marca sin scopes de Meta
  2. Abrir la tabla de cobertura
- **Aserciones**:
  - [ ] Estado claro con el motivo y los pasos
  - [ ] LinkedIn y Pinterest aparecen con su motivo
- **Capturas esperadas**: estado inicial y resultado final de la acción principal
- **Cubre tareas**: T-22

### E2E-05 — Pinterest: tableros paginados (B-01, B-04)
- **Objetivo**: Se cargan todos los tableros y se pueden refrescar
- **Precondiciones**: app levantada en la URL local con la base SQLite de pruebas y datos de fábrica; proveedores simulados
- **Pasos**:
  1. Conectar una cuenta Pinterest simulada con más de 25 tableros
  2. Pulsar «Refrescar tableros»
- **Aserciones**:
  - [ ] Se listan todos los tableros
  - [ ] El refresco actualiza la lista
- **Capturas esperadas**: estado inicial y resultado final de la acción principal
- **Cubre tareas**: T-21

### E2E-06 — Carpetas del estudio (B-16)
- **Objetivo**: Renombrar, borrar y mover a carpeta
- **Precondiciones**: app levantada en la URL local con la base SQLite de pruebas y datos de fábrica; proveedores simulados
- **Pasos**:
  1. Crear una carpeta y un diseño
  2. Renombrar la carpeta y mover el diseño
  3. Borrar la carpeta
- **Aserciones**:
  - [ ] El diseño se ve en la nueva carpeta
  - [ ] Al borrar se pide destino y no se pierde el diseño
- **Capturas esperadas**: estado inicial y resultado final de la acción principal
- **Cubre tareas**: T-33

### E2E-07 — Editor del estudio tras refactor y carga diferida (B-18, D-01, D-02, D-11, D-15)
- **Objetivo**: El editor abre rápido y conserva todas sus funciones
- **Precondiciones**: app levantada en la URL local con la base SQLite de pruebas y datos de fábrica; proveedores simulados
- **Pasos**:
  1. Abrir un diseño
  2. Abrir exportación PDF, subtítulos y gráficos
  3. Exportar PPTX
- **Aserciones**:
  - [ ] Los diálogos cargan bajo demanda sin error
  - [ ] El PPTX y el PDF se descargan
- **Capturas esperadas**: estado inicial y resultado final de la acción principal
- **Cubre tareas**: T-35, T-52, T-61, T-62, T-63

### E2E-08 — Diálogos y confirmaciones (D-04, D-05)
- **Objetivo**: Las acciones destructivas piden confirmación accesible
- **Precondiciones**: app levantada en la URL local con la base SQLite de pruebas y datos de fábrica; proveedores simulados
- **Pasos**:
  1. Borrar una marca, un sitio y un diseño
  2. Cancelar y confirmar con el teclado
- **Aserciones**:
  - [ ] No aparece ningún `confirm()` nativo
  - [ ] El foco queda atrapado y Escape cierra
- **Capturas esperadas**: estado inicial y resultado final de la acción principal
- **Cubre tareas**: T-56, T-57

### E2E-09 — Flash global y errores (D-06)
- **Objetivo**: Los mensajes de éxito y error se ven siempre
- **Precondiciones**: app levantada en la URL local con la base SQLite de pruebas y datos de fábrica; proveedores simulados
- **Pasos**:
  1. Crear y revocar una API key
  2. Provocar un error de validación en el estudio
- **Aserciones**:
  - [ ] Banner global con el mensaje
  - [ ] Ningún error queda sin mostrar
- **Capturas esperadas**: estado inicial y resultado final de la acción principal
- **Cubre tareas**: T-58

### E2E-10 — Listas paginadas (D-07)
- **Objetivo**: Ninguna lista se corta en silencio
- **Precondiciones**: app levantada en la URL local con la base SQLite de pruebas y datos de fábrica; proveedores simulados
- **Pasos**:
  1. Sembrar 250 elementos en papelera y biblioteca
  2. Navegar por las páginas
- **Aserciones**:
  - [ ] Se muestra el total y se llega a todos los elementos
- **Capturas esperadas**: estado inicial y resultado final de la acción principal
- **Cubre tareas**: T-59

### E2E-11 — Hora de la marca y textos (D-09, D-21)
- **Objetivo**: Las fechas usan la zona de la marca
- **Precondiciones**: app levantada en la URL local con la base SQLite de pruebas y datos de fábrica; proveedores simulados
- **Pasos**:
  1. Abrir inbox, papelera y claves de API con una marca en otra zona horaria
- **Aserciones**:
  - [ ] Las horas coinciden con la zona de la marca
  - [ ] Textos sin erratas conocidas
- **Capturas esperadas**: estado inicial y resultado final de la acción principal
- **Cubre tareas**: T-54

### E2E-12 — Formularios y pestañas accesibles (D-10, D-17)
- **Objetivo**: Errores asociados a su campo y pestañas con flechas
- **Precondiciones**: app levantada en la URL local con la base SQLite de pruebas y datos de fábrica; proveedores simulados
- **Pasos**:
  1. Enviar un formulario inválido
  2. Navegar las pestañas del calendario con flechas
- **Aserciones**:
  - [ ] Campo con `aria-invalid` y `aria-describedby`
  - [ ] Las flechas cambian de pestaña y el panel se anuncia
- **Capturas esperadas**: estado inicial y resultado final de la acción principal
- **Cubre tareas**: T-60

### E2E-13 — Sondeos con tope (D-08)
- **Objetivo**: Reports y Canva no sondean sin fin
- **Precondiciones**: app levantada en la URL local con la base SQLite de pruebas y datos de fábrica; proveedores simulados
- **Pasos**:
  1. Lanzar un informe que no termina
  2. Ocultar la pestaña
- **Aserciones**:
  - [ ] El sondeo se detiene al llegar al tope y con la pestaña oculta
- **Capturas esperadas**: estado inicial y resultado final de la acción principal
- **Cubre tareas**: T-55

### E2E-14 — Páginas refactorizadas (D-12)
- **Objetivo**: Sitios, SmartLinks, Informes, Calendario y compositor sin regresión
- **Precondiciones**: app levantada en la URL local con la base SQLite de pruebas y datos de fábrica; proveedores simulados
- **Pasos**:
  1. Recorrer el flujo principal de cada página
- **Aserciones**:
  - [ ] Mismo resultado que antes del refactor
- **Capturas esperadas**: estado inicial y resultado final de la acción principal
- **Cubre tareas**: T-64, T-65, T-66

### E2E-15 — Registro de actividad (F-01)
- **Objetivo**: El usuario ve su actividad filtrable
- **Precondiciones**: app levantada en la URL local con la base SQLite de pruebas y datos de fábrica; proveedores simulados
- **Pasos**:
  1. Realizar tres acciones auditadas
  2. Abrir Actividad y filtrar
- **Aserciones**:
  - [ ] Aparecen las acciones propias y no las de otro usuario
- **Capturas esperadas**: estado inicial y resultado final de la acción principal
- **Cubre tareas**: T-75

### E2E-16 — Mejor hora en un clic (F-02)
- **Objetivo**: Se propone una fecha según datos
- **Precondiciones**: app levantada en la URL local con la base SQLite de pruebas y datos de fábrica; proveedores simulados
- **Pasos**:
  1. Abrir el compositor de una cuenta con histórico
  2. Pulsar «Mejor hora»
- **Aserciones**:
  - [ ] La fecha se rellena en el futuro
  - [ ] Sin datos, aparece una explicación
- **Capturas esperadas**: estado inicial y resultado final de la acción principal
- **Cubre tareas**: T-76

### E2E-17 — Duplicar post (F-03)
- **Objetivo**: Se crea un borrador independiente
- **Precondiciones**: app levantada en la URL local con la base SQLite de pruebas y datos de fábrica; proveedores simulados
- **Pasos**:
  1. Duplicar un post publicado a otra marca
- **Aserciones**:
  - [ ] Existe un borrador nuevo sin métricas ni estado
- **Capturas esperadas**: estado inicial y resultado final de la acción principal
- **Cubre tareas**: T-77

### E2E-18 — Objetivos y alertas (F-05)
- **Objetivo**: Crear un objetivo y recibir la alerta
- **Precondiciones**: app levantada en la URL local con la base SQLite de pruebas y datos de fábrica; proveedores simulados
- **Pasos**:
  1. Crear un objetivo de seguidores
  2. Simular una caída y ejecutar la evaluación
- **Aserciones**:
  - [ ] Una sola alerta en Notificaciones
- **Capturas esperadas**: estado inicial y resultado final de la acción principal
- **Cubre tareas**: T-79

### E2E-19 — Webhooks salientes (F-06)
- **Objetivo**: Alta de un webhook y entrega firmada
- **Precondiciones**: app levantada en la URL local con la base SQLite de pruebas y datos de fábrica; proveedores simulados
- **Pasos**:
  1. Crear un webhook hacia un receptor local de prueba
  2. Publicar un post simulado
- **Aserciones**:
  - [ ] El receptor recibe el evento con firma válida
  - [ ] Una URL privada se rechaza
- **Capturas esperadas**: estado inicial y resultado final de la acción principal
- **Cubre tareas**: T-80

### E2E-20 — PWA y push (F-07)
- **Objetivo**: La app es instalable y gestiona suscripciones
- **Precondiciones**: app levantada en la URL local con la base SQLite de pruebas y datos de fábrica; proveedores simulados
- **Pasos**:
  1. Abrir el manifiesto
  2. Activar y revocar la suscripción con un sender falso
- **Aserciones**:
  - [ ] Manifiesto válido
  - [ ] Suscripción guardada y revocada
- **Capturas esperadas**: estado inicial y resultado final de la acción principal
- **Cubre tareas**: T-81

### E2E-21 — Adaptar contenido con IA (F-08)
- **Objetivo**: Versiones por red y alt text
- **Precondiciones**: app levantada en la URL local con la base SQLite de pruebas y datos de fábrica; proveedores simulados
- **Pasos**:
  1. Escribir un texto y pulsar «Adaptar»
- **Aserciones**:
  - [ ] Cada red tiene su versión dentro de límites
  - [ ] Sin clave, el botón explica por qué está inactivo
- **Capturas esperadas**: estado inicial y resultado final de la acción principal
- **Cubre tareas**: T-82

### E2E-22 — Importación CSV (F-09)
- **Objetivo**: Vista previa con errores y creación
- **Precondiciones**: app levantada en la URL local con la base SQLite de pruebas y datos de fábrica; proveedores simulados
- **Pasos**:
  1. Subir un CSV con filas válidas e inválidas
  2. Confirmar
- **Aserciones**:
  - [ ] Las inválidas se listan y no se crean
  - [ ] Las válidas aparecen como posts
- **Capturas esperadas**: estado inicial y resultado final de la acción principal
- **Cubre tareas**: T-83

### E2E-23 — Exportación completa ZIP (F-10)
- **Objetivo**: Se genera y descarga el ZIP propio
- **Precondiciones**: app levantada en la URL local con la base SQLite de pruebas y datos de fábrica; proveedores simulados
- **Pasos**:
  1. Pedir la exportación
  2. Esperar la notificación y descargar
- **Aserciones**:
  - [ ] El ZIP solo contiene datos del usuario
- **Capturas esperadas**: estado inicial y resultado final de la acción principal
- **Cubre tareas**: T-84

### E2E-24 — Paleta de comandos (F-11)
- **Objetivo**: Búsqueda global por teclado
- **Precondiciones**: app levantada en la URL local con la base SQLite de pruebas y datos de fábrica; proveedores simulados
- **Pasos**:
  1. Pulsar Ctrl+K y buscar un post y una marca
- **Aserciones**:
  - [ ] Resultados del usuario; se navega con teclado
- **Capturas esperadas**: estado inicial y resultado final de la acción principal
- **Cubre tareas**: T-85

### E2E-25 — Resumen semanal y recordatorios (F-12, F-16)
- **Objetivo**: Preferencias y envío
- **Precondiciones**: app levantada en la URL local con la base SQLite de pruebas y datos de fábrica; proveedores simulados
- **Pasos**:
  1. Activar el resumen semanal
  2. Ejecutar el job con `Mail::fake`
- **Aserciones**:
  - [ ] Se genera el resumen y respeta la preferencia
- **Capturas esperadas**: estado inicial y resultado final de la acción principal
- **Cubre tareas**: T-86

### E2E-26 — Mastodon y Telegram (F-13)
- **Objetivo**: Conectar y publicar con fakes
- **Precondiciones**: app levantada en la URL local con la base SQLite de pruebas y datos de fábrica; proveedores simulados
- **Pasos**:
  1. Conectar cada red con credenciales de prueba
  2. Publicar un post
- **Aserciones**:
  - [ ] La publicación se registra como publicada
- **Capturas esperadas**: estado inicial y resultado final de la acción principal
- **Cubre tareas**: T-87

### E2E-27 — Autolistas RSS (F-14)
- **Objetivo**: Un feed genera elementos sin duplicar
- **Precondiciones**: app levantada en la URL local con la base SQLite de pruebas y datos de fábrica; proveedores simulados
- **Pasos**:
  1. Crear una autolista RSS con un feed local
  2. Ejecutar la generación dos veces
- **Aserciones**:
  - [ ] No hay duplicados
- **Capturas esperadas**: estado inicial y resultado final de la acción principal
- **Cubre tareas**: T-88

### E2E-28 — Acortador con clics (F-15)
- **Objetivo**: El enlace corto redirige y cuenta
- **Precondiciones**: app levantada en la URL local con la base SQLite de pruebas y datos de fábrica; proveedores simulados
- **Pasos**:
  1. Crear un enlace corto y visitarlo 3 veces
- **Aserciones**:
  - [ ] Se registran 3 clics agregados
- **Capturas esperadas**: estado inicial y resultado final de la acción principal
- **Cubre tareas**: T-89

### E2E-29 — Calendario: fechas señaladas e .ics (F-17, F-18)
- **Objetivo**: Fechas visibles y feed válido
- **Precondiciones**: app levantada en la URL local con la base SQLite de pruebas y datos de fábrica; proveedores simulados
- **Pasos**:
  1. Activar fechas señaladas
  2. Descargar el feed .ics
- **Aserciones**:
  - [ ] Fechas visibles y ocultables
  - [ ] El .ics es válido y solo del usuario
- **Capturas esperadas**: estado inicial y resultado final de la acción principal
- **Cubre tareas**: T-90

### E2E-30 — Marca de agua y lote (F-19)
- **Objetivo**: Exportar a varios formatos con marca
- **Precondiciones**: app levantada en la URL local con la base SQLite de pruebas y datos de fábrica; proveedores simulados
- **Pasos**:
  1. Aplicar marca de agua y exportar en lote
- **Aserciones**:
  - [ ] Un fichero por formato en la biblioteca
- **Capturas esperadas**: estado inicial y resultado final de la acción principal
- **Cubre tareas**: T-91

### E2E-31 — Reciclado de posts (F-20)
- **Objetivo**: Se proponen los mejores posts
- **Precondiciones**: app levantada en la URL local con la base SQLite de pruebas y datos de fábrica; proveedores simulados
- **Pasos**:
  1. Abrir el reciclado de una marca con métricas
- **Aserciones**:
  - [ ] Solo posts con métricas suficientes y respetando el intervalo
- **Capturas esperadas**: estado inicial y resultado final de la acción principal
- **Cubre tareas**: T-92

### E2E-32 — Plan de contenido en lenguaje natural (F-22)
- **Objetivo**: Propuesta revisable
- **Precondiciones**: app levantada en la URL local con la base SQLite de pruebas y datos de fábrica; proveedores simulados
- **Pasos**:
  1. Escribir un briefing
  2. Revisar y confirmar parte del lote
- **Aserciones**:
  - [ ] Nada se crea hasta confirmar
- **Capturas esperadas**: estado inicial y resultado final de la acción principal
- **Cubre tareas**: T-93

### E2E-33 — Animaciones y plantillas del estudio (F-23)
- **Objetivo**: Animación por objeto y nuevas plantillas
- **Precondiciones**: app levantada en la URL local con la base SQLite de pruebas y datos de fábrica; proveedores simulados
- **Pasos**:
  1. Animar un objeto
  2. Crear un diseño desde una plantilla nueva
- **Aserciones**:
  - [ ] Previsualiza y exporta correctamente
- **Capturas esperadas**: estado inicial y resultado final de la acción principal
- **Cubre tareas**: T-94, T-95

### E2E-34 — Atajos, preferencias y onboarding (F-24)
- **Objetivo**: Atajos y onboarding no estorban
- **Precondiciones**: app levantada en la URL local con la base SQLite de pruebas y datos de fábrica; proveedores simulados
- **Pasos**:
  1. Usar un atajo
  2. Completar y repetir el onboarding
- **Aserciones**:
  - [ ] Los atajos no actúan al escribir en un campo
- **Capturas esperadas**: estado inicial y resultado final de la acción principal
- **Cubre tareas**: T-96

### E2E-35 — Modo oscuro (F-25)
- **Objetivo**: Tema persistente y legible
- **Precondiciones**: app levantada en la URL local con la base SQLite de pruebas y datos de fábrica; proveedores simulados
- **Pasos**:
  1. Cambiar a oscuro y recargar
  2. Recorrer las páginas principales
- **Aserciones**:
  - [ ] El tema persiste
  - [ ] Sin violaciones de contraste
- **Capturas esperadas**: estado inicial y resultado final de la acción principal
- **Cubre tareas**: T-97, T-98

### E2E-36 — Opciones de publicación por red (B-05, B-12, B-17)
- **Objetivo**: Primer comentario, varias fotos, stories, lugar y etiquetas
- **Precondiciones**: app levantada en la URL local con la base SQLite de pruebas y datos de fábrica; proveedores simulados
- **Pasos**:
  1. Componer un post para Threads/Bluesky con primer comentario
  2. Componer Facebook con varias fotos y lugar
- **Aserciones**:
  - [ ] Cada opción se valida y se guarda en el post
- **Capturas esperadas**: estado inicial y resultado final de la acción principal
- **Cubre tareas**: T-24, T-31, T-34

### E2E-37 — Series (B-15)
- **Objetivo**: Herencia de aprobación y recordatorio
- **Precondiciones**: app levantada en la URL local con la base SQLite de pruebas y datos de fábrica; proveedores simulados
- **Pasos**:
  1. Crear una serie con aprobación
  2. Generar ocurrencias
- **Aserciones**:
  - [ ] Las ocurrencias heredan la aprobación
- **Capturas esperadas**: estado inicial y resultado final de la acción principal
- **Cubre tareas**: T-32

### E2E-38 — Regresión tras el refactor del backend (C)
- **Objetivo**: Flujos críticos intactos
- **Precondiciones**: app levantada en la URL local con la base SQLite de pruebas y datos de fábrica; proveedores simulados
- **Pasos**:
  1. Publicar, planificar, abrir inbox y generar un informe
- **Aserciones**:
  - [ ] Mismo resultado que antes del refactor
- **Capturas esperadas**: estado inicial y resultado final de la acción principal
- **Cubre tareas**: T-36, T-37, T-38, T-39, T-40, T-41, T-42, T-43, T-44, T-45, T-46, T-47, T-48, T-49, T-50

### E2E-39 — Regresión integral
- **Objetivo**: Batería completa verde en los tres tamaños
- **Precondiciones**: app levantada en la URL local con la base SQLite de pruebas y datos de fábrica; proveedores simulados
- **Pasos**:
  1. Ejecutar la batería E2E completa (serial, una sola vez por tamaño)
- **Aserciones**:
  - [ ] Todos los specs pasan
- **Capturas esperadas**: estado inicial y resultado final de la acción principal
- **Cubre tareas**: T-01, T-13, T-14, T-18, T-27, T-51, T-53, T-67, T-68, T-69, T-99

### E2E-40 — Competidores multi-red (B-06)
- **Objetivo**: Añadir competidores de Bluesky y YouTube
- **Precondiciones**: app levantada en la URL local con la base SQLite de pruebas y datos de fábrica; proveedores simulados
- **Pasos**:
  1. Añadir un competidor de cada red
  2. Ver sus snapshots
- **Aserciones**:
  - [ ] Aparecen con sus métricas
- **Capturas esperadas**: estado inicial y resultado final de la acción principal
- **Cubre tareas**: T-25

### E2E-41 — Publicación → estudio → nueva exportación (U-15, U-16)
- **Objetivo**: Un post conserva el vínculo con su diseño y se actualiza al volver a exportarlo
- **Precondiciones**: app levantada en la URL local con la base SQLite de pruebas y datos de fábrica; proveedores simulados
- **Pasos**:
  1. Crear un diseño, exportarlo a una publicación y abrir el post
  2. Pulsar «Editar en el Estudio», cambiar el diseño y exportar de nuevo
  3. Volver al post
  4. En el compositor abrir «Desde la biblioteca» y elegir una exportación del estudio
- **Aserciones**:
  - [ ] El post guarda su `design_export_id` y muestra «Editar en el Estudio»
  - [ ] La publicación muestra la media de la nueva exportación
  - [ ] El selector «Desde la biblioteca» lista las exportaciones del estudio
- **Capturas esperadas**: estado inicial y resultado final de la acción principal
- **Cubre tareas**: T-101, T-106

### E2E-42 — Lo importado de Canva aparece en Recursos (U-17, U-18)
- **Objetivo**: Lo importado de Canva y lo subido desde el compositor llegan a Recursos del estudio
- **Precondiciones**: app levantada en la URL local con la base SQLite de pruebas y datos de fábrica; proveedores simulados
- **Pasos**:
  1. Importar un diseño de Canva (con fakes) y subir una imagen desde el compositor
  2. Abrir un diseño del estudio y su panel de Recursos
- **Aserciones**:
  - [ ] Ambos archivos aparecen en Recursos sin duplicados
  - [ ] Reimportar lo mismo no crea una segunda copia
- **Capturas esperadas**: estado inicial y resultado final de la acción principal
- **Cubre tareas**: T-101, T-106

### E2E-43 — Sistema de diseño único (U-07..U-12)
- **Objetivo**: Estudio y parte social comparten acento, botones y diálogos
- **Precondiciones**: app levantada en la URL local con la base SQLite de pruebas y datos de fábrica; proveedores simulados
- **Pasos**:
  1. Abrir el estudio y la Planificación y comparar el color de acento de `btn-primary`
  2. Abrir un diálogo del estudio y uno del compositor
  3. Cerrarlos con Escape y comprobar el foco
- **Aserciones**:
  - [ ] El acento computado es el mismo en ambas partes
  - [ ] Ambos diálogos son `<dialog>` del mismo componente y devuelven el foco
  - [ ] No hay `violet-*` en el DOM renderizado
- **Capturas esperadas**: estado inicial y resultado final de la acción principal
- **Cubre tareas**: T-102, T-107

### E2E-44 — Navegación: las tres entradas del estudio se marcan activas (U-01..U-03)
- **Objetivo**: El lateral marca como activa la entrada correcta
- **Precondiciones**: app levantada en la URL local con la base SQLite de pruebas y datos de fábrica; proveedores simulados
- **Pasos**:
  1. Abrir «Plantillas», «Identidad» y «Papelera de diseños» del estudio
  2. Abrir «Canva»
- **Aserciones**:
  - [ ] Cada una de las tres entradas se marca activa (`aria-current`) al estar en su pantalla
  - [ ] «Estudio» aparece después de Planificación
  - [ ] «Canva» existe en el lateral y no deja «Cuenta» marcada
- **Capturas esperadas**: estado inicial y resultado final de la acción principal
- **Cubre tareas**: T-103

### E2E-45 — La marca del selector coincide con la del diseño (U-04..U-06)
- **Objetivo**: Abrir un diseño de otra marca alinea el selector
- **Precondiciones**: app levantada en la URL local con la base SQLite de pruebas y datos de fábrica; proveedores simulados
- **Pasos**:
  1. Con la marca A activa, abrir `studio/designs/{design}/edit` de un diseño de la marca B
  2. Mirar el selector de marca y la cabecera
- **Aserciones**:
  - [ ] El selector muestra la marca B
  - [ ] El editor usa `#header` común y ocupa el ancho completo sin desbordar
- **Capturas esperadas**: estado inicial y resultado final de la acción principal
- **Cubre tareas**: T-103

### E2E-46 — Identidad de marca y voz de la IA (U-19, U-20)
- **Objetivo**: La identidad vive en la ficha de la marca y la voz llega a ambas IA
- **Precondiciones**: app levantada en la URL local con la base SQLite de pruebas y datos de fábrica; proveedores simulados
- **Pasos**:
  1. Abrir la ficha de la marca y su pestaña «Identidad»
  2. Pedir texto a la IA del compositor y a la del estudio (fake)
- **Aserciones**:
  - [ ] La pestaña «Identidad» edita el kit de marca
  - [ ] Los dos prompts incluyen `guidelines` y `voice`
- **Capturas esperadas**: estado inicial y resultado final de la acción principal
- **Cubre tareas**: T-104

### E2E-47 — Colaborador de la marca en el estudio (U-22)
- **Objetivo**: El colaborador ve y trabaja los diseños según su rol
- **Precondiciones**: app levantada en la URL local con la base SQLite de pruebas y datos de fábrica; proveedores simulados
- **Pasos**:
  1. Invitar a un colaborador a la marca y entrar como él
  2. Abrir el estudio
- **Aserciones**:
  - [ ] Ve los diseños de esa marca con el alcance de su rol
  - [ ] No ve los de otras marcas
- **Capturas esperadas**: estado inicial y resultado final de la acción principal
- **Cubre tareas**: T-105

## API (smoke de endpoints, los ejecuta `qa` con curl)

### API-01 — API v1 posts
- **Método y ruta**: GET /api/v1/posts (relativa a la URL local, con API key de prueba)
- **Status esperado**: 200
- **Aserción sobre el body**: lista solo posts del usuario de la API key
- **Cubre tareas**: T-28

### API-02 — API v1 recursos
- **Método y ruta**: GET /api/v1/brands (relativa a la URL local, con API key de prueba)
- **Status esperado**: 200
- **Aserción sobre el body**: marcas del usuario; `GET` de otro usuario devuelve 404/403
- **Cubre tareas**: T-29, T-108

### API-03 — Salud estricta
- **Método y ruta**: GET /health?strict=1 (relativa a la URL local, con API key de prueba)
- **Status esperado**: 200 sano / 503 con checks en rojo
- **Aserción sobre el body**: cuerpo con el detalle de checks
- **Cubre tareas**: T-05

## Manuales (M — los realiza una persona)

### M-01 — Smoke Pinterest sandbox (A-05, B-01)
- **Qué revisar**: Publicar vídeo e imagen y leer analítica en el entorno sandbox
- **Por qué no se automatiza**: Requiere credenciales reales y `PINTEREST_API_HOST` de sandbox
- **Criterio de aceptación**:
  - [ ] Vídeo publicado sin errores de memoria
  - [ ] Analítica visible
- **Cubre tareas**: T-07, T-21

### M-02 — Smoke LinkedIn (A-04)
- **Qué revisar**: Confirmar versión de API activa, escape del comentario y portada de vídeo
- **Por qué no se automatiza**: Cuenta real y revisión de la doc oficial
- **Criterio de aceptación**:
  - [ ] Publicación con comentario correcta
- **Cubre tareas**: T-08, T-34

### M-03 — Reconectar TikTok con scopes de analítica (A-11)
- **Qué revisar**: Reconectar con `TIKTOK_EXTRA_SCOPES` y ver analítica
- **Por qué no se automatiza**: Requiere auditoría de TikTok y cuenta real
- **Criterio de aceptación**:
  - [ ] La analítica deja de estar vacía
- **Cubre tareas**: T-08

### M-04 — Meta: inbox, Ads y publicación (B-02, B-12, B-17)
- **Qué revisar**: Reconectar con `META_EXTRA_SCOPES` y probar inbox, varias fotos, stories y lugares
- **Por qué no se automatiza**: Requiere app Meta aprobada
- **Criterio de aceptación**:
  - [ ] Comentarios visibles
  - [ ] Publicaciones correctas
- **Cubre tareas**: T-22, T-31, T-34

### M-05 — Restauración de copia (A-03)
- **Qué revisar**: Restaurar un respaldo en un entorno limpio
- **Por qué no se automatiza**: Requiere destino externo y servidor
- **Criterio de aceptación**:
  - [ ] La aplicación arranca con los datos restaurados
- **Cubre tareas**: T-09

### M-06 — Despliegue en el VPS (A-06, A-10, E-05, E-18)
- **Qué revisar**: Desplegar con el script nuevo, comprobar workers y cabeceras
- **Por qué no se automatiza**: Requiere el VPS
- **Criterio de aceptación**:
  - [ ] Mantenimiento corto
  - [ ] Colas consumidas
  - [ ] Cabeceras correctas
- **Cubre tareas**: T-03, T-11, T-69, T-73

### M-07 — Push en dispositivos (F-07)
- **Qué revisar**: Instalar la PWA y recibir un push
- **Por qué no se automatiza**: Requiere VAPID, HTTPS y dispositivos reales
- **Criterio de aceptación**:
  - [ ] Push recibido y revocable
- **Cubre tareas**: T-81

### M-08 — Revisión visual del modo oscuro (F-25)
- **Qué revisar**: Recorrer toda la UI en oscuro
- **Por qué no se automatiza**: Juicio visual
- **Criterio de aceptación**:
  - [ ] Sin texto ilegible ni iconos perdidos
- **Cubre tareas**: T-97, T-98

### M-09 — Protección de `main` (A-02)
- **Qué revisar**: Ejecutar `protect-main.sh` y comprobar que no se fusiona con CI roja
- **Por qué no se automatiza**: Requiere permisos de administrador del repo
- **Criterio de aceptación**:
  - [ ] Fusión bloqueada con `CI / tests` en rojo
- **Cubre tareas**: T-10

### M-10 — Canva y Mastodon/Telegram reales (B-07, B-08, F-13)
- **Qué revisar**: Probar JWKS, PDF, Autofill y las dos redes nuevas
- **Por qué no se automatiza**: Cuentas y capacidades reales
- **Criterio de aceptación**:
  - [ ] Flujos completos sin errores
- **Cubre tareas**: T-26, T-27, T-87

### M-11 — PPTX en PowerPoint (B-18)
- **Qué revisar**: Abrir el PPTX exportado en PowerPoint/LibreOffice
- **Por qué no se automatiza**: Juicio visual en software externo
- **Criterio de aceptación**:
  - [ ] Se abre sin reparar y respeta el diseño
- **Cubre tareas**: T-35

### M-12 — Accesibilidad con lector de pantalla (D-05, D-10, D-17)
- **Qué revisar**: Recorrer diálogos, formularios y pestañas con un lector
- **Por qué no se automatiza**: Juicio humano
- **Criterio de aceptación**:
  - [ ] Todo se anuncia correctamente
- **Cubre tareas**: T-56, T-60

## Cobertura (tarea → escenarios)

| Tarea | Cubierta por |
|-------|--------------|
| T-01 | E2E-39 |
| T-03 | M-06 |
| T-04 | E2E-02 |
| T-05 | API-03 |
| T-07 | M-01 |
| T-08 | M-02, M-03 |
| T-09 | M-05 |
| T-10 | M-09 |
| T-11 | M-06 |
| T-13 | E2E-39 |
| T-14 | E2E-39 |
| T-18 | E2E-39 |
| T-21 | E2E-05, M-01 |
| T-22 | E2E-04, M-04 |
| T-23 | E2E-03 |
| T-24 | E2E-36 |
| T-25 | E2E-40 |
| T-26 | M-10 |
| T-27 | E2E-39, M-10 |
| T-28 | API-01 |
| T-29 | API-02 |
| T-31 | E2E-36, M-04 |
| T-32 | E2E-37 |
| T-33 | E2E-06 |
| T-34 | E2E-36, M-02, M-04 |
| T-35 | E2E-07, M-11 |
| T-36 | E2E-38 |
| T-37 | E2E-38 |
| T-38 | E2E-38 |
| T-39 | E2E-38 |
| T-40 | E2E-38 |
| T-41 | E2E-38 |
| T-42 | E2E-38 |
| T-43 | E2E-38 |
| T-44 | E2E-38 |
| T-45 | E2E-38 |
| T-46 | E2E-38 |
| T-47 | E2E-38 |
| T-48 | E2E-38 |
| T-49 | E2E-38 |
| T-50 | E2E-38 |
| T-51 | E2E-39 |
| T-52 | E2E-07 |
| T-53 | E2E-39 |
| T-54 | E2E-11 |
| T-55 | E2E-13 |
| T-56 | E2E-08, M-12 |
| T-57 | E2E-08 |
| T-58 | E2E-09 |
| T-59 | E2E-10 |
| T-60 | E2E-12, M-12 |
| T-61 | E2E-07 |
| T-62 | E2E-07 |
| T-63 | E2E-07 |
| T-64 | E2E-14 |
| T-65 | E2E-14 |
| T-66 | E2E-14 |
| T-67 | E2E-39 |
| T-68 | E2E-39 |
| T-69 | E2E-39, M-06 |
| T-73 | M-06 |
| T-75 | E2E-15 |
| T-76 | E2E-16 |
| T-77 | E2E-17 |
| T-78 | E2E-01 |
| T-79 | E2E-18 |
| T-80 | E2E-19 |
| T-81 | E2E-20, M-07 |
| T-82 | E2E-21 |
| T-83 | E2E-22 |
| T-84 | E2E-23 |
| T-85 | E2E-24 |
| T-86 | E2E-25 |
| T-87 | E2E-26, M-10 |
| T-88 | E2E-27 |
| T-89 | E2E-28 |
| T-90 | E2E-29 |
| T-91 | E2E-30 |
| T-92 | E2E-31 |
| T-93 | E2E-32 |
| T-94 | E2E-33 |
| T-95 | E2E-33 |
| T-96 | E2E-34 |
| T-97 | E2E-35, M-08 |
| T-98 | E2E-35, M-08 |
| T-99 | E2E-39 |
| T-101 | E2E-41, E2E-42 |
| T-102 | E2E-43 |
| T-103 | E2E-44, E2E-45 |
| T-104 | E2E-46 |
| T-105 | E2E-47 |
| T-106 | E2E-41, E2E-42 |
| T-107 | E2E-43 |
| T-108 | API-02 |
