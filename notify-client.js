/**
 * NexAlert browser notification poller
 * Polls /api/v1/profile/updates and shows toasts + native Notification API.
 */
(function () {
    if (typeof api === 'undefined' || !api.token) return;

    const STORAGE_KEY = 'nexalert_updates_since';
    const POLL_MS = 22000;

    function defaultSince() {
        return new Date(Date.now() - 60000).toISOString().replace(/\.\d{3}Z$/, 'Z');
    }

    function getSince() {
        return localStorage.getItem(STORAGE_KEY) || defaultSince();
    }

    function setSince(val) {
        if (val) localStorage.setItem(STORAGE_KEY, val);
    }

    function showToast(message, type) {
        window.dispatchEvent(new CustomEvent('toast', {
            detail: { message, type: type || 'info', duration: 8000 },
        }));
    }

    function showBrowserNotification(item) {
        if (typeof Notification === 'undefined') return;
        if (Notification.permission !== 'granted') return;

        try {
            const n = new Notification(item.title || 'NexAlert', {
                body: item.body || '',
                tag: item.id || ('nexalert-' + Date.now()),
                icon: '/favicon.ico',
            });
            n.onclick = function () {
                window.focus();
                if (item.url) window.location.href = item.url;
                n.close();
            };
        } catch (e) { /* ignore */ }
    }

    async function poll() {
        try {
            const since = getSince();
            const res = await api.get('/profile/updates?since=' + encodeURIComponent(since));
            if (!res.ok) return;

            const data = res.data.data || {};
            const items = data.items || [];
            if (!items.length) {
                if (data.server_time) setSince(data.server_time.replace(' ', 'T') + 'Z');
                return;
            }

            items.forEach(function (item) {
                const msg = (item.title || 'NexAlert') + (item.body ? ': ' + item.body : '');
                showToast(msg, item.type === 'chat' ? 'info' : 'success');
                if (document.hidden || Notification.permission === 'granted') {
                    showBrowserNotification(item);
                }
            });

            const last = items[items.length - 1];
            if (last && last.at) {
                setSince(String(last.at).replace(' ', 'T') + 'Z');
            } else if (data.server_time) {
                setSince(data.server_time.replace(' ', 'T') + 'Z');
            }
        } catch (e) { /* network/auth */ }
    }

    window.nexalertRequestNotificationPermission = async function () {
        if (typeof Notification === 'undefined') {
            showToast('Browser notifications are not supported here', 'error');
            return false;
        }
        const p = await Notification.requestPermission();
        if (p === 'granted') {
            showToast('Browser notifications enabled');
            poll();
            return true;
        }
        showToast('Notification permission denied', 'error');
        return false;
    };

    window.nexalertNotificationPermission = function () {
        return typeof Notification !== 'undefined' ? Notification.permission : 'unsupported';
    };

    setTimeout(poll, 3000);
    setInterval(poll, POLL_MS);
})();
