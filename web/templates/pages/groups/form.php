<?php
use NexAlert\Config\Env;

$groupId   = (int) ($_GET['id'] ?? 0);
$isEdit    = $groupId > 0;
$pageTitle = $isEdit ? 'Edit Group' : 'New Group';
$token     = $_SESSION['access_token'];
$apiBase   = Env::get('APP_URL');

function groups_api_call(string $method, string $url, string $token, ?array $body = null): ?array {
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

$orgsRes = groups_api_call('GET', "{$apiBase}/api/v1/orgs?limit=200", $token);
$orgs    = $orgsRes['data']['orgs'] ?? [];
$group   = null;

if ($isEdit) {
    $res = groups_api_call('GET', "{$apiBase}/api/v1/groups/{$groupId}", $token);
    if (!$res || !$res['success']) {
        flash('Group not found.', 'error');
        header('Location: /admin/groups');
        exit;
    }
    $group = $res['data'];
}
?>

<div class="max-w-3xl" x-data="groupForm(<?= $isEdit ? 'true' : 'false' ?>, <?= $groupId ?>)" x-init="init()">

    <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 overflow-hidden mb-6">
        <div class="px-6 py-4 border-b border-gray-100 dark:border-gray-800">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Group Details</h2>
        </div>
        <form method="POST" action="/admin/groups/save" class="p-6 space-y-5">
            <input type="hidden" name="id" value="<?= $groupId ?>">

            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Name <span class="text-red-500">*</span></label>
                <input type="text" name="name" required
                       value="<?= htmlspecialchars($group['name'] ?? '') ?>"
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
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Owner Org <span class="text-red-500">*</span></label>
                    <select name="owner_org_id" required
                            class="w-full px-3 py-2 text-sm rounded-xl border border-gray-300 dark:border-gray-700
                                   bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-red-500">
                        <option value="">Select org…</option>
                        <?php foreach ($orgs as $org): ?>
                        <option value="<?= (int) $org['id'] ?>"><?= htmlspecialchars($org['display_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <?php else: ?>
            <div class="text-sm text-gray-500 dark:text-gray-400">
                Slug: <code class="font-mono"><?= htmlspecialchars($group['slug'] ?? '') ?></code>
                · Org: <?= htmlspecialchars($group['owner_org_name'] ?? '') ?>
            </div>
            <?php endif; ?>

            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Description</label>
                <textarea name="description" rows="3"
                          class="w-full px-3 py-2 text-sm rounded-xl border border-gray-300 dark:border-gray-700
                                 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-red-500"><?= htmlspecialchars($group['description'] ?? '') ?></textarea>
            </div>

            <div class="flex items-center gap-3 pt-2">
                <button type="submit"
                        class="px-5 py-2 bg-red-600 hover:bg-red-700 text-white text-sm font-semibold rounded-xl transition-colors">
                    <?= $isEdit ? 'Save Changes' : 'Create Group' ?>
                </button>
                <a href="/admin/groups" class="text-sm text-gray-500 hover:text-gray-700 dark:hover:text-gray-300">Cancel</a>
            </div>
        </form>
    </div>

    <?php if ($isEdit): ?>
    <!-- Members -->
    <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 overflow-hidden mb-6">
        <div class="px-6 py-4 border-b border-gray-100 dark:border-gray-800 flex items-center justify-between">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Members</h2>
            <span class="text-xs text-gray-400" x-text="members.length + ' users'"></span>
        </div>
        <div class="p-6 space-y-4">
            <div class="flex gap-2">
                <input type="search" placeholder="Search users to add…" x-model.debounce.300ms="userSearch"
                       @input="searchUsers()"
                       class="flex-1 px-3 py-2 text-sm rounded-xl border border-gray-200 dark:border-gray-700
                              bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-red-500">
            </div>
            <div x-show="userResults.length" class="border border-gray-200 dark:border-gray-700 rounded-xl overflow-hidden">
                <template x-for="u in userResults" :key="u.id">
                    <button type="button" @click="addMember(u)"
                            class="w-full flex items-center justify-between px-4 py-2 text-sm hover:bg-gray-50 dark:hover:bg-gray-800 text-left">
                        <span x-text="u.display_name + ' (' + u.username + ')'"></span>
                        <span class="text-red-600 text-xs font-medium">Add</span>
                    </button>
                </template>
            </div>
            <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                <template x-for="m in members" :key="m.user_id">
                    <li class="flex items-center justify-between py-2">
                        <div>
                            <span class="text-sm font-medium text-gray-900 dark:text-white" x-text="m.display_name"></span>
                            <code class="ml-2 text-xs text-gray-400 font-mono" x-text="m.username"></code>
                        </div>
                        <button @click="removeMember(m)" class="text-xs text-red-400 hover:text-red-600">Remove</button>
                    </li>
                </template>
                <li x-show="members.length === 0" class="py-4 text-sm text-gray-400 text-center">No members yet.</li>
            </ul>
        </div>
    </div>

    <!-- Child groups -->
    <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 overflow-hidden mb-6">
        <div class="px-6 py-4 border-b border-gray-100 dark:border-gray-800">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Nested Groups</h2>
            <p class="text-xs text-gray-400 mt-0.5">Members of child groups are included when targeting this group.</p>
        </div>
        <div class="p-6 space-y-4">
            <div class="flex gap-2">
                <select x-model="selectedChildId"
                        class="flex-1 px-3 py-2 text-sm rounded-xl border border-gray-200 dark:border-gray-700
                               bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-red-500">
                    <option value="">Select a group to nest…</option>
                    <template x-for="g in availableChildGroups" :key="g.id">
                        <option :value="g.id" x-text="g.name + ' (' + g.slug + ')'"></option>
                    </template>
                </select>
                <button type="button" @click="addChildGroup()" :disabled="!selectedChildId"
                        class="px-4 py-2 bg-gray-100 dark:bg-gray-800 text-sm font-medium rounded-xl
                               hover:bg-gray-200 dark:hover:bg-gray-700 disabled:opacity-40">Add</button>
            </div>
            <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                <template x-for="c in childGroups" :key="c.id">
                    <li class="flex items-center justify-between py-2">
                        <span class="text-sm text-gray-900 dark:text-white" x-text="c.name"></span>
                        <button @click="removeChildGroup(c)" class="text-xs text-red-400 hover:text-red-600">Remove</button>
                    </li>
                </template>
                <li x-show="childGroups.length === 0" class="py-4 text-sm text-gray-400 text-center">No nested groups.</li>
            </ul>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
function groupForm(isEdit, groupId) {
    return {
        isEdit, groupId,
        members: <?= json_encode($group['members'] ?? [], JSON_THROW_ON_ERROR) ?>,
        childGroups: <?= json_encode($group['child_groups'] ?? [], JSON_THROW_ON_ERROR) ?>,
        allGroups: [],
        userSearch: '', userResults: [],
        selectedChildId: '',

        async init() {
            if (this.isEdit) {
                const res = await api.get('/groups?limit=200&active=1');
                if (res.ok) {
                    this.allGroups = res.data.data.groups.filter(g => g.id !== this.groupId);
                }
            }
        },

        get availableChildGroups() {
            const linked = new Set(this.childGroups.map(c => c.id));
            return this.allGroups.filter(g => !linked.has(g.id));
        },

        async searchUsers() {
            if (this.userSearch.length < 2) { this.userResults = []; return; }
            const params = new URLSearchParams({ search: this.userSearch, limit: '10', active: '1' });
            const res = await api.get('/users?' + params);
            if (res.ok) {
                const memberIds = new Set(this.members.map(m => m.user_id));
                this.userResults = res.data.data.users.filter(u => !memberIds.has(u.id));
            }
        },

        async addMember(user) {
            const res = await api.post(`/groups/${this.groupId}/members`, { user_id: user.id });
            if (res.ok) {
                this.members.push({
                    user_id: user.id, username: user.username, display_name: user.display_name,
                });
                this.userSearch = '';
                this.userResults = [];
                toast(`${user.display_name} added`);
            } else {
                toast(res.data.error || 'Failed to add member', 'error');
            }
        },

        async removeMember(m) {
            if (!confirm(`Remove ${m.display_name} from this group?`)) return;
            const res = await api.delete(`/groups/${this.groupId}/members/${m.user_id}`);
            if (res.ok) {
                this.members = this.members.filter(x => x.user_id !== m.user_id);
                toast('Member removed');
            } else {
                toast(res.data.error || 'Failed', 'error');
            }
        },

        async addChildGroup() {
            if (!this.selectedChildId) return;
            const res = await api.post(`/groups/${this.groupId}/children`, {
                child_group_id: parseInt(this.selectedChildId, 10),
            });
            if (res.ok) {
                const g = this.allGroups.find(x => x.id == this.selectedChildId);
                if (g) this.childGroups.push({ id: g.id, name: g.name, slug: g.slug });
                this.selectedChildId = '';
                toast('Child group added');
            } else {
                toast(res.data.error || (res.data.errors && Object.values(res.data.errors)[0]) || 'Failed', 'error');
            }
        },

        async removeChildGroup(c) {
            if (!confirm(`Remove nested group "${c.name}"?`)) return;
            const res = await api.delete(`/groups/${this.groupId}/children/${c.id}`);
            if (res.ok) {
                this.childGroups = this.childGroups.filter(x => x.id !== c.id);
                toast('Child group removed');
            } else {
                toast(res.data.error || 'Failed', 'error');
            }
        },
    };
}
</script>
