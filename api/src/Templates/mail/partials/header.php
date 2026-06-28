<?php
/** @var string $title */
/** @var string $app_name */
/** @var string $app_url */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title) ?> — <?= htmlspecialchars($app_name) ?></title>
</head>
<body style="margin:0;padding:0;background-color:#f3f4f6;font-family:Inter,Segoe UI,Helvetica,Arial,sans-serif;-webkit-font-smoothing:antialiased;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color:#f3f4f6;padding:32px 16px;">
<tr><td align="center">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:560px;background-color:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e5e7eb;">
<tr><td style="background-color:#e51c1c;padding:24px 32px;text-align:center;">
    <div style="font-size:20px;font-weight:700;color:#ffffff;letter-spacing:-0.02em;"><?= htmlspecialchars($app_name) ?></div>
</td></tr>
<tr><td style="padding:32px;">
