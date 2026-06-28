<?php
$pageTitle    = 'Test Send';
$pageSubtitle = 'Preview alert recipients and build canonical target expressions';
?>

<div x-data="testSendPage()" x-init="init()" class="space-y-6">

    <div class="rounded-xl border border-blue-200 dark:border-blue-900/50 bg-blue-50 dark:bg-blue-950/30 px-4 py-3 text-sm text-blue-800 dark:text-blue-200">
        Preview uses the same resolver as live alert sends. The canonical expression below is what you would pass to
        <code class="font-mono text-xs">POST /api/v1/alert</code> as <code class="font-mono text-xs">targets</code>.
        Within each OR group, dimensions are <strong>AND</strong>ed; groups are unioned.
    </div>

    <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">

        <!-- Visual builder -->
        <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-800 flex items-center justify-between">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Target Builder</h2>
                <button @click="addOrRow()"
                        class="text-xs font-medium text-red-600 hover:text-red-700 dark:text-red-400">+ Add OR group</button>
            </div>
            <div class="p-5 space-y-4">
                <template x-for="(row, rowIdx) in orRows" :key="rowIdx">
                    <div class="rounded-xl border border-gray-200 dark:border-gray-700 p-4 space-y-3">
                        <div class="flex items-center justify-between">
                            <span class="text-xs font-semibold uppercase tracking-wider text-gray-400"
                                  x-text="'OR group ' + (rowIdx + 1)"></span>
                            <button @click="removeOrRow(rowIdx)" x-show="orRows.length > 1"
                                    class="text-xs text-gray-400 hover:text-red-500">Remove</button>
                        </div>

                        <div class="flex flex-wrap gap-2 min-h-[2rem]">
                            <template x-for="(dim, dimKey) in row" :key="dimKey">
                                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-xs font-mono
                                             bg-gray-100 dark:bg-gray-800 text-gray-800 dark:text-gray-200">
                                    <span x-text="dimKey + ':' + dim"></span>
                                    <button @click="removeDimension(rowIdx, dimKey)" class="text-gray-400 hover:text-red-500">&times;</button>
                                </span>
                            </template>
                            <span x-show="Object.keys(row).length === 0" class="text-xs text-gray-400 italic">No dimensions — add one below</span>
                        </div>

                        <div class="flex flex-wrap gap-2">
                            <template x-for="t in dimensionTypes" :key="t">
                                <button @click="openPicker(rowIdx, t)"
                                        class="px-2.5 py-1 text-xs rounded-lg border border-dashed border-gray-300
                                               dark:border-gray-600 text-gray-500 hover:border-red-400 hover:text-red-600"
                                        x-text="'+ ' + t"></button>
                            </template>
                        </div>
                    </div>
                </template>

                <p class="text-xs text-gray-400">
                    <code class="font-mono">org</code> = home org ·
                    <code class="font-mono">node</code> = membership subtree ·
                    <code class="font-mono">tag</code> / <code class="font-mono">group</code> / <code class="font-mono">user</code> as labeled
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
                    <textarea x-model="expression" @input="onExpressionInput()"
                              rows="3" placeholder="(org:nexstar AND tag:engineering) OR group:noc@nexstar"
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
        orRows: [{}],
        expression: '',
        parseErrors: [],
        dimensionTypes: ['org', 'node', 'tag', 'group', 'user'],
        loading: false,
        preview: {},
        restSnippet: '',
        picker: { open: false, type: '', rowIdx: 0, query: '', loading: false },
        pickerResults: [],
        entities: { orgs: [], tags: [], groups: [], nodes: [], users: [] },
        syncLock: false,

        async init() {
            await this.loadEntities('');
        },

        addOrRow() {
            this.orRows.push({});
            this.syncFromBuilder();
        },

        removeOrRow(idx) {
            this.orRows.splice(idx, 1);
            this.syncFromBuilder();
        },

        removeDimension(rowIdx, dimKey) {
            delete this.orRows[rowIdx][dimKey];
            this.syncFromBuilder();
        },

        buildExpressionFromRows() {
            const terms = this.orRows.map(row => {
                const order = ['org', 'node', 'tag', 'group', 'user'];
                const parts = order.filter(k => row[k]).map(k => k + ':' + row[k]);
                if (parts.length === 0) return null;
                return parts.length === 1 ? parts[0] : '(' + parts.join(' AND ') + ')';
            }).filter(Boolean);
            return terms.join(' OR ');
        },

        syncFromBuilder() {
            if (this.syncLock) return;
            this.expression = this.buildExpressionFromRows();
            this.parseErrors = [];
        },

        onExpressionInput() {
            // debounced preview optional; user clicks Preview
        },

        syncFromExpression() {
            const parts = this.expression.split(/\s+OR\s+/i).map(s => s.trim()).filter(Boolean);
            const rows = [];
            for (const part of parts) {
                let inner = part.replace(/^\(|\)$/g, '').trim();
                const andParts = inner.split(/\s+AND\s+/i).map(s => s.trim());
                const row = {};
                for (const p of andParts) {
                    const m = p.match(/^(org|node|tag|group|user):(.+)$/i);
                    if (m) row[m[1].toLowerCase()] = m[2].toLowerCase();
                }
                if (Object.keys(row).length) rows.push(row);
            }
            this.orRows = rows.length ? rows : [{}];
            toast('Expression loaded into builder');
        },

        openPicker(rowIdx, type) {
            this.picker = { open: true, type, rowIdx, query: '', loading: true };
            this.loadEntities('').then(() => {
                this.filterPickerResults();
                this.picker.loading = false;
            });
        },

        async loadEntities(q) {
            const res = await api.get('/targets/entities?q=' + encodeURIComponent(q) + '&limit=50');
            if (res.ok) {
                this.entities = res.data.data;
            }
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
            let list = this.entities[type + 's'] || this.entities[type] || [];
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
                    const expr = item.expression || ('group:' + item.slug + '@' + item.org_slug);
                    const val  = item.slug + '@' + item.org_slug;
                    return { _key: 'g' + item.id, _label: item.name, _expr: expr, _val: val, _sub: item.org_name };
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
            const row = this.orRows[this.picker.rowIdx];
            row[this.picker.type] = item._val;
            this.picker.open = false;
            this.syncFromBuilder();
            this.runPreview();
        },

        structuredPayload() {
            return this.orRows
                .map(row => {
                    const o = {};
                    ['org', 'node', 'tag', 'group', 'user'].forEach(k => { if (row[k]) o[k] = row[k]; });
                    return o;
                })
                .filter(o => Object.keys(o).length > 0);
        },

        async runPreview() {
            this.loading = true;
            this.parseErrors = [];
            const targets = this.structuredPayload();
            const body = targets.length
                ? { targets, expression: this.expression }
                : { expression: this.expression };

            const res = await api.post('/targets/preview', body);
            this.loading = false;

            if (!res.ok) {
                toast(res.data?.error || 'Preview failed', 'error');
                return;
            }

            this.preview = res.data.data;
            if (!this.preview.valid) {
                this.parseErrors = this.preview.errors || [];
            } else {
                this.syncLock = true;
                this.expression = this.preview.expression || this.expression;
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
</script>
