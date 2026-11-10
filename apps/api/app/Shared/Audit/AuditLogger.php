<?php

namespace App\Shared\Audit;

use App\Shared\Tenancy\TenancyScope;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Crypt;
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

        $changed = $this->changedFields($before, $after);
        [$before, $after] = [$this->protect($entityType, $before), $this->protect($entityType, $after)];

        DB::table('audit_events')->insert([
            'tenant_id' => TenancyScope::requireTenant(),
            'actor_user_id' => $actorId,
            'actor_kind' => $actorId === null ? 'system' : 'user',
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'before_data' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
            'after_data' => $after === null ? null : json_encode($after, JSON_THROW_ON_ERROR),
            'changed_fields' => $this->pgArray($changed),
            'reason' => $reason,
            'ip' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
            'request_id' => preg_match('/^[0-9a-f-]{36}$/i', $requestId) === 1 ? $requestId : null,
            'occurred_at' => now()->format('Y-m-d H:i:s.uP'),
        ]);
    }

    /**
     * Cifra los valores de campos sensibles antes de guardarlos (AUD-2).
     *
     * @param  array<string, mixed>|null  $data
     * @return array<string, mixed>|null
     */
    private function protect(string $entityType, ?array $data): ?array
    {
        if ($data === null) {
            return null;
        }
        $schema = config("audit.entities.{$entityType}");
        $sensitive = is_string($schema) ? array_keys((array) config("sensitive_fields.{$schema}", [])) : [];
        foreach ($sensitive as $field) {
            if (array_key_exists($field, $data) && $data[$field] !== null) {
                $data[$field] = ['_enc' => Crypt::encryptString(json_encode($data[$field], JSON_THROW_ON_ERROR))];
            }
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     * @return array<int, string>
     */
    private function changedFields(?array $before, ?array $after): array
    {
        // En altas y bajas no hay «campos cambiados»: se guarda la foto completa.
        if ($before === null || $after === null) {
            return [];
        }
        /** @var array<int, string> $noise */
        $noise = array_merge((array) config('audit.ignored_attributes', []), ['version', 'allowed_actions']);
        $before = array_diff_key($before, array_flip($noise));
        $after = array_diff_key($after, array_flip($noise));

        $keys = array_unique(array_merge(array_keys($before), array_keys($after)));
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
