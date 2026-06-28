<?php
use NexAlert\Config\Env;

if (empty($_FILES['csv']) || $_FILES['csv']['error'] !== UPLOAD_ERR_OK) {
    flash('Please select a valid CSV file.', 'error');
    header('Location: /admin/users/import');
    exit;
}

$token   = $_SESSION['access_token'];
$apiBase = Env::get('APP_URL');
$orgId   = (int) ($_POST['org_id'] ?? 0);

$ch = curl_init("{$apiBase}/api/v1/users/import");
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$token}"],
    CURLOPT_POSTFIELDS     => [
        'org_id' => $orgId,
        'csv'    => new CURLFile(
            $_FILES['csv']['tmp_name'],
            $_FILES['csv']['type'] ?: 'text/csv',
            $_FILES['csv']['name']
        ),
    ],
    CURLOPT_TIMEOUT => 120,
]);

$raw  = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$res = $raw ? json_decode($raw, true) : null;

if ($res && ($res['success'] ?? false)) {
    $_SESSION['import_results'] = $res['data'];
    flash($res['message'] ?? 'Import complete.');
} else {
    $err = $res['error'] ?? 'Import failed.';
    if (!empty($res['errors'])) {
        $err .= ': ' . implode(', ', array_map(
            fn ($k, $v) => "{$k}: {$v}",
            array_keys($res['errors']),
            $res['errors']
        ));
    }
    flash($err, 'error');
}

header('Location: /admin/users/import');
exit;
