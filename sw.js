/* NexAlert Web Push service worker */
self.addEventListener('push', function (event) {
    var payload = { title: 'NexAlert', body: '', url: '/profile' };
    try {
        if (event.data) {
            payload = event.data.json();
        }
    } catch (e) { /* use defaults */ }

    event.waitUntil(
        self.registration.showNotification(payload.title || 'NexAlert', {
            body: payload.body || '',
            icon: '/favicon.ico',
            badge: '/favicon.ico',
            data: { url: payload.url || '/profile' },
        })
    );
});

self.addEventListener('notificationclick', function (event) {
    event.notification.close();
    var target = (event.notification.data && event.notification.data.url) || '/profile';
    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (list) {
            for (var i = 0; i < list.length; i++) {
                if (list[i].url.indexOf(target) !== -1 && 'focus' in list[i]) {
                    return list[i].focus();
                }
            }
            if (clients.openWindow) {
                return clients.openWindow(target);
            }
        })
    );
});
