<?php

$session = $_SESSION['access_token'];
$apiBase = web_api_base();
$id      = (int) ($_POST['id'] ?? 0);

if (!$id) {
    flash('Invalid token.', 'error');
    header('Location: /admin/tokens');
    exit;
}

$ctx = stream_context_create(['http' => [
    'method'  => 'DELETE',
    'header'  => "Authorization: Bearer {$session}\r\nContent-Type: application/json",
    'timeout' => 10,
    'ignore_errors' => true,
]]);
$raw = @file_get_contents("{$apiBase}/api/v1/tokens/{$id}", false, $ctx);
$res = $raw ? json_decode($raw, true) : null;

if ($res && $res['success']) {
    flash('Token revoked.');
} else {
    flash($res['error'] ?? 'Revoke failed.', 'error');
}

header('Location: /admin/tokens');
exit;
