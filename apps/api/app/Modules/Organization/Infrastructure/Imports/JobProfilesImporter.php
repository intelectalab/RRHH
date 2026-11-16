<?php

namespace App\Modules\Organization\Infrastructure\Imports;

use App\Modules\Organization\Application\JobProfileService;
use App\Modules\Organization\Infrastructure\Models\JobProfile;
use App\Shared\Audit\AuditLogger;
use App\Shared\Imports\ImportColumn;
use App\Shared\Imports\ImportContext;
use App\Shared\Imports\ImportDryRun;
use App\Shared\Imports\Importer;
use App\Shared\Imports\ImportValues;
use App\Shared\Imports\RowCheck;
use Illuminate\Support\Facades\DB;

/**
 * IMP-2 · Importador del catálogo de puestos. Cada alta pasa por JobProfileService::create (código único,
 * familia, nivel y categoría seleccionables) y deja su primera versión, igual que el alta manual.
 */
final class JobProfilesImporter implements Importer
{
    /** Tablas que, si usan el puesto, impiden deshacer (RN-IMP-07). */
    private const DEPENDENTS = ['employment_assignments' => 'job_profile_id', 'positions' => 'job_profile_id'];

    public function __construct(private readonly JobProfileService $profiles, private readonly AuditLogger $audit) {}

    public function entity(): string
    {
        return 'job_profiles';
    }

    public function permission(): string
    {
        return 'org.job_profiles.create';
    }

    public function columns(): array
    {
        return [
            new ImportColumn('code', true, 'ANL-CONT', ['codigo', 'codigo_puesto']),
            new ImportColumn('name', true, 'Analista contable', ['nombre', 'puesto', 'cargo']),
            new ImportColumn('family', false, 'FIN', ['familia']),
            new ImportColumn('level', false, 'N3', ['nivel']),
            new ImportColumn('category', false, 'EMP', ['categoria']),
            new ImportColumn('mission', false, 'Registrar y analizar las operaciones contables.', ['mision', 'objetivo']),
            new ImportColumn('occupation_code', false, '2411', ['ocupacion', 'codigo_ocupacion']),
            new ImportColumn('risk_job', false, 'no', ['riesgo', 'puesto_de_riesgo']),
        ];
    }

    public function naturalKey(array $data): ?string
    {
        $code = mb_strtoupper(trim($data['code'] ?? ''));

        return $code === '' ? null : $code;
    }

    public function validate(array $data, ImportContext $context): RowCheck
    {
        $lookups = $context->lookups();
        $check = new RowCheck([
            'code' => mb_strtoupper(trim($data['code'] ?? '')), 'name' => trim($data['name'] ?? ''),
            'mission' => ($data['mission'] ?? '') !== '' ? $data['mission'] : null,
            'occupation_code' => ($data['occupation_code'] ?? '') !== '' ? $data['occupation_code'] : null,
            'risk_job' => false,
        ]);
        foreach (['family' => 'job_families', 'level' => 'job_levels', 'category' => 'job_categories'] as $column => $table) {
            if (($data[$column] ?? '') === '') {
                continue;
            }
            $id = $lookups->byCodeOrName($table, $data[$column]);
            if ($id === null) {
                $check->error($column, 'not_found', (string) trans('imports.catalog_missing', ['value' => $data[$column]], $context->locale));
            }
            $check->parsed["job_{$column}_id"] = $id;
        }
        if (($data['risk_job'] ?? '') !== '') {
            $risk = ImportValues::bool($data['risk_job']);
            if ($risk === null) {
                $check->error('risk_job', 'invalid', (string) trans('imports.invalid_bool', [], $context->locale));
            }
            $check->parsed['risk_job'] = (bool) $risk;
        }

        return ImportDryRun::check($check, fn () => $this->profiles->create($check->parsed + ['change_reason' => 'import'], $context->userId), [
            'code' => 'code', 'name' => 'name', 'job_family_id' => 'family', 'job_level_id' => 'level', 'job_category_id' => 'category',
            'mission' => 'mission', 'occupation_code' => 'occupation_code',
        ]);
    }

    public function import(array $parsed, ImportContext $context): array
    {
        $profile = $this->profiles->create($parsed + ['change_reason' => 'import'], $context->userId);

        return ['entity_id' => $profile->id, 'previous' => null];
    }

    public function blockingReason(string $entityId, ImportContext $context): ?string
    {
        $profile = JobProfile::query()->whereKey($entityId)->first();
        if ($profile === null) {
            return null;
        }
        foreach (self::DEPENDENTS as $table => $column) {
            if (DB::table($table)->where($column, $entityId)->when($table === 'positions', fn ($q) => $q->whereNull('deleted_at'))->exists()) {
                return (string) trans('imports.in_use', ['what' => (string) trans("imports.uses.{$table}", [], $context->locale)], $context->locale);
            }
        }
        if ($profile->status !== 'active' || $this->profiles->currentVersion($entityId) > 1) {
            return (string) trans('imports.changed_after_import', [], $context->locale);
        }

        return null;
    }

    public function revert(string $entityId, ?array $previous, ImportContext $context): void
    {
        $profile = JobProfile::query()->whereKey($entityId)->first();
        if ($profile === null) {
            return;
        }
        $before = $profile->only(['code', 'name']);
        $profile->delete();
        $this->audit->record('job_profile.import_reverted', 'job_profile', $profile->id, $before, null, 'import:'.$context->jobId);
    }
}
