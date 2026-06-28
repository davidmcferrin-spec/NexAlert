<?php
$pageTitle    = 'Tags';
$pageSubtitle = 'Targeting labels, exclusive access, and approval requests';

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
                                    <span x-show="tag.is_system == 1"
                                          class="text-xs px-1.5 py-0.5 rounded bg-blue-50 text-blue-600 dark:bg-blue-900/30 dark:text-blue-300">System</span>
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
                                      :class="tag.is_active
                                          ? 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-400'
                                          : 'bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-500'"
                                      x-text="tag.is_active ? 'Active' : 'Inactive'"></span>
                            </td>
                            <td class="px-5 py-3 text-right">
                                <div class="flex items-center justify-end gap-3">
                                    <a :href="'/admin/tags/edit?id=' + tag.id"
                                       class="text-xs text-gray-400 hover:text-gray-600 dark:hover:text-gray-200">Edit</a>
                                    <button @click="deactivate(tag)"
                                            class="text-xs text-red-400 hover:text-red-600"
                                            x-show="tag.is_active && tag.is_system != 1">Deactivate</button>
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
</div>

<script>
function tagsPage() {
    return {
        tags: [], total: 0, loading: true,
        search: '', filterActive: '1', filterSystem: 'all',
        limit: 50, offset: 0,

        async init()     { await this.loadTags(); },
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
            if (!confirm(`Deactivate tag "${tag.name}"?`)) return;
            const res = await api.delete(`/tags/${tag.id}`);
            if (res.ok) {
                toast(`${tag.name} deactivated`);
                await this.loadTags();
            } else {
                toast(res.data.error || 'Failed', 'error');
            }
        }
    };
}
</script>
