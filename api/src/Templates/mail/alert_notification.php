<?php
/** @var array $alert */
/** @var string|null $ack_url */
/** @var string $app_name */
/** @var string $app_url */
include __DIR__ . '/partials/header.php';

$severity = htmlspecialchars(strtoupper((string) ($alert['severity'] ?? 'INFO')));
$subject  = htmlspecialchars((string) ($alert['subject'] ?? ''));
$body     = nl2br(htmlspecialchars((string) ($alert['body'] ?? '')));
$type     = htmlspecialchars((string) ($alert['alert_type'] ?? 'simple'));
?>
<h1 style="margin:0 0 8px;font-size:20px;color:#111827;"><?= $subject ?></h1>
<p style="margin:0 0 16px;font-size:12px;color:#6b7280;">
    <?= htmlspecialchars($app_name) ?> · Severity: <strong><?= $severity ?></strong> · Type: <?= $type ?>
</p>
<div style="font-size:15px;line-height:1.6;color:#374151;margin-bottom:24px;">
    <?= $body ?>
</div>
<?php if (!empty($alert['poll_question']) && !empty($alert['poll_options'])): ?>
<p style="font-size:14px;font-weight:600;color:#111827;"><?= htmlspecialchars((string) $alert['poll_question']) ?></p>
<ul style="padding-left:20px;color:#374151;">
    <?php foreach ((array) $alert['poll_options'] as $opt): ?>
    <li><?= htmlspecialchars((string) $opt) ?></li>
    <?php endforeach; ?>
</ul>
<?php endif; ?>
<?php if (!empty($ack_url)): ?>
<p style="margin-top:24px;">
    <a href="<?= htmlspecialchars($ack_url) ?>"
       style="display:inline-block;background:#e51c1c;color:#fff;text-decoration:none;padding:12px 24px;border-radius:8px;font-weight:600;">
        Acknowledge Alert
    </a>
</p>
<?php endif; ?>
<p style="margin-top:24px;font-size:12px;color:#9ca3af;">
    <a href="<?= htmlspecialchars(rtrim($app_url, '/')) ?>/profile" style="color:#6b7280;">Manage your contact preferences</a>
</p>
<?php include __DIR__ . '/partials/footer.php'; ?>
