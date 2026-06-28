<?php
// GET /forgot-password — request password reset email
if (web_auth()) {
    header('Location: /admin');
    exit;
}
$pageTitle = 'Forgot Password';
?>

<div class="sm:mx-auto sm:w-full sm:max-w-md">
    <h2 class="text-center text-2xl font-bold text-gray-900 dark:text-white">Reset your password</h2>
    <p class="mt-1 text-center text-sm text-gray-500 dark:text-gray-400">
        Enter your username and we&rsquo;ll email a reset link if the account exists.
    </p>
</div>

<div class="mt-8 sm:mx-auto sm:w-full sm:max-w-md">
    <div class="bg-white dark:bg-gray-900 py-8 px-6 shadow-sm rounded-2xl border border-gray-200 dark:border-gray-800"
         x-data="forgotPasswordPage()">
        <div x-show="sent" x-cloak class="text-center space-y-4">
            <p class="text-sm text-gray-600 dark:text-gray-400">
                If that account exists, a reset email has been sent. Check your inbox and follow the link.
            </p>
            <a href="/admin/login" class="inline-block text-sm text-red-600 hover:underline">Back to sign in</a>
        </div>

        <form x-show="!sent" @submit.prevent="submit()" class="space-y-4">
            <div>
                <label for="username" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Username</label>
                <input id="username" type="text" required autocomplete="username" x-model="username"
                       class="w-full px-3 py-2 rounded-xl border border-gray-300 dark:border-gray-700
                              bg-white dark:bg-gray-800 text-gray-900 dark:text-white text-sm">
            </div>
            <p x-show="error" x-text="error" class="text-sm text-red-600 dark:text-red-400"></p>
            <button type="submit" :disabled="loading"
                    class="w-full py-2.5 px-4 bg-red-600 hover:bg-red-700 disabled:opacity-60
                           text-white text-sm font-semibold rounded-xl">
                <span x-text="loading ? 'Sending…' : 'Send reset link'"></span>
            </button>
            <div class="text-center">
                <a href="/admin/login" class="text-sm text-gray-500 hover:text-gray-700">Back to sign in</a>
            </div>
        </form>
    </div>
</div>

<script>
function forgotPasswordPage() {
    return {
        username: '', loading: false, sent: false, error: '',
        async submit() {
            this.loading = true;
            this.error = '';
            try {
                const res = await fetch('/api/v1/auth/forgot-password', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ username: this.username }),
                });
                const data = await res.json();
                if (res.ok) {
                    this.sent = true;
                } else {
                    this.error = data.error || 'Request failed';
                }
            } catch (e) {
                this.error = 'Network error';
            }
            this.loading = false;
        }
    };
}
</script>
