<?php
$pageTitle    = 'Dashboard';
$pageSubtitle = 'System overview';
?>

<div x-data="dashboard()" x-init="init()" class="space-y-8">

    <!-- Stats row -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <template x-for="s in statCards" :key="s.label">
            <a :href="s.link"
               :data-tip="s.tip"
               data-tip-pos="bottom"
               class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800
                      p-5 hover:border-red-300 dark:hover:border-red-800 transition-colors group">
                <div class="flex items-start justify-between">
                    <div>
                        <div class="text-2xl font-bold text-gray-900 dark:text-white" x-text="s.value ?? '—'"></div>
                        <div class="text-sm text-gray-500 dark:text-gray-400 mt-0.5" x-text="s.label"></div>
                    </div>
                    <div class="w-9 h-9 rounded-xl bg-red-50 dark:bg-red-950/40 flex items-center justify-center
                                group-hover:bg-red-100 dark:group-hover:bg-red-900/40 transition-colors">
                        <svg class="w-4 h-4 text-red-600 dark:text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="s.icon"/>
                        </svg>
                    </div>
                </div>
            </a>
        </template>
    </div>

    <!-- Quick actions -->
    <div>
        <h2 class="text-sm font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-3">
            <?= tip_label('Quick Actions', 'Common admin tasks — hover each button for details') ?>
        </h2>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
            <?php
            $actions = [
                ['href' => '/admin/orgs/new',     'label' => 'New Organization', 'tip' => 'Create a top-level org and root node'],
                ['href' => '/admin/users/new',    'label' => 'Add User',         'tip' => 'Manually create a user account'],
                ['href' => '/admin/users/import', 'label' => 'Import Users',     'tip' => 'Bulk CSV import with org and role mapping'],
                ['href' => '/admin/groups/new',   'label' => 'New Group',        'tip' => 'Create a static group for targeting'],
                ['href' => '/admin/alerts/new',   'label' => 'Send Alert',       'tip' => 'Compose and dispatch a live alert'],
                ['href' => '/admin/test-send',    'label' => 'Test Send',        'tip' => 'Preview targeting before sending'],
                ['href' => '/admin/tokens/new',   'label' => 'Create API Token', 'tip' => 'Token for external systems to POST alerts'],
            ];
            foreach ($actions as $a): ?>
            <a href="<?= $a['href'] ?>"
               <?= tip_attr($a['tip'], 'bottom') ?>
               class="flex items-center gap-2 px-4 py-3 rounded-xl border border-gray-200 dark:border-gray-800
                      bg-white dark:bg-gray-900 text-sm font-medium text-gray-700 dark:text-gray-300
                      hover:border-red-300 dark:hover:border-red-800 hover:text-red-600 dark:hover:text-red-400
                      transition-colors">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                </svg>
                <?= htmlspecialchars($a['label']) ?>
            </a>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- System status -->
    <div>
        <h2 class="text-sm font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-3">
            <?= tip_label('System Status', 'Deep health check of database, Redis, and schema version') ?>
        </h2>
        <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 divide-y divide-gray-100 dark:divide-gray-800">
            <template x-for="check in checks" :key="check.name">
                <div class="flex items-center justify-between px-5 py-3" :data-tip="check.tip" data-tip-pos="left">
                    <span class="text-sm text-gray-700 dark:text-gray-300" x-text="check.name"></span>
                    <div class="flex items-center gap-2">
                        <span class="text-xs text-gray-400" x-text="check.detail"></span>
                        <span class="w-2 h-2 rounded-full"
                              :class="check.ok ? 'bg-green-500' : 'bg-red-500'"></span>
                    </div>
                </div>
            </template>
            <div x-show="checks.length === 0" class="px-5 py-4 text-sm text-gray-400">Loading…</div>
        </div>
    </div>

</div>

<script>
function dashboard() {
    return {
        statCards: [
            { label: 'Organizations', value: null, icon: 'M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-2 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4', link: '/admin/orgs', tip: 'Active organizations in your scope' },
            { label: 'Users',         value: null, icon: 'M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z', link: '/admin/users', tip: 'Active user accounts' },
            { label: 'Alerts Sent',   value: null, icon: 'M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9', link: '/admin/alerts/history', tip: 'Alerts that have been sent or are sending' },
            { label: 'API Tokens',    value: null, icon: 'M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z', link: '/admin/tokens', tip: 'Active system tokens for automated senders' },
        ],
        checks: [],
        async init() {
            let pendingJobs = 0;
            const statsRes = await api.get('/dashboard/stats');
            if (statsRes.ok) {
                const d = statsRes.data.data;
                this.statCards[0].value = d.orgs ?? 0;
                this.statCards[1].value = d.users ?? 0;
                this.statCards[2].value = d.alerts_sent ?? 0;
                this.statCards[3].value = d.tokens ?? 0;
                pendingJobs = d.pending_jobs ?? 0;
            }

            const res = await api.get('/health/deep');
            if (res.ok) {
                const c = res.data.checks;
                this.checks = [
                    { name: 'Database', ok: c.database?.status === 'ok', detail: c.database?.version ?? '', tip: 'MySQL connectivity and version' },
                    { name: 'Redis',    ok: c.redis?.status === 'ok',    detail: c.redis?.status ?? '',    tip: 'Redis for rate limiting and future pub/sub' },
                    { name: 'Schema',   ok: c.schema?.status === 'ok',   detail: 'v' + (c.schema?.version ?? '?'), tip: 'Applied DB migration version' },
                    { name: 'Dispatch queue', ok: pendingJobs === 0, detail: pendingJobs + ' pending', tip: 'Alerts still sending or jobs waiting in dispatch queue' },
                ];
            }
        }
    };
}
</script>
