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
            <div class="flex items-center gap-3">
                <select x-model="filterActive" @change="loadOrgs()"
                        class="px-3 py-1.5 text-sm rounded-lg border border-gray-200 dark:border-gray-700
                               bg-gray-50 dark:bg-gray-800 text-gray-700 dark:text-gray-300
                               focus:outline-none focus:ring-2 focus:ring-red-500">
                    <option value="1">Active</option>
                    <option value="0">Inactive</option>
                    <option value="all">All</option>
                </select>
                <input type="search" placeholder="Search…" x-model="search"
                       class="px-3 py-1.5 text-sm rounded-lg border border-gray-200 dark:border-gray-700
                              bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white
                              focus:outline-none focus:ring-2 focus:ring-red-500 w-48">
            </div>
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
                                      :class="isActive(org.is_active)
                                          ? 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-400'
                                          : 'bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-500'"
                                      x-text="isActive(org.is_active) ? 'Active' : 'Inactive'"></span>
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
        <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100 dark:border-gray-800 gap-3 flex-wrap">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">
                <span x-text="selectedOrg?.display_name"></span>
                <span class="text-gray-400 font-normal ml-1">— Org Tree</span>
            </h2>
            <div class="flex items-center gap-2 flex-wrap">
                <button type="button" @click="expandAll()" x-show="nodes.length > 0"
                        class="px-2.5 py-1.5 text-xs font-medium text-gray-500 hover:text-gray-800
                               dark:text-gray-400 dark:hover:text-gray-200 rounded-lg
                               border border-gray-200 dark:border-gray-700 transition-colors">
                    Expand all
                </button>
                <button type="button" @click="collapseAll()" x-show="nodes.length > 0"
                        class="px-2.5 py-1.5 text-xs font-medium text-gray-500 hover:text-gray-800
                               dark:text-gray-400 dark:hover:text-gray-200 rounded-lg
                               border border-gray-200 dark:border-gray-700 transition-colors">
                    Collapse all
                </button>
                <button @click="openAddNode()"
                        :disabled="nodesLoading || !selectedOrg"
                        class="flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold
                               bg-red-600 hover:bg-red-700 disabled:opacity-50 disabled:cursor-not-allowed
                               text-white rounded-lg transition-colors">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                    </svg>
                    Add Node
                </button>
            </div>
        </div>

        <!-- Tree -->
        <div class="p-5">
            <div x-show="nodesLoading" class="text-sm text-gray-400">Loading tree…</div>
            <div x-show="!nodesLoading && nodes.length === 0" class="text-sm text-gray-400">
                No nodes found. Click <strong>Add Node</strong> to create the first child under the org root.
            </div>
            <template x-if="!nodesLoading && nodes.length > 0">
                <div>
                    <template x-for="node in nodes" :key="node.id">
                        <div x-show="isNodeVisible(node)"
                             class="flex items-center gap-1.5 py-1.5 group rounded-lg transition-colors"
                             :class="editingNode?.id === node.id ? 'bg-red-50 dark:bg-red-950/20' : 'hover:bg-gray-50 dark:hover:bg-gray-800/40'"
                             :style="{ paddingLeft: (node.depth * 20 + 4) + 'px' }">
                            <!-- Expand / collapse -->
                            <button type="button" @click.stop="toggleCollapse(node.id)"
                                    x-show="hasChildren(node.id)"
                                    class="w-5 h-5 flex-shrink-0 flex items-center justify-center rounded
                                           text-gray-400 hover:text-gray-700 dark:hover:text-gray-200"
                                    :aria-expanded="!collapsed[node.id]">
                                <svg class="w-3.5 h-3.5 transition-transform duration-150"
                                     :class="collapsed[node.id] ? '' : 'rotate-90'"
                                     fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                                </svg>
                            </button>
                            <span class="w-5 flex-shrink-0" x-show="!hasChildren(node.id)"></span>
                            <!-- Node type badge -->
                            <span class="text-xs px-1.5 py-0.5 rounded font-mono uppercase
                                         bg-gray-100 dark:bg-gray-800 text-gray-500 dark:text-gray-400 flex-shrink-0"
                                  x-text="formatNodeType(node.node_type)"></span>
                            <!-- Name -->
                            <span class="text-sm text-gray-800 dark:text-gray-200 font-medium truncate" x-text="node.name"></span>
                            <!-- Member count -->
                            <span class="text-xs text-gray-400 flex-shrink-0" x-show="node.member_count > 0">
                                (<span x-text="node.member_count"></span>)
                            </span>
                            <!-- Actions (show on hover) -->
                            <div class="ml-auto flex items-center gap-2 opacity-0 group-hover:opacity-100 transition-opacity flex-shrink-0">
                                <button @click="openEditNode(node)"
                                        class="text-xs text-gray-400 hover:text-red-600 dark:hover:text-red-400 transition-colors">
                                    Edit
                                </button>
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
    </div>

    <!-- Add / Edit node modal -->
    <div x-show="nodeModal" x-cloak
         class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50"
         @keydown.escape.window="closeNodeModal()">
        <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-700
                    shadow-xl max-w-lg w-full max-h-[90vh] overflow-y-auto"
             @click.outside="closeNodeModal()">

            <!-- Add -->
            <template x-if="nodeModal === 'add'">
                <div>
                    <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100 dark:border-gray-800">
                        <h3 class="text-base font-semibold text-gray-900 dark:text-white">
                            Add Node
                            <span x-show="newNode.parentName" class="font-normal text-sm text-gray-400 block sm:inline sm:ml-1">
                                under <span x-text="newNode.parentName" class="text-gray-600 dark:text-gray-300"></span>
                            </span>
                        </h3>
                        <button type="button" @click="closeNodeModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>
                    </div>
                    <div class="p-6 space-y-4">
                        <div>
                            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Name</label>
                            <input type="text" x-model="newNode.name" placeholder="e.g. Engineering" x-ref="nodeModalName"
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
                                <template x-for="opt in nodeTypesForParent()" :key="opt.value">
                                    <option :value="opt.value" x-text="opt.label"></option>
                                </template>
                            </select>
                        </div>
                    </div>
                    <div class="flex justify-end gap-3 px-6 py-4 border-t border-gray-100 dark:border-gray-800">
                        <button type="button" @click="closeNodeModal()"
                                class="px-4 py-2 text-sm text-gray-600 dark:text-gray-400 hover:text-gray-900">Cancel</button>
                        <button type="button" @click="saveNode()" :disabled="!newNode.name"
                                class="px-4 py-2 text-sm font-semibold bg-red-600 hover:bg-red-700 disabled:opacity-50
                                       text-white rounded-xl">Add Node</button>
                    </div>
                </div>
            </template>

            <!-- Edit -->
            <template x-if="nodeModal === 'edit'">
                <div>
                    <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100 dark:border-gray-800">
                        <h3 class="text-base font-semibold text-gray-900 dark:text-white">
                            Edit Node
                            <span class="font-normal text-sm text-gray-400 block sm:inline sm:ml-1" x-text="editingNode?.name"></span>
                        </h3>
                        <button type="button" @click="closeNodeModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>
                    </div>
                    <div class="p-6 space-y-4">
                        <div>
                            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Name</label>
                            <input type="text" x-model="editForm.name" x-ref="nodeModalName"
                                   class="w-full px-3 py-2 text-sm rounded-lg border border-gray-200 dark:border-gray-700
                                          bg-white dark:bg-gray-800 text-gray-900 dark:text-white
                                          focus:outline-none focus:ring-2 focus:ring-red-500">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Type</label>
                            <select x-model="editForm.node_type"
                                    :disabled="editingNode?.node_type === 'org'"
                                    class="w-full px-3 py-2 text-sm rounded-lg border border-gray-200 dark:border-gray-700
                                           bg-white dark:bg-gray-800 text-gray-900 dark:text-white
                                           focus:outline-none focus:ring-2 focus:ring-red-500 disabled:opacity-60">
                                <template x-for="opt in nodeTypesForEdit()" :key="opt.value">
                                    <option :value="opt.value" x-text="opt.label"></option>
                                </template>
                            </select>
                        </div>
                        <div x-show="editForm.node_type !== 'org'">
                            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Parent node</label>
                            <select x-model="editForm.parent_id"
                                    class="w-full px-3 py-2 text-sm rounded-lg border border-gray-200 dark:border-gray-700
                                           bg-white dark:bg-gray-800 text-gray-900 dark:text-white
                                           focus:outline-none focus:ring-2 focus:ring-red-500">
                                <template x-for="p in validMoveParents()" :key="p.id">
                                    <option :value="p.id" x-text="p.label"></option>
                                </template>
                            </select>
                            <p class="text-xs text-gray-400 mt-1">Move this node under a different parent in the tree.</p>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Slug</label>
                            <div class="px-3 py-2 text-sm rounded-lg border border-gray-200 dark:border-gray-700
                                        bg-gray-100 dark:bg-gray-800/80 text-gray-500 font-mono"
                                 x-text="editingNode?.slug || '—'"></div>
                            <p class="text-xs text-gray-400 mt-1">Set at creation (used for CSV import).</p>
                        </div>
                    </div>
                    <div class="flex justify-end gap-3 px-6 py-4 border-t border-gray-100 dark:border-gray-800">
                        <button type="button" @click="closeNodeModal()"
                                class="px-4 py-2 text-sm text-gray-600 dark:text-gray-400 hover:text-gray-900">Cancel</button>
                        <button type="button" @click="saveEditNode()" :disabled="!editForm.name || editSaving"
                                class="px-4 py-2 text-sm font-semibold bg-red-600 hover:bg-red-700 disabled:opacity-50
                                       text-white rounded-xl">
                            <span x-text="editSaving ? 'Saving…' : 'Save Changes'"></span>
                        </button>
                    </div>
                </div>
            </template>
        </div>
    </div>

</div>

<script>
function orgsPage() {
    return {
        orgs: [], nodes: [], loading: true, nodesLoading: false,
        search: '', filterActive: '1', selectedOrg: null,
        nodeModal: null,
        collapsed: {},
        editingNode: null, editForm: { name: '', node_type: '', parent_id: null }, originalParentId: null,
        editSaving: false,
        newNode: { name: '', node_type: 'region', parent_id: null, parentName: '', parent_type: null },

        formatNodeType(type) {
            const labels = {
                org: 'Org',
                global_business_unit: 'Global BU',
                region: 'Region',
                market: 'Market',
                business_unit: 'Business Unit',
                site: 'Site',
                department: 'Dept',
                team: 'Team',
            };
            return labels[type] || type;
        },

        nodeTypesForParent() {
            return this.typesForParentType(this.newNode.parent_type);
        },

        nodeTypesForEdit() {
            if (this.editingNode?.node_type === 'org') {
                return [{ value: 'org', label: 'Organization (root)' }];
            }
            const parent = this.nodeById(this.editForm.parent_id) || this.orgRootNode();
            const opts = this.typesForParentType(parent?.node_type || 'org');
            if (opts.length && !opts.find(o => o.value === this.editForm.node_type)) {
                opts.unshift({
                    value: this.editForm.node_type,
                    label: this.formatNodeType(this.editForm.node_type) + ' (current)',
                });
            }
            return opts.length ? opts : [{
                value: this.editForm.node_type,
                label: this.formatNodeType(this.editForm.node_type),
            }];
        },

        nodeById(id) {
            return this.nodes.find(n => Number(n.id) === Number(id)) || null;
        },

        hasChildren(id) {
            return this.nodes.some(n => Number(n.parent_id) === Number(id));
        },

        isNodeVisible(node) {
            let pid = node.parent_id;
            while (pid != null && pid !== '') {
                if (this.collapsed[pid]) return false;
                const parent = this.nodeById(pid);
                pid = parent?.parent_id ?? null;
            }
            return true;
        },

        toggleCollapse(id) {
            if (this.collapsed[id]) {
                const next = { ...this.collapsed };
                delete next[id];
                this.collapsed = next;
            } else {
                this.collapsed = { ...this.collapsed, [id]: true };
            }
        },

        expandAll() {
            this.collapsed = {};
        },

        collapseAll() {
            const next = {};
            this.nodes.forEach(n => {
                if (this.hasChildren(n.id)) next[n.id] = true;
            });
            this.collapsed = next;
        },

        closeNodeModal() {
            this.nodeModal = null;
            this.editingNode = null;
            this.editForm = { name: '', node_type: '', parent_id: null };
            this.originalParentId = null;
            this.editSaving = false;
            this.newNode = { name: '', node_type: 'region', parent_id: null, parentName: '', parent_type: null };
        },

        focusNodeModalInput() {
            this.$nextTick(() => {
                this.$nextTick(() => this.$refs.nodeModalName?.focus());
            });
        },

        isDescendantOf(candidate, node) {
            if (!node?.path || !candidate?.path) return false;
            return Number(candidate.id) !== Number(node.id) && String(candidate.path).startsWith(String(node.path));
        },

        validMoveParents() {
            if (!this.editingNode || this.editForm.node_type === 'org') return [];
            const nodeType = this.editForm.node_type || this.editingNode.node_type;
            return this.nodes
                .filter(n => {
                    if (Number(n.id) === Number(this.editingNode.id)) return false;
                    if (this.isDescendantOf(n, this.editingNode)) return false;
                    if (Number(n.is_active) === 0) return false;
                    return this.isValidParentForNodeType(n, nodeType);
                })
                .map(n => ({
                    id: n.id,
                    label: `${n.name} (${this.formatNodeType(n.node_type)})`,
                }));
        },

        isValidParentForNodeType(parentNode, nodeType) {
            const pt = parentNode.node_type;
            const rules = {
                global_business_unit: ['org'],
                business_unit: ['market'],
                site: ['global_business_unit', 'region', 'market', 'business_unit'],
                department: ['site', 'global_business_unit', 'business_unit'],
                team: ['department', 'site', 'global_business_unit', 'business_unit'],
            };
            if (rules[nodeType]) {
                return rules[nodeType].includes(pt);
            }
            if (nodeType === 'region' || nodeType === 'market') {
                return pt === 'org' || pt === 'region';
            }
            return pt === 'org';
        },

        typesForParentType(parentType) {
            if (parentType === 'org') {
                return [
                    { value: 'global_business_unit', label: 'Global Business Unit' },
                    { value: 'region', label: 'Region' },
                    { value: 'market', label: 'Market' },
                ];
            }
            if (parentType === 'market') {
                return [
                    { value: 'business_unit', label: 'Business Unit' },
                    { value: 'site', label: 'Site' },
                ];
            }
            if (parentType === 'region') {
                return [
                    { value: 'market', label: 'Market' },
                    { value: 'site', label: 'Site' },
                ];
            }
            if (parentType === 'global_business_unit') {
                return [
                    { value: 'site', label: 'Site' },
                    { value: 'department', label: 'Department' },
                    { value: 'team', label: 'Team' },
                ];
            }
            if (parentType === 'business_unit' || parentType === 'site') {
                return [
                    { value: 'department', label: 'Department' },
                    { value: 'team', label: 'Team' },
                ];
            }
            if (parentType === 'department') {
                return [{ value: 'team', label: 'Team' }];
            }
            return [
                { value: 'region', label: 'Region' },
                { value: 'market', label: 'Market' },
                { value: 'site', label: 'Site' },
                { value: 'department', label: 'Department' },
                { value: 'team', label: 'Team' },
            ];
        },

        orgRootNode() {
            const byType = this.nodes.find(n => String(n.node_type) === 'org');
            if (byType) return byType;
            return this.nodes.find(n =>
                (n.parent_id === null || n.parent_id === undefined || n.parent_id === '')
                && Number(n.depth) === 0
            ) || null;
        },

        async openAddNode() {
            if (!this.selectedOrg) {
                toast('Select an organization first', 'error');
                return;
            }
            if (this.nodesLoading) {
                return;
            }
            await this.loadNodesForSelectedOrg();
            const root = this.orgRootNode();
            if (!root) {
                toast('Could not find the organization root node', 'error');
                return;
            }
            this.setAddNodeParent(root);
            this.nodeModal = 'add';
            this.focusNodeModalInput();
        },

        setAddNodeParent(parent) {
            const opts = this.typesForParentType(parent.node_type);
            this.newNode = {
                name: '',
                node_type: opts[0]?.value || 'department',
                parent_id: parent.id,
                parentName: parent.name,
                parent_type: parent.node_type,
            };
        },

        openEditNode(node) {
            this.closeNodeModal();
            this.editingNode = node;
            const parentId = node.parent_id != null && node.parent_id !== ''
                ? Number(node.parent_id)
                : (this.orgRootNode()?.id ?? null);
            this.editForm = {
                name: node.name,
                node_type: node.node_type,
                parent_id: parentId,
            };
            this.originalParentId = node.parent_id != null && node.parent_id !== ''
                ? Number(node.parent_id)
                : null;
            this.nodeModal = 'edit';
            this.focusNodeModalInput();
        },

        async saveEditNode() {
            if (!this.editingNode || !this.selectedOrg || !this.editForm.name) {
                toast('Name is required', 'error');
                return;
            }
            this.editSaving = true;
            const orgId = this.selectedOrg.id;
            const nodeId = this.editingNode.id;

            const updateRes = await api.put(`/orgs/${orgId}/nodes/${nodeId}`, {
                name: this.editForm.name,
                node_type: this.editForm.node_type,
            });
            if (!updateRes.ok) {
                this.editSaving = false;
                const err = updateRes.data?.errors
                    ? Object.values(updateRes.data.errors).join(' ')
                    : (updateRes.data?.error || 'Failed to update node');
                toast(err, 'error');
                return;
            }

            const newParentId = this.editForm.parent_id != null ? Number(this.editForm.parent_id) : null;
            const oldParentId = this.originalParentId != null ? Number(this.originalParentId) : null;
            if (this.editingNode.node_type !== 'org' && this.editForm.node_type !== 'org' && newParentId !== oldParentId) {
                const moveRes = await api.put(`/orgs/${orgId}/nodes/${nodeId}/move`, {
                    parent_id: newParentId,
                });
                if (!moveRes.ok) {
                    this.editSaving = false;
                    const err = moveRes.data?.errors
                        ? Object.values(moveRes.data.errors).join(' ')
                        : (moveRes.data?.error || 'Node updated but move failed');
                    toast(err, 'error');
                    await this.loadNodesForSelectedOrg();
                    return;
                }
            }

            this.editSaving = false;
            toast('Node updated');
            this.closeNodeModal();
            await this.loadNodesForSelectedOrg();
            await this.loadOrgs();
        },

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
            await this.loadOrgs();
        },

        async loadOrgs() {
            this.loading = true;
            const params = new URLSearchParams({ limit: '200', active: this.filterActive });
            const res = await api.get('/orgs?' + params);
            if (res.ok) {
                this.orgs = res.data.data.orgs;
                if (this.selectedOrg) {
                    const updated = this.orgs.find(o => o.id === this.selectedOrg.id);
                    if (updated) this.selectedOrg = updated;
                }
            } else {
                toast(res.data?.error || res.data?.message || 'Failed to load organizations', 'error');
            }
            this.loading = false;
        },

        async selectOrg(org) {
            this.selectedOrg = org;
            this.closeNodeModal();
            this.collapsed = {};
            await this.loadNodesForSelectedOrg();
        },

        async loadNodesForSelectedOrg() {
            if (!this.selectedOrg) {
                return;
            }
            this.nodesLoading = true;
            this.nodes = [];

            const res = await api.get(`/orgs/${this.selectedOrg.id}/nodes`);
            if (res.ok && res.data?.data?.nodes) {
                this.nodes = res.data.data.nodes;
            } else if (!res.ok) {
                toast(res.data?.error || 'Failed to load org tree', 'error');
            }

            if (this.nodes.length === 0) {
                const orgRes = await api.get(`/orgs/${this.selectedOrg.id}`);
                if (orgRes.ok && orgRes.data?.data?.nodes?.length) {
                    this.nodes = orgRes.data.data.nodes;
                }
            }

            this.nodesLoading = false;
            this.collapsed = {};
        },

        addChildNode(parent) {
            this.closeNodeModal();
            this.setAddNodeParent(parent);
            this.nodeModal = 'add';
            this.focusNodeModalInput();
        },

        async saveNode() {
            if (!this.newNode.name || !this.selectedOrg || !this.newNode.parent_id) {
                toast('Name and parent node are required', 'error');
                return;
            }
            const body = { name: this.newNode.name, node_type: this.newNode.node_type, parent_id: this.newNode.parent_id };

            const res = await api.post(`/orgs/${this.selectedOrg.id}/nodes`, body);
            if (res.ok) {
                toast('Node added');
                this.closeNodeModal();
                await this.selectOrg(this.selectedOrg);
                await this.loadOrgs();
            } else {
                const err = res.data.errors
                    ? Object.values(res.data.errors).join(' ')
                    : (res.data.error || 'Failed to add node');
                toast(err, 'error');
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
                this.selectedOrg = null;
                await this.loadOrgs();
            } else {
                toast(res.data.error || 'Failed to deactivate', 'error');
            }
        }
    };
}
</script>
