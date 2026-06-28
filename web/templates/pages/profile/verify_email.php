<?php
$pageTitle = 'Verify Email';
$token = trim($_GET['token'] ?? '');
$message = null;
$error = null;

if ($token !== '') {
    $apiBase = web_api_base();
    $url = $apiBase . '/api/v1/profile/verify-email?token=' . urlencode($token);
    $raw = @file_get_contents($url);
    $res = $raw ? json_decode($raw, true) : null;
    if ($res && ($res['success'] ?? false)) {
        $message = $res['message'] ?? 'Email verified successfully.';
    } else {
        $error = $res['error'] ?? 'Verification failed.';
    }
} else {
    $error = 'Missing verification token.';
}
?>

<div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 p-8 text-center max-w-md mx-auto">
    <?php if ($message): ?>
    <div class="text-green-600 text-lg font-semibold mb-2">✓ Verified</div>
    <p class="text-sm text-gray-600 dark:text-gray-400 mb-6"><?= htmlspecialchars($message) ?></p>
    <?php else: ?>
    <div class="text-red-600 text-lg font-semibold mb-2">Verification failed</div>
    <p class="text-sm text-gray-600 dark:text-gray-400 mb-6"><?= htmlspecialchars($error ?? '') ?></p>
    <?php endif; ?>
    <a href="/profile" class="text-sm text-red-600 hover:underline">Go to my profile</a>
</div>
