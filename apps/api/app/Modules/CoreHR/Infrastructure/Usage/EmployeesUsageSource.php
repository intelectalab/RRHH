<?php

namespace App\Modules\CoreHR\Infrastructure\Usage;

use App\Shared\Usage\UsageMetrics;
use App\Shared\Usage\UsageSource;
use Illuminate\Support\Facades\DB;

/**
 * SAS-6 · RN-SAS-02 · RN-COL-13 · Colaboradores con relación laboral activa. Vive en Core HR, dueño
 * de las relaciones laborales (se movió desde Organización al crearse el módulo COL).
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
