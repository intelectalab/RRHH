<?php

namespace App\Modules\Contracts\Http\Controllers;

use App\Modules\Contracts\Application\ContractDocuments;
use App\Modules\Contracts\Application\RemoteWorkService;
use App\Modules\Contracts\Infrastructure\Models\RemoteWorkAgreement;
use App\Shared\Exceptions\InvalidFilterException;
use App\Shared\Permissions\DataScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * CON-6 · Solicitudes y acuerdos de teletrabajo. Se ubican por la relación laboral con el alcance del usuario aplicado
 * en la consulta (regla 6). Los estados cambian solo por acciones (regla 3).
 */
final class RemoteWorkController
{
    private const STATUSES = ['requested', 'approved', 'rejected', 'pending_signature', 'active', 'ended'];

    public function __construct(
        private readonly RemoteWorkService $service,
        private readonly ContractDocuments $documents,
        private readonly DataScope $scope,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = RemoteWorkAgreement::query()->select('remote_work_agreements.*');
        $this->scope->applyEmployment($query, $request, 'contracts.remote_work.view', 'remote_work_agreements.employment_id');
        $filter = (array) $request->query('filter', []);
        if (isset($filter['status']) && is_string($filter['status'])) {
            if (! in_array($filter['status'], self::STATUSES, true)) {
                throw new InvalidFilterException((string) trans('errors.invalid_filter'), ['filter[status]']);
            }
            $query->where('remote_work_agreements.status', $filter['status']);
        }
        if (isset($filter['employment_id']) && is_string($filter['employment_id'])) {
            if (preg_match('/^[0-9a-f-]{36}$/i', $filter['employment_id']) !== 1) {
                throw new InvalidFilterException((string) trans('errors.invalid_filter'), ['filter[employment_id]']);
            }
            $query->where('remote_work_agreements.employment_id', $filter['employment_id']);
        }
        $perPage = max(1, min(100, (int) $request->query('per_page', 25)));
        $page = max(1, (int) $request->query('page', 1));
        $total = (clone $query)->count();
        $rows = $query->orderByRaw("CASE remote_work_agreements.status WHEN 'requested' THEN 0 WHEN 'approved' THEN 1 WHEN 'pending_signature' THEN 2 ELSE 3 END")
            ->orderBy('remote_work_agreements.response_due_on')->orderByDesc('remote_work_agreements.created_at')
            ->forPage($page, $perPage)->get()->map(fn (RemoteWorkAgreement $a): array => $this->present($a))->all();

        return response()->json(['data' => $rows, 'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'next_cursor' => null]]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employment_id' => ['required', 'uuid'],
            'requested_by' => ['sometimes', Rule::in(['employee', 'employer'])],
            'requested_on' => ['sometimes', 'date_format:Y-m-d', 'before_or_equal:today'],
            ...$this->contentRules(true),
        ]);
        $this->employmentInScope($request, (string) $data['employment_id'], 'contracts.remote_work.create');

        return $this->respond($this->service->create($data, $this->userId($request)), 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return $this->respond($this->find($request, $id, 'contracts.remote_work.view'));
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $agreement = $this->find($request, $id, 'contracts.remote_work.update');
        $data = $request->validate($this->contentRules(false));
        $version = $request->header('If-Match');
        $expected = is_string($version) && $version !== '' ? (int) trim($version, '"W/') : null;

        return $this->respond($this->service->update($agreement, $data, $expected));
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        $agreement = $this->find($request, $id, 'contracts.remote_work.decide');
        $reason = $request->input('reason');

        return $this->respond($this->service->decide($agreement, 'approve', is_string($reason) ? $reason : null, $this->userId($request)));
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        $agreement = $this->find($request, $id, 'contracts.remote_work.decide');
        $reason = $request->input('reason');

        return $this->respond($this->service->decide($agreement, 'reject', is_string($reason) ? $reason : null, $this->userId($request)));
    }

    public function issue(Request $request, string $id): JsonResponse
    {
        return $this->respond($this->service->issue($this->find($request, $id, 'contracts.remote_work.issue'), $this->userId($request)));
    }

    public function registerSignature(Request $request, string $id): JsonResponse
    {
        $agreement = $this->find($request, $id, 'contracts.remote_work.issue');
        $data = $request->validate([
            'file' => ['required', 'file', 'max:'.(int) (config('files.max_size_bytes', 26214400) / 1024)],
            'origin' => ['sometimes', Rule::in(['physical_scan', 'electronic'])],
            'signed_on' => ['required', 'date_format:Y-m-d'],
        ]);
        $file = $request->file('file');
        abort_unless($file instanceof UploadedFile, 422);

        return $this->respond($this->service->registerSignature($agreement, (string) $file->getContent(), $file->getClientOriginalName(), (string) $file->getMimeType(),
            (string) ($data['origin'] ?? 'physical_scan'), (string) $data['signed_on'], $this->userId($request)));
    }

    public function end(Request $request, string $id): JsonResponse
    {
        $agreement = $this->find($request, $id, 'contracts.remote_work.issue');
        $data = $request->validate([
            'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$agreement->starts_on->toDateString()],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        return $this->respond($this->service->end($agreement, (string) $data['ends_on'], (string) $data['reason']));
    }

    public function document(Request $request, string $id): Response
    {
        $agreement = $this->find($request, $id, 'contracts.remote_work.view');
        $file = $this->documents->download($agreement->document_id, $this->userId($request));
        abort_if($file === null, 404);

        return response($file['contents'], 200, [
            'Content-Type' => $file['mime'],
            'Content-Disposition' => 'attachment; filename="'.addslashes($file['name']).'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function contentRules(bool $creating): array
    {
        return [
            'modality' => [$creating ? 'required' : 'sometimes', Rule::in(['total', 'partial'])],
            'starts_on' => [$creating ? 'required' : 'sometimes', 'date_format:Y-m-d'],
            'ends_on' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'work_place' => ['sometimes', 'nullable', 'string', 'max:500'],
            'schedule_description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'disconnection_hours' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:24'],
            'supervision_mechanism' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'equipment_provided_by' => ['sometimes', 'nullable', Rule::in(['employer', 'worker', 'mixed'])],
            'expense_compensation' => ['sometimes', 'nullable', 'array'],
            'expense_compensation.amount' => ['required_with:expense_compensation', 'decimal:0,2', 'min:0'],
            'expense_compensation.currency' => ['required_with:expense_compensation', 'string', 'size:3', Rule::exists('currencies', 'code')],
            'request_reason' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function present(RemoteWorkAgreement $a): array
    {
        $person = DB::table('employments as e')->join('people as p', 'p.id', '=', 'e.person_id')->where('e.id', $a->employment_id)
            ->first(['e.employee_code', 'e.legal_entity_id', 'p.first_names', 'p.preferred_name', 'p.paternal_surname']);
        $today = now()->toDateString();

        // Los campos opcionales sin valor no viajan (el contrato no los declara anulables).
        return array_filter([
            'id' => $a->id, 'employment_id' => $a->employment_id,
            'person_name' => $person === null ? null : trim(($person->preferred_name ?: $person->first_names).' '.$person->paternal_surname),
            'employee_code' => $person?->employee_code, 'legal_entity_id' => $person?->legal_entity_id,
            'modality' => $a->modality, 'starts_on' => $a->starts_on->toDateString(), 'ends_on' => $a->ends_on?->toDateString(),
            'work_place' => $a->work_place, 'schedule_description' => $a->schedule_description, 'disconnection_hours' => $a->disconnection_hours,
            'supervision_mechanism' => $a->supervision_mechanism, 'equipment_provided_by' => $a->equipment_provided_by,
            'expense_compensation' => $a->expense_compensation === null || $a->currency === null ? null : ['amount' => $a->expense_compensation, 'currency' => $a->currency],
            'requested_by' => $a->requested_by, 'request_reason' => $a->request_reason,
            'requested_on' => $a->requested_on?->toDateString(), 'response_due_on' => $a->response_due_on?->toDateString(),
            'responded_on' => $a->responded_on?->toDateString(),
            'due_soon' => $this->service->dueSoon($a, $today),
            'overdue' => $a->status === 'requested' && $a->response_due_on !== null && $a->response_due_on->toDateString() < $today,
            'decision_reason' => $a->decision_reason, 'status' => $a->status,
            'missing_content' => $this->service->missingContent($a), 'has_document' => $a->document_id !== null,
            'signed_at' => $a->signed_at?->toIso8601String(), 'signature_origin' => $a->signature_origin, 'end_reason' => $a->end_reason,
            'allowed_actions' => $this->service->allowedActions($a), 'version' => $a->lock_version,
            'created_at' => $a->created_at->toIso8601String(), 'updated_at' => $a->updated_at->toIso8601String(),
        ], fn ($v): bool => $v !== null);
    }

    private function respond(RemoteWorkAgreement $agreement, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $this->present($agreement->refresh())], $status)->header('ETag', '"'.$agreement->lock_version.'"');
    }

    private function find(Request $request, string $id, string $permission): RemoteWorkAgreement
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $id) === 1, 404);
        $query = RemoteWorkAgreement::query()->select('remote_work_agreements.*')->where('remote_work_agreements.id', $id);
        $this->scope->applyEmployment($query, $request, $permission, 'remote_work_agreements.employment_id');
        $agreement = $query->first();
        abort_unless($agreement instanceof RemoteWorkAgreement, 404);

        return $agreement;
    }

    private function employmentInScope(Request $request, string $employmentId, string $permission): void
    {
        $query = DB::table('employments')->where('employments.id', $employmentId)->whereNull('employments.deleted_at');
        $this->scope->applyEmployment($query, $request, $permission, 'employments.id');
        abort_unless($query->exists(), 404);
    }

    private function userId(Request $request): ?string
    {
        $id = $request->user()?->getAuthIdentifier();

        return $id === null ? null : (string) $id;
    }
}
