<?php

namespace App\Shared\Calendar;

use App\Shared\Permissions\EffectivePermissions;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * CFG-8 · Fuente del calendario central de RRHH. Cada módulo registra las suyas (feriados, tareas,
 * y más adelante cumpleaños, vacaciones, contratos, capacitaciones…). El calendario solo consulta
 * las fuentes cuyo módulo está contratado y cuyo permiso tiene el usuario; cada fuente aplica el
 * alcance del usuario en su propia consulta.
 */
interface CalendarSource
{
    /** Clave estable de la fuente (holidays, tasks…); la interfaz la traduce. */
    public function key(): string;

    /** Permiso del recurso de origen que además exige (el del calendario solo no basta). */
    public function permission(): string;

    /**
     * Elementos entre dos fechas laborales (inclusive). link es la ruta de la interfaz a su registro.
     *
     * @return array<int, array{id: string, title: string, subtitle: string|null, starts_on: string, ends_on: string, link: string, tone: string|null}>
     */
    public function items(CarbonImmutable $from, CarbonImmutable $to, Request $request, ?EffectivePermissions $permissions): array;
}
