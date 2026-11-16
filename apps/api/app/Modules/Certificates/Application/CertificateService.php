<?php

namespace App\Modules\Certificates\Application;

use App\Modules\Certificates\Domain\CertificateRequestStateMachine;
use App\Modules\Certificates\Infrastructure\Models\CertificateRequest;
use App\Shared\Audit\AuditLogger;
use App\Shared\Documents\CertificateTemplates;
use App\Shared\Documents\DocumentArchive;
use App\Shared\Documents\DocumentTypes;
use App\Shared\Documents\SimplePdf;
use App\Shared\Exceptions\AyniException;
use App\Shared\Exceptions\BusinessRuleViolatedException;
use App\Shared\Notifications\Notifier;
use App\Shared\Permissions\EffectivePermissions;
use App\Shared\Permissions\ModuleGate;
use App\Shared\Settings\ParameterResolver;
use App\Shared\Workflow\ApprovalRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ESS-4 · Solicitud y emisión de constancias.
 *
 * - Catálogo: trabajo y vacaciones (esta con el módulo de vacaciones contratado) para todo colaborador;
 *   remuneraciones solo si quien pide ve su propio sueldo (people.employments.salary.view), porque la
 *   constancia lo muestra.
 * - Las de constancias.emision_automatica se emiten al instante, sin intervención de RRHH.
 * - Las demás entran al flujo de aprobación de certificate_request si hay uno publicado; si no, esperan a
 *   RRHH, que las emite o las rechaza (people.certificates.issue).
 * - Cada emisión deja el PDF en el legajo (tipo «Constancia de trabajo», visible para el colaborador) y
 *   la solicitud apunta al documento.
 */
final class CertificateService
{
    public const SUBJECT_TYPE = 'certificate_request';

    public const KINDS = ['employment_certificate', 'vacation_certificate', 'income_certificate'];

    /** Tipo documental del legajo en el que queda la constancia emitida (carpeta Certificados, DOC-1). */
    private const DOCUMENT_TYPE = 'work_certificate';

    private const AUTO_PARAMETER = 'constancias.emision_automatica';

    public function __construct(
        private readonly CertificateRequestStateMachine $machine,
        private readonly CertificateContent $content,
        private readonly CertificateTemplates $templates,
        private readonly DocumentArchive $archive,
        private readonly DocumentTypes $documentTypes,
        private readonly ParameterResolver $parameters,
        private readonly ApprovalRequests $approvals,
        private readonly Notifier $notifier,
        private readonly ModuleGate $modules,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return list<array{kind: string, name: string, description: string, auto_issue: bool}>
     */
    public function types(EffectivePermissions $permissions): array
    {
        $auto = $this->autoKinds();
        $out = [];
        foreach (self::KINDS as $kind) {
            if (! $this->available($kind, $permissions)) {
                continue;
            }
            $out[] = [
                'kind' => $kind,
                'name' => (string) trans('certificates.kinds.'.$kind),
                'description' => (string) trans('certificates.descriptions.'.$kind),
                'auto_issue' => in_array($kind, $auto, true),
            ];
        }

        return $out;
    }

    /**
     * @param  array{employment_id: string, kind: string, purpose?: string|null}  $data
     */
    public function request(array $data, string $userId, EffectivePermissions $permissions): CertificateRequest
    {
        $kind = $data['kind'];
        if (! $this->available($kind, $permissions)) {
            throw new AyniException('kind_not_available', 422, (string) trans('certificates.kind_not_available'));
        }
        $auto = in_array($kind, $this->autoKinds(), true);

        $request = DB::transaction(function () use ($data, $userId, $kind, $auto): CertificateRequest {
            $r = CertificateRequest::create([
                'employment_id' => $data['employment_id'], 'kind' => $kind, 'purpose' => $data['purpose'] ?? null,
                'requested_by_user_id' => $userId, 'status' => CertificateRequestStateMachine::REQUESTED,
            ]);
            $this->audit->record('certificate_request.requested', 'certificate_request', $r->id, null, ['kind' => $kind, 'employment_id' => $r->employment_id]);

            if ($auto) {
                $this->emit($r, 'issue', $userId, true);

                return $r;
            }
            $this->routeToApproval($r, $userId);

            return $r;
        });

        if ($request->status === CertificateRequestStateMachine::REQUESTED) {
            $this->notifyHr($request);
        }

        return $request;
    }

    public function issue(CertificateRequest $r, string $userId): CertificateRequest
    {
        return DB::transaction(function () use ($r, $userId): CertificateRequest {
            $locked = $this->lock($r);
            $this->emit($locked, 'issue', $userId, false);
            $this->notifyRequester($locked, 'certificates.issued', []);

            return $locked;
        });
    }

    public function reject(CertificateRequest $r, string $reason, string $userId): CertificateRequest
    {
        return DB::transaction(function () use ($r, $reason, $userId): CertificateRequest {
            $locked = $this->lock($r);
            $this->decline($locked, 'reject', $reason, $userId);

            return $locked;
        });
    }

    /** El flujo aprobó: se emite la constancia en la misma transacción que la aprobación (idempotente). */
    public function approvedByFlow(string $id): void
    {
        $r = CertificateRequest::query()->lockForUpdate()->find($id);
        if (! $r instanceof CertificateRequest || $r->status !== CertificateRequestStateMachine::IN_PROGRESS) {
            return;
        }
        $this->emit($r, 'approve', null, false);
        $this->notifyRequester($r, 'certificates.issued', []);
    }

    public function rejectedByFlow(string $id, ?string $reason): void
    {
        $r = CertificateRequest::query()->lockForUpdate()->find($id);
        if (! $r instanceof CertificateRequest || $r->status !== CertificateRequestStateMachine::IN_PROGRESS) {
            return;
        }
        $this->decline($r, 'decline', $reason ?? '', null);
    }

    /** El flujo se anuló: la solicitud vuelve a esperar a RRHH. */
    public function cancelledByFlow(string $id): void
    {
        $r = CertificateRequest::query()->lockForUpdate()->find($id);
        if (! $r instanceof CertificateRequest || $r->status !== CertificateRequestStateMachine::IN_PROGRESS) {
            return;
        }
        $r->status = $this->machine->getNextState($r->status, 'withdraw');
        $r->save();
        $this->audit->record('certificate_request.returned', 'certificate_request', $r->id, ['status' => CertificateRequestStateMachine::IN_PROGRESS], ['status' => $r->status]);
    }

    /**
     * @return array<string, mixed>
     */
    public function present(CertificateRequest $r, EffectivePermissions $permissions): array
    {
        $employment = DB::table('employments as e')->join('people as p', 'p.id', '=', 'e.person_id')->where('e.id', $r->employment_id)
            ->first(['e.employee_code', 'p.first_names', 'p.preferred_name', 'p.paternal_surname']);
        $approval = $r->workflow_instance_id === null ? null : $this->approvals->summary($r->workflow_instance_id);

        return [
            'id' => $r->id,
            'employment_id' => $r->employment_id,
            'employee_code' => $employment?->employee_code,
            'person_name' => $employment === null ? null : trim(($employment->preferred_name ?: $employment->first_names).' '.$employment->paternal_surname),
            'kind' => $r->kind,
            'kind_name' => (string) trans('certificates.kinds.'.$r->kind),
            'purpose' => $r->purpose,
            'status' => $r->status,
            'auto_issued' => $r->auto_issued,
            'document_id' => $r->document_id,
            'issued_at' => $r->issued_at?->toIso8601String(),
            'decided_at' => $r->decided_at?->toIso8601String(),
            'rejection_reason' => $r->rejection_reason,
            'approval' => $approval,
            'created_at' => $r->created_at?->toIso8601String(),
            'updated_at' => $r->updated_at?->toIso8601String(),
            'version' => $r->lock_version,
            'allowed_actions' => $permissions->has('people.certificates.issue') ? $this->machine->hrActions($r->status) : [],
        ];
    }

    /** @return list<string> */
    private function autoKinds(): array
    {
        $value = $this->parameters->value(self::AUTO_PARAMETER);

        return is_array($value) ? array_values(array_map('strval', $value)) : [];
    }

    private function available(string $kind, EffectivePermissions $permissions): bool
    {
        return match ($kind) {
            'employment_certificate' => true,
            'vacation_certificate' => $this->modules->isEnabled('time_off'),
            'income_certificate' => $permissions->has('people.employments.salary.view'),
            default => false,
        };
    }

    private function routeToApproval(CertificateRequest $r, string $userId): void
    {
        try {
            $approvalId = DB::transaction(fn (): string => $this->approvals->start(self::SUBJECT_TYPE, $r->id, $userId, [
                'legal_entity_id' => DB::table('employments')->where('id', $r->employment_id)->value('legal_entity_id'),
                'employment_id' => $r->employment_id,
                'kind' => $r->kind,
            ]));
        } catch (BusinessRuleViolatedException $e) {
            if (($e->getMeta()['rule'] ?? null) === 'RN-WFL-01') {
                // Sin flujo publicado para constancias: la solicitud espera a RRHH.
                return;
            }
            throw $e;
        }
        $r->refresh();
        if ($r->status === CertificateRequestStateMachine::REQUESTED) {
            $r->status = $this->machine->getNextState($r->status, 'submit');
            $r->workflow_instance_id = $approvalId;
            $r->save();
        }
    }

    private function emit(CertificateRequest $r, string $action, ?string $userId, bool $auto): void
    {
        $before = $r->status;
        $next = $this->machine->getNextState($r->status, $action);
        $locale = $this->locale($r->requested_by_user_id);
        $built = $this->content->build($r->kind, $r->employment_id, $r->purpose, $locale, $userId ?? $r->requested_by_user_id);
        $rendered = $this->templates->render($r->kind, $built['variables'], $locale);
        $contents = $rendered['contents'] ?? SimplePdf::render($built['title'], $built['body'], $built['footer']);
        $mime = $rendered['mime'] ?? 'application/pdf';
        $extension = $rendered['extension'] ?? 'pdf';

        $employment = DB::table('employments')->where('id', $r->employment_id)->first(['person_id', 'legal_entity_id', 'employee_code']);
        $title = trans('certificates.kinds.'.$r->kind, [], $locale).' · '.now()->toDateString();
        $documentId = $this->archive->create([
            'category_id' => $this->documentType(), 'title' => $title, 'employment_id' => $r->employment_id,
            'person_id' => $employment?->person_id, 'legal_entity_id' => $employment?->legal_entity_id,
            'subject_type' => self::SUBJECT_TYPE, 'subject_id' => $r->id, 'source' => 'generated', 'status' => 'active',
            'issued_on' => now()->toDateString(),
            // La de remuneraciones muestra el sueldo: queda confidencial (la ve el titular y quien tiene el permiso).
            'classification' => $r->kind === 'income_certificate' ? 'confidential' : 'internal', 'visible_to_employee' => true,
        ], $contents, $r->kind.'-'.($employment->employee_code ?? $r->id).'-'.now()->format('Ymd').'.'.$extension, $mime, $userId);

        $r->forceFill([
            'status' => $next, 'document_id' => $documentId, 'issued_at' => now(), 'auto_issued' => $auto,
            'decided_by_user_id' => $userId, 'decided_at' => now(),
        ])->save();
        $this->audit->record('certificate_request.issued', 'certificate_request', $r->id, ['status' => $before], [
            'status' => $next, 'document_id' => $documentId, 'auto_issued' => $auto,
        ]);
    }

    private function decline(CertificateRequest $r, string $action, string $reason, ?string $userId): void
    {
        $before = $r->status;
        $next = $this->machine->getNextState($r->status, $action);
        if ($action === 'reject' && trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => [(string) trans('certificates.reason_required')]]);
        }
        $r->forceFill(['status' => $next, 'rejection_reason' => $reason === '' ? null : $reason, 'decided_by_user_id' => $userId, 'decided_at' => now()])->save();
        $this->audit->record('certificate_request.rejected', 'certificate_request', $r->id, ['status' => $before], ['status' => $next, 'rejection_reason' => $r->rejection_reason]);
        $this->notifyRequester($r, 'certificates.rejected', ['reason' => (string) ($r->rejection_reason ?? '')]);
    }

    private function lock(CertificateRequest $r): CertificateRequest
    {
        $locked = CertificateRequest::query()->lockForUpdate()->find($r->id);
        abort_unless($locked instanceof CertificateRequest, 404);

        return $locked;
    }

    private function documentType(): string
    {
        $id = $this->documentTypes->systemType(self::DOCUMENT_TYPE);

        return is_string($id) ? $id : $this->archive->category(self::DOCUMENT_TYPE, (string) trans('certificates.document_type', [], 'es'), 'people.certificates.view');
    }

    private function locale(?string $userId): string
    {
        $locale = $userId === null ? null : DB::table('users')->where('id', $userId)->value('locale');

        return is_string($locale) && in_array($locale, ['es', 'en', 'pt'], true) ? $locale : 'es';
    }

    /**
     * @param  array<string, string>  $extra
     */
    private function notifyRequester(CertificateRequest $r, string $category, array $extra): void
    {
        if ($r->requested_by_user_id === null) {
            return;
        }
        $this->notifier->notify($category, [$r->requested_by_user_id], [
            'certificate' => (string) trans('certificates.kinds.'.$r->kind),
        ] + $extra, 'certificate_request', $r->id);
    }

    /** Sin flujo: avisa a RRHH que hay una constancia por emitir (además, queda en su bandeja). */
    private function notifyHr(CertificateRequest $r): void
    {
        $role = DB::table('roles')->where('system_role_code', 'rrhh')->whereNull('deleted_at')->value('id');
        if (! is_string($role)) {
            return;
        }
        $users = $this->notifier->usersWithRole($role);
        if ($users === []) {
            return;
        }
        $employee = $this->present($r, new EffectivePermissions([], [], []))['person_name'] ?? '';
        $this->notifier->notify('certificates.requested', $users, [
            'certificate' => (string) trans('certificates.kinds.'.$r->kind), 'employee' => (string) $employee,
        ], 'certificate_request', $r->id);
    }
}
