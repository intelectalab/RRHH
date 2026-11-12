<?php

namespace App\Modules\Platform\Application\Plans;

use App\Modules\Platform\Application\Console\ConsoleAudit;
use App\Modules\Platform\Domain\PlanStateMachine;
use App\Modules\Platform\Infrastructure\Models\PlatformUser;
use App\Shared\Exceptions\BusinessRuleViolatedException;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * SAS-3 · Planes comerciales: nombres libres (nada fijo en el código), precio y moneda que define el
 * operador, ciclo, límites, módulos y funcionalidades.
 *
 *  - Duplicar copia módulos, límites y funcionalidades a un borrador nuevo.
 *  - Editar un plan sin suscriptores lo cambia en el lugar; con suscriptores crea la versión siguiente
 *    (mismo código) y la anterior queda inactiva, apuntando a la nueva, para quienes ya la contrataron.
 *  - Un plan no se elimina: se retira de la venta y sus suscriptores conservan sus condiciones (RN-SAS-11).
 *  - Los módulos respetan sus dependencias (ADR 0008: Planilla exige Vacaciones) y siempre incluyen
 *    Plataforma base y Core HR.
 */
final class PlanCatalog
{
    public function __construct(
        private readonly PlanStateMachine $machine,
        private readonly ConsoleAudit $audit,
    ) {}

    /**
     * Versión vigente de cada plan (las anteriores se ven en su historial).
     *
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function list(?string $status, ?string $q): array
    {
        $rows = DB::table('plans')->whereNull('deleted_at')->whereNull('superseded_by_plan_id')
            ->when($status !== null && $status !== '', fn ($w) => $w->where('status', $status))
            ->when($q !== null && $q !== '', fn ($w) => $w->where(fn ($x) => $x->where('name', 'ilike', '%'.$q.'%')->orWhere('code', 'ilike', '%'.$q.'%')))
            ->orderBy('name')->get();

        return ['data' => array_values($rows->map(fn ($r): array => $this->present($r))->all()), 'meta' => ['total' => $rows->count(), 'modules' => $this->moduleCatalog()]];
    }

    /**
     * Catálogo de módulos comerciales con sus dependencias, para armar un plan desde la consola.
     *
     * @return list<array{code: string, name: string, is_core: bool, depends_on: list<string>}>
     */
    public function moduleCatalog(): array
    {
        $deps = DB::table('module_dependencies')->where('is_required', true)->get(['module_code', 'depends_on_code'])->groupBy('module_code');

        return array_values(DB::table('modules')->orderBy('phase')->orderBy('code')->get(['code', 'name', 'is_core'])
            ->map(fn ($m): array => [
                'code' => (string) $m->code, 'name' => (string) $m->name, 'is_core' => (bool) $m->is_core,
                'depends_on' => array_values(($deps[$m->code] ?? collect())->pluck('depends_on_code')->map(fn ($c): string => (string) $c)->all()),
            ])->all());
    }

    /**
     * @return array<string, mixed>
     */
    public function show(string $id): array
    {
        return $this->present($this->find($id));
    }

    /**
     * Versiones del mismo plan, de la más reciente a la primera, con cuántos suscriptores tiene cada una.
     *
     * @return list<array<string, mixed>>
     */
    public function versions(string $id): array
    {
        $plan = $this->find($id);

        return array_values(DB::table('plans')->where('code', $plan->code)->whereNull('deleted_at')->orderByDesc('version')->get()
            ->map(fn ($r): array => $this->present($r))->all());
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function create(array $input, PlatformUser $actor): array
    {
        $code = (string) $input['code'];
        if (DB::table('plans')->where('code', $code)->whereNull('deleted_at')->exists()) {
            throw ValidationException::withMessages(['code' => [(string) trans('platform.plans.code_taken')]]);
        }
        $modules = $this->modules((array) ($input['modules'] ?? []));
        $limits = $this->limits((array) ($input['limits'] ?? []));
        $features = $this->features((array) ($input['features'] ?? []));
        $price = $this->price($input['price'] ?? null);

        $id = (string) Str::uuid();
        DB::transaction(function () use ($id, $code, $input, $modules, $limits, $features, $price, $actor): void {
            DB::table('plans')->insert([
                'id' => $id, 'code' => $code, 'name' => $input['name'], 'description' => $input['description'] ?? null,
                'status' => 'draft', 'billing_cycle' => $input['billing_cycle'] ?? 'monthly',
                'price' => $price['amount'], 'currency' => $price['currency'],
                'trial_days' => $input['trial_days'] ?? null, 'is_public' => (bool) ($input['is_public'] ?? true), 'version' => 1,
            ]);
            $this->writeComposition($id, $modules, $limits, $features);
            $this->audit->record('plan.created', $actor, 'plan', $id, null, $this->snapshot($id));
        });

        return $this->show($id);
    }

    /**
     * Cambia el plan. Si ya tiene suscriptores, crea la versión siguiente y devuelve esa.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function update(string $id, array $input, PlatformUser $actor): array
    {
        $plan = $this->find($id);
        if ($plan->superseded_by_plan_id !== null) {
            throw new BusinessRuleViolatedException('plan_superseded', (string) trans('platform.plans.superseded'));
        }
        $modules = array_key_exists('modules', $input) ? $this->modules((array) $input['modules']) : null;
        $limits = array_key_exists('limits', $input) ? $this->limits((array) $input['limits']) : null;
        $features = array_key_exists('features', $input) ? $this->features((array) $input['features']) : null;
        $columns = [];
        foreach (['name', 'description', 'billing_cycle', 'trial_days', 'is_public'] as $field) {
            if (array_key_exists($field, $input)) {
                $columns[$field] = $input[$field];
            }
        }
        if (array_key_exists('price', $input)) {
            $price = $this->price($input['price']);
            $columns['price'] = $price['amount'];
            $columns['currency'] = $price['currency'];
        }
        if ($columns === [] && $modules === null && $limits === null && $features === null) {
            return $this->show($id);
        }

        $before = $this->snapshot((string) $plan->id);
        $targetId = DB::transaction(function () use ($plan, $columns, $modules, $limits, $features, $actor, $before): string {
            // Bloqueo del plan: dos ediciones simultáneas no crean dos versiones con el mismo número.
            DB::table('plans')->where('id', $plan->id)->lockForUpdate()->first(['id']);
            if (! $this->hasSubscribers((string) $plan->id)) {
                if ($columns !== []) {
                    DB::table('plans')->where('id', $plan->id)->update($columns);
                }
                $this->writeComposition((string) $plan->id, $modules, $limits, $features, false);
                $this->audit->record('plan.updated', $actor, 'plan', (string) $plan->id, $before, $this->snapshot((string) $plan->id));

                return (string) $plan->id;
            }

            $newId = $this->copy($plan, [
                'code' => (string) $plan->code,
                'version' => ((int) DB::table('plans')->where('code', $plan->code)->max('version')) + 1,
                'status' => (string) $plan->status,
            ] + $columns, $modules, $limits, $features);
            DB::table('plans')->where('id', $plan->id)->update(['status' => 'inactive', 'superseded_by_plan_id' => $newId]);
            $this->audit->record('plan.versioned', $actor, 'plan', $newId, $before, $this->snapshot($newId));

            return $newId;
        });

        return $this->show($targetId);
    }

    /**
     * Copia el plan (módulos, límites y funcionalidades) a un borrador nuevo con otro código.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function duplicate(string $id, array $input, PlatformUser $actor): array
    {
        $plan = $this->find($id);
        $code = isset($input['code']) ? (string) $input['code'] : $this->freeCode((string) $plan->code);
        if (DB::table('plans')->where('code', $code)->whereNull('deleted_at')->exists()) {
            throw ValidationException::withMessages(['code' => [(string) trans('platform.plans.code_taken')]]);
        }
        $name = isset($input['name']) ? (string) $input['name'] : (string) trans('platform.plans.copy_of', ['name' => $plan->name]);

        $newId = DB::transaction(function () use ($plan, $code, $name, $actor): string {
            $newId = $this->copy($plan, [
                'code' => $code, 'name' => $name, 'version' => 1, 'status' => 'draft', 'duplicated_from_plan_id' => $plan->id,
            ], null, null, null);
            $this->audit->record('plan.duplicated', $actor, 'plan', $newId, ['plan_id' => (string) $plan->id], $this->snapshot($newId));

            return $newId;
        });

        return $this->show($newId);
    }

    /**
     * @return array<string, mixed>
     */
    public function transition(string $id, string $action, PlatformUser $actor): array
    {
        $plan = $this->find($id);
        if ($plan->superseded_by_plan_id !== null) {
            throw new BusinessRuleViolatedException('plan_superseded', (string) trans('platform.plans.superseded'));
        }
        $to = $this->machine->getNextState((string) $plan->status, $action);
        if ($to === 'active' && ($plan->price === null || $plan->currency === null)) {
            // El monto lo define el operador en la consola: sin precio el plan no se vende.
            throw new BusinessRuleViolatedException('plan_price_required', (string) trans('platform.plans.price_required'));
        }
        DB::transaction(function () use ($plan, $to, $action, $actor): void {
            DB::table('plans')->where('id', $plan->id)->update(['status' => $to]);
            $this->audit->record('plan.'.$action, $actor, 'plan', (string) $plan->id, ['status' => $plan->status], ['status' => $to]);
        });

        return $this->show($id);
    }

    public function find(string $id): \stdClass
    {
        $row = TenancyScope::isValidUuid($id) ? DB::table('plans')->where('id', $id)->whereNull('deleted_at')->first() : null;
        if (! $row instanceof \stdClass) {
            throw new ModelNotFoundException;
        }

        return $row;
    }

    public function hasSubscribers(string $planId): bool
    {
        return DB::table('subscriptions')->where('plan_id', $planId)->exists();
    }

    /**
     * Módulos pedidos más Plataforma base y Core HR, validados contra el catálogo y sus dependencias.
     *
     * @param  array<int, mixed>  $codes
     * @return list<string>
     */
    public function modules(array $codes): array
    {
        $core = DB::table('modules')->where('is_core', true)->pluck('code')->map(fn ($c): string => (string) $c)->all();
        $codes = array_values(array_unique([...$core, ...array_map('strval', $codes)]));
        $known = DB::table('modules')->whereIn('code', $codes)->pluck('code')->map(fn ($c): string => (string) $c)->all();
        $unknown = array_values(array_diff($codes, $known));
        if ($unknown !== []) {
            throw ValidationException::withMessages(['modules' => [(string) trans('platform.plans.unknown_modules', ['modules' => implode(', ', $unknown)])]]);
        }
        $this->assertDependencies($codes);
        sort($codes);

        return $codes;
    }

    /**
     * @param  list<string>  $codes
     */
    public function assertDependencies(array $codes): void
    {
        $missing = DB::table('module_dependencies')->where('is_required', true)->whereIn('module_code', $codes)
            ->whereNotIn('depends_on_code', $codes)->orderBy('module_code')->get(['module_code', 'depends_on_code'])
            ->map(fn ($d): string => $d->module_code.' → '.$d->depends_on_code)->all();
        if ($missing !== []) {
            throw new BusinessRuleViolatedException('plan_module_dependencies', (string) trans('platform.plans.missing_dependencies', ['pairs' => implode(', ', $missing)]));
        }
    }

    /**
     * @param  array<int, mixed>  $limits
     * @return list<array{limit_code: string, limit_value: string|null, is_unlimited: bool}>
     */
    private function limits(array $limits): array
    {
        $out = [];
        foreach ($limits as $i => $limit) {
            $limit = (array) $limit;
            $code = (string) ($limit['code'] ?? '');
            $unlimited = (bool) ($limit['unlimited'] ?? false);
            $value = $limit['value'] ?? null;
            if ($code === '' || isset($out[$code]) || (! $unlimited && ($value === null || preg_match('/^\d{1,12}(\.\d{1,2})?$/', (string) $value) !== 1))) {
                throw ValidationException::withMessages(["limits.$i" => [(string) trans('platform.plans.invalid_limit')]]);
            }
            $out[$code] = ['limit_code' => $code, 'limit_value' => $unlimited ? null : (string) $value, 'is_unlimited' => $unlimited];
        }

        return array_values($out);
    }

    /**
     * @param  array<int, mixed>  $features
     * @return list<string>
     */
    private function features(array $features): array
    {
        $features = array_values(array_unique(array_map('strval', $features)));
        sort($features);

        return $features;
    }

    /**
     * @return array{amount: string|null, currency: string|null}
     */
    private function price(mixed $price): array
    {
        if ($price === null) {
            return ['amount' => null, 'currency' => null];
        }
        $price = (array) $price;
        $amount = (string) ($price['amount'] ?? '');
        $currency = (string) ($price['currency'] ?? '');
        $errors = [];
        if (preg_match('/^\d{1,12}(\.\d{1,2})?$/', $amount) !== 1) {
            $errors['price.amount'][] = (string) trans('platform.plans.invalid_price');
        }
        if (! DB::table('currencies')->where('code', $currency)->exists()) {
            $errors['price.currency'][] = (string) trans('platform.tenants.unknown_currency');
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return ['amount' => $amount, 'currency' => $currency];
    }

    /**
     * @param  list<string>|null  $modules
     * @param  list<array{limit_code: string, limit_value: string|null, is_unlimited: bool}>|null  $limits
     * @param  list<string>|null  $features
     */
    private function writeComposition(string $planId, ?array $modules, ?array $limits, ?array $features, bool $fresh = true): void
    {
        if ($modules !== null) {
            if (! $fresh) {
                DB::table('plan_modules')->where('plan_id', $planId)->delete();
            }
            DB::table('plan_modules')->insert(array_map(fn (string $m): array => ['plan_id' => $planId, 'module_code' => $m], $modules));
        }
        if ($limits !== null) {
            if (! $fresh) {
                DB::table('plan_limits')->where('plan_id', $planId)->delete();
            }
            if ($limits !== []) {
                DB::table('plan_limits')->insert(array_map(fn (array $l): array => ['plan_id' => $planId] + $l, $limits));
            }
        }
        if ($features !== null) {
            if (! $fresh) {
                DB::table('plan_features')->where('plan_id', $planId)->delete();
            }
            if ($features !== []) {
                DB::table('plan_features')->insert(array_map(fn (string $f): array => ['plan_id' => $planId, 'feature_code' => $f, 'enabled' => true], $features));
            }
        }
    }

    /**
     * Fila nueva con los datos del plan de origen (más los cambios) y su composición.
     *
     * @param  array<string, mixed>  $overrides
     * @param  list<string>|null  $modules
     * @param  list<array{limit_code: string, limit_value: string|null, is_unlimited: bool}>|null  $limits
     * @param  list<string>|null  $features
     */
    private function copy(\stdClass $plan, array $overrides, ?array $modules, ?array $limits, ?array $features): string
    {
        $id = (string) Str::uuid();
        DB::table('plans')->insert(array_merge([
            'id' => $id, 'name' => $plan->name, 'description' => $plan->description, 'billing_cycle' => $plan->billing_cycle,
            'price' => $plan->price, 'currency' => $plan->currency, 'trial_days' => $plan->trial_days, 'is_public' => $plan->is_public,
        ], $overrides));
        $this->writeComposition(
            $id,
            $modules ?? array_values(DB::table('plan_modules')->where('plan_id', $plan->id)->orderBy('module_code')->pluck('module_code')->map(fn ($c): string => (string) $c)->all()),
            $limits ?? array_values(DB::table('plan_limits')->where('plan_id', $plan->id)->orderBy('limit_code')->get(['limit_code', 'limit_value', 'is_unlimited'])
                ->map(fn ($l): array => ['limit_code' => (string) $l->limit_code, 'limit_value' => $l->limit_value === null ? null : (string) $l->limit_value, 'is_unlimited' => (bool) $l->is_unlimited])->all()),
            $features ?? array_values(DB::table('plan_features')->where('plan_id', $plan->id)->where('enabled', true)->orderBy('feature_code')->pluck('feature_code')->map(fn ($c): string => (string) $c)->all()),
        );

        return $id;
    }

    private function freeCode(string $code): string
    {
        $base = mb_substr($code, 0, 40).'-copia';
        $candidate = $base;
        for ($n = 2; DB::table('plans')->where('code', $candidate)->whereNull('deleted_at')->exists(); $n++) {
            $candidate = $base.'-'.$n;
        }

        return $candidate;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(string $id): array
    {
        $p = DB::table('plans')->where('id', $id)->first();

        return [
            'code' => $p?->code, 'name' => $p?->name, 'version' => $p?->version, 'status' => $p?->status,
            'billing_cycle' => $p?->billing_cycle, 'price' => $p?->price === null ? null : (string) $p->price, 'currency' => $p?->currency,
            'modules' => DB::table('plan_modules')->where('plan_id', $id)->orderBy('module_code')->pluck('module_code')->all(),
            'limits' => DB::table('plan_limits')->where('plan_id', $id)->orderBy('limit_code')->get(['limit_code', 'limit_value', 'is_unlimited'])->all(),
            'features' => DB::table('plan_features')->where('plan_id', $id)->orderBy('feature_code')->pluck('feature_code')->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function present(\stdClass $p): array
    {
        $id = (string) $p->id;

        return [
            'id' => $id,
            'code' => (string) $p->code,
            'name' => (string) $p->name,
            'description' => $p->description,
            'billing_cycle' => (string) $p->billing_cycle,
            ...($p->price === null || $p->currency === null ? [] : ['price' => ['amount' => (string) $p->price, 'currency' => (string) $p->currency]]),
            'trial_days' => $p->trial_days === null ? null : (int) $p->trial_days,
            'is_public' => (bool) $p->is_public,
            'status' => (string) $p->status,
            'revision' => (int) $p->version,
            'superseded_by_plan_id' => $p->superseded_by_plan_id,
            'duplicated_from_plan_id' => $p->duplicated_from_plan_id,
            'modules' => DB::table('plan_modules')->where('plan_id', $id)->orderBy('module_code')->pluck('module_code')->map(fn ($c): string => (string) $c)->all(),
            'limits' => DB::table('plan_limits')->where('plan_id', $id)->orderBy('limit_code')->get(['limit_code', 'limit_value', 'is_unlimited'])
                ->map(fn ($l): array => ['code' => (string) $l->limit_code, 'value' => $l->limit_value === null ? null : (string) $l->limit_value, 'unlimited' => (bool) $l->is_unlimited])->all(),
            'features' => DB::table('plan_features')->where('plan_id', $id)->where('enabled', true)->orderBy('feature_code')->pluck('feature_code')->map(fn ($c): string => (string) $c)->all(),
            'subscribers' => DB::table('subscriptions')->where('plan_id', $id)->whereNotIn('status', ['cancelled'])->count(),
            'allowed_actions' => $p->superseded_by_plan_id === null ? $this->machine->allowedActions((string) $p->status) : [],
            'created_at' => Carbon::parse((string) $p->created_at)->toIso8601String(),
            'updated_at' => Carbon::parse((string) $p->updated_at)->toIso8601String(),
        ];
    }
}
