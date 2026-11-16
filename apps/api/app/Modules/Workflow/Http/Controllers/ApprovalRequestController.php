<?php

namespace App\Modules\Workflow\Http\Controllers;

use App\Modules\Workflow\Application\ApprovalEngine;
use App\Modules\Workflow\Application\ApprovalRequestPresenter;
use App\Modules\Workflow\Infrastructure\Models\ApprovalRequest;
use App\Shared\Exceptions\AyniException;
use App\Shared\Pagination\CursorPaginator;
use App\Shared\Pagination\PaginationParams;
use App\Shared\Permissions\EffectivePermissions;
use App\Shared\Permissions\TeamLine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * WFL-2 · Solicitudes de aprobación: listado (todas o «asignadas a mí»), detalle y decisiones.
 * MSS-3 · filter[team]=1: solo las de personas del equipo del usuario (RN-MSS-01) y las que él inició sin
 * persona (una nueva posición). Acota, nunca amplía: sigue aplicando el alcance de lectura.
 */
final class ApprovalRequestController
{
    public function __construct(
        private readonly ApprovalEngine $engine,
        private readonly ApprovalRequestPresenter $presenter,
        private readonly TeamLine $team,
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
        if (in_array((string) $request->input('filter.team', ''), ['1', 'true'], true)) {
            $effective = $request->attributes->get('effective_permissions');
            $indirect = $effective instanceof EffectivePermissions && $effective->has('people.team.view_indirect');
            $members = array_keys($this->team->members($userId, now()->toDateString(), $indirect));
            $query->where(function ($w) use ($members, $userId): void {
                $w->whereRaw("context->>'employment_id' = ANY (?::text[])", ['{'.implode(',', $members).'}'])
                    ->orWhere(fn ($own) => $own->where('started_by_user_id', $userId)->whereRaw("context->>'employment_id' IS NULL"));
            });
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
            'data' => collect($ids)->map(fn ($id) => $models->get($id))->filter()->values()->map(fn (ApprovalRequest $r) => $this->presenter->present($r, $userId))->all(),
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
     * RN-WFL-12 · Aprobación masiva: solo tipos marcados como seguros; cada una se decide y audita por separado.
     */
    public function bulkApprove(Request $request): JsonResponse
    {
        $data = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:100'], 'ids.*' => ['uuid'], 'comment' => ['sometimes', 'nullable', 'string', 'max:2000']]);
        $userId = $this->userId($request);
        $approved = [];
        $failed = [];
        foreach (array_values(array_unique($data['ids'])) as $id) {
            $query = ApprovalRequest::query()->whereKey($id);
            $this->presenter->applyScope($query, $userId, $this->viewScope($request), false);
            $approval = $query->first();
            if (! $approval instanceof ApprovalRequest) {
                $failed[] = ['id' => $id, 'code' => 'not_found'];

                continue;
            }
            if (! $this->presenter->bulkSafe($approval->subject_type)) {
                $failed[] = ['id' => $id, 'code' => 'bulk_not_allowed'];

                continue;
            }
            try {
                $this->engine->decide($approval, $userId, 'approve', $data['comment'] ?? null);
                $approved[] = $id;
            } catch (AyniException $e) {
                $failed[] = ['id' => $id, 'code' => $e->getErrorCode()];
            }
        }

        return response()->json(['data' => ['approved' => $approved, 'failed' => $failed]]);
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
