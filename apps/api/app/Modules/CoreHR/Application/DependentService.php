<?php

namespace App\Modules\CoreHR\Application;

use App\Modules\CoreHR\Infrastructure\Models\Dependent;
use App\Modules\CoreHR\Infrastructure\Models\Employment;
use App\Shared\Audit\AuditLogger;
use App\Shared\Exceptions\AyniException;
use App\Shared\Exceptions\InvalidTransitionException;
use App\Shared\Files\FileService;
use App\Shared\Files\Models\StoredFile;
use App\Shared\LegalParameters\LegalParameters;
use App\Shared\Outbox\Contracts\OutboxWriter;
use App\Shared\Outbox\DomainEventTypes;
use App\Shared\Security\FieldCipher;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * COL-6 · Derechohabientes (E-19).
 *
 * - Pertenecen a la persona: se ven desde cualquiera de sus relaciones laborales.
 * - RN-COL-07: un hijo exige fecha de nacimiento y sustento; solo con sustento cuenta para la
 *   asignación familiar (que calcula M-BSO).
 * - Una baja no borra: deja fecha y motivo. Eliminar (papelera) es solo para corregir un registro errado.
 * - Al cumplir un hijo la mayoría de edad (parámetro legal mayoria_edad_anios) se avisa a RRHH para revisarlo.
 */
final class DependentService
{
    public const RELATIONSHIPS = ['spouse', 'partner', 'pregnant_partner', 'child', 'disabled_adult_child', 'parent', 'other'];

    /** Vínculos que exigen fecha de nacimiento y sustento (RN-COL-07) y pueden dar asignación familiar. */
    public const CHILDREN = ['child', 'disabled_adult_child'];

    public const PROOF_MIMES = ['application/pdf', 'image/jpeg', 'image/png'];

    public function __construct(
        private readonly IdentityDocuments $documents,
        private readonly FileService $files,
        private readonly LegalParameters $legal,
        private readonly OutboxWriter $outbox,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return list<Dependent>
     */
    public function forPerson(string $personId, bool $includeDeregistered = true): array
    {
        $query = Dependent::query()->where('person_id', $personId)->orderBy('registry_status')->orderBy('full_name');
        if (! $includeDeregistered) {
            $query->where('registry_status', '!=', 'deregistered');
        }

        return array_values($query->get()->all());
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function create(Employment $employment, array $input): Dependent
    {
        $values = $this->values($input, null);
        $relationship = (string) $input['relationship'];
        $this->assertChildData($relationship, $values['birth_date'] ?? null);

        return DB::transaction(function () use ($employment, $values, $relationship): Dependent {
            $this->assertNotDuplicated($employment->person_id, $values['number_hash'] ?? null, null);
            $dependent = Dependent::create($values + [
                'person_id' => $employment->person_id,
                'relationship' => $relationship,
                'registered_on' => $values['registered_on'] ?? now()->toDateString(),
                'registry_status' => 'pending',
            ]);
            $this->audit->record('dependent.created', 'dependent', $dependent->id, null, $this->auditable($dependent));

            return $dependent->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function update(Dependent $dependent, array $input, ?int $ifMatch): Dependent
    {
        if ($ifMatch !== null && $ifMatch !== $dependent->lock_version) {
            throw new AyniException('conflict', 409, (string) trans('people.version_conflict'));
        }
        if ($dependent->registry_status === 'deregistered') {
            throw new InvalidTransitionException('deregistered', [], (string) trans('people.dependents.deregistered_locked'));
        }
        $values = $this->values($input, $dependent);
        $birth = array_key_exists('birth_date', $values) ? $values['birth_date'] : $dependent->birth_date?->toDateString();
        $this->assertChildData($dependent->relationship, $birth);
        $before = $this->auditable($dependent);

        return DB::transaction(function () use ($dependent, $values, $before): Dependent {
            if (array_key_exists('number_hash', $values)) {
                $this->assertNotDuplicated($dependent->person_id, $values['number_hash'], $dependent->id);
            }
            $dependent->fill($values);
            $dependent->entitles_family_allowance = $this->entitles($dependent);
            $dependent->lock_version++;
            $dependent->save();
            $this->audit->record('dependent.updated', 'dependent', $dependent->id, $before, $this->auditable($dependent));

            return $dependent->refresh();
        });
    }

    /**
     * Baja del derechohabiente (registro laboral y seguros): queda con fecha y motivo, nunca se borra.
     */
    public function deregister(Dependent $dependent, string $on, string $reason): Dependent
    {
        if ($dependent->registry_status === 'deregistered') {
            throw new InvalidTransitionException('deregistered', []);
        }
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => [(string) trans('people.reason_required')]]);
        }
        if ($dependent->registered_on !== null && $on < $dependent->registered_on->toDateString()) {
            throw ValidationException::withMessages(['deregistered_on' => [(string) trans('people.dependents.deregistered_before_registered')]]);
        }
        $before = $this->auditable($dependent);

        return DB::transaction(function () use ($dependent, $on, $reason, $before): Dependent {
            $dependent->forceFill([
                'registry_status' => 'deregistered', 'deregistered_on' => $on, 'deregistration_reason' => trim($reason),
                'entitles_family_allowance' => false,
            ]);
            $dependent->lock_version++;
            $dependent->save();
            $this->audit->record('dependent.deregistered', 'dependent', $dependent->id, $before, $this->auditable($dependent), trim($reason));

            return $dependent->refresh();
        });
    }

    /** Corrige un registro errado: pasa a la papelera con auditoría (no es la baja). */
    public function delete(Dependent $dependent): void
    {
        DB::transaction(function () use ($dependent): void {
            $this->audit->record('dependent.deleted', 'dependent', $dependent->id, $this->auditable($dependent), null);
            $dependent->delete();
        });
    }

    /**
     * Documento de sustento (partida de nacimiento, acta de matrimonio, constancia). Se guarda confidencial.
     */
    public function attachProof(Dependent $dependent, string $contents, string $name, string $mime, ?string $userId): Dependent
    {
        if (! in_array($mime, self::PROOF_MIMES, true)) {
            throw ValidationException::withMessages(['file' => [(string) trans('people.dependents.proof_type')]]);
        }
        if ($dependent->registry_status === 'deregistered') {
            throw new InvalidTransitionException('deregistered', [], (string) trans('people.dependents.deregistered_locked'));
        }

        return DB::transaction(function () use ($dependent, $contents, $name, $mime, $userId): Dependent {
            $before = $dependent->proof_file_id;
            $file = $this->files->upload($contents, $name, $mime, $dependent->tenant_id, $userId, StoredFile::CLASSIFICATION_CONFIDENTIAL);
            $dependent->proof_file_id = $file->id;
            $dependent->entitles_family_allowance = $this->entitles($dependent);
            $dependent->lock_version++;
            $dependent->save();
            $this->audit->record('dependent.proof_attached', 'dependent', $dependent->id, ['proof_file_id' => $before], ['proof_file_id' => $file->id]);

            return $dependent->refresh();
        });
    }

    /**
     * @return array{name: string, mime: string, contents: string}|null
     */
    public function proof(Dependent $dependent, ?string $userId): ?array
    {
        return $dependent->proof_file_id === null ? null : $this->files->contentsForDownload($dependent->proof_file_id, $dependent->tenant_id, $userId);
    }

    /**
     * Hijos que cumplieron la mayoría de edad y aún no se revisaron: se marca la fecha de la alerta y se avisa
     * a RRHH por el outbox. Idempotente: un hijo se alerta una sola vez.
     */
    public function reviewMajority(?string $today = null): int
    {
        $day = CarbonImmutable::parse($today ?? now()->toDateString());
        $param = $this->legal->at('PE', 'mayoria_edad_anios', $day);
        $years = is_numeric($param['value'] ?? null) ? (int) $param['value'] : null;
        if ($years === null || $years <= 0) {
            return 0; // Sin el parámetro cargado no se adivina la edad.
        }
        $limit = $day->subYears($years)->toDateString();
        $count = 0;
        Dependent::query()->where('relationship', 'child')->where('registry_status', '!=', 'deregistered')
            ->whereNull('majority_alerted_on')->whereNotNull('birth_date')->where('birth_date', '<=', $limit)
            ->orderBy('id')->each(function (Dependent $dependent) use ($day, &$count): void {
                DB::transaction(function () use ($dependent, $day): void {
                    $dependent->forceFill(['majority_alerted_on' => $day->toDateString()])->save();
                    $employmentId = Employment::query()->where('person_id', $dependent->person_id)
                        ->whereIn('status', ['active', 'pending', 'suspended'])->orderByDesc('hire_date')->value('id');
                    $this->outbox->record(DomainEventTypes::DEPENDENT_REACHED_MAJORITY, 'dependent', $dependent->id, [
                        'dependent_id' => $dependent->id, 'person_id' => $dependent->person_id,
                        'employment_id' => $employmentId === null ? null : (string) $employmentId,
                        'reached_on' => $day->toDateString(),
                    ]);
                });
                $count++;
            });

        return $count;
    }

    /**
     * @return array<string, mixed>
     */
    public function present(Dependent $d): array
    {
        $number = $d->number_enc === null ? null : FieldCipher::decrypt($d->number_enc);
        $row = [
            'id' => $d->id,
            'person_id' => $d->person_id,
            'full_name' => $d->full_name,
            'relationship' => $d->relationship,
            'document_type' => $d->doc_type,
            'document_number' => $number,
            'document_number_masked' => $number === null ? null : IdentityDocuments::mask($number),
            'birth_date' => $d->birth_date?->toDateString(),
            'is_student' => $d->is_student,
            'has_disability' => $d->disability_enc === null ? null : FieldCipher::decrypt($d->disability_enc) === '1',
            'registered_on' => $d->registered_on?->toDateString(),
            'deregistered_on' => $d->deregistered_on?->toDateString(),
            'deregistration_reason' => $d->deregistration_reason,
            'registry_status' => $d->registry_status,
            'entitles_family_allowance' => $d->entitles_family_allowance,
            'has_proof' => $d->proof_file_id !== null,
            'missing_proof' => in_array($d->relationship, self::CHILDREN, true) && $d->proof_file_id === null,
            'majority_alerted_on' => $d->majority_alerted_on?->toDateString(),
            'version' => $d->lock_version,
            'created_at' => $d->created_at->toIso8601String(),
            'updated_at' => $d->updated_at->toIso8601String(),
        ];
        // Un dato sensible vacío no viaja: no se registra la lectura de algo que no existe.
        foreach (['document_number', 'birth_date', 'has_disability'] as $field) {
            if (($row[$field] ?? null) === null) {
                unset($row[$field]);
            }
        }

        return $row;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function values(array $input, ?Dependent $current): array
    {
        $out = [];
        if (array_key_exists('full_name', $input)) {
            $out['full_name'] = trim((string) $input['full_name']);
        }
        foreach (['birth_date', 'registered_on'] as $f) {
            if (array_key_exists($f, $input)) {
                $out[$f] = $input[$f] === null || $input[$f] === '' ? null : (string) $input[$f];
            }
        }
        if (array_key_exists('is_student', $input)) {
            $out['is_student'] = (bool) $input['is_student'];
        }
        if (array_key_exists('has_disability', $input)) {
            $out['disability_enc'] = $input['has_disability'] === null ? null : FieldCipher::encrypt($input['has_disability'] ? '1' : '0');
        }
        $type = $input['document_type'] ?? $current?->doc_type;
        if (array_key_exists('document_number', $input)) {
            $number = $input['document_number'];
            if ($number === null || trim((string) $number) === '') {
                $out += ['doc_type' => null, 'number_enc' => null, 'number_hash' => null];
            } else {
                if (! is_string($type) || $type === '') {
                    throw ValidationException::withMessages(['document_type' => [(string) trans('people.document_type_invalid')]]);
                }
                $normalized = $this->documents->assertValid($type, (string) $number);
                $out += ['doc_type' => $type, 'number_enc' => FieldCipher::encrypt($normalized), 'number_hash' => IdentityDocuments::hash($type, $normalized)];
            }
        }

        return $out;
    }

    /** RN-COL-07 · Un hijo exige fecha de nacimiento. */
    private function assertChildData(string $relationship, mixed $birthDate): void
    {
        if (in_array($relationship, self::CHILDREN, true) && ($birthDate === null || $birthDate === '')) {
            throw ValidationException::withMessages(['birth_date' => [(string) trans('people.dependents.child_birth_date')]]);
        }
    }

    private function assertNotDuplicated(string $personId, ?string $hash, ?string $exceptId): void
    {
        if ($hash === null) {
            return;
        }
        $query = Dependent::query()->where('person_id', $personId)->where('number_hash', $hash);
        if ($exceptId !== null) {
            $query->whereKeyNot($exceptId);
        }
        if ($query->exists()) {
            throw ValidationException::withMessages(['document_number' => [(string) trans('people.dependents.duplicated')]]);
        }
    }

    /** Solo un hijo con sustento cuenta para la asignación familiar (RN-COL-07); el cálculo es de M-BSO. */
    private function entitles(Dependent $d): bool
    {
        return in_array($d->relationship, self::CHILDREN, true) && $d->proof_file_id !== null && $d->registry_status !== 'deregistered';
    }

    /**
     * Foto para la auditoría: el documento y la fecha de nacimiento se cifran por el mapa de campos de Dependent.
     *
     * @return array<string, mixed>
     */
    private function auditable(Dependent $d): array
    {
        return [
            'full_name' => $d->full_name, 'relationship' => $d->relationship, 'document_type' => $d->doc_type,
            'document_number' => $d->number_enc === null ? null : FieldCipher::decrypt($d->number_enc),
            'birth_date' => $d->birth_date?->toDateString(), 'is_student' => $d->is_student,
            'registered_on' => $d->registered_on?->toDateString(), 'deregistered_on' => $d->deregistered_on?->toDateString(),
            'registry_status' => $d->registry_status, 'proof_file_id' => $d->proof_file_id,
        ];
    }
}
