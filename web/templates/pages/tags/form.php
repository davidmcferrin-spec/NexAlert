<?php
$tagId     = (int) ($_GET['id'] ?? 0);
$isEdit    = $tagId > 0;
$pageTitle = $isEdit ? 'Edit Tag' : 'New Tag';
$isSuperAdmin = in_array('super_admin', $_SESSION['user']['roles'] ?? [], true);
?>

<div class="max-w-3xl" x-data="tagForm(<?= $isEdit ? 'true' : 'false' ?>, <?= $tagId ?>, <?= $isSuperAdmin ? 'true' : 'false' ?>)" x-init="init()">

    <div x-show="loading" class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 p-8 text-center text-sm text-gray-400">
        Loading tag…
    </div>

    <div x-show="loadError" x-cloak class="bg-white dark:bg-gray-900 rounded-2xl border border-red-200 dark:border-red-900 p-6">
        <p class="text-sm text-red-600 dark:text-red-400" x-text="loadError"></p>
        <a href="/admin/tags" class="inline-block mt-3 text-sm text-gray-500 hover:text-gray-700">← Back to tags</a>
    </div>

    <template x-if="!loading && !loadError">
        <div>
            <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 overflow-hidden mb-6">
                <div class="px-6 py-4 border-b border-gray-100 dark:border-gray-800">
                    <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Tag Details</h2>
                    <p x-show="isEdit && isSystem(tag?.is_system)" class="text-xs text-blue-500 mt-1">
                        System tag — auto-created from the org tree. View only.
                    </p>
                    <p x-show="isEdit && isSystem(tag?.is_system) && tag?.node_backed == 0" class="text-xs text-amber-600 dark:text-amber-400 mt-1">
                        No active org node backs this tag. Switch to <strong>Inactive</strong> on the tags list to remove it.
                    </p>
                </div>

                <div x-show="isEdit && isSystem(tag?.is_system)" class="p-6 space-y-3 text-sm">
                    <div><span class="text-gray-400">Name:</span> <span class="font-medium text-gray-900 dark:text-white" x-text="tag?.name"></span></div>
                    <div><span class="text-gray-400">Slug:</span> <code class="font-mono text-xs" x-text="tag?.slug"></code></div>
                    <div><span class="text-gray-400">Assignments:</span> <span x-text="tag?.assignment_count ?? 0"></span></div>
                    <a href="/admin/tags" class="inline-block text-sm text-gray-500 hover:text-gray-700 dark:hover:text-gray-300 mt-2">← Back to tags</a>
                </div>

                <form x-show="!isEdit || !isSystem(tag?.is_system)" method="POST" action="/admin/tags/save" class="p-6 space-y-5">
                    <input type="hidden" name="id" :value="isEdit ? tagId : ''">

                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Name <span class="text-red-500">*</span></label>
                        <input type="text" name="name" required
                               :value="tag?.name ?? ''"
                               class="w-full px-3 py-2 text-sm rounded-xl border border-gray-300 dark:border-gray-700
                                      bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-red-500">
                    </div>

                    <template x-if="!isEdit">
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
                                    <template x-for="org in orgs" :key="org.id">
                                        <option :value="org.id" x-text="org.display_name"></option>
                                    </template>
                                </select>
                            </div>
                        </div>
                    </template>

                    <div x-show="isEdit" class="text-sm text-gray-500 dark:text-gray-400">
                        Slug: <code class="font-mono" x-text="tag?.slug"></code>
                        · Scope: <span x-text="tag?.owner_org_name || 'Global'"></span>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Description</label>
                        <textarea name="description" rows="3" x-model="description"
                                  class="w-full px-3 py-2 text-sm rounded-xl border border-gray-300 dark:border-gray-700
                                         bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-red-500"></textarea>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300 cursor-pointer">
                            <input type="checkbox" name="is_exclusive" value="1"
                                   :checked="tag?.is_exclusive == 1"
                                   class="rounded border-gray-300 text-red-600 focus:ring-red-500">
                            Exclusive tag
                        </label>
                        <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300 cursor-pointer">
                            <input type="checkbox" name="allow_self_request" value="1"
                                   :checked="tag?.allow_self_request != 0"
                                   class="rounded border-gray-300 text-red-600 focus:ring-red-500">
                            Allow self-request
                        </label>
                        <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300 cursor-pointer">
                            <input type="checkbox" name="requires_approval" value="1"
                                   :checked="tag?.requires_approval != 0"
                                   class="rounded border-gray-300 text-red-600 focus:ring-red-500">
                            Requires approval
                        </label>
                    </div>

                    <div class="flex items-center gap-3 pt-2">
                        <button type="submit"
                                class="px-5 py-2 bg-red-600 hover:bg-red-700 text-white text-sm font-semibold rounded-xl transition-colors">
                            <span x-text="isEdit ? 'Save Changes' : 'Create Tag'"></span>
                        </button>
                        <a href="/admin/tags" class="text-sm text-gray-500 hover:text-gray-700 dark:hover:text-gray-300">Cancel</a>
                    </div>
                </form>
            </div>

            <div x-show="isEdit && pendingRequests.length > 0"
                 class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 overflow-hidden mb-6">
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
        </div>
    </template>
</div>

<script>
function tagForm(isEdit, tagId, isSuperAdmin) {
    return {
        isEdit, tagId, isSuperAdmin,
        tag: null, orgs: [], description: '',
        loading: isEdit, loadError: null,
        pendingRequests: [],

        async init() {
            if (!this.isEdit) {
                const orgsRes = await api.get('/orgs?limit=200');
                if (orgsRes.ok) this.orgs = orgsRes.data.data.orgs;
                return;
            }

            const res = await api.get(`/tags/${this.tagId}`);
            if (!res.ok) {
                this.loadError = res.data?.error || 'Tag not found';
                this.loading = false;
                return;
            }

            this.tag = res.data.data;
            this.description = this.tag?.description ?? '';

            const reqRes = await api.get(`/tags/${this.tagId}/requests?status=pending`);
            if (reqRes.ok) this.pendingRequests = reqRes.data.data.requests;

            this.loading = false;
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
