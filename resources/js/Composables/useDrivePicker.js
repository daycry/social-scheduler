import { ref } from 'vue';

// Google Drive (C-04b): Google Identity Services pide un token `drive.file` y el Picker deja elegir UN fichero de imagen o vídeo.
// Los scripts de Google no se cargan al abrir el compositor sino al acercarse al botón (ratón, foco o toque: `preload`), para que al
// pulsar `requestAccessToken` se llame DENTRO del gesto del usuario (si se espera a la red, el navegador bloquea el popup). El token
// vive únicamente en la memoria de esta llamada: no se guarda en el estado de la página, en el almacenamiento del navegador ni en la URL.
const SCOPE = 'https://www.googleapis.com/auth/drive.file';
const MIME_TYPES = 'image/jpeg,image/png,image/webp,video/mp4,video/quicktime';
const GIS_SRC = 'https://accounts.google.com/gsi/client';
const GAPI_SRC = 'https://apis.google.com/js/api.js';

// Sin respuesta de Google en este tiempo (red colgada, bloqueador de contenido) el botón vuelve a su estado con un mensaje.
const TIMEOUT_MS = 15000;
const LOAD_ERROR = 'No se pudo cargar Google. Revisa tu conexión e inténtalo de nuevo.';
const BLOCKED = 'El navegador ha bloqueado la ventana de Google. Permite las ventanas emergentes para este sitio y vuelve a pulsar «Google Drive».';

const pending = new Map();

const loadScript = (src, ready) => {
    if (ready()) return Promise.resolve();
    if (!pending.has(src)) {
        pending.set(src, new Promise((resolve, reject) => {
            const el = document.createElement('script');
            const timer = setTimeout(() => fail(), TIMEOUT_MS);
            const fail = () => {
                clearTimeout(timer);
                pending.delete(src);
                el.remove();
                reject(new Error(LOAD_ERROR));
            };
            el.src = src;
            el.async = true;
            el.onload = () => {
                clearTimeout(timer);
                resolve();
            };
            el.onerror = fail;
            document.head.appendChild(el);
        }));
    }
    return pending.get(src);
};

const gisReady = () => !!window.google?.accounts?.oauth2;
const gapiReady = () => !!window.gapi?.load;

/**
 * @param {() => ({ api_key: string, app_id: string|null, client_id: string }|null)} getConfig configuración del servidor (null = Drive desactivado)
 */
export function useDrivePicker(getConfig) {
    const busy = ref(false);

    // Con `drive.file` el Picker necesita el número de proyecto (`setAppId`): sin él `files.get` da 404. Misma regla que el servidor.
    const available = () => !!getConfig()?.api_key && !!getConfig()?.app_id && !!getConfig()?.client_id;

    /** Precarga los scripts de Google al acercarse al botón; un fallo se ignora aquí (se reintenta y se informa al pulsar). */
    const preload = () => {
        if (!available()) return;
        loadScript(GIS_SRC, gisReady).catch(() => {});
        loadScript(GAPI_SRC, gapiReady).catch(() => {});
    };

    const requestToken = (clientId) => new Promise((resolve, reject) => {
        const client = window.google.accounts.oauth2.initTokenClient({
            client_id: clientId,
            scope: SCOPE,
            callback: (response) => (response?.access_token ? resolve(response.access_token) : reject(new Error('No se concedió el acceso a Google Drive.'))),
            error_callback: (e) => reject(new Error(e?.type === 'popup_closed' ? '' : e?.type === 'popup_failed_to_open' ? BLOCKED : 'No se concedió el acceso a Google Drive.')),
        });
        client.requestAccessToken({ prompt: '' });
    });

    const openPicker = (config, token) => new Promise((resolve, reject) => {
        const timer = setTimeout(() => reject(new Error(LOAD_ERROR)), TIMEOUT_MS);
        const settle = (fn, value) => {
            clearTimeout(timer);
            fn(value);
        };

        const build = () => {
            try {
                const view = new window.google.picker.DocsView(window.google.picker.ViewId.DOCS).setMimeTypes(MIME_TYPES).setIncludeFolders(true);
                new window.google.picker.PickerBuilder()
                    .setOAuthToken(token)
                    .setDeveloperKey(config.api_key)
                    .setAppId(config.app_id)
                    .setLocale('es')
                    .addView(view)
                    .setCallback((data) => {
                        const action = data[window.google.picker.Response.ACTION];
                        if (action === window.google.picker.Action.PICKED) {
                            const doc = data[window.google.picker.Response.DOCUMENTS]?.[0];
                            settle(resolve, doc ? { fileId: doc[window.google.picker.Document.ID], name: doc[window.google.picker.Document.NAME] } : null);
                        } else if (action === window.google.picker.Action.CANCEL) {
                            settle(resolve, null);
                        }
                    })
                    .build()
                    .setVisible(true);
                // El Picker ya está en pantalla: el usuario puede tardar lo que quiera en elegir.
                clearTimeout(timer);
            } catch {
                settle(reject, new Error(LOAD_ERROR));
            }
        };

        try {
            window.gapi.load('picker', {
                callback: build,
                onerror: () => settle(reject, new Error(LOAD_ERROR)),
                timeout: TIMEOUT_MS,
                ontimeout: () => settle(reject, new Error(LOAD_ERROR)),
            });
        } catch {
            settle(reject, new Error(LOAD_ERROR));
        }
    });

    /** Abre el Picker y devuelve `{ fileId, name, token }`, o `null` si el usuario cancela. Lanza un `Error` con mensaje para el usuario. */
    const pick = async () => {
        const config = getConfig();
        if (!available() || busy.value) return null;
        busy.value = true;
        try {
            // Con los scripts ya precargados no hay `await` antes de `requestAccessToken`: se llama dentro del gesto del usuario.
            if (!gisReady() || !gapiReady()) await Promise.all([loadScript(GIS_SRC, gisReady), loadScript(GAPI_SRC, gapiReady)]);
            const token = await requestToken(config.client_id);
            const file = await openPicker(config, token);
            return file ? { ...file, token } : null;
        } finally {
            busy.value = false;
        }
    };

    return { available, busy, pick, preload };
}
