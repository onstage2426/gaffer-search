<?php

declare(strict_types=1);

namespace GafferSearch\Support;

/** @internal Not part of the public API (see API.md): may change in any version. */
class Token
{
    /** @param array<string, mixed> $payload */
    public static function sign(array $payload, string $secret): string
    {
        $encoded = rtrim(strtr(base64_encode( (string) json_encode($payload)), '+/', '-_'), '='); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode,WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
        return $encoded . '.' . hash_hmac('sha256', $encoded, $secret);
    }

    /**
     * The payload WITHOUT checking the signature — only for choosing which secret to verify
     * with (the token's site). Never act on anything else in it before verify().
     * @return array<string, mixed>|null
     */
    public static function claims(string $token): ?array
    {
        $dot  = strpos($token, '.');
        $json = base64_decode(strtr(substr($token, 0, $dot === false ? 0 : $dot), '-_', '+/')); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
        $data = $dot === false ? null : json_decode($json, true);
        return is_array($data) ? $data : null;
    }

    /** @return array<string, mixed>|null The payload; null when the signature doesn't match. */
    public static function verify(string $token, string $secret): ?array
    {
        $dot = strpos($token, '.');
        if ($dot === false) {
            return null;
        }
        $payload = substr($token, 0, $dot);
        $sig     = substr($token, $dot + 1);
        if (!hash_equals(hash_hmac('sha256', $payload, $secret), $sig)) {
            return null;
        }
        $json = base64_decode(strtr($payload, '-_', '+/')); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
        $data = json_decode($json, true);
        return is_array($data) ? $data : null;
    }
}
