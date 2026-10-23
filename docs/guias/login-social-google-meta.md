# Login social: pasos manuales de Google y Meta

Guía para activar «Continuar con Google» y «Continuar con Facebook» en la pantalla de acceso ([ADR-004](../knowledge/adr/ADR-004-oauth-mcp-propio-sobre-sanctum-y-login-social-socialite.md)). El código ya está desplegado: solo falta crear las apps de cada proveedor y poner sus credenciales en `.env`.

Reglas del producto que conviene tener presentes:

- **Solo cuentas existentes.** El login social nunca crea usuarios (`REGISTRATION_ENABLED=false`). Quien no tiene cuenta no entra.
- **Sustituye a la contraseña, no al doble factor.** Con 2FA activo, tras el proveedor se pide el reto.
- **Google** vincula solo la primera vez si el email viene verificado por Google **y** la cuenta local tiene el correo verificado. **Facebook** nunca vincula solo: se hace desde *Perfil → Cuentas vinculadas*.
- Credenciales **propias del login**, distintas de las de los conectores de redes (`GOOGLE_CLIENT_*`, `META_APP_*`).
- Con las variables vacías el proveedor queda desactivado: no hay botón y `/auth/{proveedor}/redirect` da 404.

Redirect URIs (sustituye `<APP_URL>` por la URL de la app con esquema y sin barra final, p. ej. `https://midominio.com`, de modo que Google queda en `https://midominio.com/auth/google/callback`; una por entorno que uses, p. ej. el dominio ngrok de pruebas y el de producción):

| Proveedor | Redirect URI |
|---|---|
| Google | `<APP_URL>/auth/google/callback` |
| Facebook | `<APP_URL>/auth/facebook/callback` |

## Google

1. En Google Cloud Console crea un **proyecto dedicado** (por ejemplo «Metricool Login»). Así el consentimiento y la verificación no se mezclan con el proyecto de YouTube, que tiene scopes sensibles.
2. En *Google Auth Platform → Branding* rellena el nombre, el email de soporte, el dominio autorizado (el de producción y, para pruebas, el de ngrok; ⚠️ verificar que Google admite `ngrok-free.app`) y los enlaces a `/privacy` y `/terms`.
3. *Scopes*: `openid`, `email` y `profile`. No son sensibles y no necesitan verificación.
4. *Audiencia*: «External». En modo *Testing* solo entran los *test users* (hasta 100), lo que basta para un uso personal. Para abrirlo a cualquiera, publícalo (sin revisión con scopes no sensibles; ⚠️ verificar).
5. *Clients → Create client → Web application*: añade como *Authorized redirect URIs* las de la tabla.
6. Copia el ID y el secreto en `.env`:

```dotenv
GOOGLE_LOGIN_CLIENT_ID=
GOOGLE_LOGIN_CLIENT_SECRET=
```

## Meta (Facebook)

1. En developers.facebook.com crea una **app nueva** con el caso de uso «Autenticar y solicitar datos a los usuarios con Inicio de sesión con Facebook» (consumidor). No uses la app de conexiones (*Facebook Login for Business*, con permisos de Páginas); ⚠️ verificar que una misma app no admite los dos casos de uso.
2. Dentro del caso de uso añade los permisos `email` y `public_profile` (si no están dentro del caso de uso aparece «Invalid Scopes»).
3. *Configuración → Básica*: dominios de la app (ngrok y producción), URL de la política de privacidad (`/privacy`), condiciones (`/terms`), URL de instrucciones de eliminación de datos, icono, categoría y plataforma «Sitio web».
4. *Inicio de sesión con Facebook → Configuración*: URIs de redirección OAuth válidas (las de la tabla), con «Usar modo estricto» y «Forzar HTTPS» activados. El primer guardado puede no persistir: recarga y comprueba.
5. **Modo de la app.** En *Desarrollo* solo entran los usuarios con rol en la app (administrador, desarrollador o tester), sin revisión de Meta: lo recomendado si los usuarios son pocos y conocidos. Para *Live* hace falta acceso avanzado a `email` y probablemente la verificación de la empresa (⚠️ verificar).
6. Copia el ID y el secreto en `.env`:

```dotenv
META_LOGIN_APP_ID=
META_LOGIN_APP_SECRET=
```

La versión de la API Graph que usa el login sale de `META_GRAPH_VERSION`.

## Comprobación

1. Reinicia PHP-FPM o ejecuta `php artisan config:clear` (o `config:cache` en producción) tras editar `.env`.
2. Abre `/login`: deben verse los botones de los proveedores configurados.
3. Entra con una cuenta que ya existe en la app. Google: primera vez, vincula solo si el correo coincide y ambos están verificados. Facebook: vincúlalo antes en *Perfil → Cuentas vinculadas* (pide la contraseña).
4. Con 2FA activo, comprueba que tras el proveedor aparece el reto.

Estas comprobaciones con los proveedores reales son manuales (M-01 y M-02 del plan de pruebas de la iniciativa): la batería automática usa un proveedor falso que solo existe en `APP_ENV=e2e`.

## Problemas habituales

| Síntoma | Causa probable |
|---|---|
| No aparece el botón | Variables vacías o configuración en caché (`php artisan config:clear`) |
| `redirect_uri_mismatch` (Google) o «URL bloqueada» (Meta) | La redirect URI registrada no coincide exactamente con `<APP_URL>/auth/<proveedor>/callback` (esquema, dominio, barra final) |
| Vuelve a `/login` con «No hay ninguna cuenta vinculada a esta identidad» | No hay cuenta local con ese correo, no está verificada o la identidad de Facebook no se ha vinculado desde el perfil |
| Facebook: «Invalid Scopes: email» | `email` no está añadido dentro del caso de uso |
| «La sesión de inicio ha caducado» | Se tardó demasiado en el proveedor o se abrió el callback en otro navegador |
