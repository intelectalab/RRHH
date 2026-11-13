<?php

namespace App\Modules\CoreHR\Http\Controllers;

use App\Modules\CoreHR\Application\DependentService;
use App\Modules\CoreHR\Application\IdentityDocuments;
use App\Modules\CoreHR\Http\Controllers\Concerns\ResolvesEmployment;
use App\Modules\CoreHR\Infrastructure\Models\Dependent;
use App\Modules\CoreHR\Infrastructure\Models\Employment;
use App\Shared\Permissions\DataScope;
use App\Shared\Permissions\SensitiveFields;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * COL-6 · Derechohabientes de la persona, vistos desde una de sus relaciones laborales (el alcance del
 * usuario se aplica a la relación). Son datos de terceros: el recurso exige people.dependents.*; el
 * documento y la fecha de nacimiento viajan solo con people.people.personal_data.view y la discapacidad
 * con people.people.health_data.view.
 */
final class DependentController
{
    use ResolvesEmployment;

    private const SCHEMA = 'Dependent';

    public function __construct(
        private readonly DependentService $dependents,
        private readonly DataScope $scope,
        private readonly SensitiveFields $sensitive,
    ) {}

    public function index(Request $request, string $employment_id): JsonResponse
    {
        $employment = $this->employment($request, $employment_id, 'people.dependents.view');
        $rows = $this->dependents->forPerson($employment->person_id);

        return response()->json([
            'data' => $this->sensitive->presentMany(self::SCHEMA, array_map(fn (Dependent $d) => $this->dependents->present($d), $rows),
                $this->permissions($request), 'api', array_map(fn () => $employment->person_id, $rows)),
            'meta' => ['total' => count($rows), 'person_id' => $employment->person_id],
        ]);
    }

    public function show(Request $request, string $employment_id, string $id): JsonResponse
    {
        [$employment, $dependent] = $this->find($request, $employment_id, $id, 'people.dependents.view');

        return $this->respond($request, $employment, $dependent);
    }

    public function store(Request $request, string $employment_id): JsonResponse
    {
        $employment = $this->employment($request, $employment_id, 'people.dependents.create');
        $input = $request->validate($this->rules(true));
        $this->guardSensitive($request, $input);

        return $this->respond($request, $employment, $this->dependents->create($employment, $input), 201);
    }

    public function update(Request $request, string $employment_id, string $id): JsonResponse
    {
        [$employment, $dependent] = $this->find($request, $employment_id, $id, 'people.dependents.update');
        $input = $request->validate($this->rules(false));
        if (array_key_exists('relationship', $input) && $input['relationship'] !== $dependent->relationship) {
            // El vínculo no se corrige en sitio: se da de baja y se registra otra vez.
            throw ValidationException::withMessages(['relationship' => [(string) trans('people.dependents.relationship_locked')]]);
        }
        $this->guardSensitive($request, $input);
        $ifMatch = trim((string) $request->header('If-Match', ''), ' "W/');

        return $this->respond($request, $employment, $this->dependents->update($dependent, $input, $ifMatch === '' ? null : (int) $ifMatch));
    }

    public function destroy(Request $request, string $employment_id, string $id): Response
    {
        [, $dependent] = $this->find($request, $employment_id, $id, 'people.dependents.delete');
        $this->dependents->delete($dependent);

        return response()->noContent();
    }

    public function deregister(Request $request, string $employment_id, string $id): JsonResponse
    {
        [$employment, $dependent] = $this->find($request, $employment_id, $id, 'people.dependents.update');
        $input = $request->validate([
            'deregistered_on' => ['required', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        return $this->respond($request, $employment, $this->dependents->deregister($dependent, (string) $input['deregistered_on'], (string) $input['reason']));
    }

    public function uploadProof(Request $request, string $employment_id, string $id): JsonResponse
    {
        [$employment, $dependent] = $this->find($request, $employment_id, $id, 'people.dependents.update');
        $request->validate(['file' => ['required', 'file', 'max:'.(int) (config('files.max_size_bytes', 26214400) / 1024)]]);
        $file = $request->file('file');
        abort_unless($file instanceof UploadedFile, 422);

        return $this->respond($request, $employment, $this->dependents->attachProof(
            $dependent, (string) $file->getContent(), $file->getClientOriginalName(), (string) $file->getMimeType(), $this->userId($request),
        ));
    }

    public function proof(Request $request, string $employment_id, string $id): Response
    {
        [, $dependent] = $this->find($request, $employment_id, $id, 'people.dependents.view');
        $file = $this->dependents->proof($dependent, $this->userId($request));
        abort_if($file === null, 404);

        return response($file['contents'], 200, [
            'Content-Type' => $file['mime'],
            'Content-Disposition' => 'attachment; filename="'.addslashes($file['name']).'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * @return array{0: Employment, 1: Dependent}
     */
    private function find(Request $request, string $employmentId, string $id, string $permission): array
    {
        $employment = $this->employment($request, $employmentId, $permission);
        self::uuidOr404($id);
        $dependent = Dependent::query()->whereKey($id)->where('person_id', $employment->person_id)->first();
        abort_unless($dependent instanceof Dependent, 404);

        return [$employment, $dependent];
    }

    /**
     * Lo que la interfaz oculta, el backend lo valida: sin el permiso de edición del campo no se registra el
     * documento ni la fecha de nacimiento (datos personales); la discapacidad (salud) exige además ver datos de salud.
     *
     * @param  array<string, mixed>  $input
     */
    private function guardSensitive(Request $request, array $input): void
    {
        $permissions = $this->permissions($request);
        $errors = [];
        if (! $permissions->has('people.people.personal_data.edit')) {
            foreach (array_intersect(array_keys($input), ['document_number', 'birth_date']) as $field) {
                $errors[$field] = [(string) trans('people.personal_data_forbidden')];
            }
        }
        if (array_key_exists('has_disability', $input) && ! ($permissions->has('people.people.health_data.view') && $permissions->has('people.people.personal_data.edit'))) {
            $errors['has_disability'] = [(string) trans('people.dependents.health_forbidden')];
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return [
            'full_name' => [$req, 'string', 'min:3', 'max:200'],
            'relationship' => [$req, Rule::in(DependentService::RELATIONSHIPS)],
            'document_type' => ['sometimes', 'nullable', Rule::in(IdentityDocuments::TYPES)],
            'document_number' => ['sometimes', 'nullable', 'string', 'max:30'],
            'birth_date' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'is_student' => ['sometimes', 'boolean'],
            'has_disability' => ['sometimes', 'nullable', 'boolean'],
            'registered_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ];
    }

    private function respond(Request $request, Employment $employment, Dependent $dependent, int $status = 200): JsonResponse
    {
        $data = $this->sensitive->present(self::SCHEMA, $this->dependents->present($dependent), $this->permissions($request), 'api', $employment->person_id);

        return response()->json(['data' => $data], $status)->header('ETag', '"'.$dependent->lock_version.'"');
    }
}
