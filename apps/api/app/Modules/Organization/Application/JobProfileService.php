<?php

namespace App\Modules\Organization\Application;

use App\Modules\Organization\Domain\JobCatalogStateMachine;
use App\Modules\Organization\Infrastructure\Models\JobProfile;
use App\Modules\Organization\Infrastructure\Models\JobProfileVersion;
use App\Shared\Audit\AuditLogger;
use App\Shared\Exceptions\AyniException;
use App\Shared\Exceptions\VersionConflictException;
use App\Shared\Outbox\Contracts\OutboxWriter;
use App\Shared\Outbox\DomainEventTypes;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ORG-5 · Puestos de catálogo (job_profiles). El puesto es la definición; la plaza concreta es la
 * posición, y muchas posiciones pueden usar el mismo puesto.
 *
 * - La descripción (nombre, misión, funciones y requisitos) se versiona: cada cambio inserta una
 *   versión inalterable en job_profile_versions con su motivo; la ficha muestra la vigente.
 * - RN-ORG-04: un puesto no se elimina; se archiva y entonces no admite nuevas posiciones.
 * - RN-ORG-07: sin categoría laboral queda «pendiente» (category_pending) para el cuadro de
 *   categorías y funciones.
 * - Solo se eligen familias, niveles y categorías activos.
 */
final class JobProfileService
{
    /** Campos editables: nombre de la API => columna. */
    public const FIELDS = [
        'code' => 'code', 'name' => 'name', 'job_family_id' => 'job_family_id', 'job_level_id' => 'job_level_id',
        'job_category_id' => 'job_category_id', 'mission' => 'mission', 'functions' => 'functions',
        'requirements' => 'requirements', 'occupation_code' => 'occupation_code', 'risk_job' => 'is_risk_job',
        'trust_classification' => 'trust_classification',
    ];

    /** Columnas que forman la descripción versionada. */
    private const DESCRIPTION = ['name', 'mission', 'functions', 'requirements'];

    /** Estados de posición que ya no usan el puesto (no cuentan como uso vigente). */
    private const CLOSED_POSITION_STATUSES = ['closed'];

    public function __construct(
        private readonly JobCatalogStateMachine $machine,
        private readonly JobCatalogService $catalogs,
        private readonly AuditLogger $audit,
        private readonly OutboxWriter $outbox,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function present(JobProfile $profile): array
    {
        return [
            'id' => $profile->id,
            'code' => $profile->code,
            'name' => $profile->name,
            'job_family_id' => $profile->job_family_id,
            'job_level_id' => $profile->job_level_id,
            'job_category_id' => $profile->job_category_id,
            'mission' => $profile->mission,
            'functions' => $profile->functions,
            'requirements' => $profile->requirements,
            'occupation_code' => $profile->occupation_code,
            'risk_job' => $profile->is_risk_job,
            'trust_classification' => $profile->trust_classification,
            'category_pending' => $profile->job_category_id === null,
            'positions_count' => $this->positionsCount($profile),
            'current_version' => $this->currentVersion($profile->id),
            'status' => $profile->status === 'inactive' ? 'archived' : 'active',
            'allowed_actions' => $this->machine->allowedActions($profile->status === 'inactive' ? 'inactive' : 'active'),
            'version' => $profile->lock_version,
            'created_at' => $profile->created_at->toIso8601String(),
            'updated_at' => $profile->updated_at->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function presentVersion(JobProfileVersion $version): array
    {
        return [
            'version' => $version->version,
            'name' => $version->name,
            'mission' => $version->mission,
            'functions' => $version->functions,
            'requirements' => $version->requirements,
            'change_reason' => $version->change_reason,
            'changed_by_user_id' => $version->changed_by_user_id,
            'changed_by_name' => $version->changed_by_user_id === null ? null
                : DB::table('users')->where('id', $version->changed_by_user_id)->value('display_name'),
            'created_at' => $version->created_at->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $input  ya validado (claves de la API)
     */
    public function create(array $input, ?string $userId): JobProfile
    {
        $values = $this->columns($input);
        $this->check($values, null);

        return DB::transaction(function () use ($values, $input, $userId): JobProfile {
            $profile = JobProfile::create($values + ['status' => 'active']);
            $profile->refresh();
            $this->snapshot($profile, 1, $this->reason($input), $userId);
            $this->outbox->record(DomainEventTypes::JOB_PROFILE_CREATED, 'job_profile', $profile->id, ['job_profile_id' => $profile->id, 'version' => 1]);
            $this->audit->record('job_profile.created', 'job_profile', $profile->id, null, $this->present($profile));

            return $profile;
        });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function update(JobProfile $profile, array $input, ?int $expectedVersion, ?string $userId): JobProfile
    {
        if ($expectedVersion !== null && $expectedVersion !== $profile->lock_version) {
            throw new VersionConflictException($expectedVersion, $profile->lock_version);
        }
        if ($profile->status === 'inactive') {
            throw new AyniException('invalid_transition', 409, null, [], ['current_status' => 'archived', 'allowed_actions' => ['restore']]);
        }
        $values = $this->columns($input);
        $this->check($values, $profile);

        return DB::transaction(function () use ($profile, $values, $input, $userId): JobProfile {
            $before = $this->present($profile);
            $profile->fill($values);
            $descriptionChanged = $profile->isDirty(self::DESCRIPTION);
            if ($profile->isDirty()) {
                $profile->save();
            }
            $profile->refresh();
            $version = $this->currentVersion($profile->id);
            if ($descriptionChanged) {
                $version++;
                $this->snapshot($profile, $version, $this->reason($input), $userId);
            }
            $this->outbox->record(DomainEventTypes::JOB_PROFILE_UPDATED, 'job_profile', $profile->id, [
                'job_profile_id' => $profile->id, 'version' => $version, 'description_changed' => $descriptionChanged,
            ]);
            $this->audit->record('job_profile.updated', 'job_profile', $profile->id, $before, $this->present($profile), $this->reason($input));

            return $profile;
        });
    }

    public function transition(JobProfile $profile, string $action, ?string $reason): JobProfile
    {
        $current = $profile->status === 'inactive' ? 'inactive' : 'active';
        $next = $this->machine->getNextState($current, $action);

        return DB::transaction(function () use ($profile, $action, $next, $reason): JobProfile {
            $before = $this->present($profile);
            $profile->forceFill(['status' => $next])->save();
            $profile->refresh();
            $this->audit->record('job_profile.'.($action === 'archive' ? 'archived' : 'restored'), 'job_profile', $profile->id, $before, $this->present($profile), $reason);

            return $profile;
        });
    }

    /** Posiciones (no cerradas) que usan el puesto: un puesto puede usarse en muchas. */
    public function positionsCount(JobProfile $profile): int
    {
        return (int) DB::table('positions')->where('job_profile_id', $profile->id)
            ->whereNull('deleted_at')->whereNotIn('status', self::CLOSED_POSITION_STATUSES)->count();
    }

    public function currentVersion(string $profileId): int
    {
        return (int) JobProfileVersion::query()->where('job_profile_id', $profileId)->max('version');
    }

    private function snapshot(JobProfile $profile, int $version, ?string $reason, ?string $userId): void
    {
        JobProfileVersion::create([
            'job_profile_id' => $profile->id,
            'version' => $version,
            'name' => $profile->name,
            'mission' => $profile->mission,
            'functions' => $profile->functions,
            'requirements' => $profile->requirements,
            'change_reason' => $reason,
            'changed_by_user_id' => $userId,
        ]);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function reason(array $input): ?string
    {
        $reason = $input['change_reason'] ?? null;

        return is_string($reason) && trim($reason) !== '' ? trim($reason) : null;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function columns(array $input): array
    {
        $values = [];
        foreach (self::FIELDS as $in => $column) {
            if (array_key_exists($in, $input)) {
                $value = $input[$in];
                $values[$column] = is_string($value) && trim($value) === '' && ! in_array($in, ['code', 'name'], true) ? null : $value;
            }
        }
        if (isset($values['code']) && is_string($values['code'])) {
            $values['code'] = strtoupper(trim($values['code']));
        }

        return $values;
    }

    /**
     * Reglas que dependen de otros datos (las de forma están en el controlador).
     *
     * @param  array<string, mixed>  $v  columnas
     */
    private function check(array $v, ?JobProfile $profile): void
    {
        $errors = [];
        $code = $v['code'] ?? null;
        if (is_string($code) && JobProfile::query()->whereRaw('lower(code) = lower(?)', [$code])
            ->when($profile instanceof JobProfile, fn ($q) => $q->whereKeyNot($profile?->id))->exists()) {
            $errors['code'] = [(string) trans('organization.jobs.duplicate_code')];
        }
        foreach (['family' => 'job_family_id', 'level' => 'job_level_id', 'category' => 'job_category_id'] as $kind => $column) {
            $id = $v[$column] ?? null;
            // Solo se valida lo que cambia: un puesto conserva su nivel aunque luego se archive.
            if (is_string($id) && (! $profile instanceof JobProfile || $profile->getAttribute($column) !== $id) && ! $this->catalogs->selectable($kind, $id)) {
                $errors[$column] = [(string) trans('organization.jobs.not_selectable')];
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
