/** Public worker registration never requests notification permission. */
export function pushSupported() {
    return (
        typeof window !== 'undefined' &&
        window.isSecureContext &&
        'serviceWorker' in navigator &&
        'PushManager' in window &&
        typeof Notification !== 'undefined'
    );
}

export async function registerPushWorker() {
    if (!pushSupported()) return null;
    return bounded(navigator.serviceWorker.register('/sw.js', { scope: '/', updateViaCache: 'none' }));
}

/** @param {string} publicKey @returns {Promise<PushSubscriptionJSON>} */
export async function activatePush(publicKey) {
    if (!pushSupported()) throw new Error('Este navegador necesita HTTPS y compatibilidad con notificaciones push.');
    // First async operation: called directly by the user's click, preserving the permission gesture.
    const permission = await Notification.requestPermission();
    if (permission !== 'granted')
        throw new Error(
            'No has concedido permiso para las notificaciones. Puedes cambiarlo en los ajustes del navegador.',
        );
    await registerPushWorker();
    const registration = await bounded(navigator.serviceWorker.ready);
    const binary = atob(publicKey.replace(/-/g, '+').replace(/_/g, '/'));
    const key = Uint8Array.from(binary, (character) => character.charCodeAt(0));
    let subscription = await bounded(registration.pushManager.getSubscription());
    const previous = subscription?.options?.applicationServerKey;
    if (subscription && previous && !sameKey(new Uint8Array(previous), key)) {
        await bounded(subscription.unsubscribe());
        subscription = null;
    }
    subscription ??= await bounded(
        registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: key }),
    );
    return subscription.toJSON();
}

/** @param {Uint8Array} left @param {Uint8Array} right */
function sameKey(left, right) {
    return left.length === right.length && left.every((byte, index) => byte === right[index]);
}

export async function revokeBrowserPush() {
    if (!pushSupported()) return;
    await registerPushWorker();
    const registration = await bounded(navigator.serviceWorker.ready);
    const subscription = await bounded(registration.pushManager.getSubscription());
    if (subscription) await bounded(subscription.unsubscribe());
}

/** @template T @param {Promise<T>} pending @returns {Promise<T>} */
async function bounded(pending) {
    /** @type {ReturnType<typeof setTimeout> | undefined} */
    let timer;
    /** @type {Promise<never>} */
    const deadline = new Promise((_, reject) => {
        timer = setTimeout(
            () => reject(new Error('El navegador no ha respondido a tiempo. Inténtalo de nuevo.')),
            3000,
        );
    });
    try {
        return await Promise.race([pending, deadline]);
    } finally {
        if (timer !== undefined) clearTimeout(timer);
    }
}
