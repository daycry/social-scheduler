# Tokens de color para la interfaz

Las paletas están en [app.css](../../resources/css/app.css), en `:root` y `:root.dark`. Los valores son tres canales RGB y [Tailwind](../../tailwind.config.js) los expone como colores con opacidad opcional. Cada utilidad cambia de valor con el tema del documento.

| Uso | Utilidades |
| --- | --- |
| Fondo general, paneles y menús o diálogos | `bg-canvas`, `bg-surface`, `bg-surface-raised`, `hover:bg-surface-hover` |
| Separadores e identificación de controles | `border-border`, `border-border-subtle`, `border-control-border` |
| Títulos, texto general, etiquetas, ayuda | `text-foreground-strong`, `text-foreground`, `text-foreground-secondary`, `text-foreground-muted`, `text-foreground-subtle` |
| Enlaces, selección y acciones | `text-accent`, `text-accent-strong`, `bg-accent-soft`, `bg-accent-fill text-on-accent` |
| Error textual y acción destructiva | `text-danger`, `bg-danger-fill text-on-danger` |
| Avisos | `bg-{success,danger,warning,info}-soft` con `text-…-ink` y `border-…-border` del mismo estado. `border-warning-panel-border` en las tarjetas de permisos y recuperación. `border-warning-outline` cuando el contorno debe alcanzar 3:1 |
| Foco y fondo modal | `ring-focus`, `bg-overlay/75` |
| Cabecera y panel de navegación de marca | `bg-chrome`, `bg-chrome-panel`, `text-chrome-muted`, `text-chrome-strong` |

Para T-98, usa `card`, `field`, `btn-primary`, `btn-secondary`, `btn-danger` y `link` donde encajen. Reutiliza `FormField` con `InputLabel` para enlazar errores y controles. En los diálogos, `BaseDialog` aporta superficie, texto y overlay, y `dialog-panel` aporta la geometría. Elige tokens por el uso del elemento y comprueba el par de colores cuando cambies su fondo o su opacidad.

En gráficos y controles del Estudio que dibujan la interfaz en canvas, lee el token del documento con `getComputedStyle(document.documentElement).getPropertyValue('--color-…')` al dibujar. Inyecta `themeKey` desde `useTheme.js` y observa `dark` para volver a dibujar cuando cambie la paleta. Conserva los colores de marca y del contenido del diseño en el documento y las exportaciones.

El arranque de Blade aplica el esquema antes de Vue. `useTheme` lo mantiene sincronizado con la preferencia de la cuenta, la selección local del invitado y los cambios en `prefers-color-scheme`.

Comprueba 4,5:1 en texto normal y 3:1 en texto grande, controles y foco. Amplía los pares en [theme-contrast.test.mjs](../../tests/frontend/theme-contrast.test.mjs) si añades una combinación. E2E-35 y M-08 cubren el contraste en navegador y la revisión visual.
