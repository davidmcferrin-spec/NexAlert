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

<div class="max-w-3xl" x-data="userForm()" x-init="init(<?= $isEdit ? (int)($user['home_org_id'] ?? 0) : 0 ?>)">

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
            <button @click="showAdd = !showAdd"
                    class="text-xs font-semibold text-red-600 hover:text-red-700 dark:text-red-400">
                <span x-text="showAdd ? 'Cancel' : '+ Add Membership'"></span>
            </button>
        </div>

        <div x-show="showAdd" x-cloak class="px-5 py-4 border-b border-gray-100 dark:border-gray-800 bg-gray-50 dark:bg-gray-800/40 space-y-3">
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Organization</label>
                    <select x-model="newMembership.org_id" @change="loadMembershipNodes()"
                            class="w-full px-3 py-2 text-sm rounded-lg border border-gray-200 dark:border-gray-700
                                   bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-red-500">
                        <option value="">Select org…</option>
                        <?php foreach ($orgs as $o): ?>
                        <option value="<?= (int) $o['id'] ?>"><?= htmlspecialchars($o['display_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Org Node</label>
                    <select x-model="newMembership.org_node_id"
                            :disabled="!newMembership.org_id || nodesLoading"
                            class="w-full px-3 py-2 text-sm rounded-lg border border-gray-200 dark:border-gray-700
                                   bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-red-500 disabled:opacity-50">
                        <option value="">Select node…</option>
                        <template x-for="node in membershipNodes" :key="node.id">
                            <option :value="node.id"
                                    x-text="'　'.repeat(node.depth) + node.name + ' (' + node.node_type + ')'"></option>
                        </template>
                    </select>
                </div>
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Position Title (optional)</label>
                <input type="text" x-model="newMembership.position_title" placeholder="e.g. Chief Engineer"
                       class="w-full px-3 py-2 text-sm rounded-lg border border-gray-200 dark:border-gray-700
                              bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-red-500">
            </div>
            <button @click="addMembership()" :disabled="!newMembership.org_id || !newMembership.org_node_id"
                    class="px-4 py-2 text-sm font-semibold bg-red-600 hover:bg-red-700 disabled:opacity-50
                           text-white rounded-lg transition-colors">
                Add Membership
            </button>
        </div>

        <div class="divide-y divide-gray-100 dark:divide-gray-800">
            <template x-for="m in activeMemberships" :key="m.id">
                <div class="flex items-center justify-between px-5 py-3 gap-4">
                    <div class="min-w-0">
                        <div class="text-sm font-medium text-gray-900 dark:text-white break-words"
                             x-text="membershipLabel(m)"></div>
                        <div class="text-xs text-gray-400 mt-0.5" x-text="membershipMeta(m)"></div>
                    </div>
                    <button @click="remove(m)"
                            class="text-xs text-red-400 hover:text-red-600 transition-colors flex-shrink-0">Remove</button>
                </div>
            </template>
            <div x-show="activeMemberships.length === 0" class="px-5 py-4 text-sm text-gray-400">No org node memberships.</div>
        </div>
    </div>

    <!-- Tags panel -->
    <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 overflow-hidden"
         x-data="tagsPanel(<?= $userId ?>)" x-init="load()">
        <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100 dark:border-gray-800">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Tags</h3>
            <button @click="showAdd = !showAdd"
                    class="text-xs font-semibold text-red-600 hover:text-red-700 dark:text-red-400">
                <span x-text="showAdd ? 'Cancel' : '+ Assign Tag'"></span>
            </button>
        </div>

        <div x-show="showAdd" x-cloak class="px-5 py-4 border-b border-gray-100 dark:border-gray-800 bg-gray-50 dark:bg-gray-800/40">
            <div class="flex gap-2">
                <select x-model="selectedTagId"
                        class="flex-1 px-3 py-2 text-sm rounded-lg border border-gray-200 dark:border-gray-700
                               bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-red-500">
                    <option value="">Select a tag…</option>
                    <template x-for="t in availableTags" :key="t.id">
                        <option :value="t.id" x-text="t.name + (t.is_exclusive == 1 ? ' (exclusive)' : '')"></option>
                    </template>
                </select>
                <button @click="assignTag()" :disabled="!selectedTagId"
                        class="px-4 py-2 text-sm font-semibold bg-red-600 hover:bg-red-700 disabled:opacity-50
                               text-white rounded-lg transition-colors">
                    Assign
                </button>
            </div>
            <p x-show="availableTags.length === 0" class="text-xs text-gray-400 mt-2">No assignable tags found.</p>
        </div>

        <div class="px-5 py-4">
            <div class="flex flex-wrap gap-2">
                <template x-for="t in tags" :key="t.tag_id">
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-xs font-medium"
                          :class="t.is_system
                            ? 'bg-blue-50 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300'
                            : 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300'"
                          :title="assignmentLabel(t)">
                        <span x-text="t.name"></span>
                        <span class="opacity-60 text-[10px] uppercase" x-text="t.assignment_type.replace('_', ' ')"></span>
                        <button x-show="t.assignment_type === 'manual'" @click="removeTag(t)"
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
        membershipNodes: [],
        nodesLoading: false,
        showAdd: false,
        newMembership: { org_id: '', org_node_id: '', position_title: '' },

        get activeMemberships() {
            return this.memberships.filter(m => m.is_active == 1);
        },

        membershipLabel(m) {
            if (m.breadcrumb) return m.breadcrumb;
            if (m.path_nodes?.length) {
                return [m.org_name, ...m.path_nodes.map(n => n.name)].join(' → ');
            }
            return `${m.org_name} → ${m.node_name}`;
        },

        membershipMeta(m) {
            const parts = [m.node_type];
            if (m.position_title) parts.push(m.position_title);
            return parts.join(' · ');
        },

        async load() {
            const res = await api.get(`/users/${userId}/memberships`);
            if (res.ok) this.memberships = res.data.data.memberships;
        },

        async loadMembershipNodes() {
            this.newMembership.org_node_id = '';
            this.membershipNodes = [];
            if (!this.newMembership.org_id) return;
            this.nodesLoading = true;
            const res = await api.get(`/orgs/${this.newMembership.org_id}/nodes`);
            if (res.ok) this.membershipNodes = res.data.data.nodes;
            this.nodesLoading = false;
        },

        async addMembership() {
            const body = {
                org_id: parseInt(this.newMembership.org_id, 10),
                org_node_id: parseInt(this.newMembership.org_node_id, 10),
            };
            if (this.newMembership.position_title.trim()) {
                body.position_title = this.newMembership.position_title.trim();
            }
            const res = await api.post(`/users/${userId}/memberships`, body);
            if (res.ok) {
                toast('Membership added');
                this.showAdd = false;
                this.newMembership = { org_id: '', org_node_id: '', position_title: '' };
                this.membershipNodes = [];
                await this.load();
            } else {
                const err = res.data.errors
                    ? Object.values(res.data.errors).join(' ')
                    : (res.data.error || 'Failed to add membership');
                toast(err, 'error');
            }
        },

        async remove(m) {
            if (!confirm(`Remove membership at ${this.membershipLabel(m)}?`)) return;
            const res = await api.delete(`/users/${userId}/memberships/${m.id}`);
            if (res.ok) { toast('Membership removed'); await this.load(); }
            else toast(res.data.error || 'Failed', 'error');
        }
    };
}

function tagsPanel(userId) {
    return {
        tags: [],
        availableTags: [],
        selectedTagId: '',
        showAdd: false,

        async load() {
            const res = await api.get(`/users/${userId}/tags`);
            if (res.ok) this.tags = res.data.data.tags;
            await this.loadAvailableTags();
        },

        async loadAvailableTags() {
            const res = await api.get('/tags?assignable=1&limit=200&active=1');
            if (!res.ok) return;
            const assigned = new Set(this.tags.map(t => t.tag_id));
            this.availableTags = res.data.data.tags.filter(t => !assigned.has(t.id));
        },

        assignmentLabel(t) {
            if (t.source_node_name) return `From node: ${t.source_node_name}`;
            return t.assignment_type;
        },

        async assignTag() {
            if (!this.selectedTagId) return;
            const res = await api.post(`/users/${userId}/tags`, { tag_id: parseInt(this.selectedTagId, 10) });
            if (res.ok) {
                toast('Tag assigned');
                this.selectedTagId = '';
                this.showAdd = false;
                await this.load();
            } else {
                toast(res.data.error || 'Failed to assign tag', 'error');
            }
        },

        async removeTag(t) {
            if (!confirm(`Remove manual tag "${t.name}"?`)) return;
            const res = await api.delete(`/users/${userId}/tags/${t.tag_id}`);
            if (res.ok) { toast('Tag removed'); await this.load(); }
            else toast(res.data.error || 'Failed', 'error');
        }
    };
}
</script>
