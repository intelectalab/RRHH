<?php

namespace App\Modules\Vacations\Domain;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateTimeImmutable;

/**
 * VAC-2 · Aritmética pura del año vacacional (sin base ni framework).
 *
 * - Los periodos van de aniversario a aniversario desde la fecha ancla de la relación (ingreso o
 *   antigüedad reconocida, según el paquete de país): [ancla + n años, ancla + (n+1) años − 1 día].
 * - Los días de un periodo se prorratean por tramo: si en el año cambian el régimen o la política,
 *   cada tramo aporta (días del tramo ÷ días del periodo) × días por año de ese tramo.
 * - El devengo en curso (para truncas y adelantos) es la parte proporcional de lo que corresponde.
 */
final class VacationYear
{
    /**
     * Periodos desde el ancla que ya empezaron a la fecha $until (incluido el que está en curso).
     *
     * @return list<array{start: string, end: string}>
     */
    public static function periods(string $anchor, string $until): array
    {
        $start = new DateTimeImmutable($anchor);
        $limit = new DateTimeImmutable($until);
        $out = [];
        for ($n = 0; $n < 200; $n++) {
            $from = self::addYears($start, $n);
            if ($from > $limit) {
                break;
            }
            $to = self::addYears($start, $n + 1)->modify('-1 day');
            $out[] = ['start' => $from->format('Y-m-d'), 'end' => $to->format('Y-m-d')];
        }

        return $out;
    }

    /**
     * Días que corresponden al periodo, prorrateados por tramos. Un tramo con días por año nulo
     * («por validar») aporta cero y se informa aparte.
     *
     * @param  list<array{from: string, to: string, days_per_year: string|null}>  $segments  tramos dentro del periodo, sin solapes
     * @return array{days: string, pending: bool}
     */
    public static function entitlement(string $periodStart, string $periodEnd, array $segments): array
    {
        $total = self::days($periodStart, $periodEnd);
        $sum = BigDecimal::zero();
        $pending = false;
        foreach ($segments as $s) {
            $from = max($s['from'], $periodStart);
            $to = min($s['to'], $periodEnd);
            if ($from > $to) {
                continue;
            }
            if ($s['days_per_year'] === null) {
                $pending = true;

                continue;
            }
            $sum = $sum->plus(BigDecimal::of($s['days_per_year'])->multipliedBy(self::days($from, $to))->dividedBy($total, 6, RoundingMode::HALF_UP));
        }

        return ['days' => (string) $sum->toScale(2, RoundingMode::HALF_UP), 'pending' => $pending];
    }

    /**
     * Parte devengada a la fecha de lo que corresponde al periodo (días transcurridos ÷ días del periodo).
     */
    public static function accrued(string $periodStart, string $periodEnd, string $entitled, string $asOf): string
    {
        if ($asOf < $periodStart) {
            return '0.00';
        }
        $elapsed = self::days($periodStart, min($asOf, $periodEnd));

        return (string) BigDecimal::of($entitled)->multipliedBy($elapsed)
            ->dividedBy(self::days($periodStart, $periodEnd), 2, RoundingMode::DOWN);
    }

    /** Fecha límite de goce: el periodo se gana al día siguiente de su fin y se goza dentro de los meses del plazo. */
    public static function enjoyBy(string $periodEnd, int $months): string
    {
        return (new DateTimeImmutable($periodEnd))->modify('+'.$months.' months')->format('Y-m-d');
    }

    /** Días entre dos fechas, ambas incluidas. */
    public static function days(string $from, string $to): int
    {
        return (int) (new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days + 1;
    }

    /** Suma años conservando el día; el 29 de febrero cae al 28 en años no bisiestos. */
    private static function addYears(DateTimeImmutable $date, int $years): DateTimeImmutable
    {
        $y = (int) $date->format('Y') + $years;
        $m = (int) $date->format('n');
        $d = (int) $date->format('j');
        $last = (int) (new DateTimeImmutable(sprintf('%04d-%02d-01', $y, $m)))->format('t');

        return new DateTimeImmutable(sprintf('%04d-%02d-%02d', $y, $m, min($d, $last)));
    }
}
