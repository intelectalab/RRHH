<?php

namespace App\Modules\Settings\Infrastructure\Trash;

use App\Modules\Settings\Infrastructure\Models\CustomFieldDefinition;
use App\Shared\Audit\AuditLogger;
use App\Shared\Trash\TrashSource;
use Illuminate\Support\Facades\DB;

/**
 * Campos personalizados desactivados: al restaurarlos vuelven con todos sus valores.
 */
final class CustomFieldTrashSource implements TrashSource
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function type(): string
    {
        return 'custom_field';
    }

    public function permission(): string
    {
        return 'settings.custom_fields.view';
    }

    public function items(?string $search, int $limit): array
    {
        $q = CustomFieldDefinition::onlyTrashed()->orderByDesc('deleted_at');
        if ($search !== null && $search !== '') {
            $q->where('label', 'ilike', '%'.addcslashes($search, '%_\\').'%');
        }

        return $q->limit($limit)->get()->map(fn (CustomFieldDefinition $d) => [
            'id' => $d->id,
            'title' => $d->label,
            'subtitle' => $d->entity_type.' · '.DB::table('custom_field_values')->where('custom_field_definition_id', $d->id)->count().' valores',
            'deleted_at' => (string) $d->deleted_at?->toIso8601String(),
        ])->all();
    }

    public function owns(string $id): bool
    {
        return CustomFieldDefinition::onlyTrashed()->whereKey($id)->exists();
    }

    public function restore(string $id): void
    {
        /** @var CustomFieldDefinition $d */
        $d = CustomFieldDefinition::onlyTrashed()->whereKey($id)->firstOrFail();
        DB::transaction(function () use ($d): void {
            $d->restore();
            $this->audit->record('custom_field.restored', 'custom_field', $d->id, null, ['key' => $d->key]);
        });
    }
}
