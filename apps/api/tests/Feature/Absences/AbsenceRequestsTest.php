<?php

/**
 * AUS-2 · Solicitud y registro de ausencias (ME-16) con las reglas vigentes del tipo (AUS-1):
 * borrador → enviada (flujo absence_request) → aprobada por el jefe → en curso → finalizada; RRHH la
 * registra directamente; solapes rechazados; días hábiles con los feriados de la sede (CFG-8); sustento
 * con plazo (RN-AUS-01); tope con excepción de RRHH (RN-AUS-07); motivo médico confidencial (RN-AUS-05);
 * aparece en el calendario y en el puerto de ausencias aprobadas que lee asistencia.
 *
 * Hoy (congelado): lunes 2 de marzo de 2026.
 */

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Organization\Infrastructure\Models\LegalEntity;
use App\Shared\Permissions\ModuleGate;
use App\Shared\Permissions\PermissionResolver;
use App\Shared\Tenancy\TenancyScope;
use App\Shared\Time\ApprovedAbsences;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\IdentityFixtures;

uses(DatabaseTransactions::class);

const ABSR_TENANT = 'aaaaaaaa-a052-4000-8000-00000000000a';
const ABSR_OTHER = 'bbbbbbbb-a052-4000-8000-00000000000b';

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requiere PostgreSQL.');
    }
    Carbon::setTestNow('2026-03-02 15:00:00');
    Storage::fake('s3');
    config(['files.disk' => 's3']);
    TenancyScope::clear();
    foreach ([ABSR_TENANT => 'aus-org', ABSR_OTHER => 'aus-otra'] as $id => $slug) {
        DB::table('tenants')->insertOrIgnore(['id' => $id, 'slug' => $slug, 'name' => 'Grupo '.$slug, 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN']);
        DB::select('SELECT seed_tenant_defaults(?)', [$id]);
        TenancyScope::runAs($id, fn () => IdentityFixtures::enableAllModules());
    }

    $this->admin = absrUser(ABSR_TENANT, 'admin_empresa');
    $this->hr = absrUser(ABSR_TENANT, 'rrhh');

    $org = TenancyScope::runAs(ABSR_TENANT, function (): array {
        $entity = LegalEntity::factory()->create(['timezone' => 'America/Lima'])->id;
        $calendar = (string) Str::uuid();
        DB::table('holiday_calendars')->insert(['id' => $calendar, 'tenant_id' => ABSR_TENANT, 'code' => 'LIM', 'name' => 'Feriados Lima', 'country_code' => 'PE']);
        DB::table('holidays')->insert(['tenant_id' => ABSR_TENANT, 'holiday_calendar_id' => $calendar, 'holiday_date' => '2026-03-11', 'name' => 'Feriado de prueba']);
        $site = (string) Str::uuid();
        DB::table('sites')->insert(['id' => $site, 'tenant_id' => ABSR_TENANT, 'legal_entity_id' => $entity, 'code' => 'LIM', 'name' => 'Lima', 'timezone' => 'America/Lima', 'holiday_calendar_id' => $calendar]);
        $unit = (string) Str::uuid();
        DB::table('org_units')->insert(['id' => $unit, 'tenant_id' => ABSR_TENANT, 'code' => 'OPE', 'name' => 'Operaciones']);
        $profile = (string) Str::uuid();
        DB::table('job_profiles')->insert(['id' => $profile, 'tenant_id' => ABSR_TENANT, 'code' => 'ANA', 'name' => 'Analista']);

        $mk = function (string $name, ?string $manager) use ($entity, $site, $unit, $profile): array {
            $person = (string) Str::uuid();
            $employment = (string) Str::uuid();
            DB::table('people')->insert(['id' => $person, 'tenant_id' => ABSR_TENANT, 'first_names' => $name, 'paternal_surname' => 'Prueba']);
            DB::table('employments')->insert(['id' => $employment, 'tenant_id' => ABSR_TENANT, 'person_id' => $person, 'legal_entity_id' => $entity, 'employee_code' => strtoupper($name), 'hire_date' => '2024-01-01', 'status' => 'active']);
            DB::table('employment_assignments')->insert([
                'tenant_id' => ABSR_TENANT, 'employment_id' => $employment, 'job_profile_id' => $profile, 'org_unit_id' => $unit,
                'site_id' => $site, 'manager_employment_id' => $manager, 'valid_from' => '2024-01-01',
            ]);

            return [$person, $employment];
        };
        [$bossPerson, $bossEmployment] = $mk('Marta', null);
        [$person, $employment] = $mk('Juan', $bossEmployment);

        return compact('entity', 'site', 'bossPerson', 'bossEmployment', 'person', 'employment');
    });
    $this->org = $org;
    $this->boss = absrUser(ABSR_TENANT, 'manager', $org['bossPerson']);
    $this->employee = absrUser(ABSR_TENANT, 'empleado', $org['person']);
    $this->emp = $org['employment'];

    // Flujo publicado: aprueba el jefe directo.
    $flow = absrCall($this->admin, 'POST', '/approval-flows', ['name' => 'Ausencias', 'subject_type' => 'absence_request',
        'steps' => ['levels' => [['mode' => 'sequential', 'approvers' => [['kind' => 'manager']]]]]]);
    $flow->assertCreated();
    absrCall($this->admin, 'POST', '/approval-flows/'.$flow->json('data.id').'/publish')->assertOk();
});

afterEach(function (): void {
    TenancyScope::clear();
    Carbon::setTestNow();
});

function absrUser(string $tenant, string $role, ?string $personId = null): User
{
    return TenancyScope::runAs($tenant, function () use ($role, $personId): User {
        $u = User::factory()->create(['person_id' => $personId]);
        $u->roleAssignments()->create(['role_id' => Role::query()->where('system_role_code', $role)->value('id')]);

        return $u;
    });
}

/**
 * @param  array<string, mixed>  $body
 */
function absrCall(User $user, string $method, string $path, array $body = []): TestResponse
{
    TenancyScope::clear();
    app('auth')->forgetGuards();
    app(PermissionResolver::class)->forget();
    app(ModuleGate::class)->forget();

    return test()->actingAs($user, 'ayni')->withHeaders(['X-Tenant-Id' => $user->tenant_id, 'Idempotency-Key' => (string) Str::uuid()])->json($method, '/api/v1'.$path, $body);
}

/**
 * Tipo de ausencia propio con reglas vigentes desde 2025.
 *
 * @param  array<string, mixed>  $rules
 */
function absrType(string $code, array $rules = [], bool $confidential = false, string $category = 'personal'): string
{
    $r = absrCall(test()->admin, 'POST', '/absence-types', [
        'code' => $code, 'name' => 'Tipo '.$code, 'category' => $category, 'is_confidential' => $confidential,
        'effective_from' => '2025-01-01', 'rules' => $rules + ['unit' => 'days', 'requires_document' => false, 'requestable_by' => 'employee'],
    ]);
    $r->assertCreated();

    return (string) $r->json('data.id');
}

/**
 * @param  array<string, mixed>  $extra
 */
function absrDraft(User $user, string $type, string $from, ?string $to = null, array $extra = []): TestResponse
{
    return absrCall($user, 'POST', '/absences', $extra + ['employment_id' => test()->emp, 'absence_type_id' => $type, 'starts_on' => $from, 'ends_on' => $to ?? $from]);
}

test('el colaborador solicita, el jefe aprueba en el centro de aprobaciones y la ausencia ocupa el calendario y justifica la asistencia', function (): void {
    $type = absrType('dia_libre', ['day_count' => 'working']);

    // Simulación: del lunes 9 al domingo 15 son 7 días calendario y 4 hábiles (sin sábado, domingo ni el feriado del 11).
    $sim = absrCall($this->employee, 'POST', '/absences/simulate', ['employment_id' => $this->emp, 'absence_type_id' => $type, 'starts_on' => '2026-03-09', 'ends_on' => '2026-03-15']);
    $sim->assertOk();
    $this->assertMatchesOpenApi($sim, 'post', '/absences/simulate', 200);
    expect($sim->json('data'))->toMatchArray(['unit' => 'days', 'days' => '4', 'calendar_days' => 7, 'working_days' => 4, 'holidays' => ['2026-03-11'], 'issues' => [], 'role' => 'employee']);

    $created = absrDraft($this->employee, $type, '2026-03-09', '2026-03-15', ['reason' => 'Trámite familiar']);
    $created->assertCreated();
    $this->assertMatchesOpenApi($created, 'post', '/absences', 201);
    expect($created->json('data'))->toMatchArray(['status' => 'draft', 'days' => '4', 'calendar_days' => '7', 'reason' => 'Trámite familiar', 'registered_directly' => false])
        ->and($created->json('data.allowed_actions'))->toContain('submit', 'cancel');
    $id = (string) $created->json('data.id');

    // Editar el borrador recalcula.
    $patched = absrCall($this->employee, 'PATCH', "/absences/{$id}", ['ends_on' => '2026-03-13', 'status' => 'approved']);
    $patched->assertOk();
    $this->assertMatchesOpenApi($patched, 'patch', '/absences/{id}', 200);
    expect($patched->json('data'))->toMatchArray(['status' => 'draft', 'days' => '4', 'calendar_days' => '5', 'ends_on' => '2026-03-13']);

    $sent = absrCall($this->employee, 'POST', "/absences/{$id}/submit");
    $sent->assertOk();
    $this->assertMatchesOpenApi($sent, 'post', '/absences/{id}/submit', 200);
    expect($sent->json('data.status'))->toBe('pending')->and($sent->json('data.approval_request_id'))->not->toBeNull();
    $approval = (string) $sent->json('data.approval_request_id');

    // Sin acciones de aprobar en el recurso: decide el flujo.
    absrCall($this->boss, 'POST', "/approval-requests/{$approval}/approve")->assertOk()->assertJsonPath('data.status', 'approved');

    $show = absrCall($this->boss, 'GET', "/absences/{$id}");
    $show->assertOk();
    $this->assertMatchesOpenApi($show, 'get', '/absences/{id}', 200);
    expect($show->json('data'))->toMatchArray(['status' => 'approved', 'person_name' => 'Juan Prueba', 'absence_type_name' => 'Tipo dia_libre'])
        ->and($show->json('data.decided_at'))->not->toBeNull();

    $list = absrCall($this->boss, 'GET', '/absences?filter[status]=approved');
    $list->assertOk();
    $this->assertMatchesOpenApi($list, 'get', '/absences', 200);
    expect(collect($list->json('data'))->pluck('id')->all())->toBe([$id]);

    TenancyScope::runAs(ABSR_TENANT, function () use ($id): void {
        $events = DB::table('outbox_events')->where('aggregate_id', $id)->pluck('event_type')->all();
        expect($events)->toContain('absence.requested.v1', 'absence.approved.v1');
        expect(DB::table('audit_events')->where('entity_id', $id)->pluck('action')->all())->toContain('absence.created', 'absence.submitted', 'absence.approved');
        $port = app(ApprovedAbsences::class);
        expect($port->justifiesFullDay($this->emp, '2026-03-10'))->toBeTrue()
            ->and($port->justifiesFullDay($this->emp, '2026-03-16'))->toBeFalse()
            ->and($port->on($this->emp, '2026-03-12'))->toHaveCount(1);
    });

    // Calendario del equipo: el jefe la ve con enlace a la pantalla de ausencias.
    $cal = absrCall($this->boss, 'GET', '/calendar?from=2026-03-01&to=2026-03-31&sources=absences');
    $cal->assertOk();
    $this->assertMatchesOpenApi($cal, 'get', '/calendar', 200);
    $item = collect($cal->json('data'))->firstWhere('source', 'absences');
    expect($item)->toMatchArray(['starts_on' => '2026-03-09', 'link' => '/tiempo/ausencias?id='.$id]);

    // La tarea diaria la inicia y la finaliza.
    Artisan::call('absences:advance', ['--date' => '2026-03-09']);
    TenancyScope::runAs(ABSR_TENANT, fn () => expect(DB::table('absence_requests')->where('id', $id)->value('status'))->toBe('in_progress'));
    Artisan::call('absences:advance', ['--date' => '2026-03-14']);
    TenancyScope::runAs(ABSR_TENANT, fn () => expect(DB::table('absence_requests')->where('id', $id)->value('status'))->toBe('completed'));
});

test('una ausencia que se cruza con otra activa se rechaza (409) y la base también lo impide', function (): void {
    $type = absrType('dia_libre');
    $first = absrDraft($this->employee, $type, '2026-03-09', '2026-03-10');
    absrCall($this->employee, 'POST', '/absences/'.$first->json('data.id').'/submit')->assertOk();

    // Un borrador sí se guarda; al enviarlo se rechaza.
    $second = absrDraft($this->employee, $type, '2026-03-10', '2026-03-12');
    $second->assertCreated();
    $sim = absrCall($this->employee, 'POST', '/absences/simulate', ['employment_id' => $this->emp, 'absence_type_id' => $type, 'starts_on' => '2026-03-10']);
    expect(collect($sim->json('data.issues'))->pluck('code')->all())->toContain('absence_overlap');
    $rejected = absrCall($this->employee, 'POST', '/absences/'.$second->json('data.id').'/submit');
    $rejected->assertStatus(409)->assertJsonPath('error.code', 'absence_overlap');
    $this->assertMatchesOpenApi($rejected, 'post', '/absences/{id}/submit', 409);

    // Ni RRHH puede registrarla encima.
    absrCall($this->hr, 'POST', '/absences/'.$second->json('data.id').'/register')->assertStatus(409)->assertJsonPath('error.code', 'absence_overlap');
});

test('sustento: sin plazo se exige al enviar; con plazo queda pendiente, al adjuntarlo pasa a aprobación y vencido se rechaza', function (): void {
    $strict = absrType('cita_judicial', ['requires_document' => true]);
    $draft = absrDraft($this->employee, $strict, '2026-03-05');
    absrCall($this->employee, 'POST', '/absences/'.$draft->json('data.id').'/submit')->assertStatus(422)->assertJsonPath('error.code', 'document_required');

    $graced = absrType('licencia_familiar', ['requires_document' => true, 'document_grace_days' => 3]);
    $created = absrDraft($this->employee, $graced, '2026-03-05');
    $id = (string) $created->json('data.id');
    $sent = absrCall($this->employee, 'POST', "/absences/{$id}/submit");
    $sent->assertOk();
    expect($sent->json('data'))->toMatchArray(['status' => 'pending_document', 'document_due_on' => '2026-03-08', 'has_document' => false]);
    expect(collect($sent->json('meta.warnings'))->pluck('code')->all())->toContain('document_grace');

    // Un archivo que no es PDF ni imagen: 422.
    absrCall($this->employee, 'POST', "/absences/{$id}/attachments", ['file' => UploadedFile::fake()->createWithContent('nota.txt', 'hola')])->assertStatus(422);

    $attached = $this->actingAs($this->employee, 'ayni')->withHeaders(['X-Tenant-Id' => ABSR_TENANT, 'Idempotency-Key' => (string) Str::uuid()])
        ->post("/api/v1/absences/{$id}/attachments", ['file' => UploadedFile::fake()->createWithContent('constancia.pdf', "%PDF-1.4\n%prueba\n"), 'label' => 'Constancia'], ['Accept' => 'application/json']);
    $attached->assertCreated();
    $this->assertMatchesOpenApi($attached, 'post', '/absences/{id}/attachments', 201);
    expect($attached->json('data'))->toMatchArray(['status' => 'pending', 'has_document' => true, 'document_due_on' => null])
        ->and($attached->json('data.approval_request_id'))->not->toBeNull()
        ->and($attached->json('data.attachments.0.label'))->toBe('Constancia');

    // Descarga del sustento (no confidencial: el jefe puede verlo).
    $attachment = (string) $attached->json('data.attachments.0.id');
    $download = absrCall($this->boss, 'GET', "/absences/{$id}/attachments/{$attachment}");
    $download->assertOk();
    $this->assertMatchesOpenApi($download, 'get', '/absences/{id}/attachments/{attachment_id}', 200);
    expect($download->headers->get('Content-Type'))->toBe('application/pdf');

    // Otra con plazo que vence sin sustento: la tarea diaria la rechaza.
    $late = absrDraft($this->employee, $graced, '2026-03-20');
    absrCall($this->employee, 'POST', '/absences/'.$late->json('data.id').'/submit')->assertOk()->assertJsonPath('data.document_due_on', '2026-03-23');
    Artisan::call('absences:advance', ['--date' => '2026-03-24']);
    TenancyScope::runAs(ABSR_TENANT, fn () => expect(DB::table('absence_requests')->where('id', $late->json('data.id'))->value('status'))->toBe('rejected'));
});

test('RRHH registra directamente sin flujo, en fechas pasadas y con excepción al tope con motivo', function (): void {
    $type = absrType('suspension_propia', ['requestable_by' => 'hr', 'max_quantity' => 2, 'max_period' => 'year']);

    // El colaborador no puede pedir un tipo que solo registra RRHH.
    $own = absrDraft($this->employee, $type, '2026-03-20');
    absrCall($this->employee, 'POST', '/absences/'.$own->json('data.id').'/submit')->assertStatus(403)->assertJsonPath('error.code', 'not_requestable');
    absrCall($this->employee, 'POST', '/absences/'.$own->json('data.id').'/register')->assertStatus(403);

    // Ya ocurrió (16 y 17 de febrero): queda finalizada al registrarla.
    $past = absrDraft($this->hr, $type, '2026-02-16', '2026-02-17');
    $past->assertCreated();
    $registered = absrCall($this->hr, 'POST', '/absences/'.$past->json('data.id').'/register');
    $registered->assertOk();
    $this->assertMatchesOpenApi($registered, 'post', '/absences/{id}/register', 200);
    expect($registered->json('data'))->toMatchArray(['status' => 'completed', 'registered_directly' => true, 'approval_request_id' => null]);

    // Un día más supera el tope de 2 al año: sin motivo, 422; con motivo, aviso de excepción.
    $over = absrDraft($this->hr, $type, '2026-03-20');
    $blocked = absrCall($this->hr, 'POST', '/absences/'.$over->json('data.id').'/register');
    $blocked->assertStatus(422)->assertJsonPath('error.code', 'limit_exceeded');
    $this->assertMatchesOpenApi($blocked, 'post', '/absences/{id}/register', 422);
    absrCall($this->hr, 'PATCH', '/absences/'.$over->json('data.id'), ['exception_reason' => 'Acuerdo con el sindicato'])->assertOk();
    $ok = absrCall($this->hr, 'POST', '/absences/'.$over->json('data.id').'/register');
    $ok->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.exception_reason', 'Acuerdo con el sindicato');
    expect(collect($ok->json('meta.warnings'))->pluck('code')->all())->toContain('limit_exception');
});

test('anticipación mínima y unidad por horas', function (): void {
    $notice = absrType('capacitacion', ['min_notice_days' => 5]);
    $sim = absrCall($this->employee, 'POST', '/absences/simulate', ['employment_id' => $this->emp, 'absence_type_id' => $notice, 'starts_on' => '2026-03-04']);
    expect(collect($sim->json('data.issues'))->pluck('code')->all())->toBe(['insufficient_notice']);
    $draft = absrDraft($this->employee, $notice, '2026-03-04');
    absrCall($this->employee, 'POST', '/absences/'.$draft->json('data.id').'/submit')->assertStatus(422)->assertJsonPath('error.code', 'insufficient_notice');

    $hours = absrType('tramite', ['unit' => 'hours']);
    absrDraft($this->employee, $hours, '2026-03-05')->assertStatus(422);
    absrDraft($this->employee, $hours, '2026-03-05', '2026-03-06', ['start_time' => '09:00', 'end_time' => '11:00'])->assertStatus(422);
    $ok = absrDraft($this->employee, $hours, '2026-03-05', null, ['start_time' => '09:00', 'end_time' => '11:30']);
    $ok->assertCreated();
    expect($ok->json('data'))->toMatchArray(['unit' => 'hours', 'hours' => '2.5', 'days' => null, 'start_time' => '09:00', 'end_time' => '11:30']);

    // Un permiso por horas aprobado no justifica el día completo (RN-AUS-09).
    absrCall($this->hr, 'POST', '/absences/'.$ok->json('data.id').'/register')->assertOk();
    TenancyScope::runAs(ABSR_TENANT, fn () => expect(app(ApprovedAbsences::class)->justifiesFullDay($this->emp, '2026-03-05'))->toBeFalse());
});

test('el motivo médico es confidencial: el jefe ve «Ausencia justificada»; RRHH con permiso lo ve y queda en el registro de accesos', function (): void {
    $type = absrType('salud', [], true, 'medical_leave');
    $created = absrDraft($this->employee, $type, '2026-03-05', '2026-03-06', ['reason' => 'Gripe con fiebre']);
    $created->assertCreated();
    $id = (string) $created->json('data.id');
    // El titular ve su propio motivo.
    expect($created->json('data.medical_reason'))->toBe('Gripe con fiebre')->and($created->json('data'))->not->toHaveKey('reason');
    absrCall($this->employee, 'POST', "/absences/{$id}/submit")->assertOk();

    $boss = absrCall($this->boss, 'GET', "/absences/{$id}");
    $boss->assertOk();
    $this->assertMatchesOpenApi($boss, 'get', '/absences/{id}', 200);
    expect($boss->json('data'))->toMatchArray(['absence_type_name' => 'Ausencia justificada', 'absence_type_category' => null, 'absence_type_id' => null, 'starts_on' => '2026-03-05'])
        ->and($boss->json('data'))->not->toHaveKey('medical_reason')
        ->and($boss->json('data'))->not->toHaveKey('medical_attachments')
        ->and(json_encode($boss->json()))->not->toContain('Gripe');

    $hr = absrCall($this->hr, 'GET', "/absences/{$id}");
    expect($hr->json('data.medical_reason'))->toBe('Gripe con fiebre')->and($hr->json('data.absence_type_name'))->toBe('Tipo salud');
    TenancyScope::runAs(ABSR_TENANT, function () use ($id): void {
        $log = DB::table('data_access_logs')->where('user_id', $this->hr->id)->where('data_category', 'health')->first();
        expect($log)->not->toBeNull()->and($log->subject_person_id)->toBe($this->org['person']);
        // El motivo no queda en claro en la auditoría.
        expect(DB::table('audit_events')->where('entity_id', $id)->get()->map(fn ($r) => json_encode($r))->implode(' '))->not->toContain('Gripe');
    });

    // Solo las aprobadas ocupan el calendario; ya aprobada, el jefe tampoco ve el tipo allí.
    expect(absrCall($this->boss, 'GET', '/calendar?from=2026-03-01&to=2026-03-31&sources=absences')->json('data'))->toBe([]);
    $approval = (string) $hr->json('data.approval_request_id');
    absrCall($this->boss, 'POST', "/approval-requests/{$approval}/approve")->assertOk();
    $bossCal = collect(absrCall($this->boss, 'GET', '/calendar?from=2026-03-01&to=2026-03-31&sources=absences')->json('data'));
    $hrCal = collect(absrCall($this->hr, 'GET', '/calendar?from=2026-03-01&to=2026-03-31&sources=absences')->json('data'));
    expect($bossCal->firstWhere('id', $id)['subtitle'])->toBe('Ausencia justificada')
        ->and($hrCal->firstWhere('id', $id)['subtitle'])->toBe('Tipo salud');
});

test('cancelar: el titular cancela con motivo y se anula su aprobación; una aprobada ya iniciada no se cancela (409)', function (): void {
    $type = absrType('dia_libre');
    $created = absrDraft($this->employee, $type, '2026-03-09');
    $id = (string) $created->json('data.id');
    $sent = absrCall($this->employee, 'POST', "/absences/{$id}/submit");
    $approval = (string) $sent->json('data.approval_request_id');

    absrCall($this->employee, 'POST', "/absences/{$id}/cancel")->assertStatus(422);
    // El jefe no cancela la ausencia de su colaborador.
    absrCall($this->boss, 'POST', "/absences/{$id}/cancel", ['reason' => 'No'])->assertNotFound();
    $cancelled = absrCall($this->employee, 'POST', "/absences/{$id}/cancel", ['reason' => 'Cambio de planes']);
    $cancelled->assertOk();
    $this->assertMatchesOpenApi($cancelled, 'post', '/absences/{id}/cancel', 200);
    expect($cancelled->json('data'))->toMatchArray(['status' => 'cancelled', 'cancellation_reason' => 'Cambio de planes']);
    TenancyScope::runAs(ABSR_TENANT, fn () => expect(DB::table('workflow_instances')->where('id', $approval)->value('status'))->toBe('cancelled'));

    // Transiciones inválidas: enviar una cancelada, editarla.
    $again = absrCall($this->employee, 'POST', "/absences/{$id}/submit");
    $again->assertStatus(409)->assertJsonPath('error.code', 'invalid_transition');
    $this->assertMatchesOpenApi($again, 'post', '/absences/{id}/submit', 409);
    absrCall($this->employee, 'PATCH', "/absences/{$id}", ['reason' => 'x'])->assertStatus(409);

    // Aprobada que ya empezó (registrada por RRHH para hoy): no se cancela.
    $today = absrDraft($this->hr, $type, '2026-03-02');
    absrCall($this->hr, 'POST', '/absences/'.$today->json('data.id').'/register')->assertOk()->assertJsonPath('data.status', 'in_progress');
    absrCall($this->hr, 'POST', '/absences/'.$today->json('data.id').'/cancel', ['reason' => 'Error'])->assertStatus(409)->assertJsonPath('error.code', 'invalid_transition');
});

test('permisos, módulo y aislamiento: 403 sin permiso, 403 module_not_enabled, 404 desde otra organización o fuera del alcance', function (): void {
    $type = absrType('dia_libre');
    $created = absrDraft($this->employee, $type, '2026-03-09');
    $id = (string) $created->json('data.id');

    $auditor = absrUser(ABSR_TENANT, 'auditor');
    $denied = absrCall($auditor, 'POST', '/absences', ['employment_id' => $this->emp, 'absence_type_id' => $type, 'starts_on' => '2026-03-09']);
    $denied->assertStatus(403);
    $this->assertMatchesOpenApi($denied, 'post', '/absences', 403);
    absrCall($auditor, 'GET', "/absences/{$id}")->assertOk();

    // Otro colaborador (sin relación con Juan) no la ve ni registra a su nombre.
    $stranger = absrUser(ABSR_TENANT, 'empleado');
    absrCall($stranger, 'GET', "/absences/{$id}")->assertNotFound();
    absrCall($stranger, 'POST', '/absences', ['employment_id' => $this->emp, 'absence_type_id' => $type, 'starts_on' => '2026-03-10'])->assertNotFound();
    expect(absrCall($stranger, 'GET', '/absences')->json('data'))->toBe([]);

    $foreign = absrUser(ABSR_OTHER, 'admin_empresa');
    $missing = absrCall($foreign, 'GET', "/absences/{$id}");
    $missing->assertNotFound();
    $this->assertMatchesOpenApi($missing, 'get', '/absences/{id}', 404);
    absrCall($foreign, 'POST', "/absences/{$id}/register")->assertNotFound();

    // Datos inválidos: 422.
    $invalid = absrCall($this->employee, 'POST', '/absences', ['employment_id' => $this->emp, 'absence_type_id' => $type, 'starts_on' => '2026-03-10', 'ends_on' => '2026-03-09']);
    $invalid->assertStatus(422);
    $this->assertMatchesOpenApi($invalid, 'post', '/absences', 422);
    absrCall($this->employee, 'GET', '/absences?filter[status]=nada')->assertStatus(400)->assertJsonPath('error.code', 'invalid_filter');

    IdentityFixtures::disableModule(ABSR_TENANT, 'time_off');
    absrCall($this->admin, 'GET', '/absences')->assertStatus(403)->assertJsonPath('error.code', 'module_not_enabled');
});
