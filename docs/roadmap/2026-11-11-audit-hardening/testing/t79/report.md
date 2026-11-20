# T-79 cerrada: objetivos y alertas

Integrada el 18 de noviembre de 2026 tras tres revisiones reales de **Claude Code Opus 5.5**: aceptación, corrección, seguridad, rendimiento y calidad del código. No quedan hallazgos abiertos. Las dos sugerencias finales de interfaz se aplicaron según la indicación del revisor y se verificaron con RED/GREEN.

- PHP completo: **4.402 pruebas pasan, 9 omitidas y 29.556 aserciones**.
- SQLite y MySQL 8.4.3 finales: **51 pruebas y 286 aserciones** por motor, incluyendo las regresiones de caducidad de Conexiones (T-23).
- Cinco rondas de concurrencia con **dos procesos PHP nativos y MySQL**: exactamente un evento y un aviso por ronda. Rollback y nueva aplicación de las migraciones comprobados.
- Node **789/789**, TypeScript estricto y vue-tsc sin errores; Pint, ESLint, Prettier y build pasan.
- Navegador ampliado **27/27** en tres tamaños, sin omisiones, fallos ni inestabilidad; `qa-gate` VERDE. Tras ajustar únicamente la posición de las capturas, el focal final de objetivos pasa **12/12**. Los recuentos se solapan y no se suman.
- **1.809 huellas** comprobadas y **30 archivos** integrados con bytes exactos; parche reversible. Capturas de escritorio y 360 píxeles inspeccionadas.

Se corrigieron la validación de cuentas desconectadas, el periodo adelantado al colector en determinadas zonas horarias y los objetivos inconsistentes que detenían el lote. Los errores se resumen después de procesar a los demás usuarios para permitir reintentos sin duplicar avisos. La selección de caducidad reutiliza Conexiones y evita alertas por accesos autorrenovables; contempla fechas vencidas y un refresh vacío cifrado. La deduplicación de caídas incluye la regla y el periodo.

Se mejoraron nombres accesibles, mensajes de error por campo, estado explícito de reconexión, cola independiente del correo, títulos de avisos y fechas exactas en la zona de la marca. La cuenta desconectada ya vinculada puede conservarse durante la edición, sin permitir elegir otra desconectada. Un plazo conocido vencido tiene prioridad frente a otro futuro: no se presume que el primero sea un dato rancio.

Después de R3 solo cambiaron el componente de objetivos y su spec: el estado de reconexión ya no oculta el valor observado y la fecha vencida dice «Caducó el». Las dos pruebas fallaron antes de aplicar esos cambios y pasan después. La suite PHP completa sigue siendo atribuible a todos los bytes PHP finales. El último ajuste del spec desplaza la página arriba antes de capturar para evitar el artefacto de cabecera fija en imágenes largas.

Las pruebas no acreditan proveedores, SMTP ni un trabajador de cola real. El correo tras un fallo de encolado es best effort y no tiene outbox propio. La escala de aplicación personal y la retención de eventos están documentadas. Sin cambios en entorno privado, base de producción o usuario 28; sin commit, push ni merge.

`verification.json`, `source-hashes.json` y `reviews/` conservan resultados, recibos y decisiones. Las notas antiguas del ledger son historia y no sustituyen este cierre.
