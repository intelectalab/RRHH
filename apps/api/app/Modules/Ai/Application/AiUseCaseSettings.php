<?php

namespace App\Modules\Ai\Application;

use App\Shared\Audit\AuditLogger;
use App\Shared\Exceptions\AyniException;
use App\Shared\Features\FeatureFlags;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Support\Facades\DB;

/**
 * IAR-10 · Activación de los casos de uso de IA desde la administración de la organización.
 *  - El plan lo habilita la consola con la bandera de funcionalidad del caso de uso (ai_help_assistant). Si el
 *    plan no lo incluye, no se puede encender (403 feature_not_enabled) y la pantalla lo dice.
 *  - Sin fila, el caso de uso está apagado y sin roles (RN-IAR-05). Cada cambio queda auditado (antes, después,
 *    autor).
 */
final class AiUseCaseSettings
{
    public const HELP_ASSISTANT = 'help_assistant';

    /** Caso de uso → bandera de funcionalidad que lo habilita en el plan. */
    public const USE_CASES = [self::HELP_ASSISTANT => 'ai_help_assistant'];

    public function __construct(
        private readonly FeatureFlags $flags,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function list(): array
    {
        return array_map(fn (string $useCase): array => $this->get($useCase), array_keys(self::USE_CASES));
    }

    /**
     * @return array{id: string, use_case: string, enabled: bool, role_ids: list<string>, question_limit: int|null, limit_period: string, notice: string|null, plan_allows: bool}
     */
    public function get(string $useCase): array
    {
        if (! isset(self::USE_CASES[$useCase])) {
            throw new AyniException('not_found', 404, (string) trans('errors.not_found'));
        }
        $row = DB::table('ai_use_case_settings')->where('use_case', $useCase)->first();
        $roles = $row === null ? [] : array_values(DB::table('ai_use_case_roles')->where('ai_use_case_setting_id', $row->id)->orderBy('role_id')->pluck('role_id')
            ->map(fn (mixed $id): string => (string) $id)->all());
        $planAllows = $this->planAllows($useCase);

        return [
            'id' => $useCase,
            'use_case' => $useCase,
            'enabled' => $row !== null && (bool) $row->is_enabled && $planAllows,
            'role_ids' => $roles,
            'question_limit' => $row?->question_limit === null ? null : (int) $row->question_limit,
            'limit_period' => $row === null ? 'day' : (string) $row->limit_period,
            'notice' => is_string($row?->notice) ? $row->notice : null,
            'plan_allows' => $planAllows,
        ];
    }

    /**
     * @param  array{enabled?: bool, role_ids?: list<string>, question_limit?: int|null, limit_period?: string, notice?: string|null}  $input
     * @return array<string, mixed>
     */
    public function update(string $useCase, array $input, string $actorId): array
    {
        $before = $this->get($useCase);
        if (($input['enabled'] ?? false) === true && ! $before['plan_allows']) {
            throw new AyniException('feature_not_enabled', 403, (string) trans('errors.feature_not_enabled'), [], ['feature' => self::USE_CASES[$useCase]]);
        }
        $roleIds = array_values(array_unique($input['role_ids'] ?? $before['role_ids']));
        if (count($roleIds) !== DB::table('roles')->whereIn('id', $roleIds === [] ? ['00000000-0000-0000-0000-000000000000'] : $roleIds)->count() && $roleIds !== []) {
            throw new AyniException('validation_failed', 422, (string) trans('errors.validation_failed'), [['field' => 'role_ids', 'rule' => 'exists', 'message' => (string) trans('ai.settings.unknown_role')]]);
        }

        DB::transaction(function () use ($useCase, $input, $before, $roleIds, $actorId): void {
            $values = [
                'is_enabled' => $input['enabled'] ?? (bool) DB::table('ai_use_case_settings')->where('use_case', $useCase)->value('is_enabled'),
                'question_limit' => array_key_exists('question_limit', $input) ? $input['question_limit'] : $before['question_limit'],
                'limit_period' => $input['limit_period'] ?? $before['limit_period'],
                'notice' => array_key_exists('notice', $input) ? $input['notice'] : $before['notice'],
                'changed_by_user_id' => $actorId,
                'updated_at' => now(),
            ];
            $id = DB::table('ai_use_case_settings')->where('use_case', $useCase)->value('id');
            if ($id === null) {
                $id = DB::table('ai_use_case_settings')->insertGetId(['tenant_id' => TenancyScope::requireTenant(), 'use_case' => $useCase, ...$values], 'id');
            } else {
                DB::table('ai_use_case_settings')->where('id', $id)->update([...$values, 'lock_version' => DB::raw('lock_version + 1')]);
            }
            DB::table('ai_use_case_roles')->where('ai_use_case_setting_id', $id)->whereNotIn('role_id', $roleIds === [] ? ['00000000-0000-0000-0000-000000000000'] : $roleIds)->delete();
            foreach ($roleIds as $roleId) {
                DB::table('ai_use_case_roles')->insertOrIgnore(['tenant_id' => TenancyScope::requireTenant(), 'ai_use_case_setting_id' => $id, 'role_id' => $roleId]);
            }
        });
        $after = $this->get($useCase);
        $rowId = DB::table('ai_use_case_settings')->where('use_case', $useCase)->value('id');
        $this->audit->record('ai.use_case_settings.update', 'ai_use_case_setting', $rowId === null ? null : (string) $rowId, $before, $after);

        return $after;
    }

    public function planAllows(string $useCase): bool
    {
        return isset(self::USE_CASES[$useCase]) && $this->flags->enabled(self::USE_CASES[$useCase]);
    }
}
