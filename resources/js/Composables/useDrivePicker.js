import { ref } from 'vue';

// Google Drive (C-04b): Google Identity Services pide un token `drive.file` y el Picker deja elegir UN fichero de imagen o vídeo.
// Los scripts de Google se cargan solo al pulsar el botón (no al abrir el compositor) y el token vive únicamente en la memoria de
// esta llamada: no se guarda en el estado de la página, en el almacenamiento del navegador ni en la URL.
const SCOPE = 'https://www.googleapis.com/auth/drive.file';
const MIME_TYPES = 'image/jpeg,image/png,image/webp,video/mp4,video/quicktime';
const GIS_SRC = 'https://accounts.google.com/gsi/client';
const GAPI_SRC = 'https://apis.google.com/js/api.js';

const pending = new Map();

const loadScript = (src, ready) => {
    if (ready()) return Promise.resolve();
    if (!pending.has(src)) {
        pending.set(src, new Promise((resolve, reject) => {
            const el = document.createElement('script');
            el.src = src;
            el.async = true;
            el.onload = () => resolve();
            el.onerror = () => {
                pending.delete(src);
                el.remove();
                reject(new Error('No se pudo cargar Google. Revisa tu conexión e inténtalo de nuevo.'));
            };
            document.head.appendChild(el);
        }));
    }
    return pending.get(src);
};

/**
 * @param {() => ({ api_key: string, app_id: string|null, client_id: string }|null)} getConfig configuración del servidor (null = Drive desactivado)
 */
export function useDrivePicker(getConfig) {
    const busy = ref(false);

    const available = () => !!getConfig()?.api_key && !!getConfig()?.client_id;

    const requestToken = (clientId) => new Promise((resolve, reject) => {
        const client = window.google.accounts.oauth2.initTokenClient({
            client_id: clientId,
            scope: SCOPE,
            callback: (response) => (response?.access_token ? resolve(response.access_token) : reject(new Error('No se concedió el acceso a Google Drive.'))),
            error_callback: (e) => reject(new Error(e?.type === 'popup_closed' ? '' : 'No se concedió el acceso a Google Drive.')),
        });
        client.requestAccessToken({ prompt: '' });
    });

    const openPicker = (config, token) => new Promise((resolve) => {
        window.gapi.load('picker', () => {
            const view = new window.google.picker.DocsView(window.google.picker.ViewId.DOCS).setMimeTypes(MIME_TYPES).setIncludeFolders(true);
            const builder = new window.google.picker.PickerBuilder()
                .setOAuthToken(token)
                .setDeveloperKey(config.api_key)
                .setLocale('es')
                .addView(view)
                .setCallback((data) => {
                    const action = data[window.google.picker.Response.ACTION];
                    if (action === window.google.picker.Action.PICKED) {
                        const doc = data[window.google.picker.Response.DOCUMENTS]?.[0];
                        resolve(doc ? { fileId: doc[window.google.picker.Document.ID], name: doc[window.google.picker.Document.NAME] } : null);
                    } else if (action === window.google.picker.Action.CANCEL) {
                        resolve(null);
                    }
                });
            if (config.app_id) builder.setAppId(config.app_id);
            builder.build().setVisible(true);
        });
    });

    /** Abre el Picker y devuelve `{ fileId, name, token }`, o `null` si el usuario cancela. Lanza un `Error` con mensaje para el usuario. */
    const pick = async () => {
        const config = getConfig();
        if (!available() || busy.value) return null;
        busy.value = true;
        try {
            await Promise.all([
                loadScript(GIS_SRC, () => !!window.google?.accounts?.oauth2),
                loadScript(GAPI_SRC, () => !!window.gapi?.load),
            ]);
            const token = await requestToken(config.client_id);
            const file = await openPicker(config, token);
            return file ? { ...file, token } : null;
        } finally {
            busy.value = false;
        }
    };

    return { available, busy, pick };
}
