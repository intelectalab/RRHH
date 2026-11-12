<?php

namespace App\Modules\Platform\Application\Plans;

use App\Modules\Platform\Application\Console\ConsoleAudit;
use App\Modules\Platform\Domain\PlanStateMachine;
use App\Modules\Platform\Infrastructure\Models\PlatformUser;
use App\Shared\Exceptions\BusinessRuleViolatedException;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * SAS-3 · SAS-4 · Complementos (add-ons): módulo extra, ampliación de límite, funcionalidad o servicio.
 * Se cargan como datos (ADR 0013, nacen inactivos y sin precio); el operador fija el precio y los activa.
 * Lo que ya contrató una organización guarda su precio en subscription_addons: cambiar el catálogo no lo altera.
 */
final class AddonCatalog
{
    public function __construct(
        private readonly PlanStateMachine $machine,
        private readonly ConsoleAudit $audit,
        private readonly PlanCatalog $plans,
    ) {}

    /**
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function list(?string $status): array
    {
        $rows = DB::table('addons')->whereNull('deleted_at')
            ->when($status !== null && $status !== '', fn ($w) => $w->where('status', $status))
            ->orderBy('name')->get();

        return ['data' => array_values($rows->map(fn ($r): array => $this->present($r))->all()), 'meta' => ['total' => $rows->count()]];
    }

    /**
     * @return array<string, mixed>
     */
    public function show(string $id): array
    {
        return $this->present($this->find($id));
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function update(string $id, array $input, PlatformUser $actor): array
    {
        $addon = $this->find($id);
        $columns = [];
        foreach (['name', 'billing_cycle'] as $field) {
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
        if (array_key_exists('limit_increment', $input)) {
            if ($addon->kind !== 'limit') {
                throw ValidationException::withMessages(['limit_increment' => [(string) trans('platform.addons.not_a_limit')]]);
            }
            $columns['limit_increment'] = $input['limit_increment'];
        }
        $modules = null;
        if (array_key_exists('modules', $input)) {
            if ($addon->kind !== 'module') {
                throw ValidationException::withMessages(['modules' => [(string) trans('platform.addons.not_a_module')]]);
            }
            $modules = array_values(array_unique(array_map('strval', (array) $input['modules'])));
            $known = DB::table('modules')->whereIn('code', $modules)->count();
            if ($modules === [] || $known !== count($modules)) {
                throw ValidationException::withMessages(['modules' => [(string) trans('platform.plans.unknown_modules', ['modules' => implode(', ', $modules)])]]);
            }
        }

        $before = $this->present($addon);
        DB::transaction(function () use ($addon, $columns, $modules, $actor, $before): void {
            if ($columns !== []) {
                DB::table('addons')->where('id', $addon->id)->update($columns);
            }
            if ($modules !== null) {
                DB::table('addon_modules')->where('addon_id', $addon->id)->delete();
                DB::table('addon_modules')->insert(array_map(fn (string $m): array => ['addon_id' => $addon->id, 'module_code' => $m], $modules));
            }
            $this->audit->record('addon.updated', $actor, 'addon', (string) $addon->id, $before, $this->present($this->find((string) $addon->id)));
        });

        return $this->show($id);
    }

    /**
     * @return array<string, mixed>
     */
    public function transition(string $id, string $action, PlatformUser $actor): array
    {
        $addon = $this->find($id);
        $to = $this->machine->getNextState((string) $addon->status, $action);
        if ($to === 'active' && ($addon->price === null || $addon->currency === null)) {
            throw new BusinessRuleViolatedException('addon_price_required', (string) trans('platform.plans.price_required'));
        }
        DB::transaction(function () use ($addon, $to, $action, $actor): void {
            DB::table('addons')->where('id', $addon->id)->update(['status' => $to]);
            $this->audit->record('addon.'.$action, $actor, 'addon', (string) $addon->id, ['status' => $addon->status], ['status' => $to]);
        });

        return $this->show($id);
    }

    public function find(string $id): \stdClass
    {
        $row = TenancyScope::isValidUuid($id) ? DB::table('addons')->where('id', $id)->whereNull('deleted_at')->first() : null;
        if (! $row instanceof \stdClass) {
            throw new ModelNotFoundException;
        }

        return $row;
    }

    /**
     * Módulos que enciende un complemento, con sus dependencias ya contratadas o incluidas (ADR 0008).
     *
     * @param  list<string>  $alreadyIncluded
     */
    public function assertCompatible(\stdClass $addon, array $alreadyIncluded): void
    {
        if ($addon->kind !== 'module') {
            return;
        }
        $modules = DB::table('addon_modules')->where('addon_id', $addon->id)->pluck('module_code')->map(fn ($c): string => (string) $c)->all();
        $this->plans->assertDependencies(array_values(array_unique([...$alreadyIncluded, ...$modules])));
    }

    /**
     * @return array<string, mixed>
     */
    private function present(\stdClass $a): array
    {
        return [
            'id' => (string) $a->id,
            'code' => (string) $a->code,
            'name' => (string) $a->name,
            'kind' => (string) $a->kind,
            'web_product_code' => $a->web_product_code,
            'modules' => DB::table('addon_modules')->where('addon_id', $a->id)->orderBy('module_code')->pluck('module_code')->map(fn ($c): string => (string) $c)->all(),
            'limit_code' => $a->limit_code,
            'limit_increment' => $a->limit_increment === null ? null : (string) $a->limit_increment,
            ...($a->price === null || $a->currency === null ? [] : ['price' => ['amount' => (string) $a->price, 'currency' => (string) $a->currency]]),
            'billing_cycle' => (string) $a->billing_cycle,
            'status' => (string) $a->status,
            'allowed_actions' => $this->machine->allowedActions((string) $a->status),
        ];
    }
}
