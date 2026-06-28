<?php

$token   = $_SESSION['access_token'];
$apiBase = web_api_base();
$id      = (int) ($_POST['id'] ?? 0);
$isEdit  = $id > 0;

$body = [
    'name'               => trim($_POST['name'] ?? ''),
    'description'        => trim($_POST['description'] ?? ''),
    'is_exclusive'       => !empty($_POST['is_exclusive']),
    'allow_self_request' => !empty($_POST['allow_self_request']),
    'requires_approval'  => !empty($_POST['requires_approval']),
];

if (!$isEdit) {
    if (!empty($_POST['slug'])) {
        $body['slug'] = strtolower(trim($_POST['slug']));
    }
    if (!empty($_POST['owner_org_id'])) {
        $body['owner_org_id'] = (int) $_POST['owner_org_id'];
    }
}

$payload = json_encode($body);
$method  = $isEdit ? 'PUT' : 'POST';
$url     = $isEdit ? "{$apiBase}/api/v1/tags/{$id}" : "{$apiBase}/api/v1/tags";

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
    flash($isEdit ? 'Tag updated.' : 'Tag created.');
    $newId = $isEdit ? $id : (int) ($res['data']['id'] ?? 0);
    header('Location: ' . ($newId ? "/admin/tags/edit?id={$newId}" : '/admin/tags'));
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
    header('Location: ' . ($isEdit ? "/admin/tags/edit?id={$id}" : '/admin/tags/new'));
}
exit;
