<?php
// web/templates/pages/orgs/index.php
$pageTitle    = 'Organizations';
$pageSubtitle = 'Manage org tree, sites, and departments';
$token        = $_SESSION['access_token'];

$headerActions = '
<a href="/admin/orgs/new"
   class="flex items-center gap-2 px-4 py-2 bg-red-600 hover:bg-red-700 text-white text-sm font-semibold rounded-xl transition-colors">
    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
    </svg>
    New Organization
</a>';
?>

<div x-data="orgsPage()" x-init="init()">

    <!-- Org list -->
    <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 overflow-hidden mb-6">
        <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100 dark:border-gray-800">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Organizations</h2>
            <input type="search" placeholder="Search…" x-model="search"
                   class="px-3 py-1.5 text-sm rounded-lg border border-gray-200 dark:border-gray-700
                          bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white
                          focus:outline-none focus:ring-2 focus:ring-red-500 w-48">
        </div>

        <div x-show="loading" class="p-8 text-center text-gray-400 text-sm">Loading…</div>

        <div x-show="!loading">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 dark:bg-gray-800/50">
                    <tr>
                        <th class="text-left px-5 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Organization</th>
                        <th class="text-left px-5 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider hidden md:table-cell">Slug</th>
                        <th class="text-center px-5 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider hidden lg:table-cell">Users</th>
                        <th class="text-center px-5 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider hidden lg:table-cell">Nodes</th>
                        <th class="text-center px-5 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Status</th>
                        <th class="px-5 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    <template x-for="org in filtered" :key="org.id">
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/40 transition-colors">
                            <td class="px-5 py-3">
                                <button @click="selectOrg(org)"
                                        class="font-medium text-gray-900 dark:text-white hover:text-red-600 dark:hover:text-red-400 text-left"
                                        x-text="org.display_name"></button>
                            </td>
                            <td class="px-5 py-3 hidden md:table-cell">
                                <code class="text-xs text-gray-500 dark:text-gray-400 font-mono" x-text="org.slug"></code>
                            </td>
                            <td class="px-5 py-3 text-center text-gray-500 dark:text-gray-400 hidden lg:table-cell" x-text="org.user_count"></td>
                            <td class="px-5 py-3 text-center text-gray-500 dark:text-gray-400 hidden lg:table-cell" x-text="org.node_count"></td>
                            <td class="px-5 py-3 text-center">
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium"
                                      :class="org.is_active ? 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-400' : 'bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-500'"
                                      x-text="org.is_active ? 'Active' : 'Inactive'"></span>
                            </td>
                            <td class="px-5 py-3 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    <a :href="'/admin/orgs/edit?id=' + org.id"
                                       class="text-xs text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 transition-colors">Edit</a>
                                    <button @click="deleteOrg(org)"
                                            class="text-xs text-red-400 hover:text-red-600 transition-colors">Deactivate</button>
                                </div>
                            </td>
                        </tr>
                    </template>
                    <tr x-show="!loading && filtered.length === 0">
                        <td colspan="6" class="px-5 py-8 text-center text-sm text-gray-400">
                            No organizations found.
                            <a href="/admin/orgs/new" class="text-red-600 hover:underline ml-1">Create one</a>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Org tree panel (shown when org is selected) -->
    <div x-show="selectedOrg" x-cloak class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 overflow-hidden">
        <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100 dark:border-gray-800">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">
                <span x-text="selectedOrg?.display_name"></span>
                <span class="text-gray-400 font-normal ml-1">— Org Tree</span>
            </h2>
            <button @click="showAddNode = true"
                    class="flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold
                           bg-red-600 hover:bg-red-700 text-white rounded-lg transition-colors">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                </svg>
                Add Node
            </button>
        </div>

        <!-- Tree -->
        <div class="p-5">
            <div x-show="nodesLoading" class="text-sm text-gray-400">Loading tree…</div>
            <div x-show="!nodesLoading && nodes.length === 0" class="text-sm text-gray-400">
                No nodes yet. Add a root node to start building the org tree.
            </div>
            <template x-if="!nodesLoading && nodes.length > 0">
                <div>
                    <template x-for="node in nodes" :key="node.id">
                        <div class="flex items-center gap-2 py-1.5 group"
                             :style="{ paddingLeft: (node.depth * 20 + 4) + 'px' }">
                            <!-- Tree line indicator -->
                            <svg class="w-4 h-4 text-gray-300 dark:text-gray-700 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                            </svg>
                            <!-- Node type badge -->
                            <span class="text-xs px-1.5 py-0.5 rounded font-mono uppercase
                                         bg-gray-100 dark:bg-gray-800 text-gray-500 dark:text-gray-400 flex-shrink-0"
                                  x-text="node.node_type"></span>
                            <!-- Name -->
                            <span class="text-sm text-gray-800 dark:text-gray-200 font-medium" x-text="node.name"></span>
                            <!-- Member count -->
                            <span class="text-xs text-gray-400" x-show="node.member_count > 0">
                                (<span x-text="node.member_count"></span>)
                            </span>
                            <!-- Actions (show on hover) -->
                            <div class="ml-auto flex items-center gap-2 opacity-0 group-hover:opacity-100 transition-opacity">
                                <button @click="addChildNode(node)"
                                        class="text-xs text-gray-400 hover:text-red-600 dark:hover:text-red-400 transition-colors">
                                    + Child
                                </button>
                                <button @click="deleteNode(node)"
                                        class="text-xs text-gray-400 hover:text-red-600 dark:hover:text-red-400 transition-colors"
                                        x-show="node.node_type !== 'org'">
                                    Remove
                                </button>
                            </div>
                        </div>
                    </template>
                </div>
            </template>
        </div>

        <!-- Add node form (inline) -->
        <div x-show="showAddNode" x-cloak
             class="border-t border-gray-100 dark:border-gray-800 p-5 bg-gray-50 dark:bg-gray-800/40">
            <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-4">
                Add Node
                <span x-show="newNode.parentName" class="font-normal text-gray-400">
                    under <span x-text="newNode.parentName" class="text-gray-600 dark:text-gray-300"></span>
                </span>
            </h3>
            <div class="grid grid-cols-2 gap-4 mb-4">
                <div>
                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Name</label>
                    <input type="text" x-model="newNode.name" placeholder="e.g. Engineering"
                           class="w-full px-3 py-2 text-sm rounded-lg border border-gray-200 dark:border-gray-700
                                  bg-white dark:bg-gray-800 text-gray-900 dark:text-white
                                  focus:outline-none focus:ring-2 focus:ring-red-500">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Type</label>
                    <select x-model="newNode.node_type"
                            class="w-full px-3 py-2 text-sm rounded-lg border border-gray-200 dark:border-gray-700
                                   bg-white dark:bg-gray-800 text-gray-900 dark:text-white
                                   focus:outline-none focus:ring-2 focus:ring-red-500">
                        <option value="region">Region</option>
                        <option value="market">Market</option>
                        <option value="site">Site</option>
                        <option value="department">Department</option>
                        <option value="team">Team</option>
                    </select>
                </div>
            </div>
            <div class="flex gap-2">
                <button @click="saveNode()"
                        :disabled="!newNode.name"
                        class="px-4 py-2 text-sm font-semibold bg-red-600 hover:bg-red-700
                               disabled:opacity-50 disabled:cursor-not-allowed
                               text-white rounded-lg transition-colors">
                    Add Node
                </button>
                <button @click="showAddNode = false; newNode = { name: '', node_type: 'department', parent_id: null, parentName: '' }"
                        class="px-4 py-2 text-sm text-gray-500 hover:text-gray-700 dark:hover:text-gray-300 transition-colors">
                    Cancel
                </button>
            </div>
        </div>
    </div>

</div>

<script>
function orgsPage() {
    return {
        orgs: [], nodes: [], loading: true, nodesLoading: false,
        search: '', selectedOrg: null, showAddNode: false,
        newNode: { name: '', node_type: 'department', parent_id: null, parentName: '' },

        get filtered() {
            if (!this.search) return this.orgs;
            const q = this.search.toLowerCase();
            return this.orgs.filter(o =>
                o.name.toLowerCase().includes(q) ||
                o.slug.toLowerCase().includes(q) ||
                o.display_name.toLowerCase().includes(q)
            );
        },

        async init() {
            const res = await api.get('/orgs?limit=200');
            if (res.ok) this.orgs = res.data.data.orgs;
            this.loading = false;
        },

        async selectOrg(org) {
            this.selectedOrg = org;
            this.showAddNode = false;
            this.nodesLoading = true;
            const res = await api.get(`/orgs/${org.id}/nodes`);
            if (res.ok) this.nodes = res.data.data.nodes;
            this.nodesLoading = false;
        },

        addChildNode(parent) {
            this.newNode = { name: '', node_type: 'department', parent_id: parent.id, parentName: parent.name };
            this.showAddNode = true;
        },

        async saveNode() {
            if (!this.newNode.name || !this.selectedOrg) return;
            const body = { name: this.newNode.name, node_type: this.newNode.node_type };
            if (this.newNode.parent_id) body.parent_id = this.newNode.parent_id;

            const res = await api.post(`/orgs/${this.selectedOrg.id}/nodes`, body);
            if (res.ok) {
                toast('Node added');
                this.showAddNode = false;
                this.newNode = { name: '', node_type: 'department', parent_id: null, parentName: '' };
                await this.selectOrg(this.selectedOrg);
                // Refresh org list for node count
                const orgsRes = await api.get('/orgs?limit=200');
                if (orgsRes.ok) this.orgs = orgsRes.data.data.orgs;
            } else {
                toast(res.data.error || 'Failed to add node', 'error');
            }
        },

        async deleteNode(node) {
            if (!confirm(`Remove node "${node.name}"? This cannot be undone.`)) return;
            const res = await api.delete(`/orgs/${this.selectedOrg.id}/nodes/${node.id}`);
            if (res.ok) {
                toast('Node removed');
                await this.selectOrg(this.selectedOrg);
            } else {
                toast(res.data.error || 'Failed to remove node', 'error');
            }
        },

        async deleteOrg(org) {
            if (!confirm(`Deactivate "${org.display_name}"?`)) return;
            const res = await api.delete(`/orgs/${org.id}`);
            if (res.ok) {
                toast('Organization deactivated');
                await this.init();
            } else {
                toast(res.data.error || 'Failed to deactivate', 'error');
            }
        }
    };
}
</script>
