<?php

/**
 * DOC-3 · Solicitud y aprobación de documentos (motor de aprobaciones, flujo document_request).
 * DOC-4 · Plantillas con variables seguras, versiones y vista previa con datos reales (RN-DOC-10).
 * DOC-5 · Generación de PDF al legajo, una a una o en lote con avance; los generados conservan su versión (RN-DOC-11).
 */

use App\Modules\Documents\Application\DocumentGenerator;
use App\Modules\Documents\Database\Factories\DocumentFactory;
use App\Modules\Documents\Domain\TemplateEngine;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Workflow\Application\ApprovalFlowService;
use App\Shared\Documents\TemplateRenderer;
use App\Shared\LegalParameters\LegalParameters;
use App\Shared\Outbox\Models\OutboxEvent;
use App\Shared\Outbox\OutboxService;
use App\Shared\Permissions\ModuleGate;
use App\Shared\Permissions\PermissionResolver;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\IdentityFixtures;

uses(DatabaseTransactions::class);

const DT_TENANT = 'aaaaaaaa-0d03-4000-8000-00000000000a';
const DT_OTHER = 'bbbbbbbb-0d03-4000-8000-00000000000b';

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requiere PostgreSQL.');
    }
    Storage::fake('s3');
    TenancyScope::clear();
    app(LegalParameters::class)->sync('PE');
    foreach ([DT_TENANT => 'dt-org', DT_OTHER => 'dt-otra'] as $id => $slug) {
        DB::table('tenants')->insertOrIgnore(['id' => $id, 'slug' => $slug, 'name' => 'Grupo '.$slug, 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN']);
        DB::select('SELECT seed_tenant_defaults(?)', [$id]);
        TenancyScope::runAs($id, fn () => IdentityFixtures::enableAllModules());
    }
    $this->admin = dtUser(DT_TENANT, 'admin_empresa');
    $this->rrhh = dtUser(DT_TENANT, 'rrhh');
    $this->payroll = dtUser(DT_TENANT, 'payroll');
    $this->auditor = dtUser(DT_TENANT, 'auditor');
    $this->other = dtUser(DT_OTHER, 'admin_empresa');
    TenancyScope::runAs(DT_TENANT, function (): void {
        $this->employment = DocumentFactory::employment();
        $this->employment2 = DocumentFactory::employment();
        $this->person = (string) DB::table('employments')->where('id', $this->employment)->value('person_id');
        DB::table('people')->where('id', $this->person)->update(['first_names' => 'Ana María', 'paternal_surname' => 'Quispe', 'maternal_surname' => 'Rojas']);
        DB::table('employment_compensations')->insert(['tenant_id' => DT_TENANT, 'employment_id' => $this->employment, 'base_salary' => '3500.00',
            'currency' => 'PEN', 'valid_from' => '2025-01-01']);
        $rrhh = (string) Role::query()->where('system_role_code', 'rrhh')->value('id');
        $flows = app(ApprovalFlowService::class);
        $flow = $flows->create(['name' => 'Documentos', 'subject_type' => 'document_request', 'steps' => ['levels' => [
            ['name' => 'RRHH', 'mode' => 'parallel_any', 'sla_hours' => 48, 'approvers' => [['kind' => 'role', 'role_id' => $rrhh]]],
        ]], 'conditions' => []]);
        $flows->publish($flow, null);
    });
    $this->titular = dtUser(DT_TENANT, 'empleado', $this->person);
});

afterEach(fn () => TenancyScope::clear());

function dtUser(string $tenant, string $role, ?string $person = null): User
{
    return TenancyScope::runAs($tenant, function () use ($role, $person): User {
        $u = User::factory()->create($person === null ? [] : ['person_id' => $person]);
        $u->roleAssignments()->create(['role_id' => Role::query()->where('system_role_code', $role)->value('id')]);

        return $u;
    });
}

/**
 * @param  array<string, mixed>  $body
 */
function dtCall(User $user, string $method, string $path, array $body = [], bool $multipart = false): TestResponse
{
    TenancyScope::clear();
    app('auth')->forgetGuards();
    app(PermissionResolver::class)->forget();
    app(ModuleGate::class)->forget();
    test()->flushHeaders();
    $test = test()->actingAs($user, 'ayni')->withHeaders(['X-Tenant-Id' => $user->tenant_id, 'Idempotency-Key' => (string) Str::uuid(), 'Accept' => 'application/json']);

    return $multipart ? $test->post('/api/v1'.$path, $body) : $test->json($method, '/api/v1'.$path, $body);
}

function dtDispatch(): void
{
    $outbox = app(OutboxService::class);
    $events = TenancyScope::runAs(DT_TENANT, fn () => OutboxEvent::query()->where('event_type', 'like', 'document.%')->orderBy('occurred_at')->get());
    foreach ($events as $event) {
        foreach ($outbox->getSubscribersFor($event->event_type) as $subscriber) {
            $outbox->getTracker()->executeIdempotently($event->tenant_id, $subscriber->consumerName(), $event->id, fn () => $subscriber->handle($event));
        }
    }
}

function dtType(string $code): string
{
    dtCall(test()->admin, 'get', '/document-categories')->assertOk();

    return (string) TenancyScope::runAs(DT_TENANT, fn () => DB::table('document_categories')->where('code', $code)->whereNull('deleted_at')->value('id'));
}

/**
 * @param  array<string, mixed>  $extra
 */
function dtTemplate(string $body, array $extra = []): string
{
    $r = dtCall(test()->admin, 'post', '/document-templates', ['code' => 'carta_'.Str::lower(Str::random(6)), 'name' => 'Carta de prueba', 'kind' => 'letter',
        'category_id' => dtType('work_certificate'), 'body' => $body] + $extra);
    $r->assertCreated();
    test()->assertMatchesOpenApi($r, 'post', '/document-templates', 201);

    return (string) $r->json('data.id');
}

test('DOC-4 · el catálogo de variables marca las sensibles y si el usuario puede usarlas', function (): void {
    $r = dtCall($this->auditor, 'get', '/document-templates/variables');
    $r->assertOk();
    $this->assertMatchesOpenApi($r, 'get', '/document-templates/variables', 200);
    $vars = collect($r->json('data'))->keyBy('name');
    expect($vars['empleado.nombre_completo']['sensitive'])->toBeFalse()
        ->and($vars['compensacion.sueldo_basico']['sensitive'])->toBeTrue()
        ->and($vars['compensacion.sueldo_basico']['permission'])->toBe('people.employments.salary.view')
        ->and($vars['compensacion.sueldo_basico']['allowed'])->toBeTrue()
        ->and($r->json('meta.syntax.if'))->toContain('{{#if');
});

test('DOC-4 · una variable desconocida bloquea la publicación; publicada queda como versión 1 y la edición abre un borrador', function (): void {
    $id = dtTemplate('Hola {{empleado.nombre_completo}}, su {{empleado.mascota}}.');
    $bad = dtCall($this->admin, 'post', "/document-templates/{$id}/publish");
    $bad->assertStatus(422);
    $this->assertMatchesOpenApi($bad, 'post', '/document-templates/{id}/publish', 422);
    expect(json_encode($bad->json()))->toContain('empleado.mascota');

    $syntax = dtCall($this->admin, 'patch', "/document-templates/{$id}", ['body' => '{{#if empleado.fecha_cese}}cesó']);
    $syntax->assertOk();
    dtCall($this->admin, 'post', "/document-templates/{$id}/publish")->assertStatus(422);

    $fixed = dtCall($this->admin, 'patch', "/document-templates/{$id}", ['body' => 'Certifico que {{empleado.nombre_completo}} {{#if empleado.fecha_cese}}laboró{{else}}labora{{/if}} en {{empresa.razon_social}}.']);
    $fixed->assertOk();
    $this->assertMatchesOpenApi($fixed, 'patch', '/document-templates/{id}', 200);
    $pub = dtCall($this->admin, 'post', "/document-templates/{$id}/publish");
    $pub->assertOk();
    $this->assertMatchesOpenApi($pub, 'post', '/document-templates/{id}/publish', 200);
    expect($pub->json('data.status'))->toBe('active')->and($pub->json('data.current_version'))->toBe(1)->and($pub->json('data.has_draft'))->toBeFalse()
        ->and($pub->json('data.variables'))->toBe(['empleado.nombre_completo', 'empleado.fecha_cese', 'empresa.razon_social'])
        ->and($pub->json('data.allowed_actions'))->toContain('retire', 'generate')->not->toContain('publish');

    // Sin cambios no hay nada que publicar (409); un cambio abre la versión 2 en borrador.
    dtCall($this->admin, 'post', "/document-templates/{$id}/publish")->assertStatus(409);
    $draft = dtCall($this->admin, 'patch', "/document-templates/{$id}", ['body' => 'Texto nuevo de {{empleado.nombres}}.']);
    expect($draft->json('data.has_draft'))->toBeTrue()->and($draft->json('data.current_version'))->toBe(1)->and($draft->json('data.body'))->toBe('Texto nuevo de {{empleado.nombres}}.');
    expect(dtCall($this->admin, 'post', "/document-templates/{$id}/publish")->json('data.current_version'))->toBe(2);

    $show = dtCall($this->auditor, 'get', "/document-templates/{$id}");
    $show->assertOk();
    $this->assertMatchesOpenApi($show, 'get', '/document-templates/{id}', 200);
    expect(collect($show->json('data.versions'))->pluck('version')->all())->toBe([2, 1]);

    $retired = dtCall($this->admin, 'post', "/document-templates/{$id}/retire");
    $this->assertMatchesOpenApi($retired, 'post', '/document-templates/{id}/retire', 200);
    expect($retired->json('data.status'))->toBe('inactive');
    $again = dtCall($this->admin, 'post', "/document-templates/{$id}/retire");
    $again->assertStatus(409)->assertJsonPath('error.code', 'invalid_transition');
    $this->assertMatchesOpenApi($again, 'post', '/document-templates/{id}/retire', 409);
    expect(dtCall($this->admin, 'post', "/document-templates/{$id}/publish")->json('data.status'))->toBe('active');
    expect(TenancyScope::runAs(DT_TENANT, fn () => DB::table('audit_events')->where('entity_id', $id)->where('action', 'document_template.published')->count()))->toBe(3);
});

test('DOC-4 · permisos, aislamiento y validación de plantillas', function (): void {
    $list = dtCall($this->auditor, 'get', '/document-templates');
    $list->assertOk();
    $this->assertMatchesOpenApi($list, 'get', '/document-templates', 200);
    // Las plantillas del sistema (certificado de trabajo, constancia) vienen publicadas.
    expect(collect($list->json('data'))->pluck('code')->all())->toContain('certificado_trabajo', 'constancia_trabajo');
    $denied = dtCall($this->auditor, 'post', '/document-templates', ['code' => 'x', 'name' => 'X', 'kind' => 'letter']);
    $denied->assertForbidden();
    $this->assertMatchesOpenApi($denied, 'post', '/document-templates', 403);
    $invalid = dtCall($this->admin, 'post', '/document-templates', ['code' => 'Con Espacios', 'name' => '', 'kind' => 'poema']);
    $invalid->assertStatus(422);
    $this->assertMatchesOpenApi($invalid, 'post', '/document-templates', 422);
    // Un contrato no va fuera de la carpeta de contratos.
    dtCall($this->admin, 'post', '/document-templates', ['code' => 'contrato_x', 'name' => 'Contrato', 'kind' => 'contract', 'category_id' => dtType('work_certificate')])->assertStatus(422);

    $id = dtTemplate('Hola {{empleado.nombres}}');
    $hidden = dtCall($this->other, 'get', "/document-templates/{$id}");
    $hidden->assertNotFound();
    $this->assertMatchesOpenApi($hidden, 'get', '/document-templates/{id}', 404);
    dtCall($this->other, 'post', "/document-templates/{$id}/generate", ['employment_ids' => [$this->employment]])->assertNotFound();
});

test('DOC-4 · la vista previa usa datos reales; el sueldo exige el permiso del campo y su lectura queda registrada', function (): void {
    $id = dtTemplate('{{empleado.nombre_completo}} gana {{compensacion.sueldo_basico}} {{compensacion.periodicidad}}.', ['page_settings' => ['company_header' => true]]);
    $r = dtCall($this->payroll, 'post', "/document-templates/{$id}/preview", ['employment_id' => $this->employment]);
    $r->assertOk();
    $this->assertMatchesOpenApi($r, 'post', '/document-templates/{id}/preview', 200);
    expect($r->json('data.text'))->toContain('Ana María Quispe Rojas gana PEN 3,500.00 Mensual.')->toContain('RUC 20')
        ->and($r->json('data.withheld'))->toBe([])->and($r->json('data.unknown'))->toBe([]);
    $logs = TenancyScope::runAs(DT_TENANT, fn () => DB::table('data_access_logs')->where('entity_type', 'document_template')->where('entity_id', $id)
        ->where('user_id', $this->payroll->id)->get(['data_category', 'subject_person_id']));
    expect($logs)->toHaveCount(1)->and($logs[0]->data_category)->toBe('salary')->and($logs[0]->subject_person_id)->toBe($this->person);

    // Sin permiso del campo, el sueldo no se resuelve (y no se registra lectura).
    TenancyScope::runAs(DT_TENANT, fn () => DB::table('role_permissions')->where('role_id', Role::query()->where('system_role_code', 'payroll')->value('id'))
        ->where('permission_code', 'people.employments.salary.view')->delete());
    $blind = dtCall($this->payroll, 'post', "/document-templates/{$id}/preview", ['employment_id' => $this->employment]);
    expect($blind->json('data.text'))->toContain('gana '.TemplateEngine::EMPTY)->not->toContain('3,500')
        ->and($blind->json('data.withheld'))->toBe(['compensacion.sueldo_basico']);
    dtCall($this->admin, 'post', "/document-templates/{$id}/publish")->assertOk();
    // RN-DOC-10 · Generar con una variable sensible sin su permiso: 403.
    $forbidden = dtCall($this->payroll, 'post', "/document-templates/{$id}/generate", ['employment_ids' => [$this->employment]]);
    $forbidden->assertForbidden()->assertJsonPath('error.meta.permission', 'people.employments.salary.view');
    $this->assertMatchesOpenApi($forbidden, 'post', '/document-templates/{id}/generate', 403);
    // Fuera del alcance (otra organización) la relación no existe.
    dtCall($this->admin, 'post', "/document-templates/{$id}/preview", ['employment_id' => (string) Str::uuid()])->assertStatus(422);
});

test('DOC-5 · el certificado de trabajo se genera con los datos vigentes, entra al legajo y una regeneración es versión nueva', function (): void {
    $cert = (string) collect(dtCall($this->admin, 'get', '/document-templates')->json('data'))->firstWhere('code', 'certificado_trabajo')['id'];
    $r = dtCall($this->admin, 'post', "/document-templates/{$cert}/generate", ['employment_ids' => [$this->employment]]);
    $r->assertCreated();
    $this->assertMatchesOpenApi($r, 'post', '/document-templates/{id}/generate', 201);
    expect($r->json('data.status'))->toBe('completed')->and($r->json('data.generated'))->toBe(1)->and($r->json('data.items.0.status'))->toBe('generated');
    $docId = (string) $r->json('data.items.0.document_id');

    $doc = dtCall($this->admin, 'get', "/documents/{$docId}")->assertOk();
    expect($doc->json('data.source'))->toBe('generated')->and($doc->json('data.status'))->toBe('active')->and($doc->json('data.current_version'))->toBe(1)
        ->and($doc->json('data.folder_code'))->toBe('certificates')->and($doc->json('data.visible_to_employee'))->toBeTrue();
    $pdf = dtCall($this->admin, 'get', "/documents/{$docId}/file");
    $pdf->assertOk();
    expect(str_starts_with((string) $pdf->getContent(), '%PDF'))->toBeTrue();
    // El titular lo ve en su legajo.
    dtCall($this->titular, 'get', "/documents/{$docId}")->assertOk();

    // RN-DOC-11 · Una versión nueva de la plantilla no toca lo emitido; regenerar agrega la versión 2 del documento.
    $v1 = TenancyScope::runAs(DT_TENANT, fn () => DB::table('documents')->where('id', $docId)->value('document_template_version_id'));
    dtCall($this->admin, 'patch', "/document-templates/{$cert}", ['body' => 'Certificamos que {{empleado.nombre_completo}} trabaja en {{empresa.razon_social}}.'])->assertOk();
    dtCall($this->admin, 'post', "/document-templates/{$cert}/publish")->assertOk();
    expect(TenancyScope::runAs(DT_TENANT, fn () => DB::table('documents')->where('id', $docId)->value('document_template_version_id')))->toBe($v1);
    $again = dtCall($this->admin, 'post', "/document-templates/{$cert}/generate", ['employment_ids' => [$this->employment]]);
    expect($again->json('data.items.0.document_id'))->toBe($docId)->and($again->json('data.items.0.version'))->toBe(2)
        ->and($again->json('data.template_version'))->toBe(2);
    expect(TenancyScope::runAs(DT_TENANT, fn () => DB::table('outbox_events')->where('event_type', 'document.generated.v1')->where('aggregate_id', $docId)->count()))->toBe(2);
});

test('DOC-5 · la generación en lote corre en segundo plano e informa avance y errores por persona', function (): void {
    $cert = (string) collect(dtCall($this->admin, 'get', '/document-templates')->json('data'))->firstWhere('code', 'constancia_trabajo')['id'];
    // Una persona sin ficha completa (la relación se borra antes de procesar) se informa como omitida.
    $r = dtCall($this->admin, 'post', "/document-templates/{$cert}/generate", ['employment_ids' => [$this->employment, $this->employment2]]);
    $r->assertCreated();
    $batchId = (string) $r->json('data.id');
    expect($r->json('data.total'))->toBe(2);
    // El lote ya se procesó al terminar la petición (document.generation_started).
    $show = dtCall($this->admin, 'get', "/document-generation-batches/{$batchId}");
    $show->assertOk();
    $this->assertMatchesOpenApi($show, 'get', '/document-generation-batches/{id}', 200);
    expect($show->json('data.status'))->toBe('completed')->and($show->json('data.generated'))->toBe(2)->and($show->json('data.failed'))->toBe(0);

    TenancyScope::runAs(DT_TENANT, fn () => DB::table('employments')->where('id', $this->employment2)->update(['deleted_at' => now()]));
    $mixed = dtCall($this->admin, 'post', "/document-templates/{$cert}/generate", ['employment_ids' => [$this->employment]]);
    expect($mixed->json('data.status'))->toBe('completed');
    $batch = TenancyScope::runAs(DT_TENANT, function () use ($cert): string {
        $version = (string) DB::table('document_templates')->where('id', $cert)->value('current_version_id');
        $id = (string) Str::uuid();
        DB::table('document_generation_batches')->insert(['id' => $id, 'tenant_id' => DT_TENANT, 'document_template_id' => $cert, 'document_template_version_id' => $version,
            'total' => 2, 'items' => json_encode([['employment_id' => $this->employment, 'status' => 'pending'], ['employment_id' => $this->employment2, 'status' => 'pending']])]);
        app(DocumentGenerator::class)->run($id);

        return $id;
    });
    $partial = dtCall($this->admin, 'get', "/document-generation-batches/{$batch}")->json('data');
    expect($partial['status'])->toBe('completed_with_errors')->and($partial['generated'])->toBe(1)->and($partial['failed'])->toBe(1)
        ->and($partial['items'][1]['status'])->toBe('failed')->and($partial['items'][1]['error'])->not->toBe('');

    $list = dtCall($this->admin, 'get', '/document-generation-batches');
    $list->assertOk();
    $this->assertMatchesOpenApi($list, 'get', '/document-generation-batches', 200);
    dtCall($this->other, 'get', "/document-generation-batches/{$batchId}")->assertNotFound();
});

test('DOC-3 · RRHH solicita, el colaborador entrega desde el portal, se rechaza con observación y se aprueba al reentregar', function (): void {
    $type = dtType('academic_degree');
    $r = dtCall($this->rrhh, 'post', '/document-requests', ['category_id' => $type, 'employment_ids' => [$this->employment, $this->employment2],
        'due_on' => now()->addWeek()->toDateString(), 'instructions' => 'Copia legalizada del título.']);
    $r->assertCreated();
    $this->assertMatchesOpenApi($r, 'post', '/document-requests', 201);
    expect($r->json('meta.created'))->toBe(2);
    $requestId = (string) collect($r->json('data'))->firstWhere('employment_id', $this->employment)['id'];
    // No se duplica una solicitud abierta del mismo tipo.
    $dup = dtCall($this->rrhh, 'post', '/document-requests', ['category_id' => $type, 'employment_ids' => [$this->employment]]);
    expect($dup->json('meta.created'))->toBe(0)->and($dup->json('meta.skipped'))->toHaveCount(1);
    // Se avisa al colaborador.
    dtDispatch();
    expect(TenancyScope::runAs(DT_TENANT, fn () => DB::table('notifications')->where('recipient_user_id', $this->titular->id)->where('category', 'documents.requested')->count()))->toBe(1);

    // El colaborador ve solo lo que le piden (lo que le falta entregar).
    $mine = dtCall($this->titular, 'get', '/document-requests?filter[status]=requested,rejected');
    $mine->assertOk();
    $this->assertMatchesOpenApi($mine, 'get', '/document-requests', 200);
    expect(collect($mine->json('data'))->pluck('id')->all())->toBe([$requestId])->and($mine->json('data.0.allowed_actions'))->toBe(['submit']);

    $submitted = dtCall($this->titular, 'post', "/document-requests/{$requestId}/submit", ['file' => UploadedFile::fake()->createWithContent('titulo.pdf', "%PDF-1.4\n%a\n")], true);
    $submitted->assertOk();
    $this->assertMatchesOpenApi($submitted, 'post', '/document-requests/{id}/submit', 200);
    expect($submitted->json('data.status'))->toBe('submitted')->and($submitted->json('data.approval.status'))->toBe('running');
    $docId = (string) $submitted->json('data.document_id');
    $approval = (string) $submitted->json('data.approval_request_id');
    // La revisión es del flujo: el documento no se aprueba directo.
    dtCall($this->rrhh, 'post', "/documents/{$docId}/approve")->assertStatus(409)->assertJsonPath('error.code', 'managed_by_flow');
    // Entregar dos veces: transición inválida.
    $twice = dtCall($this->titular, 'post', "/document-requests/{$requestId}/submit", ['file' => UploadedFile::fake()->createWithContent('titulo.pdf', "%PDF-1.4\n%b\n")], true);
    $twice->assertStatus(409)->assertJsonPath('error.code', 'invalid_transition');
    $this->assertMatchesOpenApi($twice, 'post', '/document-requests/{id}/submit', 409);

    dtCall($this->rrhh, 'post', "/approval-requests/{$approval}/reject", ['comment' => 'El título no está legalizado.'])->assertOk();
    $back = dtCall($this->titular, 'get', "/document-requests/{$requestId}");
    $this->assertMatchesOpenApi($back, 'get', '/document-requests/{id}', 200);
    expect($back->json('data.status'))->toBe('rejected')->and($back->json('data.rejection_reason'))->toBe('El título no está legalizado.')
        ->and($back->json('data.allowed_actions'))->toBe(['submit']);
    dtDispatch();
    expect(TenancyScope::runAs(DT_TENANT, fn () => DB::table('documents')->where('id', $docId)->value('status')))->toBe('rejected')
        ->and(TenancyScope::runAs(DT_TENANT, fn () => DB::table('notifications')->where('recipient_user_id', $this->titular->id)->where('category', 'documents.rejected')->count()))->toBe(1);

    $resubmitted = dtCall($this->titular, 'post', "/document-requests/{$requestId}/submit", ['file' => UploadedFile::fake()->createWithContent('titulo.pdf', "%PDF-1.4\n%c\n")], true);
    $newDoc = (string) $resubmitted->json('data.document_id');
    expect($newDoc)->not->toBe($docId);
    dtCall($this->rrhh, 'post', '/approval-requests/'.$resubmitted->json('data.approval_request_id').'/approve')->assertOk();
    $done = dtCall($this->rrhh, 'get', "/document-requests/{$requestId}")->json('data');
    expect($done['status'])->toBe('approved')->and($done['allowed_actions'])->toBe([]);
    expect(TenancyScope::runAs(DT_TENANT, fn () => DB::table('documents')->where('id', $newDoc)->value('status')))->toBe('active');
    expect(TenancyScope::runAs(DT_TENANT, fn () => DB::table('audit_events')->where('entity_id', $requestId)->pluck('action')->all()))
        ->toContain('document_request.created', 'document_request.submitted', 'document_request.rejected', 'document_request.approved');
});

test('DOC-3 · permisos, aislamiento, cancelación y validación de solicitudes', function (): void {
    $type = dtType('academic_degree');
    $denied = dtCall($this->titular, 'post', '/document-requests', ['category_id' => $type, 'employment_ids' => [$this->employment]]);
    $denied->assertForbidden();
    $this->assertMatchesOpenApi($denied, 'post', '/document-requests', 403);
    $invalid = dtCall($this->rrhh, 'post', '/document-requests', ['category_id' => $type, 'employment_ids' => [$this->employment], 'due_on' => '2001-01-01']);
    $invalid->assertStatus(422);
    $this->assertMatchesOpenApi($invalid, 'post', '/document-requests', 422);
    // Por filtro: todas las relaciones activas de la empresa.
    $legalEntity = (string) TenancyScope::runAs(DT_TENANT, fn () => DB::table('employments')->where('id', $this->employment)->value('legal_entity_id'));
    $byFilter = dtCall($this->rrhh, 'post', '/document-requests', ['category_id' => $type, 'filter' => ['legal_entity_id' => $legalEntity]]);
    expect($byFilter->json('meta.created'))->toBe(1);
    $id = (string) $byFilter->json('data.0.id');

    dtCall($this->other, 'get', "/document-requests/{$id}")->assertNotFound();
    $foreign = dtCall($this->other, 'post', "/document-requests/{$id}/cancel");
    $foreign->assertNotFound();
    $this->assertMatchesOpenApi($foreign, 'post', '/document-requests/{id}/cancel', 404);
    // El colaborador no cancela; RRHH sí, y luego no se puede entregar.
    dtCall($this->titular, 'post', "/document-requests/{$id}/cancel")->assertForbidden();
    $cancelled = dtCall($this->rrhh, 'post', "/document-requests/{$id}/cancel", ['reason' => 'Ya no se necesita.']);
    $cancelled->assertOk();
    $this->assertMatchesOpenApi($cancelled, 'post', '/document-requests/{id}/cancel', 200);
    expect($cancelled->json('data.status'))->toBe('cancelled');
    dtCall($this->titular, 'post', "/document-requests/{$id}/submit", ['file' => UploadedFile::fake()->createWithContent('t.pdf', "%PDF-1.4\n")], true)
        ->assertStatus(409);
    // Otro colaborador no ve ni entrega solicitudes ajenas.
    $stranger = dtUser(DT_TENANT, 'empleado', (string) TenancyScope::runAs(DT_TENANT, fn () => DB::table('employments')->where('id', $this->employment2)->value('person_id')));
    expect(dtCall($stranger, 'get', '/document-requests')->json('meta.total'))->toBe(0);
});

test('CON-2 · el puente del motor de plantillas resuelve una plantilla publicada sin variables sensibles', function (): void {
    $id = dtTemplate('{{empleado.nombre_completo}} desde {{contrato.inicio}} · {{compensacion.sueldo_basico}}');
    dtCall($this->admin, 'post', "/document-templates/{$id}/publish")->assertOk();
    $out = TenancyScope::runAs(DT_TENANT, fn () => app(TemplateRenderer::class)->renderPublished($id, $this->employment, ['contrato.inicio' => '01/03/2026', 'compensacion.sueldo_basico' => '9']));
    expect($out['body'] ?? null)->toBe('Ana María Quispe Rojas desde 01/03/2026 · '.TemplateEngine::EMPTY);
});

test('DOC-4 · el motor resuelve variables y bloques anidados y detecta errores de sintaxis', function (): void {
    $body = '{{#if a.b}}A{{#unless c.d}} sin C{{/unless}}{{else}}no A{{/if}} · {{e.f}}';
    expect(TemplateEngine::variables($body))->toBe(['a.b', 'c.d', 'e.f'])
        ->and(TemplateEngine::render($body, ['a.b' => 'x', 'e.f' => 'E']))->toBe('A sin C · E')
        ->and(TemplateEngine::render($body, ['c.d' => 'y']))->toBe('no A · '.TemplateEngine::EMPTY)
        ->and(TemplateEngine::syntaxErrors($body))->toBe([])
        ->and(TemplateEngine::syntaxErrors('{{#if a.b}}abierto {{ mal }}'))->not->toBe([]);
});
