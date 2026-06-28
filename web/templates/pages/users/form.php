<?php
// web/templates/pages/users/form.php
$userId    = (int) ($_GET['id'] ?? 0);
$isEdit    = $userId > 0;
$pageTitle = $isEdit ? 'Edit User' : 'Add User';
$token     = $_SESSION['access_token'];
$apiBase   = Env::get('APP_URL');
$user      = null;

function api_call(string $method, string $url, string $token, ?array $body = null): ?array {
    $payload = $body ? json_encode($body) : null;
    $headers = "Authorization: Bearer {$token}\r\nContent-Type: application/json";
    if ($payload) $headers .= "\r\nContent-Length: " . strlen($payload);
    $ctx = stream_context_create(['http' => [
        'method'  => $method,
        'header'  => $headers,
        'content' => $payload,
        'timeout' => 5,
        'ignore_errors' => true,
    ]]);
    $raw = @file_get_contents($url, false, $ctx);
    return $raw ? json_decode($raw, true) : null;
}

// Fetch orgs for dropdown
$orgsRes = api_call('GET', "{$apiBase}/api/v1/orgs?limit=200", $token);
$orgs    = $orgsRes['data']['orgs'] ?? [];

if ($isEdit) {
    $res  = api_call('GET', "{$apiBase}/api/v1/users/{$userId}", $token);
    if (!$res || !$res['success']) {
        flash('User not found.', 'error');
        header('Location: /admin/users');
        exit;
    }
    $user = $res['data'];
}
?>

<div class="max-w-2xl" x-data="userForm()" x-init="init(<?= $isEdit ? (int)($user['home_org_id'] ?? 0) : 0 ?>)">

    <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 overflow-hidden mb-6">
        <div class="px-6 py-4 border-b border-gray-100 dark:border-gray-800">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white"><?= $pageTitle ?></h2>
        </div>

        <form method="POST" action="/admin/users/save" class="p-6 space-y-5">
            <input type="hidden" name="id" value="<?= $userId ?>">

            <div class="grid grid-cols-2 gap-5">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">First Name <span class="text-red-500">*</span></label>
                    <input type="text" name="first_name" required
                           value="<?= htmlspecialchars($user['first_name'] ?? '') ?>"
                           class="w-full px-3 py-2 text-sm rounded-xl border border-gray-300 dark:border-gray-700
                                  bg-white dark:bg-gray-800 text-gray-900 dark:text-white
                                  focus:outline-none focus:ring-2 focus:ring-red-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Last Name <span class="text-red-500">*</span></label>
                    <input type="text" name="last_name" required
                           value="<?= htmlspecialchars($user['last_name'] ?? '') ?>"
                           class="w-full px-3 py-2 text-sm rounded-xl border border-gray-300 dark:border-gray-700
                                  bg-white dark:bg-gray-800 text-gray-900 dark:text-white
                                  focus:outline-none focus:ring-2 focus:ring-red-500">
                </div>
            </div>

            <div class="grid grid-cols-2 gap-5">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Username <span class="text-red-500">*</span></label>
                    <input type="text" name="username" required
                           value="<?= htmlspecialchars($user['username'] ?? '') ?>"
                           <?= $isEdit ? 'readonly class="opacity-60"' : '' ?>
                           class="w-full px-3 py-2 text-sm rounded-xl border border-gray-300 dark:border-gray-700
                                  bg-white dark:bg-gray-800 text-gray-900 dark:text-white font-mono
                                  focus:outline-none focus:ring-2 focus:ring-red-500">
                </div>
                <?php if (!$isEdit): ?>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Password</label>
                    <input type="password" name="password"
                           placeholder="Leave blank to auto-generate"
                           class="w-full px-3 py-2 text-sm rounded-xl border border-gray-300 dark:border-gray-700
                                  bg-white dark:bg-gray-800 text-gray-900 dark:text-white
                                  focus:outline-none focus:ring-2 focus:ring-red-500">
                </div>
                <?php endif; ?>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Email</label>
                <input type="email" name="email"
                       value="<?= htmlspecialchars(($user['contacts'] ?? [])[0]['contact_value'] ?? '') ?>"
                       <?= $isEdit ? 'readonly class="opacity-60"' : '' ?>
                       class="w-full px-3 py-2 text-sm rounded-xl border border-gray-300 dark:border-gray-700
                              bg-white dark:bg-gray-800 text-gray-900 dark:text-white
                              focus:outline-none focus:ring-2 focus:ring-red-500">
                <?php if ($isEdit): ?>
                <p class="text-xs text-gray-400 mt-1">Manage contact info from the Contacts tab below.</p>
                <?php endif; ?>
            </div>

            <div class="grid grid-cols-2 gap-5">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Organization <span class="text-red-500">*</span></label>
                    <select name="home_org_id" required x-model="selectedOrgId" @change="loadNodes()"
                            class="w-full px-3 py-2 text-sm rounded-xl border border-gray-300 dark:border-gray-700
                                   bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300
                                   focus:outline-none focus:ring-2 focus:ring-red-500">
                        <option value="">Select org…</option>
                        <?php foreach ($orgs as $o): ?>
                        <option value="<?= $o['id'] ?>" <?= ($user['home_org_id'] ?? '') == $o['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($o['display_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Home Node</label>
                    <select name="home_node_id" x-model="selectedNodeId"
                            class="w-full px-3 py-2 text-sm rounded-xl border border-gray-300 dark:border-gray-700
                                   bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300
                                   focus:outline-none focus:ring-2 focus:ring-red-500"
                            :disabled="!selectedOrgId || nodesLoading">
                        <option value="">No node / org root</option>
                        <template x-for="node in nodes" :key="node.id">
                            <option :value="node.id"
                                    :selected="node.id == <?= (int)($user['home_node_id'] ?? 0) ?>"
                                    x-text="'　'.repeat(node.depth) + node.name + ' (' + node.node_type + ')'">
                            </option>
                        </template>
                    </select>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-5">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Timezone</label>
                    <select name="timezone"
                            class="w-full px-3 py-2 text-sm rounded-xl border border-gray-300 dark:border-gray-700
                                   bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300
                                   focus:outline-none focus:ring-2 focus:ring-red-500">
                        <?php
                        $tzones = ['America/New_York','America/Chicago','America/Denver','America/Los_Angeles','America/Anchorage','Pacific/Honolulu','UTC'];
                        foreach ($tzones as $tz):
                        ?>
                        <option value="<?= $tz ?>" <?= ($user['timezone'] ?? 'America/Chicago') === $tz ? 'selected' : '' ?>>
                            <?= $tz ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="flex items-center justify-between pt-2">
                <a href="/admin/users" class="text-sm text-gray-500 hover:text-gray-700 dark:hover:text-gray-300 transition-colors">
                    ← Cancel
                </a>
                <button type="submit"
                        class="px-5 py-2 bg-red-600 hover:bg-red-700 text-white text-sm font-semibold rounded-xl transition-colors">
                    <?= $isEdit ? 'Save Changes' : 'Create User' ?>
                </button>
            </div>
        </form>
    </div>

    <?php if ($isEdit && $user): ?>
    <!-- Memberships panel -->
    <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 overflow-hidden mb-6"
         x-data="membershipsPanel(<?= $userId ?>)" x-init="load()">
        <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100 dark:border-gray-800">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Org Memberships</h3>
        </div>
        <div class="divide-y divide-gray-100 dark:divide-gray-800">
            <template x-for="m in memberships" :key="m.id">
                <div class="flex items-center justify-between px-5 py-3">
                    <div>
                        <div class="text-sm font-medium text-gray-900 dark:text-white" x-text="m.org_name + ' → ' + m.node_name"></div>
                        <div class="text-xs text-gray-400" x-text="m.position_title || m.node_type"></div>
                    </div>
                    <button @click="remove(m)"
                            class="text-xs text-red-400 hover:text-red-600 transition-colors">Remove</button>
                </div>
            </template>
            <div x-show="memberships.length === 0" class="px-5 py-4 text-sm text-gray-400">No additional memberships.</div>
        </div>
    </div>

    <!-- Tags panel -->
    <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 overflow-hidden"
         x-data="tagsPanel(<?= $userId ?>)" x-init="load()">
        <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100 dark:border-gray-800">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Tags</h3>
        </div>
        <div class="px-5 py-4">
            <div class="flex flex-wrap gap-2 mb-4">
                <template x-for="t in tags" :key="t.tag_id">
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-xs font-medium"
                          :class="t.is_system
                            ? 'bg-blue-50 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300'
                            : 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300'">
                        <span x-text="t.name"></span>
                        <button x-show="!t.is_system" @click="removeTag(t)"
                                class="opacity-60 hover:opacity-100 transition-opacity">✕</button>
                    </span>
                </template>
                <span x-show="tags.length === 0" class="text-sm text-gray-400">No tags assigned.</span>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
function userForm() {
    return {
        selectedOrgId: '<?= $user !== null ? (int)($user['home_org_id'] ?? 0) : 0 ?>',
        selectedNodeId: '<?= $user !== null ? (int)($user['home_node_id'] ?? 0) : 0 ?>',
        nodes: [], nodesLoading: false,

        async init(orgId) {
            if (orgId) { this.selectedOrgId = orgId; await this.loadNodes(); }
        },

        async loadNodes() {
            if (!this.selectedOrgId) { this.nodes = []; return; }
            this.nodesLoading = true;
            const res = await api.get(`/orgs/${this.selectedOrgId}/nodes`);
            if (res.ok) this.nodes = res.data.data.nodes;
            this.nodesLoading = false;
        }
    };
}

function membershipsPanel(userId) {
    return {
        memberships: [],
        async load() {
            const res = await api.get(`/users/${userId}/memberships`);
            if (res.ok) this.memberships = res.data.data.memberships;
        },
        async remove(m) {
            if (!confirm(`Remove membership in ${m.node_name}?`)) return;
            const res = await api.delete(`/users/${userId}/memberships/${m.id}`);
            if (res.ok) { toast('Membership removed'); await this.load(); }
            else toast(res.data.error || 'Failed', 'error');
        }
    };
}

function tagsPanel(userId) {
    return {
        tags: [],
        async load() {
            const res = await api.get(`/users/${userId}/tags`);
            if (res.ok) this.tags = res.data.data.tags;
        },
        async removeTag(t) {
            const res = await api.delete(`/users/${userId}/tags/${t.tag_id}`);
            if (res.ok) { toast('Tag removed'); await this.load(); }
            else toast(res.data.error || 'Failed', 'error');
        }
    };
}
</script>
