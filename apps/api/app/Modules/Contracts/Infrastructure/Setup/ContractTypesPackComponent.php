<?php

namespace App\Modules\Contracts\Infrastructure\Setup;

use App\Modules\Contracts\Application\ContractTypeCatalog;
use App\Shared\Setup\CountryPackComponent;
use Illuminate\Support\Facades\DB;

/**
 * IMP-4 · Tipos de contrato y modalidades del paquete de país (CON-1). Son de sistema; la organización
 * agrega los suyos y elige su plantilla en Configuración → Tipos de contrato.
 */
final class ContractTypesPackComponent implements CountryPackComponent
{
    public function __construct(private readonly ContractTypeCatalog $catalog) {}

    public function code(): string
    {
        return 'contract_types';
    }

    public function moduleCode(): string
    {
        return 'core';
    }

    public function items(string $country): array
    {
        return array_column(ContractTypeCatalog::countryTypes($country), 'name');
    }

    public function present(string $country): int
    {
        return DB::table('contract_types')->whereIn('code', array_column(ContractTypeCatalog::countryTypes($country), 'code'))->count();
    }

    public function apply(string $country, ?string $userId): void
    {
        $this->catalog->ensureCountryTypes();
    }

    public function link(): string
    {
        return '/configuracion/tipos-contrato';
    }
}
