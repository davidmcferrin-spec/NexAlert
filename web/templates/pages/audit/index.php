<?php
$pageTitle = 'Audit Log';
$pageSubtitle = 'All system actions';
?>
<div x-data="auditPage()" x-init="init()">
    <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 dark:bg-gray-800/50">
                <tr>
                    <th class="text-left px-5 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wider">Time</th>
                    <th class="text-left px-5 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wider">Action</th>
                    <th class="text-left px-5 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wider hidden md:table-cell">Entity</th>
                    <th class="text-left px-5 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wider hidden lg:table-cell">Actor IP</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-800" id="audit-rows">
                <tr><td colspan="4" class="px-5 py-8 text-center text-sm text-gray-400">Loading…</td></tr>
            </tbody>
        </table>
    </div>
</div>
<script>
function auditPage() {
    return {
        async init() {
            // Audit log queried directly via DB in future — placeholder for now
            document.getElementById('audit-rows').innerHTML =
                '<tr><td colspan="4" class="px-5 py-8 text-center text-sm text-gray-400">Audit log UI coming in Phase 6.</td></tr>';
        }
    };
}
</script>
