# Dependencias directas y plan de migraciones

Inventario comprobado el 17 de noviembre de 2026 con los lockfiles, `composer outdated --locked` y `npm outdated`. Las versiones observadas orientan las pruebas de futuras ramas. «Sin dato» indica que el inventario no registró una versión para esa fila.

| Dependencia | Restricción | Bloqueada | Disponible observada |
|---|---|---|---|
| `web-auth/webauthn-lib` | `~5.3.0` | 5.3.9 | Sin dato |
| `laravel/passkeys` | `~0.2.1` | 0.2.1 | [0.3.0](https://github.com/laravel/passkeys-server/releases/tag/v0.3.0) |
| `@laravel/passkeys` | `~0.4.0` | 0.4.0 | 0.4.1 |
| `laravel/framework` | `^13.17` | 13.42.0 | Sin dato |
| `laravel/mcp` | `~1.0.2` | 1.0.2 | [1.2.0](https://github.com/laravel/mcp/releases/tag/v1.2.0) |
| `inertiajs/inertia-laravel` | `^2.0` | 2.0.28 | [3.7.0](https://github.com/inertiajs/inertia-laravel/releases/tag/v3.7.0) |
| `@inertiajs/vue3` | `^2.0.0` | 2.3.29 | 3.11.0 |
| `tailwindcss` | `^3.2.1` | 3.4.19 | 4.3.5 |
| `@tailwindcss/forms` | `^0.5.3` | 0.5.11 | Sin dato |

## T-72

`PasskeyGateway.php` importa tipos de WebAuthn directamente. La restricción `~5.3.0` declara ese uso y coincide con el intervalo `5.3.x` que exige `laravel/passkeys` 0.2.1. La restricción admite correcciones de la rama 5.3 y requiere una revisión explícita antes de adoptar 5.4. Composer conserva los 163 paquetes existentes, incluida la versión 5.3.9. El manifiesto añade una restricción y el lockfile actualiza su huella.

Se mantienen las restricciones vigentes de MCP y Passkeys. Programa sus actualizaciones en ramas independientes tras las dos migraciones mayores. Antes de empezar cada rama, incorpora el lockfile aprobado más reciente y vuelve a resolver sus dependencias.

- **MCP 1.0 → 1.2, 4–8 horas orientativas.** Comprueba los flujos de API keys, OAuth y catálogo con los clientes admitidos. La publicación cambia la validación de origen y las redirecciones del transporte. El manifiesto 1.2 exige PHP 8.2 o superior dentro de la serie 8.x y componentes de Laravel `^12.41.1` o `^13.0`. En la futura rama de MCP, cambia la restricción raíz a `~1.2.0` y ejecuta `composer update laravel/mcp --with-all-dependencies --dry-run` para comprobar la resolución con Laravel 13.42.0. Después de actualizar el lockfile en esa rama, verifica PHP y extensiones con `composer check-platform-reqs --lock`. [Notas de MCP 1.2.0](https://github.com/laravel/mcp/releases/tag/v1.2.0) · [Manifiesto de MCP 1.2.0](https://raw.githubusercontent.com/laravel/mcp/v1.2.0/composer.json)
- **Passkeys PHP 0.2 → 0.3, 4–8 horas orientativas.** La versión 0.3.0 sigue declarando WebAuthn `5.3.x`. Conserva ese intervalo mientras sea compatible y revisa `PasskeyGateway` junto con cualquier cambio posterior de API. Comprueba el contrato del servidor con `@laravel/passkeys ~0.4.0`, el acceso con credenciales ya registradas en Safari de iOS y el acceso con contraseña. Repite la verificación de plataforma y el flujo de navegador. [Notas de Passkeys PHP 0.3.0](https://github.com/laravel/passkeys-server/releases/tag/v0.3.0) · [Manifiesto de Passkeys PHP 0.3.0](https://raw.githubusercontent.com/laravel/passkeys-server/v0.3.0/composer.json)

## Orden de las dos migraciones mayores

1. **Inertia 2 → 3, 12–18 horas orientativas.** Fijar una línea base de compilación y pruebas. Actualizar juntos el adaptador Laravel y el cliente Vue en una rama. El adaptador 3.7 declara PHP `^8.2.0` y Laravel `^11.35`, `^12.0` o `^13.0`. Cambiar `inertia` por `data-inertia` en el título de `resources/views/app.blade.php` y comprobar la configuración y las vistas compiladas. Las pruebas de GIF y de importación multimedia que preparan `data-page` tendrán que adaptarse al nuevo elemento JSON inicial. Revisar el manejo de errores de visita, incluido el listener del editor preparado en T-58, porque v3 sustituye Axios y cambia los eventos. Comprobar cancelación de sondeo en Informes, avisos flash en `HandleInertiaRequests`, reinicio del estado de formularios, autenticación y descarga tras navegación. [Guía oficial de Inertia 3](https://inertiajs.com/docs/v3/getting-started/upgrade-guide) · [Manifiesto de Inertia Laravel 3.7.0](https://raw.githubusercontent.com/inertiajs/inertia-laravel/v3.7.0/composer.json)
2. **Tailwind 3 → 4, 16–24 horas orientativas.** Partir de la rama aprobada de Inertia, con su propia línea base visual. Confirmar que el soporte contratado admite Safari 16.4+, Chrome 111+ y Firefox 128+. Migrar el plugin en `vite.config.js` o usar la integración oficial de PostCSS. Sustituir las directivas de `resources/css/app.css` y trasladar colores, tipografía, sombras y formularios de `tailwind.config.js`. Revisar los orígenes de clases en Blade y Vue, las vistas de paginación y las utilidades dinámicas. Comparar formularios, anillos de foco, bordes, diálogos, barras del editor y desbordamiento en escritorio, 375 px y 360 px. Incluir Safari 16.4, Chrome 111 y Firefox 128, como mínimos, además de las versiones que admite el proyecto. [Guía oficial de Tailwind 4](https://tailwindcss.com/docs/upgrade-guide)

Cada rama requiere instalación limpia de sus lockfiles, compilación, tipos, Pest y Playwright de los recorridos afectados. La declaración directa de T-72 conserva las versiones y los metadatos de los 163 paquetes. El único cambio del lockfile es su huella. El filtro Passkey de Pest pasó 77 pruebas y 558 aserciones. El cierre requiere repetir en navegador el alta, acceso y revocación de credenciales. Inertia requiere comparar navegación, visibilidad y cancelación de peticiones. Tailwind requiere conservar capturas antes y después en los tamaños definidos. Si falla un recorrido, revertir conjuntamente los manifiestos, sus lockfiles y la integración que los acompaña.
