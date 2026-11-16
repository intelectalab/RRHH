<?php

namespace App\Modules\Documents\Application;

use App\Modules\Documents\Domain\DocumentTemplateStateMachine;
use App\Modules\Documents\Domain\TemplateEngine;
use App\Modules\Documents\Infrastructure\Models\DocumentGenerationBatch;
use App\Modules\Documents\Infrastructure\Models\DocumentTemplate;
use App\Modules\Documents\Infrastructure\Models\DocumentTemplateVersion;
use App\Shared\Audit\AuditLogger;
use App\Shared\Documents\DocumentArchive;
use App\Shared\Documents\SimplePdf;
use App\Shared\Exceptions\AyniException;
use App\Shared\Outbox\Contracts\OutboxWriter;
use App\Shared\Outbox\DomainEventTypes;
use App\Shared\Outbox\OutboxService;
use App\Shared\Permissions\EffectivePermissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * DOC-5 · Generación de PDF desde la versión publicada de una plantilla, al legajo (DOC-1).
 *
 * - Una persona se genera en el acto; varias, en segundo plano (evento document.generation_started → lote), con
 *   avance y el resultado por persona: el documento o el motivo por el que se omitió (sin ficha, sin datos).
 * - El PDF se arma con SimplePdf y entra al legajo con el tipo documental de la plantilla, su clasificación y
 *   visibilidad, y la versión de la plantilla con que se emitió (RN-DOC-11). Si ya se generó antes para esa
 *   persona, se agrega como versión nueva del mismo documento (RN-DOC-01).
 * - RN-DOC-10: si la plantilla usa una variable sensible, quien genera necesita su permiso de campo (403).
 */
final class DocumentGenerator
{
    public function __construct(
        private readonly DocumentTemplateService $templates,
        private readonly TemplateVariables $variables,
        private readonly DocumentArchive $archive,
        private readonly OutboxWriter $outbox,
        private readonly OutboxService $delivery,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  list<string>  $employmentIds  ya filtradas por el alcance de quien genera
     */
    public function start(DocumentTemplate $template, array $employmentIds, EffectivePermissions $permissions, ?string $userId, ?string $ip): DocumentGenerationBatch
    {
        $version = $this->templates->current($template);
        if ($template->status !== DocumentTemplateStateMachine::ACTIVE || $version === null) {
            throw new AyniException('invalid_transition', 409, (string) trans('documents.templates.not_published'), [], [
                'current_status' => $template->status, 'allowed_actions' => $this->templates->allowedActions($template),
            ]);
        }
        if (in_array($template->kind, DocumentTemplateService::MANAGED_KINDS, true) || $template->document_category_id === null) {
            throw new AyniException('managed_by_module', 409, (string) trans('documents.templates.generated_in_module'), [], [
                'current_status' => $template->status, 'allowed_actions' => [], 'managed_by' => 'contracts',
            ]);
        }
        $forbidden = TemplateVariables::forbidden(TemplateEngine::variables($version->body), $permissions);
        if ($forbidden !== []) {
            $permission = (string) TemplateVariables::CATALOG[$forbidden[0]][1];
            throw new AyniException('forbidden', 403, (string) trans('documents.templates.field_permission_required', ['variables' => implode(', ', $forbidden)]), [], [
                'permission' => $permission, 'variables' => $forbidden,
            ]);
        }

        $inline = count($employmentIds) === 1;
        $batch = DB::transaction(function () use ($template, $version, $employmentIds, $userId, $ip, $inline): DocumentGenerationBatch {
            $batch = DocumentGenerationBatch::create([
                'document_template_id' => $template->id, 'document_template_version_id' => $version->id, 'requested_by_user_id' => $userId,
                'total' => count($employmentIds), 'items' => array_map(fn (string $e): array => ['employment_id' => $e, 'status' => 'pending'], $employmentIds),
            ]);
            $this->audit->record('document_generation.started', 'document_generation_batch', $batch->id, null, [
                'template_id' => $template->id, 'template_version' => $version->version, 'total' => count($employmentIds),
            ]);
            if (! $inline) {
                $event = $this->outbox->record(DomainEventTypes::DOCUMENT_GENERATION_STARTED, 'document_generation_batch', $batch->id, [
                    'batch_id' => $batch->id, 'template_id' => $template->id, 'ip' => $ip,
                ]);
                $this->delivery->deliverSoon($event);
            }

            return $batch;
        });
        if ($inline) {
            $this->run($batch->id, $ip);
            $batch->refresh();
        }

        return $batch;
    }

    /** Procesa el lote (idempotente: un lote que ya no está en cola no se vuelve a procesar). */
    public function run(string $batchId, ?string $ip = null): void
    {
        $claimed = DB::table('document_generation_batches')->where('id', $batchId)->where('status', 'queued')
            ->update(['status' => 'running', 'started_at' => now()]);
        if ($claimed === 0) {
            return;
        }
        /** @var DocumentGenerationBatch $batch */
        $batch = DocumentGenerationBatch::query()->findOrFail($batchId);
        /** @var DocumentTemplate $template */
        $template = DocumentTemplate::query()->withTrashed()->findOrFail($batch->document_template_id);
        /** @var DocumentTemplateVersion $version */
        $version = DocumentTemplateVersion::query()->findOrFail($batch->document_template_version_id);
        // El permiso de campo se comprobó al iniciar (RN-DOC-10): el lote resuelve las variables que usa la versión.
        $fieldPermissions = [];
        foreach (TemplateEngine::variables($version->body) as $name) {
            $p = TemplateVariables::CATALOG[$name][1] ?? null;
            if ($p !== null) {
                $fieldPermissions[$p] = 'organization';
            }
        }
        $permissions = new EffectivePermissions([], $fieldPermissions, []);
        $items = $batch->items ?? [];
        $generated = 0;
        $failed = 0;
        foreach ($items as $i => $item) {
            $employmentId = (string) $item['employment_id'];
            try {
                $items[$i] = ['employment_id' => $employmentId] + $this->generateOne($template, $version, $employmentId, $permissions, $batch->requested_by_user_id, $ip);
                $generated++;
            } catch (\Throwable $e) {
                $items[$i] = ['employment_id' => $employmentId, 'status' => 'failed',
                    'error' => $e instanceof AyniException ? $e->getMessage() : (string) trans('documents.templates.generation_failed')];
                $failed++;
                report($e);
            }
            DB::table('document_generation_batches')->where('id', $batchId)->update([
                'items' => json_encode($items), 'generated' => $generated, 'failed' => $failed,
            ]);
        }
        $status = $failed === 0 ? 'completed' : ($generated === 0 ? 'failed' : 'completed_with_errors');
        DB::table('document_generation_batches')->where('id', $batchId)->update(['status' => $status, 'finished_at' => now()]);
        $this->audit->record('document_generation.finished', 'document_generation_batch', $batchId, null, ['status' => $status, 'generated' => $generated, 'failed' => $failed]);
    }

    /**
     * @return array{status: string, document_id: string, version: int, person_name: string}
     */
    private function generateOne(DocumentTemplate $template, DocumentTemplateVersion $version, string $employmentId, EffectivePermissions $permissions, ?string $userId, ?string $ip): array
    {
        $employment = DB::table('employments as e')->join('people as p', 'p.id', '=', 'e.person_id')->where('e.id', $employmentId)->whereNull('e.deleted_at')
            ->first(['e.id', 'e.person_id', 'e.legal_entity_id', 'e.employee_code', 'p.first_names', 'p.paternal_surname']);
        if ($employment === null) {
            throw new AyniException('not_found', 404, (string) trans('documents.templates.employment_missing'));
        }
        $category = DB::table('document_categories')->where('id', $template->document_category_id)->first(['id', 'default_classification', 'visible_to_employee']);
        if ($category === null) {
            throw new AyniException('business_rule_violated', 422, (string) trans('documents.templates.category_required'));
        }
        $rendered = $this->templates->renderVersion($template, $version, $employmentId, $permissions);
        $pdf = SimplePdf::render($rendered['title'], $rendered['text'], $rendered['footer']);
        $fileName = Str::slug($template->name.' '.$employment->employee_code).'.pdf';

        return DB::transaction(function () use ($template, $version, $employment, $category, $rendered, $pdf, $fileName, $userId, $ip): array {
            $existing = DB::table('documents as d')->join('document_template_versions as v', 'v.id', '=', 'd.document_template_version_id')
                ->where('d.employment_id', $employment->id)->where('v.document_template_id', $template->id)->where('d.source', 'generated')
                ->whereNull('d.deleted_at')->whereNotIn('d.status', ['archived'])->orderByDesc('d.created_at')->value('d.id');
            if (is_string($existing)) {
                $number = $this->archive->addVersion($existing, $pdf, $fileName, 'application/pdf', $userId, (string) trans('documents.templates.regenerated', ['version' => $version->version]), 'active', 'generated');
                DB::table('documents')->where('id', $existing)->update(['document_template_version_id' => $version->id, 'issued_on' => now()->toDateString()]);
                $documentId = $existing;
            } else {
                $documentId = $this->archive->create([
                    'category_id' => (string) $category->id, 'title' => $template->name, 'employment_id' => (string) $employment->id,
                    'person_id' => (string) $employment->person_id, 'legal_entity_id' => (string) $employment->legal_entity_id,
                    'subject_type' => null, 'subject_id' => null, 'source' => 'generated', 'status' => 'active', 'issued_on' => now()->toDateString(),
                    'classification' => (string) $category->default_classification, 'visible_to_employee' => (bool) $category->visible_to_employee,
                    'document_template_version_id' => $version->id,
                ], $pdf, $fileName, 'application/pdf', $userId);
                $number = 1;
            }
            if ($rendered['sensitive'] !== []) {
                $this->variables->logRead($userId, $rendered['person_id'], 'document', $documentId, $rendered['sensitive'], $rendered['sensitive_fields'], $ip, 'print');
            }
            $this->outbox->record(DomainEventTypes::DOCUMENT_GENERATED, 'document', $documentId, [
                'document_id' => $documentId, 'category_id' => (string) $category->id, 'employment_id' => (string) $employment->id,
                'template_id' => $template->id, 'template_version_id' => $version->id, 'version_number' => $number,
            ]);
            $this->audit->record('document.generated', 'document', $documentId, null, [
                'template_id' => $template->id, 'template_version' => $version->version, 'employment_id' => $employment->id, 'version_number' => $number,
            ]);

            return ['status' => 'generated', 'document_id' => $documentId, 'version' => $number,
                'person_name' => trim($employment->first_names.' '.$employment->paternal_surname)];
        });
    }
}
