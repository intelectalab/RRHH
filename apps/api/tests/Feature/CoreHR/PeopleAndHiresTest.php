<?php

/**
 * COL-1 · Personas y deduplicación: una persona existe una sola vez por organización (RN-COL-01), el
 * documento se valida según su tipo y se guarda cifrado; los datos personales son campos sensibles.
 * COL-2 · Alta de relación laboral: el ingreso crea asignación, régimen, contrato y compensación con la
 * misma fecha, emite employee.hired una sola vez, consume la posición y respeta RN-COL-02/04/13 (ME-01).
 * COL-9 · Listado de colaboradores: alcance aplicado en la consulta, filtros, búsqueda por documento y
 * permisos por campo.
 */

use App\Modules\CoreHR\Domain\EmploymentStateMachine;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Organization\Infrastructure\Models\JobProfile;
use App\Modules\Organization\Infrastructure\Models\LegalEntity;
use App\Modules\Organization\Infrastructure\Models\OrgUnit;
use App\Modules\Organization\Infrastructure\Models\Position;
use App\Modules\Organization\Infrastructure\Models\Site;
use App\Shared\Permissions\ModuleGate;
use App\Shared\Permissions\PermissionResolver;
use App\Shared\Settings\ParameterResolver;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\IdentityFixtures;

uses(DatabaseTransactions::class);

const COL_TENANT = 'aaaaaaaa-0c01-4000-8000-00000000000a';
const COL_OTHER = 'bbbbbbbb-0c01-4000-8000-00000000000b';

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requiere PostgreSQL.');
    }
    TenancyScope::clear();
    foreach ([COL_TENANT => 'col-org', COL_OTHER => 'col-otra'] as $id => $slug) {
        DB::table('tenants')->insertOrIgnore(['id' => $id, 'slug' => $slug, 'name' => 'Grupo '.$slug, 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN']);
        DB::select('SELECT seed_tenant_defaults(?)', [$id]);
        TenancyScope::runAs($id, fn () => IdentityFixtures::enableAllModules());
    }
    $this->admin = colUser(COL_TENANT, 'admin_empresa');
    TenancyScope::runAs(COL_TENANT, function (): void {
        $entity = LegalEntity::factory()->create();
        // Régimen vigente desde antes de los ingresos de prueba (la fábrica lo abre hoy).
        DB::table('legal_entity_regimes')->where('legal_entity_id', $entity->id)->update(['valid_from' => '2020-01-01']);
        $this->entity = $entity->id;
        $this->profile = JobProfile::factory()->create()->id;
        $this->unit = OrgUnit::factory()->create(['legal_entity_id' => $this->entity])->id;
        $this->site = Site::factory()->create(['legal_entity_id' => $this->entity])->id;
    });
    // Los tipos de contrato del paquete Perú se precargan al pedir las opciones del ingreso.
    $options = colCall($this->admin, 'get', '/employment-options')->assertOk();
    $this->assertMatchesOpenApi($options, 'get', '/employment-options', 200);
    $this->indefinite = collect($options->json('data.contract_types'))->firstWhere('code', 'indeterminado')['id'];
    $this->fixedTerm = collect($options->json('data.contract_types'))->firstWhere('code', 'necesidad_mercado')['id'];
});

afterEach(fn () => TenancyScope::clear());

function colUser(string $tenant, string $role): User
{
    return TenancyScope::runAs($tenant, function () use ($role): User {
        $u = User::factory()->create();
        $u->roleAssignments()->create(['role_id' => Role::query()->where('system_role_code', $role)->value('id')]);

        return $u;
    });
}

/**
 * @param  array<string, mixed>  $body
 * @param  array<string, string>  $headers
 */
function colCall(User $user, string $method, string $path, array $body = [], array $headers = []): TestResponse
{
    TenancyScope::clear();
    app('auth')->forgetGuards();
    app(PermissionResolver::class)->forget();
    app(ModuleGate::class)->forget();

    return test()->actingAs($user, 'ayni')
        ->withHeaders($headers + ['X-Tenant-Id' => $user->tenant_id, 'Idempotency-Key' => (string) Str::uuid()])
        ->json($method, '/api/v1'.$path, $body);
}

/**
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function colHire(array $extra = []): array
{
    $t = test();

    return array_replace([
        'person' => ['first_names' => 'Rosa', 'paternal_surname' => 'Quispe', 'maternal_surname' => 'Mamani',
            'document_type' => 'dni', 'document_number' => (string) random_int(10000000, 89999999)],
        'legal_entity_id' => $t->entity,
        'hire_date' => '2026-01-05',
        'job_profile_id' => $t->profile,
        'org_unit_id' => $t->unit,
        'site_id' => $t->site,
        'contract_type_id' => $t->indefinite,
    ], $extra);
}

test('COL-1 · la persona se crea una sola vez: el mismo documento responde 409 con la persona existente', function (): void {
    $body = ['first_names' => 'Luis', 'paternal_surname' => 'Huamán', 'document_type' => 'dni', 'document_number' => '4567 8912', 'birth_date' => '1990-04-12'];
    $created = colCall($this->admin, 'post', '/people', $body);
    $this->assertMatchesOpenApi($created, 'post', '/people', 201);
    expect($created->json('data.full_name'))->toBe('Luis Huamán')
        ->and($created->json('data.document_number'))->toBe('45678912')
        ->and($created->json('data.document_number_masked'))->toBe('••••8912');

    $again = colCall($this->admin, 'post', '/people', ['first_names' => 'Otro', 'paternal_surname' => 'Nombre', 'document_type' => 'dni', 'document_number' => '45678912']);
    $this->assertMatchesOpenApi($again, 'post', '/people', 409);
    expect($again->json('error.code'))->toBe('person_exists')
        ->and($again->json('error.meta.person_id'))->toBe($created->json('data.id'));

    $match = colCall($this->admin, 'get', '/people/document-match?document_type=dni&document_number=45678912');
    $this->assertMatchesOpenApi($match, 'get', '/people/document-match', 200);
    expect($match->json('data.exists'))->toBeTrue()->and($match->json('data.person.id'))->toBe($created->json('data.id'));

    // El número no se guarda en claro: cifrado y con huella.
    TenancyScope::runAs(COL_TENANT, function (): void {
        $doc = DB::table('person_documents')->where('doc_type', 'dni')->latest('created_at')->first();
        expect($doc->number_enc)->not->toContain('45678912')->and($doc->number_hash)->not->toContain('45678912');
        expect(DB::table('people')->count())->toBe(1);
        $audit = DB::table('audit_events')->where('action', 'person.created')->sole();
        expect((string) $audit->after_data)->not->toContain('45678912');
    });
});

test('COL-1 · el número se valida según el tipo de documento (422)', function (): void {
    $bad = colCall($this->admin, 'post', '/people', ['first_names' => 'Ana', 'paternal_surname' => 'Ríos', 'document_type' => 'dni', 'document_number' => '1234']);
    $this->assertMatchesOpenApi($bad, 'post', '/people', 422);
    expect($bad->json('error.details.0.field'))->toBe('document_number');

    $ok = colCall($this->admin, 'post', '/people', ['first_names' => 'Ana', 'paternal_surname' => 'Ríos', 'document_type' => 'passport', 'document_number' => 'ab-123456']);
    $this->assertMatchesOpenApi($ok, 'post', '/people', 201);
    expect($ok->json('data.document_type'))->toBe('passport');
});

test('COL-1 · sin permiso de datos personales no viajan ni se registran (y su lectura queda en el registro de accesos)', function (): void {
    $person = colCall($this->admin, 'post', '/people', ['first_names' => 'Carla', 'paternal_surname' => 'Soto', 'document_type' => 'dni', 'document_number' => '70112233', 'birth_date' => '1992-02-02', 'personal_email' => 'carla@correo.test'])->json('data.id');

    $viewer = IdentityFixtures::userWithPermissions(COL_TENANT, ['people.people.view', 'people.people.create']);
    $seen = colCall($viewer, 'get', '/people/'.$person);
    $this->assertMatchesOpenApi($seen, 'get', '/people/{id}', 200);
    expect($seen->json('data'))->not->toHaveKey('document_number')->not->toHaveKey('birth_date')->not->toHaveKey('personal_email')
        ->and($seen->json('data.document_number_masked'))->toBe('••••2233');

    $forbidden = colCall($viewer, 'post', '/people', ['first_names' => 'Pepe', 'paternal_surname' => 'Lara', 'document_type' => 'dni', 'document_number' => '70112234', 'birth_date' => '1990-01-01']);
    $this->assertMatchesOpenApi($forbidden, 'post', '/people', 422);

    colCall($this->admin, 'get', '/people/'.$person)->assertOk();
    TenancyScope::runAs(COL_TENANT, function () use ($person): void {
        $log = DB::table('data_access_logs')->where('entity_type', 'Person')->where('entity_id', $person)->where('user_id', $this->admin->id)->orderByDesc('occurred_at')->first();
        expect($log->data_category)->toBe('other')->and((string) $log->fields)->toContain('document_number');
    });
});

test('COL-2 · el ingreso crea asignación, régimen, contrato y compensación con la misma fecha y emite employee.hired una sola vez', function (): void {
    $key = (string) Str::uuid();
    $body = colHire(['base_salary' => ['amount' => '3500.00', 'currency' => 'PEN'], 'work_email' => 'Rosa.Quispe@Empresa.test']);
    $first = colCall($this->admin, 'post', '/employments', $body, ['Idempotency-Key' => $key]);
    $this->assertMatchesOpenApi($first, 'post', '/employments', 201);
    $id = (string) $first->json('data.id');
    expect($first->json('data.status'))->toBe('active')
        ->and($first->json('data.employee_code'))->toBe('00001')
        ->and($first->json('data.labor_regime_code'))->toBe('pe_general')
        ->and($first->json('data.work_email'))->toBe('rosa.quispe@empresa.test')
        ->and($first->json('data.base_salary'))->toBe(['amount' => '3500.00', 'currency' => 'PEN'])
        ->and($first->json('data.allowed_actions'))->toBe(['terminate']);

    // Repetir con la misma clave no crea otro ingreso ni otro evento.
    $repeat = colCall($this->admin, 'post', '/employments', $body, ['Idempotency-Key' => $key]);
    expect($repeat->status())->toBe(201)->and($repeat->json('data.id'))->toBe($id);

    TenancyScope::runAs(COL_TENANT, function () use ($id): void {
        expect(DB::table('employments')->count())->toBe(1);
        foreach (['employment_assignments', 'employment_labor_regimes', 'employment_compensations', 'contracts'] as $table) {
            $row = DB::table($table)->where('employment_id', $id)->sole();
            expect((string) $row->valid_from)->toBe('2026-01-05');
        }
        expect(DB::table('contracts')->where('employment_id', $id)->value('status'))->toBe('draft');
        expect(DB::table('outbox_events')->where('event_type', 'employee.hired.v1')->where('aggregate_id', $id)->count())->toBe(1);
        expect(DB::table('employment_status_events')->where('employment_id', $id)->value('to_status'))->toBe('active');
        expect(DB::table('audit_events')->where('action', 'employment.hired')->where('entity_id', $id)->count())->toBe(1);
    });
});

test('COL-2 · con fecha futura queda «Por ingresar», no se activa antes de tiempo y se activa ese día (RN-COL-04)', function (): void {
    $future = now()->addDays(10)->toDateString();
    $created = colCall($this->admin, 'post', '/employments', colHire(['hire_date' => $future]));
    $this->assertMatchesOpenApi($created, 'post', '/employments', 201);
    $id = (string) $created->json('data.id');
    expect($created->json('data.status'))->toBe('pending')->and($created->json('data.allowed_actions'))->toBe(['activate', 'cancel']);

    $early = colCall($this->admin, 'post', "/employments/{$id}/activate");
    $this->assertMatchesOpenApi($early, 'post', '/employments/{id}/activate', 422);

    $this->travelTo(now()->addDays(10)->setTime(1, 0));
    Artisan::call('employments:activate-due');
    TenancyScope::runAs(COL_TENANT, function () use ($id): void {
        expect(DB::table('employments')->where('id', $id)->value('status'))->toBe('active');
        expect(DB::table('outbox_events')->where('event_type', 'employment.activated.v1')->where('aggregate_id', $id)->count())->toBe(1);
    });
});

test('COL-2 · el ingreso consume la posición vacante y la política puede exigirla', function (): void {
    $position = TenancyScope::runAs(COL_TENANT, fn () => Position::factory()->create([
        'legal_entity_id' => $this->entity, 'job_profile_id' => $this->profile, 'org_unit_id' => $this->unit, 'site_id' => $this->site, 'status' => 'open',
    ])->id);
    $hired = colCall($this->admin, 'post', '/employments', colHire(['position_id' => $position, 'job_profile_id' => null, 'org_unit_id' => null, 'site_id' => null]));
    $this->assertMatchesOpenApi($hired, 'post', '/employments', 201);
    expect($hired->json('data.position_id'))->toBe($position)->and($hired->json('data.org_unit_id'))->toBe($this->unit);
    expect(TenancyScope::runAs(COL_TENANT, fn () => DB::table('positions')->where('id', $position)->value('status')))->toBe('filled');

    $full = colCall($this->admin, 'post', '/employments', colHire(['position_id' => $position]));
    $this->assertMatchesOpenApi($full, 'post', '/employments', 422);

    TenancyScope::runAs(COL_TENANT, fn () => app(ParameterResolver::class)->set('posiciones.contratar_solo_con_posicion', true, 'organization', null, null, 'Prueba'));
    $required = colCall($this->admin, 'post', '/employments', colHire());
    $this->assertMatchesOpenApi($required, 'post', '/employments', 422);
    expect($required->json('error.details.0.field'))->toBe('position_id');
});

test('COL-2 · anular un ingreso por empezar libera la posición; una transición inválida responde 409', function (): void {
    $position = TenancyScope::runAs(COL_TENANT, fn () => Position::factory()->create([
        'legal_entity_id' => $this->entity, 'job_profile_id' => $this->profile, 'org_unit_id' => $this->unit, 'site_id' => $this->site, 'status' => 'open',
    ])->id);
    $id = (string) colCall($this->admin, 'post', '/employments', colHire(['hire_date' => now()->addDays(5)->toDateString(), 'position_id' => $position]))->json('data.id');

    $noReason = colCall($this->admin, 'post', "/employments/{$id}/cancel", []);
    $this->assertMatchesOpenApi($noReason, 'post', '/employments/{id}/cancel', 422);
    $cancelled = colCall($this->admin, 'post', "/employments/{$id}/cancel", ['reason' => 'No se presentó']);
    $this->assertMatchesOpenApi($cancelled, 'post', '/employments/{id}/cancel', 200);
    expect($cancelled->json('data.status'))->toBe('cancelled');
    TenancyScope::runAs(COL_TENANT, function () use ($id, $position): void {
        expect(DB::table('employment_assignments')->where('position_id', $position)->count())->toBe(0);
        expect(DB::table('contracts')->where('employment_id', $id)->value('status'))->toBe('cancelled');
        expect(DB::table('outbox_events')->where('event_type', 'employee.hire_cancelled.v1')->where('aggregate_id', $id)->count())->toBe(1);
    });

    $again = colCall($this->admin, 'post', "/employments/{$id}/activate");
    $this->assertMatchesOpenApi($again, 'post', '/employments/{id}/activate', 409);
    expect($again->json('error.code'))->toBe('invalid_transition');

    // El estado no se edita por PATCH (regla 3).
    $patch = colCall($this->admin, 'patch', "/employments/{$id}", ['status' => 'active']);
    $this->assertMatchesOpenApi($patch, 'patch', '/employments/{id}', 422);
});

test('la matriz de ME-01 rechaza toda acción no declarada', function (): void {
    $machine = new EmploymentStateMachine;
    $actions = ['activate', 'cancel', 'suspend', 'resume', 'terminate', 'cancel-termination', 'make-effective'];
    foreach (['pending', 'active', 'suspended', 'terminating', 'terminated', 'cancelled'] as $state) {
        foreach ($actions as $action) {
            expect($machine->can($state, $action))->toBe(isset($machine->transitions()[$state][$action]));
        }
    }
    expect($machine->manualActions('active'))->toBe(['terminate'])->and($machine->manualActions('pending'))->toBe(['activate', 'cancel'])
        ->and($machine->manualActions('terminating'))->toBe(['cancel-termination'])->and($machine->manualActions('terminated'))->toBe([]);
});

test('COL-2 · RN-COL-02 sin dos relaciones abiertas con la misma empresa; el reingreso reutiliza a la persona', function (): void {
    $first = colCall($this->admin, 'post', '/employments', colHire());
    $person = (string) $first->json('data.person_id');

    $overlap = colCall($this->admin, 'post', '/employments', colHire(['person' => null, 'person_id' => $person]));
    $this->assertMatchesOpenApi($overlap, 'post', '/employments', 409);
    expect($overlap->json('error.code'))->toBe('employment_overlap');

    // Ingreso con un documento existente: no crea otra persona (RN-COL-01).
    $doc = TenancyScope::runAs(COL_TENANT, fn () => DB::table('person_documents')->where('person_id', $person)->value('number_hash'));
    expect($doc)->not->toBeNull();

    TenancyScope::runAs(COL_TENANT, fn () => DB::table('employments')->where('id', $first->json('data.id'))->update(['status' => 'terminated', 'termination_date' => '2026-03-31']));
    $rehire = colCall($this->admin, 'post', '/employments', colHire(['person' => null, 'person_id' => $person, 'hire_date' => '2026-05-04']));
    $this->assertMatchesOpenApi($rehire, 'post', '/employments', 201);
    expect($rehire->json('data.rehire_of_employment_id'))->toBe($first->json('data.id'))
        ->and(TenancyScope::runAs(COL_TENANT, fn () => DB::table('people')->count()))->toBe(1);
});

test('COL-2 · RN-COL-13 el ingreso que supera el límite del plan se rechaza', function (): void {
    colCall($this->admin, 'post', '/employments', colHire())->assertCreated();
    TenancyScope::runAs(COL_TENANT, fn () => DB::table('tenant_limits')->upsert([[
        'tenant_id' => COL_TENANT, 'limit_code' => 'employees', 'limit_value' => '1', 'is_unlimited' => false, 'source' => 'plan',
    ]], ['tenant_id', 'limit_code'], ['limit_value', 'is_unlimited', 'source']));

    $blocked = colCall($this->admin, 'post', '/employments', colHire());
    $this->assertMatchesOpenApi($blocked, 'post', '/employments', 403);
    expect($blocked->json('error.code'))->toBe('plan_limit_reached');
});

test('COL-2 · contrato a plazo fijo exige fecha de fin y causa; sueldo solo con permiso de campo', function (): void {
    $noEnd = colCall($this->admin, 'post', '/employments', colHire(['contract_type_id' => $this->fixedTerm]));
    $this->assertMatchesOpenApi($noEnd, 'post', '/employments', 422);

    $ok = colCall($this->admin, 'post', '/employments', colHire(['contract_type_id' => $this->fixedTerm, 'contract_end_date' => '2026-12-31', 'contract_objective_cause' => 'Campaña de fin de año']));
    $this->assertMatchesOpenApi($ok, 'post', '/employments', 201);
    expect($ok->json('data.contract_end_date'))->toBe('2026-12-31');

    $rrhh = IdentityFixtures::userWithPermissions(COL_TENANT, ['people.employments.create', 'people.employments.view', 'people.people.create']);
    $salary = colCall($rrhh, 'post', '/employments', colHire(['base_salary' => ['amount' => '2000.00', 'currency' => 'PEN']]));
    $this->assertMatchesOpenApi($salary, 'post', '/employments', 422);
    expect($salary->json('error.details.0.field'))->toBe('base_salary');
});

test('COL-9 · el listado aplica alcance, filtros, búsqueda por documento y oculta el sueldo sin permiso', function (): void {
    $a = colCall($this->admin, 'post', '/employments', colHire(['person' => ['first_names' => 'Ana', 'paternal_surname' => 'Alva', 'document_type' => 'dni', 'document_number' => '41000001'], 'base_salary' => ['amount' => '4000.00', 'currency' => 'PEN']]))->json('data.id');
    colCall($this->admin, 'post', '/employments', colHire(['person' => ['first_names' => 'Beto', 'paternal_surname' => 'Bravo', 'document_type' => 'dni', 'document_number' => '41000002'], 'hire_date' => now()->addDays(20)->toDateString()]))->assertCreated();

    $all = colCall($this->admin, 'get', '/employments?sort=person_name');
    $this->assertMatchesOpenApi($all, 'get', '/employments', 200);
    expect($all->json('meta.total'))->toBe(2)->and($all->json('data.0.person_name'))->toBe('Ana Alva');

    $pending = colCall($this->admin, 'get', '/employments?filter[status]=pending');
    expect($pending->json('meta.total'))->toBe(1)->and($pending->json('data.0.person_name'))->toStartWith('Beto');

    $byDoc = colCall($this->admin, 'get', '/employments?q=41000001');
    expect($byDoc->json('meta.total'))->toBe(1)->and($byDoc->json('data.0.id'))->toBe($a);

    $byUnit = colCall($this->admin, 'get', '/employments?filter[org_unit_id]='.$this->unit);
    expect($byUnit->json('meta.total'))->toBe(2);

    $bad = colCall($this->admin, 'get', '/employments?filter[status]=perhaps');
    $this->assertMatchesOpenApi($bad, 'get', '/employments', 400);

    // Sin permiso de sueldo: no viaja ni en la ficha.
    $noSalary = IdentityFixtures::userWithPermissions(COL_TENANT, ['people.employments.view']);
    $shown = colCall($noSalary, 'get', '/employments/'.$a);
    $this->assertMatchesOpenApi($shown, 'get', '/employments/{id}', 200);
    expect($shown->json('data'))->not->toHaveKey('base_salary');
    expect(colCall($this->admin, 'get', '/employments/'.$a)->json('data.base_salary.amount'))->toBe('4000.00');

    // Un empleado solo ve lo propio: no ve a los demás.
    $employee = colUser(COL_TENANT, 'empleado');
    $own = colCall($employee, 'get', '/employments');
    $this->assertMatchesOpenApi($own, 'get', '/employments', 200);
    expect($own->json('meta.total'))->toBe(0);
    $hidden = colCall($employee, 'get', '/employments/'.$a);
    $this->assertMatchesOpenApi($hidden, 'get', '/employments/{id}', 404);
});

test('COL-9 · la exportación respeta el listado y el alcance', function (): void {
    Storage::fake('s3');
    config(['files.disk' => 's3']);
    colCall($this->admin, 'post', '/employments', colHire(['person' => ['first_names' => 'Eva', 'paternal_surname' => 'Ensayo', 'document_type' => 'dni', 'document_number' => '42000001']]))->assertCreated();
    $export = colCall($this->admin, 'post', '/exports', ['resource' => 'employments', 'format' => 'csv', 'filters' => ['status' => 'active']]);
    $this->assertMatchesOpenApi($export, 'post', '/exports', 201);
    expect($export->json('data.row_count'))->toBe(1);
    $csv = (string) colCall($this->admin, 'get', '/exports/'.$export->json('data.id').'/download')->getContent();
    expect($csv)->toContain('Eva Ensayo')->not->toContain('42000001');
});

test('COL-9 · el jefe se lee de la asignación o, si no hay, de quien ocupa la posición jefe', function (): void {
    [$boss, $seat] = TenancyScope::runAs(COL_TENANT, function (): array {
        $boss = Position::factory()->create(['legal_entity_id' => $this->entity, 'job_profile_id' => $this->profile, 'org_unit_id' => $this->unit, 'site_id' => $this->site, 'status' => 'open']);
        $seat = Position::factory()->create(['legal_entity_id' => $this->entity, 'job_profile_id' => $this->profile, 'org_unit_id' => $this->unit, 'site_id' => $this->site, 'status' => 'open', 'reports_to_position_id' => $boss->id]);

        return [$boss->id, $seat->id];
    });
    colCall($this->admin, 'post', '/employments', colHire(['position_id' => $boss, 'person' => ['first_names' => 'Jefa', 'paternal_surname' => 'Área', 'document_type' => 'dni', 'document_number' => '43000001']]))->assertCreated();
    $report = colCall($this->admin, 'post', '/employments', colHire(['position_id' => $seat]));
    $this->assertMatchesOpenApi($report, 'post', '/employments', 201);
    expect($report->json('data.manager_name'))->toBe('Jefa Área')->and($report->json('data.manager_employment_id'))->toBeNull();
});

test('permiso, módulo contratado y datos inválidos', function (): void {
    $nobody = IdentityFixtures::userWithPermissions(COL_TENANT, ['people.people.view']);
    $forbidden = colCall($nobody, 'post', '/employments', colHire());
    $this->assertMatchesOpenApi($forbidden, 'post', '/employments', 403);

    $invalid = colCall($this->admin, 'post', '/employments', ['legal_entity_id' => 'x']);
    $this->assertMatchesOpenApi($invalid, 'post', '/employments', 422);

    IdentityFixtures::disableModule(COL_TENANT, 'core');
    $off = colCall($this->admin, 'get', '/employments');
    $this->assertMatchesOpenApi($off, 'get', '/employments', 403);
    expect($off->json('error.code'))->toBe('module_not_enabled');
});
