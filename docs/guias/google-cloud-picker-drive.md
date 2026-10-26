# Google Cloud: Picker de Drive y reconexión de YouTube

Guía para activar el botón «Google Drive» del compositor (importar media desde Drive) y para reconectar YouTube con el permiso del primer comentario. El código ya está desplegado: solo faltan los pasos de la consola de Google y las variables de `.env`.

Reglas del producto que conviene tener presentes:

- **Sin clave, sin botón.** Con `GOOGLE_PICKER_API_KEY` vacía no aparece «Google Drive» ni se carga ningún script de Google. «Desde URL» sigue funcionando.
- **Permiso mínimo.** Se pide `drive.file`: la app solo ve los ficheros que el usuario elige en el Picker, no su Drive entero. El token solo viaja una vez al servidor, cifrado en el job, y no se guarda.
- **Cliente OAuth ya existente.** Se usa el mismo cliente que los conectores (`GOOGLE_CLIENT_ID`), distinto del cliente del login social.
- **Producción:** `https://social-scheduler.daycry.ovh`.

## 1. Activar las APIs

En Google Cloud Console, en el proyecto de los conectores (el de `GOOGLE_CLIENT_ID`), *APIs y servicios → Biblioteca*:

1. Activa **Google Picker API**.
2. Activa **Google Drive API**.

## 2. Clave de API restringida al dominio

1. *APIs y servicios → Credenciales → Crear credenciales → Clave de API*.
2. *Restricciones de la aplicación*: **Sitios web (referrer HTTP)** y añade los referrers donde vive la app, con comodín de ruta: `https://social-scheduler.daycry.ovh/*` (y, para pruebas, el dominio de desarrollo que uses).
3. *Restricciones de la API*: limítala a **Google Picker API**.
4. Copia la clave en `GOOGLE_PICKER_API_KEY`.

El número de proyecto (el «Project number» del panel de Google Cloud, no el ID) va en `GOOGLE_PICKER_APP_ID`.

## 3. Pantalla de consentimiento y cliente OAuth

1. *Google Auth Platform → Acceso a los datos* (scopes): añade `https://www.googleapis.com/auth/drive.file`. Es un scope **no sensible**, no exige verificación.
2. *Clients →* el cliente web de los conectores → **Orígenes de JavaScript autorizados**: añade `https://social-scheduler.daycry.ovh` (sin barra final ni ruta). Sin esto el Picker falla al pedir el token en el navegador. Los *redirect URIs* no cambian.
3. Si la audiencia está en *Testing*, tu usuario debe figurar como *test user*.

## 4. Variables de `.env`

```dotenv
GOOGLE_CLIENT_ID=            # el de los conectores (ya existe)
GOOGLE_PICKER_API_KEY=       # clave del paso 2
GOOGLE_PICKER_APP_ID=        # número de proyecto
```

Después, `php artisan config:clear` (o `config:cache` en producción). La importación por pasadas necesita además la cola `media` y su cron `ss-media` ([despliegue](../modulos/despliegue.md#cron-y-workers)), con `media` en `QUEUE_NAMES`.

## 5. Reconectar YouTube para el primer comentario

El primer comentario de YouTube usa `commentThreads.insert`, que exige el scope `https://www.googleapis.com/auth/youtube.force-ssl`. Los canales conectados antes no lo tienen:

1. Entra en *Conexiones* y pulsa **+ Conectar** en la tarjeta de YouTube (el botón «Reconectar» de la cuenta solo aparece si ya figura como no activa; un canal activo sin el permiso nuevo se actualiza con «+ Conectar», que sustituye sus tokens al reconocer la misma cuenta por su identificador).
2. En la pantalla de Google acepta el permiso nuevo (gestionar comentarios del canal).
3. **Efecto:** la reconexión sustituye los tokens actuales por unos nuevos con el scope añadido; los anteriores dejan de valer. Las publicaciones ya programadas siguen su curso con la cuenta reconectada.

Mientras no se reconecte, el control del primer comentario de YouTube avisa «Reconecta el canal para comentar» y el resto de la publicación no se ve afectado. Con la audiencia en *Testing*, los tokens caducan a los 7 días: publica la app («En producción») para que no caduquen.

## Comprobación

1. Abre un borrador: debe verse el botón **Google Drive** junto a «Importar».
2. Púlsalo, concede el acceso y elige un fichero: aparece la barra de progreso y pasa a `listo`.
3. Tras el smoke, `grep -c "ya29" storage/logs/*.log` debe dar 0 (el token no se registra).

Estas comprobaciones con Google real son manuales (ver [pendientes manuales](../referencia/pendientes-manuales.md#metricool-parity-2)).

## Problemas habituales

| Síntoma | Causa | Solución |
|---|---|---|
| No aparece el botón «Google Drive» | `GOOGLE_PICKER_API_KEY` vacía o configuración en caché | Rellenarla y `php artisan config:clear` |
| El Picker se abre en blanco o da `origin_mismatch` | Falta el dominio en los orígenes JavaScript autorizados | Paso 3.2 |
| `API_KEY_HTTP_REFERRER_BLOCKED` | La clave está restringida a otro referrer | Paso 2.2 |
| «El acceso a Google Drive ha caducado…» | El token dura una hora o el fichero ya no es legible | Volver a elegir el fichero |
| La importación queda en «pendiente» | Falta el worker de la cola `media` | Cron `ss-media` y `QUEUE_NAMES` |
