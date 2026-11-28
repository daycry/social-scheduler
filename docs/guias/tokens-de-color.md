# Tokens de color para la interfaz

Las paletas están en [app.css](../../resources/css/app.css), en `:root` y `:root.dark`. Los valores son tres canales RGB y [Tailwind](../../tailwind.config.js) los expone como colores con opacidad opcional. Cada utilidad cambia de valor con el tema del documento.

| Uso | Utilidades |
| --- | --- |
| Fondo general, paneles y menús o diálogos | `bg-canvas`, `bg-surface`, `bg-surface-raised`, `hover:bg-surface-hover` y `bg-surface-pressed` para fondos neutros más marcados |
| Separadores e identificación de controles | `border-border`, `border-border-subtle`, `border-control-border` |
| Títulos, texto general, etiquetas, ayuda | `text-foreground-strong`, `text-foreground`, `text-foreground-secondary`, `text-foreground-muted`, `text-foreground-subtle` |
| Enlaces, selección y acciones | `text-accent`, `text-accent-strong`, `bg-accent-soft`, `bg-accent-fill text-on-accent` |
| Acento violeta del Estudio y el compositor | `bg-accent-secondary-soft` o `bg-accent-secondary-marker` con `text-accent-secondary-ink`. `btn-secondary-accent` empareja `bg-accent-secondary-fill`, `bg-accent-secondary-hover` y `text-on-accent-secondary` |
| Error textual y acción destructiva | `text-danger`, `bg-danger-fill text-on-danger` |
| Avisos | `bg-{success,danger,warning,info}-soft` con `text-…-ink` y `border-…-border` del mismo estado. `border-warning-panel-border` en las tarjetas de permisos y recuperación. `border-warning-outline` cuando el contorno debe alcanzar 3:1 |
| Selección positiva con fondo sólido | `bg-success-fill text-on-success` |
| Fechas destacadas del calendario | `bg-calendar-event-soft text-calendar-event-ink border-calendar-event-border` |
| Foco y fondo modal | `ring-focus`, `bg-overlay/75` |
| Cabecera y panel de navegación de marca | `bg-chrome`, `bg-chrome-panel`, `text-chrome-muted`, `text-chrome-strong` |

Usa `card`, `field`, `btn-primary`, `btn-secondary`, `btn-danger` y `link` donde encajen. Reutiliza `FormField` con `InputLabel` para enlazar errores y controles. En los diálogos, `BaseDialog` aporta superficie, texto y overlay, y `dialog-panel` aporta la geometría. Elige tokens por el uso del elemento y comprueba el par de colores cuando cambies su fondo o su opacidad.

En gráficos SVG, usa los tokens directamente para etiquetas, guías, rejillas y tooltips. `LineChart` mezcla el color de cada serie con `foreground-strong` mediante `--chart-brand-share`. La misma mezcla se aplica a trazos, puntos y muestras de la leyenda. «Mejores horas» usa cinco intensidades con pares AA para las etiquetas del mapa de calor. El dato numérico conserva su valor.

Para una red sin color de marca, `useNetworks` devuelve `accent-fill` y el icono usa `bg-accent-fill text-on-accent`. Las marcas conocidas conservan su color oficial y su glifo blanco.

Para un control que dibuje interfaz en canvas, lee el token con `getComputedStyle(document.documentElement).getPropertyValue('--color-…')`. Inyecta `themeKey` desde `useTheme.js` y observa `dark` para volver a dibujarlo. En el Estudio, `--color-editor-stage` y `--color-editor-stage-dot` cubren el marco punteado. El lienzo, incluida la vista previa de un gráfico, toma sus ejes, textos, series, rejilla y fondo del diseño y conserva esos valores al compartir y exportar.

Las franjas de animación de la línea de tiempo usan `bg-current`. Heredan `accent-secondary-ink` en el clip normal y `on-accent-secondary` en el seleccionado. El contraste con el fondo del clip supera 3:1 en los dos temas.

[La prueba de paleta](../../tests/frontend/theme-ui-palette.test.mjs) cubre Vue y helpers JS/TS de la interfaz. El motor del lienzo queda en `resources/js/Studio`. Las excepciones incluyen logos y botones de marca, el margen blanco del QR, las muestras de color y las vistas previas de diseños, medios, sitios y SmartLinks. El valor inicial de una campaña se guarda como dato hexadecimal.

El arranque de Blade aplica el esquema antes de Vue. `useTheme` lo mantiene sincronizado con la preferencia de la cuenta, la selección local del invitado y los cambios en `prefers-color-scheme`.

Comprueba 4,5:1 en texto normal y 3:1 en texto grande, controles y foco. Amplía los pares en [theme-contrast.test.mjs](../../tests/frontend/theme-contrast.test.mjs) si añades una combinación. E2E-35 y M-08 cubren el contraste en navegador y la revisión visual.
