<?php

namespace App\Modules\Organization\Application;

use App\Modules\Organization\Domain\JobCatalogStateMachine;
use App\Modules\Organization\Infrastructure\Models\JobCategory;
use App\Modules\Organization\Infrastructure\Models\JobFamily;
use App\Modules\Organization\Infrastructure\Models\JobLevel;
use App\Modules\Organization\Infrastructure\Models\JobProfile;
use App\Shared\Audit\AuditLogger;
use App\Shared\Exceptions\AyniException;
use App\Shared\Exceptions\VersionConflictException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ORG-5 · Catálogos configurables del catálogo de puestos: familias, niveles y categorías laborales.
 *
 * - Los códigos no se repiten dentro de cada catálogo (sin distinguir mayúsculas).
 * - No se eliminan: se archivan y se restauran por acción. Archivado sigue visible en los puestos que
 *   ya lo usan, pero no se puede elegir para un puesto nuevo ni al cambiar uno.
 */
final class JobCatalogService
{
    /** Tipo de catálogo => [modelo, columna en job_profiles, campos editables]. */
    public const KINDS = [
        'family' => [JobFamily::class, 'job_family_id', ['code', 'name', 'description']],
        'level' => [JobLevel::class, 'job_level_id', ['code', 'name', 'rank', 'description']],
        'category' => [JobCategory::class, 'job_category_id', ['code', 'name', 'rank', 'description', 'functions', 'responsibility_level', 'requirements']],
    ];

    public function __construct(
        private readonly JobCatalogStateMachine $machine,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return class-string<JobFamily|JobLevel|JobCategory>
     */
    public static function model(string $kind): string
    {
        return self::KINDS[$kind][0];
    }

    /**
     * @return array<string, mixed>
     */
    public function present(string $kind, JobFamily|JobLevel|JobCategory $item): array
    {
        $out = ['id' => $item->id];
        foreach (self::KINDS[$kind][2] as $field) {
            $out[$field] = $item->getAttribute($field);
        }

        return $out + [
            'profiles_count' => $this->profilesCount($kind, $item->id),
            'status' => $item->status === 'active' ? 'active' : 'archived',
            'allowed_actions' => $this->machine->allowedActions($item->status),
            'version' => $item->lock_version,
            'created_at' => $item->created_at->toIso8601String(),
            'updated_at' => $item->updated_at->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $input  ya validado
     */
    public function create(string $kind, array $input): JobFamily|JobLevel|JobCategory
    {
        $class = self::model($kind);
        $values = $this->values($kind, $input);
        $this->guardUniqueCode($kind, (string) $values['code'], null);

        return DB::transaction(function () use ($kind, $class, $values): Model {
            $item = $class::create($values + ['status' => 'active']);
            $item->refresh();
            $this->audit->record("job_{$kind}.created", "job_{$kind}", $item->id, null, $this->present($kind, $item));

            return $item;
        });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function update(string $kind, JobFamily|JobLevel|JobCategory $item, array $input, ?int $expectedVersion): JobFamily|JobLevel|JobCategory
    {
        if ($expectedVersion !== null && $expectedVersion !== $item->lock_version) {
            throw new VersionConflictException($expectedVersion, $item->lock_version);
        }
        if ($item->status !== 'active') {
            throw new AyniException('invalid_transition', 409, null, [], ['current_status' => 'archived', 'allowed_actions' => ['restore']]);
        }
        $values = $this->values($kind, $input);
        if (isset($values['code'])) {
            $this->guardUniqueCode($kind, (string) $values['code'], $item->id);
        }

        return DB::transaction(function () use ($kind, $item, $values) {
            $before = $this->present($kind, $item);
            $item->fill($values);
            if ($item->isDirty()) {
                $item->save();
            }
            $item->refresh();
            $this->audit->record("job_{$kind}.updated", "job_{$kind}", $item->id, $before, $this->present($kind, $item));

            return $item;
        });
    }

    public function transition(string $kind, JobFamily|JobLevel|JobCategory $item, string $action, ?string $reason): JobFamily|JobLevel|JobCategory
    {
        $next = $this->machine->getNextState($item->status, $action);

        return DB::transaction(function () use ($kind, $item, $action, $next, $reason) {
            $before = $this->present($kind, $item);
            $item->forceFill(['status' => $next])->save();
            $item->refresh();
            $this->audit->record("job_{$kind}.".($action === 'archive' ? 'archived' : 'restored'), "job_{$kind}", $item->id, $before, $this->present($kind, $item), $reason);

            return $item;
        });
    }

    /** El elemento existe y está activo (se puede elegir para un puesto). */
    public function selectable(string $kind, string $id): bool
    {
        return self::model($kind)::query()->whereKey($id)->where('status', 'active')->exists();
    }

    private function profilesCount(string $kind, string $id): int
    {
        return JobProfile::query()->where(self::KINDS[$kind][1], $id)->count();
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function values(string $kind, array $input): array
    {
        $values = [];
        foreach (self::KINDS[$kind][2] as $field) {
            if (array_key_exists($field, $input)) {
                $value = $input[$field];
                $values[$field] = is_string($value) && trim($value) === '' && $field !== 'code' && $field !== 'name' ? null : $value;
            }
        }
        if (isset($values['code']) && is_string($values['code'])) {
            $values['code'] = strtoupper(trim($values['code']));
        }

        return $values;
    }

    private function guardUniqueCode(string $kind, string $code, ?string $exceptId): void
    {
        $exists = self::model($kind)::query()->whereRaw('lower(code) = lower(?)', [$code])
            ->when($exceptId !== null, fn ($q) => $q->whereKeyNot($exceptId))->exists();
        if ($exists) {
            throw ValidationException::withMessages(['code' => [(string) trans('organization.jobs.duplicate_code')]]);
        }
    }
}
