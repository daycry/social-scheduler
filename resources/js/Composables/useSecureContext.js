import { computed, ref } from 'vue';

/**
 * WebAuthn solo existe en contexto seguro (https o localhost) y en navegadores que lo implementan.
 * `canUsePasskeys` decide si se pintan los controles de passkey; si no, la UI muestra `httpsNotice` y el
 * login con contraseña sigue funcionando igual (CA-20).
 */
export const httpsNotice = 'Las passkeys requieren HTTPS y un navegador compatible. Puedes seguir entrando con tu contraseña.';

export function useSecureContext() {
    const secure = ref(typeof window !== 'undefined' && window.isSecureContext === true);
    const hasWebAuthn = ref(typeof window !== 'undefined' && typeof window.PublicKeyCredential !== 'undefined');
    const canUsePasskeys = computed(() => secure.value && hasWebAuthn.value);

    return { secure, hasWebAuthn, canUsePasskeys, httpsNotice };
}

/** Frases que el SERVIDOR manda ya en español y sin datos de la cuenta (PasskeyGateway/PasskeyFailure): se muestran tal cual. */
const serverMessage = /^(No se pudo|La operación|La respuesta|Esta passkey|Demasiados|Ponle|El nombre|Este dispositivo)/;

/**
 * Traduce un error del cliente `@laravel/passkeys` a un mensaje neutro en español. Nunca devuelve el mensaje crudo
 * de la red, del framework o del navegador (inglés, HTML, «CSRF token mismatch»): solo los textos propios del servidor.
 * - `null`: no hay nada que mostrar (sin error, o un AbortError: otra operación de passkey sustituyó a esta).
 * - Cancelar el diálogo del navegador (NotAllowedError) no es un ataque ni un fallo: mensaje tranquilo.
 * - «Esta passkey no es válida en este dominio» es el código `invalid_domain` del servidor (ver PasskeyFailure).
 */
export function passkeyErrorMessage(error) {
    if (!error) {
        return null;
    }

    const message = String(error.message ?? '');

    switch (error.name) {
        case 'AbortError':
            return null;
        case 'UserCancelledError':
            return 'Se canceló la passkey.';
        case 'NotSupportedError':
            return httpsNotice;
        case 'InvalidDomainError':
            return 'Las passkeys no funcionan en este dominio. Usa la dirección segura (https) de la aplicación.';
        case 'PasskeyExistsError':
            return 'Este dispositivo ya está registrado como passkey en tu cuenta.';
    }

    if (/password confirmation required/i.test(message)) {
        return 'password-confirmation';
    }

    // El cliente descarta el JSON del error y solo deja el mensaje: se reconocen por texto o por el código HTTP.
    if (/abort/i.test(message)) {
        return null;
    }

    if (/csrf token mismatch|page expired|status 419/i.test(message)) {
        return 'La página ha caducado, recárgala e inténtalo de nuevo.';
    }

    if (/too many|status 429/i.test(message)) {
        return 'Demasiados intentos. Espera un minuto e inténtalo de nuevo.';
    }

    // Una redirección (302 de `guest`, sesión cambiada) acaba en HTML: `response.json()` falla con un SyntaxError.
    if (/unexpected token|unexpected end of json|not valid json|json\.parse|<!doctype|<html/i.test(message)) {
        return 'Ya has iniciado sesión o la sesión cambió; recarga la página.';
    }

    if (/failed to fetch|networkerror|load failed|network request failed/i.test(message)) {
        return 'No se pudo conectar con el servidor. Revisa tu conexión e inténtalo de nuevo.';
    }

    if (serverMessage.test(message)) {
        return message;
    }

    return 'No se pudo completar la operación con la passkey. Inténtalo de nuevo.';
}
