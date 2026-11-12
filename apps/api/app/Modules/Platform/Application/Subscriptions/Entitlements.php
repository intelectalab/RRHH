<?php

namespace App\Modules\Platform\Application\Subscriptions;

use App\Modules\Platform\Application\Tenants\TenantModules;
use App\Modules\Platform\Infrastructure\Models\PlatformUser;
use App\Shared\Exceptions\BusinessRuleViolatedException;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Support\Facades\DB;

/**
 * SAS-4 · La suscripción es la fuente de lo que la organización puede usar: lleva a tenant_modules los
 * módulos del plan y de los complementos vigentes, y a tenant_limits sus límites (plan + ampliaciones).
 *
 * Subir de plan o sumar un complemento activa los módulos al instante (SAS-5, sin migrar ni reiniciar);
 * bajar o quitar un complemento los retira a solo lectura (RN-SAS-06). Lo activado a mano desde la consola
 * (source = manual) y los ajustes de límites (override) no se tocan.
 */
final class Entitlements
{
    public function __construct(private readonly TenantModules $modules) {}

    /**
     * @return array{activated: list<string>, retired: list<string>, kept: list<string>}
     */
    public function sync(string $subscriptionId, ?PlatformUser $actor): array
    {
        $subscription = DB::table('subscriptions')->where('id', $subscriptionId)->first(['id', 'tenant_id', 'plan_id']);
        if ($subscription === null) {
            return ['activated' => [], 'retired' => [], 'kept' => []];
        }
        $tenantId = (string) $subscription->tenant_id;
        $addons = $this->currentAddons($subscriptionId);

        // Módulos que debe tener la organización y de dónde vienen (el plan manda sobre el complemento).
        $wanted = [];
        foreach (DB::table('addon_modules')->whereIn('addon_id', array_keys($addons))->pluck('module_code') as $code) {
            $wanted[(string) $code] = 'addon';
        }
        foreach (DB::table('plan_modules')->where('plan_id', $subscription->plan_id)->pluck('module_code') as $code) {
            $wanted[(string) $code] = 'plan';
        }

        $activated = [];
        foreach ($wanted as $code => $source) {
            $activated = [...$activated, ...$this->modules->activate($tenantId, $code, $actor, $source)];
        }

        $retired = [];
        $kept = [];
        $core = DB::table('modules')->where('is_core', true)->pluck('code')->map(fn ($c): string => (string) $c)->all();
        $pending = TenancyScope::runAs($tenantId, fn () => DB::table('tenant_modules')->whereIn('status', ['active', 'trial'])
            ->whereIn('source', ['plan', 'addon'])->pluck('module_code')->map(fn ($c): string => (string) $c)->all());
        $pending = array_values(array_diff($pending, array_keys($wanted), $core));
        // Se retiran primero los que nadie necesita; uno del que depende un módulo activado a mano se conserva.
        while ($pending !== []) {
            $progress = false;
            foreach ($pending as $i => $code) {
                try {
                    $this->modules->deactivate($tenantId, $code, $actor);
                    $retired[] = $code;
                    unset($pending[$i]);
                    $progress = true;
                } catch (BusinessRuleViolatedException $e) {
                    if (($e->getMeta()['rule'] ?? null) !== 'module_required_by') {
                        throw $e;
                    }
                }
            }
            if (! $progress) {
                $kept = array_values($pending);
                break;
            }
        }

        $this->syncLimits($tenantId, (string) $subscription->plan_id, $addons);

        return ['activated' => array_values(array_unique($activated)), 'retired' => $retired, 'kept' => $kept];
    }

    /**
     * Complementos vigentes hoy (sin fecha de fin o con fin futura), con su cantidad.
     *
     * @return array<string, int> addon_id → cantidad
     */
    private function currentAddons(string $subscriptionId): array
    {
        $today = now()->toDateString();

        return DB::table('subscription_addons')->where('subscription_id', $subscriptionId)
            ->where('starts_on', '<=', $today)
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>', $today))
            ->get(['addon_id', 'quantity'])
            ->groupBy('addon_id')
            ->map(fn ($rows): int => (int) $rows->sum('quantity'))
            ->mapWithKeys(fn (int $n, $id): array => [(string) $id => $n])
            ->all();
    }

    /**
     * @param  array<string, int>  $addons
     */
    private function syncLimits(string $tenantId, string $planId, array $addons): void
    {
        $limits = [];
        foreach (DB::table('plan_limits')->where('plan_id', $planId)->get(['limit_code', 'limit_value', 'is_unlimited']) as $l) {
            $limits[(string) $l->limit_code] = [
                'value' => $l->limit_value === null ? null : (string) $l->limit_value,
                'unlimited' => (bool) $l->is_unlimited, 'source' => 'plan',
            ];
        }
        $increments = DB::table('addons')->whereIn('id', array_keys($addons))->where('kind', 'limit')
            ->whereNotNull('limit_code')->get(['id', 'limit_code', 'limit_increment']);
        foreach ($increments as $a) {
            $code = (string) $a->limit_code;
            $current = $limits[$code] ?? ['value' => '0', 'unlimited' => false, 'source' => 'addon'];
            if (! $current['unlimited']) {
                // Decimal exacto en la base (regla 12: nunca float).
                $current['value'] = (string) DB::selectOne('SELECT (?::numeric + ?::numeric * ?::numeric)::numeric(14,2)::text AS v', [
                    $current['value'] ?? '0', (string) ($a->limit_increment ?? '0'), (string) $addons[(string) $a->id],
                ])->v;
                $current['source'] = 'addon';
            }
            $limits[$code] = $current;
        }

        TenancyScope::runAs($tenantId, function () use ($tenantId, $limits): void {
            $overrides = DB::table('tenant_limits')->where('source', 'override')->pluck('limit_code')->map(fn ($c): string => (string) $c)->all();
            DB::table('tenant_limits')->whereIn('source', ['plan', 'addon'])->whereNotIn('limit_code', array_keys($limits))->delete();
            foreach ($limits as $code => $limit) {
                if (in_array($code, $overrides, true)) {
                    continue;
                }
                DB::table('tenant_limits')->upsert([[
                    'tenant_id' => $tenantId, 'limit_code' => $code, 'limit_value' => $limit['value'],
                    'is_unlimited' => $limit['unlimited'], 'source' => $limit['source'],
                ]], ['tenant_id', 'limit_code'], ['limit_value', 'is_unlimited', 'source', 'updated_at']);
            }
        });
    }
}
