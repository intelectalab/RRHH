<?php

namespace App\Modules\Vacations\Infrastructure\Setup;

use App\Modules\Vacations\Application\VacationPolicyCatalog;
use App\Modules\Vacations\Infrastructure\Models\VacationPolicy;
use App\Shared\Setup\CountryPackComponent;

/**
 * IMP-4 · Políticas de vacaciones por régimen del paquete de país (VAC-1). Nacen sin días propios: rige la
 * ley del régimen desde los parámetros legales (RN-VAC-01). Se ajustan con vigencia en Configuración →
 * Políticas de vacaciones; una política que la organización archivó o borró no se vuelve a crear.
 */
final class VacationPoliciesPackComponent implements CountryPackComponent
{
    public function __construct(private readonly VacationPolicyCatalog $catalog) {}

    public function code(): string
    {
        return 'vacation_policies';
    }

    public function moduleCode(): string
    {
        return 'time_off';
    }

    public function items(string $country): array
    {
        return array_column($this->policies($country), 'name');
    }

    public function present(string $country): int
    {
        return VacationPolicy::query()->whereIn('code', array_column($this->policies($country), 'code'))->count();
    }

    public function apply(string $country, ?string $userId): void
    {
        $existing = VacationPolicy::query()->withTrashed()->whereIn('code', array_column($this->policies($country), 'code'))->pluck('code')->all();
        foreach ($this->policies($country) as $p) {
            if (in_array($p['code'], $existing, true)) {
                continue;
            }
            $this->catalog->create(['code' => $p['code'], 'name' => $p['name'], 'labor_regime_code' => $p['regimen']], [], now()->toDateString());
        }
    }

    public function link(): string
    {
        return '/configuracion/politicas-vacaciones';
    }

    /** @return list<array{code: string, name: string, regimen: string}> */
    private function policies(string $country): array
    {
        /** @var list<array{code: string, name: string, regimen: string}> */
        return VacationPolicyCatalog::countryPackage($country)['politicas'] ?? [];
    }
}
