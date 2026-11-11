<?php

/**
 * AUS-1 · Tipos de ausencia con reglas propias: catálogo del paquete Perú, reglas con vigencia
 * (goce, unidad, tope, sustento, quién solicita, flujo, efecto en planilla, alcance por empresa o
 * régimen), duración de ley leída de parámetros legales y archivo por acción.
 */

use App\Modules\Absences\Application\AbsenceTypeCatalog;
use App\Modules\Absences\Domain\AbsenceTypeRules;
use App\Modules\Absences\Domain\AbsenceTypeStateMachine;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Shared\LegalParameters\LegalParameters;
use App\Shared\Permissions\ModuleGate;
use App\Shared\Permissions\PermissionResolver;
use App\Shared\Tenancy\TenancyScope;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\IdentityFixtures;

uses(DatabaseTransactions::class);

const AT_TENANT = 'aaaaaaaa-a051-4000-8000-00000000000a';
const AT_OTHER = 'bbbbbbbb-a051-4000-8000-00000000000b';

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requiere PostgreSQL.');
    }
    TenancyScope::clear();
    foreach ([AT_TENANT => 'at-org', AT_OTHER => 'at-otra'] as $id => $slug) {
        DB::table('tenants')->insertOrIgnore(['id' => $id, 'slug' => $slug, 'name' => 'Grupo '.$slug, 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN']);
        DB::select('SELECT seed_tenant_defaults(?)', [$id]);
        TenancyScope::runAs($id, fn () => IdentityFixtures::enableAllModules());
    }
    app(LegalParameters::class)->sync('PE');
    $this->admin = atUser(AT_TENANT, 'admin_empresa');
});

afterEach(fn () => TenancyScope::clear());

function atUser(string $tenant, string $role): User
{
    return TenancyScope::runAs($tenant, function () use ($role): User {
        $u = User::factory()->create();
        $u->roleAssignments()->create(['role_id' => Role::query()->where('system_role_code', $role)->value('id')]);

        return $u;
    });
}

function atCall(User $user, string $method, string $path, array $body = []): TestResponse
{
    TenancyScope::clear();
    app('auth')->forgetGuards();
    app(PermissionResolver::class)->forget();
    app(ModuleGate::class)->forget();

    return test()->actingAs($user, 'ayni')->withHeaders(['X-Tenant-Id' => $user->tenant_id, 'Idempotency-Key' => (string) Str::uuid()])->json($method, '/api/v1'.$path, $body);
}

/**
 * @return array<string, array<string, mixed>>
 */
function atByCode(TestResponse $r): array
{
    return collect($r->json('data'))->keyBy('code')->all();
}

function atCompany(string $tenant, string $name): string
{
    return TenancyScope::runAs($tenant, fn (): string => (string) DB::table('legal_entities')->insertGetId([
        'tenant_id' => $tenant, 'legal_name' => $name, 'country_code' => 'PE', 'tax_id' => '20'.random_int(100000000, 999999999),
        'currency' => 'PEN', 'timezone' => 'America/Lima',
    ]));
}

test('el paquete Perú precarga los tipos con sus reglas y la duración de ley queda «por validar» sin número inventado', function (): void {
    $r = atCall($this->admin, 'GET', '/absence-types');
    $r->assertOk();
    $this->assertMatchesOpenApi($r, 'get', '/absence-types', 200);
    $types = atByCode($r);

    $codes = array_column(AbsenceTypeCatalog::countryTypes('PE'), 'code');
    expect(array_keys($types))->toEqualCanonicalizing($codes);

    $mat = $types['licencia_maternidad'];
    expect($mat['is_system'])->toBeTrue()
        ->and($mat['is_confidential'])->toBeTrue()
        ->and($mat['rules']['requestable_by'])->toBe('hr')
        ->and($mat['rules']['subsidy_kind'])->toBe('maternity')
        ->and($mat['rules']['payroll_concept_code'])->toBe('subsidio_maternidad')
        ->and($mat['rules']['valid_from'])->toBe(now()->toDateString())
        ->and($mat['legal_duration']['parameter_code'])->toBe('licencia_maternidad_dias')
        ->and($mat['legal_duration']['value'])->toBeNull()
        ->and($mat['legal_duration']['pending_validation'])->toBeTrue();

    // RN-AUS-02: sin goce descuenta; RN-AUS-09: trabajo remoto no afecta asistencia.
    expect($types['licencia_sin_goce']['rules']['deducts_pay'])->toBeTrue()
        ->and($types['licencia_sin_goce']['rules']['counts_as_worked_for_benefits'])->toBeFalse()
        ->and($types['trabajo_remoto']['rules']['affects_attendance'])->toBeFalse()
        ->and($types['trabajo_remoto']['rules']['deducts_pay'])->toBeFalse()
        ->and($types['descanso_medico']['rules']['requires_document'])->toBeTrue()
        ->and($types['permiso_personal']['rules']['unit'])->toBe('hours')
        ->and($types['permiso_personal']['legal_duration'])->toBeNull();

    // Idempotente: listar otra vez no duplica tipos ni reglas.
    expect(atCall($this->admin, 'GET', '/absence-types')->json('data'))->toHaveCount(count($codes));
    expect(TenancyScope::runAs(AT_TENANT, fn () => DB::table('absence_type_rules')->count()))->toBe(count($codes));

    $one = atCall($this->admin, 'GET', '/absence-types/'.$mat['id']);
    $one->assertOk()->assertJsonPath('data.code', 'licencia_maternidad');
    $this->assertMatchesOpenApi($one, 'get', '/absence-types/{id}', 200);
});

test('la organización crea un tipo propio con goce, sustento, quién solicita, concepto de planilla y alcance por empresa y régimen', function (): void {
    $company = atCompany(AT_TENANT, 'Empresa Norte SAC');
    $created = atCall($this->admin, 'POST', '/absence-types', [
        'code' => 'permiso_estudios', 'name' => 'Permiso por estudios', 'category' => 'personal',
        'rules' => [
            'is_paid' => false, 'unit' => 'hours', 'max_quantity' => 40, 'max_period' => 'year', 'min_notice_days' => 3,
            'requires_document' => true, 'document_grace_days' => 5, 'requestable_by' => 'manager',
            'payroll_concept_code' => 'faltas', 'legal_entity_ids' => [$company], 'labor_regime_codes' => ['pe_general'],
        ],
    ]);
    $created->assertCreated();
    $this->assertMatchesOpenApi($created, 'post', '/absence-types', 201);
    expect($created->json('data.is_system'))->toBeFalse()
        ->and($created->json('data.rules.is_paid'))->toBeFalse()
        ->and($created->json('data.rules.deducts_pay'))->toBeTrue()
        ->and($created->json('data.rules.max_quantity'))->toBe('40')
        ->and($created->json('data.rules.requires_document'))->toBeTrue()
        ->and($created->json('data.rules.requestable_by'))->toBe('manager')
        ->and($created->json('data.rules.payroll_concept_code'))->toBe('faltas')
        ->and($created->json('data.rules.legal_entity_ids'))->toBe([$company])
        ->and($created->json('data.rules.labor_regime_codes'))->toBe(['pe_general']);
    $id = $created->json('data.id');

    // Auditoría con el después.
    $audit = TenancyScope::runAs(AT_TENANT, fn () => DB::table('audit_events')->where('action', 'absence_type.created')->where('entity_id', $id)->first());
    expect($audit)->not->toBeNull();

    // Código duplicado, concepto de planilla inexistente, empresa de otra organización o régimen desconocido.
    atCall($this->admin, 'POST', '/absence-types', ['code' => 'permiso_estudios', 'name' => 'Otro', 'category' => 'personal'])->assertStatus(409);
    $bad = atCall($this->admin, 'POST', '/absence-types', ['code' => 'x_concepto', 'name' => 'X', 'category' => 'other', 'rules' => ['payroll_concept_code' => 'no_existe']]);
    $this->assertMatchesOpenApi($bad, 'post', '/absence-types', 422);
    $foreign = atCompany(AT_OTHER, 'Empresa ajena SAC');
    atCall($this->admin, 'POST', '/absence-types', ['code' => 'x_empresa', 'name' => 'X', 'category' => 'other', 'rules' => ['legal_entity_ids' => [$foreign]]])->assertStatus(422);
    atCall($this->admin, 'POST', '/absence-types', ['code' => 'x_regimen', 'name' => 'X', 'category' => 'other', 'rules' => ['labor_regime_codes' => ['no_existe']]])->assertStatus(422);
    atCall($this->admin, 'POST', '/absence-types', ['code' => 'X mal', 'name' => 'X', 'category' => 'nada'])->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed');

    // Duración de ley solo desde un parámetro legal existente.
    atCall($this->admin, 'POST', '/absence-types', ['code' => 'x_param', 'name' => 'X', 'category' => 'other', 'legal_duration_parameter_code' => 'no_existe'])->assertStatus(422);
    $withParam = atCall($this->admin, 'POST', '/absence-types', ['code' => 'licencia_propia', 'name' => 'Licencia propia', 'category' => 'paternity', 'legal_duration_parameter_code' => 'licencia_paternidad_dias']);
    $withParam->assertCreated()->assertJsonPath('data.legal_duration.pending_validation', true);
});

test('cambiar una regla cierra la vigente e inserta otra: as_of devuelve el valor correcto en fecha pasada, presente y futura', function (): void {
    $past = now()->subMonths(2)->toDateString();
    $created = atCall($this->admin, 'POST', '/absence-types', ['code' => 'capacitacion', 'name' => 'Capacitación', 'category' => 'other', 'effective_from' => $past, 'rules' => ['max_quantity' => 5]]);
    $created->assertCreated()->assertJsonPath('data.rules.valid_from', $past);
    $id = $created->json('data.id');
    $firstRuleId = $created->json('data.rules.id');

    $future = now()->addMonth()->startOfMonth()->toDateString();
    $upd = atCall($this->admin, 'PATCH', '/absence-types/'.$id, ['effective_from' => $future, 'rules' => ['max_quantity' => 8, 'requires_document' => true]]);
    $upd->assertOk();
    $this->assertMatchesOpenApi($upd, 'patch', '/absence-types/{id}', 200);
    expect($upd->json('data.rules.max_quantity'))->toBe('8')->and($upd->json('data.rules.valid_from'))->toBe($future);

    $before = atCall($this->admin, 'GET', '/absence-types/'.$id.'?as_of='.Carbon::parse($past)->subDay()->toDateString());
    expect($before->json('data.rules'))->toBeNull();
    $now = atCall($this->admin, 'GET', '/absence-types/'.$id);
    expect($now->json('data.rules.max_quantity'))->toBe('5')->and($now->json('data.rules.requires_document'))->toBeFalse();
    $later = atCall($this->admin, 'GET', '/absence-types/'.$id.'?as_of='.$future);
    expect($later->json('data.rules.max_quantity'))->toBe('8')->and($later->json('data.rules.requires_document'))->toBeTrue();

    // La fila anterior no se reescribió: solo se cerró el día previo.
    $old = TenancyScope::runAs(AT_TENANT, fn () => DB::table('absence_type_rules')->where('id', $firstRuleId)->first());
    expect((string) $old->max_quantity)->toStartWith('5')->and((string) $old->valid_to)->toBe(Carbon::parse($future)->subDay()->toDateString());

    $history = atCall($this->admin, 'GET', '/absence-types/'.$id.'/rules');
    $history->assertOk();
    $this->assertMatchesOpenApi($history, 'get', '/absence-types/{id}/rules', 200);
    expect(collect($history->json('data'))->pluck('valid_from')->all())->toBe([$future, $past]);

    // Con un cambio futuro ya programado, otro cambio anterior a él choca (409), y la base impide solapes.
    atCall($this->admin, 'PATCH', '/absence-types/'.$id, ['rules' => ['max_quantity' => 6]])->assertStatus(409);
    $overlap = fn () => TenancyScope::runAs(AT_TENANT, fn () => DB::transaction(fn () => DB::table('absence_type_rules')->insert([
        'tenant_id' => AT_TENANT, 'absence_type_id' => $id, 'valid_from' => $past, 'valid_to' => null,
    ])));
    expect($overlap)->toThrow(QueryException::class);

    atCall($this->admin, 'GET', '/absence-types/'.$id.'?as_of=2026-13-40')->assertStatus(400)->assertJsonPath('error.code', 'invalid_filter');
});

test('en los tipos del paquete se ajustan las reglas pero no la identidad', function (): void {
    $types = atByCode(atCall($this->admin, 'GET', '/absence-types'));
    $id = $types['descanso_medico']['id'];
    $flow = TenancyScope::runAs(AT_TENANT, fn (): string => (string) DB::table('workflow_definitions')->insertGetId([
        'tenant_id' => AT_TENANT, 'code' => 'aprobacion_ausencias', 'name' => 'Aprobación de ausencias', 'kind' => 'approval', 'status' => 'active',
    ]));

    $ok = atCall($this->admin, 'PATCH', '/absence-types/'.$id, ['rules' => ['approval_workflow_definition_id' => $flow, 'document_grace_days' => 2]]);
    $ok->assertOk()->assertJsonPath('data.rules.approval_workflow_definition_id', $flow)->assertJsonPath('data.rules.document_grace_days', 2)
        ->assertJsonPath('data.rules.requires_document', true);

    $locked = atCall($this->admin, 'PATCH', '/absence-types/'.$id, ['name' => 'Otro nombre']);
    $locked->assertStatus(422)->assertJsonPath('error.meta.rule', 'absence_type_is_system');
    $this->assertMatchesOpenApi($locked, 'patch', '/absence-types/{id}', 422);
    atCall($this->admin, 'PATCH', '/absence-types/'.$id, ['rules' => ['approval_workflow_definition_id' => (string) Str::uuid()]])->assertStatus(422);

    $audit = TenancyScope::runAs(AT_TENANT, fn () => DB::table('audit_events')->where('action', 'absence_type.updated')->where('entity_id', $id)->first());
    expect($audit)->not->toBeNull();
});

test('archivar es una acción con máquina de estados; un tipo archivado no se edita', function (): void {
    $id = atCall($this->admin, 'POST', '/absence-types', ['code' => 'temporal', 'name' => 'Temporal', 'category' => 'other'])->json('data.id');

    $arch = atCall($this->admin, 'POST', '/absence-types/'.$id.'/archive', ['reason' => 'Ya no se usa']);
    $arch->assertOk()->assertJsonPath('data.status', 'archived');
    $this->assertMatchesOpenApi($arch, 'post', '/absence-types/{id}/archive', 200);

    $again = atCall($this->admin, 'POST', '/absence-types/'.$id.'/archive');
    $again->assertStatus(409)->assertJsonPath('error.code', 'invalid_transition');
    $this->assertMatchesOpenApi($again, 'post', '/absence-types/{id}/archive', 409);
    atCall($this->admin, 'PATCH', '/absence-types/'.$id, ['name' => 'Nuevo'])->assertStatus(422)->assertJsonPath('error.meta.rule', 'absence_type_archived');

    // El estado no se cambia por PATCH (regla 3).
    $other = atCall($this->admin, 'POST', '/absence-types', ['code' => 'otro', 'name' => 'Otro', 'category' => 'other'])->json('data.id');
    atCall($this->admin, 'PATCH', '/absence-types/'.$other, ['status' => 'archived'])->assertOk()->assertJsonPath('data.status', 'active');

    // Matriz: cada estado × cada acción.
    $m = new AbsenceTypeStateMachine;
    expect($m->can('active', 'archive'))->toBeTrue()->and($m->can('archived', 'archive'))->toBeFalse();

    expect(collect(atCall($this->admin, 'GET', '/absence-types?filter[status]=archived')->json('data'))->pluck('code')->all())->toBe(['temporal']);
});

test('filtros: quién puede solicitarlo y a qué empresa o régimen aplica', function (): void {
    $north = atCompany(AT_TENANT, 'Norte SAC');
    $south = atCompany(AT_TENANT, 'Sur SAC');
    atCall($this->admin, 'POST', '/absence-types', ['code' => 'solo_norte', 'name' => 'Solo Norte', 'category' => 'other', 'rules' => ['legal_entity_ids' => [$north]]])->assertCreated();

    $employee = collect(atCall($this->admin, 'GET', '/absence-types?filter[requestable_by]=employee')->json('data'))->pluck('code');
    expect($employee)->toContain('permiso_personal')->not->toContain('licencia_maternidad');
    $hr = collect(atCall($this->admin, 'GET', '/absence-types?filter[requestable_by]=hr')->json('data'))->pluck('code');
    expect($hr)->toContain('licencia_maternidad')->toContain('permiso_personal');

    expect(collect(atCall($this->admin, 'GET', '/absence-types?filter[legal_entity_id]='.$north)->json('data'))->pluck('code'))->toContain('solo_norte');
    expect(collect(atCall($this->admin, 'GET', '/absence-types?filter[legal_entity_id]='.$south)->json('data'))->pluck('code'))->not->toContain('solo_norte');

    $bad = atCall($this->admin, 'GET', '/absence-types?filter[requestable_by]=nadie');
    $bad->assertStatus(400)->assertJsonPath('error.code', 'invalid_filter');
    $this->assertMatchesOpenApi($bad, 'get', '/absence-types', 400);
});

test('reglas del dominio: quién solicita, alcance y descuento', function (): void {
    $r = new AbsenceTypeRules(false, 'manager', ['e1'], ['pe_general']);
    expect($r->canBeRequestedBy('employee'))->toBeFalse()
        ->and($r->canBeRequestedBy('manager'))->toBeTrue()
        ->and($r->canBeRequestedBy('hr'))->toBeTrue()
        ->and($r->canBeRequestedBy('otro'))->toBeFalse()
        ->and($r->appliesTo('e1', 'pe_general'))->toBeTrue()
        ->and($r->appliesTo('e2', 'pe_general'))->toBeFalse()
        ->and($r->appliesTo('e1', 'pe_micro'))->toBeFalse()
        ->and($r->deductsPay())->toBeTrue();
    expect((new AbsenceTypeRules(true, 'employee', null, []))->appliesTo(null, null))->toBeTrue();
});

test('permisos: el colaborador ve pero no crea ni archiva; sin el módulo, 403 module_not_enabled', function (): void {
    $employee = atUser(AT_TENANT, 'empleado');
    atCall($employee, 'GET', '/absence-types')->assertOk();
    $denied = atCall($employee, 'POST', '/absence-types', ['code' => 'nuevo', 'name' => 'Nuevo', 'category' => 'other']);
    $denied->assertStatus(403);
    $this->assertMatchesOpenApi($denied, 'post', '/absence-types', 403);
    $id = collect(atCall($this->admin, 'GET', '/absence-types')->json('data'))->firstWhere('code', 'permiso_personal')['id'];
    atCall($employee, 'POST', '/absence-types/'.$id.'/archive')->assertStatus(403);
    atCall($employee, 'PATCH', '/absence-types/'.$id, ['rules' => ['is_paid' => false]])->assertStatus(403);

    IdentityFixtures::disableModule(AT_TENANT, 'time_off');
    $off = atCall($this->admin, 'GET', '/absence-types');
    $off->assertStatus(403)->assertJsonPath('error.code', 'module_not_enabled');
    $this->assertMatchesOpenApi($off, 'get', '/absence-types', 403);
});

test('aislamiento: otra organización no ve ni modifica los tipos propios de A', function (): void {
    $id = atCall($this->admin, 'POST', '/absence-types', ['code' => 'propio_a', 'name' => 'Propio A', 'category' => 'other'])->json('data.id');
    $intruder = atUser(AT_OTHER, 'admin_empresa');

    atCall($intruder, 'GET', '/absence-types/'.$id)->assertNotFound();
    atCall($intruder, 'GET', '/absence-types/'.$id.'/rules')->assertNotFound();
    atCall($intruder, 'PATCH', '/absence-types/'.$id, ['name' => 'X'])->assertNotFound();
    atCall($intruder, 'POST', '/absence-types/'.$id.'/archive')->assertNotFound();
    expect(collect(atCall($intruder, 'GET', '/absence-types')->json('data'))->pluck('code'))->not->toContain('propio_a');
});
