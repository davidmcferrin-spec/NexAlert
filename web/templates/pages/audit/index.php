<?php
$pageTitle    = 'Audit Log';
$pageSubtitle = 'Append-only record of all system actions';
?>

<div x-data="auditPage()" x-init="init()">

    <div class="flex flex-wrap items-center gap-3 mb-4">
        <input type="search" placeholder="Search action, entity, user…" x-model.debounce.300ms="search"
               @input="loadEntries()"
               <?= tip_attr('Search audit entries by action name, entity type, or actor', 'bottom') ?>
               class="px-3 py-2 text-sm rounded-xl border border-gray-200 dark:border-gray-700
                      bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-red-500 w-72">

        <input type="text" placeholder="Action prefix (e.g. user.)" x-model="actionFilter"
               @change="loadEntries()"
               class="px-3 py-2 text-sm rounded-xl border border-gray-200 dark:border-gray-700
                      bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 focus:ring-2 focus:ring-red-500 w-48">

        <div class="ml-auto text-sm text-gray-400" x-text="total + ' entries'"></div>
    </div>

    <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 overflow-hidden">
        <div x-show="loading" class="p-8 text-center text-gray-400 text-sm">Loading…</div>
        <div x-show="!loading" class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 dark:bg-gray-800/50">
                    <tr>
                        <th class="text-left px-5 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wider whitespace-nowrap">Time</th>
                        <th class="text-left px-5 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wider">Action</th>
                        <th class="text-left px-5 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wider hidden md:table-cell">Entity</th>
                        <th class="text-left px-5 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wider hidden lg:table-cell">Actor</th>
                        <th class="text-left px-5 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wider hidden xl:table-cell">IP</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    <template x-for="entry in entries" :key="entry.id">
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/40">
                            <td class="px-5 py-3 text-xs text-gray-500 whitespace-nowrap" x-text="formatTime(entry.created_at)"></td>
                            <td class="px-5 py-3">
                                <code class="text-xs font-mono text-gray-800 dark:text-gray-200" x-text="entry.action"></code>
                            </td>
                            <td class="px-5 py-3 hidden md:table-cell text-xs text-gray-500">
                                <span x-text="entry.entity_type || '—'"></span>
                                <span x-show="entry.entity_id" class="text-gray-400"> #<span x-text="entry.entity_id"></span></span>
                            </td>
                            <td class="px-5 py-3 hidden lg:table-cell text-xs text-gray-500" x-text="entry.actor_name || entry.actor_username || 'System'"></td>
                            <td class="px-5 py-3 hidden xl:table-cell text-xs text-gray-400 font-mono" x-text="entry.actor_ip || '—'"></td>
                        </tr>
                    </template>
                    <tr x-show="!loading && entries.length === 0">
                        <td colspan="5" class="px-5 py-8 text-center text-sm text-gray-400">No audit entries found.</td>
                    </tr>
                </tbody>
            </table>

            <div class="flex items-center justify-between px-5 py-3 border-t border-gray-100 dark:border-gray-800"
                 x-show="total > limit">
                <button @click="prevPage()" :disabled="offset === 0"
                        class="text-sm text-gray-500 disabled:opacity-40">← Previous</button>
                <span class="text-xs text-gray-400">
                    <span x-text="offset + 1"></span>–<span x-text="Math.min(offset + limit, total)"></span>
                    of <span x-text="total"></span>
                </span>
                <button @click="nextPage()" :disabled="offset + limit >= total"
                        class="text-sm text-gray-500 disabled:opacity-40">Next →</button>
            </div>
        </div>
    </div>
</div>

<script>
function auditPage() {
    return {
        entries: [], total: 0, loading: true,
        search: '', actionFilter: '',
        limit: 50, offset: 0,

        async init()     { await this.loadEntries(); },
        async prevPage() { this.offset = Math.max(0, this.offset - this.limit); await this.loadEntries(); },
        async nextPage() { this.offset += this.limit; await this.loadEntries(); },

        formatTime(ts) {
            if (!ts) return '—';
            return new Date(ts.replace(' ', 'T')).toLocaleString();
        },

        async loadEntries() {
            this.loading = true;
            const params = new URLSearchParams({ limit: this.limit, offset: this.offset });
            if (this.search) params.set('search', this.search);
            if (this.actionFilter) params.set('action', this.actionFilter);
            const res = await api.get('/audit?' + params);
            if (res.ok) {
                this.entries = res.data.data.entries;
                this.total   = res.data.data.total;
            } else {
                toast(res.data.error || 'Failed to load audit log', 'error');
            }
            this.loading = false;
        }
    };
}
</script>
