<?php
$pageTitle    = 'Groups';
$pageSubtitle = 'Distribution lists and nested group membership';

$headerActions = '
<a href="/admin/groups/new"
   class="flex items-center gap-2 px-4 py-2 bg-red-600 hover:bg-red-700 text-white text-sm font-semibold rounded-xl transition-colors">
    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
    </svg>
    New Group
</a>';
?>

<div x-data="groupsPage()" x-init="init()">

    <div class="flex flex-wrap items-center gap-3 mb-4">
        <input type="search" placeholder="Search groups…" x-model.debounce.300ms="search"
               @input="loadGroups()"
               class="px-3 py-2 text-sm rounded-xl border border-gray-200 dark:border-gray-700
                      bg-white dark:bg-gray-800 text-gray-900 dark:text-white
                      focus:outline-none focus:ring-2 focus:ring-red-500 w-64">

        <select x-model="filterActive" @change="loadGroups()"
                class="px-3 py-2 text-sm rounded-xl border border-gray-200 dark:border-gray-700
                       bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300
                       focus:outline-none focus:ring-2 focus:ring-red-500">
            <option value="1">Active</option>
            <option value="0">Inactive</option>
            <option value="all">All</option>
        </select>

        <div class="ml-auto text-sm text-gray-400" x-text="total + ' groups'"></div>
    </div>

    <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 overflow-hidden">
        <div x-show="loading" class="p-8 text-center text-gray-400 text-sm">Loading…</div>
        <div x-show="!loading">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 dark:bg-gray-800/50">
                    <tr>
                        <th class="text-left px-5 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Group</th>
                        <th class="text-left px-5 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider hidden md:table-cell">Slug</th>
                        <th class="text-left px-5 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider hidden lg:table-cell">Org</th>
                        <th class="text-center px-5 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Members</th>
                        <th class="text-center px-5 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider hidden lg:table-cell">Subgroups</th>
                        <th class="text-center px-5 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Status</th>
                        <th class="px-5 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    <template x-for="group in groups" :key="group.id">
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/40 transition-colors">
                            <td class="px-5 py-3">
                                <a :href="'/admin/groups/edit?id=' + group.id"
                                   class="font-medium text-gray-900 dark:text-white hover:text-red-600 dark:hover:text-red-400"
                                   x-text="group.name"></a>
                                <div class="text-xs text-gray-400 mt-0.5 truncate max-w-xs" x-text="group.description || ''" x-show="group.description"></div>
                            </td>
                            <td class="px-5 py-3 hidden md:table-cell">
                                <code class="text-xs text-gray-500 dark:text-gray-400 font-mono" x-text="group.slug"></code>
                            </td>
                            <td class="px-5 py-3 text-gray-500 dark:text-gray-400 hidden lg:table-cell" x-text="group.owner_org_name"></td>
                            <td class="px-5 py-3 text-center text-gray-500 dark:text-gray-400" x-text="group.member_count"></td>
                            <td class="px-5 py-3 text-center text-gray-500 dark:text-gray-400 hidden lg:table-cell" x-text="group.child_group_count"></td>
                            <td class="px-5 py-3 text-center">
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium"
                                      :class="isActive(group.is_active)
                                          ? 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-400'
                                          : 'bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-500'"
                                      x-text="isActive(group.is_active) ? 'Active' : 'Inactive'"></span>
                            </td>
                            <td class="px-5 py-3 text-right">
                                <div class="flex items-center justify-end gap-2 flex-wrap">
                                    <a :href="'/admin/groups/edit?id=' + group.id"
                                       class="text-xs text-gray-400 hover:text-gray-600 dark:hover:text-gray-200">Edit</a>
                                    <button @click="deleteGroup(group)"
                                            class="text-xs text-red-500 hover:text-red-700 dark:text-red-400"
                                            x-show="isActive(group.is_active)">Delete</button>
                                    <button @click="reactivate(group)"
                                            class="text-xs text-green-600 hover:text-green-800 dark:text-green-400"
                                            x-show="!isActive(group.is_active)">Reactivate</button>
                                </div>
                            </td>
                        </tr>
                    </template>
                    <tr x-show="!loading && groups.length === 0">
                        <td colspan="7" class="px-5 py-8 text-center text-sm text-gray-400">
                            No groups found.
                            <a href="/admin/groups/new" class="text-red-600 hover:underline ml-1">Create one</a>
                        </td>
                    </tr>
                </tbody>
            </table>

            <div class="flex items-center justify-between px-5 py-3 border-t border-gray-100 dark:border-gray-800"
                 x-show="total > limit">
                <button @click="prevPage()" :disabled="offset === 0"
                        class="text-sm text-gray-500 hover:text-gray-700 disabled:opacity-40">← Previous</button>
                <span class="text-xs text-gray-400">
                    <span x-text="offset + 1"></span>–<span x-text="Math.min(offset + limit, total)"></span>
                    of <span x-text="total"></span>
                </span>
                <button @click="nextPage()" :disabled="offset + limit >= total"
                        class="text-sm text-gray-500 hover:text-gray-700 disabled:opacity-40">Next →</button>
            </div>
        </div>
    </div>

    <!-- Second confirm when group has members or nested groups -->
    <div x-show="pendingDelete" x-cloak
         class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50"
         @keydown.escape.window="pendingDelete = null">
        <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-700
                    shadow-xl max-w-md w-full p-6" @click.outside="pendingDelete = null">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-2">Group not empty</h3>
            <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">
                <span class="font-medium" x-text="pendingDelete?.name"></span> still has:
            </p>
            <ul class="text-sm text-gray-600 dark:text-gray-400 space-y-1 mb-6">
                <li x-show="(pendingDelete?.member_count || 0) > 0">
                    <span x-text="pendingDelete?.member_count"></span> direct member(s)
                </li>
                <li x-show="(pendingDelete?.child_group_count || 0) > 0">
                    <span x-text="pendingDelete?.child_group_count"></span> nested group(s)
                </li>
            </ul>
            <p class="text-sm text-gray-500 dark:text-gray-400 mb-6">
                Deleting removes this group from targeting. Members are not removed from NexAlert.
            </p>
            <div class="flex justify-end gap-3">
                <button @click="pendingDelete = null"
                        class="px-4 py-2 text-sm text-gray-600 dark:text-gray-400 hover:text-gray-900">Cancel</button>
                <button @click="confirmDelete()" :disabled="deleting"
                        class="px-4 py-2 text-sm font-semibold bg-red-600 hover:bg-red-700 text-white rounded-xl disabled:opacity-60">
                    <span x-text="deleting ? 'Deleting…' : 'Delete anyway'"></span>
                </button>
            </div>
        </div>
    </div>
</div>

<script>
function groupsPage() {
    return {
        groups: [], total: 0, loading: true,
        search: '', filterActive: '1',
        limit: 50, offset: 0,
        pendingDelete: null, deleting: false,

        async init() {
            registerPageRefresh(() => this.loadGroups());
            await this.loadGroups();
        },
        async prevPage() { this.offset = Math.max(0, this.offset - this.limit); await this.loadGroups(); },
        async nextPage() { this.offset += this.limit; await this.loadGroups(); },

        async loadGroups() {
            this.loading = true;
            const params = new URLSearchParams({ limit: this.limit, offset: this.offset, active: this.filterActive });
            if (this.search) params.set('search', this.search);
            const res = await api.get('/groups?' + params);
            if (res.ok) {
                this.groups = res.data.data.groups;
                this.total = res.data.data.total;
            } else {
                toast(res.data?.error || res.data?.message || 'Failed to load groups', 'error');
            }
            this.loading = false;
        },

        async deleteGroup(group) {
            if (!confirm(`Delete group "${group.name}"? It will be deactivated and removed from targeting.`)) return;

            const members = Number(group.member_count) || 0;
            const children = Number(group.child_group_count) || 0;

            if (members > 0 || children > 0) {
                this.pendingDelete = group;
                return;
            }

            await this.performDelete(group);
        },

        async confirmDelete() {
            if (!this.pendingDelete || this.deleting) return;
            await this.performDelete(this.pendingDelete);
            this.pendingDelete = null;
        },

        async performDelete(group) {
            this.deleting = true;
            const res = await api.delete(`/groups/${group.id}`);
            this.deleting = false;
            if (res.ok) {
                toast(`${group.name} deleted`);
                await this.loadGroups();
            } else {
                toast(res.data?.error || 'Failed to delete group', 'error');
            }
        },

        async reactivate(group) {
            const res = await api.put(`/groups/${group.id}`, { is_active: 1 });
            if (res.ok) {
                toast(`${group.name} reactivated`);
                await this.loadGroups();
            } else {
                toast(res.data?.error || 'Failed to reactivate', 'error');
            }
        }
    };
}
</script>
