<?php

declare(strict_types=1);

namespace App\Payroll\Calculation;

use DateTimeImmutable;

/**
 * Conteo de tiempo de servicios entre dos fechas, ambas incluidas. Solo es aritmética de calendario:
 * cuántos días vale el mes (dias_base_mes) lo decide el parámetro, no esta clase.
 */
final class ServiceTime
{
    /** Días calendario entre dos fechas, ambas incluidas. */
    public static function days(DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        return $from > $to ? 0 : (int) $from->diff($to)->days + 1;
    }

    /**
     * Meses completos contados desde la fecha de inicio y días sueltos que sobran.
     * Del 1 de julio al 20 de noviembre: 4 meses y 20 días.
     *
     * @return array{months: int, days: int}
     */
    public static function monthsAndDays(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        if ($from > $to) {
            return ['months' => 0, 'days' => 0];
        }
        $end = $to->modify('+1 day');
        $months = 0;
        while (self::addMonths($from, $months + 1) <= $end) {
            $months++;
        }

        return ['months' => $months, 'days' => (int) self::addMonths($from, $months)->diff($end)->days];
    }

    /** Meses calendario completos dentro del rango: del 15 de marzo al 20 de junio cuenta abril y mayo. */
    public static function fullCalendarMonths(DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        $count = 0;
        for ($m = $from->modify('first day of this month'); $m <= $to; $m = $m->modify('first day of next month')) {
            if ($m >= $from && $m->modify('last day of this month') <= $to) {
                $count++;
            }
        }

        return $count;
    }

    /** Suma meses sin desbordar: 31 de enero + 1 mes = 28 o 29 de febrero. */
    public static function addMonths(DateTimeImmutable $date, int $months): DateTimeImmutable
    {
        $first = $date->modify('first day of this month')->modify("+{$months} months");
        $day = min((int) $date->format('j'), (int) $first->format('t'));

        return $first->setDate((int) $first->format('Y'), (int) $first->format('n'), $day);
    }

    /** Último aniversario de la fecha de ingreso que no pasa de la fecha dada. */
    public static function lastAnniversary(DateTimeImmutable $hire, DateTimeImmutable $at): DateTimeImmutable
    {
        $years = (int) $hire->diff($at)->y;

        return self::addMonths($hire, 12 * $years);
    }
}
