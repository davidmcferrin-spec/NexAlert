<?php

$session = $_SESSION['access_token'];
$apiBase = web_api_base();
$id      = (int) ($_POST['id'] ?? 0);
$isEdit  = $id > 0;

$body = [
    'name'                 => trim($_POST['name'] ?? ''),
    'allowed_severity'     => $_POST['allowed_severity'] ?? [],
    'allowed_alert_types'  => $_POST['allowed_alert_types'] ?? [],
    'ip_allowlist'         => trim($_POST['ip_allowlist'] ?? ''),
];

if (!$isEdit) {
    $body['owner_org_id'] = (int) ($_POST['owner_org_id'] ?? 0);
} else {
    $body['is_active'] = !empty($_POST['is_active']);
}

$payload = json_encode($body);
$method  = $isEdit ? 'PUT' : 'POST';
$url     = $isEdit ? "{$apiBase}/api/v1/tokens/{$id}" : "{$apiBase}/api/v1/tokens";

$ctx = stream_context_create(['http' => [
    'method'  => $method,
    'header'  => "Authorization: Bearer {$session}\r\nContent-Type: application/json\r\nContent-Length: " . strlen($payload),
    'content' => $payload,
    'timeout' => 10,
    'ignore_errors' => true,
]]);
$raw = @file_get_contents($url, false, $ctx);
$res = $raw ? json_decode($raw, true) : null;

if ($res && $res['success']) {
    if (!$isEdit) {
        $newId    = (int) ($res['data']['id'] ?? 0);
        $rawToken = (string) ($res['data']['raw_token'] ?? '');
        if ($newId > 0 && $rawToken !== '') {
            $_SESSION['token_bearer_display'] = ['token_id' => $newId, 'raw' => $rawToken];
            flash('Token created — copy the bearer token on the edit page.');
            header("Location: /admin/tokens/edit?id={$newId}");
            exit;
        }
        flash('Token created but bearer value was not returned — use Regenerate on the edit page.');
        header('Location: /admin/tokens');
        exit;
    }

    flash('Token updated.');
    header("Location: /admin/tokens/edit?id={$id}");
} else {
    $err = $res['error'] ?? ($raw === false ? 'Save failed — could not reach API.' : 'Save failed.');
    if (!empty($res['errors'])) {
        $err .= ': ' . implode(', ', array_map(
            fn ($k, $v) => "{$k}: {$v}",
            array_keys($res['errors']),
            $res['errors']
        ));
    }
    flash($err, 'error');
    header('Location: ' . ($isEdit ? "/admin/tokens/edit?id={$id}" : '/admin/tokens/new'));
}
exit;
