<?php

/**
 * ORG-5 · Catálogo de puestos, cargos, niveles y categorías: un puesto se usa en muchas posiciones,
 * su descripción se versiona, y niveles, familias y categorías son catálogos configurables.
 * RN-ORG-04 (no se elimina: se archiva y no admite nuevas posiciones) y RN-ORG-07 (sin categoría
 * queda pendiente).
 * ORG-1 · Empresas: historia del régimen con vigencia y empresa desactivada sin nuevas relaciones.
 */

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Organization\Infrastructure\Models\JobCategory;
use App\Modules\Organization\Infrastructure\Models\JobLevel;
use App\Modules\Organization\Infrastructure\Models\JobProfile;
use App\Modules\Organization\Infrastructure\Models\LegalEntity;
use App\Shared\Organization\StructureAvailability;
use App\Shared\Permissions\ModuleGate;
use App\Shared\Permissions\PermissionResolver;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Tests\Support\IdentityFixtures;

uses(DatabaseTransactions::class);

const JOB_TENANT = 'aaaaaaaa-0a65-4000-8000-00000000000a';
const JOB_OTHER = 'bbbbbbbb-0a65-4000-8000-00000000000b';

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requiere PostgreSQL.');
    }
    TenancyScope::clear();
    foreach ([JOB_TENANT => 'job-org', JOB_OTHER => 'job-otra'] as $id => $slug) {
        DB::table('tenants')->insertOrIgnore(['id' => $id, 'slug' => $slug, 'name' => 'Grupo '.$slug, 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN']);
        DB::select('SELECT seed_tenant_defaults(?)', [$id]);
        TenancyScope::runAs($id, fn () => IdentityFixtures::enableAllModules());
    }
    $this->admin = jobUser(JOB_TENANT, 'admin_empresa');
});

afterEach(fn () => TenancyScope::clear());

function jobUser(string $tenant, string $role): User
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
function jobCall(User $user, string $method, string $path, array $body = [], array $headers = []): TestResponse
{
    TenancyScope::clear();
    app('auth')->forgetGuards();
    app(PermissionResolver::class)->forget();
    app(ModuleGate::class)->forget();

    return test()->actingAs($user, 'ayni')
        ->withHeaders(['X-Tenant-Id' => $user->tenant_id, 'Idempotency-Key' => (string) Str::uuid()] + $headers)
        ->json($method, '/api/v1'.$path, $body);
}

/** Posición que usa el puesto (las posiciones son de POS; aquí se siembran directo en base). */
function jobPosition(string $profileId, string $status = 'open'): void
{
    TenancyScope::runAs(JOB_TENANT, function () use ($profileId, $status): void {
        $entity = LegalEntity::factory()->create()->id;
        $unit = (string) Str::uuid();
        DB::table('org_units')->insert(['id' => $unit, 'tenant_id' => JOB_TENANT, 'code' => 'U'.Str::random(6), 'name' => 'Unidad']);
        DB::table('positions')->insert([
            'tenant_id' => JOB_TENANT, 'code' => 'POS'.Str::random(6), 'title' => 'Plaza', 'job_profile_id' => $profileId,
            'org_unit_id' => $unit, 'legal_entity_id' => $entity, 'status' => $status,
        ]);
    });
}

// ---------------------------------------------------------------- Catálogos configurables

test('niveles, familias y categorías son catálogos configurables con código único y se archivan', function (): void {
    $level = jobCall($this->admin, 'POST', '/job-levels', ['code' => 'n3', 'name' => 'Analista', 'rank' => 3]);
    $level->assertCreated();
    $this->assertMatchesOpenApi($level, 'post', '/job-levels', 201);
    expect($level->json('data'))->toMatchArray(['code' => 'N3', 'rank' => 3, 'status' => 'active', 'profiles_count' => 0, 'allowed_actions' => ['archive']]);

    $family = jobCall($this->admin, 'POST', '/job-families', ['code' => 'fin', 'name' => 'Finanzas']);
    $family->assertCreated();
    $this->assertMatchesOpenApi($family, 'post', '/job-families', 201);

    $category = jobCall($this->admin, 'POST', '/job-categories', ['code' => 'cat-b', 'name' => 'Categoría B', 'functions' => 'Análisis y registro', 'responsibility_level' => 'Medio']);
    $category->assertCreated();
    $this->assertMatchesOpenApi($category, 'post', '/job-categories', 201);

    $dup = jobCall($this->admin, 'POST', '/job-levels', ['code' => 'N3', 'name' => 'Otro', 'rank' => 4]);
    $dup->assertStatus(422);
    $this->assertMatchesOpenApi($dup, 'post', '/job-levels', 422);

    $list = jobCall($this->admin, 'GET', '/job-levels?sort=rank');
    $list->assertOk();
    $this->assertMatchesOpenApi($list, 'get', '/job-levels', 200);
    expect($list->json('meta.total'))->toBe(1);

    $edit = jobCall($this->admin, 'PATCH', '/job-categories/'.$category->json('data.id'), ['requirements' => 'Título técnico'], ['If-Match' => '"1"']);
    $edit->assertOk();
    $this->assertMatchesOpenApi($edit, 'patch', '/job-categories/{id}', 200);
    expect($edit->json('data.requirements'))->toBe('Título técnico');

    $archived = jobCall($this->admin, 'POST', '/job-levels/'.$level->json('data.id').'/archive', ['reason' => 'Ya no se usa']);
    $archived->assertOk();
    $this->assertMatchesOpenApi($archived, 'post', '/job-levels/{id}/archive', 200);
    expect($archived->json('data'))->toMatchArray(['status' => 'archived', 'allowed_actions' => ['restore']]);

    $again = jobCall($this->admin, 'POST', '/job-levels/'.$level->json('data.id').'/archive');
    $again->assertStatus(409)->assertJsonPath('error.code', 'invalid_transition');
    $this->assertMatchesOpenApi($again, 'post', '/job-levels/{id}/archive', 409);

    jobCall($this->admin, 'POST', '/job-levels/'.$level->json('data.id').'/restore')->assertOk()->assertJsonPath('data.status', 'active');
    expect(DB::table('audit_events')->where('action', 'job_level.archived')->exists())->toBeTrue();
});

// ---------------------------------------------------------------- Puestos

test('un puesto con familia, nivel y categoría se usa en muchas posiciones', function (): void {
    [$level, $category] = TenancyScope::runAs(JOB_TENANT, fn () => [JobLevel::factory()->create()->id, JobCategory::factory()->create()->id]);

    $r = jobCall($this->admin, 'POST', '/job-profiles', [
        'code' => 'an-cont', 'name' => 'Analista Contable', 'job_level_id' => $level, 'job_category_id' => $category,
        'mission' => 'Registrar las operaciones contables', 'functions' => "Conciliar cuentas\nPreparar reportes", 'requirements' => 'Contador',
        'risk_job' => false, 'trust_classification' => 'none',
    ]);
    $r->assertCreated();
    $this->assertMatchesOpenApi($r, 'post', '/job-profiles', 201);
    expect($r->json('data'))->toMatchArray([
        'code' => 'AN-CONT', 'job_level_id' => $level, 'job_category_id' => $category, 'category_pending' => false,
        'positions_count' => 0, 'current_version' => 1, 'status' => 'active',
    ]);
    $id = $r->json('data.id');

    jobPosition($id);
    jobPosition($id);
    jobPosition($id, 'closed');

    $show = jobCall($this->admin, 'GET', '/job-profiles/'.$id);
    $show->assertOk();
    $this->assertMatchesOpenApi($show, 'get', '/job-profiles/{id}', 200);
    expect($show->json('data.positions_count'))->toBe(2);

    $list = jobCall($this->admin, 'GET', '/job-profiles?filter[job_level_id]='.$level);
    $list->assertOk();
    $this->assertMatchesOpenApi($list, 'get', '/job-profiles', 200);
    expect(collect($list->json('data'))->pluck('id')->all())->toBe([$id]);

    jobCall($this->admin, 'GET', '/job-profiles?filter[job_level_id]=no-es-un-id')->assertStatus(400)->assertJsonPath('error.code', 'invalid_filter');
    jobCall($this->admin, 'GET', '/job-profiles?sort=salario')->assertStatus(400);
});

test('la descripción del puesto se versiona y las versiones no se alteran', function (): void {
    $id = jobCall($this->admin, 'POST', '/job-profiles', ['code' => 'ASIS', 'name' => 'Asistente', 'mission' => 'Apoyar al área'])->json('data.id');

    // Cambiar solo la clasificación no crea versión de la descripción.
    jobCall($this->admin, 'PATCH', '/job-profiles/'.$id, ['risk_job' => true])->assertOk()->assertJsonPath('data.current_version', 1);

    $edit = jobCall($this->admin, 'PATCH', '/job-profiles/'.$id, ['mission' => 'Apoyar y coordinar al área', 'change_reason' => 'Reorganización 2026'], ['If-Match' => '"2"']);
    $edit->assertOk();
    $this->assertMatchesOpenApi($edit, 'patch', '/job-profiles/{id}', 200);
    expect($edit->json('data'))->toMatchArray(['mission' => 'Apoyar y coordinar al área', 'current_version' => 2]);

    $stale = jobCall($this->admin, 'PATCH', '/job-profiles/'.$id, ['name' => 'Asistente senior'], ['If-Match' => '"1"']);
    $stale->assertStatus(409);
    $this->assertMatchesOpenApi($stale, 'patch', '/job-profiles/{id}', 409);

    $versions = jobCall($this->admin, 'GET', '/job-profiles/'.$id.'/versions');
    $versions->assertOk();
    $this->assertMatchesOpenApi($versions, 'get', '/job-profiles/{id}/versions', 200);
    expect(collect($versions->json('data'))->map(fn ($v) => [$v['version'], $v['mission'], $v['change_reason']])->all())->toBe([
        [2, 'Apoyar y coordinar al área', 'Reorganización 2026'],
        [1, 'Apoyar al área', null],
    ]);
    expect($versions->json('data.0.changed_by_user_id'))->toBe($this->admin->id);

    // Solo inserción: la base rechaza cambiar o borrar una versión.
    TenancyScope::runAs(JOB_TENANT, function () use ($id): void {
        expect(fn () => DB::table('job_profile_versions')->where('job_profile_id', $id)->update(['mission' => 'x']))->toThrow(QueryException::class);
    });
});

test('RN-ORG-04: el puesto no se elimina; archivado no admite nuevas posiciones ni cambios', function (): void {
    $id = jobCall($this->admin, 'POST', '/job-profiles', ['code' => 'OPE', 'name' => 'Operario'])->json('data.id');
    jobPosition($id);

    jobCall($this->admin, 'DELETE', '/job-profiles/'.$id)->assertStatus(405);

    $archived = jobCall($this->admin, 'POST', '/job-profiles/'.$id.'/archive', ['reason' => 'Fusión de puestos']);
    $archived->assertOk();
    $this->assertMatchesOpenApi($archived, 'post', '/job-profiles/{id}/archive', 200);
    expect($archived->json('data'))->toMatchArray(['status' => 'archived', 'positions_count' => 1, 'allowed_actions' => ['restore']]);

    $locked = jobCall($this->admin, 'PATCH', '/job-profiles/'.$id, ['name' => 'Operario II']);
    $locked->assertStatus(409)->assertJsonPath('error.code', 'invalid_transition');

    $availability = app(StructureAvailability::class);
    TenancyScope::runAs(JOB_TENANT, function () use ($availability, $id): void {
        expect($availability->jobProfileAcceptsPositions($id))->toBeFalse();
        expect(fn () => $availability->assertJobProfileAssignable($id))->toThrow(ValidationException::class);
    });

    jobCall($this->admin, 'POST', '/job-profiles/'.$id.'/restore')->assertOk()->assertJsonPath('data.status', 'active');
    TenancyScope::runAs(JOB_TENANT, fn () => expect($availability->jobProfileAcceptsPositions($id))->toBeTrue());
});

test('RN-ORG-07: un puesto sin categoría laboral queda pendiente y se puede filtrar', function (): void {
    $category = TenancyScope::runAs(JOB_TENANT, fn () => JobCategory::factory()->create()->id);
    $pending = jobCall($this->admin, 'POST', '/job-profiles', ['code' => 'SIN', 'name' => 'Sin categoría'])->json('data');
    jobCall($this->admin, 'POST', '/job-profiles', ['code' => 'CON', 'name' => 'Con categoría', 'job_category_id' => $category])->assertCreated();

    expect($pending['category_pending'])->toBeTrue();
    $list = jobCall($this->admin, 'GET', '/job-profiles?filter[category_pending]=true');
    $list->assertOk();
    expect(collect($list->json('data'))->pluck('code')->all())->toBe(['SIN']);
});

test('solo se eligen niveles, familias y categorías activos; el puesto conserva el que ya tenía', function (): void {
    [$level, $other] = TenancyScope::runAs(JOB_TENANT, fn () => [JobLevel::factory()->create()->id, JobLevel::factory()->create(['status' => 'inactive'])->id]);
    $id = jobCall($this->admin, 'POST', '/job-profiles', ['code' => 'JEF', 'name' => 'Jefe', 'job_level_id' => $level])->json('data.id');
    TenancyScope::runAs(JOB_TENANT, fn () => JobLevel::query()->whereKey($level)->update(['status' => 'inactive']));

    jobCall($this->admin, 'PATCH', '/job-profiles/'.$id, ['name' => 'Jefe de área'])->assertOk()->assertJsonPath('data.job_level_id', $level);

    $bad = jobCall($this->admin, 'POST', '/job-profiles', ['code' => 'X1', 'name' => 'Otro', 'job_level_id' => $other]);
    $bad->assertStatus(422);
    $this->assertMatchesOpenApi($bad, 'post', '/job-profiles', 422);
    expect(collect($bad->json('error.details'))->pluck('field')->all())->toContain('job_level_id');

    $dup = jobCall($this->admin, 'POST', '/job-profiles', ['code' => 'jef', 'name' => 'Repetido']);
    $dup->assertStatus(422);
});

test('sin permiso responde 403, con el módulo apagado 403 module_not_enabled y otra organización 404', function (): void {
    $employee = jobUser(JOB_TENANT, 'empleado');
    $denied = jobCall($employee, 'POST', '/job-profiles', ['code' => 'NO', 'name' => 'No']);
    $denied->assertStatus(403);
    $this->assertMatchesOpenApi($denied, 'post', '/job-profiles', 403);
    jobCall($employee, 'POST', '/job-levels', ['code' => 'NO', 'name' => 'No', 'rank' => 1])->assertStatus(403);

    $foreign = TenancyScope::runAs(JOB_OTHER, fn () => JobProfile::factory()->create()->id);
    $missing = jobCall($this->admin, 'GET', '/job-profiles/'.$foreign);
    $missing->assertNotFound();
    $this->assertMatchesOpenApi($missing, 'get', '/job-profiles/{id}', 404);
    jobCall($this->admin, 'GET', '/job-profiles/'.$foreign.'/versions')->assertNotFound();

    IdentityFixtures::disableModule(JOB_TENANT, 'core');
    jobCall($this->admin, 'GET', '/job-profiles')->assertStatus(403)->assertJsonPath('error.code', 'module_not_enabled');
    jobCall($this->admin, 'GET', '/job-categories')->assertStatus(403)->assertJsonPath('error.code', 'module_not_enabled');
});

// ---------------------------------------------------------------- ORG-1 · Empresas

test('ORG-1: la historia del régimen laboral de la empresa se consulta con su vigencia', function (): void {
    $entity = TenancyScope::runAs(JOB_TENANT, fn (): LegalEntity => LegalEntity::factory()->create());
    TenancyScope::runAs(JOB_TENANT, function () use ($entity): void {
        $entity->regimes()->update(['valid_from' => '2024-01-01', 'valid_to' => '2025-12-31']);
        $entity->regimes()->create(['labor_regime_code' => 'pe_pequena', 'valid_from' => '2026-01-01']);
    });

    $r = jobCall($this->admin, 'GET', '/legal-entities/'.$entity->id.'/regimes');
    $r->assertOk();
    $this->assertMatchesOpenApi($r, 'get', '/legal-entities/{id}/regimes', 200);
    expect(collect($r->json('data'))->map(fn ($x) => [$x['labor_regime_code'], $x['valid_from'], $x['valid_to'], $x['current']])->all())->toBe([
        ['pe_pequena', '2026-01-01', null, true],
        ['pe_general', '2024-01-01', '2025-12-31', false],
    ]);

    $foreign = TenancyScope::runAs(JOB_OTHER, fn () => LegalEntity::factory()->create()->id);
    jobCall($this->admin, 'GET', '/legal-entities/'.$foreign.'/regimes')->assertNotFound();
    jobCall(jobUser(JOB_TENANT, 'empleado'), 'GET', '/legal-entities/'.$entity->id.'/regimes')->assertStatus(403);
});

test('ORG-1: una empresa no se elimina; desactivada no admite nuevas relaciones laborales', function (): void {
    $entity = TenancyScope::runAs(JOB_TENANT, fn () => LegalEntity::factory()->create()->id);
    jobCall($this->admin, 'DELETE', '/legal-entities/'.$entity)->assertStatus(405);

    $availability = app(StructureAvailability::class);
    TenancyScope::runAs(JOB_TENANT, fn () => expect($availability->legalEntityAcceptsEmployments($entity))->toBeTrue());

    jobCall($this->admin, 'POST', '/legal-entities/'.$entity.'/archive', ['reason' => 'Fusión'])->assertOk();
    TenancyScope::runAs(JOB_TENANT, function () use ($availability, $entity): void {
        expect($availability->legalEntityAcceptsEmployments($entity))->toBeFalse();
        expect(fn () => $availability->assertLegalEntityAssignable($entity))->toThrow(ValidationException::class);
        expect($availability->legalEntityAcceptsEmployments((string) Str::uuid()))->toBeFalse();
    });
});
