<?php
/** @var string $verify_url */
/** @var string $app_name */
/** @var string $app_url */

$title = 'Verify Email';
include __DIR__ . '/partials/header.php';
?>
<h1 style="margin:0 0 16px;font-size:22px;font-weight:700;color:#111827;">Verify your email address</h1>
<p style="margin:0 0 20px;font-size:15px;line-height:1.6;color:#374151;">
    Please confirm this email address belongs to you so <?= htmlspecialchars($app_name) ?> can send alerts and account notifications to the right place.
</p>
<table role="presentation" cellspacing="0" cellpadding="0" style="margin:0 0 24px;">
<tr><td style="border-radius:8px;background-color:#e51c1c;">
    <a href="<?= htmlspecialchars($verify_url) ?>"
       style="display:inline-block;padding:14px 28px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;border-radius:8px;">
        Verify Email
    </a>
</td></tr>
</table>
<p style="margin:0 0 12px;font-size:13px;line-height:1.6;color:#6b7280;">
    If you did not add this email to your NexAlert profile, you can ignore this message.
</p>
<p style="margin:0;font-size:12px;line-height:1.6;color:#9ca3af;word-break:break-all;">
    Button not working? Copy and paste this URL into your browser:<br>
    <a href="<?= htmlspecialchars($verify_url) ?>" style="color:#e51c1c;"><?= htmlspecialchars($verify_url) ?></a>
</p>
<?php include __DIR__ . '/partials/footer.php'; ?>
