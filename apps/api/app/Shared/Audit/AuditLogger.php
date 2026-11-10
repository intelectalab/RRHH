<?php

namespace App\Shared\Audit;

use App\Shared\Tenancy\TenancyScope;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

/**
 * Auditoría de negocio de solo inserción (regla 5 de CLAUDE.md): quién, qué, cuándo,
 * desde dónde, valor anterior y nuevo. La tabla no admite UPDATE ni DELETE.
 */
final class AuditLogger
{
    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function record(
        string $action,
        string $entityType,
        ?string $entityId,
        ?array $before,
        ?array $after,
        ?string $reason = null,
    ): void {
        $request = request();
        $user = $request->user();
        $actorId = $user instanceof Authenticatable ? (string) $user->getAuthIdentifier() : null;
        $requestId = (string) $request->header('X-Request-Id', '');

        DB::table('audit_events')->insert([
            'tenant_id' => TenancyScope::requireTenant(),
            'actor_user_id' => $actorId,
            'actor_kind' => $actorId === null ? 'system' : 'user',
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'before_data' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
            'after_data' => $after === null ? null : json_encode($after, JSON_THROW_ON_ERROR),
            'changed_fields' => $this->pgArray($this->changedFields($before, $after)),
            'reason' => $reason,
            'ip' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
            'request_id' => preg_match('/^[0-9a-f-]{36}$/i', $requestId) === 1 ? $requestId : null,
            'occurred_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     * @return array<int, string>
     */
    private function changedFields(?array $before, ?array $after): array
    {
        $keys = array_unique(array_merge(array_keys($before ?? []), array_keys($after ?? [])));
        $changed = [];
        foreach ($keys as $key) {
            if (($before[$key] ?? null) != ($after[$key] ?? null)) {
                $changed[] = (string) $key;
            }
        }
        sort($changed);

        return $changed;
    }

    /**
     * @param  array<int, string>  $values
     */
    private function pgArray(array $values): string
    {
        return '{'.implode(',', array_map(fn (string $v) => '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $v).'"', $values)).'}';
    }
}
