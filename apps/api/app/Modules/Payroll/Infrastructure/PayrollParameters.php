<?php

namespace App\Modules\Payroll\Infrastructure;

use App\Modules\Payroll\Domain\Engine\ParameterResolver;
use App\Shared\LegalParameters\LegalParameters;
use App\Shared\Vigencia\EffectiveDated;
use Illuminate\Support\Facades\DB;

/**
 * NOM-1 · RN-NOM-01 · Parámetros con la vigencia de la fecha del periodo: primero el valor propio de la
 * empresa o de la organización (legal_parameter_overrides), luego el del régimen y por último el general
 * del país (tabla legal_parameters, regla 4). Un valor «por validar» se usa y queda marcado en la traza.
 */
final class PayrollParameters implements ParameterResolver
{
    public function __construct(
        private readonly LegalParameters $legal,
        private readonly string $country,
        private readonly ?string $regime,
        private readonly ?string $legalEntityId,
        private readonly string $date,
    ) {}

    public function resolve(string $code): ?array
    {
        $q = DB::table('legal_parameter_overrides')->where('parameter_code', $code)
            ->where(fn ($w) => $w->whereNull('legal_entity_id')->when($this->legalEntityId !== null, fn ($x) => $x->orWhere('legal_entity_id', $this->legalEntityId)));
        EffectiveDated::applyAsOf($q, $this->date);
        $override = $q->orderByRaw('legal_entity_id IS NULL')->first(['value_numeric', 'value_json', 'valid_from', 'valid_to', 'reason']);
        $base = $this->legal->at($this->country, $code, $this->date, $this->regime);
        if ($override !== null) {
            return [
                'code' => $code,
                'value' => $override->value_numeric !== null ? (string) $override->value_numeric : ($override->value_json !== null ? (string) $override->value_json : null),
                'unit' => $base['unit'] ?? 'amount',
                'valid_from' => substr((string) $override->valid_from, 0, 10),
                'valid_to' => $override->valid_to === null ? null : substr((string) $override->valid_to, 0, 10),
                'verified' => true, 'pending_validation' => false, 'legal_source' => (string) $override->reason, 'origin' => 'organization',
            ];
        }
        if ($base === null) {
            return null;
        }
        $value = $base['value'];

        return [
            'code' => $code,
            'value' => is_array($value) ? (string) json_encode($value) : $value,
            'unit' => $base['unit'], 'valid_from' => $base['valid_from'], 'valid_to' => $base['valid_to'],
            'verified' => $base['verified'], 'pending_validation' => $base['pending_validation'], 'legal_source' => $base['legal_source'],
            'origin' => 'country',
        ];
    }
}
