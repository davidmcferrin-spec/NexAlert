<!DOCTYPE html>
<html lang="en" x-data="nexalert()" :class="{ 'dark': darkMode }" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle ?? 'Sign In') ?> — NexAlert</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Inter', 'system-ui', 'sans-serif'],
                    }
                }
            }
        }
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        [x-cloak] { display: none !important; }
        body { font-family: 'Inter', sans-serif; }
    </style>
    <?= $headExtra ?? '' ?>
</head>
<body class="h-full bg-gray-50 dark:bg-gray-950" x-cloak>

<div class="min-h-full flex flex-col justify-center py-12 px-4 sm:px-6 lg:px-8 relative">

    <!-- Theme toggle -->
    <div class="absolute top-4 right-4">
        <button @click="toggleDark()"
                class="p-2 rounded-lg text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors">
            <svg x-show="!darkMode" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/>
            </svg>
            <svg x-show="darkMode" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/>
            </svg>
        </button>
    </div>

    <?= $content ?? '' ?>

</div>

<script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
<script>
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

<?php if (!web_auth()): ?>
localStorage.removeItem('nexalert_token');
<?php endif; ?>
</script>
<?= $scripts ?? '' ?>
</body>
</html>
