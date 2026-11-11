<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Application\CodeSignIn;
use App\Modules\Identity\Application\UserService;
use App\Modules\Identity\Http\Requests\UserRequest;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Shared\Audit\ActivityLogger;
use App\Shared\Pagination\CursorPaginator;
use App\Shared\Pagination\PaginationParams;
use App\Shared\Permissions\EffectivePermissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * SEG-6 · Usuarios. Los cambios de estado son acciones (POST /users/{id}/accion).
 */
final class UserController
{
    public function __construct(private readonly UserService $users) {}

    public function index(Request $request): JsonResponse
    {
        $params = PaginationParams::fromRequest($request);
        $query = User::query();

        $q = trim((string) $request->query('q', ''));
        if ($q !== '') {
            $query->where('display_name', 'ilike', '%'.addcslashes($q, '%_\\').'%');
        }
        $status = $request->query('status');
        if (is_string($status) && $status !== '') {
            $query->whereIn('status', explode(',', $status));
        }

        $page = CursorPaginator::paginate($query, $params->perPage, $params->cursor);
        $ids = array_column($page['data'], 'id');
        $models = User::query()->whereIn('id', $ids)->get()->keyBy('id');

        return response()->json([
            'data' => array_values(array_map(
                fn (User $u) => $this->users->present($u),
                array_filter(array_map(fn ($id) => $models->get($id), $ids), fn ($u) => $u instanceof User),
            )),
            'meta' => ['per_page' => $page['meta']['per_page'], 'next_cursor' => $page['meta']['next_cursor']],
        ]);
    }

    public function show(string $id): JsonResponse
    {
        $user = $this->find($id);
        // AUD-3: la vista de la ficha va a la actividad del usuario (no a la auditoría).
        app(ActivityLogger::class)->record('record.viewed', 'user', $user->id);

        return $this->respond($user);
    }

    public function store(UserRequest $request): JsonResponse
    {
        /** @var array{login?: string|null, display_name?: string, email?: string|null, document_type?: string|null, document_number?: string|null, phone?: string|null, person_id?: string|null, role_ids?: array<int, string>} $input */
        $input = $request->validated();

        return $this->respond($this->users->create($input, $this->actor($request), $request->getSchemeAndHttpHost()), 201);
    }

    public function update(UserRequest $request, string $id): JsonResponse
    {
        $user = $this->find($id);
        $ifMatch = trim((string) $request->header('If-Match', ''), ' "W/');

        /** @var array{login?: string|null, display_name?: string, email?: string|null, document_type?: string|null, document_number?: string|null, phone?: string|null, person_id?: string|null, role_ids?: array<int, string>, role_assignments?: array<int, mixed>} $input */
        $input = $request->validated();

        return $this->respond($this->users->update($user, $input, $this->actor($request), $ifMatch === '' ? null : (int) $ifMatch));
    }

    public function invite(Request $request, string $id): JsonResponse
    {
        return $this->respond($this->users->invite($this->find($id), $request->getSchemeAndHttpHost()));
    }

    public function suspend(Request $request, string $id): JsonResponse
    {
        // Primero el recurso (404 entre organizaciones), luego la validación (422).
        $user = $this->find($id);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        return $this->respond($this->users->suspend($user, (string) $data['reason']));
    }

    /**
     * SEG-4 · Un administrador fuerza el cierre de todas las sesiones de un usuario.
     */
    public function revokeSessions(Request $request, string $id): JsonResponse
    {
        $user = $this->find($id);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        return $this->respond($this->users->revokeSessions($user, (string) $data['reason']));
    }

    public function reactivate(Request $request, string $id): JsonResponse
    {
        $user = $this->find($id);
        $data = $request->validate(['reason' => ['sometimes', 'nullable', 'string', 'max:500']]);

        return $this->respond($this->users->reactivate($user, isset($data['reason']) ? (string) $data['reason'] : null));
    }

    public function resetPassword(Request $request, string $id): JsonResponse
    {
        $user = $this->find($id);
        $data = $request->validate(['reason' => ['sometimes', 'nullable', 'string', 'max:500']]);

        return $this->respond($this->users->resetPassword($user, $request->getSchemeAndHttpHost(), isset($data['reason']) ? (string) $data['reason'] : null));
    }

    /** SEG-2: código de activación para quien no tiene correo (se muestra una vez o se envía por mensaje). */
    public function activationCode(Request $request, string $id, CodeSignIn $codes): JsonResponse
    {
        $user = $this->find($id);
        $data = $request->validate(['channel' => ['required', 'string', 'in:in_app,sms,whatsapp']]);

        return response()->json(['data' => $codes->issueActivationCode($user, (string) $data['channel'])]);
    }

    public function resetMfa(Request $request, string $id): JsonResponse
    {
        $user = $this->find($id);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        return $this->respond($this->users->resetMfa($user, (string) $data['reason']));
    }

    private function find(string $id): User
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $id) === 1, 404);

        /** @var User */
        return User::query()->whereKey($id)->firstOrFail();
    }

    private function actor(Request $request): EffectivePermissions
    {
        /** @var EffectivePermissions */
        return $request->attributes->get('effective_permissions');
    }

    private function respond(User $user, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $this->users->present($user)], $status)
            ->header('ETag', '"'.$user->lock_version.'"');
    }
}
