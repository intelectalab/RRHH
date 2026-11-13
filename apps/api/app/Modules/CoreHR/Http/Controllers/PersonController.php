<?php

namespace App\Modules\CoreHR\Http\Controllers;

use App\Modules\CoreHR\Application\IdentityDocuments;
use App\Modules\CoreHR\Application\PersonService;
use App\Modules\CoreHR\Infrastructure\Models\Person;
use App\Shared\Exceptions\InvalidFilterException;
use App\Shared\Permissions\DataScope;
use App\Shared\Permissions\EffectivePermissions;
use App\Shared\Permissions\SensitiveFields;
use App\Shared\Query\TableQuery;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * COL-1 · Personas: alta con deduplicación por documento (RN-COL-01), búsqueda por documento para
 * ofrecer reingreso o relación adicional, consulta y edición. Los datos personales (documento,
 * nacimiento, sexo, estado civil, correo y celular personales) solo viajan con
 * people.people.personal_data.view y se registran con people.people.personal_data.edit.
 */
final class PersonController
{
    private const SCHEMA = 'Person';

    public function __construct(
        private readonly PersonService $people,
        private readonly IdentityDocuments $documents,
        private readonly DataScope $scope,
        private readonly SensitiveFields $sensitive,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $permissions = $this->permissions($request);
        $this->sensitive->guardQuery(self::SCHEMA, $request, $permissions);
        $query = $this->visible($request, 'people.people.view');
        $q = trim((string) $request->query('q', ''));
        if ($q !== '') {
            $like = '%'.addcslashes($q, '%_\\').'%';
            $normalized = IdentityDocuments::normalize($q);
            $hashes = $normalized === '' ? [] : array_map(fn (string $t) => IdentityDocuments::hash($t, $normalized), IdentityDocuments::TYPES);
            $query->where(function ($w) use ($like, $hashes): void {
                $w->whereRaw("concat_ws(' ', first_names, preferred_name, paternal_surname, maternal_surname) ilike ?", [$like]);
                if ($hashes !== []) {
                    $w->orWhereIn('id', DB::table('person_documents')->whereIn('number_hash', $hashes)->whereNull('deleted_at')->select('person_id'));
                }
            });
        }
        $page = (new TableQuery(['paternal_surname' => 'paternal_surname', 'first_names' => 'first_names', 'created_at' => 'created_at'], []))
            ->page($query, $request, 'paternal_surname');
        $models = Person::query()->whereIn('id', $page['ids'])->get()->keyBy('id');
        $ordered = [];
        foreach ($page['ids'] as $id) {
            $p = $models->get($id);
            if ($p instanceof Person) {
                $ordered[] = $p;
            }
        }

        return response()->json([
            'data' => $this->sensitive->presentMany(self::SCHEMA, $this->people->presentMany($ordered), $permissions, 'api', array_map(fn (Person $p) => $p->id, $ordered)),
            'meta' => $page['meta'],
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return $this->respond($request, $this->find($request, $id, 'people.people.view'));
    }

    public function store(Request $request): JsonResponse
    {
        $input = $request->validate($this->rules(true));
        $this->guardPersonalData($request, $input);

        return $this->respond($request, $this->people->create($input), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $person = $this->find($request, $id, 'people.people.update');
        $input = $request->validate($this->rules(false));
        if (array_key_exists('document_number', $input) || array_key_exists('document_type', $input)) {
            // El documento se corrige conservando la historia de documentos (cambio de documento, COL-4).
            throw ValidationException::withMessages(['document_number' => [(string) trans('people.document_not_editable')]]);
        }
        $this->guardPersonalData($request, $input);
        $ifMatch = trim((string) $request->header('If-Match', ''), ' "W/');

        return $this->respond($request, $this->people->update($person, $input, $ifMatch === '' ? null : (int) $ifMatch));
    }

    /**
     * RN-COL-01 · ¿Ya existe una persona con este documento? Para no duplicarla: la respuesta permite
     * ofrecer reingreso o relación adicional. No devuelve datos personales.
     */
    public function documentMatch(Request $request): JsonResponse
    {
        $type = $request->query('document_type');
        $number = $request->query('document_number');
        $country = $request->query('document_country', 'PE');
        if (! is_string($type) || ! in_array($type, IdentityDocuments::TYPES, true) || ! is_string($number) || trim($number) === ''
            || ! is_string($country) || preg_match('/^[A-Za-z]{2}$/', $country) !== 1) {
            throw new InvalidFilterException((string) trans('errors.invalid_filter'), ['document_type', 'document_number', 'document_country']);
        }
        $normalized = $this->documents->assertValid($type, $number, strtoupper($country));
        $person = $this->people->findByDocument($type, $normalized, $country);

        return response()->json(['data' => [
            'exists' => $person !== null,
            'document_number_masked' => IdentityDocuments::mask($normalized),
            'person' => $person === null ? null : [
                'id' => $person->id,
                'full_name' => $person->fullName(),
                'employments' => $this->people->employmentSummary($person),
            ],
        ]]);
    }

    /**
     * Personas visibles: con alcance de organización, todas; si no, las que tienen una relación laboral
     * dentro del alcance del usuario (regla 6: el alcance se aplica en la consulta).
     *
     * @return Builder<Person>
     */
    private function visible(Request $request, string $permission): Builder
    {
        $query = Person::query()->whereNull('anonymized_at');
        $user = $request->user();
        $scope = $user instanceof Authenticatable ? $this->scope->employmentScope((string) $user->getAuthIdentifier(), $permission) : ['SELECT NULL::uuid WHERE false', []];
        if ($scope !== null) {
            [$sql, $bindings] = $scope;
            $query->whereRaw("id IN (SELECT person_id FROM employments WHERE id IN ({$sql}))", $bindings);
        }

        return $query;
    }

    private function find(Request $request, string $id, string $permission): Person
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $id) === 1, 404);
        $person = $this->visible($request, $permission)->whereKey($id)->first();
        abort_unless($person instanceof Person, 404);

        return $person;
    }

    /**
     * Lo que la interfaz oculta, el backend lo valida: sin people.people.personal_data.edit no se registran
     * datos personales sensibles.
     *
     * @param  array<string, mixed>  $input
     */
    private function guardPersonalData(Request $request, array $input): void
    {
        if ($this->permissions($request)->has('people.people.personal_data.edit')) {
            return;
        }
        $sent = array_intersect(array_keys($input), PersonService::PERSONAL_FIELDS);
        if ($sent !== []) {
            throw ValidationException::withMessages(array_fill_keys(array_values($sent), [(string) trans('people.personal_data_forbidden')]));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return [
            'first_names' => [$req, 'string', 'min:1', 'max:100'],
            'paternal_surname' => [$req, 'string', 'min:1', 'max:100'],
            'maternal_surname' => ['sometimes', 'nullable', 'string', 'max:100'],
            'preferred_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'document_type' => [$req, Rule::in(IdentityDocuments::TYPES)],
            'document_number' => [$req, 'string', 'max:30'],
            'document_country' => ['sometimes', 'nullable', 'string', 'size:2', Rule::exists('countries', 'code')],
            'nationality_code' => ['sometimes', 'nullable', 'string', 'size:2', Rule::exists('countries', 'code')],
            'birth_date' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'before:today'],
            'sex' => ['sometimes', 'nullable', Rule::in(['female', 'male', 'unspecified'])],
            'marital_status' => ['sometimes', 'nullable', Rule::in(['single', 'married', 'cohabiting', 'divorced', 'widowed'])],
            'personal_email' => ['sometimes', 'nullable', 'email:rfc', 'max:150'],
            'mobile_phone' => ['sometimes', 'nullable', 'string', 'max:30'],
        ];
    }

    private function permissions(Request $request): EffectivePermissions
    {
        $p = $request->attributes->get('effective_permissions');

        return $p instanceof EffectivePermissions ? $p : new EffectivePermissions([], [], []);
    }

    private function respond(Request $request, Person $person, int $status = 200): JsonResponse
    {
        $data = $this->sensitive->present(self::SCHEMA, $this->people->present($person), $this->permissions($request), 'api', $person->id);

        return response()->json(['data' => $data], $status)->header('ETag', '"'.$person->lock_version.'"');
    }
}
