# Giphy: clave de API, límites y atribución

Guía para activar el botón **GIF** del compositor (buscador de Giphy, C-04b). El código ya está desplegado: solo falta la clave. Sin `GIPHY_API_KEY` el botón no aparece y `GET /gifs` y `POST /media/import-gif` responden 404.

> ⚠️ Esta guía se escribió sin acceso a la red: lo marcado con ⚠️ (pasos del panel de Giphy, límites vigentes y términos legales) **no se ha podido verificar** y debe confirmarlo el usuario en [developers.giphy.com](https://developers.giphy.com) antes de usarlo en producción. Lo demás sale del código del proyecto.

## 1. Crear la clave

1. Entra en [developers.giphy.com](https://developers.giphy.com) con tu cuenta de Giphy (o créala). ⚠️
2. *Create an App* → elige **API** (no SDK) → ponle nombre y descripción (por ejemplo «Social Scheduler, uso personal»). ⚠️
3. Copia la **API Key**. Las claves nuevas son de tipo *beta* (de desarrollo). ⚠️
4. Pásala al servidor, nunca al navegador:

```dotenv
GIPHY_API_KEY=la_clave
```

Después `php artisan config:clear` (o `config:cache`) y reinicia los workers. La clave la usa solo el *proxy* del servidor: no sale a las props de Inertia ni a ninguna respuesta.

## 2. Límites

| Qué | Valor | Dónde |
|---|---|---|
| Clave *beta* de Giphy | 100 llamadas por hora ⚠️ (vigente según la documentación consultada al diseñar; confírmalo en el panel) | developers.giphy.com |
| Tope de llamadas reales de la aplicación | 90 por hora (`SOCIAL_GIPHY_HOURLY_LIMIT`), dejando margen a la clave | `config/social.php` |
| Caché de respuestas | 10 minutos (`SOCIAL_GIPHY_CACHE_MINUTES`); una respuesta en caché no gasta cupo | `config/social.php` |
| Búsquedas por usuario | 20 por minuto; importaciones, 10 por minuto y 3 vivas a la vez | rutas |
| Clasificación | `pg-13` (`SOCIAL_GIPHY_RATING`) | `config/social.php` |

Agotado el cupo, el usuario ve «Has hecho muchas búsquedas de GIF; prueba en unos minutos.» Subir a una clave de producción (más cupo) exige una revisión de Giphy y cumplir su atribución. ⚠️

## 3. Atribución (obligatoria)

El modal del buscador muestra siempre **«Powered by GIPHY»**; no hay opción para ocultarlo y no debe quitarse. Las condiciones de la API de Giphy exigen la atribución al mostrar sus contenidos. ⚠️ (confirma el texto exacto o el logotipo que piden hoy). Comprobación visual en móvil: es el punto M-03 de [pendientes manuales](../referencia/pendientes-manuales.md).

## 4. Términos de uso: descargar y republicar

La aplicación **no enlaza** el GIF: descarga su MP4 y lo vuelve a publicar como vídeo en tus redes (Bluesky y Threads, o Instagram, Facebook y TikTok si dura al menos 3 s). Por eso conviene leer los términos de Giphy sobre este uso:

- ⚠️ **No verificado**: si los términos de la API de Giphy permiten descargar el MP4 y subirlo a una red de terceros, o solo mostrarlo o enlazarlo desde su CDN.
- ⚠️ **No verificado**: qué ocurre con los GIF de marcas o de terceros con derechos propios (el contenido sigue siendo de su autor).
- Decisión: es del usuario (uso personal). Si los términos no lo permiten, deja `GIPHY_API_KEY` vacío y el botón desaparece sin tocar nada más.

## 5. Qué hace con el GIF

Se importa el **MP4** (lista cerrada de hosts `media*.giphy.com` e `i.giphy.com`, solo `https`, puerto 443, comprobada en cada redirección) por la cola `media` y se valida como cualquier vídeo. Detalle técnico en [Media → Buscador de GIFs](../modulos/media.md#buscador-de-gifs-de-giphy-c-04b). Los GIF de menos de 3 s no valen para Reels ni TikTok.
