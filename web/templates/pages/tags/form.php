<?php
use NexAlert\Config\Env;

$tagId     = (int) ($_GET['id'] ?? 0);
$isEdit    = $tagId > 0;
$pageTitle = $isEdit ? 'Edit Tag' : 'New Tag';
$token     = $_SESSION['access_token'];
$apiBase   = Env::get('APP_URL');
$tag       = null;

function tags_api_call(string $method, string $url, string $token, ?array $body = null): ?array {
    $payload = $body ? json_encode($body) : null;
    $headers = "Authorization: Bearer {$token}\r\nContent-Type: application/json";
    if ($payload) $headers .= "\r\nContent-Length: " . strlen($payload);
    $ctx = stream_context_create(['http' => [
        'method' => $method, 'header' => $headers, 'content' => $payload,
        'timeout' => 10, 'ignore_errors' => true,
    ]]);
    $raw = @file_get_contents($url, false, $ctx);
    return $raw ? json_decode($raw, true) : null;
}

$orgsRes = tags_api_call('GET', "{$apiBase}/api/v1/orgs?limit=200", $token);
$orgs    = $orgsRes['data']['orgs'] ?? [];

if ($isEdit) {
    $res = tags_api_call('GET', "{$apiBase}/api/v1/tags/{$tagId}", $token);
    if (!$res || !$res['success']) {
        flash('Tag not found.', 'error');
        header('Location: /admin/tags');
        exit;
    }
    $tag = $res['data'];
}
?>

<div class="max-w-3xl" x-data="tagForm(<?= $isEdit ? 'true' : 'false' ?>, <?= $tagId ?>)" x-init="init()">

    <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 overflow-hidden mb-6">
        <div class="px-6 py-4 border-b border-gray-100 dark:border-gray-800">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Tag Details</h2>
            <?php if ($isEdit && !empty($tag['is_system'])): ?>
            <p class="text-xs text-blue-500 mt-1">System tag — auto-created from the org tree. View only.</p>
            <?php endif; ?>
        </div>

        <?php if ($isEdit && !empty($tag['is_system'])): ?>
        <div class="p-6 space-y-3 text-sm">
            <div><span class="text-gray-400">Name:</span> <span class="font-medium text-gray-900 dark:text-white"><?= htmlspecialchars($tag['name']) ?></span></div>
            <div><span class="text-gray-400">Slug:</span> <code class="font-mono text-xs"><?= htmlspecialchars($tag['slug']) ?></code></div>
            <div><span class="text-gray-400">Assignments:</span> <?= (int) ($tag['assignment_count'] ?? 0) ?></div>
            <a href="/admin/tags" class="inline-block text-sm text-gray-500 hover:text-gray-700 dark:hover:text-gray-300 mt-2">← Back to tags</a>
        </div>
        <?php else: ?>
        <form method="POST" action="/admin/tags/save" class="p-6 space-y-5">
            <input type="hidden" name="id" value="<?= $tagId ?>">

            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Name <span class="text-red-500">*</span></label>
                <input type="text" name="name" required
                       value="<?= htmlspecialchars($tag['name'] ?? '') ?>"
                       class="w-full px-3 py-2 text-sm rounded-xl border border-gray-300 dark:border-gray-700
                              bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-red-500">
            </div>

            <?php if (!$isEdit): ?>
            <div class="grid grid-cols-2 gap-5">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Slug</label>
                    <input type="text" name="slug" placeholder="auto-generated if blank"
                           class="w-full px-3 py-2 text-sm rounded-xl border border-gray-300 dark:border-gray-700
                                  bg-white dark:bg-gray-800 text-gray-900 dark:text-white font-mono focus:ring-2 focus:ring-red-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Owner Org</label>
                    <select name="owner_org_id"
                            class="w-full px-3 py-2 text-sm rounded-xl border border-gray-300 dark:border-gray-700
                                   bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-red-500">
                        <option value="">Global (all orgs)</option>
                        <?php foreach ($orgs as $org): ?>
                        <option value="<?= (int) $org['id'] ?>"><?= htmlspecialchars($org['display_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <?php else: ?>
            <div class="text-sm text-gray-500 dark:text-gray-400">
                Slug: <code class="font-mono"><?= htmlspecialchars($tag['slug'] ?? '') ?></code>
                · Scope: <?= htmlspecialchars($tag['owner_org_name'] ?? 'Global') ?>
            </div>
            <?php endif; ?>

            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Description</label>
                <textarea name="description" rows="3"
                          class="w-full px-3 py-2 text-sm rounded-xl border border-gray-300 dark:border-gray-700
                                 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-red-500"><?= htmlspecialchars($tag['description'] ?? '') ?></textarea>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300 cursor-pointer">
                    <input type="checkbox" name="is_exclusive" value="1"
                           <?= !empty($tag['is_exclusive']) ? 'checked' : '' ?>
                           class="rounded border-gray-300 text-red-600 focus:ring-red-500">
                    Exclusive tag
                </label>
                <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300 cursor-pointer">
                    <input type="checkbox" name="allow_self_request" value="1"
                           <?= ($tag['allow_self_request'] ?? 1) ? 'checked' : '' ?>
                           class="rounded border-gray-300 text-red-600 focus:ring-red-500">
                    Allow self-request
                </label>
                <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300 cursor-pointer">
                    <input type="checkbox" name="requires_approval" value="1"
                           <?= ($tag['requires_approval'] ?? 1) ? 'checked' : '' ?>
                           class="rounded border-gray-300 text-red-600 focus:ring-red-500">
                    Requires approval
                </label>
            </div>

            <div class="flex items-center gap-3 pt-2">
                <button type="submit"
                        class="px-5 py-2 bg-red-600 hover:bg-red-700 text-white text-sm font-semibold rounded-xl transition-colors">
                    <?= $isEdit ? 'Save Changes' : 'Create Tag' ?>
                </button>
                <a href="/admin/tags" class="text-sm text-gray-500 hover:text-gray-700 dark:hover:text-gray-300">Cancel</a>
            </div>
        </form>
        <?php endif; ?>
    </div>

    <?php if ($isEdit): ?>
    <!-- Pending approval requests -->
    <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 overflow-hidden mb-6"
         x-show="pendingRequests.length > 0">
        <div class="px-6 py-4 border-b border-gray-100 dark:border-gray-800">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Pending Approval Requests</h2>
        </div>
        <ul class="divide-y divide-gray-100 dark:divide-gray-800">
            <template x-for="req in pendingRequests" :key="req.id">
                <li class="px-6 py-4">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <div class="text-sm font-medium text-gray-900 dark:text-white"
                                 x-text="req.user_display_name + ' (' + req.user_username + ')'"></div>
                            <div class="text-xs text-gray-400 mt-0.5" x-text="req.justification || 'No justification provided'"></div>
                            <div class="text-xs text-gray-400 mt-1" x-text="'Requested ' + req.created_at"></div>
                        </div>
                        <div class="flex gap-2 flex-shrink-0">
                            <button @click="reviewRequest(req, 'approve')"
                                    class="px-3 py-1.5 text-xs font-semibold bg-green-600 hover:bg-green-700 text-white rounded-lg">Approve</button>
                            <button @click="reviewRequest(req, 'deny')"
                                    class="px-3 py-1.5 text-xs font-semibold bg-gray-200 dark:bg-gray-700 hover:bg-gray-300 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-200 rounded-lg">Deny</button>
                        </div>
                    </div>
                </li>
            </template>
        </ul>
    </div>
    <?php endif; ?>
</div>

<script>
function tagForm(isEdit, tagId) {
    return {
        isEdit, tagId,
        pendingRequests: [],

        async init() {
            if (!this.isEdit) return;
            const res = await api.get(`/tags/${this.tagId}/requests?status=pending`);
            if (res.ok) this.pendingRequests = res.data.data.requests;
        },

        async reviewRequest(req, action) {
            const path = action === 'approve' ? 'approve' : 'deny';
            if (!confirm(`${action === 'approve' ? 'Approve' : 'Deny'} tag request for ${req.user_display_name}?`)) return;
            const res = await api.post(`/tags/${this.tagId}/requests/${req.id}/${path}`, {});
            if (res.ok) {
                toast(action === 'approve' ? 'Request approved' : 'Request denied');
                this.pendingRequests = this.pendingRequests.filter(r => r.id !== req.id);
            } else {
                toast(res.data.error || 'Failed', 'error');
            }
        },
    };
}
</script>
