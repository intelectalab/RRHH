<?php

namespace App\Modules\Documents\Http\Controllers;

use App\Modules\Documents\Application\DocumentAccess;
use App\Modules\Documents\Application\DocumentRequestService;
use App\Modules\Documents\Domain\DocumentRequestStateMachine;
use App\Modules\Documents\Infrastructure\Models\DocumentRequest;
use App\Shared\Exceptions\AyniException;
use App\Shared\Permissions\DataScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * DOC-3 · Solicitudes de documentos. El alcance se aplica en la consulta (la relación laboral de la solicitud);
 * fuera de él, 404. RRHH ve lo pendiente de entrega y de revisión; el colaborador, lo que le piden (portal).
 * Aprobar y rechazar se deciden en el centro de aprobaciones (/approval-requests/{id}).
 */
final class DocumentRequestController
{
    private const STATUSES = ['requested', 'submitted', 'approved', 'rejected', 'cancelled'];

    public function __construct(
        private readonly DocumentAccess $access,
        private readonly DocumentRequestService $requests,
        private readonly DocumentRequestStateMachine $machine,
        private readonly DataScope $scope,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $input = $request->validate([
            'filter' => ['sometimes', 'array'],
            'filter.status' => ['sometimes', 'string', 'max:200'],
            'filter.employment_id' => ['sometimes', 'uuid'],
            'filter.category_id' => ['sometimes', 'uuid'],
            'filter.request_group_id' => ['sometimes', 'uuid'],
            'filter.overdue' => ['sometimes', 'in:true,false,1,0'],
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $filter = $input['filter'] ?? [];
        $statuses = isset($filter['status']) ? explode(',', (string) $filter['status']) : [];
        if (array_diff($statuses, self::STATUSES) !== []) {
            throw new AyniException('invalid_filter', 400, (string) trans('documents.documents.invalid_filter'));
        }
        $query = $this->scoped($request, 'documents.documents.view')
            ->when($statuses !== [], fn ($q) => $q->whereIn('document_requests.status', $statuses))
            ->when(isset($filter['employment_id']), fn ($q) => $q->where('document_requests.employment_id', $filter['employment_id']))
            ->when(isset($filter['category_id']), fn ($q) => $q->where('document_requests.document_category_id', $filter['category_id']))
            ->when(isset($filter['request_group_id']), fn ($q) => $q->where('document_requests.request_group_id', $filter['request_group_id']))
            ->when(in_array($filter['overdue'] ?? null, ['true', '1'], true), fn ($q) => $q->whereIn('document_requests.status', ['requested', 'rejected'])
                ->where('document_requests.due_on', '<', now()->toDateString()));
        $q = isset($input['q']) ? trim((string) $input['q']) : '';
        if ($q !== '') {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($q)).'%';
            $query->where(fn ($w) => $w->whereRaw('lower(document_requests.title) like ?', [$like])
                ->orWhereIn('document_requests.employment_id', DB::table('employments as e')->join('people as p', 'p.id', '=', 'e.person_id')
                    ->where(fn ($p) => $p->whereRaw("lower(p.first_names || ' ' || p.paternal_surname) like ?", [$like])->orWhereRaw('lower(e.employee_code) like ?', [$like]))
                    ->select('e.id')));
        }
        $page = (int) ($input['page'] ?? 1);
        $perPage = (int) ($input['per_page'] ?? 25);
        $total = (clone $query)->count();
        /** @var list<DocumentRequest> $rows */
        $rows = $query->orderByRaw("CASE document_requests.status WHEN 'submitted' THEN 0 WHEN 'requested' THEN 1 WHEN 'rejected' THEN 2 ELSE 3 END")
            ->orderBy('document_requests.due_on')->orderByDesc('document_requests.created_at')->forPage($page, $perPage)->get()->all();

        return response()->json(['data' => $this->present($rows, $request), 'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'next_cursor' => null]]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $row = $this->find($request, $id, 'documents.documents.view');

        return response()->json(['data' => $this->present([$row], $request, true)[0]]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'category_id' => ['required', 'uuid'],
            'employment_ids' => ['required_without:filter', 'array', 'min:1', 'max:500'],
            'employment_ids.*' => ['uuid'],
            'filter' => ['required_without:employment_ids', 'array'],
            'filter.legal_entity_id' => ['sometimes', 'nullable', 'uuid'],
            'filter.org_unit_id' => ['sometimes', 'nullable', 'uuid'],
            'filter.site_id' => ['sometimes', 'nullable', 'uuid'],
            'title' => ['sometimes', 'nullable', 'string', 'max:200'],
            'instructions' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'due_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ]);
        $employments = DB::table('employments')->whereNull('employments.deleted_at')->whereIn('employments.status', ['active', 'pending', 'suspended', 'terminating']);
        if (isset($data['employment_ids'])) {
            $employments->whereIn('employments.id', array_values(array_unique((array) $data['employment_ids'])));
        } else {
            $f = $data['filter'] ?? [];
            $today = now()->toDateString();
            $employments->when(isset($f['legal_entity_id']), fn ($q) => $q->where('employments.legal_entity_id', $f['legal_entity_id']))
                ->when(isset($f['org_unit_id']) || isset($f['site_id']), fn ($q) => $q->whereExists(fn ($a) => $a->from('employment_assignments as a')
                    ->whereColumn('a.employment_id', 'employments.id')->where('a.valid_from', '<=', $today)
                    ->where(fn ($v) => $v->whereNull('a.valid_to')->orWhere('a.valid_to', '>=', $today))
                    ->when(isset($f['org_unit_id']), fn ($w) => $w->where('a.org_unit_id', $f['org_unit_id']))
                    ->when(isset($f['site_id']), fn ($w) => $w->where('a.site_id', $f['site_id']))));
        }
        $this->scope->applyEmployment($employments, $request, 'documents.documents.request', 'employments.id');
        $ids = array_values($employments->orderBy('employments.employee_code')->limit(500)->pluck('employments.id')->map(fn ($id): string => (string) $id)->all());
        if ($ids === []) {
            throw ValidationException::withMessages(['employment_ids' => [(string) trans('documents.requests.no_recipients')]]);
        }
        $result = $this->requests->request($data, $ids, $this->access->userId($request));

        return response()->json(['data' => $this->present($result['created'], $request), 'meta' => ['created' => count($result['created']), 'skipped' => $result['skipped']]], 201);
    }

    public function submit(Request $request, string $id): JsonResponse
    {
        $row = $this->find($request, $id, 'documents.documents.create');
        $data = $request->validate([
            'file' => ['required', 'file', 'max:20480'],
            'issued_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'expires_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ]);
        $file = $request->file('file');
        abort_unless($file instanceof UploadedFile, 422);
        $row = $this->requests->submit($row, $file, $data['issued_on'] ?? null, $data['expires_on'] ?? null, $this->access->userId($request), $this->access->viewerPerson($request));

        return response()->json(['data' => $this->present([$row], $request, true)[0]]);
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $row = $this->find($request, $id, 'documents.documents.request');
        $reason = $request->validate(['reason' => ['sometimes', 'nullable', 'string', 'max:500']])['reason'] ?? null;
        $row = $this->requests->cancel($row, is_string($reason) && trim($reason) !== '' ? trim($reason) : null);

        return response()->json(['data' => $this->present([$row], $request, true)[0]]);
    }

    /**
     * @return Builder<DocumentRequest>
     */
    private function scoped(Request $request, string $permission): Builder
    {
        $query = DocumentRequest::query()->select('document_requests.*')->whereNotNull('document_requests.employment_id');
        $this->scope->applyEmployment($query, $request, $permission, 'document_requests.employment_id');
        $blocked = $this->access->blockedCategories($this->access->permissions($request));
        if ($blocked !== []) {
            $query->whereNotIn('document_requests.document_category_id', $blocked);
        }

        return $query;
    }

    private function find(Request $request, string $id, string $permission): DocumentRequest
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $id) === 1, 404);
        $row = $this->scoped($request, $permission)->where('document_requests.id', $id)->first();
        abort_unless($row instanceof DocumentRequest, 404);

        return $row;
    }

    /**
     * @param  list<DocumentRequest>  $rows
     * @return list<array<string, mixed>>
     */
    private function present(array $rows, Request $request, bool $withApproval = false): array
    {
        if ($rows === []) {
            return [];
        }
        $employments = DB::table('employments as e')->join('people as p', 'p.id', '=', 'e.person_id')
            ->whereIn('e.id', array_values(array_unique(array_map(fn (DocumentRequest $r) => (string) $r->employment_id, $rows))))
            ->get(['e.id', 'e.employee_code', 'e.person_id', 'p.first_names', 'p.preferred_name', 'p.paternal_surname', 'p.maternal_surname'])->keyBy('id');
        $categories = DB::table('document_categories')->whereIn('id', array_values(array_unique(array_map(fn (DocumentRequest $r) => $r->document_category_id, $rows))))->pluck('name', 'id');
        $users = DB::table('users')->whereIn('id', array_values(array_filter(array_unique(array_map(fn (DocumentRequest $r) => $r->requested_by_user_id, $rows)))))->pluck('display_name', 'id');
        $permissions = $this->access->permissions($request);
        $person = $this->access->viewerPerson($request);
        $today = now()->toDateString();

        return array_map(function (DocumentRequest $r) use ($employments, $categories, $users, $permissions, $person, $today, $withApproval): array {
            $e = $employments->get((string) $r->employment_id);
            $actions = [];
            foreach ($this->machine->allowedActions($r->status) as $action) {
                $ok = match ($action) {
                    'submit' => $permissions->has('documents.documents.create') && ($person === null || $e === null || $e->person_id === $person
                        || ! $this->access->isTitularOnly($permissions, 'documents.documents.create')),
                    'cancel' => $permissions->has('documents.documents.request'),
                    default => false,
                };
                if ($ok) {
                    $actions[] = $action;
                }
            }
            $due = $r->dueOn();

            return [
                'id' => $r->id, 'employment_id' => $r->employment_id, 'person_id' => $r->person_id,
                'person_name' => $e === null ? null : trim(($e->preferred_name ?: $e->first_names).' '.$e->paternal_surname.' '.($e->maternal_surname ?? '')),
                'employee_code' => $e?->employee_code, 'category_id' => $r->document_category_id, 'category_name' => $categories[$r->document_category_id] ?? null,
                'title' => $r->title, 'instructions' => $r->instructions, 'due_on' => $due,
                'overdue' => $due !== null && $due < $today && in_array($r->status, ['requested', 'rejected'], true),
                'status' => $r->status, 'document_id' => $r->document_id, 'rejection_reason' => $r->rejection_reason, 'request_group_id' => $r->request_group_id,
                'requested_by_user_id' => $r->requested_by_user_id, 'requested_by_name' => $r->requested_by_user_id === null ? null : ($users[$r->requested_by_user_id] ?? null),
                'submitted_at' => $r->submitted_at?->toIso8601String(), 'reviewed_at' => $r->reviewed_at?->toIso8601String(),
                'cancellation_reason' => $r->cancellation_reason,
                'approval_request_id' => $withApproval ? $this->requests->approvalId($r->id) : null,
                'approval' => $withApproval ? $this->requests->approval($r->id) : null,
                'allowed_actions' => $actions, 'version' => $r->lock_version,
                'created_at' => $r->created_at->toIso8601String(), 'updated_at' => $r->updated_at->toIso8601String(),
            ];
        }, $rows);
    }
}
