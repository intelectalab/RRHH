<?php

declare(strict_types=1);

namespace App\Payroll\Parameters;

use App\Models\LegalParameter;
use App\Models\PensionFundRate;
use Brick\Math\BigDecimal;
use DateTimeInterface;

/**
 * Lee legal_parameters y pension_fund_rates. Guarda en memoria lo leído durante la solicitud o el
 * trabajo, porque un cálculo de planilla consulta los mismos parámetros para cada colaborador.
 */
final class EloquentParameterRepository implements ParameterRepository
{
    /** @var array<string, ParameterValue|null> */
    private array $memo = [];

    public function __construct(private readonly string $country) {}

    public function get(string $code, ?string $regimeCode, DateTimeInterface $date): ParameterValue
    {
        return $this->find($code, $regimeCode, $date)
            ?? throw MissingParameterException::notFound($code, $regimeCode, $date->format('Y-m-d'));
    }

    public function find(string $code, ?string $regimeCode, DateTimeInterface $date): ?ParameterValue
    {
        $day = $date->format('Y-m-d');
        $key = "{$code}|{$regimeCode}|{$day}";
        if (array_key_exists($key, $this->memo)) {
            return $this->memo[$key];
        }

        $row = null;
        foreach ($regimeCode !== null ? [$regimeCode, null] : [null] as $regime) {
            $row = LegalParameter::query()
                ->where('country_code', $this->country)
                ->where('code', $code)
                ->when($regime === null, fn ($q) => $q->whereNull('labor_regime_code'), fn ($q) => $q->where('labor_regime_code', $regime))
                ->vigentAt($day)
                ->first();
            if ($row !== null) {
                break;
            }
        }

        return $this->memo[$key] = $row === null ? null : new ParameterValue(
            code: $row->code,
            regimeCode: $row->labor_regime_code,
            value: $row->value_numeric === null ? null : BigDecimal::of($row->value_numeric),
            unit: $row->unit,
            verified: (bool) $row->verified,
            validFrom: $row->valid_from->format('Y-m-d'),
            legalSource: $row->legal_source,
            json: $row->value_json,
        );
    }

    public function pensionFundRates(string $fundCode, DateTimeInterface $date): PensionFundRates
    {
        $day = $date->format('Y-m-d');
        $row = PensionFundRate::query()
            ->whereHas('fund', fn ($q) => $q->where('code', $fundCode))
            ->vigentAt($day)
            ->first();

        if ($row === null || $row->contribution_rate === null || $row->insurance_premium_rate === null || $row->max_insurable_remuneration === null) {
            throw MissingParameterException::pensionRates($fundCode, $day);
        }

        return new PensionFundRates(
            fundCode: $fundCode,
            contributionRate: BigDecimal::of($row->contribution_rate),
            commissionFlowRate: $row->commission_flow_rate === null ? null : BigDecimal::of($row->commission_flow_rate),
            commissionMixedRate: $row->commission_mixed_rate === null ? null : BigDecimal::of($row->commission_mixed_rate),
            insurancePremiumRate: BigDecimal::of($row->insurance_premium_rate),
            maxInsurableRemuneration: BigDecimal::of($row->max_insurable_remuneration),
            verified: (bool) $row->verified,
            validFrom: $row->valid_from->format('Y-m-d'),
        );
    }
}
