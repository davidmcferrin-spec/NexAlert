#!/usr/bin/env php
<?php
/**
 * Generate VAPID key pair for Web Push (.env).
 *
 * Output format matches web-push / pywebpush (base64url P-256 keys).
 *
 * Usage: php scripts/generate_vapid.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

function b64url(string $bin): string
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

$key = openssl_pkey_new([
    'private_key_type' => OPENSSL_KEYTYPE_EC,
    'curve_name'       => 'prime256v1',
]);

if ($key === false) {
    fwrite(STDERR, "OpenSSL failed to generate EC key.\n");
    exit(1);
}

$details = openssl_pkey_get_details($key);
if ($details === false || ($details['type'] ?? 0) !== OPENSSL_KEYTYPE_EC) {
    fwrite(STDERR, "Could not read EC key details.\n");
    exit(1);
}

$d = $details['ec']['d'] ?? '';
$x = $details['ec']['x'] ?? '';
$y = $details['ec']['y'] ?? '';

if ($d === '' || $x === '' || $y === '') {
    fwrite(STDERR, "Could not extract EC coordinates.\n");
    exit(1);
}

$d = str_pad($d, 32, "\x00", STR_PAD_LEFT);
$x = str_pad($x, 32, "\x00", STR_PAD_LEFT);
$y = str_pad($y, 32, "\x00", STR_PAD_LEFT);

$publicKey  = b64url("\x04" . $x . $y);
$privateKey = b64url($d);

$subject = 'mailto:nexalert@yourdomain.com';
$from    = getenv('MAIL_FROM_ADDRESS') ?: '';
if ($from !== '' && str_contains($from, '@') && str_contains($from, '.')) {
    $subject = 'mailto:' . $from;
}

echo "Add to .env:\n\n";
echo "VAPID_PUBLIC_KEY={$publicKey}\n";
echo "VAPID_PRIVATE_KEY={$privateKey}\n";
echo "VAPID_SUBJECT={$subject}\n";
