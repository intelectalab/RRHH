<?php

namespace Database\Seeders;

/**
 * IMP-5 · Fechas de la organización demo, siempre relativas al día de carga: la historia termina hoy y los
 * vencimientos caen cerca sin importar cuándo se siembre.
 */
final class DemoDates
{
    /** Inicio de la historia demo (estructura, regímenes, primeras posiciones): 30 meses atrás. */
    public static function start(): string
    {
        return now()->startOfMonth()->subMonths(31)->toDateString();
    }

    /** Día $day del mes que fue hace $months meses. */
    public static function monthsAgo(int $months, int $day = 1): string
    {
        return now()->startOfMonth()->subMonths($months)->addDays(max(0, $day - 1))->toDateString();
    }

    public static function inDays(int $days): string
    {
        return now()->addDays($days)->toDateString();
    }

    public static function daysAgo(int $days): string
    {
        return now()->subDays($days)->toDateString();
    }
}
