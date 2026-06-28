<?php
// web/templates/pages/auth/login_post.php
// POST /admin/login

$username = trim((string) ($_POST['username'] ?? ''));
$password = (string) ($_POST['password'] ?? '');

if ($username === '' || $password === '') {
    $_SESSION['login_error'] = 'Username and password are required.';
    header('Location: /admin/login');
    exit;
}

// Call our own API login endpoint
$payload = json_encode(['username' => $username, 'password' => $password]);

$ctx = stream_context_create([
    'http' => [
        'method'  => 'POST',
        'header'  => "Content-Type: application/json\r\nContent-Length: " . strlen($payload),
        'content' => $payload,
        'timeout' => 10,
        'ignore_errors' => true,
    ],
    'ssl' => [
        'verify_peer'      => true,
        'verify_peer_name' => true,
    ],
]);

// Use loopback to avoid external DNS round-trip when on same server
$apiBase = Env::get('APP_URL', 'https://nexalert.area51consulting.com');
$raw     = @file_get_contents("{$apiBase}/api/v1/auth/login", false, $ctx);
$result  = $raw ? json_decode($raw, true) : null;

if (!$result || !($result['success'] ?? false)) {
    $msg = $result['error'] ?? 'Login failed. Please check your credentials.';
    $_SESSION['login_error'] = $msg;
    header('Location: /admin/login');
    exit;
}

// Store session
$_SESSION['access_token']  = $result['data']['access_token'];
$_SESSION['refresh_token'] = $result['data']['refresh_token'];
$_SESSION['token_expires'] = time() + ($result['data']['expires_in'] ?? 28800);
$_SESSION['user']          = $result['data']['user'];

$redirect = $_SESSION['redirect_after_login'] ?? '/admin';
unset($_SESSION['redirect_after_login']);

header('Location: ' . $redirect);
exit;
