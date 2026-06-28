<?php
$pageTitle    = 'Alert History';
$pageSubtitle = 'Sent alerts and delivery status';

$headerActions = '
<a href="/admin/alerts/new"
   class="flex items-center gap-2 px-4 py-2 bg-red-600 hover:bg-red-700 text-white text-sm font-semibold rounded-xl transition-colors">
    Send Alert
</a>';
?>

<div x-data="alertHistoryPage()" x-init="init()">
    <div class="flex flex-wrap items-center gap-3 mb-4">
        <input type="search" placeholder="Search alerts…" x-model.debounce.300ms="search" @input="load()"
               class="px-3 py-2 text-sm rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 w-64">
        <select x-model="filterSeverity" @change="load()"
                class="px-3 py-2 text-sm rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800">
            <option value="all">All severities</option>
            <option value="test">Test</option>
            <option value="info">Info</option>
            <option value="notice">Notice</option>
            <option value="warning">Warning</option>
            <option value="critical">Critical</option>
            <option value="evacuation">Evacuation</option>
        </select>
        <select x-model="filterStatus" @change="load()"
                class="px-3 py-2 text-sm rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800">
            <option value="all">All statuses</option>
            <option value="sending">Sending</option>
            <option value="sent">Sent</option>
            <option value="cancelled">Cancelled</option>
        </select>
    </div>

    <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 overflow-hidden">
        <div x-show="loading" class="p-8 text-center text-gray-400 text-sm">Loading…</div>
        <table x-show="!loading" class="w-full text-sm">
            <thead class="bg-gray-50 dark:bg-gray-800/50">
                <tr>
                    <th class="text-left px-5 py-3 text-xs font-semibold text-gray-500 uppercase">Alert</th>
                    <th class="text-left px-5 py-3 text-xs font-semibold text-gray-500 uppercase hidden md:table-cell">Severity</th>
                    <th class="text-center px-5 py-3 text-xs font-semibold text-gray-500 uppercase">Recipients</th>
                    <th class="text-center px-5 py-3 text-xs font-semibold text-gray-500 uppercase hidden lg:table-cell">Sent</th>
                    <th class="text-center px-5 py-3 text-xs font-semibold text-gray-500 uppercase">Status</th>
                    <th class="px-5 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                <template x-for="a in alerts" :key="a.id">
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/40">
                        <td class="px-5 py-3">
                            <div class="font-medium text-gray-900 dark:text-white" x-text="a.subject"></div>
                            <div class="text-xs text-gray-400 mt-0.5" x-text="a.created_at"></div>
                        </td>
                        <td class="px-5 py-3 hidden md:table-cell">
                            <span class="text-xs px-2 py-0.5 rounded-full font-medium"
                                  :class="severityClass(a.severity)" x-text="a.severity"></span>
                        </td>
                        <td class="px-5 py-3 text-center text-gray-500" x-text="a.recipient_count"></td>
                        <td class="px-5 py-3 text-center text-gray-500 hidden lg:table-cell" x-text="a.sent_count ?? '—'"></td>
                        <td class="px-5 py-3 text-center">
                            <span class="text-xs capitalize" x-text="a.status"></span>
                        </td>
                        <td class="px-5 py-3 text-right">
                            <button @click="viewDetail(a)" class="text-xs text-gray-400 hover:text-red-600">Details</button>
                        </td>
                    </tr>
                </template>
                <tr x-show="!loading && alerts.length === 0">
                    <td colspan="6" class="px-5 py-8 text-center text-gray-400">No alerts yet.</td>
                </tr>
            </tbody>
        </table>
    </div>

    <div x-show="detail" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50"
         @keydown.escape.window="detail = null">
        <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-700 shadow-xl max-w-2xl w-full max-h-[85vh] overflow-y-auto p-6">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-1" x-text="detail?.subject"></h3>
            <p class="text-xs text-gray-400 mb-4" x-text="detail?.severity + ' · ' + detail?.alert_type + ' · ' + detail?.status"></p>
            <p class="text-sm text-gray-700 dark:text-gray-300 whitespace-pre-wrap mb-4" x-text="detail?.body"></p>
            <div class="text-xs text-gray-500 mb-4">
                <span x-text="'Recipients: ' + (detail?.recipient_count ?? '—')"></span>
                ·
                <span x-text="'Queued: ' + (detail?.delivery_stats?.queued ?? 0)"></span>
                ·
                <span x-text="'Sent: ' + (detail?.delivery_stats?.sent ?? 0)"></span>
                ·
                <span x-text="'Skipped: ' + (detail?.delivery_stats?.skipped ?? 0)"></span>
                ·
                <span x-text="'Failed: ' + (detail?.delivery_stats?.failed ?? 0)"></span>
            </div>
            <button @click="detail = null" class="text-sm text-gray-500 hover:text-gray-700">Close</button>
        </div>
    </div>
</div>

<script>
function alertHistoryPage() {
    return {
        alerts: [], loading: true, search: '', filterSeverity: 'all', filterStatus: 'all', detail: null,
        async init() { await this.load(); },
        severityClass(s) {
            const m = { test: 'bg-gray-100 text-gray-600', info: 'bg-blue-100 text-blue-700',
                warning: 'bg-yellow-100 text-yellow-700', critical: 'bg-orange-100 text-orange-700',
                evacuation: 'bg-red-100 text-red-700', notice: 'bg-indigo-100 text-indigo-700' };
            return m[s] || 'bg-gray-100 text-gray-600';
        },
        async load() {
            this.loading = true;
            const p = new URLSearchParams({ limit: 50 });
            if (this.search) p.set('search', this.search);
            if (this.filterSeverity !== 'all') p.set('severity', this.filterSeverity);
            if (this.filterStatus !== 'all') p.set('status', this.filterStatus);
            const res = await api.get('/alerts?' + p);
            if (res.ok) this.alerts = res.data.data.alerts;
            else toast(res.data?.error || 'Failed to load', 'error');
            this.loading = false;
        },
        async viewDetail(a) {
            const res = await api.get('/alerts/' + a.id);
            if (res.ok) this.detail = res.data.data;
            else toast(res.data?.error || 'Failed', 'error');
        }
    };
}
</script>
