<?php
// web/templates/pages/dashboard.php
$pageTitle    = 'Dashboard';
$pageSubtitle = 'System overview';
$token        = $_SESSION['access_token'];
$apiBase      = Env::get('APP_URL');

// Fetch stats via internal API calls
function api_get(string $path, string $token, string $apiBase): ?array {
    $ctx = stream_context_create(['http' => [
        'method'  => 'GET',
        'header'  => "Authorization: Bearer {$token}\r\nContent-Type: application/json",
        'timeout' => 5,
        'ignore_errors' => true,
    ]]);
    $raw = @file_get_contents("{$apiBase}/api/v1{$path}", false, $ctx);
    return $raw ? json_decode($raw, true) : null;
}

$orgs  = api_get('/orgs?limit=200', $token, $apiBase);
$users = api_get('/users?limit=1', $token, $apiBase);

$orgCount  = $orgs['data']['total']  ?? 0;
$userCount = $users['data']['total'] ?? 0;
?>

<div x-data="dashboard()" x-init="init()">

    <!-- Stats row -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
        <?php
        $stats = [
            ['label' => 'Organizations', 'value' => $orgCount, 'icon' => 'M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-2 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4', 'link' => '/admin/orgs'],
            ['label' => 'Users',         'value' => $userCount, 'icon' => 'M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z', 'link' => '/admin/users'],
            ['label' => 'Alerts Sent',  'value' => '—',  'icon' => 'M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9', 'link' => '/admin/alerts/history'],
            ['label' => 'API Tokens',   'value' => '—',  'icon' => 'M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z', 'link' => '/admin/tokens'],
        ];
        foreach ($stats as $s): ?>
        <a href="<?= $s['link'] ?>"
           class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800
                  p-5 hover:border-red-300 dark:hover:border-red-800 transition-colors group">
            <div class="flex items-start justify-between">
                <div>
                    <div class="text-2xl font-bold text-gray-900 dark:text-white"><?= $s['value'] ?></div>
                    <div class="text-sm text-gray-500 dark:text-gray-400 mt-0.5"><?= $s['label'] ?></div>
                </div>
                <div class="w-9 h-9 rounded-xl bg-red-50 dark:bg-red-950/40 flex items-center justify-center
                            group-hover:bg-red-100 dark:group-hover:bg-red-900/40 transition-colors">
                    <svg class="w-4 h-4 text-red-600 dark:text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="<?= $s['icon'] ?>"/>
                    </svg>
                </div>
            </div>
        </a>
        <?php endforeach; ?>
    </div>

    <!-- Quick actions -->
    <div class="mb-8">
        <h2 class="text-sm font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-3">Quick Actions</h2>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
            <?php
            $actions = [
                ['href' => '/admin/orgs/new',    'label' => 'New Organization', 'icon' => 'M12 4v16m8-8H4'],
                ['href' => '/admin/users/new',   'label' => 'Add User',         'icon' => 'M12 4v16m8-8H4'],
                ['href' => '/admin/users/import','label' => 'Import Users',     'icon' => 'M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12'],
                ['href' => '/admin/groups/new',  'label' => 'New Group',        'icon' => 'M12 4v16m8-8H4'],
                ['href' => '/admin/tokens/new',  'label' => 'Create API Token', 'icon' => 'M12 4v16m8-8H4'],
            ];
            foreach ($actions as $a): ?>
            <a href="<?= $a['href'] ?>"
               class="flex items-center gap-2 px-4 py-3 rounded-xl border border-gray-200 dark:border-gray-800
                      bg-white dark:bg-gray-900 text-sm font-medium text-gray-700 dark:text-gray-300
                      hover:border-red-300 dark:hover:border-red-800 hover:text-red-600 dark:hover:text-red-400
                      transition-colors">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="<?= $a['icon'] ?>"/>
                </svg>
                <?= htmlspecialchars($a['label']) ?>
            </a>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- System status -->
    <div>
        <h2 class="text-sm font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-3">System Status</h2>
        <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 divide-y divide-gray-100 dark:divide-gray-800">
            <template x-for="check in checks" :key="check.name">
                <div class="flex items-center justify-between px-5 py-3">
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
        checks: [],
        async init() {
            const res = await api.get('/health/deep');
            if (res.ok) {
                const c = res.data.checks;
                this.checks = [
                    { name: 'Database',      ok: c.database?.status === 'ok', detail: c.database?.version ?? '' },
                    { name: 'Redis',         ok: c.redis?.status    === 'ok', detail: c.redis?.status ?? '' },
                    { name: 'Schema',        ok: c.schema?.status   === 'ok', detail: 'v' + (c.schema?.version ?? '?') },
                ];
            }
        }
    };
}
</script>
