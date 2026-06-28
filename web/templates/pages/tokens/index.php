<?php
$pageTitle    = 'API Tokens';
$pageSubtitle = 'System integration tokens for inbound alerts';

$newTokenOnce = $_SESSION['new_token_display'] ?? null;
unset($_SESSION['new_token_display']);

$headerActions = '
<a href="/admin/tokens/new"
   class="flex items-center gap-2 px-4 py-2 bg-red-600 hover:bg-red-700 text-white text-sm font-semibold rounded-xl transition-colors">
    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
    </svg>
    New Token
</a>';
?>

<div x-data="tokensPage()" x-init="init(<?= $newTokenOnce ? json_encode($newTokenOnce, JSON_THROW_ON_ERROR) : 'null' ?>)">

    <div x-show="newToken" x-cloak
         class="mb-4 p-4 rounded-xl bg-amber-50 dark:bg-amber-950/40 border border-amber-200 dark:border-amber-900">
        <p class="text-sm font-semibold text-amber-800 dark:text-amber-300 mb-2">Copy this token now — it will not be shown again</p>
        <code class="block text-xs font-mono break-all p-3 bg-white dark:bg-gray-900 rounded-lg border border-amber-200 dark:border-amber-800 text-gray-800 dark:text-gray-200"
              x-text="newToken"></code>
        <button @click="copyToken()" class="mt-2 text-xs text-amber-700 dark:text-amber-400 hover:underline">Copy to clipboard</button>
    </div>

    <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 overflow-hidden">
        <div x-show="loading" class="p-8 text-center text-gray-400 text-sm">Loading…</div>
        <div x-show="!loading">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 dark:bg-gray-800/50">
                    <tr>
                        <th class="text-left px-5 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wider">Name</th>
                        <th class="text-left px-5 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wider hidden md:table-cell">Org</th>
                        <th class="text-left px-5 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wider hidden lg:table-cell">Severities</th>
                        <th class="text-left px-5 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wider hidden lg:table-cell">Last Used</th>
                        <th class="text-center px-5 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wider">Status</th>
                        <th class="px-5 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    <template x-for="token in tokens" :key="token.id">
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/40">
                            <td class="px-5 py-3">
                                <a :href="'/admin/tokens/edit?id=' + token.id"
                                   class="font-medium text-gray-900 dark:text-white hover:text-red-600"
                                   x-text="token.name"></a>
                            </td>
                            <td class="px-5 py-3 text-gray-500 hidden md:table-cell" x-text="token.owner_org_name"></td>
                            <td class="px-5 py-3 hidden lg:table-cell">
                                <code class="text-xs text-gray-400 font-mono" x-text="token.allowed_severity"></code>
                            </td>
                            <td class="px-5 py-3 text-gray-400 text-xs hidden lg:table-cell" x-text="token.last_used_at || 'Never'"></td>
                            <td class="px-5 py-3 text-center">
                                <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium"
                                      :class="token.is_active ? 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-400' : 'bg-gray-100 text-gray-500'"
                                      x-text="token.is_active ? 'Active' : 'Inactive'"></span>
                            </td>
                            <td class="px-5 py-3 text-right">
                                <a :href="'/admin/tokens/edit?id=' + token.id" class="text-xs text-gray-400 hover:text-gray-600 mr-3">Edit</a>
                                <button @click="deactivate(token)" class="text-xs text-red-400 hover:text-red-600" x-show="token.is_active">Revoke</button>
                            </td>
                        </tr>
                    </template>
                    <tr x-show="!loading && tokens.length === 0">
                        <td colspan="6" class="px-5 py-8 text-center text-sm text-gray-400">
                            No tokens yet. <a href="/admin/tokens/new" class="text-red-600 hover:underline">Create one</a>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
function tokensPage() {
    return {
        tokens: [], loading: true, newToken: null,

        async init(pendingToken) {
            this.newToken = pendingToken;
            await this.loadTokens();
        },

        async loadTokens() {
            this.loading = true;
            const res = await api.get('/tokens?limit=100');
            if (res.ok) this.tokens = res.data.data.tokens;
            this.loading = false;
        },

        copyToken() {
            if (this.newToken) {
                navigator.clipboard.writeText(this.newToken);
                toast('Token copied to clipboard');
            }
        },

        async deactivate(token) {
            if (!confirm(`Revoke token "${token.name}"? External systems will lose access.`)) return;
            const res = await api.delete(`/tokens/${token.id}`);
            if (res.ok) {
                toast('Token revoked');
                await this.loadTokens();
            } else {
                toast(res.data.error || 'Failed', 'error');
            }
        }
    };
}
</script>
