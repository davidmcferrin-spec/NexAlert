<?php
// web/templates/pages/users/index.php
$pageTitle    = 'Users';
$pageSubtitle = 'Manage staff accounts and contact information';

$headerActions = '
<a href="/admin/users/import"
   class="flex items-center gap-2 px-4 py-2 text-sm font-semibold rounded-xl border border-gray-200 dark:border-gray-700
          text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors">
    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
    </svg>
    Import CSV
</a>
<a href="/admin/users/new"
   class="flex items-center gap-2 px-4 py-2 bg-red-600 hover:bg-red-700 text-white text-sm font-semibold rounded-xl transition-colors">
    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
    </svg>
    Add User
</a>';
?>

<div x-data="usersPage()" x-init="init()">

    <!-- Filters -->
    <div class="flex flex-wrap items-center gap-3 mb-4">
        <input type="search" placeholder="Search name, username…" x-model.debounce.300ms="search"
               @input="loadUsers()"
               class="px-3 py-2 text-sm rounded-xl border border-gray-200 dark:border-gray-700
                      bg-white dark:bg-gray-800 text-gray-900 dark:text-white
                      focus:outline-none focus:ring-2 focus:ring-red-500 w-64">

        <select x-model="filterActive" @change="loadUsers()"
                class="px-3 py-2 text-sm rounded-xl border border-gray-200 dark:border-gray-700
                       bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300
                       focus:outline-none focus:ring-2 focus:ring-red-500">
            <option value="1">Active</option>
            <option value="0">Inactive</option>
            <option value="all">All</option>
        </select>

        <div class="ml-auto text-sm text-gray-400" x-text="total + ' users'"></div>
    </div>

    <!-- Table -->
    <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 overflow-hidden">
        <div x-show="loading" class="p-8 text-center text-gray-400 text-sm">Loading…</div>
        <div x-show="!loading">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 dark:bg-gray-800/50">
                    <tr>
                        <th class="text-left px-5 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Name</th>
                        <th class="text-left px-5 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider hidden md:table-cell">Username</th>
                        <th class="text-left px-5 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider hidden lg:table-cell">Org</th>
                        <th class="text-left px-5 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider hidden lg:table-cell">Node</th>
                        <th class="text-center px-5 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Status</th>
                        <th class="px-5 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    <template x-for="user in users" :key="user.id">
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/40 transition-colors">
                            <td class="px-5 py-3">
                                <a :href="'/admin/users/edit?id=' + user.id"
                                   class="font-medium text-gray-900 dark:text-white hover:text-red-600 dark:hover:text-red-400"
                                   x-text="user.display_name"></a>
                            </td>
                            <td class="px-5 py-3 hidden md:table-cell">
                                <code class="text-xs text-gray-500 dark:text-gray-400 font-mono" x-text="user.username"></code>
                            </td>
                            <td class="px-5 py-3 text-gray-500 dark:text-gray-400 hidden lg:table-cell" x-text="user.home_org_name"></td>
                            <td class="px-5 py-3 text-gray-500 dark:text-gray-400 hidden lg:table-cell" x-text="user.home_node_name || '—'"></td>
                            <td class="px-5 py-3 text-center">
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium"
                                      :class="!isActive(user.is_active)
                                          ? 'bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-500'
                                          : (isLocked(user.is_locked)
                                              ? 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/40 dark:text-yellow-400'
                                              : 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-400')"
                                      x-text="!isActive(user.is_active) ? 'Inactive' : (isLocked(user.is_locked) ? 'Locked' : 'Active')">
                                </span>
                            </td>
                            <td class="px-5 py-3 text-right">
                                <div class="flex items-center justify-end gap-3">
                                    <a :href="'/admin/users/edit?id=' + user.id"
                                       class="text-xs text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 transition-colors">Edit</a>
                                    <button @click="deactivate(user)"
                                            class="text-xs text-red-400 hover:text-red-600 transition-colors"
                                            x-show="isActive(user.is_active) && user.id !== <?= (int)($_SESSION['user']['id'] ?? 0) ?>">
                                        Deactivate
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </template>
                    <tr x-show="!loading && users.length === 0">
                        <td colspan="6" class="px-5 py-8 text-center text-sm text-gray-400">
                            No users found.
                            <a href="/admin/users/new" class="text-red-600 hover:underline ml-1">Add one</a>
                        </td>
                    </tr>
                </tbody>
            </table>

            <!-- Pagination -->
            <div class="flex items-center justify-between px-5 py-3 border-t border-gray-100 dark:border-gray-800"
                 x-show="total > limit">
                <button @click="prevPage()" :disabled="offset === 0"
                        class="text-sm text-gray-500 hover:text-gray-700 dark:hover:text-gray-300 disabled:opacity-40 transition-colors">
                    ← Previous
                </button>
                <span class="text-xs text-gray-400">
                    <span x-text="offset + 1"></span>–<span x-text="Math.min(offset + limit, total)"></span>
                    of <span x-text="total"></span>
                </span>
                <button @click="nextPage()" :disabled="offset + limit >= total"
                        class="text-sm text-gray-500 hover:text-gray-700 dark:hover:text-gray-300 disabled:opacity-40 transition-colors">
                    Next →
                </button>
            </div>
        </div>
    </div>
</div>

<script>
function usersPage() {
    return {
        users: [], total: 0, loading: true,
        search: '', filterActive: '1',
        limit: 50, offset: 0,

        async init()     { await this.loadUsers(); },
        async prevPage() { this.offset = Math.max(0, this.offset - this.limit); await this.loadUsers(); },
        async nextPage() { this.offset += this.limit; await this.loadUsers(); },

        async loadUsers() {
            this.loading = true;
            const params = new URLSearchParams({
                limit:  this.limit,
                offset: this.offset,
                active: this.filterActive,
            });
            if (this.search) params.set('search', this.search);

            const res = await api.get('/users?' + params);
            if (res.ok) {
                this.users = res.data.data.users;
                this.total = res.data.data.total;
            } else {
                toast(res.data?.error || res.data?.message || 'Failed to load users', 'error');
            }
            this.loading = false;
        },

        async deactivate(user) {
            if (!confirm(`Deactivate ${user.display_name}?`)) return;
            const res = await api.delete(`/users/${user.id}`);
            if (res.ok) {
                toast(`${user.display_name} deactivated`);
                await this.loadUsers();
            } else {
                toast(res.data.error || 'Failed', 'error');
            }
        }
    };
}
</script>
