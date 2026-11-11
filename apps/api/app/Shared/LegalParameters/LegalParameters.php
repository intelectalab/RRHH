<?php

namespace App\Shared\LegalParameters;

use App\Shared\Vigencia\EffectiveDated;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Regla 4 de CLAUDE.md · Parámetros legales por país y régimen con vigencia (tabla legal_parameters).
 *
 * Los módulos leen aquí plazos, topes y tasas a una fecha; ningún número legal vive en el código.
 * Un parámetro sin valor o no verificado se devuelve marcado como «por validar» (kit/docs/19).
 */
final class LegalParameters
{
    /**
     * Valor vigente a la fecha. Primero el específico del régimen; si no hay, el general del país.
     *
     * @return array{code: string, value: string|array<mixed>|null, unit: string, verified: bool, pending_validation: bool, valid_from: string, valid_to: string|null, legal_source: string|null}|null
     */
    public function at(string $country, string $code, CarbonInterface|string|null $date = null, ?string $regime = null): ?array
    {
        $day = EffectiveDated::day($date ?? now());
        $query = DB::table('legal_parameters')->where('country_code', $country)->where('code', $code)
            ->where(fn ($q) => $regime === null ? $q->whereNull('labor_regime_code') : $q->whereNull('labor_regime_code')->orWhere('labor_regime_code', $regime));
        EffectiveDated::applyAsOf($query, $day);
        /** @var \stdClass|null $row */
        $row = $query->orderByRaw('labor_regime_code IS NULL')->first();
        if ($row === null) {
            return null;
        }
        $value = $row->value_numeric !== null ? self::number((string) $row->value_numeric)
            : ($row->value_json !== null ? json_decode((string) $row->value_json, true) : null);
        $verified = (bool) $row->verified;

        return [
            'code' => (string) $row->code,
            'value' => $value,
            'unit' => (string) $row->unit,
            'verified' => $verified,
            'pending_validation' => ! $verified || $value === null,
            'valid_from' => (string) $row->valid_from,
            'valid_to' => $row->valid_to === null ? null : (string) $row->valid_to,
            'legal_source' => $row->legal_source === null ? null : (string) $row->legal_source,
        ];
    }

    /**
     * Carga el catálogo del paquete de país (packages/country-xx/data/parametros_legales_xx.json, copia de
     * kit/database/catalogos). Idempotente: inserta las vigencias que faltan y, en las existentes, solo
     * completa un valor que estaba vacío y actualiza la verificación y la fuente; nunca pisa un valor.
     *
     * @return array{inserted: int, updated: int}
     */
    public function sync(string $country): array
    {
        $file = base_path('packages/country-'.strtolower($country).'/data/parametros_legales_'.strtolower($country).'.json');
        if (! is_file($file)) {
            return ['inserted' => 0, 'updated' => 0];
        }
        /** @var array{parametros?: list<array<string, mixed>>, moneda?: string} $catalog */
        $catalog = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        $inserted = $updated = 0;

        DB::transaction(function () use ($catalog, $country, &$inserted, &$updated): void {
            foreach ($catalog['parametros'] ?? [] as $p) {
                $regime = $p['regimen'] ?? null;
                $key = DB::table('legal_parameters')->where('country_code', $p['pais'] ?? $country)->where('code', $p['codigo'])
                    ->where('valid_from', $p['vigente_desde']);
                $regime === null ? $key->whereNull('labor_regime_code') : $key->where('labor_regime_code', $regime);
                /** @var \stdClass|null $row */
                $row = $key->first();
                $values = [
                    'value_numeric' => $p['valor'] ?? null,
                    'value_json' => isset($p['valor_json']) ? json_encode($p['valor_json']) : null,
                ];
                $meta = [
                    'name' => (string) $p['nombre'],
                    'legal_source' => $p['base_legal'] ?? null,
                    'verified' => (bool) ($p['verificado'] ?? false),
                    'notes' => ($p['nota'] ?? '') === '' ? null : (string) $p['nota'],
                ];
                if ($row === null) {
                    DB::table('legal_parameters')->insert($values + $meta + [
                        'country_code' => $p['pais'] ?? $country, 'labor_regime_code' => $regime, 'code' => (string) $p['codigo'],
                        'unit' => (string) $p['unidad'], 'currency' => $p['unidad'] === 'amount' ? ($catalog['moneda'] ?? null) : null,
                        'valid_from' => $p['vigente_desde'], 'valid_to' => $p['vigente_hasta'] ?? null,
                    ]);
                    $inserted++;

                    continue;
                }
                $fill = $row->value_numeric === null && $row->value_json === null ? array_filter($values, fn ($v) => $v !== null) : [];
                $changes = array_filter($fill + $meta, fn ($v, $k) => $row->{$k} != $v, ARRAY_FILTER_USE_BOTH);
                if ($changes !== []) {
                    DB::table('legal_parameters')->where('id', $row->id)->update($changes);
                    $updated++;
                }
            }
        });

        return ['inserted' => $inserted, 'updated' => $updated];
    }

    private static function number(string $value): string
    {
        return str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
    }
}
