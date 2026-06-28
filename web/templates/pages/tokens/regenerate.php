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
    'method'  => 'POST',
    'header'  => "Authorization: Bearer {$session}\r\nContent-Type: application/json\r\nContent-Length: 0",
    'timeout' => 10,
    'ignore_errors' => true,
]]);
$raw = @file_get_contents("{$apiBase}/api/v1/tokens/{$id}/regenerate", false, $ctx);
$res = $raw ? json_decode($raw, true) : null;

if ($res && $res['success'] && !empty($res['data']['raw_token'])) {
    $_SESSION['token_bearer_display'] = [
        'token_id' => $id,
        'raw'      => $res['data']['raw_token'],
    ];
    flash('New bearer token generated — copy it below. The previous token no longer works.');
    header("Location: /admin/tokens/edit?id={$id}");
    exit;
}

$err = $res['error'] ?? ($raw === false ? 'Regenerate failed — could not reach API.' : 'Regenerate failed.');
flash($err, 'error');
header("Location: /admin/tokens/edit?id={$id}");
exit;
