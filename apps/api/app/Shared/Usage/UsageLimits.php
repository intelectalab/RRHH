<?php

namespace App\Shared\Usage;

use App\Shared\Exceptions\PlanLimitReachedException;
use App\Shared\Outbox\Contracts\OutboxWriter;
use App\Shared\Outbox\DomainEventTypes;
use App\Shared\Settings\ParameterResolver;
use App\Shared\Tenancy\TenancyScope;
use App\Shared\Usage\Sources\StorageUsageSource;
use Illuminate\Support\Facades\DB;

/**
 * SAS-6 · Límites y consumo de la organización activa.
 *
 *  - Mide cada métrica con la fuente que registró su módulo y la compara con tenant_limits (lo que fija la
 *    suscripción, SAS-4). Sin fila de límite o con is_unlimited, no hay tope.
 *  - assertCanAdd / assertCanStore bloquean en el servidor la nueva alta o subida que superaría el tope
 *    (RN-SAS-02, RN-SAS-03); lo existente sigue operativo. Tras bajar de plan con consumo por encima del
 *    nuevo límite, las altas quedan bloqueadas hasta regularizar (M-SAS §8).
 *  - record guarda la foto del consumo (tenant_usage_counters) y avisa una sola vez al acercarse al tope
 *    (parámetro limites.porcentaje_alerta) y al llegar a él: eventos limit.warning y limit.reached.
 */
final class UsageLimits
{
    private const DEFAULT_ALERT_PERCENT = 80;

    public function __construct(
        private readonly UsageRegistry $registry,
        private readonly ParameterResolver $parameters,
        private readonly OutboxWriter $outbox,
    ) {}

    /** Consumo actual en centésimas (0 si ningún módulo mide la métrica). */
    public function current(string $metric): int
    {
        return $this->registry->get($metric)?->measure() ?? 0;
    }

    /**
     * Límite efectivo; null si la organización no tiene límite para la métrica.
     *
     * @return array{cents: int|null, unlimited: bool}|null
     */
    public function limit(string $metric): ?array
    {
        $row = DB::table('tenant_limits')->where('limit_code', $metric)->first(['limit_value', 'is_unlimited']);
        if ($row === null) {
            return null;
        }
        $unlimited = (bool) $row->is_unlimited || $row->limit_value === null;

        return ['cents' => $unlimited ? null : UsageAmount::toCents((string) $row->limit_value), 'unlimited' => $unlimited];
    }

    public function alertPercent(): int
    {
        $value = $this->parameters->value('limites.porcentaje_alerta');

        return is_numeric($value) ? max(1, min(99, (int) $value)) : self::DEFAULT_ALERT_PERCENT;
    }

    /**
     * Consumo frente a límites de la organización activa.
     *
     * @return list<array{metric: string, current: string, limit: string|null, unlimited: bool, has_limit: bool, percent: int|null, status: string}>
     */
    public function summary(): array
    {
        $metrics = array_values(array_unique([
            ...UsageMetrics::ALL,
            ...array_keys($this->registry->all()),
            ...DB::table('tenant_limits')->orderBy('limit_code')->pluck('limit_code')->map(fn ($c): string => (string) $c)->all(),
        ]));
        $alert = $this->alertPercent();
        $out = [];
        foreach ($metrics as $metric) {
            $out[] = $this->describe($metric, $this->current($metric), $this->limit($metric), $alert);
        }

        return $out;
    }

    /**
     * @param  array{cents: int|null, unlimited: bool}|null  $limit
     * @return array{metric: string, current: string, limit: string|null, unlimited: bool, has_limit: bool, percent: int|null, status: string}
     */
    public function describe(string $metric, int $current, ?array $limit, ?int $alert = null): array
    {
        $alert ??= $this->alertPercent();
        $cents = $limit['cents'] ?? null;
        $percent = $cents === null ? null : ($cents <= 0 ? ($current > 0 ? 100 : 0) : intdiv($current * 100, $cents));
        $status = match (true) {
            $cents === null => 'ok',
            $current > $cents => 'over',
            $current === $cents || ($cents === 0) => 'reached',
            $percent !== null && $percent >= $alert => 'warning',
            default => 'ok',
        };

        return [
            'metric' => $metric,
            'current' => UsageAmount::format($current),
            'limit' => $cents === null ? null : UsageAmount::format($cents),
            'unlimited' => $limit !== null && $limit['unlimited'],
            'has_limit' => $limit !== null,
            'percent' => $percent,
            'status' => $status,
        ];
    }

    /**
     * Impide agregar $amountCents de una métrica si superaría el límite (403 plan_limit_reached con un
     * mensaje que explica cómo ampliarlo).
     */
    public function assertCanAdd(string $metric, int $amountCents = 100): void
    {
        $limit = $this->limit($metric);
        if ($limit === null || $limit['unlimited'] || $limit['cents'] === null) {
            return;
        }
        $current = $this->current($metric);
        if ($current + $amountCents > $limit['cents']) {
            throw $this->reached($metric, $current, $limit['cents']);
        }
    }

    /** RN-SAS-03 · Una subida que superaría el almacenamiento del plan se rechaza; la lectura sigue. */
    public function assertCanStore(int $bytes): void
    {
        $limit = $this->limit(UsageMetrics::STORAGE_MB);
        if ($limit === null || $limit['unlimited'] || $limit['cents'] === null) {
            return;
        }
        $source = $this->registry->get(UsageMetrics::STORAGE_MB);
        $used = $source instanceof StorageUsageSource ? $source->bytes() : 0;
        // Límite en MB (centésimas) → bytes, sin flotantes.
        $maxBytes = intdiv($limit['cents'] * 1048576, 100);
        if ($used + $bytes > $maxBytes) {
            throw $this->reached(UsageMetrics::STORAGE_MB, UsageAmount::bytesToMbCents($used), $limit['cents']);
        }
    }

    /**
     * Guarda la foto de la métrica y, si cruzó un umbral, avisa una sola vez.
     *
     * @return array{metric: string, current: string, limit: string|null, unlimited: bool, has_limit: bool, percent: int|null, status: string}
     */
    public function record(string $metric): array
    {
        $tenantId = TenancyScope::requireTenant();
        $current = $this->current($metric);
        $now = now();
        DB::table('tenant_usage_counters')->upsert([[
            'tenant_id' => $tenantId,
            'metric_code' => $metric,
            'period_start' => UsageMetrics::periodStart($metric),
            'value' => UsageAmount::format($current),
            'measured_at' => $now,
            'updated_at' => $now,
        ]], ['tenant_id', 'metric_code', 'period_start'], ['value', 'measured_at', 'updated_at']);

        return $this->evaluate($metric, $current);
    }

    /** Foto de todas las métricas medidas (tarea diaria y alta de organización). */
    public function recordAll(): void
    {
        foreach (array_keys($this->registry->all()) as $metric) {
            $this->record($metric);
        }
    }

    /**
     * @return array{metric: string, current: string, limit: string|null, unlimited: bool, has_limit: bool, percent: int|null, status: string}
     */
    private function evaluate(string $metric, int $current): array
    {
        $row = DB::table('tenant_limits')->where('limit_code', $metric)->first(['id', 'limit_value', 'is_unlimited', 'warned_at', 'reached_at']);
        $limit = $row === null ? null : [
            'cents' => ((bool) $row->is_unlimited || $row->limit_value === null) ? null : UsageAmount::toCents((string) $row->limit_value),
            'unlimited' => (bool) $row->is_unlimited || $row->limit_value === null,
        ];
        $described = $this->describe($metric, $current, $limit);
        if ($row === null || $limit === null || $limit['cents'] === null) {
            return $described;
        }

        $changes = [];
        $event = null;
        switch ($described['status']) {
            case 'reached':
            case 'over':
                if ($row->reached_at === null) {
                    $changes = ['reached_at' => now(), 'warned_at' => $row->warned_at ?? now()];
                    $event = DomainEventTypes::LIMIT_REACHED;
                }
                break;
            case 'warning':
                if ($row->warned_at === null) {
                    $changes['warned_at'] = now();
                    $event = DomainEventTypes::LIMIT_WARNING;
                }
                if ($row->reached_at !== null) {
                    $changes['reached_at'] = null;
                }
                break;
            default:
                if ($row->warned_at !== null || $row->reached_at !== null) {
                    $changes = ['warned_at' => null, 'reached_at' => null];
                }
        }
        if ($changes !== []) {
            DB::table('tenant_limits')->where('id', $row->id)->update($changes + ['updated_at' => now()]);
        }
        if ($event !== null) {
            $this->outbox->record($event, 'tenant_limit', (string) $row->id, [
                'metric' => $metric,
                'current' => $described['current'],
                'limit' => $described['limit'],
                'percent' => $described['percent'],
            ]);
        }

        return $described;
    }

    private function reached(string $metric, int $currentCents, int $limitCents): PlanLimitReachedException
    {
        $key = 'usage.reached.'.$metric;
        $message = trans($key, ['limit' => UsageAmount::format($limitCents), 'current' => UsageAmount::format($currentCents)]);

        return new PlanLimitReachedException(
            $metric,
            intdiv($currentCents, 100),
            intdiv($limitCents, 100),
            is_string($message) && $message !== $key ? $message : (string) trans('usage.reached.default', ['limit' => UsageAmount::format($limitCents)]),
        );
    }
}
