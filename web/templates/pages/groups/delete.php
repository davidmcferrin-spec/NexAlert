<?php
use NexAlert\Config\Env;

$token   = $_SESSION['access_token'];
$apiBase = Env::get('APP_URL');
$id      = (int) ($_POST['id'] ?? 0);

if (!$id) {
    flash('Invalid group.', 'error');
    header('Location: /admin/groups');
    exit;
}

$ctx = stream_context_create(['http' => [
    'method'  => 'DELETE',
    'header'  => "Authorization: Bearer {$token}\r\nContent-Type: application/json",
    'timeout' => 10,
    'ignore_errors' => true,
]]);
$raw = @file_get_contents("{$apiBase}/api/v1/groups/{$id}", false, $ctx);
$res = $raw ? json_decode($raw, true) : null;

if ($res && $res['success']) {
    flash('Group deactivated.');
} else {
    flash($res['error'] ?? 'Delete failed.', 'error');
}

header('Location: /admin/groups');
exit;
