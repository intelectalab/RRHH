<?php

namespace App\Modules\Platform\Application\Subscriptions;

use App\Modules\Platform\Application\Console\ConsoleAudit;
use App\Modules\Platform\Application\Plans\AddonCatalog;
use App\Modules\Platform\Application\Plans\PlanCatalog;
use App\Modules\Platform\Application\PlatformSettings;
use App\Modules\Platform\Application\Tenants\TenantAdministration;
use App\Modules\Platform\Domain\SubscriptionStateMachine;
use App\Modules\Platform\Domain\TenantStateMachine;
use App\Modules\Platform\Infrastructure\Models\PlatformUser;
use App\Shared\Exceptions\BusinessRuleViolatedException;
use App\Shared\Outbox\Contracts\OutboxWriter;
use App\Shared\Outbox\DomainEventTypes;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * SAS-4 · Suscripción de cada organización: plan, complementos, prueba con fecha de fin, cambio de plan,
 * renovación y cancelación. Es la fuente de lo que la organización puede usar (Entitlements la lleva a
 * módulos y límites) y arrastra el estado de la organización según ME-40.
 *
 * Una organización tiene una sola suscripción; su historia (cada cambio con su autor) es la auditoría
 * de la consola sobre esa suscripción.
 */
final class SubscriptionService
{
    /** Acciones que exigen motivo (queda en el historial y en el aviso a la organización). */
    private const REASON_REQUIRED = [SubscriptionStateMachine::SUSPEND, SubscriptionStateMachine::CANCEL];

    public function __construct(
        private readonly SubscriptionStateMachine $machine,
        private readonly PlanCatalog $plans,
        private readonly AddonCatalog $addons,
        private readonly Entitlements $entitlements,
        private readonly TenantAdministration $tenants,
        private readonly ConsoleAudit $audit,
        private readonly OutboxWriter $outbox,
        private readonly PlatformSettings $settings,
    ) {}

    /**
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function list(int $page, int $perPage, ?string $status, ?string $tenantId): array
    {
        $query = DB::table('subscriptions AS s')->join('tenants AS t', 't.id', '=', 's.tenant_id')->whereNull('t.deleted_at')
            ->when($status !== null && $status !== '', fn ($w) => $w->where('s.status', $status))
            ->when($tenantId !== null && $tenantId !== '', fn ($w) => $w->where('s.tenant_id', $tenantId));
        $total = (clone $query)->count();
        $rows = $query->orderBy('t.name')->forPage($page, $perPage)->get(['s.*']);

        return [
            'data' => array_values($rows->map(fn ($r): array => $this->present($r))->all()),
            'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function show(string $id): array
    {
        return $this->present($this->find($id));
    }

    /**
     * Suscripción de una organización, si la tiene.
     */
    public function forTenant(string $tenantId): ?\stdClass
    {
        $row = DB::table('subscriptions')->where('tenant_id', $tenantId)->first();

        return $row instanceof \stdClass ? $row : null;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function create(array $input, ?PlatformUser $actor): array
    {
        $tenant = $this->tenants->find((string) $input['tenant_id']);
        if (in_array($tenant->status, ['cancelled', 'deleted'], true)) {
            throw new BusinessRuleViolatedException('tenant_cancelled', (string) trans('platform.subscriptions.tenant_cancelled'));
        }
        if ($this->forTenant((string) $tenant->id) !== null) {
            throw new BusinessRuleViolatedException('subscription_exists', (string) trans('platform.subscriptions.exists'));
        }
        $plan = $this->sellablePlan((string) $input['plan_id']);

        $trialEnds = $this->trialEnd($input, $tenant, $plan);
        $status = $trialEnds !== null ? 'trialing' : 'active';
        $id = (string) Str::uuid();

        DB::transaction(function () use ($id, $tenant, $plan, $input, $trialEnds, $status, $actor): void {
            DB::table('subscriptions')->insert([
                'id' => $id, 'tenant_id' => $tenant->id, 'plan_id' => $plan->id, 'status' => $status,
                'billing_cycle' => $input['billing_cycle'] ?? $plan->billing_cycle,
                'price' => $plan->price, 'currency' => $plan->currency,
                'starts_on' => $input['starts_on'] ?? now()->toDateString(), 'ends_on' => $input['ends_on'] ?? null,
                'trial_ends_on' => $trialEnds,
            ]);
            if ($trialEnds !== null) {
                DB::table('tenants')->where('id', $tenant->id)->update(['trial_ends_at' => Carbon::parse($trialEnds)->endOfDay()]);
            }
            $changes = $this->entitlements->sync($id, $actor);
            if ($status === 'active') {
                $this->tenants->transitionIfAllowed((string) $tenant->id, TenantStateMachine::ACTIVATE, null, $actor);
            }
            $this->audit->record('subscription.created', $actor, 'subscription', $id, null,
                $this->snapshot($id) + ['modules_activated' => $changes['activated']], (string) $tenant->id);
            $this->changed($id);
        });

        return $this->show($id);
    }

    /**
     * Datos de la suscripción (ciclo, precio pactado, fechas). El estado no se edita: cambia por acciones.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function update(string $id, array $input, PlatformUser $actor): array
    {
        $sub = $this->find($id);
        $columns = [];
        foreach (['billing_cycle', 'ends_on'] as $field) {
            if (array_key_exists($field, $input)) {
                $columns[$field] = $input[$field];
            }
        }
        if (array_key_exists('price', $input)) {
            $price = (array) ($input['price'] ?? []);
            if ($input['price'] !== null && ! DB::table('currencies')->where('code', (string) ($price['currency'] ?? ''))->exists()) {
                throw ValidationException::withMessages(['price.currency' => [(string) trans('platform.tenants.unknown_currency')]]);
            }
            $columns['price'] = $input['price'] === null ? null : (string) $price['amount'];
            $columns['currency'] = $input['price'] === null ? null : (string) $price['currency'];
        }
        if (array_key_exists('trial_ends_on', $input)) {
            if ($sub->status !== 'trialing') {
                throw new BusinessRuleViolatedException('not_trialing', (string) trans('platform.subscriptions.not_trialing'));
            }
            $columns['trial_ends_on'] = $input['trial_ends_on'];
        }
        if ($columns === []) {
            return $this->show($id);
        }

        $before = $this->snapshot($id);
        DB::transaction(function () use ($sub, $columns, $actor, $before): void {
            DB::table('subscriptions')->where('id', $sub->id)->update($columns);
            if (isset($columns['trial_ends_on'])) {
                DB::table('tenants')->where('id', $sub->tenant_id)->where('status', 'trial')
                    ->update(['trial_ends_at' => Carbon::parse((string) $columns['trial_ends_on'])->endOfDay()]);
            }
            $this->audit->record('subscription.updated', $actor, 'subscription', (string) $sub->id, $before, $this->snapshot((string) $sub->id), (string) $sub->tenant_id);
            $this->changed((string) $sub->id);
        });

        return $this->show($id);
    }

    /**
     * Cambio de plan: subir activa los módulos al instante; bajar los retira a solo lectura (RN-SAS-06).
     * El precio pasa a ser el del plan nuevo.
     *
     * @return array<string, mixed>
     */
    public function changePlan(string $id, string $planId, PlatformUser $actor): array
    {
        $sub = $this->find($id);
        if (in_array($sub->status, ['cancelled'], true)) {
            throw new BusinessRuleViolatedException('subscription_cancelled', (string) trans('platform.subscriptions.cancelled'));
        }
        $plan = $this->sellablePlan($planId);
        if ((string) $plan->id === (string) $sub->plan_id) {
            throw new BusinessRuleViolatedException('same_plan', (string) trans('platform.subscriptions.same_plan'));
        }
        $this->assertAddonsFit($sub, (string) $plan->id);

        $before = $this->snapshot($id);
        DB::transaction(function () use ($sub, $plan, $actor, $before): void {
            DB::table('subscriptions')->where('id', $sub->id)->update([
                'plan_id' => $plan->id, 'price' => $plan->price, 'currency' => $plan->currency, 'billing_cycle' => $plan->billing_cycle,
            ]);
            $changes = $this->entitlements->sync((string) $sub->id, $actor);
            $this->audit->record('subscription.plan_changed', $actor, 'subscription', (string) $sub->id, $before,
                $this->snapshot((string) $sub->id) + ['modules_activated' => $changes['activated'], 'modules_retired' => $changes['retired']], (string) $sub->tenant_id);
            $this->changed((string) $sub->id);
        });

        return $this->show($id);
    }

    /**
     * Cambio de estado por acción (regla 3) y, con él, el de la organización (ME-40).
     *
     * @return array<string, mixed>
     */
    public function transition(string $id, string $action, ?string $reason, ?PlatformUser $actor): array
    {
        $sub = $this->find($id);
        $from = (string) $sub->status;
        $to = $this->machine->getNextState($from, $action);
        $reason = $reason === null ? null : trim($reason);
        if (in_array($action, self::REASON_REQUIRED, true) && ($reason === null || $reason === '')) {
            throw ValidationException::withMessages(['reason' => [(string) trans('platform.tenants.reason_required')]]);
        }
        $changes = match ($to) {
            'cancelled' => ['cancelled_at' => now(), 'cancel_reason' => $reason],
            'expired' => ['expired_at' => now()],
            'active' => ['cancelled_at' => null, 'cancel_reason' => null, 'expired_at' => null],
            default => [],
        };

        DB::transaction(function () use ($sub, $from, $to, $action, $reason, $changes, $actor): void {
            DB::table('subscriptions')->where('id', $sub->id)->update(['status' => $to] + $changes);
            $tenant = $this->tenants->find((string) $sub->tenant_id);
            $tenantAction = SubscriptionStateMachine::tenantAction($action, (string) $tenant->status);
            if ($tenantAction !== null) {
                $this->tenants->transitionIfAllowed((string) $sub->tenant_id, $tenantAction, $reason, $actor);
            }
            if ($to === 'active') {
                $this->entitlements->sync((string) $sub->id, $actor);
            }
            $this->audit->record('subscription.'.$action, $actor, 'subscription', (string) $sub->id,
                ['status' => $from], ['status' => $to, 'reason' => $reason], (string) $sub->tenant_id);
            $this->changed((string) $sub->id);
        });

        return $this->show($id);
    }

    /**
     * Suma un complemento: su módulo queda activo al instante, sin reiniciar ni migrar (SAS-5).
     *
     * @return array<string, mixed>
     */
    public function addAddon(string $id, string $addonId, int $quantity, ?PlatformUser $actor): array
    {
        $sub = $this->find($id);
        if ($sub->status === 'cancelled') {
            throw new BusinessRuleViolatedException('subscription_cancelled', (string) trans('platform.subscriptions.cancelled'));
        }
        $addon = $this->addons->find($addonId);
        if ($addon->status !== 'active') {
            throw new BusinessRuleViolatedException('addon_not_active', (string) trans('platform.addons.not_active'));
        }
        if ($this->currentAddonRow((string) $sub->id, (string) $addon->id) !== null) {
            throw new BusinessRuleViolatedException('addon_already_added', (string) trans('platform.addons.already_added'));
        }
        $this->addons->assertCompatible($addon, $this->includedModules($sub));

        DB::transaction(function () use ($sub, $addon, $quantity, $actor): void {
            $rowId = (string) Str::uuid();
            DB::table('subscription_addons')->insert([
                'id' => $rowId, 'subscription_id' => $sub->id, 'addon_id' => $addon->id, 'quantity' => $quantity,
                'price' => $addon->price, 'currency' => $addon->currency, 'starts_on' => now()->toDateString(),
            ]);
            $changes = $this->entitlements->sync((string) $sub->id, $actor);
            $this->audit->record('subscription.addon_added', $actor, 'subscription', (string) $sub->id, null, [
                'addon' => (string) $addon->code, 'quantity' => $quantity, 'modules_activated' => $changes['activated'],
            ], (string) $sub->tenant_id);
            $this->changed((string) $sub->id);
        });

        return $this->show($id);
    }

    /**
     * Quita un complemento desde hoy: sus módulos pasan a solo lectura (salvo que el plan los incluya).
     *
     * @return array<string, mixed>
     */
    public function removeAddon(string $id, string $addonId, PlatformUser $actor): array
    {
        $sub = $this->find($id);
        $row = TenancyScope::isValidUuid($addonId) ? $this->currentAddonRow((string) $sub->id, $addonId) : null;
        if ($row === null) {
            throw new ModelNotFoundException;
        }

        DB::transaction(function () use ($sub, $row, $actor): void {
            DB::table('subscription_addons')->where('id', $row->id)->update(['ends_on' => now()->toDateString()]);
            $changes = $this->entitlements->sync((string) $sub->id, $actor);
            $code = DB::table('addons')->where('id', $row->addon_id)->value('code');
            $this->audit->record('subscription.addon_removed', $actor, 'subscription', (string) $sub->id, [
                'addon' => $code, 'quantity' => (int) $row->quantity,
            ], ['modules_retired' => $changes['retired'], 'modules_kept' => $changes['kept']], (string) $sub->tenant_id);
            $this->changed((string) $sub->id);
        });

        return $this->show($id);
    }

    /**
     * Historial de la suscripción: cada cambio con su autor (la consola o el sistema).
     *
     * @return list<array<string, mixed>>
     */
    public function history(string $id): array
    {
        $sub = $this->find($id);

        return array_values(DB::table('platform_audit_events AS e')->leftJoin('platform_users AS u', 'u.id', '=', 'e.platform_user_id')
            ->where('e.entity_type', 'subscription')->where('e.entity_id', $sub->id)
            ->orderByDesc('e.occurred_at')->orderByDesc('e.id')
            ->get(['e.id', 'e.action', 'e.before_data', 'e.after_data', 'e.occurred_at', 'u.full_name'])
            ->map(fn ($e): array => [
                'id' => (string) $e->id,
                'action' => (string) $e->action,
                'actor' => $e->full_name,
                'before' => $e->before_data === null ? null : json_decode((string) $e->before_data, true),
                'after' => $e->after_data === null ? null : json_decode((string) $e->after_data, true),
                'occurred_at' => Carbon::parse((string) $e->occurred_at)->toIso8601String(),
            ])->all());
    }

    /**
     * Tarea diaria (RN-SAS-04): la prueba vencida sin conversión deja a la organización en solo lectura
     * durante el periodo de gracia; al terminar la gracia, se suspende.
     *
     * @return array{expired: int, suspended: int}
     */
    public function runTrialLifecycle(): array
    {
        $today = now()->toDateString();
        $expired = 0;
        foreach (DB::table('subscriptions')->where('status', 'trialing')->where('trial_ends_on', '<', $today)->pluck('id') as $id) {
            $this->transition((string) $id, SubscriptionStateMachine::EXPIRE, null, null);
            $expired++;
        }
        // Organizaciones en prueba sin suscripción (alta sin plan): vencen igual.
        $orphans = DB::table('tenants AS t')->where('t.status', 'trial')->whereNotNull('t.trial_ends_at')->where('t.trial_ends_at', '<', now())
            ->whereNull('t.deleted_at')->whereNotExists(fn ($q) => $q->selectRaw('1')->from('subscriptions AS s')->whereColumn('s.tenant_id', 't.id'))
            ->pluck('t.id');
        foreach ($orphans as $tenantId) {
            $this->tenants->transitionIfAllowed((string) $tenantId, TenantStateMachine::EXPIRE_TRIAL, null, null);
            $expired++;
        }

        $limit = now()->subDays($this->settings->trialGraceDays());
        $reason = (string) trans('platform.subscriptions.grace_ended');
        $suspended = 0;
        foreach (DB::table('subscriptions')->where('status', 'expired')->where('expired_at', '<=', $limit)->pluck('id') as $id) {
            $this->transition((string) $id, SubscriptionStateMachine::SUSPEND, $reason, null);
            $suspended++;
        }
        $orphans = DB::table('tenants AS t')->where('t.status', 'grace_period')->whereNotNull('t.trial_ends_at')->where('t.trial_ends_at', '<=', $limit)
            ->whereNull('t.deleted_at')->whereNotExists(fn ($q) => $q->selectRaw('1')->from('subscriptions AS s')->whereColumn('s.tenant_id', 't.id'))
            ->pluck('t.id');
        foreach ($orphans as $tenantId) {
            $this->tenants->transitionIfAllowed((string) $tenantId, TenantStateMachine::SUSPEND, $reason, null);
            $suspended++;
        }

        return ['expired' => $expired, 'suspended' => $suspended];
    }

    public function find(string $id): \stdClass
    {
        $row = TenancyScope::isValidUuid($id) ? DB::table('subscriptions')->where('id', $id)->first() : null;
        if (! $row instanceof \stdClass) {
            throw new ModelNotFoundException;
        }

        return $row;
    }

    private function sellablePlan(string $planId): \stdClass
    {
        $plan = TenancyScope::isValidUuid($planId) ? DB::table('plans')->where('id', $planId)->whereNull('deleted_at')->first() : null;
        if (! $plan instanceof \stdClass) {
            throw ValidationException::withMessages(['plan_id' => [(string) trans('platform.subscriptions.unknown_plan')]]);
        }
        if ($plan->status !== 'active' || $plan->superseded_by_plan_id !== null) {
            throw new BusinessRuleViolatedException('plan_not_for_sale', (string) trans('platform.subscriptions.plan_not_for_sale'));
        }

        return $plan;
    }

    /**
     * Fecha de fin de la prueba: la pedida, o los días de prueba del plan. Solo una organización en prueba
     * puede empezar con una suscripción en prueba (ME-40 no vuelve de activa a prueba).
     *
     * @param  array<string, mixed>  $input
     */
    private function trialEnd(array $input, \stdClass $tenant, \stdClass $plan): ?string
    {
        $requested = $input['trial_ends_on'] ?? null;
        if ($tenant->status !== 'trial') {
            if ($requested !== null) {
                throw new BusinessRuleViolatedException('tenant_not_in_trial', (string) trans('platform.subscriptions.tenant_not_in_trial'));
            }

            return null;
        }
        if ($requested !== null) {
            return (string) $requested;
        }
        if ($tenant->trial_ends_at !== null) {
            return Carbon::parse((string) $tenant->trial_ends_at)->toDateString();
        }

        return $plan->trial_days === null ? null : now()->addDays((int) $plan->trial_days)->toDateString();
    }

    /**
     * Los complementos vigentes tienen que seguir cabiendo en el plan nuevo (sus dependencias).
     */
    private function assertAddonsFit(\stdClass $sub, string $planId): void
    {
        $planModules = DB::table('plan_modules')->where('plan_id', $planId)->pluck('module_code')->map(fn ($c): string => (string) $c)->all();
        $addonModules = DB::table('subscription_addons AS sa')->join('addon_modules AS am', 'am.addon_id', '=', 'sa.addon_id')
            ->where('sa.subscription_id', $sub->id)->where(fn ($q) => $q->whereNull('sa.ends_on')->orWhere('sa.ends_on', '>', now()->toDateString()))
            ->pluck('am.module_code')->map(fn ($c): string => (string) $c)->all();
        $this->plans->assertDependencies(array_values(array_unique([...$planModules, ...$addonModules])));
    }

    /**
     * @return list<string>
     */
    private function includedModules(\stdClass $sub): array
    {
        $plan = DB::table('plan_modules')->where('plan_id', $sub->plan_id)->pluck('module_code')->map(fn ($c): string => (string) $c)->all();
        $addons = DB::table('subscription_addons AS sa')->join('addon_modules AS am', 'am.addon_id', '=', 'sa.addon_id')
            ->where('sa.subscription_id', $sub->id)->where(fn ($q) => $q->whereNull('sa.ends_on')->orWhere('sa.ends_on', '>', now()->toDateString()))
            ->pluck('am.module_code')->map(fn ($c): string => (string) $c)->all();

        return array_values(array_unique([...$plan, ...$addons]));
    }

    private function currentAddonRow(string $subscriptionId, string $addonOrRowId): ?\stdClass
    {
        $row = DB::table('subscription_addons')->where('subscription_id', $subscriptionId)
            ->where(fn ($q) => $q->where('addon_id', $addonOrRowId)->orWhere('id', $addonOrRowId))
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>', now()->toDateString()))
            ->first();

        return $row instanceof \stdClass ? $row : null;
    }

    private function changed(string $id): void
    {
        $sub = $this->find($id);
        $plan = DB::table('plans')->where('id', $sub->plan_id)->first(['code', 'version']);
        TenancyScope::runAs((string) $sub->tenant_id, fn () => $this->outbox->record(DomainEventTypes::SUBSCRIPTION_CHANGED, 'subscription', (string) $sub->id, [
            'tenant_id' => (string) $sub->tenant_id, 'subscription_id' => (string) $sub->id,
            'plan_code' => (string) $plan?->code, 'plan_revision' => (int) $plan?->version, 'status' => (string) $sub->status,
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(string $id): array
    {
        $s = $this->find($id);
        $plan = DB::table('plans')->where('id', $s->plan_id)->first(['code', 'name', 'version']);

        return [
            'plan' => $plan === null ? null : ['code' => (string) $plan->code, 'name' => (string) $plan->name, 'revision' => (int) $plan->version],
            'status' => (string) $s->status, 'billing_cycle' => (string) $s->billing_cycle,
            'price' => $s->price === null ? null : ['amount' => (string) $s->price, 'currency' => (string) $s->currency],
            'trial_ends_on' => $s->trial_ends_on, 'ends_on' => $s->ends_on,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function present(\stdClass $s): array
    {
        $plan = DB::table('plans')->where('id', $s->plan_id)->first(['id', 'code', 'name', 'version', 'superseded_by_plan_id', 'status']);
        $tenant = DB::table('tenants')->where('id', $s->tenant_id)->first(['name', 'slug', 'status']);
        $today = now()->toDateString();
        $addons = DB::table('subscription_addons AS sa')->join('addons AS a', 'a.id', '=', 'sa.addon_id')
            ->where('sa.subscription_id', $s->id)->orderBy('sa.starts_on')->orderBy('a.name')
            ->get(['sa.id', 'sa.addon_id', 'a.code', 'a.name', 'a.kind', 'sa.quantity', 'sa.price', 'sa.currency', 'sa.starts_on', 'sa.ends_on']);

        return [
            'id' => (string) $s->id,
            'tenant_id' => (string) $s->tenant_id,
            'tenant' => $tenant === null ? null : ['name' => (string) $tenant->name, 'slug' => (string) $tenant->slug, 'status' => (string) $tenant->status],
            'plan_id' => (string) $s->plan_id,
            'plan' => $plan === null ? null : [
                'code' => (string) $plan->code, 'name' => (string) $plan->name, 'revision' => (int) $plan->version,
                'is_current' => $plan->superseded_by_plan_id === null && $plan->status === 'active',
            ],
            'status' => (string) $s->status,
            'billing_cycle' => (string) $s->billing_cycle,
            ...($s->price === null || $s->currency === null ? [] : ['price' => ['amount' => (string) $s->price, 'currency' => (string) $s->currency]]),
            'starts_on' => (string) $s->starts_on,
            'ends_on' => $s->ends_on,
            'trial_ends_on' => $s->trial_ends_on,
            'expired_at' => $s->expired_at === null ? null : Carbon::parse((string) $s->expired_at)->toIso8601String(),
            'cancelled_at' => $s->cancelled_at === null ? null : Carbon::parse((string) $s->cancelled_at)->toIso8601String(),
            'cancel_reason' => $s->cancel_reason,
            'addons' => array_values($addons->map(fn ($a): array => [
                'id' => (string) $a->id, 'addon_id' => (string) $a->addon_id, 'code' => (string) $a->code, 'name' => (string) $a->name,
                'kind' => (string) $a->kind, 'quantity' => (int) $a->quantity,
                ...($a->price === null || $a->currency === null ? [] : ['price' => ['amount' => (string) $a->price, 'currency' => (string) $a->currency]]),
                'starts_on' => (string) $a->starts_on, 'ends_on' => $a->ends_on,
                'current' => $a->ends_on === null || (string) $a->ends_on > $today,
            ])->all()),
            'allowed_actions' => $this->machine->allowedActions((string) $s->status),
            'version' => (int) $s->lock_version,
            'created_at' => Carbon::parse((string) $s->created_at)->toIso8601String(),
            'updated_at' => Carbon::parse((string) $s->updated_at)->toIso8601String(),
        ];
    }
}
