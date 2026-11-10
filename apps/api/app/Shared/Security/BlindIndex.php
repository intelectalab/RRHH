<?php

namespace App\Shared\Security;

/**
 * Índice ciego: permite buscar por un identificador sensible (correo, documento,
 * teléfono) sin guardarlo en claro. HMAC-SHA256 con una clave derivada de APP_KEY
 * y separada por tipo de identificador.
 */
final class BlindIndex
{
    public static function for(string $kind, string $value): string
    {
        return hash_hmac('sha256', $kind.'|'.self::normalize($kind, $value), self::key());
    }

    public static function normalize(string $kind, string $value): string
    {
        $value = trim($value);

        return match ($kind) {
            'email', 'username' => mb_strtolower($value),
            'national_id', 'phone' => (string) preg_replace('/[^0-9A-Za-z]/', '', strtoupper($value)),
            default => $value,
        };
    }

    private static function key(): string
    {
        $appKey = (string) config('app.key');
        if (str_starts_with($appKey, 'base64:')) {
            $appKey = (string) base64_decode(substr($appKey, 7), true);
        }

        return hash_hmac('sha256', 'ayni.blind-index.v1', $appKey, true);
    }
}
