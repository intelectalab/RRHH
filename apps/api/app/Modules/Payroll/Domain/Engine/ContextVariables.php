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
        'dias_vacaciones' => 'number',
        'hijos_asignacion_familiar' => 'number',
        // BSO-1 a BSO-3 · Procesos de beneficios sociales (cero en los demás procesos).
        'meses_computables' => 'number',
        'dias_computables' => 'number',
        'gratificacion_ultima' => 'number',
        'utilidades_asignadas' => 'number',
        // LIQ-1 · Liquidación al cese (cero fuera de ella; remuneracion_del_mes vale 1 fuera de la liquidación).
        'remuneracion_del_mes' => 'number',
        'liq_meses_gratificacion' => 'number',
        'liq_meses_cts' => 'number',
        'liq_dias_cts' => 'number',
        'liq_gratificacion_cts' => 'number',
        'liq_meses_vacaciones' => 'number',
        'liq_dias_vacaciones' => 'number',
        'liq_dias_vacaciones_pendientes' => 'number',
        'liq_dias_vacaciones_vencidas' => 'number',
        'liq_indemnizacion' => 'number',
        'liq_plazo_fijo' => 'number',
        'liq_meses_restantes' => 'number',
        'liq_dias_restantes' => 'number',
        'liq_meses_servicio' => 'number',
        'liq_dias_servicio' => 'number',
        'fecha_inicio' => 'date',
        'fecha_fin' => 'date',
        'fecha_ingreso' => 'date',
        'fecha_cese' => 'date',
        'regimen' => 'text',
        'sistema_salud' => 'text',
        'sistema_pension' => 'text',
        'afp_comision_tipo' => 'text',
        'tiene_asignacion_familiar' => 'boolean',
        'tiene_seguro_riesgo' => 'boolean',
    ];

    public static function has(string $name): bool
    {
        return isset(self::ALL[$name]);
    }
}
