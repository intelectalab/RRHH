<?php

namespace App\Modules\CoreHR\Infrastructure\Imports;

use App\Modules\CoreHR\Application\EmploymentService;
use App\Modules\CoreHR\Application\IdentityDocuments;
use App\Modules\CoreHR\Application\PersonService;
use App\Modules\CoreHR\Infrastructure\Models\Employment;
use App\Modules\CoreHR\Infrastructure\Models\Person;
use App\Shared\Audit\AuditLogger;
use App\Shared\Imports\ImportColumn;
use App\Shared\Imports\ImportContext;
use App\Shared\Imports\ImportDryRun;
use App\Shared\Imports\Importer;
use App\Shared\Imports\ImportValues;
use App\Shared\Imports\RowCheck;
use App\Shared\Labor\ContractTypeSource;
use App\Shared\Organization\PositionOccupancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * IMP-2 · Importador de colaboradores: persona, relación laboral, asignación inicial, régimen, primer
 * contrato y sueldo de ingreso. Cada fila pasa por EmploymentService::hire, igual que el ingreso manual
 * (RN-IMP-06): documento único, una sola relación abierta por empresa, límite del plan, posición
 * (POS-6) y vigencias sin solape. Una persona que ya existe por documento se reutiliza (aviso) y una
 * repetida en el archivo es error. El sueldo exige el permiso por campo (RN-IMP-04).
 */
final class EmployeesImporter implements Importer
{
    /** Filas que el propio ingreso crea; cualquier otra fila que apunte a la relación impide deshacer. */
    private const CREATED_BY_HIRE = ['employment_assignments', 'employment_labor_regimes', 'contracts', 'employment_compensations', 'employment_status_events'];

    /** Registros de acceso y bitácoras: no son uso del dato. */
    private const IGNORED = ['data_access_logs', 'ai_interactions', 'notifications', 'notification_deliveries'];

    private const SEX = ['f' => 'female', 'femenino' => 'female', 'female' => 'female', 'm' => 'male', 'masculino' => 'male', 'male' => 'male'];

    /** @var list<string>|null */
    private ?array $referencing = null;

    public function __construct(
        private readonly EmploymentService $employments,
        private readonly PersonService $people,
        private readonly ContractTypeSource $contractTypes,
        private readonly PositionOccupancy $occupancy,
        private readonly AuditLogger $audit,
    ) {}

    public function entity(): string
    {
        return 'employees';
    }

    public function permission(): string
    {
        return 'people.employments.create';
    }

    public function columns(): array
    {
        return [
            new ImportColumn('document_type', false, 'dni', ['tipo_documento', 'tipo_doc']),
            new ImportColumn('document_number', true, '45678912', ['documento', 'dni', 'numero_documento', 'nro_documento']),
            new ImportColumn('first_names', true, 'Rosa María', ['nombres', 'nombre']),
            new ImportColumn('paternal_surname', true, 'Quispe', ['apellido_paterno', 'primer_apellido']),
            new ImportColumn('maternal_surname', false, 'Mamani', ['apellido_materno', 'segundo_apellido']),
            new ImportColumn('birth_date', false, '12/04/1990', ['fecha_nacimiento', 'nacimiento']),
            new ImportColumn('sex', false, 'F', ['sexo', 'genero']),
            new ImportColumn('personal_email', false, 'rosa@correo.pe', ['correo_personal', 'email']),
            new ImportColumn('mobile_phone', false, '987654321', ['celular', 'telefono']),
            new ImportColumn('legal_entity', true, '20123456781', ['empresa', 'ruc']),
            new ImportColumn('employee_code', false, 'E-0001', ['codigo', 'codigo_colaborador', 'codigo_trabajador']),
            new ImportColumn('hire_date', true, '05/01/2026', ['fecha_ingreso', 'ingreso']),
            new ImportColumn('position_code', false, 'POS-0101', ['posicion', 'plaza']),
            new ImportColumn('job_profile_code', false, 'ANL-CONT', ['puesto', 'cargo']),
            new ImportColumn('org_unit_code', false, 'ADM-FIN', ['unidad', 'area']),
            new ImportColumn('site', false, 'Sede central', ['sede', 'local']),
            new ImportColumn('cost_center_code', false, 'CC-100', ['centro_de_costo', 'centro_costo']),
            new ImportColumn('manager_document', false, '41234567', ['jefe', 'documento_jefe', 'dni_jefe']),
            new ImportColumn('contract_type', true, 'indeterminado', ['tipo_contrato', 'contrato']),
            new ImportColumn('contract_end_date', false, '', ['fin_contrato', 'fecha_fin']),
            new ImportColumn('labor_regime', false, 'pe_general', ['regimen', 'regimen_laboral']),
            new ImportColumn('base_salary', false, '3500.00', ['sueldo', 'remuneracion', 'sueldo_basico'], 'people.employments.salary.edit'),
            new ImportColumn('currency', false, 'PEN', ['moneda']),
            new ImportColumn('work_email', false, 'rosa.quispe@empresa.pe', ['correo_laboral', 'correo_trabajo']),
        ];
    }

    public function naturalKey(array $data): ?string
    {
        $number = IdentityDocuments::normalize($data['document_number'] ?? '');

        return $number === '' ? null : $this->docType($data).':'.$number;
    }

    public function validate(array $data, ImportContext $context): RowCheck
    {
        $this->contractTypes->ensureCountryTypes();
        $l = $context->lookups();
        $entity = $l->legalEntity($data['legal_entity'] ?? '');
        $type = $this->docType($data);
        $number = IdentityDocuments::normalize($data['document_number'] ?? '');
        $check = new RowCheck(['document_type' => $type, 'document_number' => $number, 'legal_entity_id' => $entity, 'manager_document' => null]);
        if ($entity === null) {
            $check->error('legal_entity', 'not_found', (string) trans('imports.catalog_missing', ['value' => $data['legal_entity'] ?? ''], $context->locale));
        }

        $existing = $number !== '' ? $this->people->findByDocument($type, $number) : null;
        if ($existing instanceof Person) {
            $check->parsed['person_id'] = $existing->id;
            $check->warning('document_number', 'person_exists', (string) trans('imports.person_exists', [], $context->locale));
        } else {
            $check->parsed['person'] = array_filter([
                'first_names' => trim($data['first_names'] ?? ''), 'paternal_surname' => trim($data['paternal_surname'] ?? ''),
                'maternal_surname' => trim($data['maternal_surname'] ?? '') ?: null, 'document_type' => $type, 'document_number' => $number,
                'personal_email' => trim($data['personal_email'] ?? '') ?: null, 'mobile_phone' => trim($data['mobile_phone'] ?? '') ?: null,
            ], fn ($v) => $v !== null);
            if (($data['birth_date'] ?? '') !== '') {
                $birth = ImportValues::date($data['birth_date']);
                if ($birth === null) {
                    $check->error('birth_date', 'invalid', (string) trans('imports.invalid_date', [], $context->locale));
                } else {
                    $check->parsed['person']['birth_date'] = $birth;
                }
            }
            if (($data['sex'] ?? '') !== '') {
                $sex = self::SEX[mb_strtolower(trim($data['sex']))] ?? null;
                if ($sex === null) {
                    $check->error('sex', 'invalid', (string) trans('imports.invalid_value', [], $context->locale));
                } else {
                    $check->parsed['person']['sex'] = $sex;
                }
            }
        }

        $hire = ImportValues::date($data['hire_date'] ?? '');
        if ($hire === null) {
            $check->error('hire_date', 'invalid', (string) trans('imports.invalid_date', [], $context->locale));
        } else {
            $check->parsed['hire_date'] = $hire;
        }
        if (($data['contract_end_date'] ?? '') !== '') {
            $end = ImportValues::date($data['contract_end_date']);
            if ($end === null) {
                $check->error('contract_end_date', 'invalid', (string) trans('imports.invalid_date', [], $context->locale));
            } else {
                $check->parsed['contract_end_date'] = $end;
            }
        }
        $check->parsed['contract_type_id'] = $l->byCodeOrName('contract_types', $data['contract_type'] ?? '');
        $refs = ['contract_type' => $check->parsed['contract_type_id']];
        foreach (['position_code' => ['positions', 'position_id'], 'job_profile_code' => ['job_profiles', 'job_profile_id'], 'org_unit_code' => ['org_units', 'org_unit_id'],
            'cost_center_code' => ['cost_centers', 'cost_center_id']] as $column => [$table, $key]) {
            if (($data[$column] ?? '') !== '') {
                $refs[$column] = $check->parsed[$key] = $l->byCode($table, $data[$column], $entity);
            }
        }
        if (($data['site'] ?? '') !== '') {
            $refs['site'] = $check->parsed['site_id'] = $l->byCodeOrName('sites', $data['site'], $entity);
        }
        foreach ($refs as $column => $id) {
            if ($id === null) {
                $check->error($column, 'not_found', (string) trans('imports.catalog_missing', ['value' => $data[$column] ?? ''], $context->locale));
            }
        }
        foreach (['employee_code' => 'employee_code', 'labor_regime' => 'labor_regime_code', 'work_email' => 'work_email'] as $column => $key) {
            if (trim($data[$column] ?? '') !== '') {
                $check->parsed[$key] = trim($data[$column]);
            }
        }

        // El jefe ya trabaja en la empresa o viene antes en el archivo (se resuelve al importar).
        $managerDoc = IdentityDocuments::normalize($data['manager_document'] ?? '');
        if ($managerDoc !== '' && $entity !== null) {
            $check->parsed['manager_document'] = $managerDoc;
            $manager = $l->employment('dni', $managerDoc, $entity);
            if ($manager === null && ! isset($context->memo['employees']["dni:{$managerDoc}"])) {
                $check->error('manager_document', 'not_found', (string) trans('imports.manager_missing', [], $context->locale));
            }
        }

        $salary = trim($data['base_salary'] ?? '');
        if ($salary !== '') {
            $amount = ImportValues::decimal($salary);
            $currency = strtoupper(trim($data['currency'] ?? '')) ?: (string) DB::table('legal_entities')->where('id', $entity ?? '')->value('currency');
            if ($amount === null) {
                $check->error('base_salary', 'invalid', (string) trans('imports.invalid_number', [], $context->locale));
            } else {
                $check->parsed['base_salary'] = ['amount' => $amount, 'currency' => $currency];
            }
        }

        ImportDryRun::check($check, fn () => $this->hire($check->parsed, $context), [
            'person_id' => 'document_number', 'person.document_number' => 'document_number', 'person.document_type' => 'document_type',
            'person.first_names' => 'first_names', 'person.paternal_surname' => 'paternal_surname', 'legal_entity_id' => 'legal_entity',
            'hire_date' => 'hire_date', 'employee_code' => 'employee_code', 'position_id' => 'position_code', 'job_profile_id' => 'job_profile_code',
            'org_unit_id' => 'org_unit_code', 'site_id' => 'site', 'cost_center_id' => 'cost_center_code', 'contract_type_id' => 'contract_type',
            'contract_end_date' => 'contract_end_date', 'labor_regime_code' => 'labor_regime', 'base_salary' => 'base_salary', 'work_email' => 'work_email',
        ]);
        if ($check->ok()) {
            $context->memo['employees']["{$type}:{$number}"] = true;
        }

        return $check;
    }

    public function import(array $parsed, ImportContext $context): array
    {
        // Una persona creada por una fila anterior del mismo archivo (otra empresa) ya existe ahora.
        if (! isset($parsed['person_id'])) {
            $person = $this->people->findByDocument((string) $parsed['document_type'], (string) $parsed['document_number']);
            if ($person instanceof Person) {
                $parsed['person_id'] = $person->id;
                unset($parsed['person']);
            }
        }

        return ['entity_id' => $this->hire($parsed, $context)->id, 'previous' => null];
    }

    public function blockingReason(string $entityId, ImportContext $context): ?string
    {
        $employment = Employment::query()->whereKey($entityId)->first();
        if ($employment === null) {
            return null;
        }
        if ($employment->lock_version > 1 || DB::table('employment_status_events')->where('employment_id', $entityId)->count() > 1
            || DB::table('employment_assignments')->where('employment_id', $entityId)->count() > 1
            || DB::table('employment_compensations')->where('employment_id', $entityId)->count() > 1
            || DB::table('contracts')->where('employment_id', $entityId)->count() > 1) {
            return (string) trans('imports.changed_after_import', [], $context->locale);
        }
        if (DB::table('employment_assignments')->where('manager_employment_id', $entityId)->exists()) {
            return (string) trans('imports.in_use', ['what' => (string) trans('imports.uses.direct_reports', [], $context->locale)], $context->locale);
        }
        // Una sola consulta sobre todas las tablas que apuntan a la relación (vacaciones, documentos, aprobaciones…).
        $tables = $this->referencingTables();
        if ($tables !== []) {
            $sql = implode(' UNION ALL ', array_map(fn (string $t) => "(SELECT '{$t}' AS t FROM \"{$t}\" WHERE employment_id = ?"
                .(Schema::hasColumn($t, 'deleted_at') ? ' AND deleted_at IS NULL' : '').' LIMIT 1)', $tables));
            $hit = DB::selectOne("SELECT t FROM ({$sql}) x LIMIT 1", array_fill(0, count($tables), $entityId));
            if ($hit !== null) {
                return (string) trans('imports.in_use', ['what' => (string) $hit->t], $context->locale);
            }
        }

        return null;
    }

    /**
     * Deshace el ingreso importado: quita lo que el ingreso creó (asignación, régimen, sueldo y contrato
     * de ingreso), la relación pasa a la papelera y la persona también si la creó esta importación y no
     * tiene otra relación. La plaza implícita del ingreso (POS-6) se retira; una plaza real se libera.
     */
    public function revert(string $entityId, ?array $previous, ImportContext $context): void
    {
        $employment = Employment::query()->whereKey($entityId)->first();
        if ($employment === null) {
            return;
        }
        $positionId = DB::table('employment_assignments')->where('employment_id', $entityId)->value('position_id');
        $before = $employment->only(['person_id', 'legal_entity_id', 'employee_code', 'hire_date', 'status']);
        DB::table('employment_assignments')->where('employment_id', $entityId)->delete();
        DB::table('employment_labor_regimes')->where('employment_id', $entityId)->delete();
        DB::table('employment_compensations')->where('employment_id', $entityId)->delete();
        DB::table('contracts')->where('employment_id', $entityId)->update(['deleted_at' => now()]);
        $employment->delete();
        if (is_string($positionId)) {
            if (DB::table('positions')->where('id', $positionId)->value('is_implicit') === true) {
                DB::table('positions')->where('id', $positionId)->update(['deleted_at' => now()]);
            } else {
                $this->occupancy->sync($positionId, $context->userId);
            }
        }
        $startedAt = DB::table('import_jobs')->where('id', $context->jobId)->value('started_at');
        $person = Person::query()->whereKey($employment->person_id)->first();
        if ($person instanceof Person && is_string($startedAt) && $person->created_at->gte(Carbon::parse($startedAt)->subMinute())
            && ! Employment::query()->where('person_id', $person->id)->exists()) {
            DB::table('person_documents')->where('person_id', $person->id)->update(['deleted_at' => now()]);
            $person->delete();
        }
        $this->audit->record('employment.import_reverted', 'employment', $entityId, $before, null, 'import:'.$context->jobId);
    }

    /**
     * @param  array<string, mixed>  $parsed
     */
    private function hire(array $parsed, ImportContext $context): Employment
    {
        $input = array_diff_key($parsed, array_flip(['document_type', 'document_number', 'manager_document']));
        if ($parsed['manager_document'] !== null) {
            $input['manager_employment_id'] = $context->lookups()->employment('dni', (string) $parsed['manager_document'], (string) $parsed['legal_entity_id']);
        }
        $input['manager_employment_id'] ??= null;
        if ($input['manager_employment_id'] === null) {
            unset($input['manager_employment_id']);
        }

        return $this->employments->hire($input, $context->userId, $context->can('people.employments.salary.edit'));
    }

    /**
     * @param  array<string, string>  $data
     */
    private function docType(array $data): string
    {
        $type = mb_strtolower(trim($data['document_type'] ?? ''));

        return in_array($type, IdentityDocuments::TYPES, true) ? $type : ($type === '' ? 'dni' : $type);
    }

    /** @return list<string> */
    private function referencingTables(): array
    {
        // Tablas base (sin vistas ni particiones) con employment_id, salvo lo que crea el ingreso y los registros de acceso.
        return $this->referencing ??= array_values(array_filter(
            array_map(fn ($r) => (string) $r->table_name, DB::select(
                "SELECT c.table_name FROM information_schema.columns c
                   JOIN information_schema.tables t ON t.table_schema = c.table_schema AND t.table_name = c.table_name AND t.table_type = 'BASE TABLE'
                  WHERE c.table_schema = 'public' AND c.column_name = 'employment_id'
                    AND NOT EXISTS (SELECT 1 FROM pg_inherits i JOIN pg_class k ON k.oid = i.inhrelid WHERE k.relname = c.table_name)"
            )),
            fn (string $t) => ! in_array($t, [...self::CREATED_BY_HIRE, ...self::IGNORED], true) && preg_match('/^[a-z0-9_]+$/', $t) === 1,
        ));
    }
}
