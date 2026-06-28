<?php
/** @var string $reset_url */
/** @var string $expires_in */
/** @var string $app_name */
/** @var string $app_url */

$title = 'Password Reset';
include __DIR__ . '/partials/header.php';
?>
<h1 style="margin:0 0 16px;font-size:22px;font-weight:700;color:#111827;">Reset your password</h1>
<p style="margin:0 0 20px;font-size:15px;line-height:1.6;color:#374151;">
    We received a request to reset the password for your <?= htmlspecialchars($app_name) ?> account.
    Click the button below to choose a new password.
</p>
<table role="presentation" cellspacing="0" cellpadding="0" style="margin:0 0 24px;">
<tr><td style="border-radius:8px;background-color:#e51c1c;">
    <a href="<?= htmlspecialchars($reset_url) ?>"
       style="display:inline-block;padding:14px 28px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;border-radius:8px;">
        Reset Password
    </a>
</td></tr>
</table>
<p style="margin:0 0 12px;font-size:13px;line-height:1.6;color:#6b7280;">
    This link expires in <?= htmlspecialchars($expires_in) ?>. If you did not request a password reset, you can safely ignore this email.
</p>
<p style="margin:0;font-size:12px;line-height:1.6;color:#9ca3af;word-break:break-all;">
    Button not working? Copy and paste this URL into your browser:<br>
    <a href="<?= htmlspecialchars($reset_url) ?>" style="color:#e51c1c;"><?= htmlspecialchars($reset_url) ?></a>
</p>
<?php include __DIR__ . '/partials/footer.php'; ?>
