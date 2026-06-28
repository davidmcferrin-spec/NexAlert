<!DOCTYPE html>
<html lang="en" x-data="nexalert()" :class="{ 'dark': darkMode }" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle ?? 'NexAlert') ?> — NexAlert</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        brand: {
                            50:  '#fff1f1',
                            100: '#ffe0e0',
                            200: '#ffc7c7',
                            300: '#ffa0a0',
                            400: '#ff6b6b',
                            500: '#f83b3b',
                            600: '#e51c1c',
                            700: '#c11414',
                            800: '#a01414',
                            900: '#841818',
                            950: '#480707',
                        }
                    },
                    fontFamily: {
                        sans: ['Inter', 'system-ui', 'sans-serif'],
                        mono: ['JetBrains Mono', 'monospace'],
                    }
                }
            }
        }
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <style>
        [x-cloak] { display: none !important; }

        /* Smooth theme transition */
        *, *::before, *::after {
            transition: background-color 0.15s ease, border-color 0.15s ease;
        }
        /* But not layout transitions */
        .no-transition { transition: none !important; }

        /* Custom scrollbar */
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
        .dark ::-webkit-scrollbar-thumb { background: #334155; }

        /* Sidebar active state */
        .nav-item.active {
            @apply bg-brand-600 text-white;
        }

        /* Table row hover */
        tbody tr { cursor: default; }

        /* Severity badge colors */
        .badge-test        { @apply bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400; }
        .badge-info        { @apply bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300; }
        .badge-notice      { @apply bg-indigo-100 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300; }
        .badge-warning     { @apply bg-yellow-100 text-yellow-700 dark:bg-yellow-900/40 dark:text-yellow-300; }
        .badge-critical    { @apply bg-orange-100 text-orange-700 dark:bg-orange-900/40 dark:text-orange-300; }
        .badge-evacuation  { @apply bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300; }
    </style>
    <?= $headExtra ?? '' ?>
</head>
<body class="h-full bg-gray-50 dark:bg-gray-950 font-sans antialiased" x-cloak>

<div class="flex h-full">

    <!-- ================================================================
         SIDEBAR
         ================================================================ -->
    <aside class="w-64 flex-shrink-0 flex flex-col bg-white dark:bg-gray-900 border-r border-gray-200 dark:border-gray-800 h-screen sticky top-0 overflow-y-auto">

        <!-- Logo -->
        <div class="flex items-center gap-2 px-5 py-5 border-b border-gray-100 dark:border-gray-800">
            <div class="w-8 h-8 rounded-lg bg-brand-600 flex items-center justify-center flex-shrink-0">
                <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
                          d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
                </svg>
            </div>
            <div>
                <div class="text-sm font-bold text-gray-900 dark:text-white leading-none">NexAlert</div>
                <div class="text-xs text-gray-400 dark:text-gray-500 mt-0.5">Admin Console</div>
            </div>
        </div>

        <!-- Nav -->
        <nav class="flex-1 px-3 py-4 space-y-0.5">

            <?php
            $navItems = [
                ['href' => '/admin', 'label' => 'Dashboard', 'icon' => 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6'],
                ['href' => '/admin/orgs', 'label' => 'Organizations', 'icon' => 'M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-2 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4'],
                ['href' => '/admin/users', 'label' => 'Users', 'icon' => 'M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z'],
                ['href' => '/admin/groups', 'label' => 'Groups', 'icon' => 'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z'],
                ['href' => '/admin/tags', 'label' => 'Tags', 'icon' => 'M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-5 5a2 2 0 01-2.828 0l-7-7A2 2 0 013 8V5a2 2 0 012-2z'],
                'divider',
                ['href' => '/admin/test-send', 'label' => 'Test Send', 'icon' => 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4'],
                ['href' => '/admin/alerts/new', 'label' => 'Send Alert', 'icon' => 'M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z', 'highlight' => true],
                ['href' => '/admin/alerts/history', 'label' => 'Alert History', 'icon' => 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2'],
                'divider',
                ['href' => '/admin/tokens', 'label' => 'API Tokens', 'icon' => 'M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z'],
                ['href' => '/admin/audit', 'label' => 'Audit Log', 'icon' => 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z'],
            ];

            $currentPath = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');

            foreach ($navItems as $item):
                if ($item === 'divider'): ?>
                    <div class="my-2 border-t border-gray-100 dark:border-gray-800"></div>
                <?php continue; endif;

                $isActive = $currentPath === $item['href']
                    || ($item['href'] !== '/admin' && str_starts_with($currentPath, $item['href']));
                $highlight = $item['highlight'] ?? false;
            ?>
                <a href="<?= $item['href'] ?>"
                   class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium transition-colors
                          <?= $isActive
                              ? 'bg-brand-600 text-white'
                              : ($highlight
                                  ? 'text-brand-600 dark:text-brand-400 hover:bg-brand-50 dark:hover:bg-brand-950/50'
                                  : 'text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800 hover:text-gray-900 dark:hover:text-gray-100'
                                )
                          ?>">
                    <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="<?= $item['icon'] ?>"/>
                    </svg>
                    <?= htmlspecialchars($item['label']) ?>
                </a>
            <?php endforeach; ?>
        </nav>

        <!-- Bottom: user + theme toggle -->
        <div class="border-t border-gray-100 dark:border-gray-800 p-3 space-y-1">

            <!-- Theme toggle -->
            <button @click="toggleDark()"
                    class="w-full flex items-center gap-3 px-3 py-2 rounded-lg text-sm text-gray-600 dark:text-gray-400
                           hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors">
                <svg x-show="!darkMode" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/>
                </svg>
                <svg x-show="darkMode" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/>
                </svg>
                <span x-text="darkMode ? 'Light mode' : 'Dark mode'"></span>
            </button>

            <!-- Current user -->
            <div class="flex items-center gap-3 px-3 py-2 rounded-lg">
                <div class="w-7 h-7 rounded-full bg-brand-600 flex items-center justify-center flex-shrink-0">
                    <span class="text-xs font-bold text-white">
                        <?= strtoupper(substr($_SESSION['user']['display_name'] ?? 'U', 0, 1)) ?>
                    </span>
                </div>
                <div class="flex-1 min-w-0">
                    <div class="text-xs font-medium text-gray-900 dark:text-white truncate">
                        <?= htmlspecialchars($_SESSION['user']['display_name'] ?? 'User') ?>
                    </div>
                    <div class="text-xs text-gray-400 truncate">
                        <?= htmlspecialchars($_SESSION['user']['roles'][0] ?? '') ?>
                    </div>
                </div>
                <a href="/admin/logout" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 transition-colors" title="Sign out">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                    </svg>
                </a>
            </div>
        </div>
    </aside>

    <!-- ================================================================
         MAIN CONTENT
         ================================================================ -->
    <div class="flex-1 flex flex-col min-w-0 overflow-auto">

        <!-- Page header -->
        <header class="sticky top-0 z-10 bg-white/80 dark:bg-gray-900/80 backdrop-blur border-b border-gray-200 dark:border-gray-800 px-6 py-4">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-lg font-semibold text-gray-900 dark:text-white">
                        <?= htmlspecialchars($pageTitle ?? 'Dashboard') ?>
                    </h1>
                    <?php if (!empty($pageSubtitle)): ?>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5"><?= htmlspecialchars($pageSubtitle) ?></p>
                    <?php endif; ?>
                </div>
                <div class="flex items-center gap-3">
                    <?= $headerActions ?? '' ?>
                </div>
            </div>
        </header>

        <!-- Flash messages -->
        <?php if (!empty($_SESSION['flash'])): ?>
        <div class="px-6 pt-4" x-data="{ show: true }" x-show="show" x-transition>
            <?php foreach ($_SESSION['flash'] as $flash): ?>
            <div class="flex items-start gap-3 p-4 rounded-xl mb-2
                        <?= $flash['type'] === 'error'
                            ? 'bg-red-50 dark:bg-red-950/40 border border-red-200 dark:border-red-900 text-red-700 dark:text-red-400'
                            : 'bg-green-50 dark:bg-green-950/40 border border-green-200 dark:border-green-900 text-green-700 dark:text-green-400'
                        ?>">
                <svg class="w-5 h-5 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <?php if ($flash['type'] === 'error'): ?>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    <?php else: ?>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    <?php endif; ?>
                </svg>
                <div class="flex-1 text-sm"><?= htmlspecialchars($flash['message']) ?></div>
                <button @click="show = false" class="opacity-60 hover:opacity-100 transition-opacity">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>
            <?php endforeach; unset($_SESSION['flash']); ?>
        </div>
        <?php endif; ?>

        <!-- Page content -->
        <main class="flex-1 p-6">
            <?= $content ?? '' ?>
        </main>

    </div>
</div>

<!-- ================================================================
     GLOBAL MODAL SLOT
     ================================================================ -->
<div x-data="modal()"
     x-show="open"
     x-cloak
     @open-modal.window="openModal($event.detail)"
     @close-modal.window="open = false"
     class="fixed inset-0 z-50 flex items-center justify-center p-4"
     x-transition:enter="transition ease-out duration-150"
     x-transition:enter-start="opacity-0"
     x-transition:enter-end="opacity-100"
     x-transition:leave="transition ease-in duration-100"
     x-transition:leave-start="opacity-100"
     x-transition:leave-end="opacity-0">

    <!-- Backdrop -->
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" @click="open = false"></div>

    <!-- Panel -->
    <div class="relative bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-lg
                border border-gray-200 dark:border-gray-700 overflow-hidden"
         x-transition:enter="transition ease-out duration-150"
         x-transition:enter-start="opacity-0 scale-95"
         x-transition:enter-end="opacity-100 scale-100"
         @click.stop>
        <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100 dark:border-gray-800">
            <h3 class="text-base font-semibold text-gray-900 dark:text-white" x-text="title"></h3>
            <button @click="open = false" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 transition-colors">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
        <div class="p-6" x-html="body"></div>
    </div>
</div>

<!-- Toast notifications -->
<div class="fixed bottom-4 right-4 z-50 space-y-2"
     x-data="toasts()"
     @toast.window="add($event.detail)">
    <template x-for="toast in list" :key="toast.id">
        <div class="flex items-center gap-3 px-4 py-3 rounded-xl shadow-lg text-sm font-medium
                    max-w-sm border"
             :class="toast.type === 'error'
                ? 'bg-red-50 dark:bg-red-950 border-red-200 dark:border-red-800 text-red-700 dark:text-red-400'
                : 'bg-white dark:bg-gray-800 border-gray-200 dark:border-gray-700 text-gray-900 dark:text-gray-100'"
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0 translate-x-4"
             x-transition:enter-end="opacity-100 translate-x-0"
             x-transition:leave="transition ease-in duration-150"
             x-transition:leave-start="opacity-100"
             x-transition:leave-end="opacity-0">
            <span x-text="toast.message" class="flex-1"></span>
            <button @click="remove(toast.id)" class="opacity-50 hover:opacity-100 transition-opacity flex-shrink-0">✕</button>
        </div>
    </template>
</div>

<script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
<script>
// -----------------------------------------------------------------------
// API helper - all fetch calls go through here
// -----------------------------------------------------------------------
const api = {
    token: null,

    init(sessionToken) {
        if (sessionToken) {
            this.token = sessionToken;
            localStorage.setItem('nexalert_token', sessionToken);
        } else {
            this.token = localStorage.getItem('nexalert_token');
        }
    },

    async request(method, path, body = null) {
        const opts = {
            method,
            headers: {
                'Content-Type': 'application/json',
                'Authorization': `Bearer ${this.token}`,
            },
        };
        if (body) opts.body = JSON.stringify(body);

        const res = await fetch('/api/v1' + path, { ...opts, cache: 'no-store' });
        const data = await res.json();

        if (res.status === 401) {
            window.location.href = '/admin/login';
            throw new Error('Unauthorized');
        }

        return { ok: res.ok, status: res.status, data };
    },

    get:    (path)        => api.request('GET',    path),
    post:   (path, body)  => api.request('POST',   path, body),
    put:    (path, body)  => api.request('PUT',    path, body),
    patch:  (path, body)  => api.request('PATCH',  path, body),
    delete: (path)        => api.request('DELETE', path),
};

api.init(<?= json_encode($_SESSION['access_token'] ?? '', JSON_THROW_ON_ERROR) ?>);

// -----------------------------------------------------------------------
// Page refresh — list pages register a reload fn; called on bfcache restore
// and when returning to a background tab so tables stay current after creates.
// -----------------------------------------------------------------------
window.__nexalertPageRefresh = null;

function registerPageRefresh(fn) {
    window.__nexalertPageRefresh = fn;
}

document.addEventListener('DOMContentLoaded', () => {
    window.__nexalertPageRefresh = null;
});

window.addEventListener('pageshow', (e) => {
    if (e.persisted && typeof window.__nexalertPageRefresh === 'function') {
        window.__nexalertPageRefresh();
    }
});

// -----------------------------------------------------------------------
// Root Alpine app - theme management
// -----------------------------------------------------------------------
function nexalert() {
    return {
        darkMode: localStorage.getItem('nexalert_dark') === '1'
                  || (!localStorage.getItem('nexalert_dark') && window.matchMedia('(prefers-color-scheme: dark)').matches),

        toggleDark() {
            this.darkMode = !this.darkMode;
            localStorage.setItem('nexalert_dark', this.darkMode ? '1' : '0');
        }
    };
}

// -----------------------------------------------------------------------
// Modal
// -----------------------------------------------------------------------
function modal() {
    return {
        open: false,
        title: '',
        body: '',
        openModal({ title, body }) {
            this.title = title;
            this.body  = body;
            this.open  = true;
        }
    };
}

// -----------------------------------------------------------------------
// Toast notifications
// -----------------------------------------------------------------------
function toasts() {
    return {
        list: [],
        nextId: 1,
        add({ message, type = 'success', duration = 4000 }) {
            const id = this.nextId++;
            this.list.push({ id, message, type });
            setTimeout(() => this.remove(id), duration);
        },
        remove(id) {
            this.list = this.list.filter(t => t.id !== id);
        }
    };
}

// -----------------------------------------------------------------------
// Global helpers
// -----------------------------------------------------------------------
/** MySQL TINYINT arrives as string "0"/"1" — never use bare truthiness in Alpine. */
function isActive(v) { return Number(v) === 1; }
function isLocked(v) { return Number(v) === 1; }
function isSystem(v) { return Number(v) === 1; }

function toast(message, type = 'success') {
    window.dispatchEvent(new CustomEvent('toast', { detail: { message, type } }));
}

function confirm_delete(message, onConfirm) {
    if (window.confirm(message)) onConfirm();
}
</script>
<?= $scripts ?? '' ?>
</body>
</html>
