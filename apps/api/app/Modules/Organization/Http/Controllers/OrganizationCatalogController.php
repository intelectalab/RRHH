<?php

namespace App\Modules\Organization\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Catálogos de plataforma para registrar empresas (países, monedas y regímenes laborales).
 * Vienen de la base: el frontend no conoce regímenes ni monedas por su cuenta.
 */
final class OrganizationCatalogController
{
    public function __invoke(): JsonResponse
    {
        return response()->json(['data' => [
            'countries' => DB::table('countries')->orderBy('name')->get(['code', 'name', 'default_currency']),
            'currencies' => DB::table('currencies')->orderBy('code')->get(['code', 'name', 'symbol']),
            'labor_regimes' => DB::table('labor_regimes')->orderBy('country_code')->orderBy('name')->get(['code', 'country_code as country', 'name', 'kind']),
            'tax_id_labels' => collect((array) config('countries'))->map(fn ($c) => $c['tax_id_label'] ?? null),
        ]]);
    }
}
