<?php

namespace App\Modules\Audit\Application;

use App\Shared\Permissions\EffectivePermissions;
use Illuminate\Support\Facades\Crypt;

/**
 * Presenta entradas de auditoría: descifra los campos sensibles para quien tiene el
 * permiso del campo y los enmascara para el resto (AUD-2, AUD-4).
 */
final class AuditPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function present(object $record, EffectivePermissions $permissions): array
    {
        /** @var array<string, string|null> $row */
        $row = (array) $record;
        $schema = config('audit.entities.'.($row['entity_type'] ?? ''));
        $map = is_string($schema) ? (array) config("sensitive_fields.{$schema}", []) : [];
        $sensitive = false;

        $reveal = function (?string $json) use ($map, $permissions, &$sensitive): ?array {
            if ($json === null) {
                return null;
            }
            /** @var array<string, mixed> $data */
            $data = (array) json_decode($json, true);
            foreach ($data as $field => $value) {
                if (! is_array($value) || ! isset($value['_enc'])) {
                    continue;
                }
                $sensitive = true;
                $permission = $map[$field][0] ?? null;
                // Ve el valor quien tiene el permiso del campo o el de valores sensibles de auditoría.
                $data[$field] = ($permissions->has('audit.logs.sensitive_values.view') || ($permission !== null && $permissions->has($permission)))
                    ? json_decode(Crypt::decryptString((string) $value['_enc']), true)
                    : config('audit.mask');
            }

            return $data;
        };

        $before = $reveal($row['before_data']);
        $after = $reveal($row['after_data']);
        $changed = trim((string) ($row['changed_fields'] ?? '{}'), '{}');

        return [
            'id' => $row['id'],
            'actor_id' => $row['actor_user_id'],
            'actor_name' => $row['actor_name'] ?? null,
            'actor_kind' => $row['actor_kind'],
            'action' => $row['action'],
            'entity' => $row['entity_type'],
            'entity_id' => (string) ($row['entity_id'] ?? ''),
            'before' => $before,
            'after' => $after,
            'changed_fields' => $changed === '' ? [] : array_map(fn ($f) => trim($f, '"'), explode(',', $changed)),
            'reason' => $row['reason'],
            'ip' => $row['ip'],
            'occurred_at' => (new \DateTimeImmutable((string) $row['occurred_at']))->format(DATE_ATOM),
            'sensitive' => $sensitive,
        ];
    }
}
