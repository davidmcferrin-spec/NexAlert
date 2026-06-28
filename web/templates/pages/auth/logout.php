<?php
// web/templates/pages/auth/logout.php
// GET /admin/logout — clear session and return to login (breaks 401 redirect loops)
$expired = isset($_GET['expired']);
web_clear_auth_session();
session_destroy();
header('Location: /admin/login' . ($expired ? '?expired=1' : ''));
exit;
