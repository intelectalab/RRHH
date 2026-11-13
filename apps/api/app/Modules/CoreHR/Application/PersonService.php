<?php

namespace App\Modules\CoreHR\Application;

use App\Modules\CoreHR\Infrastructure\Models\Employment;
use App\Modules\CoreHR\Infrastructure\Models\Person;
use App\Modules\CoreHR\Infrastructure\Models\PersonDocument;
use App\Shared\Audit\AuditLogger;
use App\Shared\Exceptions\AyniException;
use App\Shared\Security\FieldCipher;
use App\Shared\Settings\ParameterResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * COL-1 · Personas y deduplicación (RN-COL-01).
 *
 * Una persona existe una sola vez por organización: el documento (tipo, país y huella del número) es
 * único. Crear una persona con un documento ya registrado responde 409 person_exists con la persona
 * existente, para que la interfaz ofrezca usarla (reingreso o relación adicional).
 */
final class PersonService
{
    /** Datos personales que solo se registran con people.people.personal_data.edit. */
    public const PERSONAL_FIELDS = ['birth_date', 'sex', 'marital_status', 'personal_email', 'mobile_phone'];

    public function __construct(
        private readonly IdentityDocuments $documents,
        private readonly AuditLogger $audit,
        private readonly ParameterResolver $parameters,
    ) {}

    public function findByDocument(string $type, string $number, string $country = 'PE'): ?Person
    {
        $normalized = IdentityDocuments::normalize($number);
        if ($normalized === '') {
            return null;
        }
        $personId = PersonDocument::query()
            ->where('doc_type', $type)
            ->where('issuing_country', strtoupper($country))
            ->where('number_hash', IdentityDocuments::hash($type, $normalized))
            ->value('person_id');

        /** @var Person|null */
        return $personId === null ? null : Person::query()->find($personId);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function create(array $input): Person
    {
        $type = (string) $input['document_type'];
        $country = strtoupper((string) ($input['document_country'] ?? 'PE'));
        $number = $this->documents->assertValid($type, (string) $input['document_number'], $country);
        $this->assertSexAllowed($input);

        return DB::transaction(function () use ($input, $type, $country, $number): Person {
            // Dos altas simultáneas con el mismo documento: la segunda espera y encuentra a la primera.
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['person-doc:'.IdentityDocuments::hash($type, $number)]);
            $existing = $this->findByDocument($type, $number, $country);
            if ($existing !== null) {
                throw $this->exists($existing);
            }

            $person = Person::create($this->attributes($input));
            PersonDocument::create([
                'person_id' => $person->id, 'doc_type' => $type, 'issuing_country' => $country, 'is_primary' => true,
                'number_enc' => FieldCipher::encrypt($number), 'number_hash' => IdentityDocuments::hash($type, $number),
            ]);
            $this->audit->record('person.created', 'person', $person->id, null, $this->auditable($person, $type, $number));

            return $person->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function update(Person $person, array $input, ?int $ifMatch): Person
    {
        if ($ifMatch !== null && $ifMatch !== $person->lock_version) {
            throw new AyniException('conflict', 409, (string) trans('people.version_conflict'));
        }
        $this->assertSexAllowed($input);
        $doc = $this->primaryDocument($person);
        $before = $this->auditable($person, $doc?->doc_type, $doc === null ? null : FieldCipher::decrypt($doc->number_enc));

        return DB::transaction(function () use ($person, $input, $before): Person {
            $attributes = $this->attributes($input, partial: true);
            if ($attributes !== []) {
                $person->fill($attributes);
                $person->lock_version++;
                $person->save();
            }
            $doc = $this->primaryDocument($person);
            $this->audit->record('person.updated', 'person', $person->id, $before,
                $this->auditable($person, $doc?->doc_type, $doc === null ? null : FieldCipher::decrypt($doc->number_enc)));

            return $person->refresh();
        });
    }

    public function exists(Person $person): AyniException
    {
        return new AyniException('person_exists', 409, (string) trans('people.person_exists'), [], [
            'person_id' => $person->id,
            'full_name' => $person->fullName(),
            'employments' => $this->employmentSummary($person),
        ]);
    }

    public function primaryDocument(Person $person): ?PersonDocument
    {
        /** @var PersonDocument|null */
        return PersonDocument::query()->where('person_id', $person->id)->orderByDesc('is_primary')->orderBy('created_at')->first();
    }

    /**
     * @return array<string, mixed>
     */
    public function present(Person $person): array
    {
        return $this->presentMany([$person])[0];
    }

    /**
     * @param  array<int, Person>  $people
     * @return array<int, array<string, mixed>>
     */
    public function presentMany(array $people): array
    {
        $ids = array_map(fn (Person $p) => $p->id, $people);
        $docs = PersonDocument::query()->whereIn('person_id', $ids)->orderByDesc('is_primary')->orderBy('created_at')->get()->groupBy('person_id');
        $employments = $this->summaries($ids);

        return array_map(function (Person $p) use ($docs, $employments): array {
            /** @var PersonDocument|null $doc */
            $doc = $docs->get($p->id)?->first();
            $number = $doc === null ? null : FieldCipher::decrypt($doc->number_enc);

            $row = [
                'id' => $p->id,
                'first_names' => $p->first_names,
                'paternal_surname' => $p->paternal_surname,
                'maternal_surname' => $p->maternal_surname,
                'preferred_name' => $p->preferred_name,
                'full_name' => $p->fullName(),
                'document_type' => $doc === null ? 'other' : $doc->doc_type,
                'document_country' => $doc?->issuing_country,
                'document_number' => $number,
                'document_number_masked' => $number === null ? null : IdentityDocuments::mask($number),
                'birth_date' => $p->birth_date?->toDateString(),
                'sex' => $p->sex,
                'nationality_code' => $p->nationality_code,
                'marital_status' => $p->marital_status,
                'personal_email' => $p->personal_email,
                'mobile_phone' => $p->mobile_phone,
                'employments' => $employments[$p->id] ?? [],
                'version' => $p->lock_version,
                'created_at' => $p->created_at->toIso8601String(),
                'updated_at' => $p->updated_at->toIso8601String(),
            ];
            // Un dato personal vacío no viaja: así no se registra la lectura de algo que no existe.
            foreach (['document_number', ...self::PERSONAL_FIELDS] as $field) {
                if (array_key_exists($field, $row) && $row[$field] === null) {
                    unset($row[$field]);
                }
            }

            return $row;
        }, $people);
    }

    /**
     * Relaciones de la persona (sin datos sensibles) para ofrecer reingreso o relación adicional.
     *
     * @return array<int, array<string, mixed>>
     */
    public function employmentSummary(Person $person): array
    {
        return $this->summaries([$person->id])[$person->id] ?? [];
    }

    /**
     * @param  array<int, string>  $personIds
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function summaries(array $personIds): array
    {
        if ($personIds === []) {
            return [];
        }
        $rows = Employment::query()->toBase()
            ->join('legal_entities as le', 'le.id', '=', 'employments.legal_entity_id')
            ->whereIn('employments.person_id', $personIds)
            ->whereNull('employments.deleted_at')
            ->orderByDesc('employments.hire_date')
            ->get(['employments.id', 'employments.person_id', 'employments.legal_entity_id', 'le.legal_name', 'employments.employee_code',
                'employments.hire_date', 'employments.termination_date', 'employments.status']);
        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r->person_id][] = [
                'id' => (string) $r->id, 'legal_entity_id' => (string) $r->legal_entity_id, 'legal_entity_name' => (string) $r->legal_name,
                'employee_code' => (string) $r->employee_code, 'hire_date' => (string) $r->hire_date,
                'termination_date' => $r->termination_date === null ? null : (string) $r->termination_date, 'status' => (string) $r->status,
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function attributes(array $input, bool $partial = false): array
    {
        $fields = ['first_names', 'paternal_surname', 'maternal_surname', 'preferred_name', 'nationality_code', ...self::PERSONAL_FIELDS];
        $out = [];
        foreach ($fields as $f) {
            if (array_key_exists($f, $input)) {
                $value = $input[$f];
                $out[$f] = is_string($value) ? (trim($value) === '' ? null : trim($value)) : $value;
            } elseif (! $partial && in_array($f, ['maternal_surname', 'preferred_name'], true)) {
                $out[$f] = null;
            }
        }
        if (isset($out['personal_email']) && is_string($out['personal_email'])) {
            $out['personal_email'] = mb_strtolower($out['personal_email']);
        }
        if (isset($out['nationality_code']) && is_string($out['nationality_code'])) {
            $out['nationality_code'] = strtoupper($out['nationality_code']);
        }

        return $out;
    }

    /**
     * RN-COL-08 · El sexo solo se registra si la organización activó el campo (colaboradores.registrar_sexo).
     *
     * @param  array<string, mixed>  $input
     */
    private function assertSexAllowed(array $input): void
    {
        if (array_key_exists('sex', $input) && $input['sex'] !== null && $this->parameters->value('colaboradores.registrar_sexo') === false) {
            throw ValidationException::withMessages(['sex' => [(string) trans('people.sex_disabled')]]);
        }
    }

    /**
     * Foto para la auditoría: los datos sensibles se cifran por el mapa de campos de Person.
     *
     * @return array<string, mixed>
     */
    private function auditable(Person $p, ?string $docType, ?string $number): array
    {
        return [
            'first_names' => $p->first_names, 'paternal_surname' => $p->paternal_surname, 'maternal_surname' => $p->maternal_surname,
            'preferred_name' => $p->preferred_name, 'nationality_code' => $p->nationality_code,
            'document_type' => $docType, 'document_number' => $number,
            'birth_date' => $p->birth_date?->toDateString(), 'sex' => $p->sex, 'marital_status' => $p->marital_status,
            'personal_email' => $p->personal_email, 'mobile_phone' => $p->mobile_phone,
        ];
    }
}
