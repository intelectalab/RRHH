<?php

namespace App\Modules\CoreHR\Http\Controllers;

use App\Modules\CoreHR\Application\ProfileChangeRequests;
use App\Modules\CoreHR\Http\Controllers\Concerns\ScopedAccess;
use App\Modules\CoreHR\Infrastructure\Models\Person;
use App\Modules\CoreHR\Infrastructure\Models\ProfileChangeRequest;
use App\Shared\Exceptions\AyniException;
use App\Shared\Exceptions\InvalidFilterException;
use App\Shared\Permissions\DataScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * COL-4 · RN-COL-06 · Solicitudes de cambio de datos personales del propio colaborador.
 *
 * Solo la persona dueña de los datos propone cambios (users.person_id); RRHH edita directamente desde la
 * ficha. Cada quien ve sus propias solicitudes y, con people.change_requests.view, las de las personas de
 * su alcance. Aprobar y rechazar son acciones de RRHH (409 si la solicitud ya no está pendiente).
 */
final class ProfileChangeRequestController
{
    use ScopedAccess;

    private const STATUSES = ['pending', 'approved', 'rejected', 'cancelled'];

    public function __construct(
        private readonly ProfileChangeRequests $requests,
        private readonly DataScope $scope,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = $this->visible($request, 'people.change_requests.view');
        $status = $request->query('status');
        if ($status !== null && $status !== '') {
            $list = is_string($status) ? explode(',', $status) : [];
            if ($list === [] || array_diff($list, self::STATUSES) !== []) {
                throw new InvalidFilterException((string) trans('errors.invalid_filter'), ['status']);
            }
            $query->whereIn('status', $list);
        }
        $personId = $request->query('person_id');
        if ($personId !== null && $personId !== '') {
            if (! is_string($personId) || preg_match('/^[0-9a-f-]{36}$/i', $personId) !== 1) {
                throw new InvalidFilterException((string) trans('errors.invalid_filter'), ['person_id']);
            }
            $query->where('person_id', $personId);
        }
        $rows = $query->orderByDesc('created_at')->limit(200)->get()->all();

        return response()->json([
            'data' => $this->requests->presentMany($rows),
            'meta' => ['page' => 1, 'per_page' => 200, 'total' => count($rows), 'next_cursor' => null],
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return $this->respond($this->find($request, $id, 'people.change_requests.view'));
    }

    public function store(Request $request): JsonResponse
    {
        $input = $request->validate([
            'person_id' => ['sometimes', 'uuid'],
            'changes' => ['required', 'array', 'min:1'],
            'changes.first_names' => ['sometimes', 'string', 'min:1', 'max:100'],
            'changes.paternal_surname' => ['sometimes', 'string', 'min:1', 'max:100'],
            'changes.maternal_surname' => ['sometimes', 'nullable', 'string', 'max:100'],
            'changes.preferred_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'changes.birth_date' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'before:today'],
            'changes.sex' => ['sometimes', 'nullable', Rule::in(['female', 'male', 'unspecified'])],
            'changes.marital_status' => ['sometimes', 'nullable', Rule::in(['single', 'married', 'cohabiting', 'divorced', 'widowed'])],
            'changes.nationality_code' => ['sometimes', 'nullable', 'string', 'size:2', Rule::exists('countries', 'code')],
            'changes.personal_email' => ['sometimes', 'nullable', 'email:rfc', 'max:150'],
            'changes.mobile_phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'changes.address' => ['sometimes', 'string', 'min:3', 'max:300'],
        ]);
        $own = $this->viewerPersonId($request);
        $personId = isset($input['person_id']) ? (string) $input['person_id'] : $own;
        // La persona se busca con el alcance de quien pide: fuera de él, no existe.
        $person = $personId === null ? null : $this->visiblePeople($request, 'people.change_requests.create')->whereKey($personId)->first();
        abort_unless($person instanceof Person, 404);
        if ($own !== $person->id) {
            throw new AyniException('forbidden', 403, (string) trans('people.change_request_own_only'));
        }
        /** @var array<string, mixed> $changes */
        $changes = $input['changes'];

        return $this->respond($this->requests->create($person, $changes, (string) $this->userId($request)), 201);
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        $found = $this->find($request, $id, 'people.change_requests.approve');
        // Nadie aprueba sus propios cambios (segregación de funciones).
        if ($found->requested_by_user_id === $this->userId($request)) {
            throw new AyniException('forbidden', 403, (string) trans('people.change_request_self_approval'));
        }

        return $this->respond($this->requests->approve($found, (string) $this->userId($request)));
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        $found = $this->find($request, $id, 'people.change_requests.reject');
        if ($found->requested_by_user_id === $this->userId($request)) {
            throw new AyniException('forbidden', 403, (string) trans('people.change_request_self_approval'));
        }
        $data = $request->validate(['reason' => ['sometimes', 'nullable', 'string', 'max:500'], 'comment' => ['sometimes', 'nullable', 'string', 'max:500']]);
        $reason = $data['reason'] ?? $data['comment'] ?? null;

        return $this->respond($this->requests->reject($found, is_string($reason) ? $reason : null, (string) $this->userId($request)));
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $found = $this->find($request, $id, 'people.change_requests.cancel');

        return $this->respond($this->requests->cancel($found, (string) $this->userId($request)));
    }

    /**
     * Solicitudes visibles: las propias (pedidas por quien consulta) y las de personas de su alcance.
     *
     * @return Builder<ProfileChangeRequest>
     */
    private function visible(Request $request, string $permission): Builder
    {
        $userId = $this->userId($request);
        $people = $this->visiblePeople($request, $permission)->select('id');

        return ProfileChangeRequest::query()->where(function ($w) use ($people, $userId): void {
            $w->whereIn('person_id', $people);
            if ($userId !== null) {
                $w->orWhere('requested_by_user_id', $userId);
            }
        });
    }

    private function find(Request $request, string $id, string $permission): ProfileChangeRequest
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $id) === 1, 404);
        $found = $this->visible($request, $permission)->whereKey($id)->first();
        abort_unless($found instanceof ProfileChangeRequest, 404);

        return $found;
    }

    private function respond(ProfileChangeRequest $request, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $this->requests->presentMany([$request])[0]], $status);
    }
}
