<?php
/** @var string $phone */
/** @var string $app_name */
/** @var string $app_url */

$title = 'SMS Enrollment';
include __DIR__ . '/partials/header.php';
?>
<h1 style="margin:0 0 16px;font-size:22px;font-weight:700;color:#111827;">SMS alert enrollment</h1>
<p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#374151;">
    A mobile number (<strong><?= htmlspecialchars($phone) ?></strong>) was added to your <?= htmlspecialchars($app_name) ?> profile.
</p>
<p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#374151;">
    You will receive a text message shortly asking you to opt in to SMS alerts. Reply <strong>YES</strong> to confirm or <strong>NO</strong> to decline.
</p>
<div style="margin:0 0 20px;padding:16px;background-color:#fef3c7;border-radius:8px;border:1px solid #fcd34d;">
    <p style="margin:0;font-size:13px;line-height:1.6;color:#92400e;">
        <strong>Important:</strong> SMS alerts will not be sent until you reply YES. You can reply STOP at any time to unsubscribe.
    </p>
</div>
<p style="margin:0;font-size:13px;line-height:1.6;color:#6b7280;">
    If you did not request SMS alerts, contact your organization administrator or update your profile at
    <a href="<?= htmlspecialchars($app_url) ?>" style="color:#e51c1c;text-decoration:none;"><?= htmlspecialchars($app_url) ?></a>.
</p>
<?php include __DIR__ . '/partials/footer.php'; ?>
