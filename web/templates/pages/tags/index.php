<?php
$pageTitle    = 'Tags';
$pageSubtitle = 'Targeting labels, exclusive access, and approval requests';
$isSuperAdmin = in_array('super_admin', $_SESSION['user']['roles'] ?? [], true);

$headerActions = '
<a href="/admin/tags/new"
   class="flex items-center gap-2 px-4 py-2 bg-red-600 hover:bg-red-700 text-white text-sm font-semibold rounded-xl transition-colors">
    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
    </svg>
    New Tag
</a>';
?>

<div x-data="tagsPage()" x-init="init()">

    <div class="flex flex-wrap items-center gap-3 mb-4">
        <input type="search" placeholder="Search tags…" x-model.debounce.300ms="search"
               @input="loadTags()"
               <?= tip_attr('Search tag name or slug — use tag:slug in alert targeting expressions', 'bottom') ?>
               class="px-3 py-2 text-sm rounded-xl border border-gray-200 dark:border-gray-700
                      bg-white dark:bg-gray-800 text-gray-900 dark:text-white
                      focus:outline-none focus:ring-2 focus:ring-red-500 w-64">

        <select x-model="filterActive" @change="loadTags()"
                class="px-3 py-2 text-sm rounded-xl border border-gray-200 dark:border-gray-700
                       bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300
                       focus:outline-none focus:ring-2 focus:ring-red-500">
            <option value="1">Active</option>
            <option value="0">Inactive</option>
            <option value="all">All</option>
        </select>

        <select x-model="filterSystem" @change="loadTags()"
                <?= tip_attr('System tags auto-inherit from org node placement; manual tags are explicitly assigned', 'bottom') ?>
                class="px-3 py-2 text-sm rounded-xl border border-gray-200 dark:border-gray-700
                       bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300
                       focus:outline-none focus:ring-2 focus:ring-red-500">
            <option value="all">All types</option>
            <option value="0">Manual</option>
            <option value="1">System (org tree)</option>
        </select>

        <div class="ml-auto text-sm text-gray-400" x-text="total + ' tags'"></div>
    </div>

    <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 overflow-hidden">
        <div x-show="loading" class="p-8 text-center text-gray-400 text-sm">Loading…</div>
        <div x-show="!loading">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 dark:bg-gray-800/50">
                    <tr>
                        <th class="text-left px-5 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Tag</th>
                        <th class="text-left px-5 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider hidden md:table-cell">Slug</th>
                        <th class="text-left px-5 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider hidden lg:table-cell">Scope</th>
                        <th class="text-center px-5 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Users</th>
                        <th class="text-center px-5 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider hidden lg:table-cell">Pending</th>
                        <th class="text-center px-5 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Status</th>
                        <th class="px-5 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    <template x-for="tag in tags" :key="tag.id">
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/40 transition-colors">
                            <td class="px-5 py-3">
                                <a :href="'/admin/tags/edit?id=' + tag.id"
                                   class="font-medium text-gray-900 dark:text-white hover:text-red-600 dark:hover:text-red-400"
                                   x-text="tag.name"></a>
                                <div class="flex items-center gap-1.5 mt-0.5">
                                    <span x-show="isSystem(tag.is_system)"
                                          class="text-xs px-1.5 py-0.5 rounded bg-blue-50 text-blue-600 dark:bg-blue-900/30 dark:text-blue-300">System</span>
                                    <span x-show="isSystem(tag.is_system) && tag.node_backed == 0"
                                          class="text-xs px-1.5 py-0.5 rounded bg-amber-50 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300"
                                          title="Org node was removed — deactivate or delete from Inactive filter">Orphan</span>
                                    <span x-show="tag.is_exclusive == 1"
                                          class="text-xs px-1.5 py-0.5 rounded bg-amber-50 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300">Exclusive</span>
                                </div>
                            </td>
                            <td class="px-5 py-3 hidden md:table-cell">
                                <code class="text-xs text-gray-500 dark:text-gray-400 font-mono" x-text="tag.slug"></code>
                            </td>
                            <td class="px-5 py-3 text-gray-500 dark:text-gray-400 hidden lg:table-cell"
                                x-text="tag.owner_org_name || 'Global'"></td>
                            <td class="px-5 py-3 text-center text-gray-500 dark:text-gray-400" x-text="tag.assignment_count"></td>
                            <td class="px-5 py-3 text-center hidden lg:table-cell">
                                <span x-show="tag.pending_request_count > 0"
                                      class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium
                                             bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-400"
                                      x-text="tag.pending_request_count"></span>
                                <span x-show="!tag.pending_request_count || tag.pending_request_count == 0" class="text-gray-300">—</span>
                            </td>
                            <td class="px-5 py-3 text-center">
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium"
                                      :class="isActive(tag.is_active)
                                          ? 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-400'
                                          : 'bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-500'"
                                      x-text="isActive(tag.is_active) ? 'Active' : 'Inactive'"></span>
                            </td>
                            <td class="px-5 py-3 text-right">
                                <div class="flex items-center justify-end gap-2 flex-wrap">
                                    <a :href="'/admin/tags/edit?id=' + tag.id"
                                       class="text-xs text-gray-400 hover:text-gray-600 dark:hover:text-gray-200"
                                       x-text="isSystem(tag.is_system) ? 'View' : 'Edit'"></a>
                                    <button @click="deactivate(tag)"
                                            class="text-xs text-amber-600 hover:text-amber-800 dark:text-amber-400"
                                            x-show="canDeactivate(tag)">Deactivate</button>
                                    <button @click="reactivate(tag)"
                                            class="text-xs text-green-600 hover:text-green-800 dark:text-green-400"
                                            x-show="canReactivate(tag)">Reactivate</button>
                                    <button @click="deleteTag(tag)"
                                            class="text-xs text-red-500 hover:text-red-700"
                                            x-show="canDelete(tag)">Delete</button>
                                </div>
                            </td>
                        </tr>
                    </template>
                    <tr x-show="!loading && tags.length === 0">
                        <td colspan="7" class="px-5 py-8 text-center text-sm text-gray-400">
                            No tags found.
                            <a href="/admin/tags/new" class="text-red-600 hover:underline ml-1">Create one</a>
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

    <!-- In-use warning before force delete -->
    <div x-show="pendingDelete" x-cloak
         class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50"
         @keydown.escape.window="pendingDelete = null">
        <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-700
                    shadow-xl max-w-md w-full p-6" @click.outside="pendingDelete = null">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-2">Tag in use</h3>
            <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">
                <span x-text="pendingDelete?.tag?.name"></span> is still referenced. Delete anyway?
            </p>
            <ul class="text-sm text-gray-600 dark:text-gray-400 space-y-1 mb-6" x-show="pendingDelete?.usage">
                <li x-show="pendingDelete.usage.assignments > 0">
                    <span x-text="pendingDelete.usage.assignments"></span> user assignment(s)
                </li>
                <li x-show="pendingDelete.usage.alert_targets > 0">
                    <span x-text="pendingDelete.usage.alert_targets"></span> alert target(s)
                </li>
                <li x-show="pendingDelete.usage.pending_requests > 0">
                    <span x-text="pendingDelete.usage.pending_requests"></span> pending approval request(s)
                </li>
            </ul>
            <div class="flex justify-end gap-3">
                <button @click="pendingDelete = null"
                        class="px-4 py-2 text-sm text-gray-600 dark:text-gray-400 hover:text-gray-900">Cancel</button>
                <button @click="confirmForceDelete()"
                        class="px-4 py-2 text-sm font-semibold bg-red-600 hover:bg-red-700 text-white rounded-xl">
                    Delete anyway
                </button>
            </div>
        </div>
    </div>
</div>

<script>
function tagsPage() {
    return {
        tags: [], total: 0, loading: true,
        search: '', filterActive: '1', filterSystem: 'all',
        limit: 50, offset: 0,
        pendingDelete: null,
        isSuperAdmin: <?= $isSuperAdmin ? 'true' : 'false' ?>,

        canDeactivate(tag) {
            if (!isActive(tag.is_active)) return false;
            if (!isSystem(tag.is_system)) return true;
            return this.isSuperAdmin && tag.node_backed == 0;
        },

        canReactivate(tag) {
            if (isActive(tag.is_active)) return false;
            if (!isSystem(tag.is_system)) return true;
            return false;
        },

        canDelete(tag) {
            if (isSystem(tag.is_system)) {
                return this.isSuperAdmin && tag.node_backed == 0;
            }
            return true;
        },

        async init() {
            registerPageRefresh(() => this.loadTags());
            await this.loadTags();
        },
        async prevPage() { this.offset = Math.max(0, this.offset - this.limit); await this.loadTags(); },
        async nextPage() { this.offset += this.limit; await this.loadTags(); },

        async loadTags() {
            this.loading = true;
            const params = new URLSearchParams({
                limit: this.limit, offset: this.offset,
                active: this.filterActive, system: this.filterSystem,
            });
            if (this.search) params.set('search', this.search);
            const res = await api.get('/tags?' + params);
            if (res.ok) {
                this.tags = res.data.data.tags;
                this.total = res.data.data.total;
            } else {
                toast(res.data?.error || 'Failed to load tags', 'error');
            }
            this.loading = false;
        },

        async deactivate(tag) {
            if (!confirm(`Deactivate tag "${tag.name}"? Assigned users keep the assignment but the tag won't appear in assignable lists.`)) return;
            const res = await api.delete(`/tags/${tag.id}`);
            if (res.ok) {
                toast(`${tag.name} deactivated`);
                await this.loadTags();
            } else {
                toast(res.data?.error || 'Failed to deactivate', 'error');
            }
        },

        async reactivate(tag) {
            const res = await api.put(`/tags/${tag.id}`, { is_active: 1 });
            if (res.ok) {
                toast(`${tag.name} reactivated`);
                await this.loadTags();
            } else {
                toast(res.data?.error || 'Failed to reactivate', 'error');
            }
        },

        async deleteTag(tag, force = false) {
            if (!force && !confirm(`Permanently delete "${tag.name}"? This cannot be undone.`)) return;
            const params = new URLSearchParams({ hard: '1' });
            if (force) params.set('force', '1');
            const res = await api.delete(`/tags/${tag.id}?${params}`);
            if (!res.ok) {
                if (res.status === 409 && res.data?.usage) {
                    this.pendingDelete = { tag, usage: res.data.usage };
                    return;
                }
                const msg = res.data?.error || 'Delete failed';
                toast(msg, 'error');
                return;
            }
            toast(`${tag.name} permanently deleted`);
            this.pendingDelete = null;
            await this.loadTags();
        },

        async confirmForceDelete() {
            if (!this.pendingDelete) return;
            await this.deleteTag(this.pendingDelete.tag, true);
        }
    };
}
</script>
