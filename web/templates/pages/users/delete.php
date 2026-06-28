<?php
$token   = $_SESSION['access_token'];
$apiBase = Env::get('APP_URL');
$id      = (int) ($_POST['id'] ?? 0);
if (!$id) { header('Location: /admin/users'); exit; }
$ctx = stream_context_create(['http' => ['method' => 'DELETE', 'header' => "Authorization: Bearer {$token}", 'timeout' => 10, 'ignore_errors' => true]]);
$raw = @file_get_contents("{$apiBase}/api/v1/users/{$id}", false, $ctx);
$res = $raw ? json_decode($raw, true) : null;
if ($res && $res['success']) { flash('User deactivated.'); } else { flash($res['error'] ?? 'Failed.', 'error'); }
header('Location: /admin/users'); exit;
