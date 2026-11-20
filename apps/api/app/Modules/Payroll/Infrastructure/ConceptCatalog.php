<?php

namespace App\Modules\Payroll\Infrastructure;

use App\Modules\Payroll\Domain\Engine\ConceptDefinition;
use App\Shared\Vigencia\EffectiveDated;
use Illuminate\Support\Facades\DB;

/**
 * NOM-1 · Conceptos de la organización con la versión vigente a una fecha (RN-NOM-01). Los define M-CPT;
 * el motor solo los lee.
 */
final class ConceptCatalog
{
    /**
     * @return list<ConceptDefinition>
     */
    public function at(string $date): array
    {
        $q = DB::table('payroll_concept_versions AS v')->join('payroll_concepts AS c', 'c.id', '=', 'v.payroll_concept_id')
            ->whereNull('c.deleted_at')->where('c.status', 'active');
        EffectiveDated::applyAsOf($q, $date, 'v.valid_from', 'v.valid_to');
        $rows = $q->orderBy('c.code')->get(['c.id AS concept_id', 'c.code', 'c.name', 'c.kind', 'c.sort_order', 'v.id AS version_id', 'v.formula',
            'v.condition', 'v.rounding', 'v.applies_to_regimes', 'v.applies_to_period_kinds', 'v.valid_from']);
        // CPT-3 · Bases a las que suma cada versión.
        $affects = [];
        if ($rows->isNotEmpty()) {
            foreach (DB::table('payroll_concept_affectations')->whereIn('payroll_concept_version_id', $rows->pluck('version_id')->all())
                ->where('affects', true)->orderBy('base_code')->get(['payroll_concept_version_id', 'base_code']) as $a) {
                $affects[(string) $a->payroll_concept_version_id][] = (string) $a->base_code;
            }
        }

        $out = [];
        foreach ($rows as $r) {
            $out[] = new ConceptDefinition(
                (string) $r->code, (string) $r->name, (string) $r->kind, $r->formula === null ? null : (string) $r->formula,
                (string) $r->rounding, (int) $r->sort_order, self::pgArray($r->applies_to_regimes), self::pgArray($r->applies_to_period_kinds),
                (string) $r->concept_id, (string) $r->version_id, substr((string) $r->valid_from, 0, 10),
                $r->condition === null || trim((string) $r->condition) === '' ? null : (string) $r->condition, $affects[(string) $r->version_id] ?? [],
            );
        }

        return $out;
    }

    /**
     * Todos los códigos de concepto de la organización (para validar referencias de una fórmula).
     *
     * @return list<string>
     */
    public function codes(): array
    {
        return array_values(DB::table('payroll_concepts')->whereNull('deleted_at')->orderBy('code')->pluck('code')->map(fn ($c) => (string) $c)->all());
    }

    /**
     * @return list<string>|null
     */
    public static function pgArray(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }
        $s = trim((string) $value, '{}');

        return $s === '' ? [] : array_map(fn ($v) => trim($v, '"'), explode(',', $s));
    }
}
