<?php

namespace App\Shared\LegalParameters;

use App\Shared\Vigencia\EffectiveDated;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * PAR-2 · Tasas de cada administradora de pensiones por periodo (pension_fund_rates): aporte obligatorio,
 * comisión sobre flujo y mixta, prima de seguro y remuneración máxima asegurable, con la fuente oficial citada.
 * Cada carga inserta una vigencia nueva y cierra la anterior (regla 2); nunca se modifica un valor cargado.
 * Ninguna tasa vive en el código (regla 4): llegan desde la consola, por formulario o por archivo.
 */
final class PensionFundRates
{
    /** Parámetro del motor → columna de la tabla de la administradora. */
    public const PARAMETERS = [
        'afp_aporte_obligatorio_pct' => 'contribution_rate',
        'afp_comision_flujo_pct' => 'commission_flow_rate',
        'afp_comision_mixta_pct' => 'commission_mixed_rate',
        'afp_prima_seguro_pct' => 'insurance_premium_rate',
        'afp_remuneracion_maxima_asegurable' => 'max_insurable_remuneration',
    ];

    private const RATE_FIELDS = ['contribution_rate', 'commission_flow_rate', 'commission_mixed_rate', 'insurance_premium_rate'];

    public static function isFundParameter(string $code): bool
    {
        return isset(self::PARAMETERS[$code]);
    }

    /**
     * @param  array<string, mixed>  $rate
     */
    public static function valueFor(array $rate, string $code): ?string
    {
        $column = self::PARAMETERS[$code] ?? null;

        $value = $column === null ? null : ($rate[$column] ?? null);

        return $value === null ? null : (string) $value;
    }

    /**
     * Tabla vigente de una administradora a la fecha; null si no hay tabla para ese periodo (RN-PAR-04).
     *
     * @return array<string, mixed>|null
     */
    public function at(string $fundCode, string $date): ?array
    {
        $q = DB::table('pension_fund_rates AS r')->join('pension_funds AS f', 'f.id', '=', 'r.pension_fund_id')->where('f.code', $fundCode);
        EffectiveDated::applyAsOf($q, $date, 'r.valid_from', 'r.valid_to');
        $row = $q->first(['r.*', 'f.code AS fund_code', 'f.name AS fund_name']);

        return $row === null ? null : self::present($row);
    }

    /**
     * Tablas de todas las administradoras del país vigentes a la fecha; las que no tienen tabla aparecen con
     * missing=true para advertir antes de calcular.
     *
     * @return list<array<string, mixed>>
     */
    public function forDate(string $country, string $date): array
    {
        $funds = DB::table('pension_funds')->where('country_code', $country)->where('kind', 'private')->where('status', 'active')->orderBy('name')->get(['code', 'name']);
        $out = [];
        foreach ($funds as $f) {
            $rate = $this->at((string) $f->code, $date);
            $out[] = $rate ?? ['fund_code' => (string) $f->code, 'fund_name' => (string) $f->name, 'missing' => true];
        }

        return $out;
    }

    /**
     * Historial de vigencias de una administradora (más reciente primero).
     *
     * @return array<int, array<string, mixed>>
     */
    public function history(string $fundCode): array
    {
        return DB::table('pension_fund_rates AS r')->join('pension_funds AS f', 'f.id', '=', 'r.pension_fund_id')->where('f.code', $fundCode)
            ->orderByDesc('r.valid_from')->get(['r.*', 'f.code AS fund_code', 'f.name AS fund_name'])
            ->map(fn ($r): array => self::present($r))->values()->all();
    }

    /**
     * Carga la tabla de un periodo para una administradora: inserta la vigencia nueva y cierra la abierta anterior.
     * Una vigencia que empieza en o antes del inicio de la última cargada se rechaza (no se reescribe historia).
     *
     * @param  array<string, mixed>  $input  fund_code, valid_from, contribution_rate…, max_insurable_remuneration, source, verified
     * @return array<string, mixed>
     */
    public function store(array $input): array
    {
        $fund = DB::table('pension_funds')->where('code', (string) ($input['fund_code'] ?? ''))->first(['id', 'code', 'kind']);
        if ($fund === null || $fund->kind !== 'private') {
            throw ValidationException::withMessages(['fund_code' => trans('payroll.parameters.unknown_fund')]);
        }
        $from = self::date($input['valid_from'] ?? null, 'valid_from');
        $source = trim((string) ($input['source'] ?? ''));
        if ($source === '') {
            throw ValidationException::withMessages(['source' => trans('payroll.parameters.source_required')]);
        }
        $values = [];
        foreach ([...self::RATE_FIELDS, 'max_insurable_remuneration'] as $field) {
            $v = $input[$field] ?? null;
            if ($v === null || $v === '') {
                $values[$field] = null;

                continue;
            }
            if (! is_numeric($v) || (float) $v < 0 || (in_array($field, self::RATE_FIELDS, true) && (float) $v > 100)) {
                throw ValidationException::withMessages([$field => trans('payroll.parameters.invalid_rate')]);
            }
            $values[$field] = (string) $v;
        }
        if (array_filter($values, fn ($v) => $v !== null) === []) {
            throw ValidationException::withMessages(['contribution_rate' => trans('payroll.parameters.rates_required')]);
        }

        return DB::transaction(function () use ($fund, $from, $source, $values, $input): array {
            $last = DB::table('pension_fund_rates')->where('pension_fund_id', $fund->id)->orderByDesc('valid_from')->lockForUpdate()->first(['id', 'valid_from', 'valid_to']);
            if ($last !== null && substr((string) $last->valid_from, 0, 10) >= $from) {
                throw ValidationException::withMessages(['valid_from' => trans('payroll.parameters.rate_overlap')]);
            }
            if ($last !== null && ($last->valid_to === null || substr((string) $last->valid_to, 0, 10) >= $from)) {
                DB::table('pension_fund_rates')->where('id', $last->id)->update(['valid_to' => CarbonImmutable::parse($from)->subDay()->toDateString()]);
            }
            $id = (string) Str::uuid();
            DB::table('pension_fund_rates')->insert($values + ['id' => $id,
                'pension_fund_id' => $fund->id, 'currency' => $values['max_insurable_remuneration'] === null ? null : ($input['currency'] ?? 'PEN'),
                'source' => $source, 'verified' => (bool) ($input['verified'] ?? true), 'valid_from' => $from,
            ]);

            return $this->at((string) $fund->code, $from) ?? ['id' => $id];
        });
    }

    private static function date(mixed $value, string $field): string
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            throw ValidationException::withMessages([$field => trans('payroll.parameters.invalid_date')]);
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private static function present(\stdClass $r): array
    {
        $num = fn ($v): ?string => $v === null ? null : (str_contains((string) $v, '.') ? rtrim(rtrim((string) $v, '0'), '.') : (string) $v);

        return [
            'id' => (string) $r->id, 'fund_code' => (string) $r->fund_code, 'fund_name' => (string) $r->fund_name,
            'contribution_rate' => $num($r->contribution_rate), 'commission_flow_rate' => $num($r->commission_flow_rate),
            'commission_mixed_rate' => $num($r->commission_mixed_rate), 'insurance_premium_rate' => $num($r->insurance_premium_rate),
            'max_insurable_remuneration' => $num($r->max_insurable_remuneration), 'currency' => $r->currency === null ? null : (string) $r->currency,
            'source' => $r->source === null ? null : (string) $r->source, 'verified' => (bool) $r->verified,
            'valid_from' => substr((string) $r->valid_from, 0, 10), 'valid_to' => $r->valid_to === null ? null : substr((string) $r->valid_to, 0, 10),
            'missing' => false,
        ];
    }
}
