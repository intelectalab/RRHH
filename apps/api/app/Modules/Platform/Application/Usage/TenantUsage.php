<?php

namespace App\Modules\Platform\Application\Usage;

use App\Shared\Exceptions\InvalidFilterException;
use App\Shared\Tenancy\TenancyScope;
use App\Shared\Usage\UsageAmount;
use App\Shared\Usage\UsageLimits;
use App\Shared\Usage\UsageMetrics;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * SAS-6 · Consumo de una organización visto desde la consola: actual frente a límites, historia por día y
 * comparación con otro plan antes de cambiarlo (M-SAS §6: «el sistema compara límites con el consumo
 * actual»). Solo cifras (RN-SAS-12).
 */
final class TenantUsage
{
    /** Días de historia por defecto en la ficha. */
    private const DEFAULT_HISTORY_DAYS = 90;

    public function __construct(private readonly UsageLimits $usage) {}

    /**
     * @return array{metrics: list<array<string, mixed>>, alert_percent: int, history: list<array{metric: string, period_start: string, value: string}>, from: string, to: string}
     */
    public function show(string $tenantId, ?string $from, ?string $to): array
    {
        $to = $to === null ? now()->toImmutable() : Carbon::parse($to)->toImmutable();
        $from = $from === null ? $to->subDays(self::DEFAULT_HISTORY_DAYS - 1) : Carbon::parse($from)->toImmutable();
        if ($from->greaterThan($to) || $from->diffInDays($to) > 731) {
            throw new InvalidFilterException((string) trans('platform.usage.invalid_range'), ['from', 'to']);
        }

        return TenancyScope::runAs($tenantId, function () use ($from, $to): array {
            $history = DB::table('tenant_usage_counters')
                ->whereBetween('period_start', [$from->startOfMonth()->toDateString(), $to->toDateString()])
                ->orderBy('metric_code')->orderBy('period_start')
                ->get(['metric_code', 'period_start', 'value'])
                ->filter(fn ($r): bool => in_array((string) $r->metric_code, UsageMetrics::MONTHLY, true)
                    || Carbon::parse((string) $r->period_start)->greaterThanOrEqualTo($from->startOfDay()))
                ->map(fn ($r): array => [
                    'metric' => (string) $r->metric_code,
                    'period_start' => Carbon::parse((string) $r->period_start)->toDateString(),
                    'value' => UsageAmount::format(UsageAmount::toCents((string) $r->value)),
                ])->values()->all();
            $history = array_values($history);

            return [
                'metrics' => $this->usage->summary(),
                'alert_percent' => $this->usage->alertPercent(),
                'history' => $history,
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ];
        });
    }

    /**
     * Compara el consumo actual con los límites de otro plan (los complementos y ajustes vigentes se
     * suman igual que en la suscripción). «over» = el consumo ya supera el límite del plan elegido: el
     * cambio se permite, pero las altas quedan bloqueadas hasta regularizar (M-SAS §8).
     *
     * @return list<array{metric: string, current: string, limit: string|null, unlimited: bool, over: bool}>
     */
    public function planFit(string $tenantId, string $subscriptionId, string $planId): array
    {
        $limits = [];
        foreach (DB::table('plan_limits')->where('plan_id', $planId)->get(['limit_code', 'limit_value', 'is_unlimited']) as $l) {
            $unlimited = (bool) $l->is_unlimited || $l->limit_value === null;
            $limits[(string) $l->limit_code] = $unlimited ? null : UsageAmount::toCents((string) $l->limit_value);
        }
        $today = now()->toDateString();
        $addons = DB::table('subscription_addons as sa')->join('addons as a', 'a.id', '=', 'sa.addon_id')
            ->where('sa.subscription_id', $subscriptionId)->where('sa.starts_on', '<=', $today)
            ->where(fn ($q) => $q->whereNull('sa.ends_on')->orWhere('sa.ends_on', '>', $today))
            ->where('a.kind', 'limit')->whereNotNull('a.limit_code')
            ->get(['a.limit_code', 'a.limit_increment', 'sa.quantity']);
        foreach ($addons as $a) {
            $code = (string) $a->limit_code;
            if (array_key_exists($code, $limits) && $limits[$code] === null) {
                continue;
            }
            $limits[$code] = ($limits[$code] ?? 0) + UsageAmount::toCents((string) ($a->limit_increment ?? '0')) * (int) $a->quantity;
        }

        return TenancyScope::runAs($tenantId, function () use ($limits): array {
            $overrides = DB::table('tenant_limits')->where('source', 'override')->get(['limit_code', 'limit_value', 'is_unlimited']);
            foreach ($overrides as $o) {
                $limits[(string) $o->limit_code] = ((bool) $o->is_unlimited || $o->limit_value === null) ? null : UsageAmount::toCents((string) $o->limit_value);
            }
            $out = [];
            foreach (array_values(array_unique([...UsageMetrics::ALL, ...array_keys($limits)])) as $metric) {
                $current = $this->usage->current($metric);
                $has = array_key_exists($metric, $limits);
                $limit = $has ? $limits[$metric] : null;
                $out[] = [
                    'metric' => $metric,
                    'current' => UsageAmount::format($current),
                    'limit' => $limit === null ? null : UsageAmount::format($limit),
                    'unlimited' => ! $has || $limit === null,
                    'over' => $limit !== null && $current > $limit,
                ];
            }

            return $out;
        });
    }

    /**
     * Foto diaria del consumo de todas las organizaciones que operan (usage:snapshot).
     *
     * @return int organizaciones medidas
     */
    public function snapshotAll(): int
    {
        $ids = DB::table('tenants')->whereNull('deleted_at')->whereIn('status', ['trial', 'active', 'grace_period', 'suspended', 'blocked'])
            ->orderBy('id')->pluck('id')->map(fn ($id): string => (string) $id)->all();
        foreach ($ids as $id) {
            TenancyScope::runAs($id, fn () => $this->usage->recordAll());
        }

        return count($ids);
    }
}
