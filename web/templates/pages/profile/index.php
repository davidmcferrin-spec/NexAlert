<?php
$pageTitle = 'My Profile';

if (!web_auth()) {
    $_SESSION['redirect_after_login'] = '/profile';
    header('Location: /admin/login');
    exit;
}

$severities = ['test', 'info', 'notice', 'warning', 'critical', 'evacuation'];
?>

<div x-data="profilePage()" x-init="init()" class="space-y-6">

    <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 p-6">
        <h2 class="text-sm font-semibold mb-4">Profile</h2>
        <div class="space-y-3">
            <div>
                <label class="block text-xs text-gray-400 mb-1">Username</label>
                <div class="text-sm text-gray-600 dark:text-gray-300 font-mono" x-text="profile.username"></div>
            </div>
            <div>
                <label class="block text-xs text-gray-400 mb-1">Home organization</label>
                <div class="text-sm text-gray-600 dark:text-gray-300" x-text="profile.home_org_name || '—'"></div>
                <div x-show="profile.home_node_name" class="text-xs text-gray-400 mt-0.5">
                    Home node: <span x-text="profile.home_node_name"></span>
                </div>
            </div>
            <div>
                <label class="block text-xs text-gray-400 mb-1">Display name</label>
                <input type="text" x-model="profile.display_name"
                       class="w-full px-3 py-2 text-sm rounded-xl border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800">
            </div>
            <div>
                <label class="block text-xs text-gray-400 mb-1">Timezone</label>
                <input type="text" x-model="profile.timezone" placeholder="America/Chicago"
                       class="w-full px-3 py-2 text-sm rounded-xl border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800">
            </div>
            <button @click="saveProfile()" class="px-4 py-2 text-sm font-semibold bg-red-600 text-white rounded-xl">Save profile</button>
        </div>
    </div>

    <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 p-6">
        <h2 class="text-sm font-semibold mb-1">Organizations &amp; nodes</h2>
        <p class="text-xs text-gray-400 mb-4">Your home org and active org-node memberships (managed by administrators).</p>
        <ul class="divide-y divide-gray-100 dark:divide-gray-800">
            <template x-for="m in memberships" :key="m.id">
                <li class="py-3">
                    <div class="text-sm font-medium text-gray-900 dark:text-white" x-text="m.breadcrumb || m.org_name"></div>
                    <div x-show="m.position_title" class="text-xs text-gray-500 mt-0.5" x-text="m.position_title"></div>
                    <div class="text-xs text-gray-400 mt-0.5 capitalize" x-text="m.node_type ? m.node_type.replace('_', ' ') : ''"></div>
                </li>
            </template>
            <li x-show="!memberships.length" class="py-3 text-sm text-gray-400">No org node memberships on file.</li>
        </ul>
    </div>

    <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 p-6">
        <h2 class="text-sm font-semibold mb-1">Tags</h2>
        <p class="text-xs text-gray-400 mb-4">Tags used for alert targeting. System and org-inherited tags are read-only.</p>

        <div class="flex flex-wrap gap-2 mb-5">
            <template x-for="t in myTags" :key="t.tag_id">
                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-xs font-medium"
                      :class="t.is_system == 1
                        ? 'bg-blue-50 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300'
                        : 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300'"
                      :title="tagAssignmentLabel(t)">
                    <span x-text="t.name"></span>
                    <span class="opacity-60 text-[10px] uppercase" x-text="(t.assignment_type || '').replace('_', ' ')"></span>
                    <button x-show="canRemoveTag(t)" @click="removeMyTag(t)"
                            class="opacity-60 hover:opacity-100 transition-opacity" title="Remove tag">✕</button>
                </span>
            </template>
            <span x-show="!myTags.length" class="text-sm text-gray-400">No tags assigned yet.</span>
        </div>

        <div x-show="pendingTagRequests.length" class="mb-5 border-t border-gray-100 dark:border-gray-800 pt-4">
            <h3 class="text-xs font-semibold uppercase tracking-wider text-gray-500 mb-2">Pending requests</h3>
            <ul class="space-y-2">
                <template x-for="r in pendingTagRequests" :key="r.id">
                    <li class="flex items-center justify-between gap-3 text-sm">
                        <div>
                            <span class="font-medium" x-text="r.tag_name"></span>
                            <span class="text-xs text-amber-600 ml-2">Awaiting approval</span>
                            <p x-show="r.justification" class="text-xs text-gray-400 mt-0.5" x-text="r.justification"></p>
                        </div>
                        <button @click="cancelTagRequest(r)" class="text-xs text-red-500 hover:underline">Cancel</button>
                    </li>
                </template>
            </ul>
        </div>

        <div class="border-t border-gray-100 dark:border-gray-800 pt-4">
            <h3 class="text-xs font-semibold uppercase tracking-wider text-gray-500 mb-2">Request a tag</h3>
            <p class="text-xs text-gray-400 mb-3">Request tags your administrator has opened for self-service. Some tags are added immediately; others require approval.</p>
            <div class="space-y-3 max-w-lg">
                <select x-model="tagRequestForm.tag_id"
                        class="w-full px-3 py-2 text-sm rounded-xl border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800">
                    <option value="">Select a tag to request…</option>
                    <template x-for="t in requestableTags" :key="t.id">
                        <option :value="t.id" x-text="t.name + (t.requires_approval == 1 ? ' (needs approval)' : '')"></option>
                    </template>
                </select>
                <textarea x-model="tagRequestForm.justification" rows="2" placeholder="Optional: why you need this tag"
                          class="w-full px-3 py-2 text-sm rounded-xl border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800"></textarea>
                <button @click="submitTagRequest()" :disabled="!tagRequestForm.tag_id || tagRequestBusy"
                        class="px-4 py-2 text-sm font-semibold bg-red-600 text-white rounded-xl disabled:opacity-50">
                    <span x-text="tagRequestBusy ? 'Submitting…' : 'Submit request'"></span>
                </button>
                <p x-show="!requestableTags.length" class="text-xs text-gray-400">No tags are available to request right now.</p>
            </div>
        </div>
    </div>

    <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 p-6">
        <h2 class="text-sm font-semibold mb-4">Change password</h2>
        <div class="space-y-3 max-w-md">
            <div>
                <label class="block text-xs text-gray-400 mb-1">Current password</label>
                <input type="password" autocomplete="current-password" x-model="passwordForm.current"
                       class="w-full px-3 py-2 text-sm rounded-xl border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800">
            </div>
            <div>
                <label class="block text-xs text-gray-400 mb-1">New password</label>
                <input type="password" autocomplete="new-password" minlength="12" x-model="passwordForm.password"
                       class="w-full px-3 py-2 text-sm rounded-xl border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800">
            </div>
            <div>
                <label class="block text-xs text-gray-400 mb-1">Confirm new password</label>
                <input type="password" autocomplete="new-password" minlength="12" x-model="passwordForm.confirm"
                       class="w-full px-3 py-2 text-sm rounded-xl border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800">
            </div>
            <button @click="changePassword()" :disabled="passwordSaving"
                    class="px-4 py-2 text-sm font-semibold bg-gray-800 dark:bg-gray-700 text-white rounded-xl disabled:opacity-60">
                <span x-text="passwordSaving ? 'Saving…' : 'Update password'"></span>
            </button>
        </div>
    </div>

    <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 p-6">
        <h2 class="text-sm font-semibold mb-1">Browser push notifications</h2>
        <p class="text-xs text-gray-400 mb-4">Receive alerts on this device even when NexAlert is not open. Requires HTTPS and a supported browser.</p>
        <div x-show="!pushConfigured" class="text-sm text-amber-600 mb-3">Web Push is not configured on this server (VAPID keys missing).</div>
        <div class="flex flex-wrap gap-2 mb-4">
            <button @click="enablePush()" :disabled="pushBusy || !pushConfigured"
                    class="px-4 py-2 text-sm font-semibold bg-red-600 text-white rounded-xl disabled:opacity-50">
                <span x-text="pushBusy ? 'Enabling…' : 'Enable push on this device'"></span>
            </button>
            <button x-show="notifyPermission !== 'granted'" @click="enableBrowserNotify()"
                    class="px-4 py-2 text-sm font-semibold bg-gray-100 dark:bg-gray-800 rounded-xl">
                Enable browser notifications
            </button>
            <span x-show="notifyPermission === 'granted'" class="text-xs text-green-600 self-center">Browser notifications on</span>
        </div>
        <ul x-show="pushSubscriptions.length" class="text-sm divide-y divide-gray-100 dark:divide-gray-800">
            <template x-for="s in pushSubscriptions" :key="s.id">
                <li class="py-2 flex items-center justify-between gap-2">
                    <span x-text="s.device_label || 'Browser device'"></span>
                    <button @click="removePush(s)" class="text-xs text-red-500">Remove</button>
                </li>
            </template>
        </ul>
    </div>

    <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 p-6">
        <h2 class="text-sm font-semibold mb-1">Notification preferences</h2>
        <p class="text-xs text-gray-400 mb-4">Choose which channels receive alerts at each severity level.</p>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-xs text-gray-400 uppercase">
                        <th class="text-left py-2 pr-4">Severity</th>
                        <th class="text-center py-2 px-2">Email</th>
                        <th class="text-center py-2 px-2">SMS</th>
                        <th class="text-center py-2 px-2">Push</th>
                        <th class="text-center py-2 px-2">In-app</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="pref in notificationPrefs" :key="pref.severity">
                        <tr class="border-t border-gray-100 dark:border-gray-800">
                            <td class="py-2 pr-4 capitalize font-medium" x-text="pref.severity"></td>
                            <td class="py-2 text-center">
                                <input type="checkbox" :checked="pref.channel_email == 1"
                                       @change="pref.channel_email = $event.target.checked ? 1 : 0"
                                       class="rounded border-gray-300">
                            </td>
                            <td class="py-2 text-center">
                                <input type="checkbox" :checked="pref.channel_sms == 1"
                                       @change="pref.channel_sms = $event.target.checked ? 1 : 0"
                                       class="rounded border-gray-300">
                            </td>
                            <td class="py-2 text-center">
                                <input type="checkbox" :checked="pref.channel_push == 1"
                                       @change="pref.channel_push = $event.target.checked ? 1 : 0"
                                       class="rounded border-gray-300">
                            </td>
                            <td class="py-2 text-center">
                                <input type="checkbox" :checked="pref.channel_in_app == 1"
                                       @change="pref.channel_in_app = $event.target.checked ? 1 : 0"
                                       class="rounded border-gray-300">
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
        <button @click="saveNotifications()" class="mt-4 px-4 py-2 text-sm font-semibold bg-red-600 text-white rounded-xl">Save preferences</button>
    </div>

    <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 p-6">
        <h2 class="text-sm font-semibold mb-1">Contact methods</h2>
        <p class="text-xs text-gray-400 mb-4">Email and mobile number used for alert delivery. SMS requires replying YES to confirm.</p>
        <ul class="divide-y divide-gray-100 dark:divide-gray-800 mb-4">
            <template x-for="c in profile.contacts || []" :key="c.id">
                <li class="py-3 flex items-start justify-between gap-3">
                    <div class="flex-1 min-w-0">
                        <span class="text-xs uppercase font-medium text-gray-400" x-text="c.channel === 'sms' ? 'Mobile phone (SMS)' : 'Email'"></span>
                        <template x-if="editingContactId !== c.id">
                            <div class="text-sm font-mono mt-0.5 break-all" x-text="c.contact_value"></div>
                        </template>
                        <template x-if="editingContactId === c.id">
                            <input type="text" x-model="editContactValue"
                                   :placeholder="c.channel === 'sms' ? '+1 555 123 4567' : 'you@example.com'"
                                   class="mt-1 w-full px-3 py-2 text-sm font-mono rounded-xl border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800">
                        </template>
                        <div class="text-xs text-gray-400 mt-0.5">
                            <span x-show="c.is_verified == 1" class="text-green-600">Email verified</span>
                            <span x-show="c.channel === 'sms' && c.sms_consent_status"
                                  class="capitalize"
                                  :class="{
                                      'text-green-600': c.sms_consent_status === 'confirmed',
                                      'text-amber-600': ['pending','invite_sent','opt_in_sent'].includes(c.sms_consent_status),
                                      'text-red-600': ['stopped','denied'].includes(c.sms_consent_status)
                                  }"
                                  x-text="'SMS: ' + smsConsentLabel(c.sms_consent_status)"></span>
                        </div>
                    </div>
                    <div class="flex flex-col items-end gap-1 flex-shrink-0">
                        <template x-if="editingContactId !== c.id">
                            <button @click="startEditContact(c)" class="text-xs text-blue-600 hover:underline">Edit</button>
                        </template>
                        <template x-if="editingContactId === c.id">
                            <div class="flex gap-2">
                                <button @click="saveEditContact(c)" class="text-xs font-semibold text-green-600">Save</button>
                                <button @click="cancelEditContact()" class="text-xs text-gray-400">Cancel</button>
                            </div>
                        </template>
                        <button x-show="c.channel === 'email' && c.is_verified != 1 && editingContactId !== c.id" @click="resendVerify(c)"
                                class="text-xs text-blue-600">Resend verify</button>
                        <button x-show="c.channel === 'sms' && c.sms_consent_status !== 'confirmed' && c.sms_consent_status !== 'stopped' && editingContactId !== c.id"
                                @click="smsOptIn(c)"
                                class="text-xs text-green-600"
                                :title="c.sms_consent_status === 'opt_in_sent' ? 'Reply YES to the text message to confirm' : 'Send Twilio opt-in SMS'">
                            <span x-text="c.sms_consent_status === 'opt_in_sent' ? 'Resend opt-in' : 'Request SMS opt-in'"></span>
                        </button>
                        <button x-show="editingContactId !== c.id" @click="removeContact(c)" class="text-xs text-red-500">Remove</button>
                    </div>
                </li>
            </template>
            <li x-show="!(profile.contacts || []).length" class="py-3 text-sm text-gray-400">No contacts on file — add an email or phone below.</li>
        </ul>
        <div class="border-t border-gray-100 dark:border-gray-800 pt-4">
            <p class="text-xs font-medium text-gray-500 mb-2">Add contact</p>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
                <select x-model="newContact.channel" class="px-3 py-2 text-sm rounded-xl border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800">
                    <option value="email">Email</option>
                    <option value="sms">Mobile phone (SMS)</option>
                </select>
                <input type="text" x-model="newContact.contact_value"
                       :placeholder="newContact.channel === 'sms' ? '+1 555 123 4567' : 'you@example.com'"
                       class="px-3 py-2 text-sm rounded-xl border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 sm:col-span-2">
            </div>
            <button @click="addContact()" class="mt-3 px-4 py-2 text-sm font-semibold bg-gray-100 dark:bg-gray-800 rounded-xl hover:bg-gray-200 dark:hover:bg-gray-700 transition-colors">Add contact</button>
        </div>
    </div>

    <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 p-6">
        <h2 class="text-sm font-semibold mb-4">My alerts</h2>
        <template x-for="a in myAlerts" :key="a.id">
            <div class="py-4 border-b border-gray-100 dark:border-gray-800 last:border-0">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2 flex-wrap">
                            <span class="font-medium text-sm" x-text="a.subject"></span>
                            <span class="text-xs px-2 py-0.5 rounded-full capitalize"
                                  :class="severityBadge(a.severity)" x-text="a.severity"></span>
                        </div>
                        <div class="text-xs text-gray-400 mt-1" x-text="formatDate(a.created_at)"></div>
                        <p class="text-sm text-gray-600 dark:text-gray-400 mt-2 whitespace-pre-wrap" x-text="a.body"></p>
                        <template x-if="a.alert_type === 'poll' && a.poll_question">
                            <div class="mt-3">
                                <p class="text-sm font-medium text-gray-800 dark:text-gray-200" x-text="a.poll_question"></p>
                                <div x-show="canVote(a)" class="flex flex-wrap gap-2 mt-2">
                                    <template x-for="opt in (a.poll_options || [])" :key="opt">
                                        <button @click="votePoll(a, opt)"
                                                class="px-3 py-1.5 text-xs font-semibold bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                                            <span x-text="opt"></span>
                                        </button>
                                    </template>
                                </div>
                                <p x-show="a.i_voted > 0" class="text-xs text-green-600 mt-2 font-medium">You responded to this poll</p>
                                <p x-show="!canVote(a) && a.i_voted == 0 && (a.is_expired || a.status === 'expired')" class="text-xs text-gray-400 mt-2">Poll closed</p>
                            </div>
                        </template>
                        <div x-show="a.can_chat" class="mt-3 border-t border-gray-100 dark:border-gray-800 pt-3">
                            <button @click="toggleChat(a)" class="text-xs font-semibold text-red-600 hover:underline mb-2"
                                    x-text="openChatId === a.id ? 'Hide conversation' : 'View / reply'"></button>
                            <div x-show="openChatId === a.id">
                                <div class="max-h-48 overflow-y-auto space-y-2 mb-2 text-sm bg-gray-50 dark:bg-gray-800/50 rounded-xl p-3">
                                    <template x-for="m in (chatMessages[a.id] || [])" :key="m.id">
                                        <div>
                                            <span class="text-xs font-semibold text-gray-500" x-text="m.user_name"></span>
                                            <span class="text-xs text-gray-400 ml-1" x-text="formatDate(m.created_at)"></span>
                                            <p class="text-gray-700 dark:text-gray-300 whitespace-pre-wrap" x-text="m.body"></p>
                                        </div>
                                    </template>
                                    <p x-show="!(chatMessages[a.id] || []).length" class="text-xs text-gray-400">No messages yet.</p>
                                </div>
                                <div class="flex gap-2">
                                    <input type="text" x-model="chatDraft[a.id]" placeholder="Type a reply…"
                                           @keydown.enter.prevent="sendChat(a)"
                                           class="flex-1 px-3 py-2 text-sm rounded-xl border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800">
                                    <button @click="sendChat(a)" class="px-3 py-2 text-xs font-semibold bg-red-600 text-white rounded-xl">Send</button>
                                </div>
                                <p class="text-xs text-gray-400 mt-1">SMS recipients can reply by texting back while this thread is open.</p>
                            </div>
                        </div>
                    </div>
                    <button x-show="a.ack_required == 1 && a.i_acked == 0 && !a.is_expired && a.status !== 'expired'" @click="ackAlert(a)"
                            class="flex-shrink-0 px-3 py-1.5 text-xs font-semibold bg-red-600 text-white rounded-lg">
                        Acknowledge
                    </button>
                    <span x-show="a.i_acked > 0" class="flex-shrink-0 text-xs text-green-600 font-medium">Acknowledged</span>
                </div>
            </div>
        </template>
        <p x-show="!myAlerts.length" class="text-sm text-gray-400">No alerts yet.</p>
    </div>
</div>

<script>
const SEVERITIES = <?= json_encode($severities, JSON_THROW_ON_ERROR) ?>;

function profilePage() {
    return {
        profile: {}, myAlerts: [], notificationPrefs: [],
        pushSubscriptions: [], pushConfigured: false, pushBusy: false,
        notifyPermission: typeof Notification !== 'undefined' ? Notification.permission : 'unsupported',
        openChatId: null, chatMessages: {}, chatDraft: {}, chatPollTimer: null,
        newContact: { channel: 'email', contact_value: '' },
        editingContactId: null, editContactValue: '',
        passwordForm: { current: '', password: '', confirm: '' },
        passwordSaving: false,
        memberships: [],
        myTags: [],
        tagRequests: [],
        requestableTags: [],
        tagRequestForm: { tag_id: '', justification: '' },
        tagRequestBusy: false,
        get pendingTagRequests() {
            return (this.tagRequests || []).filter(r => r.status === 'pending');
        },
        async init() {
            await this.reloadProfile();
            await this.loadOrgAndTags();
            const a = await api.get('/profile/alerts?limit=20');
            if (a.ok) this.myAlerts = a.data.data.alerts;
            await this.loadNotifications();
            await this.loadPushSubscriptions();
            if (typeof window.nexalertNotificationPermission === 'function') {
                this.notifyPermission = window.nexalertNotificationPermission();
            }
            const params = new URLSearchParams(location.search);
            const ackId = params.get('ack_alert');
            if (ackId) this.ackAlert({ id: parseInt(ackId, 10) });
            const alertId = params.get('alert');
            if (alertId) {
                const a = this.myAlerts.find(x => x.id == alertId);
                if (a && a.can_chat) this.toggleChat(a);
            }
        },
        urlBase64ToUint8Array(base64String) {
            const padding = '='.repeat((4 - base64String.length % 4) % 4);
            const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
            const raw = atob(base64);
            const arr = new Uint8Array(raw.length);
            for (let i = 0; i < raw.length; i++) arr[i] = raw.charCodeAt(i);
            return arr;
        },
        async loadPushSubscriptions() {
            const res = await api.get('/profile/push/subscriptions');
            if (res.ok) {
                this.pushSubscriptions = res.data.data.subscriptions || [];
                this.pushConfigured = !!res.data.data.configured;
            }
        },
        async enableBrowserNotify() {
            if (typeof window.nexalertRequestNotificationPermission === 'function') {
                const ok = await window.nexalertRequestNotificationPermission();
                this.notifyPermission = window.nexalertNotificationPermission();
                return ok;
            }
            toast('Notifications not available', 'error');
        },
        async enablePush() {
            if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
                toast('Push notifications are not supported in this browser', 'error');
                return;
            }
            this.pushBusy = true;
            try {
                const keyRes = await api.get('/profile/push/vapid-key');
                if (!keyRes.ok) {
                    toast(keyRes.data?.error || 'Push not available', 'error');
                    return;
                }
                const reg = await navigator.serviceWorker.register('/sw.js');
                const sub = await reg.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey: this.urlBase64ToUint8Array(keyRes.data.data.public_key),
                });
                const json = sub.toJSON();
                const save = await api.post('/profile/push/subscribe', {
                    endpoint: json.endpoint,
                    p256dh: json.keys.p256dh,
                    auth: json.keys.auth,
                    user_agent: navigator.userAgent,
                });
                toast(save.ok ? 'Push enabled on this device' : (save.data?.error || 'Failed'), save.ok ? 'success' : 'error');
                if (save.ok) await this.loadPushSubscriptions();
            } catch (e) {
                toast(e.message || 'Could not enable push', 'error');
            } finally {
                this.pushBusy = false;
            }
        },
        async removePush(s) {
            const res = await api.delete('/profile/push/subscriptions/' + s.id);
            toast(res.ok ? 'Device removed' : (res.data?.error || 'Failed'), res.ok ? 'success' : 'error');
            if (res.ok) await this.loadPushSubscriptions();
        },
        async toggleChat(a) {
            if (this.chatPollTimer) {
                clearInterval(this.chatPollTimer);
                this.chatPollTimer = null;
            }
            if (this.openChatId === a.id) {
                this.openChatId = null;
                return;
            }
            this.openChatId = a.id;
            await this.loadChat(a.id);
            this.chatPollTimer = setInterval(() => this.loadChat(a.id, true), 5000);
        },
        async loadChat(alertId, appendOnly = false) {
            let url = '/alerts/' + alertId + '/chat/messages';
            if (appendOnly && (this.chatMessages[alertId] || []).length) {
                const last = this.chatMessages[alertId][this.chatMessages[alertId].length - 1];
                if (last?.created_at) url += '?since=' + encodeURIComponent(last.created_at);
            }
            const res = await api.get(url);
            if (!res.ok) return;
            const incoming = res.data.data.messages || [];
            if (appendOnly && incoming.length) {
                const existing = this.chatMessages[alertId] || [];
                const ids = new Set(existing.map(m => m.id));
                this.chatMessages[alertId] = existing.concat(incoming.filter(m => !ids.has(m.id)));
            } else if (!appendOnly) {
                this.chatMessages[alertId] = incoming;
            }
        },
        async sendChat(a) {
            const body = (this.chatDraft[a.id] || '').trim();
            if (!body) return;
            const res = await api.post('/alerts/' + a.id + '/chat/messages', { body });
            if (res.ok) {
                this.chatDraft[a.id] = '';
                await this.loadChat(a.id);
            } else {
                toast(res.data?.error || 'Send failed', 'error');
            }
        },
        severityBadge(s) {
            const m = { test: 'bg-gray-100 text-gray-600', info: 'bg-blue-100 text-blue-700',
                warning: 'bg-yellow-100 text-yellow-700', critical: 'bg-orange-100 text-orange-700',
                evacuation: 'bg-red-100 text-red-700', notice: 'bg-indigo-100 text-indigo-700' };
            return m[s] || 'bg-gray-100 text-gray-600';
        },
        smsConsentLabel(status) {
            const labels = {
                pending: 'pending — request opt-in',
                invite_sent: 'invite email sent',
                opt_in_sent: 'awaiting YES reply',
                confirmed: 'confirmed',
                denied: 'declined',
                stopped: 'STOP — unsubscribed',
                expired: 'expired',
            };
            return labels[status] || status;
        },
        formatDate(iso) {
            if (!iso) return '';
            try {
                return new Date(iso.replace(' ', 'T') + 'Z').toLocaleString();
            } catch (e) { return iso; }
        },
        canVote(a) {
            return a.alert_type === 'poll' && a.i_voted == 0 && !a.is_expired && a.status !== 'expired'
                && ['sending', 'sent'].includes(a.status);
        },
        async votePoll(a, option) {
            const res = await api.post('/alerts/' + a.id + '/poll', { response_value: option });
            toast(res.ok ? 'Vote recorded' : (res.data?.error || 'Failed'), res.ok ? 'success' : 'error');
            if (res.ok) {
                const r = await api.get('/profile/alerts');
                if (r.ok) this.myAlerts = r.data.data.alerts;
            }
        },
        async reloadProfile() {
            const p = await api.get('/profile');
            if (p.ok) this.profile = p.data.data;
        },
        async loadOrgAndTags() {
            const [memRes, tagRes, reqRes, availRes] = await Promise.all([
                api.get('/profile/memberships'),
                api.get('/profile/tags'),
                api.get('/profile/tag-requests'),
                api.get('/profile/tags/requestable'),
            ]);
            if (memRes.ok) this.memberships = memRes.data.data.memberships || [];
            if (tagRes.ok) this.myTags = tagRes.data.data.tags || [];
            if (reqRes.ok) this.tagRequests = reqRes.data.data.requests || [];
            if (availRes.ok) this.requestableTags = availRes.data.data.tags || [];
        },
        tagAssignmentLabel(t) {
            if (t.source_node_name) return 'From org node: ' + t.source_node_name;
            return (t.assignment_type || '').replace(/_/g, ' ');
        },
        canRemoveTag(t) {
            return t.assignment_type === 'manual' || t.assignment_type === 'approved_request';
        },
        async removeMyTag(t) {
            if (!confirm('Remove tag "' + t.name + '" from your profile?')) return;
            const res = await api.delete('/profile/tags/' + t.tag_id);
            if (res.ok) {
                toast('Tag removed');
                await this.loadOrgAndTags();
            } else {
                toast(res.data?.error || 'Could not remove tag', 'error');
            }
        },
        async submitTagRequest() {
            if (!this.tagRequestForm.tag_id) return;
            this.tagRequestBusy = true;
            const res = await api.post('/profile/tag-requests', {
                tag_id: parseInt(this.tagRequestForm.tag_id, 10),
                justification: this.tagRequestForm.justification.trim() || null,
            });
            this.tagRequestBusy = false;
            if (res.ok) {
                toast(res.data.message || 'Request submitted', 'success');
                this.tagRequestForm = { tag_id: '', justification: '' };
                await this.loadOrgAndTags();
            } else {
                toast(res.data?.error || res.data?.errors?.tag_id || 'Request failed', 'error');
            }
        },
        async cancelTagRequest(r) {
            if (!confirm('Cancel your request for "' + r.tag_name + '"?')) return;
            const res = await api.delete('/profile/tag-requests/' + r.id);
            if (res.ok) {
                toast('Request cancelled');
                await this.loadOrgAndTags();
            } else {
                toast(res.data?.error || 'Could not cancel', 'error');
            }
        },
        async loadNotifications() {
            const res = await api.get('/profile/notifications');
            if (!res.ok) return;
            const existing = res.data.data.prefs || [];
            const bySev = Object.fromEntries(existing.map(p => [p.severity, p]));
            this.notificationPrefs = SEVERITIES.map(sev => ({
                severity: sev,
                channel_email: bySev[sev]?.channel_email ?? 1,
                channel_sms: bySev[sev]?.channel_sms ?? (['warning', 'critical', 'evacuation'].includes(sev) ? 1 : 0),
                channel_push: bySev[sev]?.channel_push ?? (['warning', 'critical', 'evacuation'].includes(sev) ? 1 : 0),
                channel_in_app: bySev[sev]?.channel_in_app ?? 1,
            }));
        },
        async saveProfile() {
            const res = await api.put('/profile', { display_name: this.profile.display_name, timezone: this.profile.timezone });
            toast(res.ok ? 'Profile saved' : (res.data?.error || 'Failed'), res.ok ? 'success' : 'error');
            if (res.ok) await this.reloadProfile();
        },
        async changePassword() {
            if (this.passwordForm.password !== this.passwordForm.confirm) {
                toast('Passwords do not match', 'error');
                return;
            }
            this.passwordSaving = true;
            const res = await api.post('/profile/change-password', {
                current_password: this.passwordForm.current,
                password: this.passwordForm.password,
                password_confirm: this.passwordForm.confirm,
            });
            this.passwordSaving = false;
            if (res.ok) {
                toast('Password updated');
                this.passwordForm = { current: '', password: '', confirm: '' };
            } else {
                toast(res.data?.error || res.data?.errors?.password?.[0] || 'Failed', 'error');
            }
        },
        async saveNotifications() {
            const prefs = this.notificationPrefs.map(p => ({
                severity: p.severity,
                channel_email: p.channel_email == 1,
                channel_sms: p.channel_sms == 1,
                channel_push: p.channel_push == 1,
                channel_in_app: p.channel_in_app == 1,
            }));
            const res = await api.put('/profile/notifications', { prefs });
            toast(res.ok ? 'Preferences saved' : (res.data?.error || 'Failed'), res.ok ? 'success' : 'error');
        },
        async addContact() {
            const val = (this.newContact.contact_value || '').trim();
            if (!val) { toast('Enter an email or phone number', 'error'); return; }
            const res = await api.post('/profile/contacts', this.newContact);
            toast(res.ok ? 'Contact added' : (res.data?.error || res.data?.errors?.contact_value?.[0] || 'Failed'), res.ok ? 'success' : 'error');
            if (res.ok) {
                this.newContact.contact_value = '';
                await this.reloadProfile();
                if (this.newContact.channel === 'sms') {
                    toast('Request SMS opt-in from the contact list after adding your number', 'success');
                }
            }
        },
        startEditContact(c) {
            this.editingContactId = c.id;
            this.editContactValue = c.contact_value;
        },
        cancelEditContact() {
            this.editingContactId = null;
            this.editContactValue = '';
        },
        async saveEditContact(c) {
            const val = (this.editContactValue || '').trim();
            if (!val) { toast('Value required', 'error'); return; }
            const res = await api.put('/profile/contacts/' + c.id, { contact_value: val });
            if (res.ok) {
                toast(c.channel === 'sms' ? 'Phone updated — request SMS opt-in again if needed' : 'Email updated — check inbox to verify');
                this.cancelEditContact();
                await this.reloadProfile();
            } else {
                toast(res.data?.error || res.data?.errors?.contact_value?.[0] || 'Failed', 'error');
            }
        },
        async removeContact(c) {
            if (!confirm('Remove this contact?')) return;
            const res = await api.delete('/profile/contacts/' + c.id);
            if (res.ok) await this.reloadProfile();
            else toast(res.data?.error || 'Failed', 'error');
        },
        async resendVerify(c) {
            const res = await api.post('/profile/contacts/' + c.id + '/verify', {});
            toast(res.ok ? 'Verification sent' : (res.data?.error || 'Failed'), res.ok ? 'success' : 'error');
        },
        async smsOptIn(c) {
            const res = await api.post('/profile/sms-optin', { contact_id: c.id });
            if (res.ok) {
                toast('Opt-in SMS queued — reply YES to confirm subscription');
                await this.reloadProfile();
            } else {
                toast(res.data?.error || 'Failed', 'error');
            }
        },
        async ackAlert(a) {
            const res = await api.post('/alerts/' + a.id + '/ack', {});
            toast(res.ok ? 'Acknowledged' : (res.data?.error || 'Failed'), res.ok ? 'success' : 'error');
            if (res.ok) {
                const r = await api.get('/profile/alerts');
                if (r.ok) this.myAlerts = r.data.data.alerts;
            }
        }
    };
}
</script>
