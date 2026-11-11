<?php

namespace App\Modules\Identity\Application\Mfa;

use Illuminate\Support\Carbon;

/**
 * Código de un solo uso basado en tiempo (RFC 6238, HMAC-SHA1, 6 dígitos, pasos de 30 s),
 * compatible con las aplicaciones autenticadoras habituales.
 *
 * Son parámetros del estándar, no reglas de negocio: las aplicaciones solo aceptan estos valores.
 */
final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    private const PERIOD = 30;

    private const DIGITS = 6;

    /** Pasos de tolerancia hacia atrás y adelante por desfase de reloj. */
    private const WINDOW = 1;

    public static function newSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    public static function uri(string $secret, string $issuer, string $account): string
    {
        return 'otpauth://totp/'.rawurlencode($issuer.':'.$account)
            .'?secret='.$secret.'&issuer='.rawurlencode($issuer).'&algorithm=SHA1&digits='.self::DIGITS.'&period='.self::PERIOD;
    }

    public static function stepStart(int $step): int
    {
        return $step * self::PERIOD;
    }

    public static function currentStep(?int $time = null): int
    {
        return intdiv($time ?? Carbon::now()->getTimestamp(), self::PERIOD);
    }

    /**
     * Devuelve el paso de tiempo que coincide, o null. El llamador rechaza pasos ya usados
     * (un mismo código no sirve dos veces).
     */
    public static function match(string $secret, string $code, ?int $time = null): ?int
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (preg_match('/^\d{'.self::DIGITS.'}$/', $code) !== 1) {
            return null;
        }
        $key = self::base32Decode($secret);
        $now = self::currentStep($time);
        for ($i = -self::WINDOW; $i <= self::WINDOW; $i++) {
            if (hash_equals(self::at($key, $now + $i), $code)) {
                return $now + $i;
            }
        }

        return null;
    }

    /**
     * Segundos de desfase si el código corresponde a otra hora dentro de ±$hours horas; null si no corresponde.
     * Solo para explicar un error al configurar: nunca se acepta un código fuera de la ventana.
     */
    public static function drift(string $secret, string $code, int $hours = 12, ?int $time = null): ?int
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (preg_match('/^\d{'.self::DIGITS.'}$/', $code) !== 1) {
            return null;
        }
        $key = self::base32Decode($secret);
        $now = self::currentStep($time);
        $range = intdiv($hours * 3600, self::PERIOD);
        for ($i = 1; $i <= $range; $i++) {
            foreach ([$i, -$i] as $offset) {
                if (hash_equals(self::at($key, $now + $offset), $code)) {
                    return $offset * self::PERIOD;
                }
            }
        }

        return null;
    }

    public static function at(string $key, int $step): string
    {
        $hash = hash_hmac('sha1', pack('N2', intdiv($step, 1 << 32), $step & 0xFFFFFFFF), $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24) | (ord($hash[$offset + 1]) << 16) | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);

        return str_pad((string) ($value % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    public static function base32Encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[(int) bindec(str_pad($chunk, 5, '0'))];
        }

        return $out;
    }

    public static function base32Decode(string $text): string
    {
        $text = strtoupper(rtrim($text, '='));
        $bits = '';
        foreach (str_split($text) as $c) {
            $pos = strpos(self::ALPHABET, $c);
            if ($pos === false) {
                continue;
            }
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr((int) bindec($byte));
            }
        }

        return $out;
    }
}
