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
        <h2 class="text-sm font-semibold mb-1">Notification preferences</h2>
        <p class="text-xs text-gray-400 mb-4">Choose which channels receive alerts at each severity level.</p>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-xs text-gray-400 uppercase">
                        <th class="text-left py-2 pr-4">Severity</th>
                        <th class="text-center py-2 px-2">Email</th>
                        <th class="text-center py-2 px-2">SMS</th>
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
        <h2 class="text-sm font-semibold mb-4">Contact methods</h2>
        <ul class="divide-y divide-gray-100 dark:divide-gray-800 mb-4">
            <template x-for="c in profile.contacts || []" :key="c.id">
                <li class="py-3 flex items-center justify-between gap-3">
                    <div>
                        <span class="text-xs uppercase text-gray-400" x-text="c.channel"></span>
                        <div class="text-sm font-mono" x-text="c.contact_value"></div>
                        <div class="text-xs text-gray-400 mt-0.5">
                            <span x-show="c.is_verified == 1" class="text-green-600">Verified</span>
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
                    <div class="flex gap-2">
                        <button x-show="c.channel === 'email' && c.is_verified != 1" @click="resendVerify(c)"
                                class="text-xs text-blue-600">Resend verify</button>
                        <button x-show="c.channel === 'sms' && c.sms_consent_status !== 'confirmed' && c.sms_consent_status !== 'stopped'"
                                @click="smsOptIn(c)"
                                class="text-xs text-green-600"
                                :title="c.sms_consent_status === 'opt_in_sent' ? 'Reply YES to the text message to confirm' : 'Send Twilio opt-in SMS'">
                            <span x-text="c.sms_consent_status === 'opt_in_sent' ? 'Resend opt-in' : 'Request SMS opt-in'"></span>
                        </button>
                        <button @click="removeContact(c)" class="text-xs text-red-500">Remove</button>
                    </div>
                </li>
            </template>
        </ul>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
            <select x-model="newContact.channel" class="px-3 py-2 text-sm rounded-xl border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800">
                <option value="email">Email</option>
                <option value="sms">SMS</option>
            </select>
            <input type="text" x-model="newContact.contact_value" placeholder="address or +1…"
                   class="px-3 py-2 text-sm rounded-xl border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 sm:col-span-2">
        </div>
        <button @click="addContact()" class="mt-3 px-4 py-2 text-sm font-semibold bg-gray-100 dark:bg-gray-800 rounded-xl">Add contact</button>
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
                    </div>
                    <button x-show="a.ack_required == 1 && a.i_acked == 0" @click="ackAlert(a)"
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
        newContact: { channel: 'email', contact_value: '' },
        passwordForm: { current: '', password: '', confirm: '' },
        passwordSaving: false,
        async init() {
            await this.reloadProfile();
            const a = await api.get('/profile/alerts?limit=20');
            if (a.ok) this.myAlerts = a.data.data.alerts;
            await this.loadNotifications();
            const params = new URLSearchParams(location.search);
            const ackId = params.get('ack_alert');
            if (ackId) this.ackAlert({ id: parseInt(ackId, 10) });
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
        async reloadProfile() {
            const p = await api.get('/profile');
            if (p.ok) this.profile = p.data.data;
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
                channel_in_app: p.channel_in_app == 1,
            }));
            const res = await api.put('/profile/notifications', { prefs });
            toast(res.ok ? 'Preferences saved' : (res.data?.error || 'Failed'), res.ok ? 'success' : 'error');
        },
        async addContact() {
            const res = await api.post('/profile/contacts', this.newContact);
            toast(res.ok ? 'Contact added' : (res.data?.error || 'Failed'), res.ok ? 'success' : 'error');
            if (res.ok) { this.newContact.contact_value = ''; await this.reloadProfile(); }
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
