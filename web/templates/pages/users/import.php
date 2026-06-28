<?php
use NexAlert\Config\Env;

$pageTitle    = 'Import Users';
$pageSubtitle = 'Bulk import staff from a CSV file';

$token   = $_SESSION['access_token'];
$apiBase = Env::get('APP_URL');

$ctx = stream_context_create(['http' => [
    'method' => 'GET',
    'header' => "Authorization: Bearer {$token}\r\nContent-Type: application/json",
    'timeout' => 5,
    'ignore_errors' => true,
]]);
$raw    = @file_get_contents("{$apiBase}/api/v1/orgs?limit=200", false, $ctx);
$orgsRes = $raw ? json_decode($raw, true) : null;
$orgs    = $orgsRes['data']['orgs'] ?? [];

$results = $_SESSION['import_results'] ?? null;
unset($_SESSION['import_results']);
?>

<div class="max-w-2xl">

    <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 overflow-hidden mb-6">
        <div class="px-6 py-4 border-b border-gray-100 dark:border-gray-800">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Upload CSV</h2>
        </div>

        <form method="POST" action="/admin/users/import" enctype="multipart/form-data" class="p-6 space-y-5">
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Organization <span class="text-red-500">*</span></label>
                <select name="org_id" required
                        class="w-full px-3 py-2 text-sm rounded-xl border border-gray-300 dark:border-gray-700
                               bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-red-500">
                    <?php foreach ($orgs as $org): ?>
                    <option value="<?= (int) $org['id'] ?>"><?= htmlspecialchars($org['display_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">CSV File <span class="text-red-500">*</span></label>
                <input type="file" name="csv" accept=".csv,text/csv" required
                       class="w-full text-sm text-gray-600 dark:text-gray-400
                              file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0
                              file:text-sm file:font-semibold file:bg-red-50 file:text-red-700
                              dark:file:bg-red-950/40 dark:file:text-red-400 hover:file:bg-red-100">
            </div>

            <div class="rounded-xl bg-gray-50 dark:bg-gray-800/50 border border-gray-200 dark:border-gray-700 p-4">
                <p class="text-xs font-semibold text-gray-700 dark:text-gray-300 mb-2">Required columns</p>
                <code class="text-xs text-gray-500 dark:text-gray-400 font-mono">username, first_name, last_name, email</code>
                <p class="text-xs font-semibold text-gray-700 dark:text-gray-300 mt-3 mb-2">Optional columns</p>
                <code class="text-xs text-gray-500 dark:text-gray-400 font-mono">phone, org_node_slug, position_title</code>
            </div>

            <div class="flex items-center gap-3">
                <button type="submit"
                        class="px-5 py-2 bg-red-600 hover:bg-red-700 text-white text-sm font-semibold rounded-xl transition-colors">
                    Import Users
                </button>
                <a href="/admin/users" class="text-sm text-gray-500 hover:text-gray-700 dark:hover:text-gray-300">Cancel</a>
            </div>
        </form>
    </div>

    <?php if ($results): ?>
    <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-100 dark:border-gray-800">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Import Results</h2>
        </div>
        <div class="p-6">
            <div class="grid grid-cols-3 gap-4 mb-6">
                <div class="text-center p-4 rounded-xl bg-green-50 dark:bg-green-950/30 border border-green-200 dark:border-green-900">
                    <div class="text-2xl font-bold text-green-700 dark:text-green-400"><?= (int) ($results['created'] ?? 0) ?></div>
                    <div class="text-xs text-green-600 dark:text-green-500 mt-1">Created</div>
                </div>
                <div class="text-center p-4 rounded-xl bg-yellow-50 dark:bg-yellow-950/30 border border-yellow-200 dark:border-yellow-900">
                    <div class="text-2xl font-bold text-yellow-700 dark:text-yellow-400"><?= (int) ($results['skipped'] ?? 0) ?></div>
                    <div class="text-xs text-yellow-600 dark:text-yellow-500 mt-1">Skipped</div>
                </div>
                <div class="text-center p-4 rounded-xl bg-red-50 dark:bg-red-950/30 border border-red-200 dark:border-red-900">
                    <div class="text-2xl font-bold text-red-700 dark:text-red-400"><?= count($results['errors'] ?? []) ?></div>
                    <div class="text-xs text-red-600 dark:text-red-500 mt-1">Errors</div>
                </div>
            </div>

            <?php if (!empty($results['errors'])): ?>
            <div class="max-h-64 overflow-y-auto rounded-xl border border-gray-200 dark:border-gray-700">
                <ul class="divide-y divide-gray-100 dark:divide-gray-800 text-xs font-mono">
                    <?php foreach ($results['errors'] as $err): ?>
                    <li class="px-4 py-2 text-red-600 dark:text-red-400"><?= htmlspecialchars($err) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>
