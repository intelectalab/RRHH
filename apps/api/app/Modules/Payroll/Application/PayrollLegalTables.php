<?php

namespace App\Modules\Payroll\Application;

use App\Modules\Payroll\Infrastructure\Country\DataPayrollCountryPack;
use App\Modules\Payroll\Infrastructure\PayrollParameters;
use App\Shared\Audit\AuditLogger;
use App\Shared\Exceptions\AyniException;
use App\Shared\LegalParameters\LegalParameters;
use App\Shared\LegalParameters\PensionFundRates;
use App\Shared\Tenancy\TenancyScope;
use App\Shared\Vigencia\EffectiveDated;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * PAR-2 · PAR-3 · PAR-5 · PAR-6 · PAR-7 · Tablas legales de planilla que lee la organización: tasas por AFP del
 * periodo, escala y deducciones de quinta vigentes, conjunto de parámetros de cada régimen (con lo que falta),
 * tablas salariales por convenio (lotes con verificación propia contra el acta) y novedades normativas con los
 * periodos que tocan. Nada de esto se teclea en la organización: lo publica el producto (regla 4, ADR 0023).
 */
final class PayrollLegalTables
{
    /** Parámetros que componen la renta de quinta (PAR-3); se editan como datos, sin tocar fórmulas. */
    private const FIFTH = ['scale' => 'quinta_escala', 'deduction_uit' => 'quinta_deduccion_uit', 'divisors' => 'quinta_divisores',
        'withholding_cuts' => 'quinta_cortes_retenciones', 'min_rate_pct' => 'quinta_tasa_minima_pct', 'max_rate_pct' => 'quinta_tasa_maxima_pct',
        'non_domiciled_rate_pct' => 'quinta_no_domiciliado_tasa_pct', 'uit' => 'uit'];

    /** Estados del periodo que ya no se recalculan: una novedad propone planilla complementaria (RN-PAR-08). */
    private const CLOSED = ['closed', 'paid', 'accounted'];

    public function __construct(private readonly LegalParameters $legal, private readonly PensionFundRates $funds, private readonly AuditLogger $audit) {}

    /**
     * @return array<string, mixed>
     */
    public function pensionFundRates(string $asOf): array
    {
        $rates = $this->funds->forDate(LegalParameterCatalog::country(), $asOf);

        return ['as_of' => $asOf, 'funds' => $rates, 'missing' => array_values(array_map(fn ($r) => $r['fund_code'], array_filter($rates, fn ($r) => (bool) $r['missing'])))];
    }

    /**
     * @return array<string, mixed>
     */
    public function fifthCategory(string $asOf): array
    {
        $country = LegalParameterCatalog::country();
        $out = ['as_of' => $asOf, 'pending_validation' => false, 'missing' => []];
        foreach (self::FIFTH as $key => $code) {
            $p = $this->legal->at($country, $code, $asOf);
            $out[$key] = $p === null ? null : ['code' => $code, 'value' => $p['value'], 'unit' => $p['unit'], 'valid_from' => $p['valid_from'],
                'valid_to' => $p['valid_to'], 'legal_source' => $p['legal_source'], 'pending_validation' => $p['pending_validation']];
            if ($p === null || $p['value'] === null) {
                $out['missing'][] = $code;
            }
            $out['pending_validation'] = $out['pending_validation'] || ($p['pending_validation'] ?? true);
        }

        return $out;
    }

    /**
     * PAR-5 · Conjunto de parámetros del régimen a la fecha: los requeridos por el paquete de país y todos los
     * propios del régimen, con valor, origen y si falta (la falta de un requerido impide calcular).
     *
     * @return array<string, mixed>
     */
    public function regimeParameters(string $regime, string $asOf, ?string $legalEntityId = null): array
    {
        $country = LegalParameterCatalog::country();
        $pack = new DataPayrollCountryPack($country);
        $strategy = $pack->regime($regime);
        if ($strategy === null && ! DB::table('labor_regimes')->where('code', $regime)->exists()) {
            throw new AyniException('not_found', 404, (string) trans('payroll.parameters.not_found'));
        }
        $required = $strategy['required_parameters'] ?? [];
        $own = DB::table('legal_parameters')->where('country_code', $country)->where('labor_regime_code', $regime);
        EffectiveDated::applyAsOf($own, $asOf);
        $codes = array_values(array_unique([...$required, ...$own->pluck('code')->map(fn ($c) => (string) $c)->all()]));
        sort($codes);
        $params = new PayrollParameters($this->legal, $country, $regime, $legalEntityId, $asOf);
        $items = [];
        $missing = [];
        foreach ($codes as $code) {
            $p = $params->resolve($code);
            $isRequired = in_array($code, $required, true);
            $absent = $p === null || $p['value'] === null || $p['value'] === '';
            if ($absent && $isRequired) {
                $missing[] = $code;
            }
            $items[] = ['code' => $code, 'required' => $isRequired, 'missing' => $absent, 'value' => $p['value'] ?? null, 'unit' => $p['unit'] ?? null,
                'origin' => $p['origin'] ?? null, 'valid_from' => $p['valid_from'] ?? null, 'valid_to' => $p['valid_to'] ?? null,
                'legal_source' => $p['legal_source'] ?? null, 'pending_validation' => $p['pending_validation'] ?? true];
        }

        return ['regime' => $regime, 'as_of' => $asOf, 'supported' => $strategy !== null, 'parameters' => $items, 'missing' => $missing, 'can_calculate' => $strategy !== null && $missing === []];
    }

    /**
     * PAR-6 · Tablas salariales por convenio del paquete de país vigentes o vencidas a la fecha.
     *
     * @return list<array<string, mixed>>
     */
    public function wageTables(string $asOf): array
    {
        $out = [];
        foreach ($this->wageDefinitions() as $code => $def) {
            $anchors = DB::table('legal_parameters')->where('country_code', LegalParameterCatalog::country())->where('labor_regime_code', $def['regimen'])
                ->where('code', $def['ancla'])->where('valid_from', '<=', $asOf)->orderByDesc('valid_from')->limit(1)->get();
            foreach ($anchors as $anchor) {
                $out[] = $this->wageTable($code, $def, $anchor, $asOf);
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public function wageTableById(string $id, string $asOf): array
    {
        [$code, $def, $anchor] = $this->wageAnchor($id);

        return $this->wageTable($code, $def, $anchor, $asOf) + ['retroactive_impact' => $this->closedPeriodsSince(substr((string) $anchor->valid_from, 0, 10), (string) $def['regimen'])];
    }

    /**
     * RN-PAR-10 · La organización verifica el lote contra el acta (solo inserción); hasta entonces se usa marcado.
     *
     * @return array<string, mixed>
     */
    public function verifyWageTable(string $id, string $acta, ?string $note, string $userId): array
    {
        [$code, $def, $anchor] = $this->wageAnchor($id);
        if (trim($acta) === '') {
            throw ValidationException::withMessages(['acta_reference' => trans('payroll.parameters.acta_required')]);
        }
        $from = substr((string) $anchor->valid_from, 0, 10);
        if (DB::table('wage_table_verifications')->where('table_code', $code)->where('valid_from', $from)->exists()) {
            throw new AyniException('wage_table_already_verified', 409, (string) trans('payroll.parameters.wage_table_verified'));
        }
        $newId = (string) Str::uuid();
        DB::table('wage_table_verifications')->insert([
            'id' => $newId, 'tenant_id' => TenancyScope::requireTenant(), 'table_code' => $code, 'labor_regime_code' => $def['regimen'],
            'valid_from' => $from, 'verified_by_user_id' => $userId, 'acta_reference' => trim($acta), 'note' => $note === null || trim($note) === '' ? null : trim($note),
        ]);
        $this->audit->record('wage_table.verified', 'wage_table', (string) $anchor->id, null, ['table_code' => $code, 'valid_from' => $from, 'acta_reference' => trim($acta)]);

        return $this->wageTableById($id, now()->toDateString());
    }

    /**
     * PAR-7 · Novedades normativas del país de la organización: parámetro, valor anterior y nuevo, vigencia y norma,
     * los regímenes que usan sus empresas, y los periodos que toca (abiertos: avisar y simular; cerrados: proponer
     * planilla complementaria, nunca recalcular).
     *
     * @param  array{legal_entity_id?: string|null, regime?: string|null, from?: string|null, to?: string|null}  $filters
     * @return array<int, array<string, mixed>>
     */
    public function legalUpdates(array $filters): array
    {
        $country = LegalParameterCatalog::country();
        $regimes = null;
        if (($filters['legal_entity_id'] ?? null) !== null) {
            if (! TenancyScope::isValidUuid((string) $filters['legal_entity_id'])) {
                return [];
            }
            $q = DB::table('legal_entity_regimes')->where('legal_entity_id', $filters['legal_entity_id']);
            $regimes = $q->pluck('labor_regime_code')->map(fn ($c) => (string) $c)->unique()->values()->all();
        }
        $q = DB::table('legal_parameter_changes')->where('country_code', $country);
        if (($filters['regime'] ?? null) !== null) {
            $q->where(fn ($w) => $w->whereNull('labor_regime_code')->orWhere('labor_regime_code', $filters['regime']));
        }
        if ($regimes !== null) {
            $q->where(fn ($w) => $w->whereNull('labor_regime_code')->orWhereIn('labor_regime_code', $regimes));
        }
        if (($filters['from'] ?? null) !== null) {
            $q->where('published_at', '>=', $filters['from']);
        }
        if (($filters['to'] ?? null) !== null) {
            $q->where('published_at', '<', CarbonImmutable::parse((string) $filters['to'])->addDay()->toDateString());
        }

        return $q->orderByDesc('published_at')->limit(300)->get()->map(function ($c): array {
            $regime = $c->labor_regime_code === null ? null : (string) $c->labor_regime_code;
            $periods = $this->periodsSince(substr((string) $c->valid_from, 0, 10), $regime);

            return [
                'id' => (string) $c->id, 'code' => (string) $c->code, 'name' => (string) $c->name, 'regime' => $regime, 'unit' => (string) $c->unit,
                'previous_value' => $c->previous_value === null ? null : json_decode((string) $c->previous_value, true),
                'new_value' => $c->new_value === null ? null : json_decode((string) $c->new_value, true),
                'valid_from' => substr((string) $c->valid_from, 0, 10), 'valid_to' => $c->valid_to === null ? null : substr((string) $c->valid_to, 0, 10),
                'legal_source' => $c->legal_source === null ? null : (string) $c->legal_source, 'origin' => (string) $c->origin,
                'published_at' => CarbonImmutable::parse((string) $c->published_at)->toIso8601String(),
                'open_periods' => $periods['open'], 'closed_periods' => $periods['closed'],
                'suggests_simulation' => $periods['open'] !== [], 'suggests_complementary' => $periods['closed'] !== [],
            ];
        })->values()->all();
    }

    /**
     * @return array{open: list<array<string, mixed>>, closed: list<array<string, mixed>>}
     */
    private function periodsSince(string $from, ?string $regime): array
    {
        $q = DB::table('payroll_periods AS p')->join('payroll_groups AS g', 'g.id', '=', 'p.payroll_group_id')
            ->where('p.ends_on', '>=', $from)->whereNull('g.deleted_at');
        if ($regime !== null) {
            $q->where(fn ($w) => $w->whereNull('g.labor_regime_code')->orWhere('g.labor_regime_code', $regime));
        }
        $rows = $q->orderBy('p.starts_on')->limit(100)->get(['p.id', 'p.status', 'p.starts_on', 'p.ends_on', 'p.kind', 'g.name AS group_name', 'g.legal_entity_id']);
        $open = $closed = [];
        foreach ($rows as $p) {
            $item = ['id' => (string) $p->id, 'group' => (string) $p->group_name, 'kind' => (string) $p->kind, 'status' => (string) $p->status,
                'starts_on' => substr((string) $p->starts_on, 0, 10), 'ends_on' => substr((string) $p->ends_on, 0, 10), 'legal_entity_id' => (string) $p->legal_entity_id];
            in_array($p->status, self::CLOSED, true) ? $closed[] = $item : $open[] = $item;
        }

        return ['open' => $open, 'closed' => $closed];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function closedPeriodsSince(string $from, string $regime): array
    {
        return $this->periodsSince($from, $regime)['closed'];
    }

    /**
     * @param  array{nombre: string, regimen: string, ancla: string, parametros: list<string>}  $def
     * @return array<string, mixed>
     */
    private function wageTable(string $code, array $def, \stdClass $anchor, string $asOf): array
    {
        $country = LegalParameterCatalog::country();
        $from = substr((string) $anchor->valid_from, 0, 10);
        $to = $anchor->valid_to === null ? null : substr((string) $anchor->valid_to, 0, 10);
        $values = [];
        $inconsistent = [];
        foreach ($def['parametros'] as $param) {
            $row = DB::table('legal_parameters')->where('country_code', $country)->where('labor_regime_code', $def['regimen'])->where('code', $param)
                ->where('valid_from', '<=', $from)->orderByDesc('valid_from')->first();
            if ($row !== null && substr((string) $row->valid_from, 0, 10) !== $from) {
                $inconsistent[] = $param;
            }
            $values[] = ['code' => $param, 'name' => $row === null ? $param : (string) $row->name, 'value' => $row === null ? null : LegalParameters::valueOf($row),
                'unit' => $row === null ? null : (string) $row->unit, 'pending_validation' => $row === null || ! (bool) $row->verified || LegalParameters::valueOf($row) === null];
        }
        $verification = DB::table('wage_table_verifications AS v')->leftJoin('users AS u', 'u.id', '=', 'v.verified_by_user_id')
            ->where('v.table_code', $code)->where('v.valid_from', $from)->first(['v.acta_reference', 'v.created_at', 'u.display_name']);
        $newer = DB::table('legal_parameters')->where('country_code', $country)->where('labor_regime_code', $def['regimen'])->where('code', $def['ancla'])->where('valid_from', '>', $from)->exists();

        return [
            'id' => (string) $anchor->id, 'code' => $code, 'name' => $def['nombre'], 'regime' => $def['regimen'], 'valid_from' => $from, 'valid_to' => $to,
            'legal_source' => $anchor->legal_source === null ? null : (string) $anchor->legal_source,
            // RN-PAR-11 · Fuera de vigencia y sin tabla nueva: se calcula con la última y se advierte, no se bloquea.
            'expired' => $to !== null && $asOf > $to && ! $newer, 'values' => $values, 'inconsistent_validity' => $inconsistent,
            'verified' => $verification !== null,
            'verification' => $verification === null ? null : ['acta_reference' => (string) $verification->acta_reference, 'verified_by' => (string) ($verification->display_name ?? ''),
                'verified_at' => CarbonImmutable::parse((string) $verification->created_at)->toIso8601String()],
        ];
    }

    /**
     * @return array{0: string, 1: array{nombre: string, regimen: string, ancla: string, parametros: list<string>}, 2: \stdClass}
     */
    private function wageAnchor(string $id): array
    {
        $anchor = TenancyScope::isValidUuid($id) ? DB::table('legal_parameters')->where('id', $id)->where('country_code', LegalParameterCatalog::country())->first() : null;
        foreach ($this->wageDefinitions() as $code => $def) {
            if ($anchor !== null && $anchor->code === $def['ancla'] && $anchor->labor_regime_code === $def['regimen']) {
                return [$code, $def, $anchor];
            }
        }
        throw new AyniException('not_found', 404, (string) trans('payroll.parameters.not_found'));
    }

    /**
     * @return array<string, array{nombre: string, regimen: string, ancla: string, parametros: list<string>}>
     */
    private function wageDefinitions(): array
    {
        $file = base_path('packages/country-'.strtolower(LegalParameterCatalog::country()).'/data/tablas_salariales.php');
        if (! is_file($file)) {
            return [];
        }
        /** @var array{tablas?: array<string, array{nombre: string, regimen: string, ancla: string, parametros: list<string>}>} $data */
        $data = require $file;

        return $data['tablas'] ?? [];
    }
}
