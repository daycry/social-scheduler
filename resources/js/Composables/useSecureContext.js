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

/**
 * Traduce un error del cliente `@laravel/passkeys` a un mensaje neutro en español.
 * Cancelar el diálogo del navegador (NotAllowedError) no es un ataque ni un fallo: mensaje tranquilo.
 * Los mensajes del servidor (422) ya vienen en español y no revelan nada de la cuenta; se muestran tal cual.
 */
export function passkeyErrorMessage(error) {
    if (!error) {
        return null;
    }

    const message = error.message ?? '';

    switch (error.name) {
        case 'UserCancelledError':
            return 'No se completó la passkey.';
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

    if (/too many|status 429/i.test(message)) {
        return 'Demasiados intentos. Espera un minuto e inténtalo de nuevo.';
    }

    if (/^Request failed with status|^An unknown error/i.test(message) || message === '') {
        return 'No se pudo completar la operación con la passkey. Inténtalo de nuevo.';
    }

    return message;
}
