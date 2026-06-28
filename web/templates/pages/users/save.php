<?php
// web/templates/pages/users/save.php
$token   = $_SESSION['access_token'];
$apiBase = Env::get('APP_URL');
$id      = (int) ($_POST['id'] ?? 0);
$isEdit  = $id > 0;

$body = ['first_name' => trim($_POST['first_name'] ?? ''), 'last_name' => trim($_POST['last_name'] ?? ''), 'timezone' => $_POST['timezone'] ?? 'America/Chicago'];

if (!$isEdit) {
    $body['username']    = strtolower(trim($_POST['username'] ?? ''));
    $body['home_org_id'] = (int) ($_POST['home_org_id'] ?? 0);
    if (!empty($_POST['home_node_id'])) $body['home_node_id'] = (int) $_POST['home_node_id'];
    if (!empty($_POST['email']))        $body['email']        = trim($_POST['email']);
    if (!empty($_POST['phone']))        $body['phone']        = trim($_POST['phone']);
    if (empty($_POST['send_sms_optin'])) {
        $body['send_sms_optin'] = false;
    }
    if (!empty($_POST['password']))     $body['password']     = $_POST['password'];
} else {
    if (!empty($_POST['home_node_id'])) $body['home_node_id'] = (int) $_POST['home_node_id'];
}

$payload = json_encode($body);
$method  = $isEdit ? 'PUT' : 'POST';
$url     = $isEdit ? "{$apiBase}/api/v1/users/{$id}" : "{$apiBase}/api/v1/users";

$ctx = stream_context_create(['http' => ['method' => $method, 'header' => "Authorization: Bearer {$token}\r\nContent-Type: application/json\r\nContent-Length: " . strlen($payload), 'content' => $payload, 'timeout' => 10, 'ignore_errors' => true]]);
$raw = @file_get_contents($url, false, $ctx);
$res = $raw ? json_decode($raw, true) : null;

if ($res && $res['success']) {
    flash($isEdit ? 'User updated.' : 'User created.');
    header('Location: /admin/users');
} else {
    $err = $res['error'] ?? 'Save failed.';
    if (!empty($res['errors'])) $err .= ': ' . implode(', ', array_map(fn($k,$v) => "{$k}: {$v}", array_keys($res['errors']), $res['errors']));
    flash($err, 'error');
    header('Location: ' . ($isEdit ? "/admin/users/edit?id={$id}" : '/admin/users/new'));
}
exit;
