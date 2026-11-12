<?php

namespace App\Modules\Platform\Application\Features;

use App\Modules\Platform\Application\Console\ConsoleAudit;
use App\Modules\Platform\Domain\FeatureFlagStateMachine;
use App\Modules\Platform\Infrastructure\Models\PlatformUser;
use App\Shared\Exceptions\BusinessRuleViolatedException;
use App\Shared\Features\FeatureFlags;
use App\Shared\Tenancy\CrossTenantMaintenance;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * SAS-7 · Banderas de funcionalidad desde la consola: catálogo, reglas (plan, grupo beta, país, porcentaje)
 * y activaciones por organización. Cada cambio queda en la auditoría de la consola y sube la versión de la
 * evaluación: surte efecto en la siguiente petición, sin desplegar.
 */
final class FeatureFlagCatalog
{
    public const TARGETS = ['plan', 'beta_group', 'country', 'percentage'];

    public function __construct(
        private readonly ConsoleAudit $audit,
        private readonly FeatureFlags $flags,
        private readonly FeatureFlagStateMachine $machine,
        private readonly CrossTenantMaintenance $cross,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function list(?string $status, ?string $q): array
    {
        $rows = DB::table('feature_flags')
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->when($q !== null && $q !== '', fn ($query) => $query->where(fn ($w) => $w->where('code', 'ilike', '%'.$q.'%')->orWhere('name', 'ilike', '%'.$q.'%')))
            ->orderBy('code')->get();

        return array_values($rows->map(fn ($row): array => $this->present($row))->all());
    }

    /**
     * @return array<string, mixed>
     */
    public function show(string $id): array
    {
        return $this->present($this->find($id));
    }

    /**
     * @param  array{code: string, name: string, description?: string|null, default_enabled?: bool, is_beta?: bool, rules?: array<int, mixed>}  $input
     * @return array<string, mixed>
     */
    public function create(array $input, PlatformUser $actor): array
    {
        if (DB::table('feature_flags')->where('code', $input['code'])->exists()) {
            throw ValidationException::withMessages(['code' => [(string) trans('platform.flags.duplicate_code')]]);
        }
        $rules = $this->rules($input['rules'] ?? []);

        return DB::transaction(function () use ($input, $rules, $actor): array {
            $id = (string) DB::table('feature_flags')->insertGetId([
                'code' => $input['code'],
                'name' => $input['name'],
                'description' => $input['description'] ?? null,
                'default_enabled' => (bool) ($input['default_enabled'] ?? false),
                'is_beta' => (bool) ($input['is_beta'] ?? false),
                'status' => 'active',
            ]);
            $this->replaceRules($id, $rules);
            $after = $this->show($id);
            $this->audit->record('feature_flag.created', $actor, 'feature_flag', $id, null, $after);
            $this->flags->bumpVersion();

            return $after;
        });
    }

    /**
     * @param  array{name?: string, description?: string|null, default_enabled?: bool, is_beta?: bool, rules?: array<int, mixed>}  $input
     * @return array<string, mixed>
     */
    public function update(string $id, array $input, PlatformUser $actor): array
    {
        $row = $this->find($id);
        $rules = array_key_exists('rules', $input) ? $this->rules((array) $input['rules']) : null;

        return DB::transaction(function () use ($row, $input, $rules, $actor): array {
            $before = $this->present($row);
            $changes = array_intersect_key($input, array_flip(['name', 'description', 'default_enabled', 'is_beta']));
            if ($changes !== []) {
                DB::table('feature_flags')->where('id', $row->id)->update($changes + ['updated_at' => now()]);
            }
            if ($rules !== null) {
                $this->replaceRules((string) $row->id, $rules);
            }
            $after = $this->show((string) $row->id);
            $this->audit->record('feature_flag.updated', $actor, 'feature_flag', (string) $row->id, $before, $after);
            $this->flags->bumpVersion();

            return $after;
        });
    }

    /**
     * Archivar o restaurar (regla 3: el estado cambia solo por acción; 409 si no procede).
     *
     * @return array<string, mixed>
     */
    public function transition(string $id, string $action, PlatformUser $actor): array
    {
        $row = $this->find($id);
        $next = $this->machine->getNextState((string) $row->status, $action);

        return DB::transaction(function () use ($row, $next, $action, $actor): array {
            DB::table('feature_flags')->where('id', $row->id)->update(['status' => $next, 'updated_at' => now()]);
            $this->audit->record('feature_flag.'.$action.'d', $actor, 'feature_flag', (string) $row->id, ['status' => $row->status], ['status' => $next]);
            $this->flags->bumpVersion();

            return $this->show((string) $row->id);
        });
    }

    /**
     * Activaciones explícitas por organización (prevalecen sobre las reglas, RN-SAS-07).
     *
     * @return list<array<string, mixed>>
     */
    public function overrides(string $id): array
    {
        $flag = $this->find($id);
        $rows = $this->cross->readAcrossTenants(fn () => DB::table('tenant_feature_flags as o')->join('tenants as t', 't.id', '=', 'o.tenant_id')
            ->where('o.feature_flag_code', $flag->code)->orderBy('t.name')
            ->get(['o.tenant_id', 't.name as tenant_name', 't.slug', 't.country_code', 'o.enabled', 'o.reason', 'o.expires_at', 'o.updated_at']));

        return array_values($rows->map(fn ($r): array => [
            'tenant_id' => (string) $r->tenant_id,
            'tenant_name' => (string) $r->tenant_name,
            'slug' => (string) $r->slug,
            'country' => (string) $r->country_code,
            'enabled' => (bool) $r->enabled,
            'reason' => $r->reason,
            'expires_at' => $r->expires_at === null ? null : Carbon::parse((string) $r->expires_at)->toIso8601String(),
            'expired' => $r->expires_at !== null && Carbon::parse((string) $r->expires_at)->isPast(),
            'updated_at' => Carbon::parse((string) $r->updated_at)->toIso8601String(),
        ])->all());
    }

    /**
     * @param  array{tenant_id: string, enabled: bool, reason?: string|null, expires_at?: string|null}  $input
     * @return list<array<string, mixed>>
     */
    public function setOverride(string $id, array $input, PlatformUser $actor): array
    {
        $flag = $this->find($id);
        if ($flag->status !== 'active') {
            throw new BusinessRuleViolatedException('flag_archived', (string) trans('platform.flags.archived'));
        }
        $tenantId = $input['tenant_id'];
        if (! DB::table('tenants')->where('id', $tenantId)->whereNull('deleted_at')->exists()) {
            throw new ModelNotFoundException;
        }

        DB::transaction(function () use ($flag, $tenantId, $input, $actor): void {
            $before = TenancyScope::runAs($tenantId, function () use ($flag, $tenantId, $input, $actor): ?array {
                $existing = DB::table('tenant_feature_flags')->where('feature_flag_code', $flag->code)->first(['enabled', 'reason', 'expires_at']);
                DB::table('tenant_feature_flags')->upsert([[
                    'tenant_id' => $tenantId,
                    'feature_flag_code' => $flag->code,
                    'enabled' => (bool) $input['enabled'],
                    'reason' => $input['reason'] ?? null,
                    'expires_at' => $input['expires_at'] ?? null,
                    'set_by_platform_user_id' => $actor->id,
                    'updated_at' => now(),
                ]], ['tenant_id', 'feature_flag_code'], ['enabled', 'reason', 'expires_at', 'set_by_platform_user_id', 'updated_at']);

                return $existing === null ? null : (array) $existing;
            });
            $this->audit->record('feature_flag.tenant_set', $actor, 'feature_flag', (string) $flag->id, $before, [
                'enabled' => (bool) $input['enabled'], 'reason' => $input['reason'] ?? null, 'expires_at' => $input['expires_at'] ?? null,
            ], $tenantId);
        });
        $this->flags->bumpVersion();

        return $this->overrides($id);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function removeOverride(string $id, string $tenantId, PlatformUser $actor): array
    {
        $flag = $this->find($id);
        DB::transaction(function () use ($flag, $tenantId, $actor): void {
            $before = TenancyScope::runAs($tenantId, function () use ($flag): ?array {
                $existing = DB::table('tenant_feature_flags')->where('feature_flag_code', $flag->code)->first(['enabled', 'reason', 'expires_at']);
                if ($existing === null) {
                    return null;
                }
                DB::table('tenant_feature_flags')->where('feature_flag_code', $flag->code)->delete();

                return (array) $existing;
            });
            if ($before === null) {
                throw new ModelNotFoundException;
            }
            $this->audit->record('feature_flag.tenant_removed', $actor, 'feature_flag', (string) $flag->id, $before, null, $tenantId);
        });
        $this->flags->bumpVersion();

        return $this->overrides($id);
    }

    /**
     * Resultado de todas las banderas para una organización, con qué regla decidió (misma evaluación que la
     * API y la web de esa organización).
     *
     * @return list<array{code: string, name: string, enabled: bool, source: string}>
     */
    public function evaluateFor(string $tenantId): array
    {
        $names = DB::table('feature_flags')->pluck('name', 'code');

        return TenancyScope::runAs($tenantId, function () use ($names): array {
            $out = [];
            foreach (DB::select('SELECT code, enabled, source FROM current_feature_flags() ORDER BY code') as $row) {
                $out[] = ['code' => (string) $row->code, 'name' => (string) ($names[$row->code] ?? $row->code), 'enabled' => (bool) $row->enabled, 'source' => (string) $row->source];
            }

            return $out;
        });
    }

    /**
     * @param  array<int, mixed>  $rules
     * @return list<array{target_kind: string, target_value: string, enabled: bool}>
     */
    private function rules(array $rules): array
    {
        $out = [];
        foreach ($rules as $i => $rule) {
            $rule = (array) $rule;
            $kind = (string) ($rule['target_kind'] ?? '');
            $value = trim((string) ($rule['target_value'] ?? ''));
            $valid = match ($kind) {
                'plan' => DB::table('plans')->where('code', $value)->exists(),
                'country' => DB::table('countries')->where('code', $value)->exists(),
                'beta_group' => preg_match('/^[a-z0-9][a-z0-9_-]{0,39}$/', $value) === 1,
                'percentage' => preg_match('/^\d{1,3}$/', $value) === 1 && (int) $value >= 0 && (int) $value <= 100,
                default => false,
            };
            $key = $kind.':'.$value;
            if (! $valid || isset($out[$key])) {
                throw ValidationException::withMessages(["rules.$i" => [(string) trans('platform.flags.invalid_rule')]]);
            }
            $out[$key] = ['target_kind' => $kind, 'target_value' => $value, 'enabled' => (bool) ($rule['enabled'] ?? true)];
        }

        return array_values($out);
    }

    /**
     * @param  list<array{target_kind: string, target_value: string, enabled: bool}>  $rules
     */
    private function replaceRules(string $flagId, array $rules): void
    {
        DB::table('feature_flag_rules')->where('feature_flag_id', $flagId)->delete();
        foreach ($rules as $rule) {
            DB::table('feature_flag_rules')->insert($rule + ['feature_flag_id' => $flagId]);
        }
    }

    private function find(string $id): \stdClass
    {
        $row = preg_match('/^[0-9a-f-]{36}$/i', $id) === 1 ? DB::table('feature_flags')->where('id', $id)->first() : null;
        if ($row === null) {
            throw new ModelNotFoundException;
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(\stdClass $row): array
    {
        $rules = DB::table('feature_flag_rules')->where('feature_flag_id', $row->id)
            ->orderByRaw("array_position(ARRAY['plan','beta_group','country','percentage'], target_kind)")->orderBy('target_value')
            ->get(['target_kind', 'target_value', 'enabled'])
            ->map(fn ($r): array => ['target_kind' => (string) $r->target_kind, 'target_value' => (string) $r->target_value, 'enabled' => (bool) $r->enabled])
            ->values()->all();

        return [
            'id' => (string) $row->id,
            'code' => (string) $row->code,
            'name' => (string) $row->name,
            'description' => $row->description,
            'default_enabled' => (bool) $row->default_enabled,
            'is_beta' => (bool) $row->is_beta,
            'status' => (string) $row->status,
            'rules' => $rules,
            'overrides' => (int) $this->cross->readAcrossTenants(fn () => DB::table('tenant_feature_flags')->where('feature_flag_code', $row->code)->count()),
            'allowed_actions' => $this->machine->allowedActions((string) $row->status),
            'created_at' => Carbon::parse((string) $row->created_at)->toIso8601String(),
            'updated_at' => Carbon::parse((string) $row->updated_at)->toIso8601String(),
        ];
    }
}
