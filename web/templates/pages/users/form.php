<?php
// web/templates/pages/users/form.php
$userId    = (int) ($_GET['id'] ?? 0);
$isEdit    = $userId > 0;
$pageTitle = $isEdit ? 'Edit User' : 'Add User';
$token     = $_SESSION['access_token'];
$apiBase   = Env::get('APP_URL');
$user      = null;

function api_call(string $method, string $url, string $token, ?array $body = null): ?array {
    $payload = $body ? json_encode($body) : null;
    $headers = "Authorization: Bearer {$token}\r\nContent-Type: application/json";
    if ($payload) $headers .= "\r\nContent-Length: " . strlen($payload);
    $ctx = stream_context_create(['http' => [
        'method'  => $method,
        'header'  => $headers,
        'content' => $payload,
        'timeout' => 5,
        'ignore_errors' => true,
    ]]);
    $raw = @file_get_contents($url, false, $ctx);
    return $raw ? json_decode($raw, true) : null;
}

// Fetch orgs for dropdown
$orgsRes = api_call('GET', "{$apiBase}/api/v1/orgs?limit=200", $token);
$orgs    = $orgsRes['data']['orgs'] ?? [];
$sessionRoles = $_SESSION['user']['roles'] ?? [];
$canManageRoles = in_array('super_admin', $sessionRoles, true) || in_array('org_admin', $sessionRoles, true);

if ($isEdit) {
    $res  = api_call('GET', "{$apiBase}/api/v1/users/{$userId}", $token);
    if (!$res || !$res['success']) {
        flash('User not found.', 'error');
        header('Location: /admin/users');
        exit;
    }
    $user = $res['data'];
}
?>

<div class="max-w-3xl" x-data="userForm()" x-init="init(<?= $isEdit ? (int)($user['home_org_id'] ?? 0) : 0 ?>)">

    <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 overflow-hidden mb-6">
        <div class="px-6 py-4 border-b border-gray-100 dark:border-gray-800">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white"><?= $pageTitle ?></h2>
        </div>

        <form method="POST" action="/admin/users/save" class="p-6 space-y-5">
            <input type="hidden" name="id" value="<?= $userId ?>">

            <div class="grid grid-cols-2 gap-5">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">First Name <span class="text-red-500">*</span></label>
                    <input type="text" name="first_name" required
                           value="<?= htmlspecialchars($user['first_name'] ?? '') ?>"
                           class="w-full px-3 py-2 text-sm rounded-xl border border-gray-300 dark:border-gray-700
                                  bg-white dark:bg-gray-800 text-gray-900 dark:text-white
                                  focus:outline-none focus:ring-2 focus:ring-red-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Last Name <span class="text-red-500">*</span></label>
                    <input type="text" name="last_name" required
                           value="<?= htmlspecialchars($user['last_name'] ?? '') ?>"
                           class="w-full px-3 py-2 text-sm rounded-xl border border-gray-300 dark:border-gray-700
                                  bg-white dark:bg-gray-800 text-gray-900 dark:text-white
                                  focus:outline-none focus:ring-2 focus:ring-red-500">
                </div>
            </div>

            <div class="grid grid-cols-2 gap-5">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Username <span class="text-red-500">*</span></label>
                    <input type="text" name="username" required
                           value="<?= htmlspecialchars($user['username'] ?? '') ?>"
                           <?= $isEdit ? 'readonly class="opacity-60"' : '' ?>
                           class="w-full px-3 py-2 text-sm rounded-xl border border-gray-300 dark:border-gray-700
                                  bg-white dark:bg-gray-800 text-gray-900 dark:text-white font-mono
                                  focus:outline-none focus:ring-2 focus:ring-red-500">
                </div>
                <?php if (!$isEdit): ?>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Password</label>
                    <input type="password" name="password"
                           placeholder="Leave blank to auto-generate"
                           class="w-full px-3 py-2 text-sm rounded-xl border border-gray-300 dark:border-gray-700
                                  bg-white dark:bg-gray-800 text-gray-900 dark:text-white
                                  focus:outline-none focus:ring-2 focus:ring-red-500">
                </div>
                <?php endif; ?>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Email</label>
                <?php if (!$isEdit): ?>
                <input type="email" name="email"
                       value="<?= htmlspecialchars(($user['contacts'] ?? [])[0]['contact_value'] ?? '') ?>"
                       class="w-full px-3 py-2 text-sm rounded-xl border border-gray-300 dark:border-gray-700
                              bg-white dark:bg-gray-800 text-gray-900 dark:text-white
                              focus:outline-none focus:ring-2 focus:ring-red-500">
                <?php else:
                    $primaryEmail = '';
                    foreach ($user['contacts'] ?? [] as $c) {
                        if (($c['channel'] ?? '') === 'email' && !empty($c['is_primary'])) {
                            $primaryEmail = $c['contact_value'] ?? '';
                            break;
                        }
                    }
                    if ($primaryEmail === '' && !empty($user['contacts'])) {
                        foreach ($user['contacts'] as $c) {
                            if (($c['channel'] ?? '') === 'email') {
                                $primaryEmail = $c['contact_value'] ?? '';
                                break;
                            }
                        }
                    }
                ?>
                <div class="text-sm font-mono text-gray-600 dark:text-gray-300 py-2">
                    <?= htmlspecialchars($primaryEmail !== '' ? $primaryEmail : '—') ?>
                </div>
                <p class="text-xs text-gray-400 mt-1">Edit email in the Contacts panel below.</p>
                <?php endif; ?>
            </div>

            <?php if (!$isEdit): ?>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                        <?= tip_label('Mobile phone', 'E.164 or US 10-digit. Creates SMS contact for alert delivery.') ?>
                    </label>
                    <input type="tel" name="phone" placeholder="+1 555 123 4567"
                           class="w-full px-3 py-2 text-sm rounded-xl border border-gray-300 dark:border-gray-700
                                  bg-white dark:bg-gray-800 text-gray-900 dark:text-white
                                  focus:outline-none focus:ring-2 focus:ring-red-500">
                </div>
                <div class="flex items-end pb-2">
                    <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300"
                           <?= tip_attr('Sends pre-notification email (if provided) and Twilio YES/STOP opt-in SMS', 'bottom') ?>>
                        <input type="checkbox" name="send_sms_optin" value="1" checked class="rounded border-gray-300">
                        Send SMS opt-in when phone added
                    </label>
                </div>
            </div>
            <?php endif; ?>

            <div class="grid grid-cols-2 gap-5">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Organization <span class="text-red-500">*</span></label>
                    <select name="home_org_id" required x-model="selectedOrgId" @change="loadNodes()"
                            class="w-full px-3 py-2 text-sm rounded-xl border border-gray-300 dark:border-gray-700
                                   bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300
                                   focus:outline-none focus:ring-2 focus:ring-red-500">
                        <option value="">Select org…</option>
                        <?php foreach ($orgs as $o): ?>
                        <option value="<?= $o['id'] ?>" <?= ($user['home_org_id'] ?? '') == $o['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($o['display_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Home Node</label>
                    <select name="home_node_id" x-model="selectedNodeId"
                            class="w-full px-3 py-2 text-sm rounded-xl border border-gray-300 dark:border-gray-700
                                   bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300
                                   focus:outline-none focus:ring-2 focus:ring-red-500"
                            :disabled="!selectedOrgId || nodesLoading">
                        <option value="">No node / org root</option>
                        <template x-for="node in nodes" :key="node.id">
                            <option :value="node.id"
                                    :selected="node.id == <?= (int)($user['home_node_id'] ?? 0) ?>"
                                    x-text="'　'.repeat(node.depth) + node.name + ' (' + node.node_type + ')'">
                            </option>
                        </template>
                    </select>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-5">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Timezone</label>
                    <select name="timezone"
                            class="w-full px-3 py-2 text-sm rounded-xl border border-gray-300 dark:border-gray-700
                                   bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300
                                   focus:outline-none focus:ring-2 focus:ring-red-500">
                        <?php
                        $tzones = ['America/New_York','America/Chicago','America/Denver','America/Los_Angeles','America/Anchorage','Pacific/Honolulu','UTC'];
                        foreach ($tzones as $tz):
                        ?>
                        <option value="<?= $tz ?>" <?= ($user['timezone'] ?? 'America/Chicago') === $tz ? 'selected' : '' ?>>
                            <?= $tz ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="flex items-center justify-between pt-2">
                <a href="/admin/users" class="text-sm text-gray-500 hover:text-gray-700 dark:hover:text-gray-300 transition-colors">
                    ← Cancel
                </a>
                <button type="submit"
                        class="px-5 py-2 bg-red-600 hover:bg-red-700 text-white text-sm font-semibold rounded-xl transition-colors">
                    <?= $isEdit ? 'Save Changes' : 'Create User' ?>
                </button>
            </div>
        </form>
    </div>

    <?php if ($isEdit && $user): ?>
    <!-- Password panel -->
    <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 overflow-hidden mb-6"
         x-data="passwordPanel(<?= $userId ?>)">
        <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-800">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Password</h3>
            <p class="text-xs text-gray-400 mt-0.5">Send a reset link to the user's email, or set a password directly.</p>
        </div>
        <div class="p-5 space-y-4">
            <div class="flex flex-wrap gap-2">
                <button @click="sendResetLink()" :disabled="resetSending"
                        class="px-4 py-2 text-sm font-semibold bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700 rounded-xl disabled:opacity-50 transition-colors">
                    <span x-text="resetSending ? 'Sending…' : 'Send reset email'"></span>
                </button>
            </div>
            <div class="border-t border-gray-100 dark:border-gray-800 pt-4">
                <p class="text-xs font-medium text-gray-500 mb-2">Or set password directly</p>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 max-w-lg">
                    <input type="password" x-model="setPassword.password" placeholder="New password (12+ chars)" minlength="12"
                           class="px-3 py-2 text-sm rounded-xl border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800">
                    <input type="password" x-model="setPassword.confirm" placeholder="Confirm password" minlength="12"
                           class="px-3 py-2 text-sm rounded-xl border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800">
                </div>
                <button @click="setPasswordDirect()" :disabled="setPasswordSaving"
                        class="mt-3 px-4 py-2 text-sm font-semibold bg-red-600 hover:bg-red-700 text-white rounded-xl disabled:opacity-50">
                    <span x-text="setPasswordSaving ? 'Saving…' : 'Set password'"></span>
                </button>
            </div>
        </div>
    </div>

    <!-- Contacts panel -->
    <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 overflow-hidden mb-6"
         x-data="contactsPanel(<?= $userId ?>)"
         x-init="contacts = <?= htmlspecialchars(json_encode($user['contacts'] ?? [], JSON_THROW_ON_ERROR), ENT_QUOTES, 'UTF-8') ?>">
        <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-800 flex items-center justify-between">
            <div>
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">
                    <?= tip_label('Contacts & SMS consent', 'Edit email/phone, mark email verified, manage SMS opt-in.') ?>
                </h3>
            </div>
            <button @click="showAdd = !showAdd" class="text-xs font-semibold text-red-600 hover:text-red-700">
                <span x-text="showAdd ? 'Cancel' : '+ Add contact'"></span>
            </button>
        </div>

        <div x-show="showAdd" x-cloak class="px-5 py-4 border-b border-gray-100 dark:border-gray-800 bg-gray-50 dark:bg-gray-800/40 space-y-3">
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <select x-model="newContact.channel"
                        class="px-3 py-2 text-sm rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800">
                    <option value="email">Email</option>
                    <option value="sms">Mobile phone (SMS)</option>
                </select>
                <input type="text" x-model="newContact.contact_value"
                       :placeholder="newContact.channel === 'sms' ? '+1 555 123 4567' : 'user@example.com'"
                       class="px-3 py-2 text-sm rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 sm:col-span-2">
            </div>
            <label x-show="newContact.channel === 'email'" class="flex items-center gap-2 text-xs text-gray-600 dark:text-gray-400">
                <input type="checkbox" x-model="newContact.mark_verified" class="rounded border-gray-300">
                Mark email verified (admin confirmed)
            </label>
            <label x-show="newContact.channel === 'sms'" class="flex items-center gap-2 text-xs text-gray-600 dark:text-gray-400">
                <input type="checkbox" x-model="newContact.send_sms_optin" checked class="rounded border-gray-300">
                Send SMS opt-in
            </label>
            <button @click="addContact()" :disabled="!newContact.contact_value.trim()"
                    class="px-4 py-2 text-sm font-semibold bg-red-600 hover:bg-red-700 disabled:opacity-50 text-white rounded-lg">
                Add contact
            </button>
        </div>

        <div class="divide-y divide-gray-100 dark:divide-gray-800">
            <template x-for="c in contacts" :key="c.id">
                <div class="px-5 py-3 gap-3">
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex-1 min-w-0">
                            <span class="text-xs uppercase text-gray-400" x-text="c.channel === 'sms' ? 'Mobile phone' : 'Email'"></span>
                            <template x-if="editingId !== c.id">
                                <div class="text-sm font-mono break-all" x-text="c.contact_value"></div>
                            </template>
                            <template x-if="editingId === c.id">
                                <input type="text" x-model="editValue"
                                       class="mt-1 w-full px-3 py-1.5 text-sm font-mono rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800">
                            </template>
                            <div class="text-xs mt-0.5">
                                <span x-show="c.is_verified == 1" class="text-green-600">Email verified</span>
                                <span x-show="c.channel === 'sms' && c.sms_consent_status"
                                      class="capitalize"
                                      :class="c.sms_consent_status === 'confirmed' ? 'text-green-600' : 'text-amber-600'"
                                      x-text="'SMS: ' + (c.sms_consent_status || 'pending')"></span>
                            </div>
                        </div>
                        <div class="flex flex-col items-end gap-1 flex-shrink-0">
                            <template x-if="editingId !== c.id">
                                <button @click="startEdit(c)" class="text-xs text-blue-600 hover:underline">Edit</button>
                            </template>
                            <template x-if="editingId === c.id">
                                <div class="flex gap-2">
                                    <button @click="saveEdit(c)" class="text-xs font-semibold text-green-600">Save</button>
                                    <button @click="cancelEdit()" class="text-xs text-gray-400">Cancel</button>
                                </div>
                            </template>
                            <label x-show="c.channel === 'email' && editingId === c.id" class="flex items-center gap-1 text-xs text-gray-500">
                                <input type="checkbox" x-model="markVerified" class="rounded border-gray-300">
                                Verified
                            </label>
                            <button x-show="c.channel === 'email' && c.is_verified != 1 && editingId !== c.id"
                                    @click="markVerifiedContact(c)"
                                    class="text-xs text-blue-600">Mark verified</button>
                            <button x-show="c.channel === 'sms' && c.sms_consent_status !== 'confirmed'"
                                    @click="resendSmsOptIn(c)"
                                    :disabled="optInSending === c.id"
                                    class="text-xs font-semibold text-green-600 hover:text-green-700 disabled:opacity-50">
                                <span x-text="optInSending === c.id ? 'Sending…' : 'Send opt-in'"></span>
                            </button>
                            <button x-show="editingId !== c.id" @click="removeContact(c)" class="text-xs text-red-400 hover:text-red-600">Remove</button>
                        </div>
                    </div>
                </div>
            </template>
            <div x-show="!contacts.length" class="px-5 py-4 text-sm text-gray-400">No contacts on file.</div>
        </div>
    </div>

    <!-- Memberships panel -->
    <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 overflow-hidden mb-6"
         x-data="membershipsPanel(<?= $userId ?>)" x-init="load()">
        <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100 dark:border-gray-800">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Org Memberships</h3>
            <button @click="showAdd = !showAdd"
                    class="text-xs font-semibold text-red-600 hover:text-red-700 dark:text-red-400">
                <span x-text="showAdd ? 'Cancel' : '+ Add Membership'"></span>
            </button>
        </div>

        <div x-show="showAdd" x-cloak class="px-5 py-4 border-b border-gray-100 dark:border-gray-800 bg-gray-50 dark:bg-gray-800/40 space-y-3">
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Organization</label>
                    <select x-model="newMembership.org_id" @change="loadMembershipNodes()"
                            class="w-full px-3 py-2 text-sm rounded-lg border border-gray-200 dark:border-gray-700
                                   bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-red-500">
                        <option value="">Select org…</option>
                        <?php foreach ($orgs as $o): ?>
                        <option value="<?= (int) $o['id'] ?>"><?= htmlspecialchars($o['display_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Org Node</label>
                    <select x-model="newMembership.org_node_id"
                            :disabled="!newMembership.org_id || nodesLoading"
                            class="w-full px-3 py-2 text-sm rounded-lg border border-gray-200 dark:border-gray-700
                                   bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-red-500 disabled:opacity-50">
                        <option value="">Select node…</option>
                        <template x-for="node in membershipNodes" :key="node.id">
                            <option :value="node.id"
                                    x-text="'　'.repeat(node.depth) + node.name + ' (' + node.node_type + ')'"></option>
                        </template>
                    </select>
                </div>
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Position Title (optional)</label>
                <input type="text" x-model="newMembership.position_title" placeholder="e.g. Chief Engineer"
                       class="w-full px-3 py-2 text-sm rounded-lg border border-gray-200 dark:border-gray-700
                              bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-red-500">
            </div>
            <button @click="addMembership()" :disabled="!newMembership.org_id || !newMembership.org_node_id"
                    class="px-4 py-2 text-sm font-semibold bg-red-600 hover:bg-red-700 disabled:opacity-50
                           text-white rounded-lg transition-colors">
                Add Membership
            </button>
        </div>

        <div class="divide-y divide-gray-100 dark:divide-gray-800">
            <template x-for="m in activeMemberships" :key="m.id">
                <div class="flex items-center justify-between px-5 py-3 gap-4">
                    <div class="min-w-0">
                        <div class="text-sm font-medium text-gray-900 dark:text-white break-words"
                             x-text="membershipLabel(m)"></div>
                        <div class="text-xs text-gray-400 mt-0.5" x-text="membershipMeta(m)"></div>
                    </div>
                    <button @click="remove(m)"
                            class="text-xs text-red-400 hover:text-red-600 transition-colors flex-shrink-0">Remove</button>
                </div>
            </template>
            <div x-show="activeMemberships.length === 0" class="px-5 py-4 text-sm text-gray-400">No org node memberships.</div>
        </div>
    </div>

    <!-- Roles panel -->
    <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 overflow-hidden mb-6"
         x-data="rolesPanel(<?= $userId ?>, <?= $canManageRoles ? 'true' : 'false' ?>, <?= (int)($user['home_org_id'] ?? 0) ?>)"
         x-init="load()">
        <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100 dark:border-gray-800">
            <div>
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Roles &amp; Permissions</h3>
                <p class="text-xs text-gray-400 mt-0.5">Assign scoped roles such as Alert Sender by org, tree, group, or tag.</p>
            </div>
            <?php if ($canManageRoles): ?>
            <button @click="showAdd = !showAdd"
                    class="text-xs font-semibold text-red-600 hover:text-red-700 dark:text-red-400">
                <span x-text="showAdd ? 'Cancel' : '+ Assign Role'"></span>
            </button>
            <?php endif; ?>
        </div>

        <?php if ($canManageRoles): ?>
        <div x-show="showAdd" x-cloak class="px-5 py-4 border-b border-gray-100 dark:border-gray-800 bg-gray-50 dark:bg-gray-800/40 space-y-3">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Role</label>
                    <select x-model="newRole.role_id"
                            class="w-full px-3 py-2 text-sm rounded-lg border border-gray-200 dark:border-gray-700
                                   bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-red-500">
                        <option value="">Select role…</option>
                        <template x-for="r in assignableRoles" :key="r.id">
                            <option :value="r.id" x-text="r.display_name + (r.name === 'sender' ? ' (can send alerts)' : '')"></option>
                        </template>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Scope</label>
                    <select x-model="newRole.scope_type" @change="onScopeTypeChange()"
                            class="w-full px-3 py-2 text-sm rounded-lg border border-gray-200 dark:border-gray-700
                                   bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-red-500">
                        <option value="org">Organization</option>
                        <option value="node">Org tree node</option>
                        <option value="group">Group</option>
                        <option value="tag">Tag</option>
                        <template x-if="isSuperAdmin">
                            <option value="global">Global (all orgs)</option>
                        </template>
                    </select>
                </div>
            </div>

            <div x-show="newRole.scope_type === 'org' || newRole.scope_type === 'node'" class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Organization</label>
                    <select x-model="newRole.org_id" @change="loadScopeNodes()"
                            class="w-full px-3 py-2 text-sm rounded-lg border border-gray-200 dark:border-gray-700
                                   bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-red-500">
                        <option value="">Select org…</option>
                        <template x-for="o in orgs" :key="o.id">
                            <option :value="o.id" x-text="o.name"></option>
                        </template>
                    </select>
                </div>
                <div x-show="newRole.scope_type === 'node'">
                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Tree node</label>
                    <select x-model="newRole.org_node_id"
                            class="w-full px-3 py-2 text-sm rounded-lg border border-gray-200 dark:border-gray-700
                                   bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-red-500">
                        <option value="">Select node…</option>
                        <template x-for="node in scopeNodes" :key="node.id">
                            <option :value="node.id"
                                    x-text="'　'.repeat(node.depth) + node.name + ' (' + node.node_type + ')'"></option>
                        </template>
                    </select>
                </div>
            </div>

            <div x-show="newRole.scope_type === 'group'">
                <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Group</label>
                <select x-model="newRole.group_id"
                        class="w-full px-3 py-2 text-sm rounded-lg border border-gray-200 dark:border-gray-700
                               bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-red-500">
                    <option value="">Select group…</option>
                    <template x-for="g in groups" :key="g.id">
                        <option :value="g.id" x-text="g.name"></option>
                    </template>
                </select>
            </div>

            <div x-show="newRole.scope_type === 'tag'">
                <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Tag</label>
                <select x-model="newRole.tag_id"
                        class="w-full px-3 py-2 text-sm rounded-lg border border-gray-200 dark:border-gray-700
                               bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-red-500">
                    <option value="">Select tag…</option>
                    <template x-for="t in tags" :key="t.id">
                        <option :value="t.id" x-text="t.name"></option>
                    </template>
                </select>
            </div>

            <button @click="assignRole()" :disabled="!canSubmitRole()"
                    class="px-4 py-2 text-sm font-semibold bg-red-600 hover:bg-red-700 disabled:opacity-50
                           text-white rounded-lg transition-colors">
                Assign Role
            </button>
        </div>
        <?php endif; ?>

        <div class="divide-y divide-gray-100 dark:divide-gray-800">
            <template x-for="r in roles" :key="r.user_role_id">
                <div class="flex items-center justify-between px-5 py-3 gap-4">
                    <div class="min-w-0">
                        <div class="text-sm font-medium text-gray-900 dark:text-white" x-text="r.display_name"></div>
                        <div class="text-xs text-gray-400 mt-0.5">
                            <span x-text="r.scope_label"></span>
                            <span x-show="r.name === 'sender'" class="ml-2 text-amber-600 dark:text-amber-400">· can send alerts</span>
                        </div>
                    </div>
                    <?php if ($canManageRoles): ?>
                    <button @click="removeRole(r)"
                            class="text-xs text-red-400 hover:text-red-600 transition-colors flex-shrink-0">Remove</button>
                    <?php endif; ?>
                </div>
            </template>
            <div x-show="roles.length === 0" class="px-5 py-4 text-sm text-gray-400">No roles assigned.</div>
        </div>
    </div>

    <!-- Tags panel -->
    <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 overflow-hidden"
         x-data="tagsPanel(<?= $userId ?>)" x-init="load()">
        <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100 dark:border-gray-800">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Tags</h3>
            <button @click="showAdd = !showAdd"
                    class="text-xs font-semibold text-red-600 hover:text-red-700 dark:text-red-400">
                <span x-text="showAdd ? 'Cancel' : '+ Assign Tag'"></span>
            </button>
        </div>

        <div x-show="showAdd" x-cloak class="px-5 py-4 border-b border-gray-100 dark:border-gray-800 bg-gray-50 dark:bg-gray-800/40">
            <div class="flex gap-2">
                <select x-model="selectedTagId"
                        class="flex-1 px-3 py-2 text-sm rounded-lg border border-gray-200 dark:border-gray-700
                               bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-red-500">
                    <option value="">Select a tag…</option>
                    <template x-for="t in availableTags" :key="t.id">
                        <option :value="t.id" x-text="t.name + (t.is_exclusive == 1 ? ' (exclusive)' : '')"></option>
                    </template>
                </select>
                <button @click="assignTag()" :disabled="!selectedTagId"
                        class="px-4 py-2 text-sm font-semibold bg-red-600 hover:bg-red-700 disabled:opacity-50
                               text-white rounded-lg transition-colors">
                    Assign
                </button>
            </div>
            <p x-show="availableTags.length === 0" class="text-xs text-gray-400 mt-2">No assignable tags found.</p>
        </div>

        <div class="px-5 py-4">
            <div class="flex flex-wrap gap-2">
                <template x-for="t in tags" :key="t.tag_id">
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-xs font-medium"
                          :class="t.is_system
                            ? 'bg-blue-50 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300'
                            : 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300'"
                          :title="assignmentLabel(t)">
                        <span x-text="t.name"></span>
                        <span class="opacity-60 text-[10px] uppercase" x-text="t.assignment_type.replace('_', ' ')"></span>
                        <button x-show="t.assignment_type === 'manual'" @click="removeTag(t)"
                                class="opacity-60 hover:opacity-100 transition-opacity">✕</button>
                    </span>
                </template>
                <span x-show="tags.length === 0" class="text-sm text-gray-400">No tags assigned.</span>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
function userForm() {
    return {
        selectedOrgId: '<?= $user !== null ? (int)($user['home_org_id'] ?? 0) : 0 ?>',
        selectedNodeId: '<?= $user !== null ? (int)($user['home_node_id'] ?? 0) : 0 ?>',
        nodes: [], nodesLoading: false,

        async init(orgId) {
            if (orgId) { this.selectedOrgId = orgId; await this.loadNodes(); }
        },

        async loadNodes() {
            if (!this.selectedOrgId) { this.nodes = []; return; }
            this.nodesLoading = true;
            const res = await api.get(`/orgs/${this.selectedOrgId}/nodes`);
            if (res.ok) this.nodes = res.data.data.nodes;
            this.nodesLoading = false;
        }
    };
}

function membershipsPanel(userId) {
    return {
        memberships: [],
        membershipNodes: [],
        nodesLoading: false,
        showAdd: false,
        newMembership: { org_id: '', org_node_id: '', position_title: '' },

        get activeMemberships() {
            return this.memberships.filter(m => m.is_active == 1);
        },

        membershipLabel(m) {
            if (m.breadcrumb) return m.breadcrumb;
            if (m.path_nodes?.length) {
                return [m.org_name, ...m.path_nodes.map(n => n.name)].join(' → ');
            }
            return `${m.org_name} → ${m.node_name}`;
        },

        membershipMeta(m) {
            const parts = [m.node_type];
            if (m.position_title) parts.push(m.position_title);
            return parts.join(' · ');
        },

        async load() {
            const res = await api.get(`/users/${userId}/memberships`);
            if (res.ok) this.memberships = res.data.data.memberships;
        },

        async loadMembershipNodes() {
            this.newMembership.org_node_id = '';
            this.membershipNodes = [];
            if (!this.newMembership.org_id) return;
            this.nodesLoading = true;
            const res = await api.get(`/orgs/${this.newMembership.org_id}/nodes`);
            if (res.ok) this.membershipNodes = res.data.data.nodes;
            this.nodesLoading = false;
        },

        async addMembership() {
            const body = {
                org_id: parseInt(this.newMembership.org_id, 10),
                org_node_id: parseInt(this.newMembership.org_node_id, 10),
            };
            if (this.newMembership.position_title.trim()) {
                body.position_title = this.newMembership.position_title.trim();
            }
            const res = await api.post(`/users/${userId}/memberships`, body);
            if (res.ok) {
                toast('Membership added');
                this.showAdd = false;
                this.newMembership = { org_id: '', org_node_id: '', position_title: '' };
                this.membershipNodes = [];
                await this.load();
            } else {
                const err = res.data.errors
                    ? Object.values(res.data.errors).join(' ')
                    : (res.data.error || 'Failed to add membership');
                toast(err, 'error');
            }
        },

        async remove(m) {
            if (!confirm(`Remove membership at ${this.membershipLabel(m)}?`)) return;
            const res = await api.delete(`/users/${userId}/memberships/${m.id}`);
            if (res.ok) { toast('Membership removed'); await this.load(); }
            else toast(res.data.error || 'Failed', 'error');
        }
    };
}

function rolesPanel(userId, canManage, defaultOrgId) {
    return {
        roles: [],
        assignableRoles: [],
        orgs: [],
        scopeNodes: [],
        groups: [],
        tags: [],
        showAdd: false,
        isSuperAdmin: <?= in_array('super_admin', $sessionRoles, true) ? 'true' : 'false' ?>,
        canManage,
        newRole: { role_id: '', scope_type: 'org', org_id: '', org_node_id: '', group_id: '', tag_id: '' },

        async load() {
            const res = await api.get(`/users/${userId}/roles`);
            if (res.ok) this.roles = res.data.data.roles;
            if (!this.canManage) return;

            const rolesRes = await api.get('/roles');
            if (rolesRes.ok) {
                this.assignableRoles = rolesRes.data.data.roles.filter(r => r.name !== 'recipient');
            }

            const orgsRes = await api.get('/orgs?limit=200');
            if (orgsRes.ok) {
                this.orgs = orgsRes.data.data.orgs;
                if (!this.newRole.org_id && defaultOrgId) {
                    this.newRole.org_id = String(defaultOrgId);
                    await this.loadScopeNodes();
                }
            }

            const groupsRes = await api.get('/groups?limit=200');
            if (groupsRes.ok) this.groups = groupsRes.data.data.groups || [];

            const tagsRes = await api.get('/tags?limit=200');
            if (tagsRes.ok) this.tags = tagsRes.data.data.tags || [];
        },

        onScopeTypeChange() {
            this.newRole.org_node_id = '';
            this.newRole.group_id = '';
            this.newRole.tag_id = '';
            if (this.newRole.scope_type === 'node') {
                this.loadScopeNodes();
            }
        },

        async loadScopeNodes() {
            this.newRole.org_node_id = '';
            this.scopeNodes = [];
            if (!this.newRole.org_id) return;
            const res = await api.get(`/orgs/${this.newRole.org_id}/nodes`);
            if (res.ok) this.scopeNodes = res.data.data.nodes;
        },

        canSubmitRole() {
            if (!this.newRole.role_id) return false;
            if (this.newRole.scope_type === 'global') return true;
            if (this.newRole.scope_type === 'org') return !!this.newRole.org_id;
            if (this.newRole.scope_type === 'node') return !!this.newRole.org_id && !!this.newRole.org_node_id;
            if (this.newRole.scope_type === 'group') return !!this.newRole.group_id;
            if (this.newRole.scope_type === 'tag') return !!this.newRole.tag_id;
            return false;
        },

        async assignRole() {
            const body = {
                role_id: parseInt(this.newRole.role_id, 10),
                scope_type: this.newRole.scope_type,
            };
            if (this.newRole.scope_type === 'org' || this.newRole.scope_type === 'node') {
                body.org_id = parseInt(this.newRole.org_id, 10);
            }
            if (this.newRole.scope_type === 'node') {
                body.org_node_id = parseInt(this.newRole.org_node_id, 10);
            }
            if (this.newRole.scope_type === 'group') {
                body.group_id = parseInt(this.newRole.group_id, 10);
            }
            if (this.newRole.scope_type === 'tag') {
                body.tag_id = parseInt(this.newRole.tag_id, 10);
            }

            const res = await api.post(`/users/${userId}/roles`, body);
            if (res.ok) {
                toast('Role assigned');
                this.showAdd = false;
                this.newRole = { role_id: '', scope_type: 'org', org_id: String(defaultOrgId || ''), org_node_id: '', group_id: '', tag_id: '' };
                await this.load();
            } else {
                toast(res.data.error || Object.values(res.data.errors || {}).join(' ') || 'Failed', 'error');
            }
        },

        async removeRole(r) {
            if (!confirm(`Remove ${r.display_name} (${r.scope_label})?`)) return;
            const res = await api.delete(`/users/${userId}/roles/${r.user_role_id}`);
            if (res.ok) { toast('Role removed'); await this.load(); }
            else toast(res.data.error || 'Failed', 'error');
        }
    };
}

function tagsPanel(userId) {
    return {
        tags: [],
        availableTags: [],
        selectedTagId: '',
        showAdd: false,

        async load() {
            const res = await api.get(`/users/${userId}/tags`);
            if (res.ok) this.tags = res.data.data.tags;
            await this.loadAvailableTags();
        },

        async loadAvailableTags() {
            const res = await api.get('/tags?assignable=1&limit=200');
            if (!res.ok) {
                toast(res.data?.error || 'Failed to load available tags', 'error');
                return;
            }
            const assigned = new Set(this.tags.map(t => t.tag_id));
            this.availableTags = res.data.data.tags.filter(t => !assigned.has(t.id));
        },

        assignmentLabel(t) {
            if (t.source_node_name) return `From node: ${t.source_node_name}`;
            return t.assignment_type;
        },

        async assignTag() {
            if (!this.selectedTagId) return;
            const res = await api.post(`/users/${userId}/tags`, { tag_id: parseInt(this.selectedTagId, 10) });
            if (res.ok) {
                toast('Tag assigned');
                this.selectedTagId = '';
                this.showAdd = false;
                await this.load();
            } else {
                toast(res.data.error || 'Failed to assign tag', 'error');
            }
        },

        async removeTag(t) {
            if (!confirm(`Remove manual tag "${t.name}"?`)) return;
            const res = await api.delete(`/users/${userId}/tags/${t.tag_id}`);
            if (res.ok) { toast('Tag removed'); await this.load(); }
            else toast(res.data.error || 'Failed', 'error');
        }
    };
}

function contactsPanel(userId) {
    return {
        contacts: [],
        optInSending: null,
        showAdd: false,
        editingId: null,
        editValue: '',
        markVerified: true,
        newContact: { channel: 'email', contact_value: '', mark_verified: true, send_sms_optin: true },

        startEdit(c) {
            this.editingId = c.id;
            this.editValue = c.contact_value;
            this.markVerified = c.is_verified == 1;
        },
        cancelEdit() {
            this.editingId = null;
            this.editValue = '';
        },
        async saveEdit(c) {
            const body = { contact_value: this.editValue.trim() };
            if (c.channel === 'email') body.mark_verified = this.markVerified;
            const res = await api.put(`/users/${userId}/contacts/${c.id}`, body);
            if (res.ok) {
                toast('Contact updated');
                this.contacts = res.data.data.contacts || [];
                this.cancelEdit();
            } else {
                toast(res.data?.error || Object.values(res.data?.errors || {}).join(' ') || 'Failed', 'error');
            }
        },
        async markVerifiedContact(c) {
            const res = await api.put(`/users/${userId}/contacts/${c.id}`, {
                contact_value: c.contact_value,
                mark_verified: true,
            });
            if (res.ok) {
                toast('Email marked verified');
                this.contacts = res.data.data.contacts || [];
            } else {
                toast(res.data?.error || 'Failed', 'error');
            }
        },
        async addContact() {
            const body = {
                channel: this.newContact.channel,
                contact_value: this.newContact.contact_value.trim(),
                is_primary: true,
            };
            if (this.newContact.channel === 'email') {
                body.mark_verified = this.newContact.mark_verified;
            } else {
                body.send_sms_optin = this.newContact.send_sms_optin;
            }
            const res = await api.post(`/users/${userId}/contacts`, body);
            if (res.ok) {
                toast('Contact added');
                this.contacts = res.data.data.contacts || [];
                this.newContact = { channel: 'email', contact_value: '', mark_verified: true, send_sms_optin: true };
                this.showAdd = false;
            } else {
                toast(res.data?.error || Object.values(res.data?.errors || {}).join(' ') || 'Failed', 'error');
            }
        },
        async removeContact(c) {
            if (!confirm(`Remove ${c.channel} contact ${c.contact_value}?`)) return;
            const res = await api.delete(`/users/${userId}/contacts/${c.id}`);
            if (res.ok) {
                toast('Contact removed');
                this.contacts = res.data.data.contacts || [];
            } else {
                toast(res.data?.error || 'Failed', 'error');
            }
        },
        async resendSmsOptIn(c) {
            this.optInSending = c.id;
            const res = await api.post(`/users/${userId}/sms-optin`, { contact_id: c.id });
            this.optInSending = null;
            if (res.ok) {
                toast('SMS opt-in queued — user should reply YES to the text');
                c.sms_consent_status = 'invite_sent';
            } else {
                toast(res.data?.error || 'Failed to queue opt-in', 'error');
            }
        }
    };
}

function passwordPanel(userId) {
    return {
        resetSending: false,
        setPasswordSaving: false,
        setPassword: { password: '', confirm: '' },
        async sendResetLink() {
            this.resetSending = true;
            const res = await api.post(`/users/${userId}/reset-password`, { action: 'send_link' });
            this.resetSending = false;
            if (res.ok) {
                toast('Reset email sent to ' + (res.data.data?.email || 'user'));
            } else {
                toast(res.data?.error || 'Failed to send reset email', 'error');
            }
        },
        async setPasswordDirect() {
            if (this.setPassword.password !== this.setPassword.confirm) {
                toast('Passwords do not match', 'error');
                return;
            }
            this.setPasswordSaving = true;
            const res = await api.post(`/users/${userId}/reset-password`, {
                action: 'set_password',
                password: this.setPassword.password,
                password_confirm: this.setPassword.confirm,
            });
            this.setPasswordSaving = false;
            if (res.ok) {
                toast('Password updated');
                this.setPassword = { password: '', confirm: '' };
            } else {
                toast(res.data?.error || res.data?.errors?.password?.[0] || 'Failed', 'error');
            }
        }
    };
}
</script>
