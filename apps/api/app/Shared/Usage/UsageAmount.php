<?php

namespace App\Shared\Usage;

/**
 * Cantidades de consumo en centésimas enteras (regla 12: sin flotantes). «12.5» ⇄ 1250.
 */
final class UsageAmount
{
    public static function toCents(string|int|null $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }
        $text = trim((string) $value);
        if (preg_match('/^(-?)(\d+)(?:\.(\d{1,2})\d*)?$/', $text, $m) !== 1) {
            return 0;
        }
        $cents = ((int) $m[2]) * 100 + (int) str_pad($m[3] ?? '0', 2, '0');

        return $m[1] === '-' ? -$cents : $cents;
    }

    /** 1250 → «12.5»; 1200 → «12». */
    public static function format(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);
        $units = intdiv($cents, 100);
        $rest = $cents % 100;

        return $sign.$units.($rest === 0 ? '' : '.'.rtrim(str_pad((string) $rest, 2, '0', STR_PAD_LEFT), '0'));
    }

    /** Bytes → MB en centésimas, redondeando hacia arriba (un byte ya ocupa espacio). */
    public static function bytesToMbCents(int $bytes): int
    {
        if ($bytes <= 0) {
            return 0;
        }

        return intdiv($bytes * 100 + 1048575, 1048576);
    }
}
