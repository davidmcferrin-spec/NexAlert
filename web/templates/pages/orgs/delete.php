<?php
// web/templates/pages/orgs/delete.php
$token   = $_SESSION['access_token'];
$apiBase = Env::get('APP_URL');
$id      = (int) ($_POST['id'] ?? 0);

if (!$id) { header('Location: /admin/orgs'); exit; }

$ctx = stream_context_create(['http' => [
    'method'  => 'DELETE',
    'header'  => "Authorization: Bearer {$token}",
    'timeout' => 10,
    'ignore_errors' => true,
]]);

$raw = @file_get_contents("{$apiBase}/api/v1/orgs/{$id}", false, $ctx);
$res = $raw ? json_decode($raw, true) : null;

if ($res && $res['success']) {
    flash('Organization deactivated.');
} else {
    flash($res['error'] ?? 'Failed to deactivate.', 'error');
}
header('Location: /admin/orgs');
exit;
