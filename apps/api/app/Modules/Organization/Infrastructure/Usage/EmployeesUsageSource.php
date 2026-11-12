<?php

namespace App\Modules\Organization\Infrastructure\Usage;

use App\Shared\Usage\UsageMetrics;
use App\Shared\Usage\UsageSource;
use Illuminate\Support\Facades\DB;

/**
 * SAS-6 · RN-SAS-02 · Colaboradores con relación laboral activa. Organización ya resuelve la
 * disponibilidad de las relaciones laborales (StructureAvailability); cuando exista el módulo de
 * colaboradores (COL), esta fuente pasa a él.
 */
final class EmployeesUsageSource implements UsageSource
{
    public function metric(): string
    {
        return UsageMetrics::EMPLOYEES;
    }

    public function measure(): int
    {
        return DB::table('employments')->where('status', 'active')->whereNull('deleted_at')->count() * 100;
    }
}
