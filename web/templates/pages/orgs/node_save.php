<?php
// web/templates/pages/orgs/node_save.php
// POST /admin/orgs/node/save
$token   = $_SESSION['access_token'];
$apiBase = Env::get('APP_URL');
$orgId   = (int) ($_POST['org_id'] ?? 0);
$id      = (int) ($_POST['id'] ?? 0);
$isEdit  = $id > 0;

if (!$orgId) {
    flash('Organization is required.', 'error');
    header('Location: /admin/orgs');
    exit;
}

$name     = trim($_POST['name'] ?? '');
$nodeType = trim($_POST['node_type'] ?? '');

if ($name === '' || $nodeType === '') {
    flash('Name and node type are required.', 'error');
    header('Location: /admin/orgs');
    exit;
}

if ($isEdit) {
    $body   = ['name' => $name, 'node_type' => $nodeType];
    $method = 'PUT';
    $url    = "{$apiBase}/api/v1/orgs/{$orgId}/nodes/{$id}";
} else {
    $body = ['name' => $name, 'node_type' => $nodeType];
    if (!empty($_POST['parent_id'])) {
        $body['parent_id'] = (int) $_POST['parent_id'];
    }
    $method = 'POST';
    $url    = "{$apiBase}/api/v1/orgs/{$orgId}/nodes";
}

$payload = json_encode($body);

$ctx = stream_context_create(['http' => [
    'method'        => $method,
    'header'        => "Authorization: Bearer {$token}\r\nContent-Type: application/json\r\nContent-Length: " . strlen($payload),
    'content'       => $payload,
    'timeout'       => 10,
    'ignore_errors' => true,
]]);

$raw = @file_get_contents($url, false, $ctx);
$res = $raw ? json_decode($raw, true) : null;

if ($res && $res['success']) {
    flash($isEdit ? 'Node updated.' : 'Node created.');
} else {
    $err = $res['error'] ?? 'Save failed.';
    if (!empty($res['errors'])) {
        $err .= ' ' . implode(', ', array_map(
            fn($k, $v) => "{$k}: {$v}",
            array_keys($res['errors']),
            $res['errors']
        ));
    }
    flash($err, 'error');
}

header('Location: /admin/orgs');
exit;
