<!DOCTYPE html>
<html lang="en" x-data="nexalert()" :class="{ 'dark': darkMode }" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle ?? 'My Profile') ?> — NexAlert</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { darkMode: 'class' };</script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>[x-cloak]{display:none!important} body{font-family:Inter,sans-serif}</style>
</head>
<body class="h-full bg-gray-50 dark:bg-gray-950 text-gray-900 dark:text-gray-100" x-cloak>
<div class="min-h-full">
    <header class="bg-white dark:bg-gray-900 border-b border-gray-200 dark:border-gray-800">
        <div class="max-w-3xl mx-auto px-4 py-4 flex items-center justify-between">
            <div>
                <div class="text-sm font-bold">NexAlert</div>
                <div class="text-xs text-gray-400">My Profile</div>
            </div>
            <div class="flex items-center gap-3 text-sm">
                <?php if (web_auth()): ?>
                <span class="text-gray-500 hidden sm:inline"><?= htmlspecialchars($_SESSION['user']['display_name'] ?? '') ?></span>
                <a href="/admin" class="text-gray-400 hover:text-gray-600">Admin</a>
                <a href="/admin/logout" class="text-gray-400 hover:text-red-600">Sign out</a>
                <?php else: ?>
                <a href="/admin/login" class="text-red-600 font-medium">Sign in</a>
                <?php endif; ?>
            </div>
        </div>
    </header>
    <main class="max-w-3xl mx-auto px-4 py-8">
        <?= $content ?? '' ?>
    </main>
</div>

<div class="fixed bottom-4 right-4 z-50 space-y-2"
     x-data="toasts()"
     @toast.window="add($event.detail)">
    <template x-for="toast in list" :key="toast.id">
        <div class="flex items-center gap-3 px-4 py-3 rounded-xl shadow-lg text-sm font-medium max-w-sm border"
             :class="toast.type === 'error'
                ? 'bg-red-50 dark:bg-red-950 border-red-200 dark:border-red-800 text-red-700 dark:text-red-400'
                : 'bg-white dark:bg-gray-800 border-gray-200 dark:border-gray-700 text-gray-900 dark:text-gray-100'"
             x-transition>
            <span x-text="toast.message" class="flex-1"></span>
            <button @click="remove(toast.id)" class="opacity-50 hover:opacity-100">✕</button>
        </div>
    </template>
</div>

<script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
<script>
const api = {
    token: null,
    init(t) { this.token = t || localStorage.getItem('nexalert_token'); },
    async request(method, path, body) {
        const opts = { method, headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + this.token } };
        if (body) opts.body = JSON.stringify(body);
        const res = await fetch('/api/v1' + path, { ...opts, cache: 'no-store' });
        const data = await res.json();
        if (res.status === 401) { window.location.href = '/admin/login?redirect=' + encodeURIComponent(location.pathname); throw new Error('auth'); }
        return { ok: res.ok, status: res.status, data };
    },
    get: (p) => api.request('GET', p),
    post: (p, b) => api.request('POST', p, b),
    put: (p, b) => api.request('PUT', p, b),
    delete: (p) => api.request('DELETE', p),
};
api.init(<?= json_encode($_SESSION['access_token'] ?? '', JSON_THROW_ON_ERROR) ?>);
function nexalert() {
    return {
        darkMode: localStorage.getItem('nexalert_dark') === '1',
        toggleDark() { this.darkMode = !this.darkMode; localStorage.setItem('nexalert_dark', this.darkMode ? '1' : '0'); }
    };
}
function toasts() {
    return {
        list: [], nextId: 1,
        add({ message, type = 'success', duration = 4000 }) {
            const id = this.nextId++;
            this.list.push({ id, message, type });
            setTimeout(() => this.remove(id), duration);
        },
        remove(id) { this.list = this.list.filter(t => t.id !== id); }
    };
}
function toast(message, type = 'success') {
    window.dispatchEvent(new CustomEvent('toast', { detail: { message, type } }));
}
</script>
<?= $scripts ?? '' ?>
</body>
</html>
