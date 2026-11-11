<?php

namespace App\Modules\Audit\Application;

use App\Shared\Settings\ParameterResolver;
use Carbon\CarbonImmutable;

/**
 * AUD-3 / AUD-5 · Una política de conservación por almacén, cada una configurable por separado:
 * auditoría de negocio y actividad de usuarios por organización (parámetros) y logs técnicos por
 * la plataforma (config/logging.php).
 */
final class RetentionPolicies
{
    public function __construct(private readonly ParameterResolver $parameters) {}

    /** Años de conservación de la auditoría (RN-AUD-06). Con la organización activa. */
    public function auditYears(): int
    {
        return $this->positive($this->parameters->value('privacidad.retencion_auditoria_anios'), 5);
    }

    /** Días de conservación de la actividad de usuarios. Con la organización activa. */
    public function activityDays(): int
    {
        return $this->positive($this->parameters->value('privacidad.retencion_actividad_dias'), 365);
    }

    /** Meses que la auditoría queda en línea antes de pasar al almacenamiento frío. */
    public function hotMonths(): int
    {
        return max(1, (int) config('audit.hot_months', 12));
    }

    public function technicalDays(): int
    {
        return max(1, (int) config('logging.channels.daily.days', 14));
    }

    /** Primer día del mes desde el cual la auditoría sigue en línea (lo anterior se archiva). */
    public function hotCutoff(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC')->startOfMonth()->subMonthsNoOverflow($this->hotMonths());
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function describe(): array
    {
        return [
            'audit' => [
                'store' => 'audit_events',
                'retention_years' => $this->auditYears(),
                'online_months' => $this->hotMonths(),
                'cold_storage' => true,
                'parameter' => 'privacidad.retencion_auditoria_anios',
                'configured_by' => 'organization',
            ],
            'activity' => [
                'store' => 'activity_events',
                'retention_days' => $this->activityDays(),
                'parameter' => 'privacidad.retencion_actividad_dias',
                'configured_by' => 'organization',
            ],
            'technical' => [
                'store' => 'logs',
                'retention_days' => $this->technicalDays(),
                'personal_data' => false,
                'configured_by' => 'platform',
            ],
        ];
    }

    private function positive(mixed $value, int $default): int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : $default;
    }
}
