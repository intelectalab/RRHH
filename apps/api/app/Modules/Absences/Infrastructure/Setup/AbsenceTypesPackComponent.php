<?php

namespace App\Modules\Absences\Infrastructure\Setup;

use App\Modules\Absences\Application\AbsenceTypeCatalog;
use App\Shared\Setup\CountryPackComponent;
use Illuminate\Support\Facades\DB;

/**
 * IMP-4 · Tipos de ausencia y licencias de ley del paquete de país (AUS-1). Sus reglas se ajustan con
 * vigencia en Configuración → Tipos de ausencia.
 */
final class AbsenceTypesPackComponent implements CountryPackComponent
{
    public function __construct(private readonly AbsenceTypeCatalog $catalog) {}

    public function code(): string
    {
        return 'absence_types';
    }

    public function moduleCode(): string
    {
        return 'time_off';
    }

    public function items(string $country): array
    {
        return array_column(AbsenceTypeCatalog::countryTypes($country), 'name');
    }

    public function present(string $country): int
    {
        return DB::table('absence_types')->whereIn('code', array_column(AbsenceTypeCatalog::countryTypes($country), 'code'))->count();
    }

    public function apply(string $country, ?string $userId): void
    {
        $this->catalog->ensureCountryTypes();
    }

    public function link(): string
    {
        return '/configuracion/tipos-ausencia';
    }
}
