<?php
// GET /reset-password?token=... — set new password
if (web_auth()) {
    header('Location: /admin');
    exit;
}
$pageTitle = 'Reset Password';
$token     = trim((string) ($_GET['token'] ?? ''));
?>

<div class="sm:mx-auto sm:w-full sm:max-w-md">
    <h2 class="text-center text-2xl font-bold text-gray-900 dark:text-white">Choose a new password</h2>
    <p class="mt-1 text-center text-sm text-gray-500 dark:text-gray-400">Must be at least 12 characters.</p>
</div>

<div class="mt-8 sm:mx-auto sm:w-full sm:max-w-md">
    <div class="bg-white dark:bg-gray-900 py-8 px-6 shadow-sm rounded-2xl border border-gray-200 dark:border-gray-800"
         x-data="resetPasswordPage(<?= json_encode($token, JSON_THROW_ON_ERROR) ?>)">

        <div x-show="!token && !done" x-cloak class="text-center text-sm text-red-600">
            Missing reset token. Use the link from your email.
            <div class="mt-3"><a href="/forgot-password" class="text-red-600 hover:underline">Request a new link</a></div>
        </div>

        <div x-show="done" x-cloak class="text-center space-y-4">
            <p class="text-sm text-gray-600 dark:text-gray-400">Password updated. You can sign in now.</p>
            <a href="/admin/login" class="inline-block px-4 py-2 bg-red-600 text-white text-sm font-semibold rounded-xl">Sign in</a>
        </div>

        <form x-show="token && !done" @submit.prevent="submit()" class="space-y-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">New password</label>
                <input type="password" required autocomplete="new-password" minlength="12" x-model="password"
                       class="w-full px-3 py-2 rounded-xl border border-gray-300 dark:border-gray-700
                              bg-white dark:bg-gray-800 text-gray-900 dark:text-white text-sm">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Confirm password</label>
                <input type="password" required autocomplete="new-password" minlength="12" x-model="passwordConfirm"
                       class="w-full px-3 py-2 rounded-xl border border-gray-300 dark:border-gray-700
                              bg-white dark:bg-gray-800 text-gray-900 dark:text-white text-sm">
            </div>
            <p x-show="error" x-text="error" class="text-sm text-red-600 dark:text-red-400"></p>
            <button type="submit" :disabled="loading"
                    class="w-full py-2.5 px-4 bg-red-600 hover:bg-red-700 disabled:opacity-60
                           text-white text-sm font-semibold rounded-xl">
                <span x-text="loading ? 'Saving…' : 'Update password'"></span>
            </button>
        </form>
    </div>
</div>

<script>
function resetPasswordPage(token) {
    return {
        token: token || '', password: '', passwordConfirm: '', loading: false, done: false, error: '',
        async submit() {
            if (this.password !== this.passwordConfirm) {
                this.error = 'Passwords do not match';
                return;
            }
            this.loading = true;
            this.error = '';
            try {
                const res = await fetch('/api/v1/auth/reset-password', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        token: this.token,
                        password: this.password,
                        password_confirm: this.passwordConfirm,
                    }),
                });
                const data = await res.json();
                if (res.ok) {
                    this.done = true;
                } else {
                    this.error = data.error || data.errors?.password?.[0] || 'Reset failed';
                }
            } catch (e) {
                this.error = 'Network error';
            }
            this.loading = false;
        }
    };
}
</script>
