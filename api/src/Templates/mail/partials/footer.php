<?php
/** @var string $app_name */
/** @var string $app_url */
?>
</td></tr>
<tr><td style="padding:20px 32px;background-color:#f9fafb;border-top:1px solid #e5e7eb;">
    <p style="margin:0 0 8px;font-size:12px;line-height:1.5;color:#6b7280;text-align:center;">
        This message was sent by <?= htmlspecialchars($app_name) ?>.
    </p>
    <p style="margin:0;font-size:12px;line-height:1.5;color:#9ca3af;text-align:center;">
        <a href="<?= htmlspecialchars($app_url) ?>" style="color:#e51c1c;text-decoration:none;"><?= htmlspecialchars($app_url) ?></a>
    </p>
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>
