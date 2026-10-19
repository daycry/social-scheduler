# Plan de pruebas — Social Scheduler: programación y analítica de redes sociales (uso personal)

| | |
|---|---|
| **Estado** | borrador |
| **Plan** | [`improvement-plan.md`](improvement-plan.md) |
| **URL local** | https://metricool.test |

> Los E2E corren con conectores **simulados** (`fake connector`, T-11) para no depender de las plataformas reales; la publicación y el OAuth reales se validan en los bloques manuales M-01 y M-02. Cada E2E mapea 1:1 con un criterio CA de la spec.

## Automáticos (E2E — los ejecuta `qa` con Playwright)

### E2E-01 — Registro, login y panel vacío (CA-01)
- **Objetivo**: un visitante se registra, inicia sesión y ve solo su panel vacío.
- **Precondiciones**: app levantada en la URL local; BD limpia con `migrate:fresh`.
- **Pasos**:
  1. Abrir `/register` y completar el formulario.
  2. Cerrar sesión y volver a iniciar sesión en `/login`.
  3. Abrir `/forgot-password` y solicitar el enlace (correo capturado por el driver `log`).
- **Aserciones**:
  - [ ] Tras el registro se llega al panel sin marcas ni cuentas.
  - [ ] Una ruta protegida sin sesión redirige a `/login`.
  - [ ] Se genera el enlace de recuperación.
- **Capturas esperadas**: formulario relleno, panel vacío.
- **Cubre tareas**: T-07

### E2E-02 — Aislamiento entre usuarios por la web (CA-02)
- **Objetivo**: el usuario B no accede a una marca del usuario A.
- **Precondiciones**: dos usuarios; el usuario A tiene una marca creada.
- **Pasos**:
  1. Iniciar sesión como A y crear la marca «Proyecto A»; anotar su URL.
  2. Cerrar sesión, iniciar sesión como B y abrir la URL de esa marca.
- **Aserciones**:
  - [ ] B recibe 404/403 y ningún dato de A.
  - [ ] La lista de marcas de B está vacía.
- **Capturas esperadas**: página 404/403 de B.
- **Cubre tareas**: T-08

### E2E-03 — Conectar una cuenta con proveedor simulado (CA-03)
- **Objetivo**: conectar una cuenta por OAuth y verla con su estado en la marca.
- **Precondiciones**: usuario con una marca; conector `fake` habilitado para los tests.
- **Pasos**:
  1. Abrir la pantalla de conexiones de la marca.
  2. Conectar IG, FB, YouTube y TikTok a través del proveedor simulado.
- **Aserciones**:
  - [ ] Cada cuenta aparece como `activa` en la marca.
  - [ ] Desconectar una cuenta la elimina de la lista y borra sus tokens.
- **Capturas esperadas**: lista de cuentas conectadas.
- **Cubre tareas**: T-11, T-12, T-13, T-14, T-15

### E2E-04 — Token caducado: refresco y «requiere reconexión» (CA-04)
- **Objetivo**: el refresco renueva el token y, si falla, la cuenta pide reconexión.
- **Precondiciones**: una cuenta conectada con token próximo a caducar; conector `fake` configurable.
- **Pasos**:
  1. Ejecutar el job de refresco con el proveedor respondiendo OK.
  2. Repetir con el proveedor rechazando el refresco.
  3. Abrir la pantalla de conexiones.
- **Aserciones**:
  - [ ] En el caso OK la cuenta sigue `activa` con el token renovado.
  - [ ] En el caso de fallo la cuenta pasa a `requiere reconexión` con aviso y botón de reconectar.
  - [ ] Las publicaciones pendientes de la cuenta siguen existiendo.
- **Capturas esperadas**: aviso de reconexión.
- **Cubre tareas**: T-15, T-16

### E2E-05 — Rechazo de media que excede los límites (CA-05)
- **Objetivo**: el compositor rechaza un vídeo fuera de los límites de un reel de IG.
- **Precondiciones**: cuenta de IG conectada (simulada); vídeo de muestra más largo que el máximo de un reel.
- **Pasos**:
  1. Abrir el compositor, elegir IG → Reel.
  2. Subir el vídeo y pulsar Programar.
- **Aserciones**:
  - [ ] Se muestra el límite incumplido (duración) y la publicación no se guarda.
- **Capturas esperadas**: mensaje de error de validación.
- **Cubre tareas**: T-18, T-23

### E2E-06 — Programar y publicar con conector simulado (CA-06)
- **Objetivo**: una publicación válida se publica a su hora y queda `publicada` con enlace/ID.
- **Precondiciones**: cuenta conectada (simulada); worker de cola en ejecución; media válida.
- **Pasos**:
  1. Crear en el compositor una publicación programada para 1 minuto después.
  2. Esperar a que el dispatcher y el worker la procesen.
  3. Abrir el calendario.
- **Aserciones**:
  - [ ] El estado pasa de `programada` a `publicada`.
  - [ ] Se muestra el enlace/ID externo.
- **Capturas esperadas**: publicación programada, publicación publicada.
- **Cubre tareas**: T-17, T-19, T-20, T-21, T-22, T-23, T-26, T-27

### E2E-07 — Error transitorio: reintento sin duplicados (CA-07)
- **Objetivo**: ante un error transitorio la publicación se reintenta y no se duplica.
- **Precondiciones**: conector `fake` que falla N veces con 5xx y luego acepta; en otro caso, que expira tras enviar.
- **Pasos**:
  1. Programar una publicación para el escenario de fallo transitorio.
  2. Dejar que el worker agote los reintentos o consiga publicar.
- **Aserciones**:
  - [ ] Termina `publicada` o `fallida` con motivo legible.
  - [ ] El conector simulado registra una sola publicación externa.
  - [ ] En caso de fallo hay aviso al usuario.
- **Capturas esperadas**: detalle de la publicación con historial de intentos.
- **Cubre tareas**: T-20, T-25

### E2E-08 — Calendario por marca y red con reprogramación (CA-08)
- **Objetivo**: ver publicaciones por fecha y reprogramar las pendientes.
- **Precondiciones**: publicaciones programadas y publicadas en dos marcas y varias redes.
- **Pasos**:
  1. Abrir el calendario y filtrar por marca y red.
  2. Reprogramar una publicación pendiente.
- **Aserciones**:
  - [ ] Cada publicación aparece en su fecha con su estado.
  - [ ] La publicación reprogramada cambia de fecha; una ya publicada no se puede reprogramar.
- **Capturas esperadas**: calendario filtrado.
- **Cubre tareas**: T-24

### E2E-09 — Snapshots diarios (CA-09)
- **Objetivo**: el job diario guarda snapshots de cuenta y de publicaciones recientes.
- **Precondiciones**: cuentas conectadas (simuladas) y publicaciones recientes.
- **Pasos**:
  1. Ejecutar el job de snapshots.
  2. Ejecutarlo de nuevo el mismo día.
- **Aserciones**:
  - [ ] Existe un snapshot de cuenta y de publicaciones recientes.
  - [ ] La segunda ejecución no duplica snapshots.
  - [ ] Las métricas no disponibles se guardan como no disponibles.
- **Capturas esperadas**: n/a (verificación en BD).
- **Cubre tareas**: T-31, T-32, T-33, T-34, T-36

### E2E-10 — Dashboard de marca con 30 días de histórico (CA-10)
- **Objetivo**: el dashboard muestra métricas con gráficos por red o «no disponible».
- **Precondiciones**: ≥ 30 días de snapshots sembrados para una marca.
- **Pasos**:
  1. Abrir el dashboard de la marca.
  2. Cambiar entre redes y rangos de fecha.
- **Aserciones**:
  - [ ] Se ven seguidores y crecimiento, alcance, impresiones y engagement con gráficos.
  - [ ] Donde la API no ofrece una métrica se lee «no disponible», no 0.
- **Capturas esperadas**: dashboard con gráficos.
- **Cubre tareas**: T-35, T-37

### E2E-11 — Informe por rango de fechas en PDF y CSV (CA-11)
- **Objetivo**: generar y descargar un informe de marca.
- **Precondiciones**: datos de snapshots y publicaciones en el rango.
- **Pasos**:
  1. Abrir Informes, elegir marca y rango.
  2. Generar y esperar a que termine.
  3. Descargar PDF y CSV.
- **Aserciones**:
  - [ ] El PDF se descarga, no está vacío y contiene KPIs y tabla de publicaciones.
  - [ ] El CSV contiene los datos del rango y solo del usuario.
- **Capturas esperadas**: estado «listo» y descargas.
- **Cubre tareas**: T-38, T-39, T-40

### E2E-12 — Gestión de API keys para el MCP (CA-12)
- **Objetivo**: crear y revocar una API key personal desde la UI.
- **Precondiciones**: usuario autenticado.
- **Pasos**:
  1. Abrir API keys y crear una key (se muestra una sola vez).
  2. Revocarla.
- **Aserciones**:
  - [ ] La key se muestra completa una única vez.
  - [ ] Tras revocarla, el MCP responde 401 con esa key (ver API-02).
- **Capturas esperadas**: key creada, key revocada.
- **Cubre tareas**: T-28

## API (opcional, opt-in — smoke de endpoints, los ejecuta `qa` con curl)

### API-01 — MCP: inicialización y herramientas con key válida
- **Método y ruta**: POST `/mcp` (JSON-RPC `initialize` y `tools/list`) con `Authorization: Bearer <key>`
- **Status esperado**: 200
- **Aserción sobre el body**: `tools/list` contiene las herramientas de marcas, publicaciones, calendario, estadísticas e informes
- **Cubre tareas**: T-28, T-29, T-42

### API-02 — MCP: key inválida o revocada
- **Método y ruta**: POST `/mcp` con `Authorization: Bearer <key inválida>`
- **Status esperado**: 401
- **Aserción sobre el body**: sin herramientas ni datos en la respuesta
- **Cubre tareas**: T-28

### API-03 — MCP: aislamiento entre usuarios
- **Método y ruta**: POST `/mcp` (`tools/call` de lectura de una marca) con la key del usuario B sobre un ID de A
- **Status esperado**: 200 con error de herramienta o 404/403 según la implementación
- **Aserción sobre el body**: no aparece ningún dato de la marca de A
- **Cubre tareas**: T-30, T-42

## Manuales (M — los realiza una persona)

### M-01 — Publicación real de cada tipo de contenido
- **Qué revisar**: publicar de verdad en cuentas de prueba los 13 tipos (IG post, carrusel, reel, story; FB texto/enlace, foto, vídeo, reel; YouTube vídeo y short; TikTok vídeo y foto/carrusel) y comprobar la visibilidad real.
- **Por qué no se automatiza**: requiere cuentas reales, contenido real y juicio sobre el resultado en la plataforma.
- **Criterio de aceptación**:
  - [ ] Cada tipo soportado queda publicado con enlace/ID y visibilidad conforme a la decisión G1.
  - [ ] Los tipos pospuestos por G1 quedan registrados como no aplicables.
- **Cubre tareas**: T-21, T-22, T-23, T-26, T-27, T-43

### M-02 — OAuth real de las cuatro redes
- **Qué revisar**: conectar de verdad IG, FB, YouTube y TikTok con las apps de desarrollador y comprobar la renovación de tokens.
- **Por qué no se automatiza**: pantallas de consentimiento de terceros, captchas y verificaciones.
- **Criterio de aceptación**:
  - [ ] Las cuentas se conectan, se desconectan y se reconectan sin errores.
  - [ ] Los tokens quedan cifrados en BD.
- **Cubre tareas**: T-12, T-13, T-14, T-15, T-16

### M-03 — Revisión visual de dashboards y PDF
- **Qué revisar**: legibilidad de los gráficos y diseño del PDF con datos reales.
- **Por qué no se automatiza**: juicio visual y de UX.
- **Criterio de aceptación**:
  - [ ] El usuario aprueba el dashboard y el diseño del informe.
- **Cubre tareas**: T-37, T-39, T-41

### M-04 — Despliegue y operación en el VPS con HestiaCP
- **Qué revisar**: la app responde por HTTPS, el cron del scheduler corre, los workers systemd sobreviven a reinicios y a `queue:restart`, y la cola recupera publicaciones vencidas tras reiniciar el VPS.
- **Por qué no se automatiza**: depende del servidor real y de acceso root.
- **Criterio de aceptación**:
  - [ ] `/up` devuelve 200 bajo HTTPS.
  - [ ] Un worker caído se levanta solo.
  - [ ] Una publicación vencida durante la caída se publica al volver.
- **Cubre tareas**: T-09, T-10

### M-05 — Revisión del informe del spike y de la decisión G1
- **Qué revisar**: que `spike-report.md` contiene evidencia de cada plataforma y que la decisión del usuario por red queda registrada.
- **Por qué no se automatiza**: es una decisión de negocio con juicio del usuario.
- **Criterio de aceptación**:
  - [ ] Hay un veredicto por plataforma con evidencia y una decisión G1 por red.
- **Cubre tareas**: T-01, T-02, T-03, T-04, T-05

## Cobertura (tarea → escenarios)

| Tarea | Cubierta por |
|-------|--------------|
| T-01 … T-05 | M-05 |
| T-07 | E2E-01 |
| T-08 | E2E-02 |
| T-09, T-10 | M-04 |
| T-11, T-12, T-13, T-14 | E2E-03, M-02 |
| T-15 | E2E-03, E2E-04, M-02 |
| T-16 | E2E-04, M-02 |
| T-17, T-19, T-21, T-22, T-26, T-27 | E2E-06 (T-21, T-22, T-26, T-27 también M-01) |
| T-18 | E2E-05 |
| T-20 | E2E-06, E2E-07 |
| T-23 | E2E-05, E2E-06, M-01 |
| T-24 | E2E-08 |
| T-25 | E2E-07 |
| T-28 | E2E-12, API-01, API-02 |
| T-29 | API-01 |
| T-30 | API-03 |
| T-31, T-32, T-33, T-34, T-36 | E2E-09 |
| T-35 | E2E-10 |
| T-37 | E2E-10, M-03 |
| T-38, T-40 | E2E-11 |
| T-39 | E2E-11, M-03 |
| T-41 | M-03 |
| T-42 | API-01, API-03 |
| T-43 | E2E-01 … E2E-12, M-01 … M-05 |
