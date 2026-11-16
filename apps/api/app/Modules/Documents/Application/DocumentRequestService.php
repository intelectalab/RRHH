<?php

namespace App\Modules\Documents\Application;

use App\Modules\Documents\Domain\DocumentRequestStateMachine;
use App\Modules\Documents\Infrastructure\Models\Document;
use App\Modules\Documents\Infrastructure\Models\DocumentCategory;
use App\Modules\Documents\Infrastructure\Models\DocumentRequest;
use App\Shared\Audit\AuditLogger;
use App\Shared\Exceptions\VersionConflictException;
use App\Shared\Outbox\Contracts\OutboxWriter;
use App\Shared\Outbox\DomainEventTypes;
use App\Shared\Workflow\ApprovalRequests;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * DOC-3 · Solicitud y aprobación de documentos.
 *
 * 1) RRHH pide un tipo documental a una o varias personas con fecha límite (una solicitud por persona, agrupadas
 *    en el mismo envío); a quien ya tiene una abierta del mismo tipo no se le duplica. Se avisa al colaborador.
 * 2) El colaborador lo entrega desde el portal: entra a su legajo en revisión y se pide la aprobación al motor
 *    (flujo document_request, RN-WFL-01: sin flujo publicado no se entrega).
 * 3) Aprobada, el documento queda vigente; rechazada, el documento queda rechazado con la observación y la
 *    solicitud vuelve al colaborador para entregarlo de nuevo.
 */
final class DocumentRequestService
{
    public const SUBJECT_TYPE = 'document_request';

    public const OPEN = [DocumentRequestStateMachine::REQUESTED, DocumentRequestStateMachine::SUBMITTED, DocumentRequestStateMachine::REJECTED];

    public function __construct(
        private readonly DocumentRequestStateMachine $machine,
        private readonly DocumentCatalog $catalog,
        private readonly DocumentService $documents,
        private readonly ApprovalRequests $approvals,
        private readonly AuditLogger $audit,
        private readonly OutboxWriter $outbox,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $employmentIds  ya filtradas por el alcance de quien pide
     * @return array{created: list<DocumentRequest>, skipped: list<array{employment_id: string, reason: string}>}
     */
    public function request(array $data, array $employmentIds, ?string $userId): array
    {
        $category = $this->category((string) $data['category_id']);
        $due = isset($data['due_on']) ? (string) $data['due_on'] : null;
        if ($due !== null && $due < now()->toDateString()) {
            throw ValidationException::withMessages(['due_on' => [(string) trans('documents.requests.due_in_past')]]);
        }
        $title = isset($data['title']) && trim((string) $data['title']) !== '' ? (string) $data['title'] : $category->name;
        $open = DB::table('document_requests')->whereIn('employment_id', $employmentIds)->where('document_category_id', $category->id)
            ->whereIn('status', self::OPEN)->pluck('employment_id')->map(fn ($id): string => (string) $id)->all();

        return DB::transaction(function () use ($data, $employmentIds, $userId, $category, $due, $title, $open): array {
            $group = (string) Str::uuid();
            $created = [];
            $skipped = [];
            $people = DB::table('employments')->whereIn('id', $employmentIds)->pluck('person_id', 'id');
            foreach ($employmentIds as $employmentId) {
                if (in_array($employmentId, $open, true)) {
                    $skipped[] = ['employment_id' => $employmentId, 'reason' => (string) trans('documents.requests.already_open')];

                    continue;
                }
                $request = new DocumentRequest([
                    'employment_id' => $employmentId, 'person_id' => $people[$employmentId] ?? null, 'document_category_id' => $category->id,
                    'requested_by_user_id' => $userId, 'due_on' => $due, 'instructions' => $data['instructions'] ?? null, 'title' => $title,
                    'request_group_id' => $group,
                ]);
                $request->save();
                $request->refresh();
                $this->outbox->record(DomainEventTypes::DOCUMENT_REQUESTED, 'document_request', $request->id, [
                    'document_request_id' => $request->id, 'category_id' => $category->id, 'employment_id' => $employmentId, 'due_on' => $due,
                ]);
                $this->audit->record('document_request.created', 'document_request', $request->id, null, $this->auditable($request));
                $created[] = $request;
            }

            return ['created' => $created, 'skipped' => $skipped];
        });
    }

    /** El colaborador (o RRHH por él) entrega el documento: entra en revisión y empieza la aprobación. */
    public function submit(DocumentRequest $request, UploadedFile $file, ?string $issued, ?string $expires, ?string $userId, ?string $uploaderPerson): DocumentRequest
    {
        $next = $this->machine->getNextState($request->status, 'submit');

        return DB::transaction(function () use ($request, $file, $issued, $expires, $userId, $uploaderPerson, $next): DocumentRequest {
            $before = $this->auditable($request);
            $doc = $this->documents->upload(array_filter([
                'category_id' => $request->document_category_id, 'title' => $request->title, 'employment_id' => $request->employment_id,
                'issued_on' => $issued, 'expires_on' => $expires, 'visible_to_employee' => true, 'review' => true,
            ], fn ($v) => $v !== null), $file, $userId, $uploaderPerson, false);
            $this->transition($request, $next, ['document_id' => $doc->id, 'submitted_at' => now(), 'rejection_reason' => null, 'reviewed_at' => null, 'reviewed_by_user_id' => null]);
            $legalEntity = DB::table('employments')->where('id', $request->employment_id)->value('legal_entity_id');
            $this->approvals->start(self::SUBJECT_TYPE, $request->id, (string) $userId, [
                'legal_entity_id' => $legalEntity, 'category_id' => $request->document_category_id,
            ]);
            $this->audit->record('document_request.submitted', 'document_request', $request->id, $before, $this->auditable($request));

            return $request;
        });
    }

    public function cancel(DocumentRequest $request, ?string $reason): DocumentRequest
    {
        $next = $this->machine->getNextState($request->status, 'cancel');

        return DB::transaction(function () use ($request, $reason, $next): DocumentRequest {
            $before = $this->auditable($request);
            $approval = $request->status === DocumentRequestStateMachine::SUBMITTED ? $this->approvalId($request->id) : null;
            $this->transition($request, $next, ['cancellation_reason' => $reason]);
            if ($approval !== null) {
                $this->approvals->cancel($approval, $reason);
            }
            $this->audit->record('document_request.cancelled', 'document_request', $request->id, $before, $this->auditable($request), $reason);

            return $request;
        });
    }

    public function approvedByFlow(string $id): void
    {
        $request = DocumentRequest::query()->lockForUpdate()->find($id);
        if (! $request instanceof DocumentRequest || $request->status !== DocumentRequestStateMachine::SUBMITTED) {
            return;
        }
        $before = $this->auditable($request);
        $doc = $request->document_id === null ? null : Document::query()->find($request->document_id);
        if ($doc instanceof Document && $doc->status === 'pending_approval') {
            $this->documents->approve($doc, null);
        }
        $this->transition($request, DocumentRequestStateMachine::APPROVED, ['reviewed_at' => now(), 'reviewed_by_user_id' => $this->decider($id)]);
        $this->audit->record('document_request.approved', 'document_request', $request->id, $before, $this->auditable($request));
    }

    public function rejectedByFlow(string $id, ?string $reason): void
    {
        $request = DocumentRequest::query()->lockForUpdate()->find($id);
        if (! $request instanceof DocumentRequest || $request->status !== DocumentRequestStateMachine::SUBMITTED) {
            return;
        }
        $reason = $reason !== null && trim($reason) !== '' ? $reason : (string) trans('documents.requests.rejected_default');
        $before = $this->auditable($request);
        $doc = $request->document_id === null ? null : Document::query()->find($request->document_id);
        if ($doc instanceof Document && $doc->status === 'pending_approval') {
            // El colaborador recibe la observación (document.rejected) y la solicitud vuelve a él.
            $this->documents->reject($doc, $reason, null);
        }
        $this->transition($request, DocumentRequestStateMachine::REJECTED, ['rejection_reason' => $reason, 'reviewed_at' => now(), 'reviewed_by_user_id' => $this->decider($id)]);
        $this->audit->record('document_request.rejected', 'document_request', $request->id, $before, $this->auditable($request), $reason);
    }

    public function cancelledByFlow(string $id, ?string $reason): void
    {
        $request = DocumentRequest::query()->lockForUpdate()->find($id);
        if (! $request instanceof DocumentRequest || $request->status !== DocumentRequestStateMachine::SUBMITTED) {
            return;
        }
        $before = $this->auditable($request);
        $this->transition($request, DocumentRequestStateMachine::CANCELLED, ['cancellation_reason' => $reason]);
        $this->audit->record('document_request.cancelled', 'document_request', $request->id, $before, $this->auditable($request), $reason);
    }

    public function approvalId(string $requestId): ?string
    {
        $id = DB::table('workflow_instances')->where('subject_type', self::SUBJECT_TYPE)->where('subject_id', $requestId)
            ->orderByRaw("CASE status WHEN 'running' THEN 0 ELSE 1 END")->orderByDesc('started_at')->orderByDesc('id')->value('id');

        return $id === null ? null : (string) $id;
    }

    /** @return array{id: string, status: string, level: int|null, pending_approvers: list<string>}|null */
    public function approval(string $requestId): ?array
    {
        $id = $this->approvalId($requestId);

        return $id === null ? null : $this->approvals->summary($id);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function transition(DocumentRequest $request, string $next, array $extra): void
    {
        $affected = DB::table('document_requests')->where('id', $request->id)->where('lock_version', $request->lock_version)->update(['status' => $next] + $extra);
        if ($affected === 0) {
            throw new VersionConflictException($request->lock_version, (int) DB::table('document_requests')->where('id', $request->id)->value('lock_version'));
        }
        $request->refresh();
    }

    private function decider(string $requestId): ?string
    {
        $approval = $this->approvalId($requestId);
        if ($approval === null) {
            return null;
        }
        $user = DB::table('workflow_decisions as d')->join('workflow_tasks as t', 't.id', '=', 'd.workflow_task_id')
            ->where('t.workflow_instance_id', $approval)->orderByDesc('d.decided_at')->value('d.decided_by_user_id');

        return $user === null ? null : (string) $user;
    }

    private function category(string $id): DocumentCategory
    {
        $this->catalog->ensureDefaults();
        $c = preg_match('/^[0-9a-f-]{36}$/i', $id) === 1 ? DocumentCategory::query()->where('status', 'active')->whereNotNull('parent_id')->find($id) : null;
        if (! $c instanceof DocumentCategory || $this->catalog->folder($c)->code === 'contracts') {
            throw ValidationException::withMessages(['category_id' => [(string) trans('documents.requests.type_unknown')]]);
        }

        return $c;
    }

    /**
     * @return array<string, mixed>
     */
    private function auditable(DocumentRequest $r): array
    {
        return ['employment_id' => $r->employment_id, 'category_id' => $r->document_category_id, 'title' => $r->title, 'status' => $r->status,
            'due_on' => $r->dueOn(), 'document_id' => $r->document_id, 'rejection_reason' => $r->rejection_reason];
    }
}
