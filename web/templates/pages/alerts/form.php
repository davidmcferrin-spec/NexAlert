<?php
$pageTitle    = 'Send Alert';
$pageSubtitle = 'Compose and dispatch a multi-channel alert';
?>

<div x-data="alertComposer()" x-init="init()" class="space-y-6 max-w-5xl">

    <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 p-6 space-y-5">
        <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Message</h2>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Severity</label>
                <select x-model="form.severity"
                        class="w-full px-3 py-2 text-sm rounded-xl border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800">
                    <option value="test">Test</option>
                    <option value="info">Info</option>
                    <option value="notice">Notice</option>
                    <option value="warning">Warning</option>
                    <option value="critical">Critical</option>
                    <option value="evacuation">Evacuation</option>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Alert type</label>
                <select x-model="form.alert_type"
                        class="w-full px-3 py-2 text-sm rounded-xl border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800">
                    <option value="simple">Simple (no reply)</option>
                    <option value="ack_required">Acknowledgement required</option>
                    <option value="poll">Poll</option>
                </select>
            </div>
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Subject</label>
            <input type="text" x-model="form.subject" required maxlength="255"
                   class="w-full px-3 py-2 text-sm rounded-xl border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800">
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Body</label>
            <textarea x-model="form.body" rows="5" required
                      class="w-full px-3 py-2 text-sm rounded-xl border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800"></textarea>
        </div>

        <div x-show="form.alert_type === 'poll'" class="space-y-3">
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Poll question</label>
                <input type="text" x-model="form.poll_question"
                       class="w-full px-3 py-2 text-sm rounded-xl border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Options (one per line)</label>
                <textarea x-model="pollOptionsText" rows="3" placeholder="Yes&#10;No&#10;Maybe"
                          class="w-full px-3 py-2 text-sm rounded-xl border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 font-mono"></textarea>
            </div>
        </div>

        <div x-show="form.alert_type === 'ack_required'" class="grid grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Ack deadline (minutes)</label>
                <input type="number" x-model.number="form.ack_deadline_minutes" min="1"
                       class="w-full px-3 py-2 text-sm rounded-xl border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800">
            </div>
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Channels</label>
            <div class="flex flex-wrap gap-4">
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" value="email" x-model="form.channels"> Email</label>
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" value="sms" x-model="form.channels"> SMS</label>
            </div>
        </div>
    </div>

    <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 p-6 space-y-4">
        <div class="flex items-center justify-between">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Targets</h2>
            <a href="/admin/test-send" class="text-xs text-red-600 hover:underline">Open target builder</a>
        </div>
        <textarea x-model="form.targets" rows="3"
                  placeholder="(org:nexstar AND tag:engineering) OR group:noc@nexstar"
                  class="w-full px-3 py-2 text-sm font-mono rounded-xl border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800"></textarea>
        <div class="flex items-center gap-3">
            <button type="button" @click="previewTargets()"
                    class="px-4 py-2 text-xs font-semibold rounded-xl bg-gray-100 dark:bg-gray-800 hover:bg-gray-200">
                Preview recipients
            </button>
            <span x-show="preview.counts" class="text-sm text-gray-500">
                <strong x-text="preview.counts?.total_unique ?? 0"></strong> recipients
                · <strong x-text="preview.counts?.sms_eligible ?? 0"></strong> SMS-eligible
            </span>
        </div>
        <div x-show="preview.errors?.length" class="text-xs text-red-500">
            <template x-for="e in preview.errors" :key="e"><p x-text="e"></p></template>
        </div>
    </div>

    <div class="flex items-center gap-3">
        <button @click="send()" :disabled="sending"
                class="px-6 py-2.5 bg-red-600 hover:bg-red-700 disabled:opacity-50 text-white text-sm font-semibold rounded-xl">
            <span x-text="sending ? 'Sending…' : 'Send Alert'"></span>
        </button>
        <a href="/admin/alerts/history" class="text-sm text-gray-500 hover:text-gray-700">View history</a>
    </div>
</div>

<script>
function alertComposer() {
    return {
        form: {
            severity: 'info',
            alert_type: 'simple',
            subject: '',
            body: '',
            channels: ['email'],
            targets: '',
            poll_question: '',
            ack_deadline_minutes: 30,
        },
        pollOptionsText: 'Yes\nNo',
        preview: {},
        sending: false,

        init() {
            const saved = sessionStorage.getItem('nexalert_target_expression');
            if (saved) {
                this.form.targets = saved;
                sessionStorage.removeItem('nexalert_target_expression');
            }
        },

        async previewTargets() {
            if (!this.form.targets.trim()) {
                toast('Enter a target expression', 'error');
                return;
            }
            const res = await api.post('/targets/preview', { expression: this.form.targets });
            if (res.ok) this.preview = res.data.data;
            else toast(res.data?.error || 'Preview failed', 'error');
        },

        async send() {
            if (!this.form.subject.trim() || !this.form.body.trim()) {
                toast('Subject and body are required', 'error');
                return;
            }
            if (!this.form.targets.trim()) {
                toast('Target expression is required', 'error');
                return;
            }
            if (!this.form.channels.length) {
                toast('Select at least one channel', 'error');
                return;
            }

            this.sending = true;
            const body = {
                severity: this.form.severity,
                alert_type: this.form.alert_type,
                subject: this.form.subject,
                body: this.form.body,
                channels: this.form.channels,
                targets: this.form.targets,
            };
            if (this.form.alert_type === 'poll') {
                body.poll_question = this.form.poll_question;
                body.poll_options = this.pollOptionsText.split('\n').map(s => s.trim()).filter(Boolean);
            }
            if (this.form.alert_type === 'ack_required') {
                body.ack_deadline_minutes = this.form.ack_deadline_minutes;
            }

            const res = await api.post('/alerts', body);
            this.sending = false;

            if (res.ok) {
                toast('Alert queued — ' + (res.data.data.recipient_count || '?') + ' recipients');
                window.location.href = '/admin/alerts/history';
            } else {
                const err = res.data?.error || res.data?.errors?.targets || 'Send failed';
                toast(typeof err === 'string' ? err : JSON.stringify(err), 'error');
            }
        }
    };
}
</script>
