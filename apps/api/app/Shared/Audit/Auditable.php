<?php

namespace App\Shared\Audit;

use Illuminate\Database\Eloquent\Model;

/**
 * AUD-2 · Captura automática de antes y después por campo para modelos auditables.
 *
 * El modelo declara `protected string $auditEntity = 'employment';`. Cada creación,
 * cambio o eliminación (una por registro, también en acciones masivas que iteran modelos)
 * deja una entrada con los atributos cambiados. Los sensibles se guardan cifrados.
 * Las actualizaciones masivas por consulta (query()->update) no pasan por aquí: los
 * casos de uso de dominio iteran los modelos.
 *
 * @mixin Model
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(static function (Model $model): void {
            self::audit($model, 'created', null, self::auditAttributes($model, array_keys($model->getAttributes())));
        });

        static::updated(static function (Model $model): void {
            $changed = array_keys($model->getChanges());
            $after = self::auditAttributes($model, $changed);
            if ($after === []) {
                return;
            }
            $before = [];
            foreach (array_keys($after) as $key) {
                $before[$key] = $model->getOriginal($key);
            }
            self::audit($model, 'updated', $before, $after);
        });

        static::deleted(static function (Model $model): void {
            self::audit($model, 'deleted', self::auditAttributes($model, array_keys($model->getAttributes())), null);
        });
    }

    /**
     * @param  array<int, string>  $keys
     * @return array<string, mixed>
     */
    private static function auditAttributes(Model $model, array $keys): array
    {
        /** @var array<int, string> $ignored */
        $ignored = array_merge((array) config('audit.ignored_attributes', []), $model->getHidden(), ['id', 'tenant_id']);
        $out = [];
        foreach ($keys as $key) {
            if (! in_array($key, $ignored, true)) {
                $out[$key] = $model->getAttributeValue($key);
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    private static function audit(Model $model, string $event, ?array $before, ?array $after): void
    {
        $entity = property_exists($model, 'auditEntity') ? (string) $model->auditEntity : $model->getTable();
        app(AuditLogger::class)->record("{$entity}.{$event}", $entity, (string) $model->getKey(), $before, $after);
    }
}
