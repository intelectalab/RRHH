<?php

namespace App\Modules\Documents\Application;

use App\Modules\Documents\Domain\SignatureRequestStateMachine as M;
use App\Modules\Documents\Infrastructure\Models\SignatureRequest;
use App\Modules\Documents\Infrastructure\Models\SignatureRequestSigner;
use App\Shared\Audit\AuditLogger;
use App\Shared\Documents\DocumentArchive;
use App\Shared\Documents\SimplePdf;
use App\Shared\Exceptions\AyniException;
use App\Shared\Files\FileService;
use App\Shared\Integrations\IntegrationManager;
use App\Shared\Outbox\Contracts\OutboxWriter;
use App\Shared\Outbox\DomainEventTypes;
use App\Shared\Security\StepUpCodes;
use App\Shared\Settings\ParameterResolver;
use App\Shared\Signatures\SignatureRequests;
use App\Shared\Signatures\SignatureSubjectRegistry;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * DOC-6 · DOC-7 · DOC-8 · Flujo de firma común (ME-10) para cualquier documento del legajo: firmantes y orden,
 * envío, visto, firma con código de un solo uso, rechazo con motivo, recordatorios, vencimiento y cierre con
 * constancia. ADR 0019: la firma es electrónica propia (evidencia OTP, IP, dispositivo, huella y sello de tiempo)
 * salvo en los tipos documentales de `firma.tipos_con_certificado`, que firman con certificado por el puerto del
 * proveedor (SignaturePort, falso en desarrollo). El flujo no cambia con el nivel: solo el paso de firma y la
 * evidencia que se guarda.
 */
final class SignatureService implements SignatureRequests
{
    public const OTP_PURPOSE = 'signature_otp';

    public function __construct(
        private readonly M $machine,
        private readonly SignatureEvidence $evidence,
        private readonly DocumentArchive $archive,
        private readonly FileService $files,
        private readonly IntegrationManager $integrations,
        private readonly StepUpCodes $codes,
        private readonly ParameterResolver $parameters,
        private readonly SignatureSubjectRegistry $subjects,
        private readonly AuditLogger $audit,
        private readonly OutboxWriter $outbox,
    ) {}

    /**
     * Crea la solicitud en Borrador (y la envía si se pide). Firmantes: usuarios activos de la organización, en
     * el orden indicado.
     *
     * @param  array{document_id: string, title?: string|null, signing_order?: string|null, signers: list<array{user_id: string, role?: string|null}>, send?: bool}  $input
     */
    public function create(array $input, ?string $userId): SignatureRequest
    {
        $document = DB::table('documents as d')->join('document_categories as c', 'c.id', '=', 'd.document_category_id')
            ->where('d.id', $input['document_id'])->whereNull('d.deleted_at')
            ->first(['d.id', 'd.title', 'd.current_version_id', 'd.subject_type', 'd.subject_id', 'c.code as category_code']);
        if ($document === null || $document->current_version_id === null) {
            throw new AyniException('not_found', 404);
        }
        $this->subjects->for($document->subject_type === null ? null : (string) $document->subject_type)?->assertCanSend((string) $document->subject_id);
        if (SignatureRequest::query()->where('document_id', $document->id)->whereIn('status', array_merge([M::DRAFT], M::OPEN))->exists()) {
            throw new AyniException('signature_in_progress', 409, (string) trans('documents.signatures.in_progress'));
        }
        $signers = $this->signers($input['signers']);
        $withCertificate = in_array((string) $document->category_code, $this->certificateTypes(), true);
        $hash = (string) DB::table('document_versions')->where('id', $document->current_version_id)->value('content_hash');

        $title = trim((string) ($input['title'] ?? ''));

        $request = DB::transaction(function () use ($input, $title, $document, $signers, $withCertificate, $hash, $userId): SignatureRequest {
            $request = SignatureRequest::query()->create([
                'document_id' => $document->id, 'document_version_id' => $document->current_version_id,
                'title' => $title !== '' ? $title : (string) $document->title,
                'signing_order' => ($input['signing_order'] ?? 'sequential') === 'parallel' ? 'parallel' : 'sequential',
                'signature_level' => $withCertificate ? 'digital_certificate' : 'advanced',
                'provider' => $withCertificate ? 'external' : 'internal',
                'status' => M::DRAFT, 'requested_by_user_id' => $userId,
            ]);
            foreach ($signers as $i => $s) {
                SignatureRequestSigner::query()->create([
                    'signature_request_id' => $request->id, 'user_id' => $s['user_id'], 'person_id' => $s['person_id'],
                    'signer_name' => $s['name'], 'signer_role' => $s['role'], 'sort_order' => $i + 1, 'status' => 'pending',
                ]);
            }
            $this->evidence->record($request->tenant_id, $request->id, 'created', $hash);
            $this->audit->record('signature_request.created', 'signature_request', $request->id, null, [
                'document_id' => $request->document_id, 'signature_level' => $request->signature_level, 'signers' => count($signers),
            ]);

            return $request;
        });

        return ($input['send'] ?? false) === true ? $this->send($request, $userId) : $request;
    }

    /** Borrador → Enviado: fija el vencimiento, abre el sobre del proveedor si corresponde y avisa a quien firma primero. */
    public function send(SignatureRequest $request, ?string $userId): SignatureRequest
    {
        $this->machine->getNextState($request->status, 'send');

        return DB::transaction(function () use ($request): SignatureRequest {
            $locked = $this->lock($request->id);
            $next = $this->machine->getNextState($locked->status, 'send');
            $hash = $this->versionHash($locked);
            if ($locked->provider === 'external') {
                $locked->provider_envelope_id = $this->integrations->signature()->createSignatureRequest($locked->document_id,
                    $locked->signers->map(fn (SignatureRequestSigner $s) => ['name' => $s->signer_name, 'email' => '', 'order' => $s->sort_order])->values()->all());
            }
            $days = max(1, (int) ($this->parameters->value('firma.vigencia_dias') ?? 15));
            $locked->status = $next;
            $locked->sent_at = now();
            $locked->expires_at = now()->addDays($days)->endOfDay();
            $locked->save();
            $notified = $this->openTurn($locked);
            $this->evidence->record($locked->tenant_id, $locked->id, 'sent', $hash);
            $this->outbox->record(DomainEventTypes::SIGNATURE_SENT, 'signature_request', $locked->id, [
                'signature_request_id' => $locked->id, 'document_id' => $locked->document_id, 'title' => (string) $locked->title, 'user_ids' => $notified,
            ]);
            $this->audit->record('signature_request.sent', 'signature_request', $locked->id, ['status' => M::DRAFT], ['status' => $next, 'expires_at' => $locked->expires_at->toIso8601String()]);

            return $locked;
        });
    }

    /** El firmante abre el documento: queda registrado con fecha, hora e IP. */
    public function view(SignatureRequest $request, string $userId, ?string $ip, ?string $agent): SignatureRequest
    {
        return DB::transaction(function () use ($request, $userId, $ip, $agent): SignatureRequest {
            $locked = $this->lock($request->id);
            $next = $this->machine->getNextState($locked->status, 'view');
            $signer = $this->signerOf($locked, $userId);
            if ($signer->viewed_at === null) {
                $signer->viewed_at = now();
                if (in_array($signer->status, ['pending', 'sent'], true)) {
                    $signer->status = 'viewed';
                }
                $signer->save();
                $locked->status = $next;
                $locked->save();
                $this->evidence->record($locked->tenant_id, $locked->id, 'viewed', $this->versionHash($locked), ['signer_id' => $signer->id, 'ip' => $ip, 'user_agent' => $agent]);
            }

            return $locked;
        });
    }

    /**
     * Envía el código de un solo uso al firmante de turno (DOC-7: la firma exige OTP).
     *
     * @return array{channel: string, expires_at: string}
     */
    public function sendCode(SignatureRequest $request, string $userId, ?string $ip, ?string $agent): array
    {
        $this->machine->getNextState($request->status, 'sign');
        $signer = $this->signerOf($request, $userId);
        $this->assertTurn($request, $signer);
        $sent = $this->codes->send($userId, self::OTP_PURPOSE);
        if ($sent === null) {
            throw ValidationException::withMessages(['channel' => [(string) trans('documents.signatures.no_channel')]]);
        }
        DB::transaction(function () use ($request, $signer, $sent, $ip, $agent): void {
            $locked = $this->lock($request->id);
            $this->evidence->record($locked->tenant_id, $locked->id, 'otp_sent', $this->versionHash($locked), [
                'signer_id' => $signer->id, 'ip' => $ip, 'user_agent' => $agent, 'otp_channel' => $sent['channel'],
                'otp_reference_hash' => hash('sha256', $sent['reference']),
            ]);
        });

        return ['channel' => $sent['channel'], 'expires_at' => $sent['expires_at']];
    }

    /**
     * Firma del firmante de turno: verifica el código, comprueba que el documento no cambió, guarda la evidencia
     * (y la del proveedor con certificado) y pasa el turno. Con la última firma, la solicitud se completa.
     */
    public function sign(SignatureRequest $request, string $userId, string $code, ?string $ip, ?string $agent): SignatureRequest
    {
        $this->machine->getNextState($request->status, 'sign');
        $signer = $this->signerOf($request, $userId);
        $this->assertTurn($request, $signer);
        if (! $this->codes->verify($userId, self::OTP_PURPOSE, $code)) {
            throw ValidationException::withMessages(['code' => [(string) trans('documents.signatures.invalid_code')]]);
        }

        return DB::transaction(function () use ($request, $userId, $ip, $agent): SignatureRequest {
            $locked = $this->lock($request->id);
            $next = $this->machine->getNextState($locked->status, 'sign');
            $signer = $this->signerOf($locked, $userId);
            $hash = $this->versionHash($locked);
            if (! $this->documentIntact($locked)) {
                throw new AyniException('document_changed', 409, (string) trans('documents.signatures.document_changed'));
            }
            $providerEvidence = null;
            if ($locked->provider === 'external') {
                $providerEvidence = $this->integrations->signature()->sign((string) $locked->provider_envelope_id,
                    ['name' => $signer->signer_name, 'user_id' => $signer->user_id], $hash);
            }
            $context = ['signer_id' => $signer->id, 'ip' => $ip, 'user_agent' => $agent];
            $this->evidence->record($locked->tenant_id, $locked->id, 'otp_verified', $hash, $context);
            $this->evidence->record($locked->tenant_id, $locked->id, 'signed', $hash, $context + ['provider_evidence' => $providerEvidence]);
            $signer->status = 'signed';
            $signer->signed_at = now();
            $signer->viewed_at ??= now();
            $signer->save();
            $locked->status = $next;
            $locked->save();
            $this->audit->record('signature_request.signed', 'signature_request', $locked->id, null, ['signer_id' => $signer->id, 'signature_level' => $locked->signature_level]);
            $locked->unsetRelation('signers');
            if ($locked->signers->every(fn (SignatureRequestSigner $s) => $s->status === 'signed')) {
                $this->complete($locked, $hash);
            } else {
                $notified = $this->openTurn($locked);
                if ($notified !== []) {
                    $this->outbox->record(DomainEventTypes::SIGNATURE_SENT, 'signature_request', $locked->id, [
                        'signature_request_id' => $locked->id, 'document_id' => $locked->document_id, 'title' => (string) $locked->title, 'user_ids' => $notified,
                    ]);
                }
            }

            return $locked;
        });
    }

    /** Un firmante rechaza con motivo: la solicitud termina y el dueño del documento decide qué hacer. */
    public function decline(SignatureRequest $request, string $userId, string $reason, ?string $ip, ?string $agent): SignatureRequest
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            throw ValidationException::withMessages(['reason' => [(string) trans('documents.signatures.reason_required')]]);
        }

        return DB::transaction(function () use ($request, $userId, $reason, $ip, $agent): SignatureRequest {
            $locked = $this->lock($request->id);
            $next = $this->machine->getNextState($locked->status, 'decline');
            $signer = $this->signerOf($locked, $userId);
            if ($signer->status === 'signed') {
                throw new AyniException('invalid_transition', 409, (string) trans('documents.signatures.already_signed'));
            }
            $signer->status = 'declined';
            $signer->declined_reason = $reason;
            $signer->save();
            $locked->status = $next;
            $locked->save();
            $this->evidence->record($locked->tenant_id, $locked->id, 'declined', $this->versionHash($locked), ['signer_id' => $signer->id, 'ip' => $ip, 'user_agent' => $agent]);
            $subject = $this->subjectOf($locked);
            if ($subject !== null) {
                $this->subjects->for($subject[0])?->declined($subject[1], $reason, $userId);
            }
            $this->outbox->record(DomainEventTypes::SIGNATURE_DECLINED, 'signature_request', $locked->id, [
                'signature_request_id' => $locked->id, 'title' => (string) $locked->title, 'signer' => $signer->signer_name,
                'reason' => $reason, 'user_ids' => $locked->requested_by_user_id === null ? [] : [$locked->requested_by_user_id],
            ]);
            $this->audit->record('signature_request.declined', 'signature_request', $locked->id, null, ['status' => $next, 'signer_id' => $signer->id], $reason);

            return $locked;
        });
    }

    public function cancel(SignatureRequest $request, string $reason, ?string $userId): SignatureRequest
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            throw ValidationException::withMessages(['reason' => [(string) trans('documents.signatures.reason_required')]]);
        }

        return DB::transaction(function () use ($request, $reason): SignatureRequest {
            $locked = $this->lock($request->id);
            $before = $locked->status;
            $locked->status = $this->machine->getNextState($locked->status, 'cancel');
            $locked->cancellation_reason = $reason;
            $locked->save();
            $this->evidence->record($locked->tenant_id, $locked->id, 'cancelled', $this->versionHash($locked));
            $this->audit->record('signature_request.cancelled', 'signature_request', $locked->id, ['status' => $before], ['status' => $locked->status], $reason);

            return $locked;
        });
    }

    /** Recuerda a quien tiene el turno (manual o por la tarea diaria). Devuelve a cuántos se avisó. */
    public function remind(SignatureRequest $request, ?string $userId): int
    {
        $this->machine->getNextState($request->status, 'remind');

        return DB::transaction(function () use ($request): int {
            $locked = $this->lock($request->id);
            $ids = [];
            foreach ($this->turn($locked) as $s) {
                $s->last_reminded_at = now();
                $s->save();
                if ($s->user_id !== null) {
                    $ids[] = $s->user_id;
                }
            }
            if ($ids !== []) {
                $this->outbox->record(DomainEventTypes::SIGNATURE_SENT, 'signature_request', $locked->id, [
                    'signature_request_id' => $locked->id, 'document_id' => $locked->document_id, 'title' => (string) $locked->title, 'user_ids' => $ids, 'reminder' => true,
                ]);
                $this->audit->record('signature_request.reminded', 'signature_request', $locked->id, null, ['signers' => count($ids)]);
            }

            return count($ids);
        });
    }

    /**
     * Tarea diaria: vence lo que pasó su fecha y recuerda a quien tiene el turno según `firma.recordatorio_dias`.
     *
     * @return array{expired: int, reminded: int}
     */
    public function advance(): array
    {
        $counts = ['expired' => 0, 'reminded' => 0];
        foreach (SignatureRequest::query()->whereIn('status', M::OPEN)->where('expires_at', '<', now())->get() as $r) {
            DB::transaction(function () use ($r, &$counts): void {
                $locked = $this->lock($r->id);
                $locked->status = $this->machine->getNextState($locked->status, 'expire');
                $locked->save();
                $this->evidence->record($locked->tenant_id, $locked->id, 'expired', $this->versionHash($locked));
                $this->audit->record('signature_request.expired', 'signature_request', $locked->id, null, ['status' => $locked->status]);
                $counts['expired']++;
            });
        }
        $every = (int) ($this->parameters->value('firma.recordatorio_dias') ?? 3);
        if ($every > 0) {
            foreach (SignatureRequest::query()->whereIn('status', M::OPEN)->where('sent_at', '<', now()->subDays($every))->with('signers')->get() as $r) {
                $due = collect($this->turn($r))->contains(fn (SignatureRequestSigner $s) => $s->last_reminded_at === null || $s->last_reminded_at < now()->subDays($every));
                if ($due) {
                    $counts['reminded'] += $this->remind($r, null) > 0 ? 1 : 0;
                }
            }
        }

        return $counts;
    }

    /**
     * Evidencia completa: cadena de eventos, integridad del archivo firmado y del encadenado.
     *
     * @return array{chain_valid: bool, document_intact: bool, document_hash: string, final_hash: string|null, events: list<array<string, mixed>>}
     */
    public function evidenceOf(SignatureRequest $request): array
    {
        $chain = $this->evidence->chain($request->id);

        return ['chain_valid' => $chain['chain_valid'], 'document_intact' => $this->documentIntact($request),
            'document_hash' => $this->versionHash($request), 'final_hash' => $request->final_hash, 'events' => $chain['events']];
    }

    /**
     * Archivo de la versión que se firma (lo que ve el firmante).
     *
     * @return array{name: string, mime: string, contents: string}
     */
    public function signedVersion(SignatureRequest $request, ?string $userId): array
    {
        $fileId = (string) DB::table('document_versions')->where('id', $request->document_version_id)->value('stored_file_id');

        return $this->files->contentsForDownload($fileId, null, $userId);
    }

    /** @return array{name: string, mime: string, contents: string}|null */
    public function certificate(SignatureRequest $request, ?string $userId): ?array
    {
        return $request->final_file_id === null ? null : $this->files->contentsForDownload($request->final_file_id, null, $userId);
    }

    public function sendForEmployeeAndRepresentative(string $documentId, string $title, string $representativeUserId): string
    {
        $employmentId = DB::table('documents')->where('id', $documentId)->value('employment_id');
        $personId = $employmentId === null ? null : DB::table('employments')->where('id', $employmentId)->value('person_id');
        $employeeUser = $personId === null ? null : DB::table('users')->where('person_id', $personId)->whereNull('deleted_at')->value('id');
        $signers = [];
        if (is_string($employeeUser)) {
            $signers[] = ['user_id' => $employeeUser, 'role' => 'employee'];
        }
        if ($employeeUser !== $representativeUserId) {
            $signers[] = ['user_id' => $representativeUserId, 'role' => 'employer'];
        }

        return $this->create(['document_id' => $documentId, 'title' => $title, 'signers' => $signers, 'send' => true], $representativeUserId)->id;
    }

    public function progress(array $ids): array
    {
        $out = [];
        foreach (SignatureRequest::query()->whereIn('id', $ids === [] ? ['00000000-0000-0000-0000-000000000000'] : $ids)->with('signers')->get() as $r) {
            $out[$r->id] = ['status' => $r->status, 'signed' => $r->signers->where('status', 'signed')->count(), 'total' => $r->signers->count()];
        }

        return $out;
    }

    /** Firmantes de turno: en orden secuencial, el primero que no firmó; en paralelo, todos los que faltan. */
    /** @return list<SignatureRequestSigner> */
    public function turn(SignatureRequest $request): array
    {
        $pending = $request->signers->filter(fn (SignatureRequestSigner $s) => ! in_array($s->status, ['signed', 'declined'], true))->values();
        if ($pending->isEmpty()) {
            return [];
        }

        return $request->signing_order === 'parallel' ? array_values($pending->all()) : [$pending->first()];
    }

    /** ¿El archivo de la versión firmada sigue teniendo la huella registrada al crear la solicitud? */
    public function documentIntact(SignatureRequest $request): bool
    {
        $version = DB::table('document_versions')->where('id', $request->document_version_id)->first(['stored_file_id', 'content_hash']);
        $created = DB::table('signature_events')->where('signature_request_id', $request->id)->where('event', 'created')->value('document_hash');
        if ($version === null || ! is_string($created) || ! hash_equals($created, (string) $version->content_hash)) {
            return false;
        }
        try {
            $contents = $this->files->contentsForDownload((string) $version->stored_file_id)['contents'];
        } catch (\Throwable) {
            return false;
        }

        return hash_equals($created, hash('sha256', $contents));
    }

    private function complete(SignatureRequest $locked, string $hash): void
    {
        $locked->status = $this->machine->getNextState($locked->status, 'complete');
        $locked->completed_at = now();
        $lastId = $this->evidence->record($locked->tenant_id, $locked->id, 'completed', $hash);
        $locked->final_hash = (string) DB::table('signature_events')->where('id', $lastId)->value('event_hash');
        $pdf = $this->constancia($locked, $hash);
        $name = (string) trans('documents.signatures.certificate_file').'.pdf';
        $subject = $this->subjectOf($locked);
        $handler = $subject === null ? null : $this->subjects->for($subject[0]);
        if ($handler !== null) {
            $handler->completed($subject[1], $pdf, $name, now()->toDateString(), $locked->requested_by_user_id);
        } else {
            $this->archive->addVersion($locked->document_id, $pdf, $name, 'application/pdf', $locked->requested_by_user_id,
                (string) trans('documents.signatures.signed_version'), 'active', 'signature');
        }
        $locked->final_file_id = (string) DB::table('documents as d')->join('document_versions as v', 'v.id', '=', 'd.current_version_id')
            ->where('d.id', $locked->document_id)->value('v.stored_file_id');
        $locked->save();
        $this->outbox->record(DomainEventTypes::SIGNATURE_COMPLETED, 'signature_request', $locked->id, [
            'signature_request_id' => $locked->id, 'completed_at' => $locked->completed_at->toIso8601String(), 'document_id' => $locked->document_id,
            'title' => (string) $locked->title, 'user_ids' => $locked->requested_by_user_id === null ? [] : [$locked->requested_by_user_id],
        ]);
        $this->audit->record('signature_request.completed', 'signature_request', $locked->id, null, ['status' => $locked->status, 'final_hash' => $locked->final_hash]);
    }

    /** Constancia de firma: el documento, su huella y la evidencia de cada firmante (DOC-7). */
    private function constancia(SignatureRequest $r, string $hash): string
    {
        $lines = [
            (string) trans('documents.signatures.pdf.document', ['title' => (string) $r->title]),
            (string) trans('documents.signatures.pdf.hash', ['hash' => $hash]),
            (string) trans('documents.signatures.pdf.level', ['level' => (string) trans('documents.signatures.levels.'.$r->signature_level)]),
            '',
        ];
        $signed = DB::table('signature_events')->where('signature_request_id', $r->id)->where('event', 'signed')->get()->keyBy('signer_id');
        $otp = DB::table('signature_events')->where('signature_request_id', $r->id)->where('event', 'otp_sent')->orderByDesc('occurred_at')->get()->keyBy('signer_id');
        foreach ($r->signers as $s) {
            $e = $signed[$s->id] ?? null;
            $provider = $e === null || $e->provider_evidence === null ? null : json_decode((string) $e->provider_evidence, true);
            $lines[] = (string) trans('documents.signatures.pdf.signer', [
                'name' => $s->signer_name, 'role' => (string) ($s->signer_role ?? ''), 'at' => (string) ($e->timestamp_token ?? ''),
                'ip' => (string) ($e->ip ?? ''), 'channel' => (string) ($otp[$s->id]->otp_channel ?? ''),
            ]);
            if (is_array($provider)) {
                $lines[] = (string) trans('documents.signatures.pdf.certificate', ['serial' => (string) ($provider['certificate_serial'] ?? ''), 'issuer' => (string) ($provider['certificate_issuer'] ?? '')]);
            }
            $lines[] = '';
        }

        return SimplePdf::render((string) trans('documents.signatures.pdf.title'), implode("\n", $lines), (string) trans('documents.signatures.pdf.footer'));
    }

    /** Abre el turno: marca «enviado» a quien le toca y devuelve sus usuarios para avisarles. */
    /** @return list<string> */
    private function openTurn(SignatureRequest $request): array
    {
        $request->unsetRelation('signers');
        $ids = [];
        foreach ($this->turn($request) as $s) {
            if ($s->status === 'pending') {
                $s->status = 'sent';
                $s->save();
                if ($s->user_id !== null) {
                    $ids[] = $s->user_id;
                }
            }
        }

        return $ids;
    }

    private function assertTurn(SignatureRequest $request, SignatureRequestSigner $signer): void
    {
        if (in_array($signer->status, ['signed', 'declined'], true)) {
            throw new AyniException('invalid_transition', 409, (string) trans('documents.signatures.already_signed'));
        }
        if (! collect($this->turn($request))->contains(fn (SignatureRequestSigner $s) => $s->id === $signer->id)) {
            throw new AyniException('not_your_turn', 409, (string) trans('documents.signatures.not_your_turn'));
        }
    }

    private function signerOf(SignatureRequest $request, string $userId): SignatureRequestSigner
    {
        $signer = $request->signers->first(fn (SignatureRequestSigner $s) => $s->user_id === $userId);
        if (! $signer instanceof SignatureRequestSigner) {
            throw new AyniException('not_found', 404);
        }

        return $signer;
    }

    private function lock(string $id): SignatureRequest
    {
        /** @var SignatureRequest $locked */
        $locked = SignatureRequest::query()->whereKey($id)->lockForUpdate()->firstOrFail();

        return $locked;
    }

    private function versionHash(SignatureRequest $request): string
    {
        return (string) DB::table('document_versions')->where('id', $request->document_version_id)->value('content_hash');
    }

    /** @return array{0: string, 1: string}|null */
    private function subjectOf(SignatureRequest $request): ?array
    {
        $d = DB::table('documents')->where('id', $request->document_id)->first(['subject_type', 'subject_id']);

        return $d === null || $d->subject_type === null || $d->subject_id === null ? null : [(string) $d->subject_type, (string) $d->subject_id];
    }

    /** @return list<string> */
    private function certificateTypes(): array
    {
        $v = $this->parameters->value('firma.tipos_con_certificado');

        return is_array($v) ? array_values(array_map('strval', $v)) : [];
    }

    /**
     * @param  list<array{user_id: string, role?: string|null}>  $input
     * @return list<array{user_id: string, person_id: string|null, name: string, role: string|null}>
     */
    private function signers(array $input): array
    {
        if ($input === [] || count($input) > 10) {
            throw ValidationException::withMessages(['signers' => [(string) trans('documents.signatures.signers_count')]]);
        }
        $ids = array_map(fn ($s) => (string) $s['user_id'], $input);
        if (count(array_unique($ids)) !== count($ids)) {
            throw ValidationException::withMessages(['signers' => [(string) trans('documents.signatures.signers_repeated')]]);
        }
        $users = DB::table('users')->whereIn('id', $ids)->whereNull('deleted_at')->whereIn('status', ['active', 'invited'])
            ->where('tenant_id', TenancyScope::requireTenant())->get(['id', 'person_id', 'display_name'])->keyBy('id');
        $out = [];
        foreach ($input as $i => $s) {
            $u = $users[(string) $s['user_id']] ?? null;
            if ($u === null) {
                throw ValidationException::withMessages(["signers.{$i}.user_id" => [(string) trans('documents.signatures.signer_unknown')]]);
            }
            $out[] = ['user_id' => (string) $u->id, 'person_id' => $u->person_id === null ? null : (string) $u->person_id,
                'name' => (string) $u->display_name, 'role' => isset($s['role']) && $s['role'] !== '' ? mb_substr($s['role'], 0, 60) : null];
        }

        return $out;
    }
}
