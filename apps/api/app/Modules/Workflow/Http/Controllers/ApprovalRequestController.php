<?php

namespace App\Modules\Workflow\Http\Controllers;

use App\Modules\Workflow\Application\ApprovalEngine;
use App\Modules\Workflow\Application\ApprovalRequestPresenter;
use App\Modules\Workflow\Infrastructure\Models\ApprovalRequest;
use App\Shared\Pagination\CursorPaginator;
use App\Shared\Pagination\PaginationParams;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * WFL-2 · Solicitudes de aprobación: listado (todas o «asignadas a mí»), detalle y decisiones.
 */
final class ApprovalRequestController
{
    public function __construct(
        private readonly ApprovalEngine $engine,
        private readonly ApprovalRequestPresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $params = PaginationParams::fromRequest($request);
        $userId = $this->userId($request);
        $query = ApprovalRequest::query();
        $assigned = in_array((string) $request->input('filter.assigned', $request->query('assigned', '')), ['me', '1', 'true'], true);
        $this->presenter->applyScope($query, $userId, $this->scope($request), $assigned);

        $status = $request->input('filter.status', $request->query('status'));
        if ($status === 'pending') {
            $query->whereIn('status', ['pending', 'running', 'escalated']);
        } elseif (in_array($status, ['approved', 'rejected', 'cancelled'], true)) {
            $query->where('status', $status);
        }
        $type = $request->input('filter.subject_type', $request->query('subject_type'));
        if (is_string($type) && $type !== '') {
            $query->where('subject_type', $type);
        }
        $q = trim((string) $request->query('q', ''));
        if ($q !== '') {
            $like = '%'.addcslashes($q, '%_\\').'%';
            $query->where(fn ($w) => $w->where('subject_type', 'ilike', $like)
                ->orWhereIn('started_by_user_id', fn ($u) => $u->select('id')->from('users')->where('display_name', 'ilike', $like)));
        }

        $page = CursorPaginator::paginate($query, $params->perPage, $params->cursor, 'id', 'desc');
        $ids = array_column($page['data'], 'id');
        $models = ApprovalRequest::query()->whereIn('id', $ids)->get()->keyBy('id');

        return response()->json([
            'data' => $models->only($ids)->values()->map(fn (ApprovalRequest $r) => $this->presenter->present($r, $userId))->all(),
            'meta' => ['per_page' => $page['meta']['per_page'], 'next_cursor' => $page['meta']['next_cursor']],
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return $this->respond($request, $this->find($request, $id));
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        $approval = $this->find($request, $id);
        $data = $request->validate(['comment' => ['sometimes', 'nullable', 'string', 'max:2000']]);

        return $this->respond($request, $this->engine->decide($approval, $this->userId($request), 'approve', $data['comment'] ?? null));
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        $approval = $this->find($request, $id);
        $data = $request->validate(['comment' => ['sometimes', 'nullable', 'string', 'max:2000'], 'reason' => ['sometimes', 'nullable', 'string', 'max:2000']]);

        return $this->respond($request, $this->engine->decide($approval, $this->userId($request), 'reject', $data['comment'] ?? $data['reason'] ?? null));
    }

    public function delegate(Request $request, string $id): JsonResponse
    {
        $approval = $this->find($request, $id);
        $data = $request->validate(['to_user_id' => ['required', 'uuid'], 'comment' => ['sometimes', 'nullable', 'string', 'max:2000']]);

        return $this->respond($request, $this->engine->delegate($approval, $this->userId($request), (string) $data['to_user_id'], $data['comment'] ?? null));
    }

    /**
     * Fuera del alcance del usuario es «no encontrado» (RN-SEG-04).
     */
    private function find(Request $request, string $id): ApprovalRequest
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $id) === 1, 404);
        $query = ApprovalRequest::query()->whereKey($id);
        $this->presenter->applyScope($query, $this->userId($request), $this->viewScope($request), false);

        /** @var ApprovalRequest */
        return $query->firstOrFail();
    }

    private function scope(Request $request): ?string
    {
        $scope = $request->attributes->get('permission_scope');

        return is_string($scope) ? $scope : null;
    }

    /**
     * Las acciones (alcance «asignado») se resuelven contra el alcance de lectura del usuario.
     */
    private function viewScope(Request $request): ?string
    {
        $effective = $request->attributes->get('effective_permissions');

        return is_object($effective) && method_exists($effective, 'scopeFor') ? $effective->scopeFor('workflow.approval_requests.view') : $this->scope($request);
    }

    private function userId(Request $request): string
    {
        return (string) $request->user()?->getAuthIdentifier();
    }

    private function respond(Request $request, ApprovalRequest $approval): JsonResponse
    {
        return response()->json(['data' => $this->presenter->present($approval->refresh(), $this->userId($request))]);
    }
}
