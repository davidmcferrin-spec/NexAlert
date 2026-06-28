<?php
$pageTitle    = 'Send Alert';
$pageSubtitle = 'Compose and dispatch a multi-channel alert';
?>

<div x-data="alertComposer()" x-init="init()" class="space-y-6 max-w-5xl">

    <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 p-6 space-y-5">
        <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Message</h2>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                    <?= tip_label('Severity', 'How urgent the alert is. Critical and evacuation override user channel preferences.') ?>
                </label>
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
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                    <?= tip_label('Alert type', 'Simple = no reply. Ack = confirm. Poll = vote. Chat = private reply to sender. Group chat = all see all replies.') ?>
                </label>
                <select x-model="form.alert_type"
                        class="w-full px-3 py-2 text-sm rounded-xl border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800">
                    <option value="simple">Simple (no reply)</option>
                    <option value="ack_required">Acknowledgement required</option>
                    <option value="poll">Poll</option>
                    <option value="chat">Chat (reply to sender only)</option>
                    <option value="group_chat">Group chat (all see replies)</option>
                </select>
            </div>
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                <?= tip_label('Subject', 'Short headline shown in email subject and SMS prefix (max 255 chars).') ?>
            </label>
            <input type="text" x-model="form.subject" required maxlength="255"
                   class="w-full px-3 py-2 text-sm rounded-xl border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800">
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                <?= tip_label('Body', 'Main message content. Plain text; line breaks preserved in email.') ?>
            </label>
            <textarea x-model="form.body" rows="5" required
                      class="w-full px-3 py-2 text-sm rounded-xl border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800"></textarea>
        </div>

        <div x-show="form.alert_type === 'poll'" class="space-y-3">
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                    <?= tip_label('Poll question', 'Question recipients answer when they open the poll.') ?>
                </label>
                <input type="text" x-model="form.poll_question"
                       class="w-full px-3 py-2 text-sm rounded-xl border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                    <?= tip_label('Options (one per line)', 'Each line becomes a selectable poll answer.') ?>
                </label>
                <textarea x-model="pollOptionsText" rows="3" placeholder="Yes&#10;No&#10;Maybe"
                          class="w-full px-3 py-2 text-sm rounded-xl border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 font-mono"></textarea>
            </div>
        </div>

        <div x-show="form.alert_type === 'ack_required'" class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                    <?= tip_label('Ack deadline (minutes)', 'If not all recipients ack within this window, the escalation contact is notified.') ?>
                </label>
                <input type="number" x-model.number="form.ack_deadline_minutes" min="1"
                       class="w-full px-3 py-2 text-sm rounded-xl border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800">
            </div>
            <div class="space-y-2">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                    <?= tip_label('Escalation contact', 'User or group who receives an email listing unacknowledged recipients after the deadline.') ?>
                </label>
                <div class="flex flex-wrap gap-3 text-sm">
                    <label class="flex items-center gap-1.5">
                        <input type="radio" value="none" x-model="escalationTargetType" class="rounded-full"> None
                    </label>
                    <label class="flex items-center gap-1.5">
                        <input type="radio" value="user" x-model="escalationTargetType" class="rounded-full"> User
                    </label>
                    <label class="flex items-center gap-1.5">
                        <input type="radio" value="group" x-model="escalationTargetType" class="rounded-full"> Group
                    </label>
                </div>
                <select x-show="escalationTargetType === 'user'" x-model.number="form.escalation_user_id"
                        class="w-full px-3 py-2 text-sm rounded-xl border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800">
                    <option value="">— Select user —</option>
                    <template x-for="u in escalationUsers" :key="u.id">
                        <option :value="u.id" x-text="u.display_name + ' (' + u.username + ')'"></option>
                    </template>
                </select>
                <select x-show="escalationTargetType === 'group'" x-model.number="form.escalation_group_id"
                        class="w-full px-3 py-2 text-sm rounded-xl border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800">
                    <option value="">— Select group —</option>
                    <template x-for="g in escalationGroups" :key="g.id">
                        <option :value="g.id" x-text="g.name + ' (' + g.slug + ')'"></option>
                    </template>
                </select>
            </div>
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                <?= tip_label('Channels', 'Delivery channels. SMS requires confirmed opt-in per recipient.') ?>
            </label>
            <div class="flex flex-wrap gap-4">
                <label class="flex items-center gap-2 text-sm" <?= tip_attr('Send via verified email contacts', 'bottom') ?>>
                    <input type="checkbox" value="email" x-model="form.channels"> Email
                </label>
                <label class="flex items-center gap-2 text-sm" <?= tip_attr('Send via SMS to users with confirmed Twilio consent', 'bottom') ?>>
                    <input type="checkbox" value="sms" x-model="form.channels"> SMS
                </label>
                <label class="flex items-center gap-2 text-sm" <?= tip_attr('Browser push to devices that enabled Web Push on their profile', 'bottom') ?>>
                    <input type="checkbox" value="push_web" x-model="form.channels"> Web Push
                </label>
                <label class="flex items-center gap-2 text-sm" <?= tip_attr('Show in the recipient profile alerts list (no external send)', 'bottom') ?>>
                    <input type="checkbox" value="in_app" x-model="form.channels"> In-app
                </label>
            </div>
        </div>

        <div class="border-t border-gray-100 dark:border-gray-800 pt-4">
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                <?= tip_label('Alert TTL (minutes)', 'Optional. After send completes, the alert expires and no further acks or poll votes are accepted.') ?>
            </label>
            <input type="number" x-model.number="form.ttl_minutes" min="1" placeholder="Leave blank for no expiry"
                   class="w-full max-w-xs px-3 py-2 text-sm rounded-xl border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800">
        </div>

        <div class="border-t border-gray-100 dark:border-gray-800 pt-4">
            <label class="flex items-center gap-2 text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                <input type="checkbox" x-model="scheduleEnabled" class="rounded border-gray-300">
                <?= tip_label('Schedule for later', 'Hold delivery until the chosen date/time (your local timezone).') ?>
            </label>
            <input x-show="scheduleEnabled" type="datetime-local" x-model="form.send_at"
                   :min="minScheduleAt"
                   class="w-full max-w-xs px-3 py-2 text-sm rounded-xl border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800">
        </div>
    </div>

    <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 p-6 space-y-4">
        <div class="flex items-center justify-between">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">
                <?= tip_label('Targets', 'Who receives this alert. OR groups are unioned; AND terms within a group must all match.') ?>
            </h2>
            <a href="/admin/targets" class="text-xs text-red-600 hover:underline"
               <?= tip_attr('Open Target Builder — expression, tree, and presets carry over here', 'left') ?>>
                Open Target Builder
            </a>
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-500 mb-1">Target preset</label>
            <select x-model="selectedPresetId" @change="loadTargetPreset()"
                    class="w-full max-w-md px-3 py-2 text-sm rounded-xl border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800">
                <option value="">Custom target (manual expression)</option>
                <template x-for="p in targetPresets" :key="p.id">
                    <option :value="p.id" x-text="p.name + (p.is_global == 1 ? ' (global)' : '')"></option>
                </template>
            </select>
            <p x-show="targetPresetSlug" class="text-xs text-green-600 dark:text-green-400 mt-1">
                ✓ Using preset <code class="font-mono" x-text="targetPresetSlug"></code> — edit below to override
            </p>
        </div>
        <p x-show="hasTargetTree && !targetPresetSlug" class="text-xs text-green-600 dark:text-green-400">
            ✓ Target tree loaded from Target Builder — will be sent with the alert for exact AND/OR resolution.
        </p>
        <textarea x-model="form.targets" @input="onTargetsEdited()" rows="3"
                  placeholder="(org:nexstar AND tag:engineering) OR group:noc@nexstar"
                  class="w-full px-3 py-2 text-sm font-mono rounded-xl border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800"></textarea>
        <div class="flex items-center gap-3">
            <button type="button" @click="previewTargets()"
                    class="px-4 py-2 text-xs font-semibold rounded-xl bg-gray-100 dark:bg-gray-800 hover:bg-gray-200"
                    <?= tip_attr('Resolve expression (and tree if loaded) to recipient counts without sending', 'top') ?>>
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
                class="px-6 py-2.5 bg-red-600 hover:bg-red-700 disabled:opacity-50 text-white text-sm font-semibold rounded-xl"
                <?= tip_attr('Queue alert for dispatch worker — deliveries sent asynchronously', 'top') ?>>
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
            escalation_user_id: '',
            escalation_group_id: '',
            ttl_minutes: null,
        },
        targetTree: null,
        hasTargetTree: false,
        targetPresets: [],
        selectedPresetId: '',
        targetPresetSlug: '',
        targetsOverridden: false,
        escalationTargetType: 'none',
        escalationUsers: [],
        escalationGroups: [],
        pollOptionsText: 'Yes\nNo',
        preview: {},
        sending: false,
        scheduleEnabled: false,
        minScheduleAt: '',

        async init() {
            const pad = n => String(n).padStart(2, '0');
            const now = new Date();
            now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
            this.minScheduleAt = now.toISOString().slice(0, 16);

            const savedExpr = sessionStorage.getItem('nexalert_target_expression');
            const savedTree = sessionStorage.getItem('nexalert_target_tree');
            const savedPreset = sessionStorage.getItem('nexalert_target_preset');
            if (savedExpr) {
                this.form.targets = savedExpr;
                sessionStorage.removeItem('nexalert_target_expression');
            }
            if (savedTree) {
                try {
                    this.targetTree = JSON.parse(savedTree);
                    this.hasTargetTree = this.targetTree && this.targetTree.type === 'group';
                    sessionStorage.removeItem('nexalert_target_tree');
                } catch (e) {
                    this.targetTree = null;
                    this.hasTargetTree = false;
                }
            }
            if (savedPreset) {
                this.targetPresetSlug = savedPreset;
                sessionStorage.removeItem('nexalert_target_preset');
                this.targetsOverridden = false;
            }

            const presetsRes = await api.get('/targets/presets');
            if (presetsRes.ok) {
                this.targetPresets = presetsRes.data.data.presets || [];
                if (this.targetPresetSlug) {
                    const match = this.targetPresets.find(p => p.slug === this.targetPresetSlug);
                    if (match) this.selectedPresetId = String(match.id);
                }
            }

            const res = await api.get('/users?limit=200');
            if (res.ok) {
                this.escalationUsers = (res.data.data?.users || []);
            }
            const groupsRes = await api.get('/groups?limit=200');
            if (groupsRes.ok) {
                this.escalationGroups = (groupsRes.data.data?.groups || []);
            }
        },

        onTargetsEdited() {
            this.targetsOverridden = true;
            this.targetPresetSlug = '';
            this.selectedPresetId = '';
        },

        async loadTargetPreset() {
            if (!this.selectedPresetId) {
                this.targetPresetSlug = '';
                this.targetsOverridden = false;
                return;
            }
            const res = await api.get('/targets/presets/' + this.selectedPresetId);
            if (!res.ok) {
                toast(res.data?.error || 'Could not load preset', 'error');
                return;
            }
            const p = res.data.data;
            this.form.targets = p.expression || '';
            this.targetPresetSlug = p.slug;
            this.targetsOverridden = false;
            if (p.target_tree && p.target_tree.type === 'group') {
                this.targetTree = p.target_tree;
                this.hasTargetTree = true;
            } else {
                this.targetTree = null;
                this.hasTargetTree = false;
            }
            this.preview = {};
        },

        async previewTargets() {
            if (!this.form.targets.trim() && !this.hasTargetTree && !this.targetPresetSlug) {
                toast('Enter a target expression, load a preset, or open Target Builder', 'error');
                return;
            }
            const payload = { expression: this.form.targets };
            if (this.hasTargetTree && this.targetsOverridden) payload.target_tree = this.targetTree;
            if (this.targetPresetSlug && !this.targetsOverridden) {
                payload.expression = this.form.targets;
            }
            const res = await api.post('/targets/preview', payload);
            if (res.ok) this.preview = res.data.data;
            else toast(res.data?.error || 'Preview failed', 'error');
        },

        async send() {
            if (!this.form.subject.trim() || !this.form.body.trim()) {
                toast('Subject and body are required', 'error');
                return;
            }
            const usePreset = this.targetPresetSlug && !this.targetsOverridden;
            if (!usePreset && !this.form.targets.trim() && !this.hasTargetTree) {
                toast('Target expression or preset is required', 'error');
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
            };
            if (usePreset) {
                body.target_preset = this.targetPresetSlug;
            } else {
                body.targets = this.form.targets;
                if (this.hasTargetTree) {
                    body.target_tree = this.targetTree;
                }
            }
            if (this.form.alert_type === 'poll') {
                body.poll_question = this.form.poll_question;
                body.poll_options = this.pollOptionsText.split('\n').map(s => s.trim()).filter(Boolean);
            }
            if (this.form.alert_type === 'ack_required') {
                body.ack_deadline_minutes = this.form.ack_deadline_minutes;
                if (this.escalationTargetType === 'user' && this.form.escalation_user_id) {
                    body.escalation_user_id = this.form.escalation_user_id;
                }
                if (this.escalationTargetType === 'group' && this.form.escalation_group_id) {
                    body.escalation_group_id = this.form.escalation_group_id;
                }
            }
            if (this.scheduleEnabled && this.form.send_at) {
                body.send_at = this.form.send_at;
            }
            if (this.form.ttl_minutes && this.form.ttl_minutes > 0) {
                body.ttl_minutes = this.form.ttl_minutes;
            }

            const res = await api.post('/alerts', body);
            this.sending = false;

            if (res.ok) {
                const msg = res.data.data?.scheduled ? 'Alert scheduled' : 'Alert queued';
                toast(msg + ' — ' + (res.data.data.recipient_count || '?') + ' recipients');
                window.location.href = '/admin/alerts/history';
            } else {
                const err = res.data?.error || res.data?.errors?.targets || 'Send failed';
                toast(typeof err === 'string' ? err : JSON.stringify(err), 'error');
            }
        }
    };
}
</script>
