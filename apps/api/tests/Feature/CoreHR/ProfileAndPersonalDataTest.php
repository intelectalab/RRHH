<?php

/**
 * COL-3 · Ficha 360: cabecera con antigüedad calculada por el servidor y las otras relaciones de la persona.
 * COL-4 · Datos personales: direcciones (la anterior queda histórica), contactos de emergencia cifrados,
 * discapacidad solo con consentimiento (RN-COL-08), foto, y cambios propuestos por el propio colaborador
 * con aprobación de RRHH (RN-COL-06). Lecturas sensibles en el registro de accesos.
 * COL-5 · Datos laborales con «desde cuándo» y movimientos con fecha efectiva (nunca se sobrescribe).
 */

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Organization\Infrastructure\Models\JobProfile;
use App\Modules\Organization\Infrastructure\Models\LegalEntity;
use App\Modules\Organization\Infrastructure\Models\OrgUnit;
use App\Modules\Organization\Infrastructure\Models\Site;
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

const FICHA_TENANT = 'aaaaaaaa-0c03-4000-8000-00000000000a';
const FICHA_OTHER = 'bbbbbbbb-0c03-4000-8000-00000000000b';

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requiere PostgreSQL.');
    }
    TenancyScope::clear();
    foreach ([FICHA_TENANT => 'ficha-org', FICHA_OTHER => 'ficha-otra'] as $id => $slug) {
        DB::table('tenants')->insertOrIgnore(['id' => $id, 'slug' => $slug, 'name' => 'Grupo '.$slug, 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN']);
        DB::select('SELECT seed_tenant_defaults(?)', [$id]);
        TenancyScope::runAs($id, fn () => IdentityFixtures::enableAllModules());
    }
    $this->admin = fichaUser(FICHA_TENANT, 'admin_empresa');
    $this->rrhh = fichaUser(FICHA_TENANT, 'rrhh');
    TenancyScope::runAs(FICHA_TENANT, function (): void {
        $entity = LegalEntity::factory()->create();
        DB::table('legal_entity_regimes')->where('legal_entity_id', $entity->id)->update(['valid_from' => '2020-01-01']);
        $this->entity = $entity->id;
        $this->profile = JobProfile::factory()->create()->id;
        $this->profile2 = JobProfile::factory()->create()->id;
        $this->unit = OrgUnit::factory()->create(['legal_entity_id' => $this->entity])->id;
        $this->unit2 = OrgUnit::factory()->create(['legal_entity_id' => $this->entity])->id;
        $this->site = Site::factory()->create(['legal_entity_id' => $this->entity])->id;
    });
    $options = fichaCall($this->admin, 'get', '/employment-options')->assertOk();
    $contract = collect($options->json('data.contract_types'))->firstWhere('code', 'indeterminado')['id'];
    $hire = fichaCall($this->admin, 'post', '/employments', [
        'person' => ['first_names' => 'Rosa', 'paternal_surname' => 'Quispe', 'maternal_surname' => 'Mamani',
            'document_type' => 'dni', 'document_number' => (string) random_int(10000000, 89999999)],
        'legal_entity_id' => $this->entity, 'hire_date' => '2025-03-10', 'job_profile_id' => $this->profile,
        'org_unit_id' => $this->unit, 'site_id' => $this->site, 'contract_type_id' => $contract,
    ])->assertCreated();
    $this->employment = (string) $hire->json('data.id');
    $this->person = (string) $hire->json('data.person_id');
});

afterEach(fn () => TenancyScope::clear());

function fichaUser(string $tenant, string $role): User
{
    return TenancyScope::runAs($tenant, function () use ($role): User {
        $u = User::factory()->create();
        $u->roleAssignments()->create(['role_id' => Role::query()->where('system_role_code', $role)->value('id')]);

        return $u;
    });
}

/**
 * @param  array<string, mixed>  $body
 */
function fichaCall(User $user, string $method, string $path, array $body = []): TestResponse
{
    TenancyScope::clear();
    app('auth')->forgetGuards();
    app(PermissionResolver::class)->forget();
    app(ModuleGate::class)->forget();
    app(DataScope::class)->forget();

    return test()->actingAs($user, 'ayni')
        ->withHeaders(['X-Tenant-Id' => $user->tenant_id, 'Idempotency-Key' => (string) Str::uuid()])
        ->json($method, '/api/v1'.$path, $body);
}

function fichaPng(): string
{
    return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
}

/** Usuario del rol con la persona vinculada (users.person_id): ve y propone sobre lo propio. */
function fichaEmployee(string $personId): User
{
    $user = fichaUser(FICHA_TENANT, 'empleado');
    TenancyScope::runAs(FICHA_TENANT, fn () => DB::table('users')->where('id', $user->id)->update(['person_id' => $personId]));

    return $user->refresh();
}

// ── COL-3 · Ficha 360 ───────────────────────────────────────────────────────────────────────────────

test('COL-3: la cabecera trae puesto, estado, antigüedad calculada y las relaciones de la persona', function (): void {
    $r = fichaCall($this->admin, 'get', "/employments/{$this->employment}/profile")->assertOk();
    $this->assertMatchesOpenApi($r, 'get', '/employments/{id}/profile', 200);

    $expected = (new DateTime('2025-03-10'))->diff(new DateTime(now()->toDateString()));
    expect($r->json('data.person_name'))->toBe('Rosa Quispe Mamani')
        ->and($r->json('data.status'))->toBe('active')
        ->and($r->json('data.seniority.years'))->toBe($expected->y)
        ->and($r->json('data.seniority.months'))->toBe($expected->m)
        ->and($r->json('data.seniority.days'))->toBe($expected->d)
        ->and($r->json('data.has_photo'))->toBeFalse()
        ->and(collect($r->json('data.relations'))->pluck('id')->all())->toBe([$this->employment]);
});

test('COL-3: la ficha de otra organización o fuera del alcance no existe (404)', function (): void {
    $other = fichaUser(FICHA_OTHER, 'admin_empresa');
    fichaCall($other, 'get', "/employments/{$this->employment}/profile")->assertNotFound();
    fichaCall($other, 'get', "/employments/{$this->employment}/work-data")->assertNotFound();
    fichaCall($other, 'get', "/people/{$this->person}/addresses")->assertNotFound();
    // Un empleado sin la persona vinculada no ve fichas ajenas.
    $stranger = fichaUser(FICHA_TENANT, 'empleado');
    fichaCall($stranger, 'get', "/employments/{$this->employment}/profile")->assertNotFound();
});

// ── COL-5 · Datos laborales y movimientos ───────────────────────────────────────────────────────────

test('COL-5: cada dato laboral dice desde cuándo rige y a qué historia enlaza', function (): void {
    $r = fichaCall($this->admin, 'get', "/employments/{$this->employment}/work-data")->assertOk();
    $this->assertMatchesOpenApi($r, 'get', '/employments/{id}/work-data', 200);

    expect($r->json('data.fields.org_unit.value'))->toBe($this->unit)
        ->and($r->json('data.fields.org_unit.since'))->toBe('2025-03-10')
        ->and($r->json('data.fields.org_unit.history'))->toBe('assignments')
        ->and($r->json('data.fields.labor_regime.since'))->toBe('2025-03-10')
        ->and($r->json('data.fields.status.value'))->toBe('active')
        ->and($r->json('data.seniority.total_days'))->toBeGreaterThan(0);

    fichaCall($this->admin, 'get', "/employments/{$this->employment}/work-data?as_of=ayer")->assertStatus(400);
});

test('COL-5: un movimiento cierra la asignación vigente y abre otra; el pasado no cambia', function (): void {
    // Sin tipos sometidos a aprobación, RRHH aplica la promoción en el acto (HIS-2).
    TenancyScope::runAs(FICHA_TENANT, fn () => app(ParameterResolver::class)->set('movimientos.tipos_con_aprobacion', [], 'organization', null, null, 'prueba'));
    $move = fichaCall($this->admin, 'post', "/employments/{$this->employment}/personnel-actions", [
        'effective_on' => '2026-02-01', 'reason' => 'Promoción a supervisor',
        'changes' => ['org_unit_id' => $this->unit2, 'job_profile_id' => $this->profile2],
    ]);
    $move->assertCreated();
    $this->assertMatchesOpenApi($move, 'post', '/employments/{employment_id}/personnel-actions', 201);
    expect($move->json('data.kind'))->toBe('promotion')->and($move->json('data.status'))->toBe('applied')
        ->and($move->json('data.effective_on'))->toBe('2026-02-01');

    $history = fichaCall($this->admin, 'get', "/employments/{$this->employment}/assignments")->assertOk();
    $this->assertMatchesOpenApi($history, 'get', '/employments/{employment_id}/assignments', 200);
    expect($history->json('data'))->toHaveCount(2)
        ->and($history->json('data.0.kind'))->toBe('promotion')
        ->and($history->json('data.0.valid_from'))->toBe('2026-02-01')
        ->and($history->json('data.1.valid_to'))->toBe('2026-01-31')
        ->and($history->json('data.1.org_unit_id'))->toBe($this->unit);

    // A una fecha anterior sigue la unidad de entonces; hoy, la nueva.
    expect(fichaCall($this->admin, 'get', "/employments/{$this->employment}/work-data?as_of=2026-01-15")->json('data.fields.org_unit.value'))->toBe($this->unit)
        ->and(fichaCall($this->admin, 'get', "/employments/{$this->employment}/work-data?as_of=2026-02-15")->json('data.fields.org_unit.since'))->toBe('2026-02-01');

    $one = fichaCall($this->admin, 'get', "/employments/{$this->employment}/assignments/".$history->json('data.0.id'))->assertOk();
    $this->assertMatchesOpenApi($one, 'get', '/employments/{employment_id}/assignments/{id}', 200);

    TenancyScope::runAs(FICHA_TENANT, function (): void {
        $action = DB::table('personnel_actions')->where('employment_id', $this->employment)->first();
        expect($action->kind)->toBe('promotion')->and($action->status)->toBe('applied')->and($action->effective_on)->toBe('2026-02-01');
        expect(DB::table('audit_events')->where('entity_id', $this->employment)->where('action', 'employment.assignment_changed')->exists())->toBeTrue();
        expect(DB::table('outbox_events')->where('aggregate_id', $this->employment)->where('event_type', 'employee.updated.v1')->exists())->toBeTrue();
    });
});

test('COL-5: el movimiento se rechaza el mismo día, sin cambios, antes del ingreso o sin permiso', function (): void {
    $path = "/employments/{$this->employment}/personnel-actions";
    // Mismo día que la asignación vigente: se corrige con otro registro, no se sobrescribe (409).
    fichaCall($this->admin, 'post', $path, ['effective_on' => '2025-03-10', 'changes' => ['org_unit_id' => $this->unit2]])->assertStatus(409);
    fichaCall($this->admin, 'post', $path, ['effective_on' => '2026-03-01', 'changes' => ['org_unit_id' => $this->unit]])->assertStatus(422);
    fichaCall($this->admin, 'post', $path, ['effective_on' => '2024-01-01', 'changes' => ['org_unit_id' => $this->unit2]])->assertStatus(422);
    fichaCall($this->admin, 'post', $path, ['changes' => ['org_unit_id' => $this->unit2]])->assertStatus(422);
    // La asignación ya no se escribe directamente: no hay ruta.
    fichaCall($this->admin, 'post', "/employments/{$this->employment}/assignments", ['valid_from' => '2026-03-01', 'org_unit_id' => $this->unit2])->assertStatus(405);
    $employee = fichaEmployee($this->person);
    fichaCall($employee, 'post', $path, ['effective_on' => '2026-03-01', 'changes' => ['org_unit_id' => $this->unit2]])->assertForbidden();
});

// ── COL-4 · Datos personales ────────────────────────────────────────────────────────────────────────

test('COL-4: una dirección nueva deja la anterior como histórica; se guarda cifrada y su lectura se registra', function (): void {
    $first = fichaCall($this->admin, 'post', "/people/{$this->person}/addresses", ['address' => 'Jr. Junín 456, Cusco', 'district_code' => '080101']);
    $first->assertCreated();
    $this->assertMatchesOpenApi($first, 'post', '/people/{id}/addresses', 201);
    fichaCall($this->admin, 'post', "/people/{$this->person}/addresses", ['address' => 'Av. Sol 789, Cusco'])->assertCreated();

    $list = fichaCall($this->admin, 'get', "/people/{$this->person}/addresses")->assertOk();
    $this->assertMatchesOpenApi($list, 'get', '/people/{id}/addresses', 200);
    expect($list->json('data'))->toHaveCount(1)->and($list->json('data.0.address'))->toBe('Av. Sol 789, Cusco');
    expect(fichaCall($this->admin, 'get', "/people/{$this->person}/addresses?history=true")->json('data'))->toHaveCount(2);

    TenancyScope::runAs(FICHA_TENANT, function (): void {
        $raw = DB::table('person_addresses')->where('person_id', $this->person)->pluck('address_enc')->implode(' ');
        expect($raw)->not->toContain('Cusco');
        expect(DB::table('data_access_logs')->where('entity_type', 'PersonAddress')->where('subject_person_id', $this->person)->exists())->toBeTrue();
        $audit = DB::table('audit_events')->where('action', 'person.address_changed')->latest('occurred_at')->first();
        expect((string) $audit->after_data)->not->toContain('Cusco');
    });

    $id = (string) $first->json('data.id');
    $patch = fichaCall($this->admin, 'patch', "/people/{$this->person}/addresses/{$id}", ['address' => 'Jr. Junín 465, Cusco']);
    $patch->assertOk();
    $this->assertMatchesOpenApi($patch, 'patch', '/people/{id}/addresses/{address_id}', 200);
    $del = fichaCall($this->admin, 'delete', "/people/{$this->person}/addresses/{$id}");
    $del->assertOk();
    $this->assertMatchesOpenApi($del, 'delete', '/people/{id}/addresses/{address_id}', 200);
});

test('COL-4: sin el permiso de datos personales no se leen ni se registran direcciones o contactos', function (): void {
    $manager = fichaUser(FICHA_TENANT, 'manager');
    TenancyScope::runAs(FICHA_TENANT, fn () => DB::table('role_permissions')
        ->whereIn('role_id', Role::query()->where('system_role_code', 'manager')->pluck('id'))
        ->where('permission_code', 'people.people.view')->update(['scope' => 'organization']));
    fichaCall($manager, 'get', "/people/{$this->person}/addresses")->assertForbidden();
    fichaCall($manager, 'get', "/people/{$this->person}/emergency-contacts")->assertForbidden();
    fichaCall($manager, 'post', "/people/{$this->person}/addresses", ['address' => 'Av. Sol 789'])->assertForbidden();
});

test('COL-4: contactos de emergencia con teléfono cifrado; validación y edición', function (): void {
    $add = fichaCall($this->admin, 'post', "/people/{$this->person}/emergency-contacts", ['full_name' => 'Juan Quispe', 'relationship' => 'Hermano', 'phone' => '+51 987 654 321']);
    $add->assertCreated();
    $this->assertMatchesOpenApi($add, 'post', '/people/{id}/emergency-contacts', 201);
    fichaCall($this->admin, 'post', "/people/{$this->person}/emergency-contacts", ['full_name' => 'X'])->assertStatus(422);

    $id = (string) $add->json('data.id');
    $patch = fichaCall($this->admin, 'patch', "/people/{$this->person}/emergency-contacts/{$id}", ['phone' => '984111222']);
    $patch->assertOk()->assertJsonPath('data.phone', '984111222');
    $this->assertMatchesOpenApi($patch, 'patch', '/people/{id}/emergency-contacts/{contact_id}', 200);

    $list = fichaCall($this->admin, 'get', "/people/{$this->person}/emergency-contacts")->assertOk();
    $this->assertMatchesOpenApi($list, 'get', '/people/{id}/emergency-contacts', 200);
    expect($list->json('data'))->toHaveCount(1);
    TenancyScope::runAs(FICHA_TENANT, fn () => expect(DB::table('emergency_contacts')->where('id', $id)->value('phone_enc'))->not->toContain('984111222'));

    $del = fichaCall($this->admin, 'delete', "/people/{$this->person}/emergency-contacts/{$id}")->assertOk();
    $this->assertMatchesOpenApi($del, 'delete', '/people/{id}/emergency-contacts/{contact_id}', 200);
    expect(fichaCall($this->admin, 'get', "/people/{$this->person}/emergency-contacts")->json('data'))->toHaveCount(0);
});

test('RN-COL-08: la discapacidad solo se registra con consentimiento y como dato de salud', function (): void {
    // Administrador de empresa: sin people.people.health_data.view.
    fichaCall($this->admin, 'get', "/people/{$this->person}/sensitive-data")->assertForbidden();

    fichaCall($this->rrhh, 'put', "/people/{$this->person}/sensitive-data", ['disability' => 'Discapacidad auditiva'])
        ->assertStatus(422)->assertJsonPath('error.details.0.field', 'consent');

    $saved = fichaCall($this->rrhh, 'put', "/people/{$this->person}/sensitive-data", [
        'disability' => 'Discapacidad auditiva', 'blood_type' => 'O+', 'consent' => ['granted' => true, 'channel' => 'paper', 'evidence' => 'Formato firmado'],
    ]);
    $saved->assertOk()->assertJsonPath('data.disability', 'Discapacidad auditiva')->assertJsonPath('data.consent.status', 'granted');
    $this->assertMatchesOpenApi($saved, 'put', '/people/{id}/sensitive-data', 200);
    $read = fichaCall($this->rrhh, 'get', "/people/{$this->person}/sensitive-data")->assertOk();
    $this->assertMatchesOpenApi($read, 'get', '/people/{id}/sensitive-data', 200);

    TenancyScope::runAs(FICHA_TENANT, function (): void {
        expect(DB::table('person_sensitive_data')->where('person_id', $this->person)->value('disability_enc'))->not->toContain('auditiva')
            ->and(DB::table('consents')->where('person_id', $this->person)->where('status', 'granted')->exists())->toBeTrue()
            ->and(DB::table('data_access_logs')->where('entity_type', 'PersonSensitiveData')->where('data_category', 'health')->exists())->toBeTrue()
            ->and((string) DB::table('audit_events')->where('action', 'person.sensitive_data_changed')->value('after_data'))->not->toContain('auditiva');
    });
});

test('COL-4: la foto se sube, se ve y se quita con permiso', function (): void {
    Storage::fake('s3');
    TenancyScope::clear();
    app('auth')->forgetGuards();
    $up = test()->actingAs($this->admin, 'ayni')->withHeaders(['X-Tenant-Id' => FICHA_TENANT, 'Accept' => 'application/json', 'Idempotency-Key' => (string) Str::uuid()])
        ->post("/api/v1/people/{$this->person}/photo", ['file' => UploadedFile::fake()->createWithContent('foto.png', fichaPng())]);
    $up->assertOk()->assertJsonPath('data.has_photo', true);
    $this->assertMatchesOpenApi($up, 'post', '/people/{id}/photo', 200);
    expect(fichaCall($this->admin, 'get', "/employments/{$this->employment}/profile")->json('data.has_photo'))->toBeTrue();

    TenancyScope::clear();
    app('auth')->forgetGuards();
    test()->actingAs($this->admin, 'ayni')->withHeaders(['X-Tenant-Id' => FICHA_TENANT])
        ->get("/api/v1/people/{$this->person}/photo")->assertOk()->assertHeader('Content-Type', 'image/png');

    $del = fichaCall($this->admin, 'delete', "/people/{$this->person}/photo")->assertOk()->assertJsonPath('data.has_photo', false);
    $this->assertMatchesOpenApi($del, 'delete', '/people/{id}/photo', 200);
    fichaCall($this->admin, 'get', "/people/{$this->person}/photo")->assertNotFound();
});

// ── RN-COL-06 · Cambios propuestos por el colaborador ───────────────────────────────────────────────

test('RN-COL-06: el colaborador propone cambios; los de bajo riesgo se aplican y los demás esperan a RRHH', function (): void {
    $employee = fichaEmployee($this->person);
    $req = fichaCall($employee, 'post', '/profile-change-requests', ['changes' => ['preferred_name' => 'Rosi', 'marital_status' => 'married', 'address' => 'Calle Nueva 12, Cusco']]);
    $req->assertCreated()->assertJsonPath('data.status', 'pending');
    $this->assertMatchesOpenApi($req, 'post', '/profile-change-requests', 201);
    expect($req->json('data.applied_fields'))->toBe(['preferred_name'])
        ->and($req->json('data.pending_fields'))->toBe(['marital_status', 'address']);

    TenancyScope::runAs(FICHA_TENANT, function (): void {
        $p = DB::table('people')->where('id', $this->person)->first();
        expect($p->preferred_name)->toBe('Rosi')->and($p->marital_status)->toBeNull();
        expect((string) DB::table('profile_change_requests')->where('person_id', $this->person)->value('changes'))->not->toContain('married');
    });

    // Una segunda solicitud con campos que esperan aprobación no se abre mientras haya otra pendiente.
    fichaCall($employee, 'post', '/profile-change-requests', ['changes' => ['marital_status' => 'single']])->assertStatus(422);
    // El colaborador no aprueba su propia solicitud.
    fichaCall($employee, 'post', '/profile-change-requests/'.$req->json('data.id').'/approve')->assertForbidden();

    $list = fichaCall($this->rrhh, 'get', '/profile-change-requests?status=pending')->assertOk();
    $this->assertMatchesOpenApi($list, 'get', '/profile-change-requests', 200);
    expect(collect($list->json('data'))->pluck('id'))->toContain($req->json('data.id'));

    $ok = fichaCall($this->rrhh, 'post', '/profile-change-requests/'.$req->json('data.id').'/approve');
    $ok->assertOk()->assertJsonPath('data.status', 'approved');
    $this->assertMatchesOpenApi($ok, 'post', '/profile-change-requests/{id}/approve', 200);
    TenancyScope::runAs(FICHA_TENANT, fn () => expect(DB::table('people')->where('id', $this->person)->value('marital_status'))->toBe('married'));
    expect(fichaCall($this->admin, 'get', "/people/{$this->person}/addresses")->json('data.0.address'))->toBe('Calle Nueva 12, Cusco');

    // Ya aprobada: no admite otra transición (409).
    fichaCall($this->rrhh, 'post', '/profile-change-requests/'.$req->json('data.id').'/reject', ['reason' => 'x'])->assertStatus(409);
    $show = fichaCall($employee, 'get', '/profile-change-requests/'.$req->json('data.id'))->assertOk();
    $this->assertMatchesOpenApi($show, 'get', '/profile-change-requests/{id}', 200);
});

test('RN-COL-06: rechazo con motivo, cancelación y solo sobre los propios datos', function (): void {
    $employee = fichaEmployee($this->person);
    $req = fichaCall($employee, 'post', '/profile-change-requests', ['changes' => ['birth_date' => '1990-05-04']])->assertCreated();
    $id = (string) $req->json('data.id');
    fichaCall($this->rrhh, 'post', "/profile-change-requests/{$id}/reject")->assertStatus(422);
    $rej = fichaCall($this->rrhh, 'post', "/profile-change-requests/{$id}/reject", ['reason' => 'Falta el sustento']);
    $rej->assertOk()->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.rejection_reason', 'Falta el sustento');
    $this->assertMatchesOpenApi($rej, 'post', '/profile-change-requests/{id}/reject', 200);

    $again = fichaCall($employee, 'post', '/profile-change-requests', ['changes' => ['birth_date' => '1990-05-04']])->assertCreated();
    $cancel = fichaCall($employee, 'post', '/profile-change-requests/'.$again->json('data.id').'/cancel');
    $cancel->assertOk()->assertJsonPath('data.status', 'cancelled');
    $this->assertMatchesOpenApi($cancel, 'post', '/profile-change-requests/{id}/cancel', 200);

    // RRHH no propone cambios por otro: los edita desde la ficha.
    fichaCall($this->rrhh, 'post', '/profile-change-requests', ['person_id' => $this->person, 'changes' => ['preferred_name' => 'R']])->assertForbidden();
    // Sin cambios reconocibles: 422.
    fichaCall($employee, 'post', '/profile-change-requests', ['changes' => []])->assertStatus(422);
});

test('RN-COL-06: sin aprobación configurada, todo se aplica en el acto', function (): void {
    TenancyScope::runAs(FICHA_TENANT, fn () => app(ParameterResolver::class)->set('colaboradores.cambios_perfil_requieren_aprobacion', false, 'organization', null, null, 'Prueba'));
    $employee = fichaEmployee($this->person);
    $req = fichaCall($employee, 'post', '/profile-change-requests', ['changes' => ['marital_status' => 'single']]);
    $req->assertCreated()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.pending_fields', []);
    TenancyScope::runAs(FICHA_TENANT, fn () => expect(DB::table('people')->where('id', $this->person)->value('marital_status'))->toBe('single'));
});
