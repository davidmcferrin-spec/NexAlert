#!/usr/bin/env php
<?php
/**
 * Generate VAPID key pair for Web Push (.env).
 *
 * Usage: php scripts/generate_vapid.php
 *
 * Requires OpenSSL CLI. Outputs keys in base64url format for pywebpush / browser.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$pemFile = sys_get_temp_dir() . '/nexalert_vapid_' . bin2hex(random_bytes(4)) . '.pem';
$pubFile = sys_get_temp_dir() . '/nexalert_vapid_' . bin2hex(random_bytes(4)) . '_pub.pem';

exec('openssl ecparam -name prime256v1 -genkey -noout -out ' . escapeshellarg($pemFile), $out, $code);
if ($code !== 0) {
    fwrite(STDERR, "OpenSSL failed to generate EC key.\n");
    exit(1);
}

exec(
    'openssl ec -in ' . escapeshellarg($pemFile) . ' -pubout -outform DER -out ' . escapeshellarg($pubFile) . ' 2>&1',
    $out2,
    $code2
);

$privPem = file_get_contents($pemFile);
$pubDer  = @file_get_contents($pubFile);

@unlink($pemFile);
@unlink($pubFile);

if ($privPem === false) {
    fwrite(STDERR, "Could not read private key.\n");
    exit(1);
}

preg_match('/-----BEGIN EC PRIVATE KEY-----(.*?)-----END EC PRIVATE KEY-----/s', $privPem, $m);
$der = base64_decode(str_replace(["\r", "\n", ' '], '', $m[1] ?? ''), true);

function b64url(string $bin): string
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

// Uncompressed public point is last 65 bytes of SPKI DER for P-256
$publicKey = '';
if ($pubDer !== false && strlen($pubDer) >= 65) {
    $publicKey = b64url(substr($pubDer, -65));
}

// Private key d-value: parse minimal ASN.1 or use openssl pkey export
$res = openssl_pkey_get_private($privPem);
$details = openssl_pkey_get_details($res);
$d = $details['ec']['d'] ?? '';
$privateKey = $d !== '' ? b64url($d) : '';

if ($publicKey === '' || $privateKey === '') {
    fwrite(STDERR, "Could not extract VAPID keys. Generate manually with web-push-codelab tools.\n");
    exit(1);
}

echo "Add to .env:\n\n";
echo "VAPID_PUBLIC_KEY={$publicKey}\n";
echo "VAPID_PRIVATE_KEY={$privateKey}\n";
echo "VAPID_SUBJECT=mailto:nexalert@yourdomain.com\n";
