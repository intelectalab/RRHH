<?php

/**
 * ESS-3 · Mis documentos y contrato: el colaborador ve solo lo publicado para él y cada descarga queda registrada.
 * ESS-4 · Constancias: catálogo, emisión automática, flujo de aprobación o emisión por RRHH; el PDF queda en el legajo.
 * ESS-5 · Solicitudes a RRHH: tipos configurables, adjuntos, estados por acciones (ME-31), bandeja de RRHH y avisos.
 */

use App\Modules\Certificates\Application\CertificateService;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Organization\Infrastructure\Models\JobProfile;
use App\Modules\Organization\Infrastructure\Models\LegalEntity;
use App\Modules\Organization\Infrastructure\Models\OrgUnit;
use App\Modules\Organization\Infrastructure\Models\Site;
use App\Modules\Workflow\Application\ApprovalFlowService;
use App\Shared\Permissions\DataScope;
use App\Shared\Permissions\ModuleGate;
use App\Shared\Permissions\PermissionResolver;
use App\Shared\Settings\ParameterResolver;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\IdentityFixtures;

uses(DatabaseTransactions::class);

const PTL_TENANT = 'aaaaaaaa-0e53-4000-8000-00000000000a';
const PTL_OTHER = 'bbbbbbbb-0e53-4000-8000-00000000000b';

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requiere PostgreSQL.');
    }
    Storage::fake('s3');
    TenancyScope::clear();
    foreach ([PTL_TENANT => 'ptl-org', PTL_OTHER => 'ptl-otra'] as $id => $slug) {
        DB::table('tenants')->insertOrIgnore(['id' => $id, 'slug' => $slug, 'name' => 'Grupo '.$slug, 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN']);
        DB::select('SELECT seed_tenant_defaults(?)', [$id]);
        TenancyScope::runAs($id, fn () => IdentityFixtures::enableAllModules());
    }
    $this->admin = ptlUser(PTL_TENANT, 'admin_empresa');
    $this->hr = ptlUser(PTL_TENANT, 'rrhh');
    $this->stranger = ptlUser(PTL_OTHER, 'admin_empresa');
    TenancyScope::runAs(PTL_TENANT, function (): void {
        $entity = LegalEntity::factory()->create(['legal_name' => 'Comercial Andina S.A.C.', 'tax_id' => '20999999911']);
        DB::table('legal_entity_regimes')->where('legal_entity_id', $entity->id)->update(['valid_from' => '2020-01-01']);
        $this->entity = $entity->id;
        $this->profile = JobProfile::factory()->create(['name' => 'Analista de compras'])->id;
        $this->unit = OrgUnit::factory()->create(['legal_entity_id' => $this->entity])->id;
        $this->site = Site::factory()->create(['legal_entity_id' => $this->entity])->id;
    });
    $options = ptlCall($this->admin, 'get', '/employment-options')->assertOk();
    $this->contract = collect($options->json('data.contract_types'))->firstWhere('code', 'indeterminado')['id'];

    [$this->employment, $this->person] = ptlHire($this, 'Rosa', 'Quispe');
    [$this->otherEmployment, $this->otherPerson] = ptlHire($this, 'Luis', 'Huamán');
    $this->employee = ptlEmployee($this->person);
    $this->colleague = ptlEmployee($this->otherPerson);
});

afterEach(fn () => TenancyScope::clear());

/** @return array{0: string, 1: string} */
function ptlHire(object $ctx, string $first, string $last): array
{
    $hire = ptlCall($ctx->admin, 'post', '/employments', [
        'person' => ['first_names' => $first, 'paternal_surname' => $last, 'document_type' => 'dni', 'document_number' => (string) random_int(10000000, 89999999),
            'birth_date' => '1990-05-04', 'personal_email' => strtolower($first).'@correo.pe'],
        'legal_entity_id' => $ctx->entity, 'hire_date' => '2025-03-10', 'job_profile_id' => $ctx->profile,
        'org_unit_id' => $ctx->unit, 'site_id' => $ctx->site, 'contract_type_id' => $ctx->contract,
    ])->assertCreated();

    return [(string) $hire->json('data.id'), (string) $hire->json('data.person_id')];
}

function ptlUser(string $tenant, string $role): User
{
    return TenancyScope::runAs($tenant, function () use ($role): User {
        $u = User::factory()->create();
        $u->roleAssignments()->create(['role_id' => Role::query()->where('system_role_code', $role)->value('id')]);

        return $u;
    });
}

function ptlEmployee(string $personId): User
{
    $user = ptlUser(PTL_TENANT, 'empleado');
    TenancyScope::runAs(PTL_TENANT, fn () => DB::table('users')->where('id', $user->id)->update(['person_id' => $personId]));

    return $user->refresh();
}

/**
 * @param  array<string, mixed>  $body
 */
function ptlCall(User $user, string $method, string $path, array $body = [], bool $multipart = false): TestResponse
{
    TenancyScope::clear();
    app('auth')->forgetGuards();
    app(PermissionResolver::class)->forget();
    app(ModuleGate::class)->forget();
    app(DataScope::class)->forget();
    $test = test()->actingAs($user, 'ayni')->withHeaders(['X-Tenant-Id' => $user->tenant_id, 'Idempotency-Key' => (string) Str::uuid(), 'Accept' => 'application/json']);

    return $multipart ? $test->post('/api/v1'.$path, $body) : $test->json($method, '/api/v1'.$path, $body);
}

function ptlPdf(string $name = 'sustento.pdf'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n% prueba\n");
}

// ── ESS-3 · Mis documentos y contrato ────────────────────────────────────────────────────────────────

test('ESS-3: el colaborador ve solo los documentos publicados para él, su contrato, y la descarga queda registrada', function (): void {
    $type = TenancyScope::runAs(PTL_TENANT, function (): string {
        ptlCall($this->admin, 'get', '/document-categories')->assertOk();

        return (string) DB::table('document_categories')->where('code', 'resume')->value('id');
    });
    foreach ([['Curriculum publicado', true], ['Informe interno', false]] as [$title, $visible]) {
        ptlCall($this->admin, 'post', '/documents', ['file' => ptlPdf(), 'category_id' => $type, 'title' => $title,
            'employment_id' => $this->employment, 'visible_to_employee' => $visible ? '1' : '0', 'classification' => 'confidential'], true)->assertCreated();
    }

    $r = ptlCall($this->employee, 'get', "/employments/{$this->employment}/documents")->assertOk();
    $titles = collect($r->json('data.folders'))->flatMap(fn ($f) => collect($f['documents'])->pluck('title'))->all();
    expect($titles)->toBe(['Curriculum publicado']);
    ptlCall($this->employee, 'get', "/employments/{$this->otherEmployment}/documents")->assertNotFound();

    // Su contrato vigente (solo el propio).
    $contracts = ptlCall($this->employee, 'get', '/contracts')->assertOk();
    expect(collect($contracts->json('data'))->pluck('employment_id')->unique()->values()->all())->toBe([$this->employment]);

    $doc = collect($r->json('data.folders'))->flatMap(fn ($f) => $f['documents'])->first();
    ptlCall($this->employee, 'get', "/documents/{$doc['id']}/file?inline=true")->assertOk();
    expect(TenancyScope::runAs(PTL_TENANT, fn () => DB::table('data_access_logs')->where('user_id', $this->employee->id)
        ->where('entity_id', $doc['id'])->where('access_kind', 'view')->count()))->toBe(1);

    $home = ptlCall($this->employee, 'get', '/me/home')->assertOk();
    $card = collect($home->json('data.cards'))->firstWhere('key', 'documents');
    expect($card['data']['count'])->toBe(1)->and($card['href'])->toBe('/mi/documentos');
});

// ── ESS-4 · Constancias ──────────────────────────────────────────────────────────────────────────────

test('ESS-4: la constancia de trabajo se emite al instante y queda en el legajo del colaborador', function (): void {
    $types = ptlCall($this->employee, 'get', '/certificate-types')->assertOk();
    $this->assertMatchesOpenApi($types, 'get', '/certificate-types', 200);
    $kinds = collect($types->json('data.types'))->keyBy('kind');
    // RN: la de remuneraciones solo para quien ve su propio sueldo.
    expect($kinds->keys()->all())->toBe(['employment_certificate', 'vacation_certificate'])
        ->and($kinds['employment_certificate']['auto_issue'])->toBeTrue();

    $r = ptlCall($this->employee, 'post', '/certificate-requests', ['employment_id' => $this->employment, 'kind' => 'employment_certificate', 'purpose' => 'Trámite bancario'])->assertCreated();
    $this->assertMatchesOpenApi($r, 'post', '/certificate-requests', 201);
    expect($r->json('data.status'))->toBe('issued')->and($r->json('data.auto_issued'))->toBeTrue()->and($r->json('data.document_id'))->not->toBeNull();

    $doc = TenancyScope::runAs(PTL_TENANT, fn () => DB::table('documents')->where('id', $r->json('data.document_id'))->first());
    expect($doc->visible_to_employee)->toBeTrue()->and($doc->status)->toBe('active')->and($doc->employment_id)->toBe($this->employment)
        ->and($doc->subject_type)->toBe('certificate_request');

    $file = ptlCall($this->employee, 'get', '/certificate-requests/'.$r->json('data.id').'/file')->assertOk();
    expect($file->headers->get('Content-Type'))->toBe('application/pdf')->and(substr((string) $file->getContent(), 0, 5))->toBe('%PDF-');
    expect(TenancyScope::runAs(PTL_TENANT, fn () => DB::table('data_access_logs')->where('user_id', $this->employee->id)->where('purpose', 'certificate')->count()))->toBe(1);

    // Queda en su legajo (carpeta Certificados) y en su inicio.
    $folders = ptlCall($this->employee, 'get', "/employments/{$this->employment}/documents")->assertOk();
    $cert = collect($folders->json('data.folders'))->firstWhere('code', 'certificates');
    expect(collect($cert['documents'])->pluck('id')->all())->toContain($r->json('data.document_id'));
    $card = collect(ptlCall($this->employee, 'get', '/me/home')->json('data.cards'))->firstWhere('key', 'certificates');
    expect($card['data']['last_issued']['kind'])->toBe('employment_certificate');

    $audit = TenancyScope::runAs(PTL_TENANT, fn () => DB::table('audit_events')->where('entity_id', $r->json('data.id'))->where('action', 'certificate_request.issued')->first());
    expect($audit)->not->toBeNull();
});

test('ESS-4: sin emisión automática espera a RRHH, que la emite o la rechaza; las transiciones inválidas responden 409', function (): void {
    TenancyScope::runAs(PTL_TENANT, fn () => app(ParameterResolver::class)->set('constancias.emision_automatica', [], 'organization', null, null, 'prueba'));

    $r = ptlCall($this->employee, 'post', '/certificate-requests', ['employment_id' => $this->employment, 'kind' => 'employment_certificate'])->assertCreated();
    expect($r->json('data.status'))->toBe('requested');
    $id = $r->json('data.id');

    // RRHH la ve en su bandeja y en su lista; el colaborador no puede emitir.
    $inbox = ptlCall($this->hr, 'get', '/inbox')->assertOk();
    expect(collect($inbox->json('data.items'))->pluck('kind')->all())->toContain('certificate');
    $list = ptlCall($this->hr, 'get', '/certificate-requests?filter[status]=requested')->assertOk();
    $this->assertMatchesOpenApi($list, 'get', '/certificate-requests', 200);
    expect(collect($list->json('data'))->pluck('id')->all())->toContain($id);
    $denied = ptlCall($this->employee, 'post', "/certificate-requests/{$id}/issue")->assertForbidden();
    $this->assertMatchesOpenApi($denied, 'post', '/certificate-requests/{id}/issue', 403);
    expect(TenancyScope::runAs(PTL_TENANT, fn () => DB::table('notifications')->where('recipient_user_id', $this->hr->id)->where('category', 'certificates.requested')->count()))->toBe(1);

    $issued = ptlCall($this->hr, 'post', "/certificate-requests/{$id}/issue")->assertOk();
    $this->assertMatchesOpenApi($issued, 'post', '/certificate-requests/{id}/issue', 200);
    expect($issued->json('data.status'))->toBe('issued')->and($issued->json('data.auto_issued'))->toBeFalse();
    $again = ptlCall($this->hr, 'post', "/certificate-requests/{$id}/issue")->assertStatus(409)->assertJsonPath('error.code', 'invalid_transition');
    $this->assertMatchesOpenApi($again, 'post', '/certificate-requests/{id}/issue', 409);
    expect(TenancyScope::runAs(PTL_TENANT, fn () => DB::table('notifications')->where('recipient_user_id', $this->employee->id)->where('category', 'certificates.issued')->count()))->toBe(1);

    $second = ptlCall($this->employee, 'post', '/certificate-requests', ['employment_id' => $this->employment, 'kind' => 'vacation_certificate'])->assertCreated();
    $bad = ptlCall($this->hr, 'post', '/certificate-requests/'.$second->json('data.id').'/reject', [])->assertStatus(422);
    $this->assertMatchesOpenApi($bad, 'post', '/certificate-requests/{id}/reject', 422);
    $rejected = ptlCall($this->hr, 'post', '/certificate-requests/'.$second->json('data.id').'/reject', ['reason' => 'Pide la de trabajo'])->assertOk();
    expect($rejected->json('data.status'))->toBe('rejected')->and($rejected->json('data.rejection_reason'))->toBe('Pide la de trabajo');
    ptlCall($this->employee, 'get', '/certificate-requests/'.$second->json('data.id').'/file')->assertNotFound();
});

test('ESS-4: con flujo publicado la constancia entra a aprobación y se emite al aprobarse', function (): void {
    TenancyScope::runAs(PTL_TENANT, function (): void {
        $flows = app(ApprovalFlowService::class);
        $flow = $flows->create(['name' => 'Constancias', 'subject_type' => 'certificate_request', 'steps' => ['levels' => [
            ['name' => 'RRHH', 'mode' => 'parallel_any', 'sla_hours' => 24, 'approvers' => [['kind' => 'role', 'role_id' => (string) Role::query()->where('system_role_code', 'rrhh')->value('id')]]],
        ]], 'conditions' => []]);
        $flows->publish($flow, null);
    });
    $r = ptlCall($this->employee, 'post', '/certificate-requests', ['employment_id' => $this->employment, 'kind' => 'vacation_certificate'])->assertCreated();
    expect($r->json('data.status'))->toBe('in_progress')->and($r->json('data.approval.status'))->toBe('running')->and($r->json('data.allowed_actions'))->toBe([]);

    $approval = (string) TenancyScope::runAs(PTL_TENANT, fn () => DB::table('certificate_requests')->where('id', $r->json('data.id'))->value('workflow_instance_id'));
    ptlCall($this->hr, 'post', "/approval-requests/{$approval}/approve", ['comment' => 'ok'])->assertOk();
    $show = ptlCall($this->employee, 'get', '/certificate-requests/'.$r->json('data.id'))->assertOk();
    $this->assertMatchesOpenApi($show, 'get', '/certificate-requests/{id}', 200);
    expect($show->json('data.status'))->toBe('issued')->and($show->json('data.document_id'))->not->toBeNull();
    expect(app(CertificateService::class))->toBeInstanceOf(CertificateService::class);
});

test('ESS-4: solo para la relación propia, aislado por organización y con el módulo contratado', function (): void {
    $foreign = ptlCall($this->employee, 'post', '/certificate-requests', ['employment_id' => $this->otherEmployment, 'kind' => 'employment_certificate'])->assertNotFound();
    $this->assertMatchesOpenApi($foreign, 'post', '/certificate-requests', 404);
    $notAvailable = ptlCall($this->employee, 'post', '/certificate-requests', ['employment_id' => $this->employment, 'kind' => 'income_certificate'])->assertStatus(422)
        ->assertJsonPath('error.code', 'kind_not_available');
    $this->assertMatchesOpenApi($notAvailable, 'post', '/certificate-requests', 422);

    $mine = ptlCall($this->employee, 'post', '/certificate-requests', ['employment_id' => $this->employment, 'kind' => 'employment_certificate'])->assertCreated();
    ptlCall($this->colleague, 'get', '/certificate-requests/'.$mine->json('data.id'))->assertNotFound();
    expect(ptlCall($this->colleague, 'get', '/certificate-requests')->json('data'))->toBe([]);
    ptlCall($this->stranger, 'get', '/certificate-requests/'.$mine->json('data.id'))->assertNotFound();

    IdentityFixtures::disableModule(PTL_TENANT, 'core');
    $off = ptlCall($this->employee, 'get', '/certificate-types')->assertStatus(403)->assertJsonPath('error.code', 'module_not_enabled');
    $this->assertMatchesOpenApi($off, 'get', '/certificate-types', 403);
});

// ── ESS-5 · Solicitudes a RRHH ──────────────────────────────────────────────────────────────────────

test('ESS-5: el colaborador crea una solicitud con adjunto, RRHH la atiende por acciones y él sigue su historial', function (): void {
    $cats = ptlCall($this->employee, 'get', '/ticket-categories')->assertOk();
    $this->assertMatchesOpenApi($cats, 'get', '/ticket-categories', 200);
    $payslips = collect($cats->json('data'))->firstWhere('code', 'payslips');
    expect($payslips['name'])->toBe('Boletas y pagos');

    $created = ptlCall($this->employee, 'post', '/tickets', ['category_id' => $payslips['id'], 'subject' => 'Descuento no reconocido',
        'description' => 'En mi boleta de octubre hay un descuento que no reconozco.', 'file' => ptlPdf('boleta.pdf')], true)->assertCreated();
    $this->assertMatchesOpenApi($created, 'post', '/tickets', 201);
    $id = $created->json('data.id');
    expect($created->json('data.status'))->toBe('new')->and($created->json('data.number'))->toStartWith('SOL-')
        ->and($created->json('data.employment_id'))->toBe($this->employment)->and($created->json('data.messages.0.has_file'))->toBeTrue();

    // RRHH la recibe en su bandeja y con aviso; otro colaborador no la ve.
    $inbox = ptlCall($this->hr, 'get', '/inbox')->assertOk();
    expect(collect($inbox->json('data.items'))->where('kind', 'ticket')->pluck('id')->all())->toContain($id);
    expect(TenancyScope::runAs(PTL_TENANT, fn () => DB::table('notifications')->where('recipient_user_id', $this->hr->id)->where('category', 'tickets.created')->count()))->toBe(1);
    ptlCall($this->colleague, 'get', "/tickets/{$id}")->assertNotFound();
    ptlCall($this->stranger, 'get', "/tickets/{$id}")->assertNotFound();
    $cant = ptlCall($this->employee, 'post', "/tickets/{$id}/assign")->assertForbidden();
    $this->assertMatchesOpenApi($cant, 'post', '/tickets/{id}/assign', 403);

    $taken = ptlCall($this->hr, 'post', "/tickets/{$id}/assign")->assertOk();
    $this->assertMatchesOpenApi($taken, 'post', '/tickets/{id}/assign', 200);
    expect($taken->json('data.status'))->toBe('in_progress')->and($taken->json('data.assignee_user_id'))->toBe($this->hr->id);

    // El estado no se edita por PATCH (regla 3).
    $patched = ptlCall($this->hr, 'patch', "/tickets/{$id}", ['status' => 'closed', 'priority' => 'high'])->assertOk();
    $this->assertMatchesOpenApi($patched, 'patch', '/tickets/{id}', 200);
    expect($patched->json('data.status'))->toBe('in_progress')->and($patched->json('data.priority'))->toBe('high');

    ptlCall($this->hr, 'post', "/tickets/{$id}/request-info", [])->assertStatus(422);
    $waiting = ptlCall($this->hr, 'post', "/tickets/{$id}/request-info", ['message' => 'Adjunta la constancia de tu banco.'])->assertOk();
    expect($waiting->json('data.status'))->toBe('waiting_info');
    ptlCall($this->hr, 'post', "/tickets/{$id}/messages", ['body' => 'Revisar con planilla', 'is_internal_note' => '1'], true)->assertCreated();

    $reply = ptlCall($this->employee, 'post', "/tickets/{$id}/messages", ['body' => 'Aquí está.', 'file' => ptlPdf('banco.pdf')], true)->assertCreated();
    $this->assertMatchesOpenApi($reply, 'post', '/tickets/{id}/messages', 201);
    expect($reply->json('data.status'))->toBe('in_progress');
    // La nota interna no viaja al colaborador.
    expect(collect($reply->json('data.messages'))->pluck('body')->all())->not->toContain('Revisar con planilla');

    $resolved = ptlCall($this->hr, 'post', "/tickets/{$id}/resolve", ['message' => 'Se devolverá en la próxima planilla.'])->assertOk();
    expect($resolved->json('data.status'))->toBe('resolved');
    $mine = ptlCall($this->employee, 'get', "/tickets/{$id}")->assertOk();
    $this->assertMatchesOpenApi($mine, 'get', '/tickets/{id}', 200);
    expect($mine->json('data.allowed_actions'))->toBe(['reopen', 'close'])
        ->and(collect($mine->json('data.messages'))->pluck('event')->all())->toBe(['created', 'assigned', 'info_requested', 'replied', 'resolved']);

    $closed = ptlCall($this->employee, 'post', "/tickets/{$id}/close")->assertOk();
    expect($closed->json('data.status'))->toBe('closed');
    $late = ptlCall($this->employee, 'post', "/tickets/{$id}/messages", ['body' => 'Una cosa más'], true)->assertStatus(409)->assertJsonPath('error.code', 'invalid_transition');
    $this->assertMatchesOpenApi($late, 'post', '/tickets/{id}/messages', 409);
    $again = ptlCall($this->hr, 'post', "/tickets/{$id}/resolve")->assertStatus(409);
    $this->assertMatchesOpenApi($again, 'post', '/tickets/{id}/resolve', 409);

    // Adjunto descargable por el solicitante; avisos al solicitante en cada cambio.
    $msg = collect($mine->json('data.messages'))->firstWhere('event', 'created');
    $file = ptlCall($this->employee, 'get', "/tickets/{$id}/messages/{$msg['id']}/file")->assertOk();
    expect(substr((string) $file->getContent(), 0, 5))->toBe('%PDF-');
    expect(TenancyScope::runAs(PTL_TENANT, fn () => DB::table('notifications')->where('recipient_user_id', $this->employee->id)->where('category', 'tickets.updated')->count()))->toBe(3);

    $list = ptlCall($this->employee, 'get', '/tickets')->assertOk();
    $this->assertMatchesOpenApi($list, 'get', '/tickets', 200);
    expect(collect($list->json('data'))->pluck('id')->all())->toBe([$id]);
    expect(TenancyScope::runAs(PTL_TENANT, fn () => DB::table('audit_events')->where('entity_id', $id)->where('action', 'ticket.closed')->count()))->toBe(1);
});

test('ESS-5: los tipos de solicitud son configurables y el adjunto obligatorio se valida en el servidor', function (): void {
    $denied = ptlCall($this->employee, 'post', '/ticket-categories', ['code' => 'eps', 'name' => 'Afiliación a EPS'])->assertForbidden();
    $this->assertMatchesOpenApi($denied, 'post', '/ticket-categories', 403);
    $c = ptlCall($this->hr, 'post', '/ticket-categories', ['code' => 'eps', 'name' => 'Afiliación a EPS', 'requires_attachment' => true])->assertCreated();
    $this->assertMatchesOpenApi($c, 'post', '/ticket-categories', 201);
    ptlCall($this->hr, 'post', '/ticket-categories', ['code' => 'eps', 'name' => 'Otra'])->assertStatus(409);

    $missing = ptlCall($this->employee, 'post', '/tickets', ['category_id' => $c->json('data.id'), 'subject' => 'Quiero afiliarme'])->assertStatus(422);
    $this->assertMatchesOpenApi($missing, 'post', '/tickets', 422);

    $archived = ptlCall($this->hr, 'patch', '/ticket-categories/'.$c->json('data.id'), ['status' => 'archived'])->assertOk();
    $this->assertMatchesOpenApi($archived, 'patch', '/ticket-categories/{id}', 200);
    expect(collect(ptlCall($this->employee, 'get', '/ticket-categories')->json('data'))->pluck('code')->all())->not->toContain('eps');

    IdentityFixtures::disableModule(PTL_TENANT, 'core');
    ptlCall($this->employee, 'get', '/tickets')->assertStatus(403)->assertJsonPath('error.code', 'module_not_enabled');
});
