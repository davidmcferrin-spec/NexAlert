<?php
$pageTitle = 'My Profile';

if (!web_auth()) {
    $_SESSION['redirect_after_login'] = '/profile';
    header('Location: /admin/login');
    exit;
}
?>

<div x-data="profilePage()" x-init="init()" class="space-y-6">

    <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 p-6">
        <h2 class="text-sm font-semibold mb-4">Profile</h2>
        <div class="space-y-3">
            <div>
                <label class="block text-xs text-gray-400 mb-1">Display name</label>
                <input type="text" x-model="profile.display_name"
                       class="w-full px-3 py-2 text-sm rounded-xl border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800">
            </div>
            <div>
                <label class="block text-xs text-gray-400 mb-1">Timezone</label>
                <input type="text" x-model="profile.timezone"
                       class="w-full px-3 py-2 text-sm rounded-xl border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800">
            </div>
            <button @click="saveProfile()" class="px-4 py-2 text-sm font-semibold bg-red-600 text-white rounded-xl">Save</button>
        </div>
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
                            <span x-show="c.channel === 'sms' && c.sms_consent_status" x-text="'SMS: ' + c.sms_consent_status"></span>
                        </div>
                    </div>
                    <div class="flex gap-2">
                        <button x-show="c.channel === 'email' && c.is_verified != 1" @click="resendVerify(c)"
                                class="text-xs text-blue-600">Resend verify</button>
                        <button x-show="c.channel === 'sms' && c.sms_consent_status !== 'confirmed'" @click="smsOptIn(c)"
                                class="text-xs text-green-600">SMS opt-in</button>
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
            <div class="py-3 border-b border-gray-100 dark:border-gray-800 last:border-0">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <div class="font-medium text-sm" x-text="a.subject"></div>
                        <div class="text-xs text-gray-400 mt-1" x-text="a.severity + ' · ' + a.created_at"></div>
                        <p class="text-sm text-gray-600 dark:text-gray-400 mt-2 whitespace-pre-wrap" x-text="a.body"></p>
                    </div>
                    <button x-show="a.ack_required == 1 && a.i_acked == 0" @click="ackAlert(a)"
                            class="flex-shrink-0 px-3 py-1.5 text-xs font-semibold bg-red-600 text-white rounded-lg">
                        Acknowledge
                    </button>
                    <span x-show="a.i_acked > 0" class="text-xs text-green-600">Acked</span>
                </div>
            </div>
        </template>
        <p x-show="!myAlerts.length" class="text-sm text-gray-400">No alerts yet.</p>
    </div>
</div>

<script>
function profilePage() {
    return {
        profile: {}, myAlerts: [],
        newContact: { channel: 'email', contact_value: '' },
        async init() {
            const p = await api.get('/profile');
            if (p.ok) this.profile = p.data.data;
            const a = await api.get('/profile/alerts?limit=20');
            if (a.ok) this.myAlerts = a.data.data.alerts;
            const params = new URLSearchParams(location.search);
            const ackId = params.get('ack_alert');
            if (ackId) this.ackAlert({ id: parseInt(ackId, 10) });
        },
        async saveProfile() {
            const res = await api.put('/profile', { display_name: this.profile.display_name, timezone: this.profile.timezone });
            toast(res.ok ? 'Saved' : (res.data?.error || 'Failed'), res.ok ? 'success' : 'error');
            if (res.ok) { const p = await api.get('/profile'); if (p.ok) this.profile = p.data.data; }
        },
        async addContact() {
            const res = await api.post('/profile/contacts', this.newContact);
            toast(res.ok ? 'Contact added' : (res.data?.error || 'Failed'), res.ok ? 'success' : 'error');
            if (res.ok) { this.newContact.contact_value = ''; const p = await api.get('/profile'); if (p.ok) this.profile = p.data.data; }
        },
        async removeContact(c) {
            if (!confirm('Remove this contact?')) return;
            const res = await api.delete('/profile/contacts/' + c.id);
            if (res.ok) { const p = await api.get('/profile'); if (p.ok) this.profile = p.data.data; }
        },
        async resendVerify(c) {
            const res = await api.post('/profile/contacts/' + c.id + '/verify', {});
            toast(res.ok ? 'Verification sent' : (res.data?.error || 'Failed'), res.ok ? 'success' : 'error');
        },
        async smsOptIn(c) {
            const res = await api.post('/profile/sms-optin', { contact_id: c.id });
            toast(res.ok ? 'Opt-in queued' : (res.data?.error || 'Failed'), res.ok ? 'success' : 'error');
        },
        async ackAlert(a) {
            const res = await api.post('/alerts/' + a.id + '/ack', {});
            toast(res.ok ? 'Acknowledged' : (res.data?.error || 'Failed'), res.ok ? 'success' : 'error');
            if (res.ok) { const r = await api.get('/profile/alerts'); if (r.ok) this.myAlerts = r.data.data.alerts; }
        }
    };
}
</script>
