<?php

namespace App\Modules\Platform\Application\Console;

use App\Modules\Platform\Application\PlatformSettings;
use App\Shared\Tenancy\CrossTenantMaintenance;
use App\Shared\Usage\UsageMetrics;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * SAS-10 · Tablero del negocio SaaS. Cada indicador se calcula desde datos reales (suscripciones, organizaciones,
 * consumo medido, módulos activos, incidencias y la auditoría de la consola) y viaja con su definición. Solo cifras
 * agregadas: nada de datos laborales (M-SAS §3). Los montos salen del precio de cada suscripción (o de su plan), en
 * su moneda; el consolidado usa los tipos de cambio que la consola registra en la configuración global
 * (dashboard.exchange_rates) hacia dashboard.report_currency, y lista las monedas sin tipo en lugar de inventarlo.
 */
final class BusinessMetrics
{
    /** Estados de suscripción que facturan. */
    private const BILLING = ['active', 'past_due'];

    /** Estados de organización que se informan como suspendidas. */
    private const SUSPENDED = ['suspended', 'blocked', 'grace_period'];

    private const SCALE = 2;

    public function __construct(
        private readonly CrossTenantMaintenance $cross,
        private readonly PlatformSettings $settings,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function compute(string $from, string $to, ?string $planId): array
    {
        $start = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->endOfDay();
        $tenantIds = $planId === null ? null : DB::table('subscriptions')->where('plan_id', $planId)->pluck('tenant_id')->map(fn ($id): string => (string) $id)->all();
        $tenants = DB::table('tenants')->whereNull('deleted_at')->when($tenantIds !== null, fn ($q) => $q->whereIn('id', $tenantIds));

        /** @var array<string, int> $byStatus */
        $byStatus = (clone $tenants)->selectRaw('status, count(*) AS n')->groupBy('status')->pluck('n', 'status')->map(fn ($n): int => (int) $n)->all();
        $subs = DB::table('subscriptions as s')->join('plans as p', 'p.id', '=', 's.plan_id')
            ->when($planId !== null, fn ($q) => $q->where('s.plan_id', $planId));

        $trials = (clone $subs)->whereNotNull('s.trial_ends_on')->whereBetween('s.created_at', [$start, $end])->count();
        $cancellations = (clone $subs)->whereBetween('s.cancelled_at', [$start, $end])->count();
        $subscriptionIds = $planId === null ? null : (clone $subs)->pluck('s.id')->map(fn ($id): string => (string) $id)->all();
        $conversions = DB::table('platform_audit_events')->where('action', 'subscription.convert')->whereBetween('occurred_at', [$start, $end])
            ->when($subscriptionIds !== null, fn ($q) => $q->whereIn('entity_id', $subscriptionIds))->count();

        [$mrr, $arr] = $this->revenue((clone $subs)->whereIn('s.status', self::BILLING)->get([
            DB::raw('COALESCE(s.price, p.price) AS price'), DB::raw('COALESCE(s.currency, p.currency) AS currency'), 's.billing_cycle',
        ]));
        $reportCurrency = strtoupper((string) ($this->settings->value('dashboard.report_currency') ?? 'PEN'));
        $rates = (array) ($this->settings->value('dashboard.exchange_rates') ?? []);

        $usage = $this->cross->readAcrossTenants(fn (): array => [
            'employees' => $this->latestUsage(UsageMetrics::EMPLOYEES, $tenantIds),
            'storage' => $this->latestUsage(UsageMetrics::STORAGE_MB, $tenantIds),
            'users' => DB::table('users')->whereNull('deleted_at')->where('status', 'active')->when($tenantIds !== null, fn ($q) => $q->whereIn('tenant_id', $tenantIds))->count(),
            'modules' => DB::table('tenant_modules')->whereIn('status', ['active', 'trial'])->when($tenantIds !== null, fn ($q) => $q->whereIn('tenant_id', $tenantIds))
                ->selectRaw('module_code, count(DISTINCT tenant_id) AS n')->groupBy('module_code')->orderByDesc('n')->orderBy('module_code')->limit(5)->get(),
        ]);
        $moduleNames = DB::table('modules')->pluck('name', 'code');

        $incidents = DB::table('platform_incidents');
        $converted = $trials > 0 ? (string) BigDecimal::of($conversions)->multipliedBy(100)->dividedBy($trials, 1, RoundingMode::HALF_UP) : null;

        return [
            'period' => ['from' => $start->toDateString(), 'to' => $end->toDateString(), 'plan_id' => $planId],
            'tenants' => [
                'total' => array_sum($byStatus),
                'by_status' => (object) $byStatus,
                'active' => $byStatus['active'] ?? 0,
                'trial' => $byStatus['trial'] ?? 0,
                'suspended' => array_sum(array_intersect_key($byStatus, array_flip(self::SUSPENDED))),
                'new' => (clone $tenants)->whereBetween('created_at', [$start, $end])->count(),
                'new_last_30_days' => (clone $tenants)->where('created_at', '>=', now()->subDays(30))->count(),
                'demo' => (clone $tenants)->where('is_demo', true)->count(),
            ],
            'users' => ['active' => (int) $usage['users']],
            'console_users' => DB::table('platform_users')->whereNull('deleted_at')->where('status', 'active')->count(),
            'employees_managed' => (int) $usage['employees'],
            'storage_mb' => (string) $usage['storage'],
            'subscriptions' => ['trials_started' => $trials, 'conversions' => $conversions, 'conversion_rate_pct' => $converted, 'cancellations' => $cancellations],
            'revenue' => [
                'mrr' => $mrr,
                'arr' => $arr,
                'consolidated' => $this->consolidate($mrr, $reportCurrency, $rates),
            ],
            'modules' => $usage['modules']->map(fn ($m): array => ['code' => (string) $m->module_code, 'name' => (string) ($moduleNames[$m->module_code] ?? $m->module_code), 'tenants' => (int) $m->n])->values()->all(),
            'incidents' => [
                'open' => (clone $incidents)->where('status', '<>', 'resolved')->count(),
                'in_period' => (clone $incidents)->whereBetween('started_at', [$start, $end])->count(),
            ],
            'definitions' => $this->definitions(),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Ingreso recurrente mensual por moneda (un ciclo anual se reparte en doce) y su anual.
     *
     * @param  Collection<int, \stdClass>  $rows
     * @return array{0: list<array{amount: string, currency: string}>, 1: list<array{amount: string, currency: string}>}
     */
    private function revenue(Collection $rows): array
    {
        /** @var array<string, BigDecimal> $sum */
        $sum = [];
        foreach ($rows as $r) {
            if ($r->price === null || $r->currency === null) {
                continue;
            }
            $monthly = $r->billing_cycle === 'annual'
                ? BigDecimal::of((string) $r->price)->dividedBy(12, 6, RoundingMode::HALF_UP)
                : BigDecimal::of((string) $r->price);
            $c = (string) $r->currency;
            $sum[$c] = isset($sum[$c]) ? $sum[$c]->plus($monthly) : $monthly;
        }
        ksort($sum);
        $mrr = [];
        $arr = [];
        foreach ($sum as $currency => $amount) {
            $mrr[] = ['amount' => (string) $amount->toScale(self::SCALE, RoundingMode::HALF_UP), 'currency' => (string) $currency];
            $arr[] = ['amount' => (string) $amount->multipliedBy(12)->toScale(self::SCALE, RoundingMode::HALF_UP), 'currency' => (string) $currency];
        }

        return [$mrr, $arr];
    }

    /**
     * @param  list<array{amount: string, currency: string}>  $mrr
     * @param  array<mixed>  $rates  moneda => unidades de la moneda de reporte por unidad
     * @return array{currency: string, mrr: array{amount: string, currency: string}, arr: array{amount: string, currency: string}, missing_rates: list<string>}
     */
    private function consolidate(array $mrr, string $currency, array $rates): array
    {
        $missing = [];
        $total = BigDecimal::zero();
        foreach ($mrr as $m) {
            if ($m['currency'] === $currency) {
                $total = $total->plus(BigDecimal::of($m['amount']));

                continue;
            }
            $rate = $rates[$m['currency']] ?? null;
            if (! is_numeric($rate)) {
                $missing[] = $m['currency'];

                continue;
            }
            $total = $total->plus(BigDecimal::of($m['amount'])->multipliedBy(BigDecimal::of((string) $rate)));
        }

        return [
            'currency' => $currency,
            'mrr' => ['amount' => (string) $total->toScale(self::SCALE, RoundingMode::HALF_UP), 'currency' => $currency],
            'arr' => ['amount' => (string) $total->multipliedBy(12)->toScale(self::SCALE, RoundingMode::HALF_UP), 'currency' => $currency],
            'missing_rates' => $missing,
        ];
    }

    /**
     * Última medición de cada organización (una foto por día), sumada.
     *
     * @param  array<int, string>|null  $tenantIds
     */
    private function latestUsage(string $metric, ?array $tenantIds): string
    {
        $latest = DB::table('tenant_usage_counters')->where('metric_code', $metric)
            ->when($tenantIds !== null, fn ($q) => $q->whereIn('tenant_id', $tenantIds))
            ->selectRaw('tenant_id, max(period_start) AS d')->groupBy('tenant_id');
        $value = DB::table('tenant_usage_counters as c')->joinSub($latest, 'l', fn ($j) => $j->on('l.tenant_id', '=', 'c.tenant_id')->on('l.d', '=', 'c.period_start'))
            ->where('c.metric_code', $metric)->sum('c.value');

        return (string) BigDecimal::of((string) $value)->toScale(self::SCALE, RoundingMode::HALF_UP);
    }

    /**
     * @return array<string, string>
     */
    private function definitions(): array
    {
        $out = [];
        foreach (['tenants_active', 'tenants_trial', 'tenants_suspended', 'tenants_new', 'users_active', 'employees_managed', 'storage_mb', 'mrr', 'arr',
            'consolidated', 'trials_started', 'conversions', 'conversion_rate_pct', 'cancellations', 'modules', 'incidents_open', 'incidents_in_period'] as $key) {
            $out[$key] = (string) trans('platform.metrics.definitions.'.$key);
        }

        return $out;
    }
}
