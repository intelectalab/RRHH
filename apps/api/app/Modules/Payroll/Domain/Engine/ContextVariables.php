<?php

namespace App\Modules\Payroll\Domain\Engine;

/**
 * NOM-3 · NOM-5 · Datos del contexto que una fórmula puede nombrar sin prefijo. Una fórmula que use otro
 * nombre no se puede guardar. Los textos de ayuda están en lang/{es,en,pt}/payroll.php (variables.*).
 */
final class ContextVariables
{
    /** @var array<string, string> nombre => tipo (number, date, text, boolean) */
    public const ALL = [
        'sueldo' => 'number',
        'sueldo_tramos' => 'number',
        'dias_base' => 'number',
        'dias_periodo' => 'number',
        'dias_relacion' => 'number',
        'dias_laborados' => 'number',
        'dias_remotos' => 'number',
        'dias_ausencia' => 'number',
        'dias_ausencia_pagada' => 'number',
        'dias_sin_goce' => 'number',
        'dias_cargo_empleador' => 'number',
        'dias_subsidio' => 'number',
        'horas_ausencia' => 'number',
        'fecha_inicio' => 'date',
        'fecha_fin' => 'date',
        'fecha_ingreso' => 'date',
        'fecha_cese' => 'date',
        'regimen' => 'text',
        'sistema_salud' => 'text',
        'sistema_pension' => 'text',
        'tiene_asignacion_familiar' => 'boolean',
        'tiene_seguro_riesgo' => 'boolean',
    ];

    public static function has(string $name): bool
    {
        return isset(self::ALL[$name]);
    }
}
