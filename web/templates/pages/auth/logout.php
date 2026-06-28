<?php
// web/templates/pages/auth/logout.php
// GET /admin/logout
session_destroy();
header('Location: /admin/login');
exit;
