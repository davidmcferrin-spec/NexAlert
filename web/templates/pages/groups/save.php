<?php
use NexAlert\Config\Env;

$token   = $_SESSION['access_token'];
$apiBase = Env::get('APP_URL');
$id      = (int) ($_POST['id'] ?? 0);
$isEdit  = $id > 0;

$body = [
    'name'        => trim($_POST['name'] ?? ''),
    'description' => trim($_POST['description'] ?? ''),
];

if (!$isEdit) {
    $body['owner_org_id'] = (int) ($_POST['owner_org_id'] ?? 0);
    if (!empty($_POST['slug'])) {
        $body['slug'] = strtolower(trim($_POST['slug']));
    }
}

$payload = json_encode($body);
$method  = $isEdit ? 'PUT' : 'POST';
$url     = $isEdit ? "{$apiBase}/api/v1/groups/{$id}" : "{$apiBase}/api/v1/groups";

$ctx = stream_context_create(['http' => [
    'method'  => $method,
    'header'  => "Authorization: Bearer {$token}\r\nContent-Type: application/json\r\nContent-Length: " . strlen($payload),
    'content' => $payload,
    'timeout' => 10,
    'ignore_errors' => true,
]]);
$raw = @file_get_contents($url, false, $ctx);
$res = $raw ? json_decode($raw, true) : null;

if ($res && $res['success']) {
    flash($isEdit ? 'Group updated.' : 'Group created.');
    $newId = $isEdit ? $id : (int) ($res['data']['id'] ?? 0);
    header('Location: ' . ($newId ? "/admin/groups/edit?id={$newId}" : '/admin/groups'));
} else {
    $err = $res['error'] ?? 'Save failed.';
    if (!empty($res['errors'])) {
        $err .= ': ' . implode(', ', array_map(
            fn ($k, $v) => "{$k}: {$v}",
            array_keys($res['errors']),
            $res['errors']
        ));
    }
    flash($err, 'error');
    header('Location: ' . ($isEdit ? "/admin/groups/edit?id={$id}" : '/admin/groups/new'));
}
exit;
