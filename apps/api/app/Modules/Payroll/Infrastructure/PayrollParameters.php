<?php

namespace App\Modules\Payroll\Infrastructure;

use App\Modules\Payroll\Domain\Engine\ParameterResolver;
use App\Shared\LegalParameters\LegalParameters;
use App\Shared\LegalParameters\PensionFundRates;
use App\Shared\Vigencia\EffectiveDated;
use Illuminate\Support\Facades\DB;

/**
 * NOM-1 · RN-NOM-01 · Parámetros con la vigencia de la fecha del periodo: primero el valor propio de la
 * empresa o de la organización (legal_parameter_overrides), luego el del régimen y por último el general
 * del país (tabla legal_parameters, regla 4). Un valor «por validar» se usa y queda marcado en la traza.
 *
 * PAR-2 · Las tasas que varían por administradora (aporte, comisión, prima y remuneración máxima asegurable)
 * salen de la tabla de la AFP del trabajador para el periodo (pension_fund_rates); si la AFP no tiene la tabla
 * del periodo, el parámetro falta y el cálculo queda «con error» (RN-PAR-04).
 */
final class PayrollParameters implements ParameterResolver
{
    public function __construct(
        private readonly LegalParameters $legal,
        private readonly string $country,
        private readonly ?string $regime,
        private readonly ?string $legalEntityId,
        private readonly string $date,
        private readonly ?string $employmentId = null,
    ) {}

    public function resolve(string $code): ?array
    {
        if ($this->employmentId !== null && PensionFundRates::isFundParameter($code)) {
            $fund = $this->fundRate($code);
            if ($fund !== false) {
                return $fund;
            }
        }
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

    /**
     * Tasa de la AFP del trabajador a la fecha. false si el trabajador no está en una AFP (se usa el parámetro
     * general); null si lo está y su AFP no tiene la tabla del periodo (el parámetro falta).
     *
     * @return array{code: string, value: string|null, unit: string, valid_from: string|null, valid_to: string|null, verified: bool, pending_validation: bool, legal_source: string|null, origin: string}|null|false
     */
    private function fundRate(string $code): array|null|false
    {
        $q = DB::table('employment_pension_affiliations')->where('employment_id', $this->employmentId);
        EffectiveDated::applyAsOf($q, $this->date);
        $aff = $q->first(['pension_system', 'pension_fund_code']);
        if ($aff === null || $aff->pension_system !== 'afp' || $aff->pension_fund_code === null) {
            return false;
        }
        $rate = app(PensionFundRates::class)->at((string) $aff->pension_fund_code, $this->date);
        $value = $rate === null ? null : PensionFundRates::valueFor($rate, $code);
        if ($value === null) {
            return null;
        }

        return [
            'code' => $code, 'value' => $value, 'unit' => $code === 'afp_remuneracion_maxima_asegurable' ? 'amount' : 'percent',
            'valid_from' => (string) $rate['valid_from'], 'valid_to' => $rate['valid_to'] === null ? null : (string) $rate['valid_to'],
            'verified' => (bool) $rate['verified'], 'pending_validation' => ! (bool) $rate['verified'], 'legal_source' => $rate['source'] === null ? null : (string) $rate['source'],
            'origin' => 'pension_fund:'.(string) $rate['fund_code'],
        ];
    }
}
