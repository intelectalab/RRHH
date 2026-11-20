<?php

namespace App\Modules\Payroll\Infrastructure;

use App\Modules\Payroll\Infrastructure\Country\DataPayrollCountryPack;
use App\Shared\Vigencia\EffectiveDated;
use Illuminate\Support\Facades\DB;

/**
 * NOM-2 · Régimen laboral de una relación a una fecha: el propio del trabajador (employment_labor_regimes,
 * con vigencia) o, si no lo tiene, el de su empresa (legal_entity_regimes). Dos trabajadores de la misma
 * empresa pueden estar en regímenes distintos según su fecha de ingreso.
 *
 * NOM-10 · RN-PE-MYP-02/03: un régimen heredado que exige acreditación (micro, pequeña) solo se aplica si la
 * empresa la tiene vigente a la fecha y la relación ingresó en o después de la fecha de acreditación; si no,
 * la relación queda en el régimen por defecto del paquete de país (source = legal_entity_default).
 */
final class RegimeResolver
{
    /**
     * @return array{code: string, source: 'employment'|'legal_entity'|'legal_entity_default', valid_from: string, valid_to: string|null}|null
     */
    public function at(string $employmentId, string $date): ?array
    {
        $own = DB::table('employment_labor_regimes')->where('employment_id', $employmentId)->whereNull('deleted_at')->whereNotNull('labor_regime_code');
        EffectiveDated::applyAsOf($own, $date);
        $row = $own->first(['labor_regime_code', 'valid_from', 'valid_to']);
        $source = 'employment';
        if ($row === null) {
            $emp = DB::table('employments AS e')->join('legal_entities AS l', 'l.id', '=', 'e.legal_entity_id')
                ->where('e.id', $employmentId)->first(['e.legal_entity_id', 'e.hire_date', 'l.country_code']);
            if ($emp === null) {
                return null;
            }
            $q = DB::table('legal_entity_regimes')->where('legal_entity_id', $emp->legal_entity_id);
            EffectiveDated::applyAsOf($q, $date);
            $row = $q->first(['labor_regime_code', 'valid_from', 'valid_to', 'accreditation_status', 'registered_on']);
            $source = 'legal_entity';
            if ($row !== null) {
                $pack = new DataPayrollCountryPack((string) $emp->country_code);
                $strategy = $pack->regime((string) $row->labor_regime_code);
                $default = $pack->defaultRegime();
                $accredited = in_array((string) $row->accreditation_status, ['accredited', 'in_grace_period'], true);
                $since = $row->registered_on === null ? null : substr((string) $row->registered_on, 0, 10);
                $hiredBefore = $since !== null && substr((string) $emp->hire_date, 0, 10) < $since;
                if ($strategy !== null && $strategy['requires_accreditation'] && $default !== null && (! $accredited || $hiredBefore)) {
                    return ['code' => $default, 'source' => 'legal_entity_default',
                        'valid_from' => substr((string) $row->valid_from, 0, 10), 'valid_to' => $row->valid_to === null ? null : substr((string) $row->valid_to, 0, 10)];
                }
            }
        }

        return $row === null ? null : [
            'code' => (string) $row->labor_regime_code, 'source' => $source,
            'valid_from' => substr((string) $row->valid_from, 0, 10), 'valid_to' => $row->valid_to === null ? null : substr((string) $row->valid_to, 0, 10),
        ];
    }

    /**
     * Historia del régimen propio del trabajador (más reciente primero).
     *
     * @return list<array{labor_regime_code: string|null, valid_from: string, valid_to: string|null}>
     */
    public function history(string $employmentId): array
    {
        return array_values(DB::table('employment_labor_regimes')->where('employment_id', $employmentId)->whereNull('deleted_at')->orderByDesc('valid_from')
            ->get(['labor_regime_code', 'valid_from', 'valid_to'])
            ->map(fn ($r) => ['labor_regime_code' => $r->labor_regime_code === null ? null : (string) $r->labor_regime_code,
                'valid_from' => substr((string) $r->valid_from, 0, 10), 'valid_to' => $r->valid_to === null ? null : substr((string) $r->valid_to, 0, 10)])
            ->all());
    }

    /**
     * RN-NOM-19 · Si el régimen exige acreditación, la empresa debe tenerla vigente a la fecha.
     */
    public function entityAccredited(string $legalEntityId, string $regime, string $date): bool
    {
        $q = DB::table('legal_entity_regimes')->where('legal_entity_id', $legalEntityId)->where('labor_regime_code', $regime)
            ->whereIn('accreditation_status', ['accredited', 'in_grace_period']);
        EffectiveDated::applyAsOf($q, $date);

        return $q->exists();
    }
}
