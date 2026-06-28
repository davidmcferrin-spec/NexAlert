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
               <?= tip_attr('Search alert subject and body text', 'bottom') ?>
               class="px-3 py-2 text-sm rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 w-64">
        <select x-model="filterSeverity" @change="load()"
                <?= tip_attr('Filter by alert severity level', 'bottom') ?>
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
                <?= tip_attr('Sending = dispatch in progress. Sent = all deliveries attempted.', 'bottom') ?>
                class="px-3 py-2 text-sm rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800">
            <option value="all">All statuses</option>
            <option value="scheduled">Scheduled</option>
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
                    <th class="px-5 py-3 text-right text-xs font-semibold text-gray-500 uppercase">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                <template x-for="a in alerts" :key="a.id">
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/40">
                        <td class="px-5 py-3">
                            <div class="font-medium text-gray-900 dark:text-white" x-text="a.subject"></div>
                            <div class="text-xs text-gray-400 mt-0.5">
                                <span x-text="a.created_at"></span>
                                <span x-show="a.status === 'scheduled' && a.send_at" class="text-indigo-500">
                                    · sends <span x-text="formatDate(a.send_at)"></span>
                                </span>
                            </div>
                        </td>
                        <td class="px-5 py-3 hidden md:table-cell">
                            <span class="text-xs px-2 py-0.5 rounded-full font-medium"
                                  :class="severityClass(a.severity)" x-text="a.severity"></span>
                        </td>
                        <td class="px-5 py-3 text-center text-gray-500" x-text="a.recipient_count"></td>
                        <td class="px-5 py-3 text-center text-gray-500 hidden lg:table-cell">
                            <span x-text="a.sent_count ?? '—'"></span>
                            <span x-show="a.failed_count > 0" class="text-red-500 text-xs" x-text="' (' + a.failed_count + ' failed)'"></span>
                        </td>
                        <td class="px-5 py-3 text-center">
                            <span class="text-xs capitalize px-2 py-0.5 rounded-full"
                                  :class="statusClass(a.status)" x-text="a.status"></span>
                        </td>
                        <td class="px-5 py-3 text-right whitespace-nowrap">
                            <button @click="viewDetail(a)" class="text-xs text-gray-400 hover:text-red-600 mr-2">Details</button>
                            <button x-show="canCancel(a)" @click="cancelAlert(a)" :disabled="actionId === a.id"
                                    class="text-xs text-amber-600 hover:text-amber-700 mr-2 disabled:opacity-50">Cancel</button>
                            <button x-show="canRetry(a)" @click="retryAlert(a)" :disabled="actionId === a.id"
                                    class="text-xs text-blue-600 hover:text-blue-700 mr-2 disabled:opacity-50">Retry</button>
                            <button x-show="canDelete(a)" @click="deleteAlert(a)" :disabled="actionId === a.id"
                                    class="text-xs text-red-500 hover:text-red-700 disabled:opacity-50">Delete</button>
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
        <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-700 shadow-xl max-w-5xl w-full max-h-[90vh] overflow-hidden flex flex-col">
            <div class="px-6 py-4 border-b border-gray-100 dark:border-gray-800 flex items-start justify-between gap-4">
                <div class="min-w-0">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white truncate" x-text="detail?.subject"></h3>
                    <p class="text-xs text-gray-400 mt-1">
                        <span x-text="detail?.severity"></span> ·
                        <span x-text="detail?.alert_type"></span> ·
                        <span class="capitalize" x-text="detail?.status"></span>
                        <span x-show="detail?.send_at && detail?.status === 'scheduled'" class="text-amber-600 dark:text-amber-400">
                            · sends <span x-text="formatDate(detail?.send_at)"></span>
                        </span>
                        <span x-show="detail?.sent_at"> · sent <span x-text="formatDate(detail?.sent_at)"></span></span>
                    </p>
                </div>
                <button @click="detail = null" class="text-gray-400 hover:text-gray-600 text-xl leading-none">&times;</button>
            </div>

            <div class="flex-1 overflow-y-auto px-6 py-4 space-y-4">
                <p class="text-sm text-gray-700 dark:text-gray-300 whitespace-pre-wrap" x-text="detail?.body"></p>

                <div class="flex flex-wrap gap-2 text-xs text-gray-500">
                    <span class="px-2 py-1 rounded-lg bg-gray-100 dark:bg-gray-800">
                        Recipients: <strong x-text="detail?.recipient_count ?? detail?.deliveries?.length ?? '—'"></strong>
                    </span>
                    <span class="px-2 py-1 rounded-lg bg-gray-100 dark:bg-gray-800">
                        Queued: <strong x-text="detail?.delivery_stats?.queued ?? 0"></strong>
                    </span>
                    <span class="px-2 py-1 rounded-lg bg-gray-100 dark:bg-gray-800">
                        Sent: <strong x-text="detail?.delivery_stats?.sent ?? 0"></strong>
                    </span>
                    <span class="px-2 py-1 rounded-lg bg-gray-100 dark:bg-gray-800">
                        Skipped: <strong x-text="detail?.delivery_stats?.skipped ?? 0"></strong>
                    </span>
                    <span class="px-2 py-1 rounded-lg bg-gray-100 dark:bg-gray-800">
                        Failed: <strong x-text="detail?.delivery_stats?.failed ?? 0"></strong>
                    </span>
                    <span x-show="detail?.acks?.length" class="px-2 py-1 rounded-lg bg-green-50 dark:bg-green-950 text-green-700 dark:text-green-300">
                        Acked: <strong x-text="detail?.acks?.length ?? 0"></strong>
                    </span>
                    <span x-show="detail?.escalated_at" class="px-2 py-1 rounded-lg bg-red-50 dark:bg-red-950 text-red-700">
                        Escalated <span x-text="formatDate(detail?.escalated_at)"></span>
                    </span>
                </div>

                <div class="flex flex-wrap gap-2">
                    <button x-show="canCancel(detail)" @click="cancelAlert(detail, true)"
                            class="px-3 py-1.5 text-xs font-semibold bg-amber-100 text-amber-800 rounded-lg">Cancel</button>
                    <button x-show="canRetry(detail)" @click="retryAlert(detail, true)"
                            class="px-3 py-1.5 text-xs font-semibold bg-blue-100 text-blue-800 rounded-lg">Retry delivery</button>
                    <button x-show="canDelete(detail)" @click="deleteAlert(detail, true)"
                            class="px-3 py-1.5 text-xs font-semibold bg-red-100 text-red-800 rounded-lg">Delete</button>
                </div>

                <!-- Delivery drill-down -->
                <div x-show="detail?.deliveries?.length">
                    <div class="flex flex-wrap items-center gap-2 mb-2">
                        <h4 class="text-sm font-semibold text-gray-900 dark:text-white">Deliveries</h4>
                        <select x-model="deliveryFilter" class="text-xs px-2 py-1 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800">
                            <option value="all">All statuses</option>
                            <option value="queued">Queued</option>
                            <option value="sent">Sent</option>
                            <option value="delivered">Delivered</option>
                            <option value="failed">Failed</option>
                            <option value="skipped">Skipped</option>
                        </select>
                        <input type="search" x-model="deliverySearch" placeholder="Filter recipients…"
                               class="text-xs px-2 py-1 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 ml-auto w-40">
                    </div>
                    <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-800">
                        <table class="w-full text-xs">
                            <thead class="bg-gray-50 dark:bg-gray-800/50">
                                <tr>
                                    <th class="text-left px-3 py-2 font-semibold text-gray-500">Recipient</th>
                                    <th class="text-left px-3 py-2 font-semibold text-gray-500">Channel</th>
                                    <th class="text-left px-3 py-2 font-semibold text-gray-500">Contact</th>
                                    <th class="text-left px-3 py-2 font-semibold text-gray-500">Status</th>
                                    <th class="text-left px-3 py-2 font-semibold text-gray-500 hidden md:table-cell">Sent</th>
                                    <th class="text-left px-3 py-2 font-semibold text-gray-500">Ack</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                <template x-for="d in filteredDeliveries()" :key="d.id">
                                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/40">
                                        <td class="px-3 py-2">
                                            <div class="font-medium text-gray-900 dark:text-white" x-text="d.user_name || d.username"></div>
                                            <div class="text-gray-400" x-text="d.username"></div>
                                        </td>
                                        <td class="px-3 py-2 uppercase text-gray-600" x-text="d.channel"></td>
                                        <td class="px-3 py-2 font-mono text-gray-500" x-text="maskContact(d.contact_value)"></td>
                                        <td class="px-3 py-2">
                                            <span class="px-1.5 py-0.5 rounded capitalize font-medium"
                                                  :class="deliveryStatusClass(d.status)" x-text="d.status"></span>
                                            <div x-show="d.skip_reason" class="text-gray-400 mt-0.5" x-text="d.skip_reason"></div>
                                        </td>
                                        <td class="px-3 py-2 text-gray-500 hidden md:table-cell whitespace-nowrap" x-text="d.sent_at ? formatDate(d.sent_at) : '—'"></td>
                                        <td class="px-3 py-2">
                                            <span x-show="isAcked(d.user_id)" class="text-green-600 font-medium">✓</span>
                                            <span x-show="!isAcked(d.user_id) && detail?.alert_type === 'ack_required'" class="text-gray-300">—</span>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div x-show="detail?.acks?.length">
                    <h4 class="text-sm font-semibold text-gray-900 dark:text-white mb-2">Acknowledgements</h4>
                    <ul class="text-xs space-y-1 text-gray-600 dark:text-gray-400">
                        <template x-for="a in detail.acks" :key="a.user_id + '-' + a.ack_at">
                            <li>
                                <strong x-text="a.user_name"></strong>
                                via <span x-text="a.ack_channel"></span>
                                at <span x-text="formatDate(a.ack_at)"></span>
                                <span x-show="a.notes" x-text="' — ' + a.notes"></span>
                            </li>
                        </template>
                    </ul>
                </div>
            </div>

            <div class="px-6 py-3 border-t border-gray-100 dark:border-gray-800 text-right">
                <button @click="detail = null" class="text-sm text-gray-500 hover:text-gray-700">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
function alertHistoryPage() {
    return {
        alerts: [], loading: true, search: '', filterSeverity: 'all', filterStatus: 'all',
        detail: null, actionId: null, deliveryFilter: 'all', deliverySearch: '',
        ackUserIds: [],
        async init() { await this.load(); },
        severityClass(s) {
            const m = { test: 'bg-gray-100 text-gray-600', info: 'bg-blue-100 text-blue-700',
                warning: 'bg-yellow-100 text-yellow-700', critical: 'bg-orange-100 text-orange-700',
                evacuation: 'bg-red-100 text-red-700', notice: 'bg-indigo-100 text-indigo-700' };
            return m[s] || 'bg-gray-100 text-gray-600';
        },
        statusClass(s) {
            const m = { scheduled: 'bg-indigo-100 text-indigo-800', sending: 'bg-yellow-100 text-yellow-800', sent: 'bg-green-100 text-green-800',
                cancelled: 'bg-gray-100 text-gray-600', draft: 'bg-gray-100 text-gray-500' };
            return m[s] || 'bg-gray-100 text-gray-600';
        },
        deliveryStatusClass(s) {
            const m = { queued: 'bg-yellow-100 text-yellow-800', sent: 'bg-green-100 text-green-800',
                delivered: 'bg-green-100 text-green-800', failed: 'bg-red-100 text-red-800', skipped: 'bg-gray-100 text-gray-600' };
            return m[s] || 'bg-gray-100 text-gray-600';
        },
        formatDate(v) {
            if (!v) return '—';
            try { return new Date(v.replace(' ', 'T') + 'Z').toLocaleString(); } catch (e) { return v; }
        },
        maskContact(v) {
            if (!v) return '—';
            if (v.includes('@')) {
                const [u, d] = v.split('@');
                return (u.length > 2 ? u.slice(0, 2) + '…' : u) + '@' + d;
            }
            return v.length > 6 ? v.slice(0, -4).replace(/\d/g, '•') + v.slice(-4) : v;
        },
        isAcked(userId) {
            return this.ackUserIds.includes(Number(userId));
        },
        filteredDeliveries() {
            if (!this.detail?.deliveries) return [];
            let list = this.detail.deliveries;
            if (this.deliveryFilter !== 'all') {
                list = list.filter(d => d.status === this.deliveryFilter);
            }
            const q = this.deliverySearch.trim().toLowerCase();
            if (q) {
                list = list.filter(d =>
                    (d.user_name || '').toLowerCase().includes(q) ||
                    (d.username || '').toLowerCase().includes(q) ||
                    (d.contact_value || '').toLowerCase().includes(q)
                );
            }
            return list;
        },
        canCancel(a) {
            return a && ['draft', 'scheduled', 'sending'].includes(a.status);
        },
        canRetry(a) {
            if (!a) return false;
            if (a.status === 'cancelled') return true;
            if (['sending', 'sent'].includes(a.status)) {
                return (a.failed_count > 0) || (a.status === 'sending' && (a.sent_count || 0) < (a.recipient_count || 0));
            }
            return false;
        },
        canDelete(a) {
            return a && (a.severity === 'test' || true);
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
            this.deliveryFilter = 'all';
            this.deliverySearch = '';
            const res = await api.get('/alerts/' + a.id);
            if (res.ok) {
                const d = res.data.data;
                this.ackUserIds = (d.acks || []).map(x => Number(x.user_id));
                this.detail = { ...d, failed_count: a.failed_count, recipient_count: a.recipient_count ?? d.recipient_count };
            } else toast(res.data?.error || 'Failed', 'error');
        },
        async cancelAlert(a, fromModal = false) {
            if (!confirm('Cancel this alert? Pending deliveries will be stopped.')) return;
            this.actionId = a.id;
            const res = await api.post('/alerts/' + a.id + '/cancel', {});
            this.actionId = null;
            if (res.ok) {
                toast('Alert cancelled');
                if (fromModal) this.detail = null;
                await this.load();
            } else toast(res.data?.error || 'Cancel failed', 'error');
        },
        async retryAlert(a, fromModal = false) {
            if (!confirm('Re-queue failed or pending deliveries for this alert?')) return;
            this.actionId = a.id;
            const res = await api.post('/alerts/' + a.id + '/retry', {});
            this.actionId = null;
            if (res.ok) {
                toast('Alert re-queued');
                if (fromModal) this.detail = null;
                await this.load();
            } else toast(res.data?.error || 'Retry failed', 'error');
        },
        async deleteAlert(a, fromModal = false) {
            if (!confirm('Permanently delete this alert from history? This cannot be undone.')) return;
            this.actionId = a.id;
            const res = await api.delete('/alerts/' + a.id);
            this.actionId = null;
            if (res.ok) {
                toast('Alert deleted');
                if (fromModal) this.detail = null;
                await this.load();
            } else toast(res.data?.error || 'Delete failed', 'error');
        }
    };
}
</script>
