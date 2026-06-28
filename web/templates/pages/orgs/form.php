<?php
// web/templates/pages/orgs/form.php
$orgId     = (int) ($_GET['id'] ?? 0);
$isEdit    = $orgId > 0;
$pageTitle = $isEdit ? 'Edit Organization' : 'New Organization';
$token     = $_SESSION['access_token'];
$apiBase   = Env::get('APP_URL');
$org       = null;

if ($isEdit) {
    $ctx = stream_context_create(['http' => [
        'method' => 'GET',
        'header' => "Authorization: Bearer {$token}",
        'timeout' => 5,
        'ignore_errors' => true,
    ]]);
    $raw = @file_get_contents("{$apiBase}/api/v1/orgs/{$orgId}", false, $ctx);
    $res = $raw ? json_decode($raw, true) : null;
    if (!$res || !$res['success']) {
        flash('Organization not found.', 'error');
        header('Location: /admin/orgs');
        exit;
    }
    $org = $res['data'];
}
?>

<div class="max-w-2xl">
    <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-100 dark:border-gray-800">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white"><?= $pageTitle ?></h2>
        </div>

        <form method="POST" action="/admin/orgs/save" class="p-6 space-y-5"
              x-data="{ loading: false }" @submit="loading = true">
            <input type="hidden" name="id" value="<?= $orgId ?>">

            <div class="grid grid-cols-2 gap-5">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Name <span class="text-red-500">*</span></label>
                    <input type="text" name="name" required
                           value="<?= htmlspecialchars($org['name'] ?? '') ?>"
                           placeholder="Nexstar Media Group"
                           class="w-full px-3 py-2 text-sm rounded-xl border border-gray-300 dark:border-gray-700
                                  bg-white dark:bg-gray-800 text-gray-900 dark:text-white
                                  focus:outline-none focus:ring-2 focus:ring-red-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Slug <span class="text-red-500">*</span></label>
                    <input type="text" name="slug" required
                           value="<?= htmlspecialchars($org['slug'] ?? '') ?>"
                           placeholder="nexstar"
                           <?= $isEdit ? 'readonly class="opacity-60"' : '' ?>
                           class="w-full px-3 py-2 text-sm rounded-xl border border-gray-300 dark:border-gray-700
                                  bg-white dark:bg-gray-800 text-gray-900 dark:text-white font-mono
                                  focus:outline-none focus:ring-2 focus:ring-red-500">
                    <?php if (!$isEdit): ?>
                    <p class="text-xs text-gray-400 mt-1">Lowercase letters, numbers, hyphens only. Cannot be changed.</p>
                    <?php endif; ?>
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Display Name <span class="text-red-500">*</span></label>
                <input type="text" name="display_name" required
                       value="<?= htmlspecialchars($org['display_name'] ?? '') ?>"
                       placeholder="Nexstar Media Group"
                       class="w-full px-3 py-2 text-sm rounded-xl border border-gray-300 dark:border-gray-700
                              bg-white dark:bg-gray-800 text-gray-900 dark:text-white
                              focus:outline-none focus:ring-2 focus:ring-red-500">
            </div>

            <div class="grid grid-cols-2 gap-5">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Brand Color</label>
                    <div class="flex gap-2 items-center">
                        <input type="color" name="primary_color_picker"
                               value="<?= htmlspecialchars($org['primary_color'] ?? '#e51c1c') ?>"
                               oninput="document.getElementById('color_hex').value = this.value"
                               class="h-9 w-12 rounded-lg border border-gray-300 dark:border-gray-700 cursor-pointer p-0.5">
                        <input type="text" name="primary_color" id="color_hex"
                               value="<?= htmlspecialchars($org['primary_color'] ?? '') ?>"
                               placeholder="#e51c1c" pattern="^#[0-9a-fA-F]{6}$"
                               class="flex-1 px-3 py-2 text-sm rounded-xl border border-gray-300 dark:border-gray-700
                                      bg-white dark:bg-gray-800 text-gray-900 dark:text-white font-mono
                                      focus:outline-none focus:ring-2 focus:ring-red-500">
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Logo URL</label>
                    <input type="url" name="logo_url"
                           value="<?= htmlspecialchars($org['logo_url'] ?? '') ?>"
                           placeholder="https://…"
                           class="w-full px-3 py-2 text-sm rounded-xl border border-gray-300 dark:border-gray-700
                                  bg-white dark:bg-gray-800 text-gray-900 dark:text-white
                                  focus:outline-none focus:ring-2 focus:ring-red-500">
                </div>
            </div>

            <div class="flex items-center justify-between pt-2">
                <a href="/admin/orgs" class="text-sm text-gray-500 hover:text-gray-700 dark:hover:text-gray-300 transition-colors">
                    ← Cancel
                </a>
                <button type="submit" :disabled="loading"
                        class="flex items-center gap-2 px-5 py-2 bg-red-600 hover:bg-red-700
                               disabled:opacity-60 text-white text-sm font-semibold rounded-xl transition-colors">
                    <svg x-show="loading" class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                    </svg>
                    <?= $isEdit ? 'Save Changes' : 'Create Organization' ?>
                </button>
            </div>
        </form>
    </div>
</div>
