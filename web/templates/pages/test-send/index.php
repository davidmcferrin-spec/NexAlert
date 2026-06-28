<?php
$pageTitle    = 'Test Send';
$pageSubtitle = 'Preview alert recipients and build nested AND/OR target expressions';
?>

<div x-data="testSendPage()" x-init="init()" class="space-y-6">

    <div class="rounded-xl border border-blue-200 dark:border-blue-900/50 bg-blue-50 dark:bg-blue-950/30 px-4 py-3 text-sm text-blue-800 dark:text-blue-200">
        Build nested <strong>AND</strong> / <strong>OR</strong> groups with multiple tags, nodes, groups, and users.
        Example: <code class="font-mono text-xs">org:nexstar AND (tag:eng OR tag:noc OR group:on-call@nexstar)</code>.
        Preview uses the same resolver as live alert sends.
    </div>

    <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">

        <!-- Nested tree builder -->
        <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-800 flex items-center justify-between gap-2 flex-wrap">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Target Builder</h2>
                <div class="flex items-center gap-2">
                    <button @click="addOrBranch()"
                            class="text-xs font-medium text-red-600 hover:text-red-700 dark:text-red-400">+ OR branch</button>
                    <button @click="resetTree()"
                            class="text-xs text-gray-400 hover:text-gray-600">Reset</button>
                </div>
            </div>
            <div class="p-5">
                <div class="rounded-xl border-2 border-red-200 dark:border-red-900/40 p-4 space-y-2 bg-red-50/30 dark:bg-red-950/10">
                    <div class="flex items-center justify-between gap-2 flex-wrap mb-2">
                        <span class="text-xs font-bold uppercase tracking-wider text-red-600 dark:text-red-400">Root — OR</span>
                        <span class="text-xs text-gray-400">Union of branches below</span>
                    </div>

                    <template x-for="(branch, branchIdx) in targetTree.children" :key="'b'+branchIdx">
                        <div class="space-y-2">
                            <div x-show="branchIdx > 0"
                                 class="flex items-center gap-2 text-[10px] font-bold uppercase text-red-500 tracking-wider py-1">
                                <span class="flex-1 border-t border-red-200 dark:border-red-800"></span>OR<span class="flex-1 border-t border-red-200 dark:border-red-800"></span>
                            </div>
                            <div x-data="{ branchPath: 'root.' + branchIdx }">
                                <!-- AND branch panel -->
                                <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-3 space-y-2">
                                    <div class="flex items-center justify-between gap-2">
                                        <span class="text-[10px] font-bold uppercase tracking-wider text-amber-600 dark:text-amber-400">AND branch</span>
                                        <button x-show="$root.targetTree.children.length > 1"
                                                @click="$root.removeBranch(branchPath)"
                                                class="text-xs text-gray-400 hover:text-red-500">Remove branch</button>
                                    </div>

                                    <!-- Children of AND branch (terms + OR subgroups) -->
                                    <template x-for="(child, ci) in $root.nodeAt(branchPath).children" :key="branchPath + '.' + ci">
                                        <div>
                                            <!-- OR subgroup -->
                                            <template x-if="child.type === 'group'">
                                                <div class="rounded-lg border border-dashed border-amber-300 dark:border-amber-800 p-2 space-y-2 ml-2">
                                                    <div class="flex items-center justify-between gap-2">
                                                        <span class="text-[10px] font-bold uppercase text-amber-500">OR subgroup</span>
                                                        <button @click="$root.removeChild(branchPath + '.' + ci)"
                                                                class="text-xs text-gray-400 hover:text-red-500">&times;</button>
                                                    </div>
                                                    <div class="flex flex-wrap items-center gap-1.5 min-h-[1.5rem]">
                                                        <template x-for="(sub, si) in child.children" :key="branchPath + '.' + ci + '.' + si">
                                                            <span class="inline-flex items-center gap-1">
                                                                <span x-show="si > 0" class="text-[9px] font-bold text-red-500 uppercase">or</span>
                                                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-xs font-mono
                                                                             bg-gray-100 dark:bg-gray-800 text-gray-800 dark:text-gray-200">
                                                                    <span x-text="sub.dim + ':' + (sub.label || sub.value)"></span>
                                                                    <button @click="$root.removeChild(branchPath + '.' + ci + '.' + si)"
                                                                            class="text-gray-400 hover:text-red-500">&times;</button>
                                                                </span>
                                                            </span>
                                                        </template>
                                                        <span x-show="child.children.length === 0" class="text-xs text-gray-400 italic">Empty — add terms</span>
                                                    </div>
                                                    <div class="flex flex-wrap gap-1">
                                                        <template x-for="t in $root.dimensionTypes" :key="t">
                                                            <button @click="$root.openPicker(branchPath + '.' + ci, t)"
                                                                    class="px-2 py-0.5 text-[10px] rounded border border-dashed border-gray-300
                                                                           dark:border-gray-600 text-gray-500 hover:border-red-400 hover:text-red-600"
                                                                    x-text="'+ ' + t"></button>
                                                        </template>
                                                    </div>
                                                </div>
                                            </template>
                                            <!-- Direct AND term -->
                                            <template x-if="child.type === 'term'">
                                                <div class="flex items-center gap-2 ml-2">
                                                    <span x-show="ci > 0" class="text-[9px] font-bold text-amber-600 uppercase">and</span>
                                                    <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg text-xs font-mono
                                                                 bg-gray-100 dark:bg-gray-800 text-gray-800 dark:text-gray-200">
                                                        <span x-text="child.dim + ':' + (child.label || child.value)"></span>
                                                        <button @click="$root.removeChild(branchPath + '.' + ci)"
                                                                class="text-gray-400 hover:text-red-500">&times;</button>
                                                    </span>
                                                </div>
                                            </template>
                                        </div>
                                    </template>

                                    <div x-show="$root.nodeAt(branchPath).children.length === 0"
                                         class="text-xs text-gray-400 italic ml-2">No conditions — add a term or OR subgroup</div>

                                    <div class="flex flex-wrap gap-1.5 pt-1 border-t border-gray-100 dark:border-gray-800">
                                        <template x-for="t in $root.dimensionTypes" :key="'d'+t">
                                            <button @click="$root.openPicker(branchPath, t)"
                                                    class="px-2 py-0.5 text-[10px] rounded border border-dashed border-gray-300
                                                           dark:border-gray-600 text-gray-500 hover:border-red-400 hover:text-red-600"
                                                    x-text="'+ ' + t"></button>
                                        </template>
                                        <button @click="$root.addOrSubgroup(branchPath)"
                                                class="px-2 py-0.5 text-[10px] rounded border border-dashed border-amber-400
                                                       text-amber-600 hover:bg-amber-50 dark:hover:bg-amber-950/30">
                                            + OR subgroup
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>

                <p class="text-xs text-gray-400 mt-4">
                    <code class="font-mono">org</code> = home org ·
                    <code class="font-mono">node</code> = membership subtree ·
                    <code class="font-mono">tag</code> / <code class="font-mono">group</code> / <code class="font-mono">user</code>
                    · Use OR subgroups for multiple tags/groups/users under one AND branch
                </p>
            </div>
        </div>

        <!-- Expression + REST -->
        <div class="space-y-6">
            <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 overflow-hidden">
                <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-800">
                    <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Canonical Expression</h2>
                </div>
                <div class="p-5 space-y-3">
                    <textarea x-model="expression" rows="4"
                              placeholder="(org:nexstar AND (tag:engineering OR tag:noc)) OR group:on-call@nexstar"
                              class="w-full px-3 py-2 text-sm font-mono rounded-xl border border-gray-200 dark:border-gray-700
                                     bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-red-500"></textarea>
                    <div class="flex flex-wrap gap-2">
                        <button @click="syncFromBuilder()"
                                class="px-3 py-1.5 text-xs font-medium rounded-lg bg-gray-100 dark:bg-gray-800 hover:bg-gray-200">
                            Builder → String
                        </button>
                        <button @click="syncFromExpression()"
                                class="px-3 py-1.5 text-xs font-medium rounded-lg bg-gray-100 dark:bg-gray-800 hover:bg-gray-200">
                            String → Builder
                        </button>
                        <button @click="copyExpression()"
                                class="px-3 py-1.5 text-xs font-medium rounded-lg bg-gray-100 dark:bg-gray-800 hover:bg-gray-200">
                            Copy
                        </button>
                        <button @click="runPreview()"
                                class="ml-auto px-4 py-1.5 text-xs font-semibold rounded-lg bg-red-600 hover:bg-red-700 text-white">
                            Preview Recipients
                        </button>
                        <a x-show="preview.valid && expression"
                           href="/admin/alerts/new"
                           @click="sessionStorage.setItem('nexalert_target_expression', expression)"
                           class="px-3 py-1.5 text-xs font-semibold rounded-lg border border-red-600 text-red-600 hover:bg-red-50 dark:hover:bg-red-950">
                            Send Alert →
                        </a>
                    </div>
                    <div x-show="preview.row_count != null" class="text-xs text-gray-500">
                        Resolves to <strong x-text="preview.row_count"></strong> alert target row(s) after DNF expansion
                    </div>
                    <div x-show="parseErrors.length" class="text-xs text-red-600 dark:text-red-400 space-y-1">
                        <template x-for="(err, i) in parseErrors" :key="i">
                            <p x-text="err"></p>
                        </template>
                    </div>
                </div>
            </div>

            <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 overflow-hidden"
                 x-show="restSnippet">
                <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-800 flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-gray-900 dark:text-white">REST API Payload</h2>
                    <button @click="copyRest()" class="text-xs text-gray-400 hover:text-gray-600">Copy JSON</button>
                </div>
                <pre class="p-5 text-xs font-mono text-gray-700 dark:text-gray-300 overflow-x-auto whitespace-pre-wrap"
                     x-text="restSnippet"></pre>
            </div>
        </div>
    </div>

    <!-- Preview results -->
    <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 overflow-hidden">
        <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-800 flex flex-wrap items-center gap-4">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Recipient Preview</h2>
            <div x-show="preview.valid" class="flex flex-wrap gap-3 text-xs text-gray-500">
                <span><strong x-text="preview.counts?.total_unique ?? 0"></strong> unique recipients</span>
                <span x-show="preview.counts?.sms_eligible != null">
                    <strong x-text="preview.counts.sms_eligible"></strong> SMS-eligible
                </span>
                <template x-for="rt in preview.counts?.row_totals ?? []" :key="rt.row">
                    <span x-text="'Row ' + rt.row + ': ' + rt.count"></span>
                </template>
            </div>
            <span x-show="loading" class="text-xs text-gray-400 ml-auto">Resolving…</span>
        </div>

        <div x-show="preview.warnings?.length" class="px-5 py-2 bg-amber-50 dark:bg-amber-950/30 text-xs text-amber-800 dark:text-amber-200">
            <template x-for="(w, i) in preview.warnings" :key="i"><span x-text="w"></span></template>
        </div>

        <div x-show="!loading && preview.valid === false && preview.errors?.length"
             class="px-5 py-8 text-center text-sm text-red-500">
            <template x-for="(e, i) in preview.errors" :key="i">
                <p x-text="e"></p>
            </template>
        </div>

        <div x-show="preview.valid && preview.users?.length === 0 && !loading"
             class="px-5 py-8 text-center text-sm text-gray-400">
            No active users match this expression.
        </div>

        <div x-show="preview.valid && preview.users?.length > 0" class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 dark:bg-gray-800/50">
                    <tr>
                        <th class="text-left px-5 py-3 text-xs font-semibold text-gray-500 uppercase">Name</th>
                        <th class="text-left px-5 py-3 text-xs font-semibold text-gray-500 uppercase hidden md:table-cell">Username</th>
                        <th class="text-left px-5 py-3 text-xs font-semibold text-gray-500 uppercase hidden lg:table-cell">Home org</th>
                        <th class="text-left px-5 py-3 text-xs font-semibold text-gray-500 uppercase">Matched by</th>
                        <th class="text-center px-5 py-3 text-xs font-semibold text-gray-500 uppercase">SMS</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    <template x-for="u in preview.users" :key="u.id">
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/40">
                            <td class="px-5 py-3 font-medium text-gray-900 dark:text-white" x-text="u.display_name"></td>
                            <td class="px-5 py-3 hidden md:table-cell">
                                <code class="text-xs text-gray-500 font-mono" x-text="u.username"></code>
                            </td>
                            <td class="px-5 py-3 text-gray-500 hidden lg:table-cell" x-text="u.home_org_name || '—'"></td>
                            <td class="px-5 py-3 text-xs text-gray-500" x-text="(u.matched_by || []).join('; ')"></td>
                            <td class="px-5 py-3 text-center">
                                <span x-show="u.sms_eligible == 1" class="text-green-600">✓</span>
                                <span x-show="u.sms_eligible != 1" class="text-gray-300">—</span>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Entity picker modal -->
    <div x-show="picker.open" x-cloak
         class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50"
         @keydown.escape.window="picker.open = false">
        <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-700
                    shadow-xl max-w-lg w-full max-h-[80vh] flex flex-col"
             @click.outside="picker.open = false">
            <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-800">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white"
                    x-text="'Select ' + picker.type"></h3>
                <input type="search" x-model.debounce.300ms="picker.query" @input="searchEntities()"
                       placeholder="Search…" autofocus
                       class="mt-3 w-full px-3 py-2 text-sm rounded-xl border border-gray-200 dark:border-gray-700
                              bg-white dark:bg-gray-800 focus:ring-2 focus:ring-red-500">
            </div>
            <ul class="overflow-y-auto flex-1 divide-y divide-gray-100 dark:divide-gray-800">
                <template x-for="item in pickerResults" :key="item._key">
                    <li>
                        <button type="button" @click="selectEntity(item)"
                                class="w-full text-left px-5 py-3 hover:bg-gray-50 dark:hover:bg-gray-800">
                            <div class="text-sm font-medium text-gray-900 dark:text-white" x-text="item._label"></div>
                            <div class="text-xs text-gray-400 font-mono mt-0.5" x-text="item._expr"></div>
                            <div x-show="item._sub" class="text-xs text-gray-500 mt-0.5" x-text="item._sub"></div>
                        </button>
                    </li>
                </template>
                <li x-show="!picker.loading && pickerResults.length === 0"
                    class="px-5 py-8 text-center text-sm text-gray-400">No matches</li>
                <li x-show="picker.loading" class="px-5 py-8 text-center text-sm text-gray-400">Loading…</li>
            </ul>
            <div class="px-5 py-3 border-t border-gray-100 dark:border-gray-800 text-right">
                <button @click="picker.open = false" class="text-sm text-gray-500 hover:text-gray-700">Cancel</button>
            </div>
        </div>
    </div>
</div>

<script>
function testSendPage() {
    return {
        targetTree: defaultTargetTree(),
        expression: '',
        parseErrors: [],
        dimensionTypes: ['org', 'node', 'tag', 'group', 'user'],
        loading: false,
        preview: {},
        restSnippet: '',
        picker: { open: false, type: '', path: '', query: '', loading: false },
        pickerResults: [],
        entities: { orgs: [], tags: [], groups: [], nodes: [], users: [] },
        syncLock: false,

        async init() {
            await this.loadEntities('');
            const saved = sessionStorage.getItem('nexalert_target_expression');
            if (saved) {
                this.expression = saved;
                sessionStorage.removeItem('nexalert_target_expression');
                await this.syncFromExpression();
            }
        },

        resetTree() {
            this.targetTree = defaultTargetTree();
            this.syncFromBuilder();
        },

        addOrBranch() {
            this.targetTree = cloneTree(this.targetTree);
            this.targetTree.children.push(defaultAndBranch());
            this.syncFromBuilder();
        },

        removeBranch(path) {
            const idx = parseInt(path.split('.').pop(), 10);
            this.targetTree = cloneTree(this.targetTree);
            this.targetTree.children.splice(idx, 1);
            if (this.targetTree.children.length === 0) {
                this.targetTree.children.push(defaultAndBranch());
            }
            this.syncFromBuilder();
        },

        addOrSubgroup(path) {
            this.targetTree = cloneTree(this.targetTree);
            const node = this.nodeAt(path);
            if (node && node.type === 'group') {
                node.children.push({ type: 'group', op: 'OR', children: [] });
            }
            this.targetTree = cloneTree(this.targetTree);
            this.syncFromBuilder();
        },

        removeChild(path) {
            const parts = path.replace(/^root\.?/, '').split('.').filter(Boolean);
            const idx = parseInt(parts.pop(), 10);
            this.targetTree = cloneTree(this.targetTree);
            let parent = this.targetTree;
            for (let i = 0; i < parts.length; i++) {
                parent = parent.children[parseInt(parts[i], 10)];
            }
            if (parent && parent.children) {
                parent.children.splice(idx, 1);
            }
            this.targetTree = cloneTree(this.targetTree);
            this.syncFromBuilder();
        },

        nodeAt(path) {
            if (path === 'root') return this.targetTree;
            const parts = path.replace(/^root\.?/, '').split('.').filter(p => p !== '').map(Number);
            let n = this.targetTree;
            for (const i of parts) {
                if (!n || !n.children || n.children[i] === undefined) return null;
                n = n.children[i];
            }
            return n;
        },

        openPicker(path, type) {
            this.picker = { open: true, type, path, query: '', loading: true };
            this.loadEntities('').then(() => {
                this.filterPickerResults();
                this.picker.loading = false;
            });
        },

        async loadEntities(q) {
            const res = await api.get('/targets/entities?q=' + encodeURIComponent(q) + '&limit=50');
            if (res.ok) this.entities = res.data.data;
        },

        async searchEntities() {
            this.picker.loading = true;
            await this.loadEntities(this.picker.query);
            this.filterPickerResults();
            this.picker.loading = false;
        },

        filterPickerResults() {
            const type = this.picker.type;
            const q = this.picker.query.toLowerCase();
            let list = this.entities[type + 's'] || [];
            if (type === 'org') list = this.entities.orgs;
            if (type === 'tag') list = this.entities.tags;
            if (type === 'group') list = this.entities.groups;
            if (type === 'node') list = this.entities.nodes;
            if (type === 'user') list = this.entities.users;

            this.pickerResults = list.map(item => {
                if (type === 'org') {
                    return { _key: 'o' + item.id, _label: item.name, _expr: 'org:' + item.slug, _val: item.slug, _sub: item.slug };
                }
                if (type === 'tag') {
                    return { _key: 't' + item.id, _label: item.name, _expr: 'tag:' + item.slug, _val: item.slug,
                             _sub: item.is_system == 1 ? 'System tag' : 'Manual tag' };
                }
                if (type === 'group') {
                    const val = item.slug + '@' + item.org_slug;
                    return { _key: 'g' + item.id, _label: item.name, _expr: item.expression || ('group:' + val), _val: val, _sub: item.org_name };
                }
                if (type === 'node') {
                    return { _key: 'n' + item.id, _label: item.name, _expr: item.expression || ('node:' + item.id),
                             _val: String(item.id), _sub: item.breadcrumb };
                }
                if (type === 'user') {
                    return { _key: 'u' + item.id, _label: item.display_name, _expr: 'user:' + item.username,
                             _val: item.username, _sub: '@' + item.username };
                }
                return null;
            }).filter(Boolean);

            if (q) {
                this.pickerResults = this.pickerResults.filter(i =>
                    i._label.toLowerCase().includes(q) || i._expr.toLowerCase().includes(q)
                );
            }
        },

        selectEntity(item) {
            const path = this.picker.path;
            const type = this.picker.type;
            this.targetTree = cloneTree(this.targetTree);
            const node = this.nodeAt(path);
            if (!node || node.type !== 'group') {
                this.picker.open = false;
                return;
            }
            node.children.push({
                type: 'term',
                dim: type,
                value: item._val,
                label: item._label,
            });
            this.targetTree = cloneTree(this.targetTree);
            this.picker.open = false;
            this.syncFromBuilder();
            this.runPreview();
        },

        syncFromBuilder() {
            if (this.syncLock) return;
            this.expression = treeToExpression(this.targetTree);
            this.parseErrors = [];
        },

        async syncFromExpression() {
            if (!this.expression.trim()) return;
            const res = await api.post('/targets/preview', { expression: this.expression.trim() });
            if (!res.ok) {
                toast(res.data?.error || 'Could not parse expression', 'error');
                return;
            }
            const data = res.data.data;
            if (!data.valid) {
                this.parseErrors = data.errors || [];
                toast('Expression has errors', 'error');
                return;
            }
            if (data.ast) {
                this.targetTree = astToTree(data.ast);
            }
            this.syncLock = true;
            this.expression = data.expression || this.expression;
            this.syncLock = false;
            toast('Expression loaded into builder');
        },

        async runPreview() {
            this.loading = true;
            this.parseErrors = [];
            this.syncFromBuilder();
            const body = { target_tree: this.targetTree, expression: this.expression };

            const res = await api.post('/targets/preview', body);
            this.loading = false;

            if (!res.ok) {
                toast(res.data?.error || 'Preview failed', 'error');
                return;
            }

            this.preview = res.data.data;
            this.preview.row_count = (this.preview.targets || []).length;

            if (!this.preview.valid) {
                this.parseErrors = this.preview.errors || [];
            } else {
                this.syncLock = true;
                this.expression = this.preview.expression || this.expression;
                if (this.preview.ast) {
                    this.targetTree = astToTree(this.preview.ast);
                }
                this.syncLock = false;
                if (this.preview.rest_api?.body) {
                    this.restSnippet = JSON.stringify(this.preview.rest_api.body, null, 2);
                }
            }
        },

        copyExpression() {
            navigator.clipboard.writeText(this.expression).then(() => toast('Expression copied'));
        },

        copyRest() {
            navigator.clipboard.writeText(this.restSnippet).then(() => toast('JSON copied'));
        },
    };
}

function defaultTargetTree() {
    return { type: 'group', op: 'OR', children: [defaultAndBranch()] };
}

function defaultAndBranch() {
    return { type: 'group', op: 'AND', children: [] };
}

function cloneTree(t) {
    return JSON.parse(JSON.stringify(t));
}

function treeToExpression(node) {
    if (node.type === 'term') {
        return node.dim + ':' + node.value;
    }
    if (!node.children || node.children.length === 0) return '';

    const op = node.op || 'AND';
    const parts = node.children.map(c => treeToExpression(c)).filter(Boolean);
    if (parts.length === 0) return '';
    if (parts.length === 1) return parts[0];

    const join = ' ' + op + ' ';
    const inner = parts.map(p => {
        if (p.includes(' ' + (op === 'AND' ? 'OR' : 'AND') + ' ') || (p.startsWith('(') && p.endsWith(')'))) return p;
        if (op === 'AND' && p.includes(' OR ')) return '(' + p + ')';
        if (op === 'OR' && p.includes(' AND ')) return '(' + p + ')';
        return p;
    }).join(join);

    return inner;
}

function astToTree(ast) {
    if (!ast || (ast.type === 'group' && (!ast.children || ast.children.length === 0))) {
        return defaultTargetTree();
    }

    let rootOr;
    if (ast.type === 'group' && ast.op === 'OR') {
        rootOr = ast;
    } else {
        rootOr = { type: 'group', op: 'OR', children: [ast] };
    }

    return {
        type: 'group',
        op: 'OR',
        children: rootOr.children.map(child => andBranchFromAst(child)),
    };
}

function andBranchFromAst(node) {
    if (node.type === 'group' && node.op === 'AND') {
        return {
            type: 'group',
            op: 'AND',
            children: node.children.flatMap(c => flattenAndChild(c)),
        };
    }

    return {
        type: 'group',
        op: 'AND',
        children: [convertAstNodeToTreeChild(node)],
    };
}

function flattenAndChild(node) {
    if (node.type === 'term') {
        return [convertAstNodeToTreeChild(node)];
    }
    if (node.type === 'group' && node.op === 'AND') {
        return node.children.flatMap(c => flattenAndChild(c));
    }

    return [convertAstNodeToTreeChild(node)];
}

function convertAstNodeToTreeChild(node) {
    if (node.type === 'term') {
        return { type: 'term', dim: node.dim, value: node.value, label: node.value };
    }
    if (node.type === 'group' && node.op === 'OR') {
        return {
            type: 'group',
            op: 'OR',
            children: node.children.map(c =>
                c.type === 'term'
                    ? { type: 'term', dim: c.dim, value: c.value, label: c.value }
                    : convertAstNodeToTreeChild(c)
            ),
        };
    }
    if (node.type === 'group' && node.op === 'AND') {
        return {
            type: 'group',
            op: 'OR',
            children: node.children.map(c =>
                c.type === 'term'
                    ? { type: 'term', dim: c.dim, value: c.value, label: c.value }
                    : convertAstNodeToTreeChild(c)
            ),
        };
    }

    return { type: 'term', dim: 'tag', value: 'unknown', label: 'unknown' };
}
</script>
