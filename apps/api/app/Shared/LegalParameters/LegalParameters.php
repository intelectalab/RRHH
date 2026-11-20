<?php

namespace App\Shared\LegalParameters;

use App\Shared\Vigencia\EffectiveDated;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
                    $previous = $this->rowBefore($p['pais'] ?? $country, (string) $p['codigo'], $regime, (string) $p['vigente_desde']);
                    DB::table('legal_parameters')->insert($values + $meta + [
                        'country_code' => $p['pais'] ?? $country, 'labor_regime_code' => $regime, 'code' => (string) $p['codigo'],
                        'unit' => (string) $p['unidad'], 'currency' => $p['unidad'] === 'amount' ? ($catalog['moneda'] ?? null) : null,
                        'valid_from' => $p['vigente_desde'], 'valid_to' => $p['vigente_hasta'] ?? null,
                    ]);
                    $inserted++;
                    // PAR-7 · Una vigencia nueva de un parámetro que ya existía es una novedad normativa.
                    if ($previous !== null) {
                        $this->logChange($p['pais'] ?? $country, $regime, (string) $p['codigo'], (string) $p['nombre'], (string) $p['unidad'],
                            self::valueOf($previous), self::jsonValue($values), (string) $p['vigente_desde'], $p['vigente_hasta'] ?? null, $meta['legal_source'], 'country_pack', null);
                    }

                    continue;
                }
                $fill = $row->value_numeric === null && $row->value_json === null ? array_filter($values, fn ($v) => $v !== null) : [];
                $changes = array_filter($fill + $meta, fn ($v, $k) => $row->{$k} != $v, ARRAY_FILTER_USE_BOTH);
                if ($changes !== []) {
                    DB::table('legal_parameters')->where('id', $row->id)->update($changes);
                    $updated++;
                    // PAR-7 · Completar el valor de un parámetro «por validar» también se informa como novedad.
                    if ($fill !== []) {
                        $this->logChange((string) $row->country_code, $regime, (string) $row->code, (string) $p['nombre'], (string) $row->unit,
                            null, self::jsonValue($values), substr((string) $row->valid_from, 0, 10), $row->valid_to === null ? null : substr((string) $row->valid_to, 0, 10), $meta['legal_source'], 'country_pack', null);
                    }
                }
            }
        });

        return ['inserted' => $inserted, 'updated' => $updated];
    }

    /**
     * PAR-4 · Aplica un valor nuevo con vigencia (RN-PAR-02): inserta la vigencia y cierra la abierta anterior el
     * día previo; nunca modifica un valor ya cargado. Una vigencia que empieza en o antes del inicio de la última
     * se rechaza. Deja la novedad en legal_parameter_changes (PAR-7).
     *
     * @param  array{code: string, regime?: string|null, name?: string|null, unit?: string|null, value?: string|int|float|null, value_json?: mixed, valid_from: string, valid_to?: string|null, legal_source?: string|null, verified?: bool, notes?: string|null}  $change
     * @return array{id: string, previous: mixed}
     */
    public function apply(string $country, array $change, string $origin, ?string $publicationId = null): array
    {
        $regime = $change['regime'] ?? null;
        $from = (string) $change['valid_from'];
        $q = DB::table('legal_parameters')->where('country_code', $country)->where('code', $change['code']);
        $regime === null ? $q->whereNull('labor_regime_code') : $q->where('labor_regime_code', $regime);
        $last = $q->orderByDesc('valid_from')->lockForUpdate()->first();
        if ($last !== null && substr((string) $last->valid_from, 0, 10) >= $from) {
            throw new \DomainException('legal_parameter_overlap');
        }
        $name = (string) ($change['name'] ?? $last->name ?? $change['code']);
        $unit = (string) ($change['unit'] ?? $last->unit ?? 'amount');
        $previous = $this->rowBefore($country, $change['code'], $regime, $from);
        if ($last !== null && ($last->valid_to === null || substr((string) $last->valid_to, 0, 10) >= $from)) {
            DB::table('legal_parameters')->where('id', $last->id)->update(['valid_to' => CarbonImmutable::parse($from)->subDay()->toDateString()]);
        }
        $values = [
            'value_numeric' => isset($change['value']) && $change['value'] !== '' ? (string) $change['value'] : null,
            'value_json' => isset($change['value_json']) ? json_encode($change['value_json']) : null,
        ];
        $id = (string) Str::uuid();
        DB::table('legal_parameters')->insert($values + [
            'id' => $id, 'country_code' => $country, 'labor_regime_code' => $regime, 'code' => $change['code'], 'name' => $name, 'unit' => $unit,
            'currency' => $unit === 'amount' ? ($last->currency ?? null) : null,
            'valid_from' => $from, 'valid_to' => $change['valid_to'] ?? null, 'legal_source' => $change['legal_source'] ?? null,
            'verified' => (bool) ($change['verified'] ?? true), 'notes' => $change['notes'] ?? null,
        ]);
        $this->logChange($country, $regime, $change['code'], $name, $unit, $previous === null ? null : self::valueOf($previous), self::jsonValue($values),
            $from, $change['valid_to'] ?? null, $change['legal_source'] ?? null, $origin, $publicationId);

        return ['id' => $id, 'previous' => $previous === null ? null : self::valueOf($previous)];
    }

    /**
     * Todas las vigencias de un parámetro (más reciente primero), con su norma y verificación (PAR-1).
     *
     * @return array<int, array<string, mixed>>
     */
    public function history(string $country, string $code, ?string $regime): array
    {
        $q = DB::table('legal_parameters')->where('country_code', $country)->where('code', $code);
        $regime === null ? $q->whereNull('labor_regime_code') : $q->where('labor_regime_code', $regime);

        return $q->orderByDesc('valid_from')->get()->map(fn ($r): array => [
            'id' => (string) $r->id, 'value' => self::valueOf($r), 'unit' => (string) $r->unit,
            'valid_from' => substr((string) $r->valid_from, 0, 10), 'valid_to' => $r->valid_to === null ? null : substr((string) $r->valid_to, 0, 10),
            'legal_source' => $r->legal_source === null ? null : (string) $r->legal_source, 'verified' => (bool) $r->verified,
            'pending_validation' => ! (bool) $r->verified || self::valueOf($r) === null, 'notes' => $r->notes === null ? null : (string) $r->notes,
        ])->values()->all();
    }

    /**
     * Valor de una fila: número normalizado, estructura JSON o null.
     */
    public static function valueOf(\stdClass $row): mixed
    {
        if ($row->value_numeric !== null) {
            return self::number((string) $row->value_numeric);
        }

        return $row->value_json === null ? null : json_decode((string) $row->value_json, true);
    }

    private function rowBefore(string $country, string $code, ?string $regime, string $from): ?\stdClass
    {
        $q = DB::table('legal_parameters')->where('country_code', $country)->where('code', $code)->where('valid_from', '<', $from);
        $regime === null ? $q->whereNull('labor_regime_code') : $q->where('labor_regime_code', $regime);

        return $q->orderByDesc('valid_from')->first();
    }

    /**
     * @param  array{value_numeric: mixed, value_json: mixed}  $values
     */
    private static function jsonValue(array $values): mixed
    {
        if ($values['value_numeric'] !== null) {
            return self::number((string) $values['value_numeric']);
        }

        return $values['value_json'] === null ? null : json_decode((string) $values['value_json'], true);
    }

    private function logChange(string $country, ?string $regime, string $code, string $name, string $unit, mixed $previous, mixed $new, string $from, ?string $to, ?string $source, string $origin, ?string $publicationId): void
    {
        DB::table('legal_parameter_changes')->insert([
            'id' => (string) Str::uuid(), 'legal_parameter_publication_id' => $publicationId, 'country_code' => $country, 'labor_regime_code' => $regime,
            'code' => $code, 'name' => $name, 'unit' => $unit,
            'previous_value' => $previous === null ? null : json_encode($previous), 'new_value' => $new === null ? null : json_encode($new),
            'valid_from' => $from, 'valid_to' => $to, 'legal_source' => $source, 'origin' => $origin,
        ]);
    }

    private static function number(string $value): string
    {
        return str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
    }
}
