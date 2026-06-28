<?php
// web/templates/pages/auth/login.php
// GET /admin/login - renders the login form
if (web_auth()) {
    header('Location: /admin');
    exit;
}
$pageTitle = 'Sign In';
$error     = $_SESSION['login_error'] ?? null;
unset($_SESSION['login_error']);
if ($error === null && isset($_GET['expired'])) {
    $error = 'Your session expired. Please sign in again.';
}
?>

<div class="sm:mx-auto sm:w-full sm:max-w-md">
    <!-- Logo -->
    <div class="flex justify-center">
        <div class="w-12 h-12 rounded-2xl bg-red-600 flex items-center justify-center shadow-lg">
            <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
                      d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
            </svg>
        </div>
    </div>
    <h2 class="mt-4 text-center text-2xl font-bold text-gray-900 dark:text-white">NexAlert</h2>
    <p class="mt-1 text-center text-sm text-gray-500 dark:text-gray-400">Sign in to the admin console</p>
</div>

<div class="mt-8 sm:mx-auto sm:w-full sm:max-w-md">
    <div class="bg-white dark:bg-gray-900 py-8 px-6 shadow-sm rounded-2xl border border-gray-200 dark:border-gray-800">

        <?php if ($error): ?>
        <div class="mb-4 p-3 rounded-xl bg-red-50 dark:bg-red-950/40 border border-red-200 dark:border-red-900 text-sm text-red-700 dark:text-red-400">
            <?= htmlspecialchars($error) ?>
        </div>
        <?php endif; ?>

        <form method="POST" action="/admin/login" x-data="{ loading: false }" @submit="loading = true">
            <div class="space-y-4">
                <div>
                    <label for="username" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                        Username
                    </label>
                    <input id="username" name="username" type="text" required autocomplete="username"
                           value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                           class="w-full px-3 py-2 rounded-xl border border-gray-300 dark:border-gray-700
                                  bg-white dark:bg-gray-800 text-gray-900 dark:text-white
                                  placeholder-gray-400 dark:placeholder-gray-500
                                  focus:outline-none focus:ring-2 focus:ring-red-500 focus:border-transparent
                                  text-sm transition-colors">
                </div>

                <div>
                    <label for="password" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                        Password
                    </label>
                    <input id="password" name="password" type="password" required autocomplete="current-password"
                           class="w-full px-3 py-2 rounded-xl border border-gray-300 dark:border-gray-700
                                  bg-white dark:bg-gray-800 text-gray-900 dark:text-white
                                  focus:outline-none focus:ring-2 focus:ring-red-500 focus:border-transparent
                                  text-sm transition-colors">
                </div>

                <button type="submit"
                        :disabled="loading"
                        class="w-full flex justify-center items-center gap-2 py-2.5 px-4
                               bg-red-600 hover:bg-red-700 disabled:opacity-60 disabled:cursor-not-allowed
                               text-white text-sm font-semibold rounded-xl
                               focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2
                               transition-colors">
                    <svg x-show="loading" class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                    </svg>
                    <span x-text="loading ? 'Signing in…' : 'Sign in'"></span>
                </button>
            </div>

            <div class="mt-4 text-center">
                <a href="/forgot-password" class="text-sm text-red-600 dark:text-red-400 hover:underline">
                    Forgot password?
                </a>
            </div>
        </form>
    </div>
</div>
