<?php

declare(strict_types=1);

namespace Cmdb;

final class SecretStore
{
    private const CIPHER = 'aes-256-gcm';

    public static function encrypt(string $plaintext, string $context): string
    {
        $nonce = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, Config::applicationKey(), OPENSSL_RAW_DATA, $nonce, $tag, $context, 16);
        if ($ciphertext === false) {
            throw new ApiError(503, 'A titkos SSO-beállítás nem titkosítható.');
        }
        return 'v1.'.self::b64($nonce).'.'.self::b64($tag).'.'.self::b64($ciphertext);
    }

    public static function decrypt(string $encoded, string $context): string
    {
        $parts = explode('.', $encoded);
        if (count($parts) !== 4 || $parts[0] !== 'v1') {
            throw new ApiError(503, 'A tárolt SSO-titok formátuma sérült.');
        }
        [$nonce, $tag, $ciphertext] = array_map([self::class, 'unb64'], array_slice($parts, 1));
        if (strlen($nonce) !== 12 || strlen($tag) !== 16) {
            throw new ApiError(503, 'A tárolt SSO-titok sérült.');
        }
        $plaintext = openssl_decrypt($ciphertext, self::CIPHER, Config::applicationKey(), OPENSSL_RAW_DATA, $nonce, $tag, $context);
        if ($plaintext === false) {
            throw new ApiError(503, 'A tárolt SSO-titok nem fejthető vissza. Ellenőrizd az application.key mentését.');
        }
        return $plaintext;
    }

    private static function b64(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
    private static function unb64(string $value): string
    {
        if (!preg_match('/^[A-Za-z0-9_-]*$/D', $value)) {
            throw new ApiError(503, 'A tárolt SSO-titok sérült.');
        }
        $decoded = base64_decode(strtr($value, '-_', '+/').str_repeat('=', (4 - strlen($value) % 4) % 4), true);
        if ($decoded === false) {
            throw new ApiError(503, 'A tárolt SSO-titok sérült.');
        }
        return $decoded;
    }
}
