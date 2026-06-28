<?php
$pageTitle = 'Poll Response';
$alertId = (int) ($_GET['alert_id'] ?? 0);
$userId  = (int) ($_GET['user_id'] ?? 0);
$option  = trim($_GET['option'] ?? '');
$sig     = trim($_GET['sig'] ?? '');
$message = null;
$error   = null;
$results = null;

if ($alertId > 0 && $userId > 0 && $option !== '' && $sig !== '') {
    $apiBase = web_api_base();
    $url = $apiBase . '/api/v1/poll/vote?' . http_build_query([
        'alert_id' => $alertId,
        'user_id'  => $userId,
        'option'   => $option,
        'sig'      => $sig,
    ]);
    $raw = @file_get_contents($url);
    $res = $raw ? json_decode($raw, true) : null;
    if ($res && ($res['success'] ?? false)) {
        $message = $res['message'] ?? 'Your response has been recorded.';
        $results = $res['data'] ?? null;
    } else {
        $error = $res['error'] ?? 'Unable to record your vote.';
    }
} else {
    $error = 'Invalid or incomplete vote link.';
}
?>

<div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 p-8 max-w-md mx-auto">
    <?php if ($message): ?>
    <div class="text-center mb-6">
        <div class="text-green-600 text-lg font-semibold mb-2">✓ Vote recorded</div>
        <p class="text-sm text-gray-600 dark:text-gray-400"><?= htmlspecialchars($message) ?></p>
        <?php if (!empty($results['poll_question'])): ?>
        <p class="text-sm font-medium text-gray-800 dark:text-gray-200 mt-4">
            <?= htmlspecialchars((string) $results['poll_question']) ?>
        </p>
        <?php endif; ?>
    </div>
    <?php if (!empty($results['options'])): ?>
    <ul class="text-sm space-y-2 mb-6">
        <?php foreach ($results['options'] as $opt): ?>
        <li class="flex justify-between gap-2 px-3 py-2 rounded-lg bg-gray-50 dark:bg-gray-800">
            <span><?= htmlspecialchars((string) ($opt['option'] ?? '')) ?></span>
            <span class="text-gray-500"><?= (int) ($opt['count'] ?? 0) ?> (<?= htmlspecialchars((string) ($opt['percentage'] ?? 0)) ?>%)</span>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
    <?php else: ?>
    <div class="text-center mb-6">
        <div class="text-red-600 text-lg font-semibold mb-2">Vote not recorded</div>
        <p class="text-sm text-gray-600 dark:text-gray-400"><?= htmlspecialchars($error ?? '') ?></p>
    </div>
    <?php endif; ?>
    <div class="text-center">
        <a href="/profile" class="text-sm text-red-600 hover:underline">Go to my profile</a>
    </div>
</div>
