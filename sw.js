// Push notifications: shows the notification sent by the site and opens its link when clicked.
const SITE = self.location.origin;

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', event => event.waitUntil(self.clients.claim()));

self.addEventListener('push', event => {
    let data = {};
    try { data = event.data ? event.data.json() : {}; } catch (e) { data = { body: event.data ? event.data.text() : '' }; }
    const title = data.title || self.location.hostname.replace(/^www\./, ''); // the push always sends a title; fallback = this site's address
    const url = data.url || SITE + '/';
    const options = {
        body: data.body || '',
        icon: data.icon || SITE + '/assets/icons/android-icon-192x192.png',
        badge: SITE + '/assets/badge/ic_stat_notifications_active/res/drawable-xhdpi/ic_stat_notifications_active.png',
        vibrate: [200, 100, 200],
        tag: data.tag || ('edumint24-' + (data.campaign || Date.now())),
        renotify: true,
        requireInteraction: false,
        data: { url: url, whatsappUrl: 'https://api.whatsapp.com/send?text=' + encodeURIComponent(title + ' ' + url) },
        actions: [{ action: 'open', title: 'Open' }, { action: 'whatsapp', title: 'WhatsApp' }]
    };
    if (data.image) options.image = data.image;
    event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', event => {
    event.notification.close();
    const data = event.notification.data || {};
    const targetUrl = event.action === 'whatsapp' ? data.whatsappUrl : (data.url || SITE + '/');
    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then(windowClients => {
            for (const client of windowClients) {
                if (client.url === targetUrl && 'focus' in client) return client.focus();
            }
            if (clients.openWindow) return clients.openWindow(targetUrl);
        })
    );
});
