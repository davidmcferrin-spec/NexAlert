<?php

$tokenId   = (int) ($_GET['id'] ?? 0);
$isEdit    = $tokenId > 0;
$pageTitle = $isEdit ? 'Edit API Token' : 'New API Token';
$session   = $_SESSION['access_token'];
$apiBase   = web_api_base();

function token_api(string $method, string $url, string $tok, ?array $body = null): ?array {
    $payload = $body ? json_encode($body) : null;
    $headers = "Authorization: Bearer {$tok}\r\nContent-Type: application/json";
    if ($payload !== null) {
        $headers .= "\r\nContent-Length: " . strlen($payload);
    }
    $ctx = stream_context_create(['http' => [
        'method' => $method, 'header' => $headers, 'content' => $payload,
        'timeout' => 10, 'ignore_errors' => true,
    ]]);
    $raw = @file_get_contents($url, false, $ctx);
    return $raw ? json_decode($raw, true) : null;
}

$orgsRes = token_api('GET', "{$apiBase}/api/v1/orgs?limit=200", $session);
$orgs    = $orgsRes['data']['orgs'] ?? [];
$item    = null;
$bearerToken = null;

if ($isEdit) {
    $res = token_api('GET', "{$apiBase}/api/v1/tokens/{$tokenId}", $session);
    if (!$res || !$res['success']) {
        flash('Token not found.', 'error');
        header('Location: /admin/tokens');
        exit;
    }
    $item = $res['data'];

    $pending = $_SESSION['token_bearer_display'] ?? null;
    if (is_array($pending) && (int) ($pending['token_id'] ?? 0) === $tokenId && !empty($pending['raw'])) {
        $bearerToken = (string) $pending['raw'];
    }
    unset($_SESSION['token_bearer_display']);
}

$severities  = ['test', 'info', 'notice', 'warning', 'critical', 'evacuation'];
$alertTypes  = ['simple', 'ack_required', 'poll', 'chat', 'group_chat'];
$selectedSev = $isEdit ? explode(',', $item['allowed_severity'] ?? '') : ['test', 'info', 'notice', 'warning'];
$selectedTyp = $isEdit ? explode(',', $item['allowed_alert_types'] ?? '') : ['simple', 'ack_required'];
?>

<div class="max-w-2xl space-y-4">
    <?php if ($isEdit && $bearerToken): ?>
    <div class="p-4 rounded-xl bg-amber-50 dark:bg-amber-950/40 border border-amber-200 dark:border-amber-900">
        <p class="text-sm font-semibold text-amber-800 dark:text-amber-300 mb-2">
            Bearer token — copy for external systems
        </p>
        <p class="text-xs text-amber-700/80 dark:text-amber-400/80 mb-2">
            Use as <code class="font-mono">Authorization: Bearer …</code> on <code class="font-mono">POST /api/v1/alert</code>.
            Regenerating replaces this value; the old secret stops working.
        </p>
        <code id="token-bearer-value"
              class="block text-xs font-mono break-all p-3 bg-white dark:bg-gray-900 rounded-lg border border-amber-200 dark:border-amber-800 text-gray-800 dark:text-gray-200"><?= htmlspecialchars($bearerToken) ?></code>
        <button type="button" onclick="copyBearerToken()"
                class="mt-2 text-xs text-amber-700 dark:text-amber-400 hover:underline">Copy to clipboard</button>
        <form method="POST" action="/admin/tokens/regenerate" class="mt-3"
              onsubmit="return confirm('Generate a new bearer token? The token shown above will stop working immediately.');">
            <input type="hidden" name="id" value="<?= $tokenId ?>">
            <button type="submit" class="text-xs text-amber-800 dark:text-amber-300 hover:underline">
                Regenerate (invalidates this token)
            </button>
        </form>
    </div>
    <?php elseif ($isEdit): ?>
    <div class="p-4 rounded-xl bg-gray-50 dark:bg-gray-800/50 border border-gray-200 dark:border-gray-700">
        <p class="text-sm text-gray-700 dark:text-gray-300 mb-2">
            The bearer secret is not stored after creation. Regenerate to get a new copyable token.
        </p>
        <form method="POST" action="/admin/tokens/regenerate" class="inline"
              onsubmit="return confirm('Generate a new bearer token? The current secret will stop working immediately.');">
            <input type="hidden" name="id" value="<?= $tokenId ?>">
            <button type="submit"
                    class="px-4 py-2 text-sm font-semibold rounded-xl bg-gray-200 dark:bg-gray-700 hover:bg-gray-300 dark:hover:bg-gray-600 text-gray-800 dark:text-gray-200">
                Regenerate bearer token
            </button>
        </form>
    </div>
    <?php endif; ?>

    <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-100 dark:border-gray-800">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white"><?= $pageTitle ?></h2>
            <?php if (!$isEdit): ?>
            <p class="text-xs text-gray-400 mt-1">After creation you will be taken to the edit page to copy the bearer token.</p>
            <?php endif; ?>
        </div>

        <form method="POST" action="/admin/tokens/save" class="p-6 space-y-5">
            <input type="hidden" name="id" value="<?= $tokenId ?>">

            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Name <span class="text-red-500">*</span></label>
                <input type="text" name="name" required placeholder="e.g. CheckMK Production"
                       value="<?= htmlspecialchars($item['name'] ?? '') ?>"
                       class="w-full px-3 py-2 text-sm rounded-xl border border-gray-300 dark:border-gray-700
                              bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-red-500">
            </div>

            <?php if (!$isEdit): ?>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Owner Org <span class="text-red-500">*</span></label>
                <select name="owner_org_id" required
                        class="w-full px-3 py-2 text-sm rounded-xl border border-gray-300 dark:border-gray-700
                               bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-red-500">
                    <?php foreach ($orgs as $org): ?>
                    <option value="<?= (int) $org['id'] ?>"><?= htmlspecialchars($org['display_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php else: ?>
            <div class="text-sm text-gray-500">Org: <?= htmlspecialchars($item['owner_org_name'] ?? '') ?></div>
            <?php endif; ?>

            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Allowed Severities</label>
                <div class="flex flex-wrap gap-2">
                    <?php foreach ($severities as $sev): ?>
                    <label class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-gray-200 dark:border-gray-700 text-sm cursor-pointer">
                        <input type="checkbox" name="allowed_severity[]" value="<?= $sev ?>"
                               <?= in_array($sev, $selectedSev, true) ? 'checked' : '' ?>
                               class="rounded text-red-600 focus:ring-red-500">
                        <?= htmlspecialchars($sev) ?>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Allowed Alert Types</label>
                <div class="flex flex-wrap gap-2">
                    <?php foreach ($alertTypes as $typ): ?>
                    <label class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-gray-200 dark:border-gray-700 text-sm cursor-pointer">
                        <input type="checkbox" name="allowed_alert_types[]" value="<?= $typ ?>"
                               <?= in_array($typ, $selectedTyp, true) ? 'checked' : '' ?>
                               class="rounded text-red-600 focus:ring-red-500">
                        <?= htmlspecialchars($typ) ?>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">IP Allowlist</label>
                <input type="text" name="ip_allowlist" placeholder="Optional comma-separated CIDRs"
                       value="<?= htmlspecialchars($item['ip_allowlist'] ?? '') ?>"
                       class="w-full px-3 py-2 text-sm rounded-xl border border-gray-300 dark:border-gray-700
                              bg-white dark:bg-gray-800 text-gray-900 dark:text-white font-mono focus:ring-2 focus:ring-red-500">
            </div>

            <?php if ($isEdit): ?>
            <div>
                <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                    <input type="checkbox" name="is_active" value="1"
                           <?= !empty($item['is_active']) ? 'checked' : '' ?>
                           class="rounded text-red-600 focus:ring-red-500">
                    Active
                </label>
            </div>
            <?php endif; ?>

            <div class="flex items-center gap-3 pt-2">
                <button type="submit"
                        class="px-5 py-2 bg-red-600 hover:bg-red-700 text-white text-sm font-semibold rounded-xl">
                    <?= $isEdit ? 'Save Changes' : 'Create Token' ?>
                </button>
                <a href="/admin/tokens" class="text-sm text-gray-500 hover:text-gray-700">Cancel</a>
            </div>
        </form>
    </div>
</div>

<script>
function copyBearerToken() {
    const el = document.getElementById('token-bearer-value');
    if (!el) return;
    navigator.clipboard.writeText(el.textContent.trim());
    toast('Bearer token copied to clipboard');
}
</script>
