<?php

namespace App\Modules\Payroll\Application\Settlements;

use App\Shared\Documents\DocumentArchive;
use App\Shared\Documents\SimplePdf;
use App\Shared\Documents\TemplateRenderer;
use App\Shared\Exceptions\AyniException;
use App\Shared\Signatures\SignatureRequests;
use App\Shared\Signatures\SignatureSubject;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Support\Facades\DB;

/**
 * LIQ-4 · Documentos de cese de una liquidación pagada (RN-LIQ-08): hoja de liquidación, certificado de trabajo, carta
 * de liberación de CTS y constancia de cese. Cada uno sale de la plantilla publicada de la empresa con ese código
 * (DOC-4, editable por empresa) o, si no hay, del formato base; queda en el legajo y se envía a firma con DOC-6
 * (primero el ex colaborador, luego el representante). Al entregarse quedan visibles en su portal.
 */
final class SettlementDocuments implements SignatureSubject
{
    public const KINDS = ['settlement_statement', 'work_certificate', 'cts_release_letter', 'termination_record'];

    public function __construct(
        private readonly DocumentArchive $archive,
        private readonly TemplateRenderer $templates,
        private readonly SignatureRequests $signatures,
    ) {}

    public function subjectType(): string
    {
        return 'termination_document';
    }

    /**
     * Genera los documentos que falten (idempotente por tipo).
     *
     * @return list<string> ids de documentos
     */
    public function generate(string $settlementId, ?string $userId): array
    {
        $s = DB::table('settlements')->where('id', $settlementId)->first();
        abort_unless($s !== null, 404);
        $e = DB::table('employments as e')->join('people as p', 'p.id', '=', 'e.person_id')->where('e.id', $s->employment_id)
            ->first(['e.id', 'e.person_id', 'e.legal_entity_id', 'e.employee_code', 'e.hire_date',
                DB::raw("concat_ws(' ', p.first_names, p.paternal_surname, p.maternal_surname) as name")]);
        $entity = DB::table('legal_entities')->where('id', $e?->legal_entity_id)->first(['legal_name', 'tax_id']);
        $position = DB::table('employment_assignments as a')->leftJoin('positions as po', 'po.id', '=', 'a.position_id')
            ->where('a.employment_id', $s->employment_id)->orderByDesc('a.valid_from')->value('po.title');
        $vars = [
            'employer' => (string) ($entity->legal_name ?? ''), 'tax_id' => (string) ($entity->tax_id ?? ''), 'name' => (string) ($e->name ?? ''),
            'code' => (string) ($e->employee_code ?? ''), 'hire_date' => substr((string) ($e->hire_date ?? ''), 0, 10),
            'termination_date' => substr((string) $s->termination_date, 0, 10), 'position' => (string) ($position ?? ''),
            'total' => (string) ($s->total_amount ?? '0'), 'currency' => (string) $s->currency, 'paid_on' => substr((string) ($s->paid_on ?? ''), 0, 10),
            'bank' => (string) (DB::table('person_bank_accounts')->where('employment_id', $s->employment_id)->where('purpose', 'cts')->whereNull('deleted_at')->value('bank_code') ?? ''),
        ];
        $existing = DB::table('termination_documents')->where('settlement_id', $settlementId)->where('status', '<>', 'declined')->pluck('document_id', 'kind')->all();
        $category = $this->archive->category('termination', (string) trans('payroll.settlements.documents.category', [], 'es'), 'payroll.settlements.view');
        $ids = [];
        foreach (self::KINDS as $kind) {
            if (isset($existing[$kind])) {
                $ids[] = (string) $existing[$kind];

                continue;
            }
            $title = (string) trans('payroll.settlements.documents.'.$kind.'.title');
            $rendered = $this->fromTemplate($kind, (string) $s->employment_id, (string) ($e->legal_entity_id ?? ''), $vars);
            $body = $rendered['body'] ?? $this->baseBody($kind, $s, $vars);
            $pdf = SimplePdf::render($rendered['title'] ?? $title, $body, (string) trans('payroll.settlements.documents.footer'));
            $documentId = $this->archive->create([
                'category_id' => $category, 'title' => $title.' · '.$vars['name'], 'employment_id' => (string) $s->employment_id,
                'person_id' => $e?->person_id, 'legal_entity_id' => $e?->legal_entity_id, 'subject_type' => $this->subjectType(), 'subject_id' => null,
                'source' => 'generated', 'status' => 'active', 'issued_on' => now()->toDateString(), 'classification' => 'confidential',
                'visible_to_employee' => false, 'document_template_version_id' => $rendered['template_version_id'] ?? null,
            ], $pdf, $kind.'-'.($vars['code'] !== '' ? $vars['code'] : $settlementId).'.pdf', 'application/pdf', $userId);
            $rowId = (string) DB::table('termination_documents')->insertGetId([
                'tenant_id' => TenancyScope::requireTenant(), 'employment_id' => $s->employment_id, 'settlement_id' => $settlementId,
                'kind' => $kind, 'document_id' => $documentId, 'title' => $title, 'status' => 'generated',
            ]);
            DB::table('documents')->where('id', $documentId)->update(['subject_id' => $rowId]);
            $ids[] = $documentId;
        }

        return $ids;
    }

    /** DOC-6 · Envía a firma los documentos generados (ex colaborador y representante). */
    public function sendForSignature(string $settlementId, string $userId): void
    {
        foreach (DB::table('termination_documents')->where('settlement_id', $settlementId)->where('status', 'generated')->get() as $d) {
            $request = $this->signatures->sendForEmployeeAndRepresentative((string) $d->document_id, (string) $d->title, $userId);
            DB::table('termination_documents')->where('id', $d->id)->update(['status' => 'pending_signature', 'signature_request_id' => $request]);
        }
    }

    /** Para entregar, todos firmados: por DOC-6 o, si se confirma, en físico (queda en auditoría). */
    public function assertSigned(string $settlementId, bool $physical, ?string $userId): void
    {
        $docs = DB::table('termination_documents')->where('settlement_id', $settlementId)->where('status', '<>', 'declined')->get();
        $pending = $docs->filter(fn ($d): bool => $d->status !== 'signed');
        if ($docs->count() < count(self::KINDS) || ($pending->isNotEmpty() && ! $physical)) {
            throw new AyniException('documents_not_signed', 409, (string) trans('payroll.settlements.documents.not_signed'));
        }
        foreach ($pending as $d) {
            DB::table('termination_documents')->where('id', $d->id)->update(['status' => 'signed', 'signed_at' => now(), 'delivery_method' => 'physical']);
        }
    }

    /** @return list<string> */
    public function deliver(string $settlementId, string $method): array
    {
        $ids = [];
        foreach (DB::table('termination_documents')->where('settlement_id', $settlementId)->where('status', 'signed')->get() as $d) {
            DB::table('termination_documents')->where('id', $d->id)->update(['delivered_at' => now(), 'delivery_method' => $d->delivery_method ?? $method]);
            DB::table('documents')->where('id', $d->document_id)->update(['visible_to_employee' => true, 'updated_at' => now()]);
            $ids[] = (string) $d->document_id;
        }

        return $ids;
    }

    /** @return list<array<string, mixed>> */
    public function list(string $settlementId): array
    {
        return array_values(DB::table('termination_documents')->where('settlement_id', $settlementId)->orderBy('created_at')->get()
            ->map(fn ($d): array => ['id' => (string) $d->id, 'kind' => (string) $d->kind, 'title' => (string) $d->title, 'document_id' => $d->document_id,
                'status' => (string) $d->status, 'signature_request_id' => $d->signature_request_id,
                'signed_at' => $d->signed_at === null ? null : (string) $d->signed_at, 'delivered_at' => $d->delivered_at === null ? null : (string) $d->delivered_at])->all());
    }

    public function assertCanSend(string $subjectId): void
    {
        $status = DB::table('termination_documents')->where('id', $subjectId)->value('status');
        if (! in_array($status, ['generated', 'pending_signature'], true)) {
            throw new AyniException('invalid_transition', 409, (string) trans('payroll.settlements.documents.not_sendable'));
        }
    }

    public function completed(string $subjectId, string $signedPdf, string $fileName, string $signedOn, ?string $userId): void
    {
        $d = DB::table('termination_documents')->where('id', $subjectId)->first();
        if ($d === null || $d->status === 'signed') {
            return;
        }
        $this->archive->addVersion((string) $d->document_id, $signedPdf, $fileName, 'application/pdf', $userId, null, 'active', 'signature');
        DB::table('termination_documents')->where('id', $subjectId)->update(['status' => 'signed', 'signed_at' => $signedOn, 'delivery_method' => 'electronic']);
    }

    public function declined(string $subjectId, string $reason, ?string $userId): void
    {
        DB::table('termination_documents')->where('id', $subjectId)->whereIn('status', ['generated', 'pending_signature'])->update(['status' => 'declined']);
    }

    /**
     * Plantilla publicada de la empresa (o de la organización) con el código del documento.
     *
     * @param  array<string, string>  $vars
     * @return array{title: string, body: string, template_version_id: string}|null
     */
    private function fromTemplate(string $kind, string $employmentId, string $legalEntityId, array $vars): ?array
    {
        $id = DB::table('document_templates')->where('code', $kind)->where('status', 'active')->whereNull('deleted_at')
            ->where(fn ($q) => $q->whereNull('legal_entity_id')->orWhere('legal_entity_id', $legalEntityId))
            ->orderByRaw('legal_entity_id IS NULL')->value('id');
        if ($id === null) {
            return null;
        }
        $overrides = [];
        foreach ($vars as $k => $v) {
            $overrides['liquidacion.'.$k] = $v;
        }

        return $this->templates->renderPublished((string) $id, $employmentId, $overrides);
    }

    /** @param array<string, string> $vars */
    private function baseBody(string $kind, \stdClass $s, array $vars): string
    {
        $lines = [(string) trans('payroll.settlements.documents.'.$kind.'.body', $vars)];
        if ($kind === 'settlement_statement') {
            foreach (DB::table('settlement_lines')->where('settlement_id', $s->id)->where('calculation_number', (int) ($s->calculation_number ?? 0))->orderBy('created_at')->orderBy('id')->get() as $l) {
                $lines[] = (string) trans('payroll.settlements.documents.line', ['concept' => (string) $l->concept_name, 'amount' => (string) $l->amount,
                    'currency' => (string) $l->currency, 'explanation' => (string) $l->explanation]);
            }
            $lines[] = (string) trans('payroll.settlements.documents.net', ['amount' => $vars['total'], 'currency' => $vars['currency']]);
        }

        return implode("\n", $lines);
    }
}
