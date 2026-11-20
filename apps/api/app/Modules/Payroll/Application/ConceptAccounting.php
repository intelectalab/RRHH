<?php

namespace App\Modules\Payroll\Application;

use App\Shared\Audit\AuditLogger;
use App\Shared\Exceptions\AyniException;
use App\Shared\Payroll\ConceptAccounts;
use App\Shared\Tenancy\TenancyScope;
use App\Shared\Vigencia\EffectiveDated;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * CPT-4 · Plan de cuentas de la planilla y contabilización por concepto (ADR 0020: formato contable genérico).
 * Cada concepto tiene reglas con vigencia: una general (sin empresa, centro de costo ni categoría) y
 * excepciones por empresa, centro de costo o categoría de trabajador. Al generar el asiento gana la regla
 * más específica (RN de M-CPT §2). Cambiar las reglas cierra las vigentes e inserta las nuevas.
 */
final class ConceptAccounting implements ConceptAccounts
{
    /** Tipos de concepto sin efecto contable. */
    private const NO_ACCOUNTING = ['informative'];

    public const WORKER_CATEGORIES = ['executive', 'employee', 'worker'];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function accounts(?string $legalEntityId): array
    {
        $q = DB::table('accounting_accounts')->whereNull('deleted_at');
        if ($legalEntityId !== null) {
            $q->where(fn ($w) => $w->whereNull('legal_entity_id')->orWhere('legal_entity_id', $legalEntityId));
        }

        return array_values($q->orderBy('code')->get(['id', 'legal_entity_id', 'code', 'name', 'kind'])
            ->map(fn ($a) => ['id' => (string) $a->id, 'legal_entity_id' => $a->legal_entity_id, 'code' => (string) $a->code, 'name' => (string) $a->name, 'kind' => (string) $a->kind])
            ->all());
    }

    /**
     * @param  array{code: string, name: string, kind: string, legal_entity_id?: string|null}  $input
     * @return array<string, mixed>
     */
    public function createAccount(array $input): array
    {
        $entity = $input['legal_entity_id'] ?? null;
        if (DB::table('accounting_accounts')->whereNull('deleted_at')->where('code', $input['code'])
            ->where(fn ($w) => $entity === null ? $w->whereNull('legal_entity_id') : $w->where('legal_entity_id', $entity))->exists()) {
            throw self::invalid('code', 'unique', (string) trans('payroll.concepts.account_taken', ['code' => $input['code']]));
        }
        $id = (string) DB::table('accounting_accounts')->insertGetId(['tenant_id' => TenancyScope::requireTenant(), 'legal_entity_id' => $entity,
            'code' => $input['code'], 'name' => $input['name'], 'kind' => $input['kind']]);
        $this->audit->record('accounting_account.created', 'accounting_account', $id, null, $input);

        return $this->account($id);
    }

    /**
     * @param  array{name?: string, kind?: string}  $input
     * @return array<string, mixed>
     */
    public function updateAccount(string $id, array $input): array
    {
        $before = $this->account($id);
        $data = array_intersect_key($input, array_flip(['name', 'kind']));
        if ($data !== []) {
            DB::table('accounting_accounts')->where('id', $id)->update($data);
        }
        $this->audit->record('accounting_account.updated', 'accounting_account', $id, $before, $data);

        return $this->account($id);
    }

    /**
     * @return array<string, mixed>
     */
    public function account(string $id): array
    {
        $a = DB::table('accounting_accounts')->where('id', $id)->whereNull('deleted_at')->first(['id', 'legal_entity_id', 'code', 'name', 'kind']);
        if ($a === null) {
            throw new AyniException('not_found', 404);
        }

        return ['id' => (string) $a->id, 'legal_entity_id' => $a->legal_entity_id, 'code' => (string) $a->code, 'name' => (string) $a->name, 'kind' => (string) $a->kind];
    }

    /**
     * Reglas de un concepto: las vigentes hoy o futuras primero, después la historia.
     *
     * @return list<array<string, mixed>>
     */
    public function rules(string $conceptId): array
    {
        return array_values(DB::table('concept_account_mappings AS m')
            ->leftJoin('accounting_accounts AS d', 'd.id', '=', 'm.debit_account_id')
            ->leftJoin('accounting_accounts AS c', 'c.id', '=', 'm.credit_account_id')
            ->leftJoin('legal_entities AS e', 'e.id', '=', 'm.legal_entity_id')
            ->leftJoin('cost_centers AS cc', 'cc.id', '=', 'm.cost_center_id')
            ->where('m.payroll_concept_id', $conceptId)->orderByRaw('m.valid_to IS NOT NULL')->orderByDesc('m.valid_from')
            ->orderByRaw('m.legal_entity_id IS NOT NULL, m.cost_center_id IS NOT NULL, m.worker_category IS NOT NULL')
            ->get(['m.id', 'm.legal_entity_id', 'e.legal_name AS legal_entity_name', 'm.cost_center_id', 'cc.name AS cost_center_name', 'm.worker_category',
                'm.debit_account_id', 'd.code AS debit_account_code', 'm.credit_account_id', 'c.code AS credit_account_code', 'm.valid_from', 'm.valid_to'])
            ->map(fn ($r) => ['id' => (string) $r->id, 'legal_entity_id' => $r->legal_entity_id, 'legal_entity_name' => $r->legal_entity_name,
                'cost_center_id' => $r->cost_center_id, 'cost_center_name' => $r->cost_center_name, 'worker_category' => $r->worker_category,
                'debit_account_id' => $r->debit_account_id, 'debit_account_code' => $r->debit_account_code,
                'credit_account_id' => $r->credit_account_id, 'credit_account_code' => $r->credit_account_code,
                'valid_from' => substr((string) $r->valid_from, 0, 10), 'valid_to' => $r->valid_to === null ? null : substr((string) $r->valid_to, 0, 10)])
            ->all());
    }

    /**
     * CPT-4 · Reemplaza las reglas de un concepto desde una fecha: cierra las vigentes el día anterior e inserta
     * el nuevo juego. Debe haber una regla general; las demás son excepciones sin repetir.
     *
     * @param  array{valid_from: string, rules: list<array{legal_entity_id?: string|null, cost_center_id?: string|null, worker_category?: string|null, debit_account_id: string, credit_account_id: string}>}  $input
     * @return list<array<string, mixed>>
     */
    public function replaceRules(string $conceptId, array $input): array
    {
        $concept = DB::table('payroll_concepts')->where('id', $conceptId)->whereNull('deleted_at')->first(['id', 'kind']);
        if ($concept === null) {
            throw new AyniException('not_found', 404);
        }
        $from = $input['valid_from'];
        $seen = [];
        $hasGeneral = false;
        foreach ($input['rules'] as $i => $r) {
            $key = ($r['legal_entity_id'] ?? '').'|'.($r['cost_center_id'] ?? '').'|'.($r['worker_category'] ?? '');
            if (isset($seen[$key])) {
                throw self::invalid("rules.{$i}", 'distinct', (string) trans('payroll.concepts.rule_duplicate'));
            }
            $seen[$key] = true;
            $hasGeneral = $hasGeneral || $key === '||';
            foreach (['debit_account_id', 'credit_account_id'] as $f) {
                if (! DB::table('accounting_accounts')->where('id', $r[$f])->whereNull('deleted_at')->exists()) {
                    throw self::invalid("rules.{$i}.{$f}", 'exists', (string) trans('payroll.concepts.account_not_found'));
                }
            }
            if (($r['legal_entity_id'] ?? null) !== null && ! DB::table('legal_entities')->where('id', $r['legal_entity_id'])->exists()) {
                throw self::invalid("rules.{$i}.legal_entity_id", 'exists', (string) trans('validation.exists', ['attribute' => 'legal_entity_id']));
            }
            if (($r['cost_center_id'] ?? null) !== null && ! DB::table('cost_centers')->where('id', $r['cost_center_id'])->exists()) {
                throw self::invalid("rules.{$i}.cost_center_id", 'exists', (string) trans('validation.exists', ['attribute' => 'cost_center_id']));
            }
        }
        if ($input['rules'] !== [] && ! $hasGeneral) {
            throw self::invalid('rules', 'general_required', (string) trans('payroll.concepts.general_rule_required'));
        }

        return DB::transaction(function () use ($conceptId, $input, $from): array {
            $before = $this->rules($conceptId);
            if (DB::table('concept_account_mappings')->where('payroll_concept_id', $conceptId)->where('valid_from', '>=', $from)->exists()) {
                throw self::invalid('valid_from', 'after_current', (string) trans('payroll.concepts.valid_from_not_after'));
            }
            DB::table('concept_account_mappings')->where('payroll_concept_id', $conceptId)
                ->where(fn ($w) => $w->whereNull('valid_to')->orWhere('valid_to', '>=', $from))
                ->update(['valid_to' => CarbonImmutable::parse($from)->subDay()->toDateString()]);
            $tenant = TenancyScope::requireTenant();
            foreach ($input['rules'] as $r) {
                DB::table('concept_account_mappings')->insert(['tenant_id' => $tenant, 'payroll_concept_id' => $conceptId,
                    'legal_entity_id' => $r['legal_entity_id'] ?? null, 'cost_center_id' => $r['cost_center_id'] ?? null, 'worker_category' => $r['worker_category'] ?? null,
                    'debit_account_id' => $r['debit_account_id'], 'credit_account_id' => $r['credit_account_id'], 'valid_from' => $from]);
            }
            $this->audit->record('payroll_concept.accounting_changed', 'payroll_concept', $conceptId, ['rules' => $before], $input);

            return $this->rules($conceptId);
        });
    }

    public function resolve(string $conceptId, string $legalEntityId, ?string $costCenterId, ?string $workerCategory, string $date): ?array
    {
        $q = DB::table('concept_account_mappings AS m')->join('accounting_accounts AS d', 'd.id', '=', 'm.debit_account_id')
            ->join('accounting_accounts AS c', 'c.id', '=', 'm.credit_account_id')->where('m.payroll_concept_id', $conceptId)
            ->where(fn ($w) => $w->whereNull('m.legal_entity_id')->orWhere('m.legal_entity_id', $legalEntityId))
            ->where(fn ($w) => $costCenterId === null ? $w->whereNull('m.cost_center_id') : $w->whereNull('m.cost_center_id')->orWhere('m.cost_center_id', $costCenterId))
            ->where(fn ($w) => $workerCategory === null ? $w->whereNull('m.worker_category') : $w->whereNull('m.worker_category')->orWhere('m.worker_category', $workerCategory));
        EffectiveDated::applyAsOf($q, $date, 'm.valid_from', 'm.valid_to');
        // La más específica: centro de costo pesa más que la empresa y esta más que la categoría.
        $r = $q->orderByRaw('(m.cost_center_id IS NOT NULL)::int * 4 + (m.legal_entity_id IS NOT NULL)::int * 2 + (m.worker_category IS NOT NULL)::int DESC')
            ->first(['m.id', 'd.code AS debit', 'c.code AS credit']);

        return $r === null ? null : ['debit_account_code' => (string) $r->debit, 'credit_account_code' => (string) $r->credit, 'rule_id' => (string) $r->id];
    }

    public function missing(string $legalEntityId, string $date): array
    {
        $out = [];
        $concepts = DB::table('payroll_concepts')->whereNull('deleted_at')->where('status', 'active')
            ->whereNotIn('kind', self::NO_ACCOUNTING)->orderBy('code')->get(['id', 'code', 'name', 'kind']);
        foreach ($concepts as $c) {
            if ($this->resolve((string) $c->id, $legalEntityId, null, null, $date) === null) {
                $out[] = ['id' => (string) $c->id, 'code' => (string) $c->code, 'name' => (string) $c->name, 'kind' => (string) $c->kind];
            }
        }

        return $out;
    }

    private static function invalid(string $field, string $rule, string $message): AyniException
    {
        return new AyniException('validation_failed', 422, $message, [['field' => $field, 'rule' => $rule, 'message' => $message]]);
    }
}
