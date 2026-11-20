<?php

namespace App\Modules\Payroll\Application;

use App\Modules\Payroll\Infrastructure\Country\DataPayrollCountryPack;
use App\Modules\Payroll\Infrastructure\PayrollParameters;
use App\Shared\Audit\AuditLogger;
use App\Shared\Exceptions\AyniException;
use App\Shared\LegalParameters\LegalParameters;
use App\Shared\Tenancy\TenancyScope;
use App\Shared\Vigencia\EffectiveDated;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * NOM-10 · Micro y pequeña empresa (RN-PE-MYP-01 a 07, docs/18): ventas anuales en UIT y dotación promedio
 * por razón social y ejercicio, acreditación del régimen y permanencia al superar el tope.
 *
 * - Al registrar o corregir las ventas de un ejercicio se evalúa la permanencia: si superan el tope del
 *   régimen (`mype_ventas_max_uit`) los `mype_anios_consecutivos_exceso` últimos ejercicios seguidos, la
 *   empresa entra en permanencia (`in_grace_period`) hasta el 31 de diciembre del ejercicio que resulta de
 *   sumar `mype_permanencia_anios` al último ejercicio en exceso. Es un tramo nuevo de su régimen con vigencia
 *   (nada se sobrescribe, regla 2) y queda auditado.
 * - Las relaciones nuevas solo heredan el régimen especial si la acreditación está vigente y su ingreso es
 *   posterior a ella (RegimeResolver); las anteriores conservan el suyo.
 * - Al vencer la permanencia, el cambio de régimen de la empresa y de sus relaciones es un movimiento masivo
 *   con fecha efectiva (changeEntityRegime).
 *
 * Todos los topes y plazos son parámetros legales con vigencia (regla 4); ninguno está en el código.
 */
final class SmallBusinessRegime
{
    public function __construct(
        private readonly LegalParameters $legal,
        private readonly EffectiveDated $validity,
        private readonly PayrollRegimes $regimes,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function list(?string $legalEntityId): array
    {
        $q = DB::table('legal_entity_annual_figures')->orderByDesc('fiscal_year')->orderBy('legal_entity_id');
        if ($legalEntityId !== null) {
            $q->where('legal_entity_id', $legalEntityId);
        }

        return array_values($q->get()->map(fn ($r) => $this->present($r))->all());
    }

    /**
     * @return array<string, mixed>
     */
    public function show(string $id): array
    {
        $r = DB::table('legal_entity_annual_figures')->where('id', $id)->first();
        if ($r === null) {
            throw new AyniException('not_found', 404);
        }

        return $this->present($r);
    }

    /**
     * @param  array{legal_entity_id: string, fiscal_year: int, net_sales: string, currency: string, uit_value: string, average_headcount?: string|null, source_document_id?: string|null}  $input
     * @return array<string, mixed>
     */
    public function create(array $input): array
    {
        $this->entity($input['legal_entity_id']);
        if (DB::table('legal_entity_annual_figures')->where('legal_entity_id', $input['legal_entity_id'])->where('fiscal_year', $input['fiscal_year'])->exists()) {
            throw new AyniException('conflict', 409, (string) trans('payroll.myp.year_exists'));
        }
        $id = DB::transaction(function () use ($input): string {
            $id = (string) DB::table('legal_entity_annual_figures')->insertGetId([
                'tenant_id' => TenancyScope::requireTenant(), 'legal_entity_id' => $input['legal_entity_id'], 'fiscal_year' => $input['fiscal_year'],
                'net_sales' => $input['net_sales'], 'currency' => $input['currency'], 'uit_value' => $input['uit_value'],
                'average_headcount' => $input['average_headcount'] ?? null, 'source_document_id' => $input['source_document_id'] ?? null,
            ]);
            $this->audit->record('legal_entity.annual_figures_created', 'legal_entity_annual_figure', $id, null, $input);
            $this->evaluate($input['legal_entity_id']);

            return $id;
        });

        return $this->show($id);
    }

    /**
     * @param  array{net_sales?: string, currency?: string, uit_value?: string, average_headcount?: string|null, source_document_id?: string|null}  $input
     * @return array<string, mixed>
     */
    public function update(string $id, array $input): array
    {
        $before = DB::table('legal_entity_annual_figures')->where('id', $id)->first();
        if ($before === null) {
            throw new AyniException('not_found', 404);
        }
        DB::transaction(function () use ($id, $input, $before): void {
            $values = array_intersect_key($input, array_flip(['net_sales', 'currency', 'uit_value', 'average_headcount', 'source_document_id']));
            DB::table('legal_entity_annual_figures')->where('id', $id)->update($values + ['updated_at' => now(), 'lock_version' => DB::raw('lock_version + 1')]);
            $this->audit->record('legal_entity.annual_figures_updated', 'legal_entity_annual_figure', $id,
                array_intersect_key((array) $before, $values), $values);
            $this->evaluate((string) $before->legal_entity_id);
        });

        return $this->show($id);
    }

    /**
     * RN-PE-MYP-12 · Tablero: régimen, acreditación, ventas en UIT de los últimos ejercicios contra el tope y
     * la alerta (riesgo de salida el primer año en exceso, permanencia desde el segundo).
     *
     * @return array<string, mixed>
     */
    public function status(string $legalEntityId, ?string $asOf = null): array
    {
        $entity = $this->entity($legalEntityId);
        $asOf ??= CarbonImmutable::today()->toDateString();
        $row = $this->regimeRow($legalEntityId, $asOf);
        $pack = new DataPayrollCountryPack((string) $entity->country_code);
        $regime = $row === null ? null : (string) $row->labor_regime_code;
        $strategy = $regime === null ? null : $pack->regime($regime);
        $special = $strategy !== null && $strategy['requires_accreditation'];
        $years = [];
        $required = null;
        if ($special) {
            $required = $this->intParam($entity, $regime, 'mype_anios_consecutivos_exceso', $asOf);
            foreach (DB::table('legal_entity_annual_figures')->where('legal_entity_id', $legalEntityId)->orderByDesc('fiscal_year')->limit(max(2, $required ?? 2))->get() as $f) {
                $cap = $this->param($entity, $regime, 'mype_ventas_max_uit', sprintf('%04d-12-31', (int) $f->fiscal_year));
                $years[] = ['fiscal_year' => (int) $f->fiscal_year, 'sales_in_uit' => (string) $f->sales_in_uit, 'cap_in_uit' => $cap,
                    'exceeds' => $cap !== null && BigDecimal::of((string) $f->sales_in_uit)->isGreaterThan(BigDecimal::of($cap))];
            }
        }
        $streak = 0;
        $expected = null;
        foreach ($years as $y) {
            if (! $y['exceeds'] || ($expected !== null && $y['fiscal_year'] !== $expected)) {
                break;
            }
            $streak++;
            $expected = $y['fiscal_year'] - 1;
        }
        $status = $row === null ? null : (string) $row->accreditation_status;
        $alert = match (true) {
            ! $special => 'none',
            $status === 'in_grace_period' => 'permanence',
            $required !== null && $streak >= $required => 'permanence_due',
            $streak >= 1 => 'exit_risk',
            default => 'none',
        };

        return [
            'legal_entity_id' => $legalEntityId, 'as_of' => $asOf, 'labor_regime_code' => $regime,
            'requires_accreditation' => $special, 'accreditation_status' => $status,
            'accredited_on' => $row?->registered_on === null ? null : substr((string) $row->registered_on, 0, 10),
            'registry_number' => $row->registry_number ?? null,
            'grace_period_ends_on' => $row?->grace_period_ends_on === null ? null : substr((string) $row->grace_period_ends_on, 0, 10),
            'consecutive_years_required' => $required, 'consecutive_years_exceeding' => $streak,
            'years' => $years, 'alert' => $alert,
            'proposed_regime_for_new_hires' => $special && in_array($status, ['accredited', 'in_grace_period'], true) ? $regime : $pack->defaultRegime(),
        ];
    }

    /**
     * RN-PE-MYP-05 · Marca la permanencia si las ventas superan el tope los ejercicios consecutivos exigidos.
     * Devuelve true si abrió el tramo de permanencia.
     */
    public function evaluate(string $legalEntityId): bool
    {
        $today = CarbonImmutable::today()->toDateString();
        $status = $this->status($legalEntityId, $today);
        if ($status['alert'] !== 'permanence_due' || $status['accreditation_status'] !== 'accredited') {
            return false;
        }
        $entity = $this->entity($legalEntityId);
        $regime = (string) $status['labor_regime_code'];
        $last = (int) $status['years'][0]['fiscal_year'];
        $years = $this->intParam($entity, $regime, 'mype_permanencia_anios', $today);
        if ($years === null) {
            return false;
        }
        $row = $this->regimeRow($legalEntityId, $today);
        if ($row === null) {
            return false;
        }
        $start = max(sprintf('%04d-01-01', $last + 1), CarbonImmutable::parse((string) $row->valid_from)->addDay()->toDateString());
        $ends = sprintf('%04d-12-31', $last + $years);
        $values = ['labor_regime_code' => $regime, 'registry_number' => $row->registry_number, 'registered_on' => $row->registered_on,
            'notes' => $row->notes, 'accreditation_status' => 'in_grace_period', 'accreditation_document_id' => $row->accreditation_document_id,
            'grace_period_ends_on' => $ends];
        $this->validity->change('legal_entity_regimes', ['tenant_id' => TenancyScope::requireTenant(), 'legal_entity_id' => $legalEntityId], $values, $start);
        $this->audit->record('legal_entity.regime_permanence_started', 'legal_entity', $legalEntityId,
            ['accreditation_status' => 'accredited'], ['accreditation_status' => 'in_grace_period', 'valid_from' => $start, 'grace_period_ends_on' => $ends]);

        return true;
    }

    /**
     * RN-PE-MYP-07 · Cambio masivo de régimen con fecha efectiva: un tramo nuevo del régimen de la empresa y,
     * para cada relación vigente a esa fecha cuyo régimen sea distinto, un tramo nuevo de su régimen (NOM-2).
     *
     * @param  array{labor_regime_code: string, effective_on: string, reason: string, accreditation_status?: string|null, registry_number?: string|null, registered_on?: string|null}  $input
     * @return array{legal_entity_id: string, labor_regime_code: string, effective_on: string, changed: int, unchanged: int, errors: list<array<string, mixed>>}
     */
    public function changeEntityRegime(string $legalEntityId, array $input): array
    {
        $entity = $this->entity($legalEntityId);
        $code = $input['labor_regime_code'];
        $from = EffectiveDated::day($input['effective_on']);
        $pack = new DataPayrollCountryPack((string) $entity->country_code);
        if (! DB::table('labor_regimes')->where('country_code', $entity->country_code)->where('code', $code)->exists()) {
            throw ValidationException::withMessages(['labor_regime_code' => [(string) trans('payroll.regime_unknown')]]);
        }
        $strategy = $pack->regime($code);
        $accreditation = $input['accreditation_status'] ?? ($strategy !== null && $strategy['requires_accreditation'] ? 'pending' : 'not_required');
        $before = $this->regimeRow($legalEntityId, $from);

        return DB::transaction(function () use ($legalEntityId, $code, $from, $accreditation, $input, $before): array {
            $this->validity->change('legal_entity_regimes', ['tenant_id' => TenancyScope::requireTenant(), 'legal_entity_id' => $legalEntityId], [
                'labor_regime_code' => $code, 'accreditation_status' => $accreditation, 'registry_number' => $input['registry_number'] ?? null,
                'registered_on' => $input['registered_on'] ?? null, 'grace_period_ends_on' => null, 'notes' => $input['reason'],
            ], $from);
            $this->audit->record('legal_entity.regime_changed', 'legal_entity', $legalEntityId,
                $before === null ? null : ['labor_regime_code' => $before->labor_regime_code, 'accreditation_status' => $before->accreditation_status],
                ['labor_regime_code' => $code, 'accreditation_status' => $accreditation, 'valid_from' => $from], $input['reason']);
            $changed = 0;
            $unchanged = 0;
            $errors = [];
            $ids = DB::table('employments')->where('legal_entity_id', $legalEntityId)->whereNull('deleted_at')->where('hire_date', '<=', $from)
                ->where(fn ($w) => $w->whereNull('termination_date')->orWhere('termination_date', '>=', $from))->orderBy('id')->pluck('id');
            foreach ($ids as $id) {
                $current = DB::table('employment_labor_regimes')->where('employment_id', $id)->whereNull('deleted_at');
                EffectiveDated::applyAsOf($current, $from);
                $own = $current->value('labor_regime_code');
                if ($own === $code) {
                    $unchanged++;

                    continue;
                }
                try {
                    $this->regimes->change((string) $id, ['labor_regime_code' => $code, 'valid_from' => $from, 'reason' => $input['reason']]);
                    $changed++;
                } catch (AyniException $e) {
                    $errors[] = ['employment_id' => (string) $id, 'code' => $e->getErrorCode(), 'message' => $e->getMessage()];
                }
            }

            return ['legal_entity_id' => $legalEntityId, 'labor_regime_code' => $code, 'effective_on' => $from, 'changed' => $changed, 'unchanged' => $unchanged, 'errors' => $errors];
        });
    }

    private function regimeRow(string $legalEntityId, string $date): ?\stdClass
    {
        $q = DB::table('legal_entity_regimes')->where('legal_entity_id', $legalEntityId);
        EffectiveDated::applyAsOf($q, $date);
        /** @var \stdClass|null $row */
        $row = $q->first();

        return $row;
    }

    private function param(\stdClass $entity, ?string $regime, string $code, string $date): ?string
    {
        $p = (new PayrollParameters($this->legal, (string) $entity->country_code, $regime, (string) $entity->id, $date))->resolve($code);

        return $p === null || $p['value'] === null || ! is_numeric($p['value']) ? null : (string) $p['value'];
    }

    private function intParam(\stdClass $entity, ?string $regime, string $code, string $date): ?int
    {
        $v = $this->param($entity, $regime, $code, $date);

        return $v === null ? null : BigDecimal::of($v)->toScale(0, RoundingMode::DOWN)->toInt();
    }

    private function entity(string $id): \stdClass
    {
        /** @var \stdClass|null $e */
        $e = DB::table('legal_entities')->where('id', $id)->first(['id', 'country_code', 'currency']);
        if ($e === null) {
            throw new AyniException('not_found', 404);
        }

        return $e;
    }

    /**
     * @param  \stdClass  $r
     * @return array<string, mixed>
     */
    private function present(object $r): array
    {
        return [
            'id' => (string) $r->id, 'legal_entity_id' => (string) $r->legal_entity_id, 'fiscal_year' => (int) $r->fiscal_year,
            'net_sales' => ['amount' => (string) $r->net_sales, 'currency' => (string) $r->currency],
            'uit_value' => ['amount' => (string) $r->uit_value, 'currency' => (string) $r->currency],
            'sales_in_uit' => (float) $r->sales_in_uit,
            'average_headcount' => $r->average_headcount === null ? null : (float) $r->average_headcount,
            'source_document_id' => $r->source_document_id === null ? null : (string) $r->source_document_id,
            'created_at' => (string) $r->created_at, 'updated_at' => (string) $r->updated_at,
        ];
    }
}
