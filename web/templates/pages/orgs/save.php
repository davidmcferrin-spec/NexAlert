<?php
// web/templates/pages/orgs/save.php
$token   = $_SESSION['access_token'];
$apiBase = Env::get('APP_URL');
$id      = (int) ($_POST['id'] ?? 0);
$isEdit  = $id > 0;

$body = array_filter([
    'name'          => trim($_POST['name'] ?? ''),
    'slug'          => strtolower(trim($_POST['slug'] ?? '')),
    'display_name'  => trim($_POST['display_name'] ?? ''),
    'primary_color' => trim($_POST['primary_color'] ?? '') ?: null,
    'logo_url'      => trim($_POST['logo_url'] ?? '') ?: null,
], fn($v) => $v !== null && $v !== '');

$payload = json_encode($body);
$method  = $isEdit ? 'PUT' : 'POST';
$url     = $isEdit ? "{$apiBase}/api/v1/orgs/{$id}" : "{$apiBase}/api/v1/orgs";

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
    flash($isEdit ? 'Organization updated.' : 'Organization created.');
    header('Location: /admin/orgs');
} else {
    $err = $res['error'] ?? 'Save failed.';
    if (!empty($res['errors'])) {
        $err .= ' ' . implode(', ', array_map(fn($k, $v) => "{$k}: {$v}", array_keys($res['errors']), $res['errors']));
    }
    flash($err, 'error');
    header('Location: ' . ($isEdit ? "/admin/orgs/edit?id={$id}" : '/admin/orgs/new'));
}
exit;
