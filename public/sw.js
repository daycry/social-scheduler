// No fetch handler, Cache Storage or IndexedDB: authenticated pages and user data stay out of worker storage.
self.addEventListener('install', (event) => {
    event.waitUntil(self.skipWaiting());
});

self.addEventListener('activate', (event) => {
    event.waitUntil(self.clients.claim());
});

self.addEventListener('push', (event) => {
    // Generic text also protects privacy when a device has been locked or its web session has ended.
    event.waitUntil(
        self.registration.showNotification('Social Scheduler', {
            body: 'Tienes nuevos avisos.',
            icon: '/icon-192.png',
            badge: '/icon-192.png',
            tag: 'social-scheduler-notifications',
        }),
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    // Never navigate to an endpoint or URL supplied by a push payload.
    event.waitUntil(self.clients.openWindow(new URL('/notifications', self.location.origin).href));
});
