<?php

/**
 * DOC-1 · Repositorio y carpetas del legajo. DOC-2 · Versiones, archivo y vencimientos.
 *
 * - Carpetas del sistema con tipos documentales (confidencialidad, visibilidad, revisión, vencimiento); tipos propios.
 * - Subir al legajo: nace vigente (RRHH) o en revisión (titular en un tipo que la exige); aprobar y rechazar con motivo.
 * - Confidencialidad por tipo y permiso de campo; la lectura de un confidencial queda en el registro de accesos.
 * - Versiones inmutables (RN-DOC-01); los documentos de contratos se ven pero se gestionan en su módulo.
 * - Vencimientos: aviso único dentro de la anticipación, paso a Vencido, lista de por vencer y calendario central.
 * - Archivo, restauración y papelera; los sustentos de ausencias aparecen como referencias sin copiar el archivo.
 */

use App\Modules\Documents\Database\Factories\DocumentFactory;
use App\Modules\Documents\Infrastructure\Models\Document;
use App\Modules\Documents\Infrastructure\Trash\DocumentTrashSource;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Shared\Files\FileService;
use App\Shared\LegalParameters\LegalParameters;
use App\Shared\Outbox\DomainEventTypes;
use App\Shared\Outbox\Models\OutboxEvent;
use App\Shared\Outbox\OutboxService;
use App\Shared\Permissions\ModuleGate;
use App\Shared\Permissions\PermissionResolver;
use App\Shared\Tenancy\TenancyScope;
use App\Shared\Vigencia\EffectiveDated;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\IdentityFixtures;

uses(DatabaseTransactions::class);

const DOC_TENANT = 'aaaaaaaa-0d01-4000-8000-00000000000a';
const DOC_OTHER = 'bbbbbbbb-0d01-4000-8000-00000000000b';

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requiere PostgreSQL.');
    }
    Storage::fake('s3');
    TenancyScope::clear();
    app(LegalParameters::class)->sync('PE');
    foreach ([DOC_TENANT => 'doc-org', DOC_OTHER => 'doc-otra'] as $id => $slug) {
        DB::table('tenants')->insertOrIgnore(['id' => $id, 'slug' => $slug, 'name' => 'Grupo '.$slug, 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN']);
        DB::select('SELECT seed_tenant_defaults(?)', [$id]);
        TenancyScope::runAs($id, fn () => IdentityFixtures::enableAllModules());
    }
    $this->admin = docUser(DOC_TENANT, 'admin_empresa');
    $this->auditor = docUser(DOC_TENANT, 'auditor');
    $this->other = docUser(DOC_OTHER, 'admin_empresa');
    TenancyScope::runAs(DOC_TENANT, function (): void {
        $this->employment = DocumentFactory::employment();
        $this->person = (string) DB::table('employments')->where('id', $this->employment)->value('person_id');
    });
    $this->titular = docUser(DOC_TENANT, 'empleado', $this->person);
});

afterEach(fn () => TenancyScope::clear());

function docUser(string $tenant, string $role, ?string $person = null): User
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
function docCall(User $user, string $method, string $path, array $body = [], bool $multipart = false): TestResponse
{
    TenancyScope::clear();
    app('auth')->forgetGuards();
    app(PermissionResolver::class)->forget();
    app(ModuleGate::class)->forget();
    test()->flushHeaders();
    $test = test()->actingAs($user, 'ayni')->withHeaders(['X-Tenant-Id' => $user->tenant_id, 'Idempotency-Key' => (string) Str::uuid(), 'Accept' => 'application/json']);

    return $multipart ? $test->post('/api/v1'.$path, $body) : $test->json($method, '/api/v1'.$path, $body);
}

function docPdf(string $name = 'documento.pdf', string $marker = 'uno'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n% {$marker}\n");
}

/** Id del tipo o carpeta por código. */
function docCategory(string $code): string
{
    docCall(test()->admin, 'get', '/document-categories')->assertOk();

    return (string) TenancyScope::runAs(DOC_TENANT, fn () => DB::table('document_categories')->where('code', $code)->whereNull('deleted_at')->value('id'));
}

/**
 * @param  array<string, mixed>  $extra
 */
function docUpload(User $user, string $type, array $extra = []): TestResponse
{
    return docCall($user, 'post', '/documents', ['file' => docPdf(), 'category_id' => docCategory($type), 'title' => 'Documento de prueba',
        'employment_id' => test()->employment] + $extra, true);
}

/**
 * @return list<array<string, mixed>>
 */
function docEvents(string $type, string $aggregate): array
{
    return TenancyScope::runAs(DOC_TENANT, fn () => DB::table('outbox_events')->where('event_type', $type)->where('aggregate_id', $aggregate)
        ->orderBy('occurred_at')->pluck('payload')->map(fn ($p) => json_decode((string) $p, true))->all());
}

function docDispatch(): void
{
    $outbox = app(OutboxService::class);
    $events = TenancyScope::runAs(DOC_TENANT, fn () => OutboxEvent::query()->where('event_type', 'like', 'document.%')->orderBy('occurred_at')->get());
    foreach ($events as $event) {
        foreach ($outbox->getSubscribersFor($event->event_type) as $subscriber) {
            $outbox->getTracker()->executeIdempotently($event->tenant_id, $subscriber->consumerName(), $event->id, fn () => $subscriber->handle($event));
        }
    }
}

test('DOC-1 · las carpetas del sistema traen sus tipos y la organización crea, ajusta, archiva y restaura tipos propios', function (): void {
    $tree = docCall($this->admin, 'get', '/document-categories');
    $this->assertMatchesOpenApi($tree, 'get', '/document-categories', 200);
    $codes = collect($tree->json('data'))->pluck('code')->all();
    expect($codes)->toContain('personal', 'contracts', 'payslips', 'certificates', 'vacations', 'sst', 'evaluations', 'trainings');
    $personal = collect($tree->json('data'))->firstWhere('code', 'personal');
    expect(collect($personal['types'])->pluck('code')->all())->toContain('identity_document')
        ->and(collect($personal['types'])->firstWhere('code', 'identity_document')['requires_expiry'])->toBeTrue();

    // Idempotente: una segunda lectura no duplica las carpetas.
    docCall($this->admin, 'get', '/document-categories')->assertOk();
    expect(TenancyScope::runAs(DOC_TENANT, fn () => DB::table('document_categories')->where('code', 'personal')->count()))->toBe(1);

    $created = docCall($this->admin, 'post', '/document-categories', [
        'parent_id' => $personal['id'], 'code' => 'driver_license', 'name' => 'Licencia de conducir', 'requires_expiry' => true, 'expiry_notice_days' => 45,
    ]);
    $this->assertMatchesOpenApi($created, 'post', '/document-categories', 201);
    $id = (string) $created->json('data.id');
    expect($created->json('data.visible_to_employee'))->toBeTrue();

    $this->assertMatchesOpenApi(docCall($this->admin, 'post', '/document-categories', ['code' => 'driver_license', 'name' => 'Otra']), 'post', '/document-categories', 409);
    $this->assertMatchesOpenApi(docCall($this->admin, 'post', '/document-categories', ['code' => 'x1', 'name' => 'Sin padre válido', 'parent_id' => $id]), 'post', '/document-categories', 422);

    $patched = docCall($this->admin, 'patch', '/document-categories/'.$id, ['expiry_notice_days' => 60, 'requires_review' => true]);
    $this->assertMatchesOpenApi($patched, 'patch', '/document-categories/{id}', 200);
    expect($patched->json('data.expiry_notice_days'))->toBe(60);

    $this->assertMatchesOpenApi(docCall($this->admin, 'post', '/document-categories/'.$id.'/archive'), 'post', '/document-categories/{id}/archive', 200);
    $this->assertMatchesOpenApi(docCall($this->admin, 'post', '/document-categories/'.$id.'/archive'), 'post', '/document-categories/{id}/archive', 409);
    $this->assertMatchesOpenApi(docCall($this->admin, 'post', '/document-categories/'.$id.'/restore'), 'post', '/document-categories/{id}/restore', 200);
    // Las carpetas del sistema no se archivan.
    $this->assertMatchesOpenApi(docCall($this->admin, 'post', '/document-categories/'.$personal['id'].'/archive'), 'post', '/document-categories/{id}/archive', 409);

    // Permiso y aislamiento.
    $this->assertMatchesOpenApi(docCall($this->titular, 'post', '/document-categories', ['code' => 'y', 'name' => 'Y']), 'post', '/document-categories', 403);
    $this->assertMatchesOpenApi(docCall($this->other, 'patch', '/document-categories/'.$id, ['name' => 'Ajeno']), 'patch', '/document-categories/{id}', 404);
});

test('DOC-1 · RRHH sube al legajo: nace vigente, aparece en su carpeta y se descarga; el tipo con vencimiento exige la fecha', function (): void {
    $today = CarbonImmutable::today();
    $bad = docUpload($this->admin, 'identity_document');
    $this->assertMatchesOpenApi($bad, 'post', '/documents', 422);
    expect(collect($bad->json('error.details'))->pluck('field')->all())->toContain('expires_on');

    $up = docUpload($this->admin, 'identity_document', ['issued_on' => $today->subYear()->toDateString(), 'expires_on' => $today->addYears(5)->toDateString()]);
    $this->assertMatchesOpenApi($up, 'post', '/documents', 201);
    expect($up->json('data.status'))->toBe('active')
        ->and($up->json('data.folder_code'))->toBe('personal')
        ->and($up->json('data.current_version'))->toBe(1)
        ->and($up->json('data.responsible_user_id'))->toBe($this->admin->id)
        ->and($up->json('data.allowed_actions'))->toContain('new_version', 'archive', 'update', 'delete');
    $id = (string) $up->json('data.id');

    $legajo = docCall($this->admin, 'get', '/employments/'.$this->employment.'/documents');
    $this->assertMatchesOpenApi($legajo, 'get', '/employments/{employment_id}/documents', 200);
    $folder = collect($legajo->json('data.folders'))->firstWhere('code', 'personal');
    expect(collect($folder['documents'])->pluck('id')->all())->toBe([$id])
        ->and($legajo->json('meta.can_upload'))->toBeTrue();

    $show = docCall($this->admin, 'get', '/documents/'.$id);
    $this->assertMatchesOpenApi($show, 'get', '/documents/{id}', 200);
    $list = docCall($this->admin, 'get', '/documents?filter[employment_id]='.$this->employment);
    $this->assertMatchesOpenApi($list, 'get', '/documents', 200);
    expect($list->json('meta.total'))->toBe(1);

    $file = docCall($this->admin, 'get', '/documents/'.$id.'/file?inline=true');
    $this->assertMatchesOpenApi($file, 'get', '/documents/{id}/file', 200);
    expect($file->headers->get('Content-Disposition'))->toStartWith('inline');

    // Auditoría del alta.
    expect(TenancyScope::runAs(DOC_TENANT, fn () => DB::table('audit_events')->where('action', 'document.uploaded')->where('entity_id', $id)->exists()))->toBeTrue();

    // Sin permiso de alta y con una relación de otra organización.
    $this->assertMatchesOpenApi(docUpload($this->auditor, 'resume'), 'post', '/documents', 403);
    $this->assertMatchesOpenApi(docCall($this->other, 'get', '/documents/'.$id), 'get', '/documents/{id}', 404);
    $this->assertMatchesOpenApi(docCall($this->other, 'get', '/employments/'.$this->employment.'/documents'), 'get', '/employments/{employment_id}/documents', 404);
    $foreign = docCall($this->other, 'post', '/documents', ['file' => docPdf(), 'category_id' => docCategory('resume'), 'title' => 'X', 'employment_id' => $this->employment], true);
    $this->assertMatchesOpenApi($foreign, 'post', '/documents', 422);
});

test('DOC-1 · RN-DOC-08 · un documento confidencial solo lo ve quien tiene el permiso o el titular, y su lectura queda registrada', function (): void {
    $today = CarbonImmutable::today()->toDateString();
    $hidden = (string) docUpload($this->admin, 'occupational_exam', ['expires_on' => CarbonImmutable::today()->addYear()->toDateString(), 'visible_to_employee' => 'false'])->assertCreated()->json('data.id');
    $published = (string) docUpload($this->admin, 'occupational_exam', ['expires_on' => CarbonImmutable::today()->addYear()->toDateString(), 'visible_to_employee' => 'true'])->assertCreated()->json('data.id');
    $internal = (string) docUpload($this->admin, 'resume')->assertCreated()->json('data.id');

    // El auditor ve el legajo pero no los confidenciales (no los recibe: no se enmascaran en la interfaz).
    $seen = collect(docCall($this->auditor, 'get', '/documents?filter[employment_id]='.$this->employment)->assertOk()->json('data'))->pluck('id')->all();
    expect($seen)->toBe([$internal]);
    $this->assertMatchesOpenApi(docCall($this->auditor, 'get', '/documents/'.$hidden), 'get', '/documents/{id}', 404);
    $this->assertMatchesOpenApi(docCall($this->auditor, 'get', '/documents/'.$hidden.'/file'), 'get', '/documents/{id}/file', 404);

    // El titular ve lo publicado para él, también si es confidencial.
    $mine = collect(docCall($this->titular, 'get', '/employments/'.$this->employment.'/documents')->assertOk()->json('data.folders'))
        ->flatMap(fn ($f) => collect($f['documents'])->pluck('id'))->all();
    expect($mine)->toContain($published, $internal)->not->toContain($hidden);

    // RRHH descarga el confidencial: queda en el registro de accesos.
    $this->assertMatchesOpenApi(docCall($this->admin, 'get', '/documents/'.$hidden.'/file'), 'get', '/documents/{id}/file', 200);
    $log = TenancyScope::runAs(DOC_TENANT, fn () => DB::table('data_access_logs')->where('entity_type', 'document')->where('entity_id', $hidden)->first());
    expect($log)->not->toBeNull()
        ->and($log->user_id)->toBe($this->admin->id)
        ->and($log->subject_person_id)->toBe($this->person)
        ->and($log->access_kind)->toBe('download')
        ->and(substr((string) $log->occurred_at, 0, 10))->toBe($today);
    // La clasificación no baja de la del tipo sin el permiso de confidenciales.
    $this->assertMatchesOpenApi(docUpload($this->titular, 'occupational_exam', ['classification' => 'internal', 'expires_on' => CarbonImmutable::today()->addYear()->toDateString()]), 'post', '/documents', 422);
});

test('ME-12 · lo que entrega el titular en un tipo con revisión queda en revisión; RRHH aprueba o rechaza con motivo', function (): void {
    $expires = CarbonImmutable::today()->addYears(3)->toDateString();
    $sent = docUpload($this->titular, 'identity_document', ['expires_on' => $expires]);
    $this->assertMatchesOpenApi($sent, 'post', '/documents', 201);
    expect($sent->json('data.status'))->toBe('pending_approval')
        ->and($sent->json('data.visible_to_employee'))->toBeTrue()
        ->and($sent->json('data.allowed_actions'))->not->toContain('approve');
    $id = (string) $sent->json('data.id');

    // El titular no aprueba lo suyo (sin permiso) y el estado no cambia por PATCH.
    $this->assertMatchesOpenApi(docCall($this->titular, 'post', '/documents/'.$id.'/approve'), 'post', '/documents/{id}/approve', 403);
    $patched = docCall($this->admin, 'patch', '/documents/'.$id, ['status' => 'active', 'title' => 'DNI vigente']);
    $this->assertMatchesOpenApi($patched, 'patch', '/documents/{id}', 200);
    expect($patched->json('data.status'))->toBe('pending_approval')->and($patched->json('data.title'))->toBe('DNI vigente');

    $this->assertMatchesOpenApi(docCall($this->admin, 'post', '/documents/'.$id.'/reject'), 'post', '/documents/{id}/reject', 422);
    $rejected = docCall($this->admin, 'post', '/documents/'.$id.'/reject', ['reason' => 'La foto no se lee.']);
    $this->assertMatchesOpenApi($rejected, 'post', '/documents/{id}/reject', 200);
    expect($rejected->json('data.status'))->toBe('rejected')
        ->and(docEvents(DomainEventTypes::DOCUMENT_REJECTED, $id)[0]['reason'] ?? null)->toBe('La foto no se lee.');
    $this->assertMatchesOpenApi(docCall($this->admin, 'post', '/documents/'.$id.'/approve'), 'post', '/documents/{id}/approve', 409);

    // Aviso a quien lo subió.
    docDispatch();
    expect(TenancyScope::runAs(DOC_TENANT, fn () => DB::table('notifications')->where('recipient_user_id', $this->titular->id)->where('category', 'documents.rejected')->count()))->toBe(1);

    // El titular sube la versión corregida: vuelve a revisión y RRHH la aprueba.
    $again = docCall($this->titular, 'post', '/documents/'.$id.'/versions', ['file' => docPdf('dni.pdf', 'dos'), 'expires_on' => $expires], true);
    $this->assertMatchesOpenApi($again, 'post', '/documents/{id}/versions', 201);
    expect($again->json('data.status'))->toBe('pending_approval')->and($again->json('data.current_version'))->toBe(2);
    $approved = docCall($this->admin, 'post', '/documents/'.$id.'/approve');
    $this->assertMatchesOpenApi($approved, 'post', '/documents/{id}/approve', 200);
    expect($approved->json('data.status'))->toBe('active');
    $this->assertMatchesOpenApi(docCall($this->other, 'post', '/documents/'.$id.'/approve'), 'post', '/documents/{id}/approve', 404);
});

test('DOC-2 · RN-DOC-01 · las versiones se conservan con sus fechas; los documentos de contratos se gestionan en su módulo', function (): void {
    $today = CarbonImmutable::today();
    $id = (string) docUpload($this->admin, 'training_certificate', ['issued_on' => $today->subYears(2)->toDateString(), 'expires_on' => $today->addMonths(2)->toDateString()])
        ->assertCreated()->json('data.id');
    $v2 = docCall($this->admin, 'post', '/documents/'.$id.'/versions', ['file' => docPdf('renovado.pdf', 'dos'), 'issued_on' => $today->toDateString(),
        'expires_on' => $today->addYears(2)->toDateString(), 'notes' => 'Renovación'], true);
    $this->assertMatchesOpenApi($v2, 'post', '/documents/{id}/versions', 201);
    expect($v2->json('data.current_version'))->toBe(2)->and($v2->json('data.expires_on'))->toBe($today->addYears(2)->toDateString());
    $this->assertMatchesOpenApi(docCall($this->admin, 'post', '/documents/'.$id.'/versions', ['file' => docPdf(), 'expires_on' => $today->subDays(1)->toDateString(), 'issued_on' => $today->toDateString()], true), 'post', '/documents/{id}/versions', 422);

    $versions = docCall($this->admin, 'get', '/documents/'.$id.'/versions');
    $this->assertMatchesOpenApi($versions, 'get', '/documents/{id}/versions', 200);
    expect(collect($versions->json('data'))->pluck('version')->all())->toBe([2, 1])
        ->and($versions->json('data.1.expires_on'))->toBe($today->addMonths(2)->toDateString())
        ->and($versions->json('data.0.is_current'))->toBeTrue();
    $old = docCall($this->admin, 'get', '/documents/'.$id.'/versions/1/file');
    $this->assertMatchesOpenApi($old, 'get', '/documents/{id}/versions/{version}/file', 200);
    expect($old->getContent())->toContain('uno');
    $this->assertMatchesOpenApi(docCall($this->admin, 'get', '/documents/'.$id.'/versions/9/file'), 'get', '/documents/{id}/versions/{version}/file', 404);

    // Un contrato firmado se ve en la carpeta de contratos, pero no se versiona, archiva ni borra aquí.
    $contract = TenancyScope::runAs(DOC_TENANT, fn () => Document::factory()->create([
        'document_category_id' => docCategory('contracts'), 'employment_id' => $this->employment, 'owner_person_id' => $this->person,
        'subject_type' => 'contract', 'subject_id' => (string) Str::uuid(), 'source' => 'signature', 'classification' => 'confidential', 'visible_to_employee' => false,
    ])->id);
    $folder = collect(docCall($this->admin, 'get', '/employments/'.$this->employment.'/documents')->assertOk()->json('data.folders'))->firstWhere('code', 'contracts');
    expect(collect($folder['documents'])->pluck('id')->all())->toBe([$contract])
        ->and($folder['documents'][0]['managed_by'])->toBe('contracts')
        ->and($folder['documents'][0]['allowed_actions'])->toBe([]);
    $conflict = docCall($this->admin, 'post', '/documents/'.$contract.'/versions', ['file' => docPdf()], true);
    $this->assertMatchesOpenApi($conflict, 'post', '/documents/{id}/versions', 409);
    expect($conflict->json('error.code'))->toBe('managed_by_module');
    $this->assertMatchesOpenApi(docCall($this->admin, 'post', '/documents/'.$contract.'/archive'), 'post', '/documents/{id}/archive', 409);
    $this->assertMatchesOpenApi(docCall($this->admin, 'delete', '/documents/'.$contract), 'delete', '/documents/{id}', 409);
    // El titular no ve el contrato no publicado; el auditor tampoco (confidencial).
    expect(collect(docCall($this->titular, 'get', '/documents')->assertOk()->json('data'))->pluck('id')->all())->not->toContain($contract);
    // No se sube un contrato al legajo a mano.
    $this->assertMatchesOpenApi(docUpload($this->admin, 'contracts'), 'post', '/documents', 422);
});

test('DOC-2 · RN-DOC-09 · la tarea diaria avisa una vez, vence al llegar la fecha y lo muestra en por vencer y en el calendario', function (): void {
    $today = CarbonImmutable::today();
    $soon = (string) docUpload($this->admin, 'identity_document', ['expires_on' => $today->addDays(10)->toDateString(), 'visible_to_employee' => 'true'])->assertCreated()->json('data.id');
    $far = (string) docUpload($this->admin, 'identity_document', ['expires_on' => $today->addDays(200)->toDateString()])->assertCreated()->json('data.id');
    expect(docCall($this->admin, 'get', '/documents/'.$soon)->json('data.expiring'))->toBeTrue()
        ->and(docCall($this->admin, 'get', '/documents/'.$far)->json('data.expiring'))->toBeFalse();

    Artisan::call('documents:check-expiry', ['--date' => $today->toDateString()]);
    Artisan::call('documents:check-expiry', ['--date' => $today->addDay()->toDateString()]);
    $events = docEvents(DomainEventTypes::DOCUMENT_EXPIRING, $soon);
    expect($events)->toHaveCount(1)->and($events[0]['days_left'])->toBe(10)
        ->and(docEvents(DomainEventTypes::DOCUMENT_EXPIRING, $far))->toBe([]);

    docDispatch();
    $recipients = TenancyScope::runAs(DOC_TENANT, fn () => DB::table('notifications')->where('category', 'documents.expiring')->where('subject_id', $soon)->pluck('recipient_user_id')->sort()->values()->all());
    expect($recipients)->toBe(collect([$this->admin->id, $this->titular->id])->sort()->values()->all());

    $expiring = docCall($this->admin, 'get', '/documents/expiring');
    $this->assertMatchesOpenApi($expiring, 'get', '/documents/expiring', 200);
    expect(collect($expiring->json('data'))->pluck('id')->all())->toBe([$soon]);
    expect(collect(docCall($this->admin, 'get', '/documents/expiring?within=365')->json('data'))->pluck('id')->all())->toBe([$soon, $far]);

    $calendar = docCall($this->admin, 'get', '/calendar?from='.$today->toDateString().'&to='.$today->addDays(30)->toDateString());
    $calendar->assertOk();
    expect(collect($calendar->json('data'))->where('source', 'documents')->pluck('id')->all())->toBe([$soon]);

    // Al llegar la fecha pasa a Vencido, una sola vez.
    Artisan::call('documents:check-expiry', ['--date' => $today->addDays(11)->toDateString()]);
    Artisan::call('documents:check-expiry', ['--date' => $today->addDays(12)->toDateString()]);
    expect(docCall($this->admin, 'get', '/documents/'.$soon)->json('data.status'))->toBe('expired')
        ->and(docEvents(DomainEventTypes::DOCUMENT_EXPIRED, $soon))->toHaveCount(1);

    // Una versión nueva lo regulariza y reinicia el aviso.
    $renewed = docCall($this->admin, 'post', '/documents/'.$soon.'/versions', ['file' => docPdf(), 'expires_on' => $today->addYears(8)->toDateString()], true);
    expect($renewed->assertCreated()->json('data.status'))->toBe('active');
    expect(TenancyScope::runAs(DOC_TENANT, fn () => DB::table('documents')->where('id', $soon)->value('expiry_alerted_on')))->toBeNull();
    $this->assertMatchesOpenApi(docCall($this->titular, 'get', '/documents/expiring'), 'get', '/documents/expiring', 200);
});

test('DOC-1 · archivar no borra; lo subido por error va a la papelera y se restaura; la depuración espera la conservación legal', function (): void {
    $id = (string) docUpload($this->admin, 'resume')->assertCreated()->json('data.id');
    $archived = docCall($this->admin, 'post', '/documents/'.$id.'/archive', ['reason' => 'Currículum desactualizado']);
    $this->assertMatchesOpenApi($archived, 'post', '/documents/{id}/archive', 200);
    expect($archived->json('data.status'))->toBe('archived')->and($archived->json('data.archive_reason'))->toBe('Currículum desactualizado');
    // Archivado no aparece por defecto en el legajo, pero sí al pedirlo.
    $count = fn (string $q) => collect(docCall($this->admin, 'get', '/employments/'.$this->employment.'/documents'.$q)->json('data.folders'))->sum(fn ($f) => count($f['documents']));
    expect($count(''))->toBe(0)->and($count('?include_archived=true'))->toBe(1);
    $this->assertMatchesOpenApi(docCall($this->admin, 'post', '/documents/'.$id.'/archive'), 'post', '/documents/{id}/archive', 409);
    $restored = docCall($this->admin, 'post', '/documents/'.$id.'/restore');
    $this->assertMatchesOpenApi($restored, 'post', '/documents/{id}/restore', 200);
    expect($restored->json('data.status'))->toBe('active');

    $deleted = docCall($this->admin, 'delete', '/documents/'.$id);
    $this->assertMatchesOpenApi($deleted, 'delete', '/documents/{id}', 200);
    $this->assertMatchesOpenApi(docCall($this->admin, 'get', '/documents/'.$id), 'get', '/documents/{id}', 404);
    $trash = docCall($this->admin, 'get', '/trash');
    expect(collect($trash->assertOk()->json('data'))->where('type', 'document')->pluck('id')->all())->toBe([$id]);
    docCall($this->admin, 'post', '/trash/'.$id.'/restore')->assertOk();
    docCall($this->admin, 'get', '/documents/'.$id)->assertOk();

    // Sin valor validado de conservación (aquí se vuelve a dejar «por validar»), nada se depura aunque venza el plazo.
    TenancyScope::clear();
    $current = DB::table('legal_parameters')->where('country_code', 'PE')->where('code', 'legajo_conservacion_anios')->whereNull('valid_to')->first();
    app(EffectiveDated::class)->change('legal_parameters', ['country_code' => 'PE', 'labor_regime_code' => null, 'code' => 'legajo_conservacion_anios'], [
        'name' => $current->name, 'unit' => $current->unit, 'value_numeric' => null, 'verified' => false, 'legal_source' => 'Prueba',
    ], (string) $current->valid_from, EffectiveDated::SAME_DAY_REPLACE);
    TenancyScope::runAs(DOC_TENANT, function () use ($id): void {
        DB::table('documents')->where('id', $id)->update(['deleted_at' => now()->subYears(30)]);
        $source = app(DocumentTrashSource::class);
        expect($source->expired(now()))->toBe([]);
    });
    $this->assertMatchesOpenApi(docCall($this->auditor, 'post', '/documents/'.$id.'/archive'), 'post', '/documents/{id}/archive', 403);
});

test('DOC-1 · los sustentos de ausencias aparecen en el legajo sin copiar el archivo y respetan la confidencialidad de salud', function (): void {
    [$medical, $personal] = TenancyScope::runAs(DOC_TENANT, function (): array {
        $ids = [];
        foreach ([['medical_leave', true, 'Descanso médico', '02'], ['personal', false, 'Permiso personal', '16']] as [$category, $confidential, $name, $day]) {
            $type = (string) DB::table('absence_types')->insertGetId(['tenant_id' => DOC_TENANT, 'code' => 't_'.Str::lower(Str::random(6)), 'name' => $name, 'category' => $category, 'is_confidential' => $confidential]);
            $request = (string) DB::table('absence_requests')->insertGetId([
                'tenant_id' => DOC_TENANT, 'employment_id' => $this->employment, 'absence_type_id' => $type, 'status' => 'approved',
                'starts_at' => "2026-03-{$day} 00:00:00+00", 'ends_at' => "2026-03-{$day} 23:00:00+00", 'starts_on' => "2026-03-{$day}", 'ends_on' => "2026-03-{$day}",
            ]);
            $file = app(FileService::class)->upload("%PDF-1.4\n% sustento\n", 'sustento.pdf', 'application/pdf', DOC_TENANT, null, 'confidential');
            $ids[] = (string) DB::table('attachments')->insertGetId(['tenant_id' => DOC_TENANT, 'stored_file_id' => $file->id, 'subject_type' => 'absence_request', 'subject_id' => $request, 'label' => $name]);
        }

        return $ids;
    });
    $before = TenancyScope::runAs(DOC_TENANT, fn () => DB::table('stored_files')->count());
    // RRHH ve los sustentos de salud (time.absences.medical_certificate.view); la administración de la empresa no.
    $rrhh = docUser(DOC_TENANT, 'rrhh');

    $folders = collect(docCall($rrhh, 'get', '/employments/'.$this->employment.'/documents')->assertOk()->json('data.folders'))->keyBy('code');
    expect(collect($folders['certificates']['linked'])->pluck('ref')->all())->toBe(['absence:'.$medical])
        ->and(collect($folders['vacations']['linked'])->pluck('ref')->all())->toBe(['absence:'.$personal])
        ->and($folders['certificates']['linked'][0]['sensitive'])->toBeTrue();

    $file = docCall($rrhh, 'get', '/employments/'.$this->employment.'/linked-documents/absence:'.$medical.'/file');
    $this->assertMatchesOpenApi($file, 'get', '/employments/{employment_id}/linked-documents/{ref}/file', 200);
    expect(TenancyScope::runAs(DOC_TENANT, fn () => DB::table('data_access_logs')->where('entity_type', 'absence_document')->where('entity_id', $medical)->where('data_category', 'health')->exists()))->toBeTrue()
        ->and(TenancyScope::runAs(DOC_TENANT, fn () => DB::table('stored_files')->count()))->toBe($before);

    // El auditor y la administración ven el permiso personal, no el sustento de salud; el titular ve los suyos.
    $admin = collect(docCall($this->admin, 'get', '/employments/'.$this->employment.'/documents')->assertOk()->json('data.folders'))
        ->flatMap(fn ($f) => collect($f['linked'])->pluck('ref'))->all();
    expect($admin)->toBe(['absence:'.$personal]);
    $own = collect(docCall($this->titular, 'get', '/employments/'.$this->employment.'/documents')->assertOk()->json('data.folders'))
        ->flatMap(fn ($f) => collect($f['linked'])->pluck('ref'))->sort()->values()->all();
    expect($own)->toBe(collect(['absence:'.$medical, 'absence:'.$personal])->sort()->values()->all());
    $audit = collect(docCall($this->auditor, 'get', '/employments/'.$this->employment.'/documents')->assertOk()->json('data.folders'))
        ->flatMap(fn ($f) => collect($f['linked'])->pluck('ref'))->all();
    expect($audit)->toBe(['absence:'.$personal]);
    $this->assertMatchesOpenApi(docCall($this->auditor, 'get', '/employments/'.$this->employment.'/linked-documents/absence:'.$medical.'/file'), 'get', '/employments/{employment_id}/linked-documents/{ref}/file', 404);
    $this->assertMatchesOpenApi(docCall($this->other, 'get', '/employments/'.$this->employment.'/linked-documents/absence:'.$medical.'/file'), 'get', '/employments/{employment_id}/linked-documents/{ref}/file', 404);
});
