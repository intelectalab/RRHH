<?php

namespace App\Modules\Organization\Domain;

/**
 * ORG-4 · RN-ORG-06 · Reparto de una relación laboral entre centros de costo.
 *
 * Los porcentajes se manejan en centésimas enteras (sin float): «33.33» → 3333. La suma debe ser
 * exactamente 10000 (100 %), sin centros repetidos y con cada línea mayor que cero.
 */
final class CostDistribution
{
    public const TOTAL = 10000;

    /**
     * Porcentaje («40», «33.33» o el número 40) a centésimas; null si no es un número de hasta dos decimales.
     */
    public static function hundredths(mixed $value): ?int
    {
        if (is_int($value)) {
            $value = (string) $value;
        } elseif (is_float($value)) {
            $value = rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
        }
        if (! is_string($value) || preg_match('/^(\d{1,3})(?:\.(\d{1,2}))?$/', trim($value), $m) !== 1) {
            return null;
        }

        return (int) $m[1] * 100 + (int) str_pad($m[2] ?? '0', 2, '0');
    }

    public static function format(int $hundredths): string
    {
        return intdiv($hundredths, 100).'.'.str_pad((string) ($hundredths % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * Errores de forma del reparto (vacío = válido).
     *
     * @param  list<array{cost_center_id: string, hundredths: int|null}>  $lines
     * @return list<string> códigos: empty, duplicate, invalid_percentage, total
     */
    public static function errors(array $lines): array
    {
        if ($lines === []) {
            return ['empty'];
        }
        $errors = [];
        $ids = array_column($lines, 'cost_center_id');
        if (count($ids) !== count(array_unique($ids))) {
            $errors[] = 'duplicate';
        }
        $total = 0;
        foreach ($lines as $line) {
            if ($line['hundredths'] === null || $line['hundredths'] <= 0 || $line['hundredths'] > self::TOTAL) {
                $errors[] = 'invalid_percentage';

                return $errors;
            }
            $total += $line['hundredths'];
        }
        if ($total !== self::TOTAL) {
            $errors[] = 'total';
        }

        return $errors;
    }
}
