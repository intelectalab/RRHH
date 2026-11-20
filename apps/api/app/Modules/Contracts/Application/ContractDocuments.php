<?php

namespace App\Modules\Contracts\Application;

use App\Modules\Contracts\Infrastructure\Models\Contract;
use App\Modules\Contracts\Infrastructure\Models\ContractAddendum;
use App\Modules\Contracts\Infrastructure\Models\RemoteWorkAgreement;
use App\Shared\Documents\DocumentArchive;
use App\Shared\Documents\SimplePdf;
use App\Shared\Documents\TemplateRenderer;
use App\Shared\Security\FieldCipher;
use Illuminate\Support\Facades\DB;

/**
 * CON-2 · CON-3 · Documento del contrato, de la prórroga y de la adenda: se genera desde la plantilla del
 * tipo de contrato (document_templates, si la organización la configuró) o desde la plantilla por defecto
 * del paquete de país, y se archiva en el repositorio documental con sus versiones (generado → firmado).
 *
 * DOC-4 · Puente al motor de plantillas: si la plantilla del tipo de contrato usa la sintaxis del motor
 * ({{empleado.nombre_completo}}, {{contrato.inicio}}, bloques {{#if}}), se resuelve con TemplateRenderer y los datos
 * de este contrato (no los del vigente); las plantillas con la sintaxis anterior ({employee}, {starts_on}) y las del
 * paquete de país siguen funcionando igual, así que migrar una plantilla es solo reescribir sus variables y publicarla.
 */
final class ContractDocuments
{
    public const CATEGORY = 'contracts';

    public function __construct(
        private readonly DocumentArchive $archive,
        private readonly TemplateRenderer $templates,
    ) {}

    /** Genera (o regenera como versión nueva) el documento del contrato. Devuelve el id del documento. */
    public function generateContract(Contract $contract, ?string $userId): string
    {
        $kind = $contract->renewal_of_contract_id === null ? 'contract' : 'renewal';
        $vars = $this->contractVariables($contract);
        [$title, $body] = $this->render($kind, (string) DB::table('contract_types')->where('id', $contract->contract_type_id)->value('document_template_id'), $vars, $contract);
        $pdf = SimplePdf::render($title, $body, $vars['employer'].' · '.$vars['number']);

        return $this->store($contract->document_id, $contract->employment_id, 'contract', $contract->id, $title, $pdf, 'contrato-'.$vars['number'].'.pdf', $userId, 'generated');
    }

    public function generateAddendum(ContractAddendum $addendum, Contract $contract, ?string $userId): string
    {
        $vars = $this->contractVariables($contract) + [
            'contract_number' => (string) ($contract->number ?? '—'),
            'kind' => (string) trans('contracts.addenda.kind_'.$addendum->kind),
            'description' => $addendum->description,
            'effective_on' => $addendum->effective_on->format('d/m/Y'),
        ];
        $vars['number'] = (string) ($addendum->number ?? '—');
        [$title, $body] = $this->render('amendment', null, $vars);
        $pdf = SimplePdf::render($title, $body, $vars['employer'].' · '.$vars['number']);

        return $this->store($addendum->document_id, $contract->employment_id, 'contract_addendum', $addendum->id, $title, $pdf, 'adenda-'.$vars['number'].'.pdf', $userId, 'generated');
    }

    /** CON-6 · Documento del acuerdo de teletrabajo (versión nueva si ya se había emitido). */
    public function generateRemoteWork(RemoteWorkAgreement $agreement, string $title, string $body, string $footer, ?string $userId): string
    {
        $pdf = SimplePdf::render($title, $body, $footer);

        return $this->store($agreement->document_id, $agreement->employment_id, 'remote_work_agreement', $agreement->id, $title, $pdf,
            (string) trans('contracts.remote_work.document_name').'.pdf', $userId, 'generated');
    }

    /** Archiva el ejemplar firmado como versión nueva (o como documento nuevo si no se había generado). */
    public function attachSigned(?string $documentId, string $employmentId, string $subjectType, string $subjectId, string $title, string $contents, string $name, string $mime, ?string $userId): string
    {
        if ($documentId !== null) {
            $this->archive->addVersion($documentId, $contents, $name, $mime, $userId, (string) trans('contracts.contracts.signed_version'), 'active', 'upload');

            return $documentId;
        }

        return $this->store(null, $employmentId, $subjectType, $subjectId, $title, $contents, $name, $userId, 'upload', $mime, 'active');
    }

    /**
     * @return array{name: string, mime: string, contents: string}|null
     */
    public function download(?string $documentId, ?string $userId): ?array
    {
        return $documentId === null ? null : $this->archive->download($documentId, $userId);
    }

    public function version(?string $documentId): ?int
    {
        return $documentId === null ? null : $this->archive->currentVersion($documentId);
    }

    private function store(?string $documentId, string $employmentId, string $subjectType, string $subjectId, string $title, string $contents, string $name, ?string $userId, string $source, string $mime = 'application/pdf', string $status = 'draft'): string
    {
        if ($documentId !== null) {
            $this->archive->addVersion($documentId, $contents, $name, $mime, $userId, null, $status, $source);

            return $documentId;
        }
        $employment = DB::table('employments')->where('id', $employmentId)->first(['person_id', 'legal_entity_id']);

        return $this->archive->create([
            'category_id' => $this->archive->category(self::CATEGORY, (string) trans('contracts.contracts.category_name'), 'contracts.contracts.view'),
            'title' => $title, 'employment_id' => $employmentId, 'person_id' => $employment?->person_id,
            'legal_entity_id' => $employment?->legal_entity_id, 'subject_type' => $subjectType, 'subject_id' => $subjectId,
            'source' => $source, 'status' => $status, 'issued_on' => now()->toDateString(),
        ], $contents, $name, $mime, $userId);
    }

    /**
     * @param  array<string, string>  $vars
     * @return array{0: string, 1: string}
     */
    private function render(string $kind, ?string $templateId, array $vars, ?Contract $contract = null): array
    {
        $title = null;
        $body = null;
        if ($templateId !== null && $templateId !== '' && $kind !== 'amendment') {
            $template = DB::table('document_templates as t')->join('document_template_versions as v', 'v.id', '=', 't.current_version_id')
                ->where('t.id', $templateId)->where('t.status', 'active')->whereNull('t.deleted_at')->first(['t.name', 'v.body']);
            if ($template !== null && $contract !== null && str_contains((string) $template->body, '{{')) {
                $rendered = $this->templates->renderPublished($templateId, $contract->employment_id, [
                    'contrato.tipo' => $vars['contract_type'], 'contrato.numero' => $vars['number'], 'contrato.inicio' => $vars['starts_on'],
                    'contrato.fin' => $contract->valid_to === null ? null : $vars['ends_on'], 'contrato.causa_objetiva' => $contract->objective_cause,
                    'contrato.jornada_semanal' => $vars['weekly_hours'], 'contrato.fin_periodo_prueba' => $vars['trial_period_ends_on'],
                    'empleado.cargo' => $vars['job'], 'empleado.modalidad_trabajo' => $vars['work_modality'],
                ]);
                if ($rendered !== null) {
                    return [$rendered['title'], $rendered['body']];
                }
            }
            if ($template !== null) {
                $title = (string) $template->name;
                $body = (string) $template->body;
            }
        }
        if ($body === null) {
            $country = strtolower((string) (DB::table('current_tenant')->value('country_code') ?? 'pe'));
            $file = base_path('packages/country-'.$country.'/data/plantillas_contrato.php');
            /** @var array<string, array{title: string, body: string}> $defaults */
            $defaults = is_file($file) ? require $file : [];
            $title = $defaults[$kind]['title'] ?? '{contract_type}';
            $body = $defaults[$kind]['body'] ?? '';
        }
        $replace = [];
        foreach ($vars as $k => $v) {
            $replace['{'.$k.'}'] = $v === '' ? '—' : $v;
        }

        return [strtr((string) $title, $replace), strtr($body, $replace)];
    }

    /**
     * @return array<string, string>
     */
    private function contractVariables(Contract $contract): array
    {
        $row = DB::table('employments as e')->join('people as p', 'p.id', '=', 'e.person_id')
            ->join('legal_entities as l', 'l.id', '=', 'e.legal_entity_id')
            ->where('e.id', $contract->employment_id)
            ->first(['e.person_id', 'p.first_names', 'p.paternal_surname', 'p.maternal_surname', 'l.legal_name', 'l.tax_id']);
        $type = DB::table('contract_types')->where('id', $contract->contract_type_id)->first(['name']);
        $doc = $row === null ? null : DB::table('person_documents')->where('person_id', $row->person_id)->whereNull('deleted_at')
            ->orderByDesc('is_primary')->first(['doc_type', 'number_enc']);
        $job = DB::table('employment_assignments as a')->join('job_profiles as j', 'j.id', '=', 'a.job_profile_id')
            ->where('a.employment_id', $contract->employment_id)->where('a.valid_from', '<=', $contract->valid_from->toDateString())
            ->orderByDesc('a.valid_from')->value('j.name');
        $previous = $contract->renewal_of_contract_id === null ? null : DB::table('contracts')->where('id', $contract->renewal_of_contract_id)->value('number');
        $date = fn ($d) => $d === null ? (string) trans('contracts.contracts.no_end') : $d->format('d/m/Y');

        return [
            'employer' => (string) ($row->legal_name ?? ''),
            'employer_tax_id' => (string) ($row->tax_id ?? ''),
            'employee' => $row === null ? '' : trim($row->first_names.' '.$row->paternal_surname.' '.($row->maternal_surname ?? '')),
            'employee_document' => $doc === null ? '' : strtoupper((string) $doc->doc_type).' '.FieldCipher::decrypt((string) $doc->number_enc),
            'contract_type' => (string) ($type->name ?? ''),
            'job' => is_string($job) ? $job : '',
            'objective_cause' => (string) ($contract->objective_cause ?? (string) trans('contracts.contracts.no_cause')),
            'starts_on' => $contract->valid_from->format('d/m/Y'),
            'ends_on' => $date($contract->valid_to),
            'trial_period_ends_on' => $contract->trial_period_ends_on === null ? '' : $contract->trial_period_ends_on->format('d/m/Y'),
            'weekly_hours' => $contract->weekly_hours === null ? '' : rtrim(rtrim((string) $contract->weekly_hours, '0'), '.'),
            'work_modality' => (string) trans('contracts.contracts.modality_'.$contract->work_modality),
            'number' => (string) ($contract->number ?? ''),
            'previous_number' => is_string($previous) ? $previous : '',
        ];
    }
}
