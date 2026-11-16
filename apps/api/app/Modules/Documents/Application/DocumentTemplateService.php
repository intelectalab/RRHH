<?php

namespace App\Modules\Documents\Application;

use App\Modules\Documents\Domain\DocumentTemplateStateMachine;
use App\Modules\Documents\Domain\TemplateEngine;
use App\Modules\Documents\Infrastructure\Models\DocumentCategory;
use App\Modules\Documents\Infrastructure\Models\DocumentTemplate;
use App\Modules\Documents\Infrastructure\Models\DocumentTemplateVersion;
use App\Shared\Audit\AuditLogger;
use App\Shared\Documents\TemplateRenderer;
use App\Shared\Exceptions\AyniException;
use App\Shared\Exceptions\VersionConflictException;
use App\Shared\Permissions\EffectivePermissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * DOC-4 · Plantillas dinámicas: por tipo documental y empresa, con versiones.
 *
 * - El cuerpo se edita en un borrador (versión sin published_at); publicar lo fija como versión nueva y vigente.
 *   Una variable desconocida o un bloque mal cerrado bloquean la publicación (422).
 * - Vista previa con los datos vigentes de una persona real; las variables sensibles sin permiso de campo no se
 *   resuelven (RN-DOC-10) y cada lectura de un dato sensible queda en el registro de accesos.
 * - Plantillas del sistema del paquete de país (certificado de trabajo, constancias): se crean publicadas.
 * - Puerto TemplateRenderer: Contratos (CON-2) genera desde la plantilla del tipo de contrato con este motor.
 */
final class DocumentTemplateService implements TemplateRenderer
{
    public const KINDS = ['contract', 'amendment', 'certificate', 'letter', 'offer', 'policy', 'payslip', 'settlement', 'other'];

    /** Tipos que generan otros módulos con su propio trámite (no se generan sueltos al legajo). */
    public const MANAGED_KINDS = ['contract', 'amendment'];

    private bool $ensured = false;

    public function __construct(
        private readonly DocumentTemplateStateMachine $machine,
        private readonly DocumentCatalog $catalog,
        private readonly TemplateVariables $variables,
        private readonly AuditLogger $audit,
    ) {}

    /** Crea las plantillas del sistema del paquete de país que falten (idempotente). */
    public function ensureDefaults(): void
    {
        if ($this->ensured) {
            return;
        }
        $this->ensured = true;
        $this->catalog->ensureDefaults();
        $country = strtolower((string) (DB::table('current_tenant')->value('country_code') ?? 'pe'));
        $file = base_path('packages/country-'.$country.'/data/plantillas_documentos.php');
        /** @var array<string, array{name: string, kind: string, category: string, body: string}> $defaults */
        $defaults = is_file($file) ? require $file : [];
        $existing = DB::table('document_templates')->whereIn('code', array_keys($defaults))->pluck('code')->all();
        foreach ($defaults as $code => $d) {
            if (in_array($code, $existing, true)) {
                continue;
            }
            DB::transaction(function () use ($code, $d, $country): void {
                $category = DB::table('document_categories')->where('code', $d['category'])->whereNull('deleted_at')->value('id');
                $template = new DocumentTemplate(['code' => $code, 'name' => $d['name'], 'kind' => $d['kind'], 'document_category_id' => $category,
                    'country_code' => strtoupper($country), 'is_system' => true, 'locale' => 'es']);
                $template->status = DocumentTemplateStateMachine::ACTIVE;
                $template->save();
                $version = DocumentTemplateVersion::create(['document_template_id' => $template->id, 'version' => 1, 'body' => $d['body'],
                    'variables' => TemplateEngine::variables($d['body']), 'page_settings' => ['company_header' => false], 'published_at' => now()]);
                DB::table('document_templates')->where('id', $template->id)->update(['current_version_id' => $version->id]);
            });
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?string $userId): DocumentTemplate
    {
        $this->ensureDefaults();
        if (DocumentTemplate::query()->where('code', $data['code'])->exists()) {
            throw ValidationException::withMessages(['code' => [(string) trans('documents.templates.code_taken')]]);
        }
        $category = $this->category($data['category_id'] ?? null, (string) $data['kind']);
        $legalEntity = $this->legalEntity($data['legal_entity_id'] ?? null);

        return DB::transaction(function () use ($data, $userId, $category, $legalEntity): DocumentTemplate {
            $template = new DocumentTemplate([
                'code' => $data['code'], 'name' => $data['name'], 'description' => $data['description'] ?? null, 'kind' => $data['kind'],
                'document_category_id' => $category, 'legal_entity_id' => $legalEntity, 'locale' => $data['locale'] ?? 'es',
                'country_code' => DB::table('current_tenant')->value('country_code'),
            ]);
            $template->save();
            DocumentTemplateVersion::create(['document_template_id' => $template->id, 'version' => 1, 'body' => (string) ($data['body'] ?? ''),
                'variables' => TemplateEngine::variables((string) ($data['body'] ?? '')), 'page_settings' => $this->pageSettings($data['page_settings'] ?? null),
                'created_by_user_id' => $userId]);
            $this->audit->record('document_template.created', 'document_template', $template->id, null, $this->auditable($template));

            return $template->refresh();
        });
    }

    /**
     * Metadatos en la plantilla; cuerpo y encabezado en el borrador (se crea si la última versión está publicada).
     *
     * @param  array<string, mixed>  $data
     */
    public function update(DocumentTemplate $template, array $data, ?int $expected, ?string $userId): DocumentTemplate
    {
        if ($expected !== null && $expected !== $template->lock_version) {
            throw new VersionConflictException($expected, $template->lock_version);
        }
        $changes = array_intersect_key($data, array_flip(['name', 'description', 'locale']));
        if (array_key_exists('category_id', $data)) {
            $changes['document_category_id'] = $this->category($data['category_id'], $template->kind);
        }
        if (array_key_exists('legal_entity_id', $data)) {
            $changes['legal_entity_id'] = $this->legalEntity($data['legal_entity_id']);
        }

        return DB::transaction(function () use ($template, $data, $changes, $userId): DocumentTemplate {
            $before = $this->auditable($template);
            $affected = DB::table('document_templates')->where('id', $template->id)->where('lock_version', $template->lock_version)
                ->update($changes + ['updated_at' => now()]);
            if ($affected === 0) {
                throw new VersionConflictException($template->lock_version, (int) DB::table('document_templates')->where('id', $template->id)->value('lock_version'));
            }
            if (array_key_exists('body', $data) || array_key_exists('page_settings', $data)) {
                $draft = $this->draft($template) ?? $this->newDraft($template, $userId);
                $body = array_key_exists('body', $data) ? (string) $data['body'] : $draft->body;
                $draft->forceFill(['body' => $body, 'variables' => TemplateEngine::variables($body),
                    'page_settings' => array_key_exists('page_settings', $data) ? $this->pageSettings($data['page_settings']) : $draft->page_settings])->save();
            }
            $template->refresh();
            $this->audit->record('document_template.updated', 'document_template', $template->id, $before, $this->auditable($template));

            return $template;
        });
    }

    /** Publica el borrador como versión vigente; sin borrador, reactiva la versión vigente de una retirada. */
    public function publish(DocumentTemplate $template, ?string $userId): DocumentTemplate
    {
        $next = $this->machine->getNextState($template->status, 'publish');
        $draft = $this->draft($template);
        if ($draft === null && ($template->status !== DocumentTemplateStateMachine::INACTIVE || $template->current_version_id === null)) {
            throw new AyniException('invalid_transition', 409, (string) trans('documents.templates.nothing_to_publish'), [], [
                'current_status' => $template->status, 'allowed_actions' => $this->allowedActions($template),
            ]);
        }
        if ($draft !== null) {
            $this->assertPublishable($template, $draft->body);
        }

        return DB::transaction(function () use ($template, $draft, $next): DocumentTemplate {
            $before = $this->auditable($template);
            $versionId = $template->current_version_id;
            if ($draft !== null) {
                $draft->forceFill(['published_at' => now(), 'variables' => TemplateEngine::variables($draft->body)])->save();
                $versionId = $draft->id;
            }
            $this->transition($template, ['status' => $next, 'current_version_id' => $versionId]);
            $this->audit->record('document_template.published', 'document_template', $template->id, $before, $this->auditable($template) + ['version_number' => $draft?->version]);

            return $template;
        });
    }

    public function retire(DocumentTemplate $template): DocumentTemplate
    {
        $next = $this->machine->getNextState($template->status, 'retire');
        $before = $this->auditable($template);
        $this->transition($template, ['status' => $next]);
        $this->audit->record('document_template.retired', 'document_template', $template->id, $before, $this->auditable($template));

        return $template;
    }

    /**
     * Vista previa con datos reales: texto resuelto, variables desconocidas, omitidas por permiso y errores.
     *
     * @return array{title: string, text: string, variables: list<string>, unknown: list<string>, withheld: list<string>, syntax_errors: list<string>, version: int|null, sensitive: list<string>, sensitive_fields: list<string>, person_id: string|null}
     */
    public function preview(DocumentTemplate $template, string $employmentId, ?string $body, EffectivePermissions $permissions): array
    {
        $version = $body === null ? ($this->draft($template) ?? $this->current($template)) : null;
        $body ??= $version === null ? '' : $version->body;
        $names = TemplateEngine::variables($body);
        $resolved = $this->variables->resolve($employmentId, $names, $permissions, $template->locale);

        return [
            'title' => $template->name,
            'text' => $this->compose($template, TemplateEngine::render($body, $resolved['values']), $version?->page_settings, $resolved['values'], $employmentId),
            'variables' => $names, 'unknown' => TemplateVariables::unknown($names), 'withheld' => $resolved['withheld'],
            'syntax_errors' => TemplateEngine::syntaxErrors($body), 'version' => $version?->version,
            'sensitive' => $resolved['sensitive'], 'sensitive_fields' => $resolved['sensitive_fields'], 'person_id' => $resolved['person_id'],
        ];
    }

    /**
     * Texto final de la versión indicada para una relación laboral (DOC-5).
     *
     * @return array{title: string, text: string, footer: string, sensitive: list<string>, sensitive_fields: list<string>, person_id: string|null}
     */
    public function renderVersion(DocumentTemplate $template, DocumentTemplateVersion $version, string $employmentId, EffectivePermissions $permissions): array
    {
        $names = TemplateEngine::variables($version->body);
        $resolved = $this->variables->resolve($employmentId, $names, $permissions, $template->locale);
        $company = $this->variables->resolve($employmentId, ['empresa.razon_social', 'empresa.ruc'], $permissions)['values'];

        return [
            'title' => $template->name,
            'text' => $this->compose($template, TemplateEngine::render($version->body, $resolved['values']), $version->page_settings, $resolved['values'], $employmentId),
            'footer' => trim(($company['empresa.razon_social'] ?? '').' · RUC '.($company['empresa.ruc'] ?? ''), ' ·'),
            'sensitive' => $resolved['sensitive'], 'sensitive_fields' => $resolved['sensitive_fields'], 'person_id' => $resolved['person_id'],
        ];
    }

    /**
     * CON-2 · Puente de Contratos: la plantilla del tipo de contrato, si es del motor DOC-4 ({{…}}), se resuelve
     * aquí sin variables sensibles (el sueldo va en el anexo que firma RRHH); $overrides trae los datos del contrato.
     */
    public function renderPublished(string $templateId, string $employmentId, array $overrides = []): ?array
    {
        $template = DocumentTemplate::query()->where('status', DocumentTemplateStateMachine::ACTIVE)->find($templateId);
        $version = $template instanceof DocumentTemplate ? $this->current($template) : null;
        if (! $template instanceof DocumentTemplate || $version === null) {
            return null;
        }
        $names = TemplateEngine::variables($version->body);
        $values = $this->variables->resolve($employmentId, $names, new EffectivePermissions([], [], []), $template->locale)['values'];
        foreach ($overrides as $k => $v) {
            if (array_key_exists($k, TemplateVariables::CATALOG) && TemplateVariables::CATALOG[$k][1] === null) {
                $values[$k] = $v;
            }
        }

        return ['title' => $template->name, 'body' => TemplateEngine::render($version->body, $values), 'template_version_id' => $version->id];
    }

    public function draft(DocumentTemplate $template): ?DocumentTemplateVersion
    {
        $v = DocumentTemplateVersion::query()->where('document_template_id', $template->id)->whereNull('published_at')->orderByDesc('version')->first();

        return $v instanceof DocumentTemplateVersion ? $v : null;
    }

    public function current(DocumentTemplate $template): ?DocumentTemplateVersion
    {
        $v = $template->current_version_id === null ? null : DocumentTemplateVersion::query()->find($template->current_version_id);

        return $v instanceof DocumentTemplateVersion ? $v : null;
    }

    /** @return list<string> */
    public function allowedActions(DocumentTemplate $template): array
    {
        $actions = $this->machine->allowedActions($template->status);
        if ($template->status === DocumentTemplateStateMachine::ACTIVE && $this->draft($template) === null) {
            $actions = array_values(array_diff($actions, ['publish']));
        }
        if ($template->status === DocumentTemplateStateMachine::ACTIVE && ! in_array($template->kind, self::MANAGED_KINDS, true) && $template->document_category_id !== null) {
            $actions[] = 'generate';
        }

        return array_values($actions);
    }

    private function assertPublishable(DocumentTemplate $template, string $body): void
    {
        if (trim($body) === '') {
            throw ValidationException::withMessages(['body' => [(string) trans('documents.templates.body_required')]]);
        }
        $errors = TemplateEngine::syntaxErrors($body);
        if ($errors !== []) {
            throw ValidationException::withMessages(['body' => [(string) trans('documents.templates.syntax_error', ['fragments' => implode(', ', $errors)])]]);
        }
        $unknown = TemplateVariables::unknown(TemplateEngine::variables($body));
        if ($unknown !== []) {
            throw ValidationException::withMessages(['body' => [(string) trans('documents.templates.unknown_variables', ['variables' => implode(', ', $unknown)])]]);
        }
        if ($template->document_category_id === null && ! in_array($template->kind, self::MANAGED_KINDS, true)) {
            throw ValidationException::withMessages(['category_id' => [(string) trans('documents.templates.category_required')]]);
        }
    }

    /**
     * @param  array<string, mixed>|null  $settings
     * @param  array<string, string|null>  $values
     */
    private function compose(DocumentTemplate $template, string $text, ?array $settings, array $values, string $employmentId): string
    {
        if (! (bool) ($settings['company_header'] ?? false)) {
            return $text;
        }
        $company = array_intersect_key($values, array_flip(['empresa.razon_social', 'empresa.ruc', 'empresa.direccion']));
        if (count($company) < 3) {
            $company = $this->variables->resolve($employmentId, ['empresa.razon_social', 'empresa.ruc', 'empresa.direccion'], new EffectivePermissions([], [], []))['values'];
        }
        $header = array_filter([(string) ($company['empresa.razon_social'] ?? ''), trim('RUC '.($company['empresa.ruc'] ?? '')), (string) ($company['empresa.direccion'] ?? '')],
            fn (string $l): bool => $l !== '' && $l !== 'RUC');

        return implode("\n", $header)."\n\n".$text;
    }

    private function newDraft(DocumentTemplate $template, ?string $userId): DocumentTemplateVersion
    {
        $current = $this->current($template);
        $number = (int) DocumentTemplateVersion::query()->where('document_template_id', $template->id)->max('version') + 1;

        return DocumentTemplateVersion::create(['document_template_id' => $template->id, 'version' => $number, 'body' => $current->body ?? '',
            'variables' => $current?->variables, 'page_settings' => $current?->page_settings, 'created_by_user_id' => $userId]);
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function transition(DocumentTemplate $template, array $changes): void
    {
        $affected = DB::table('document_templates')->where('id', $template->id)->where('lock_version', $template->lock_version)->update($changes);
        if ($affected === 0) {
            throw new VersionConflictException($template->lock_version, (int) DB::table('document_templates')->where('id', $template->id)->value('lock_version'));
        }
        $template->refresh();
    }

    private function category(mixed $id, string $kind): ?string
    {
        if ($id === null || $id === '') {
            return null;
        }
        $this->catalog->ensureDefaults();
        $c = preg_match('/^[0-9a-f-]{36}$/i', (string) $id) === 1 ? DocumentCategory::query()->where('status', 'active')->find((string) $id) : null;
        if (! $c instanceof DocumentCategory) {
            throw ValidationException::withMessages(['category_id' => [(string) trans('documents.documents.category_unknown')]]);
        }
        $inContracts = $c->code === 'contracts' || $this->catalog->folder($c)->code === 'contracts';
        if ($inContracts !== in_array($kind, self::MANAGED_KINDS, true)) {
            // Los contratos se generan en su módulo (CON-2); lo demás no va a la carpeta de contratos.
            throw ValidationException::withMessages(['category_id' => [(string) trans('documents.templates.category_kind_mismatch')]]);
        }

        return $c->id;
    }

    private function legalEntity(mixed $id): ?string
    {
        if ($id === null || $id === '') {
            return null;
        }
        if (preg_match('/^[0-9a-f-]{36}$/i', (string) $id) !== 1 || ! DB::table('legal_entities')->where('id', $id)->whereNull('deleted_at')->exists()) {
            throw ValidationException::withMessages(['legal_entity_id' => [(string) trans('documents.templates.legal_entity_unknown')]]);
        }

        return (string) $id;
    }

    /**
     * @return array{company_header: bool}
     */
    private function pageSettings(mixed $settings): array
    {
        return ['company_header' => is_array($settings) && in_array($settings['company_header'] ?? false, [true, 1, '1', 'true'], true)];
    }

    /**
     * @return array<string, mixed>
     */
    private function auditable(DocumentTemplate $t): array
    {
        return ['code' => $t->code, 'name' => $t->name, 'kind' => $t->kind, 'category_id' => $t->document_category_id, 'legal_entity_id' => $t->legal_entity_id,
            'status' => $t->status, 'current_version_id' => $t->current_version_id];
    }
}
