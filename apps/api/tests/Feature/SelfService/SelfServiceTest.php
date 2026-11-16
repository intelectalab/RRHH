<?php

/**
 * ESS-1 · Inicio del colaborador (GET /me/home): solo datos propios (RN-ESS-01), relación elegida cuando hay
 * varias (RN-ESS-04) y solo tarjetas de módulos contratados (RN-ESS-02).
 * ESS-2 · Mi perfil (GET /me/employment): datos laborales y personales propios, lectura registrada, campos que
 * la organización deja proponer desde el portal y cambios pendientes marcados.
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\IdentityFixtures;

uses(DatabaseTransactions::class);

const ESS_TENANT = 'aaaaaaaa-0e55-4000-8000-00000000000a';
const ESS_OTHER = 'bbbbbbbb-0e55-4000-8000-00000000000b';

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requiere PostgreSQL.');
    }
    TenancyScope::clear();
    foreach ([ESS_TENANT => 'ess-org', ESS_OTHER => 'ess-otra'] as $id => $slug) {
        DB::table('tenants')->insertOrIgnore(['id' => $id, 'slug' => $slug, 'name' => 'Grupo '.$slug, 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN']);
        DB::select('SELECT seed_tenant_defaults(?)', [$id]);
        TenancyScope::runAs($id, fn () => IdentityFixtures::enableAllModules());
    }
    $this->admin = essUser(ESS_TENANT, 'admin_empresa');
    TenancyScope::runAs(ESS_TENANT, function (): void {
        $entity = LegalEntity::factory()->create();
        DB::table('legal_entity_regimes')->where('legal_entity_id', $entity->id)->update(['valid_from' => '2020-01-01']);
        $this->entity = $entity->id;
        $this->profile = JobProfile::factory()->create()->id;
        $this->unit = OrgUnit::factory()->create(['legal_entity_id' => $this->entity])->id;
        $this->site = Site::factory()->create(['legal_entity_id' => $this->entity])->id;
    });
    $options = essCall($this->admin, 'get', '/employment-options')->assertOk();
    $this->contract = collect($options->json('data.contract_types'))->firstWhere('code', 'indeterminado')['id'];

    [$this->employment, $this->person] = essHire($this, 'Rosa', 'Quispe');
    [$this->otherEmployment, $this->otherPerson] = essHire($this, 'Luis', 'Huamán');
    $this->employee = essEmployee($this->person);
});

afterEach(fn () => TenancyScope::clear());

/** @return array{0: string, 1: string} */
function essHire(object $ctx, string $first, string $last): array
{
    $hire = essCall($ctx->admin, 'post', '/employments', [
        'person' => ['first_names' => $first, 'paternal_surname' => $last, 'document_type' => 'dni', 'document_number' => (string) random_int(10000000, 89999999),
            'birth_date' => '1990-05-04', 'personal_email' => strtolower($first).'@correo.pe'],
        'legal_entity_id' => $ctx->entity, 'hire_date' => '2025-03-10', 'job_profile_id' => $ctx->profile,
        'org_unit_id' => $ctx->unit, 'site_id' => $ctx->site, 'contract_type_id' => $ctx->contract,
    ])->assertCreated();

    return [(string) $hire->json('data.id'), (string) $hire->json('data.person_id')];
}

function essUser(string $tenant, string $role): User
{
    return TenancyScope::runAs($tenant, function () use ($role): User {
        $u = User::factory()->create();
        $u->roleAssignments()->create(['role_id' => Role::query()->where('system_role_code', $role)->value('id')]);

        return $u;
    });
}

function essEmployee(string $personId, string $tenant = ESS_TENANT): User
{
    $user = essUser($tenant, 'empleado');
    TenancyScope::runAs($tenant, fn () => DB::table('users')->where('id', $user->id)->update(['person_id' => $personId]));

    return $user->refresh();
}

/**
 * @param  array<string, mixed>  $body
 */
function essCall(User $user, string $method, string $path, array $body = []): TestResponse
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

// ── ESS-1 · Inicio ───────────────────────────────────────────────────────────────────────────────────

test('ESS-1: el inicio trae solo la relación propia, sus pendientes y las tarjetas de los módulos contratados', function (): void {
    $r = essCall($this->employee, 'get', '/me/home')->assertOk();
    $this->assertMatchesOpenApi($r, 'get', '/me/home', 200);

    expect($r->json('data.employment.id'))->toBe($this->employment)
        ->and(collect($r->json('data.employments'))->pluck('id')->all())->toBe([$this->employment])
        ->and($r->json('data.employment.read_only'))->toBeFalse()
        ->and($r->json('data.pending'))->toHaveKeys(['total', 'counts', 'items']);

    $cards = collect($r->json('data.cards'))->keyBy('key');
    expect($cards->keys()->all())->toBe(['profile', 'vacations', 'absences', 'documents', 'certificates', 'hr_requests'])
        ->and($cards['profile']['data']['first_names'])->toBe('Rosa')
        ->and($cards['profile']['href'])->toBe('/mi/perfil')
        ->and($cards['vacations']['data'])->toHaveKeys(['available_days', 'pending_requests', 'next'])
        ->and($cards['absences']['data']['upcoming'])->toBe([]);

    // Nada de la otra persona viaja en la respuesta.
    expect(json_encode($r->json()))->not->toContain($this->otherEmployment)->not->toContain('Luis');
});

test('RN-ESS-01: una relación laboral ajena responde 404, también la de otra organización', function (): void {
    $r = essCall($this->employee, 'get', '/me/home?employment_id='.$this->otherEmployment)->assertNotFound();
    $this->assertMatchesOpenApi($r, 'get', '/me/home', 404);
    essCall($this->employee, 'get', '/me/employment?employment_id='.$this->otherEmployment)->assertNotFound();
    essCall($this->employee, 'get', '/me/home?employment_id='.$this->employment)->assertOk();

    // Un usuario de la organización B no ve la relación de la A aunque conozca el identificador.
    $foreign = essUser(ESS_OTHER, 'empleado');
    essCall($foreign, 'get', '/me/home?employment_id='.$this->employment)->assertNotFound();
    $none = essCall($foreign, 'get', '/me/home')->assertOk();
    expect($none->json('data.employment'))->toBeNull()->and($none->json('data.cards'))->toBe([]);
});

test('RN-ESS-02: sin el módulo de ausencias no hay tarjetas de vacaciones ni ausencias; sin Core HR, 403', function (): void {
    IdentityFixtures::disableModule(ESS_TENANT, 'time_off');
    $r = essCall($this->employee, 'get', '/me/home')->assertOk();
    expect(collect($r->json('data.cards'))->pluck('key')->all())->toBe(['profile', 'documents', 'certificates', 'hr_requests']);

    IdentityFixtures::disableModule(ESS_TENANT, 'core');
    $off = essCall($this->employee, 'get', '/me/home')->assertStatus(403)->assertJsonPath('error.code', 'module_not_enabled');
    $this->assertMatchesOpenApi($off, 'get', '/me/home', 403);
    essCall($this->employee, 'get', '/me/employment')->assertStatus(403)->assertJsonPath('error.code', 'module_not_enabled');
});

test('ESS-1: sin sesión responde 401 y un usuario sin relación laboral recibe el inicio vacío', function (): void {
    app('auth')->forgetGuards();
    $anon = $this->getJson('/api/v1/me/home', ['X-Tenant-Id' => ESS_TENANT])->assertUnauthorized();
    $this->assertMatchesOpenApi($anon, 'get', '/me/home', 401);

    $r = essCall($this->admin, 'get', '/me/home')->assertOk();
    $this->assertMatchesOpenApi($r, 'get', '/me/home', 200);
    expect($r->json('data.employments'))->toBe([])->and($r->json('data.employment'))->toBeNull()->and($r->json('data.cards'))->toBe([]);

    $p = essCall($this->admin, 'get', '/me/employment')->assertOk();
    $this->assertMatchesOpenApi($p, 'get', '/me/employment', 200);
    expect($p->json('data.employment'))->toBeNull();
});

// ── ESS-2 · Mi perfil ────────────────────────────────────────────────────────────────────────────────

test('ESS-2: el colaborador ve sus datos laborales y personales, y la lectura queda registrada', function (): void {
    $r = essCall($this->employee, 'get', '/me/employment')->assertOk();
    $this->assertMatchesOpenApi($r, 'get', '/me/employment', 200);

    expect($r->json('data.employment.id'))->toBe($this->employment)
        ->and($r->json('data.header.person_name'))->toContain('Rosa')
        ->and($r->json('data.work_data.fields.org_unit.value'))->toBe($this->unit)
        ->and($r->json('data.person.birth_date'))->toBe('1990-05-04')
        ->and($r->json('data.person.personal_email'))->toBe('rosa@correo.pe')
        ->and($r->json('data.can_propose'))->toBeTrue()
        ->and($r->json('data.editable_fields'))->toContain('mobile_phone')
        ->and($r->json('data.editable_fields'))->not->toContain('first_names')
        ->and($r->json('data.pending_fields'))->toBe([]);

    TenancyScope::runAs(ESS_TENANT, fn () => expect(DB::table('data_access_logs')->where('entity_type', 'Person')
        ->where('subject_person_id', $this->person)->where('user_id', $this->employee->id)->exists())->toBeTrue());
});

test('ESS-2: un cambio que espera a RRHH se muestra como pendiente', function (): void {
    essCall($this->employee, 'post', '/profile-change-requests', ['changes' => ['marital_status' => 'married', 'preferred_name' => 'Rosi']])->assertCreated();

    $r = essCall($this->employee, 'get', '/me/employment')->assertOk();
    $this->assertMatchesOpenApi($r, 'get', '/me/employment', 200);
    expect($r->json('data.pending_fields'))->toBe(['marital_status'])
        ->and($r->json('data.person.preferred_name'))->toBe('Rosi')
        ->and($r->json('data.change_requests.0.status'))->toBe('pending');

    $home = essCall($this->employee, 'get', '/me/home')->assertOk();
    expect(collect($home->json('data.cards'))->firstWhere('key', 'profile')['data']['pending_changes'])->toBe(1);
});

test('ESS-2: el colaborador no puede proponer un dato que la organización no habilita en el portal', function (): void {
    TenancyScope::runAs(ESS_TENANT, fn () => app(ParameterResolver::class)->set('colaboradores.campos_editables_portal', ['mobile_phone'], 'organization', null, null, 'Prueba'));

    $no = essCall($this->employee, 'post', '/profile-change-requests', ['changes' => ['preferred_name' => 'Rosi']])->assertStatus(422);
    $this->assertMatchesOpenApi($no, 'post', '/profile-change-requests', 422);
    expect(collect($no->json('error.details'))->pluck('field')->all())->toContain('changes.preferred_name');

    essCall($this->employee, 'post', '/profile-change-requests', ['changes' => ['mobile_phone' => '987654321']])->assertCreated();
    expect(essCall($this->employee, 'get', '/me/employment')->json('data.editable_fields'))->toBe(['mobile_phone']);
});

test('RN-ESS-05: con la relación cesada el perfil es de solo lectura', function (): void {
    TenancyScope::runAs(ESS_TENANT, fn () => DB::table('employments')->where('id', $this->employment)->update(['status' => 'terminated', 'termination_date' => '2025-12-31']));

    $r = essCall($this->employee, 'get', '/me/employment')->assertOk();
    expect($r->json('data.employment.read_only'))->toBeTrue()->and($r->json('data.can_propose'))->toBeFalse();
    expect(essCall($this->employee, 'get', '/me/home')->json('data.employment.read_only'))->toBeTrue();
});
