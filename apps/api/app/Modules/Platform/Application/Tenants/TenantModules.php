<?php

namespace App\Modules\Platform\Application\Tenants;

use App\Modules\Platform\Application\Console\ConsoleAudit;
use App\Modules\Platform\Application\PlatformSettings;
use App\Modules\Platform\Infrastructure\Models\PlatformUser;
use App\Shared\Exceptions\BusinessRuleViolatedException;
use App\Shared\Exceptions\InvalidTransitionException;
use App\Shared\Outbox\Contracts\OutboxWriter;
use App\Shared\Outbox\DomainEventTypes;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * SAS-5 · Módulos comerciales por organización. Activar un módulo es una fila en tenant_modules: no
 * migra datos ni despliega nada, y los datos de un módulo retirado se conservan (RN-SAS-06): queda en
 * solo lectura hasta read_only_until y luego se oculta, hasta que se vuelva a activar.
 *
 * La API (RequirePermission + ModuleGate), el menú (/me → modules) y los permisos leen esta tabla.
 */
final class TenantModules
{
    public function __construct(
        private readonly ConsoleAudit $audit,
        private readonly OutboxWriter $outbox,
        private readonly PlatformSettings $settings,
    ) {}

    /**
     * Catálogo de módulos con su situación en la organización.
     *
     * @return list<array<string, mixed>>
     */
    public function summary(string $tenantId): array
    {
        $catalog = DB::table('modules')->orderBy('phase')->orderBy('code')->get(['code', 'name', 'phase', 'is_core']);
        $deps = DB::table('module_dependencies')->where('is_required', true)->get(['module_code', 'depends_on_code'])
            ->groupBy('module_code')->map(fn ($g) => $g->pluck('depends_on_code')->map(fn ($c): string => (string) $c)->values()->all());
        $rows = TenancyScope::runAs($tenantId, fn () => DB::table('tenant_modules')->get(['module_code', 'status', 'source', 'activated_at', 'deactivated_at', 'read_only_until'])->keyBy('module_code'));

        $out = [];
        foreach ($catalog as $m) {
            $row = $rows[$m->code] ?? null;
            $out[] = [
                'code' => (string) $m->code,
                'name' => (string) $m->name,
                'phase' => (int) $m->phase,
                'is_core' => (bool) $m->is_core,
                'depends_on' => $deps[$m->code] ?? [],
                'state' => $this->state($row),
                'source' => $row?->source,
                'activated_at' => $row?->activated_at === null ? null : Carbon::parse((string) $row->activated_at)->toIso8601String(),
                'deactivated_at' => $row?->deactivated_at === null ? null : Carbon::parse((string) $row->deactivated_at)->toIso8601String(),
                'read_only_until' => $row?->read_only_until === null ? null : Carbon::parse((string) $row->read_only_until)->toIso8601String(),
            ];
        }

        return $out;
    }

    /**
     * Activa un módulo y, antes, los módulos de los que depende. Idempotente: activar uno activo no
     * cambia nada.
     *
     * @return list<string> módulos que se activaron en esta llamada
     */
    public function activate(string $tenantId, string $code, PlatformUser $actor, string $source = 'manual'): array
    {
        $this->module($code);
        $chain = $this->withDependencies($code);

        return DB::transaction(function () use ($tenantId, $chain, $actor, $source): array {
            /** @var list<array{0: string, 1: string|null}> $changes */
            $changes = TenancyScope::runAs($tenantId, function () use ($tenantId, $chain, $source): array {
                $changes = [];
                foreach ($chain as $module) {
                    $row = DB::table('tenant_modules')->where('module_code', $module)->first(['status']);
                    if ($row !== null && in_array($row->status, ['active', 'trial'], true)) {
                        continue;
                    }
                    DB::table('tenant_modules')->upsert([[
                        'tenant_id' => $tenantId, 'module_code' => $module, 'status' => 'active', 'source' => $source,
                        'activated_at' => now(), 'deactivated_at' => null, 'read_only_until' => null,
                    ]], ['tenant_id', 'module_code'], ['status', 'source', 'activated_at', 'deactivated_at', 'read_only_until', 'updated_at']);
                    $this->outbox->record(DomainEventTypes::TENANT_MODULE_CHANGED, 'tenant', $tenantId, [
                        'tenant_id' => $tenantId, 'module_code' => $module, 'status' => 'active',
                    ]);
                    $changes[] = [$module, $row === null ? null : (string) $row->status];
                }

                return $changes;
            });
            // La auditoría de la consola se escribe fuera del contexto de la organización (tabla de plataforma).
            foreach ($changes as [$module, $before]) {
                $this->audited($tenantId, $module, 'active', $before, $actor);
            }

            return array_map(fn (array $c): string => $c[0], $changes);
        });
    }

    /**
     * Retira un módulo: sus datos quedan en solo lectura durante la transición y luego se ocultan.
     */
    public function deactivate(string $tenantId, string $code, PlatformUser $actor): void
    {
        $module = $this->module($code);
        if ((bool) $module->is_core) {
            throw new BusinessRuleViolatedException('module_core', (string) trans('platform.modules.core'));
        }

        // El parámetro de plataforma se lee antes de entrar al contexto de la organización.
        $readOnlyUntil = now()->addDays($this->settings->moduleReadOnlyDays());
        DB::transaction(function () use ($tenantId, $code, $actor, $readOnlyUntil): void {
            $before = TenancyScope::runAs($tenantId, function () use ($tenantId, $code, $readOnlyUntil): string {
                $row = DB::table('tenant_modules')->where('module_code', $code)->first(['status']);
                if ($row === null || ! in_array($row->status, ['active', 'trial'], true)) {
                    throw new InvalidTransitionException($row === null ? 'not_contracted' : (string) $row->status, ['activate']);
                }
                $active = DB::table('tenant_modules')->whereIn('status', ['active', 'trial'])->pluck('module_code')->map(fn ($c): string => (string) $c)->all();
                $dependents = DB::table('module_dependencies')->where('depends_on_code', $code)->where('is_required', true)
                    ->whereIn('module_code', $active)->orderBy('module_code')->pluck('module_code')->map(fn ($c): string => (string) $c)->all();
                if ($dependents !== []) {
                    throw new BusinessRuleViolatedException('module_required_by', (string) trans('platform.modules.required_by', ['modules' => implode(', ', $dependents)]));
                }

                DB::table('tenant_modules')->where('module_code', $code)->update([
                    'status' => 'inactive', 'deactivated_at' => now(),
                    'read_only_until' => $readOnlyUntil,
                ]);
                $this->outbox->record(DomainEventTypes::TENANT_MODULE_CHANGED, 'tenant', $tenantId, [
                    'tenant_id' => $tenantId, 'module_code' => $code, 'status' => 'inactive',
                ]);

                return (string) $row->status;
            });
            $this->audited($tenantId, $code, 'inactive', $before, $actor);
        });
    }

    private function state(?\stdClass $row): string
    {
        if ($row === null) {
            return 'not_contracted';
        }
        if (in_array($row->status, ['active', 'trial'], true)) {
            return (string) $row->status;
        }

        return $row->read_only_until !== null && Carbon::parse((string) $row->read_only_until)->isFuture() ? 'read_only' : 'hidden';
    }

    private function module(string $code): \stdClass
    {
        $module = DB::table('modules')->where('code', $code)->first(['code', 'is_core']);
        if (! $module instanceof \stdClass) {
            throw new ModelNotFoundException;
        }

        return $module;
    }

    /**
     * El módulo y sus dependencias obligatorias, de la más básica a la pedida.
     *
     * @return list<string>
     */
    private function withDependencies(string $code): array
    {
        $order = [];
        $visit = function (string $module, array $path) use (&$visit, &$order): void {
            if (in_array($module, $order, true) || in_array($module, $path, true)) {
                return;
            }
            $deps = DB::table('module_dependencies')->where('module_code', $module)->where('is_required', true)->orderBy('depends_on_code')->pluck('depends_on_code');
            foreach ($deps as $dep) {
                $visit((string) $dep, [...$path, $module]);
            }
            $order[] = $module;
        };
        $visit($code, []);

        return $order;
    }

    private function audited(string $tenantId, string $module, string $status, ?string $before, PlatformUser $actor): void
    {
        $this->audit->record($status === 'active' ? 'tenant.module_activated' : 'tenant.module_deactivated', $actor, 'tenant_module', null,
            ['module' => $module, 'status' => $before], ['module' => $module, 'status' => $status], $tenantId);
    }
}
