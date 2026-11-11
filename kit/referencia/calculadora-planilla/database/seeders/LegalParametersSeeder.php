<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\LaborRegime;
use App\Models\LegalParameter;
use Illuminate\Database\Seeder;

/**
 * Carga los regímenes y los parámetros legales del Perú desde el catálogo del kit
 * (kit/database/catalogos/parametros_legales_pe.json). Los parámetros sin verificar se cargan sin
 * valor: la calculadora los reporta como error hasta que el especialista los complete (docs/19).
 */
final class LegalParametersSeeder extends Seeder
{
    public function run(): void
    {
        $catalog = json_decode((string) file_get_contents((string) config('payroll.catalog_path')), true, flags: JSON_THROW_ON_ERROR);

        foreach ($catalog['regimenes'] as $r) {
            LaborRegime::query()->updateOrCreate(['code' => $r['codigo']], ['country_code' => $catalog['pais'], 'name' => $r['nombre'], 'kind' => $r['tipo']]);
        }

        foreach ($catalog['parametros'] as $p) {
            LegalParameter::query()->updateOrCreate(
                ['country_code' => $p['pais'], 'labor_regime_code' => $p['regimen'], 'code' => $p['codigo'], 'valid_from' => $p['vigente_desde']],
                [
                    'name' => $p['nombre'],
                    'value_numeric' => is_numeric($p['valor']) ? $p['valor'] : null,
                    'value_json' => $p['valor_json'],
                    'unit' => $p['unidad'],
                    'currency' => $p['unidad'] === 'amount' ? $catalog['moneda'] : null,
                    'valid_to' => $p['vigente_hasta'],
                    'legal_source' => $p['base_legal'],
                    'verified' => $p['verificado'],
                    'notes' => $p['nota'] ?: null,
                ],
            );
        }
    }
}
