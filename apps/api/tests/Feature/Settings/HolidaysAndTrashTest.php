<?php

/**
 * CFG-8 · Feriados del Perú precargados y calendarios propios. CFG-9 · Papelera y restauración.
 */

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(DatabaseTransactions::class);

const HOL_TENANT = 'aaaaaaaa-6262-4000-8000-00000000000a';

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requiere PostgreSQL.');
    }
    TenancyScope::clear();
    DB::table('tenants')->insertOrIgnore(['id' => HOL_TENANT, 'slug' => 'hol-org', 'name' => 'Grupo H', 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN']);
    DB::select('SELECT seed_tenant_defaults(?)', [HOL_TENANT]);
    $this->admin = TenancyScope::runAs(HOL_TENANT, function (): User {
        $u = User::factory()->create(['display_name' => 'Ana Admin']);
        $u->roleAssignments()->create(['role_id' => Role::query()->where('system_role_code', 'admin_empresa')->value('id')]);

        return $u;
    });
});

afterEach(fn () => TenancyScope::clear());

function holCall(User $user, string $method, string $path, array $body = [])
{
    TenancyScope::clear();
    app('auth')->forgetGuards();

    return test()->actingAs($user, 'ayni')->withHeaders(['X-Tenant-Id' => $user->tenant_id, 'Idempotency-Key' => (string) Str::uuid()])->json($method, '/api/v1'.$path, $body);
}

test('los feriados nacionales del Perú vienen precargados y no se duplican', function (): void {
    $list = holCall($this->admin, 'GET', '/holiday-calendars');
    $list->assertOk();
    $this->assertMatchesOpenApi($list, 'get', '/holiday-calendars', 200);
    $national = collect($list->json('data'))->firstWhere('country', 'PE');
    $dates = collect($national['holidays'])->pluck('date');
    expect($national['is_default'])->toBeTrue()
        ->and($dates)->toContain('2026-01-01', '2026-06-07', '2026-07-28', '2026-07-29', '2026-08-06', '2026-12-09', '2026-12-25')
        ->and(collect($national['holidays'])->where('date', '>=', '2026-01-01')->where('date', '<=', '2026-12-31')->count())->toBe(16)
        ->and(collect($national['holidays'])->firstWhere('date', '2027-01-01')['to_validate'])->toBeTrue()
        ->and(collect($national['holidays'])->firstWhere('date', '2026-01-01')['to_validate'])->toBeFalse();

    holCall($this->admin, 'GET', '/holiday-calendars');
    TenancyScope::runAs(HOL_TENANT, fn () => expect(DB::table('holidays')->count())->toBe($dates->count()));
});

test('una organización agrega un feriado local; uno pasado no se quita', function (): void {
    $this->travelTo('2026-11-10');
    $national = collect(holCall($this->admin, 'GET', '/holiday-calendars')->json('data'))->firstWhere('country', 'PE');

    $created = holCall($this->admin, 'POST', '/holiday-calendars', ['name' => 'Sede Arequipa', 'country' => 'PE']);
    $created->assertCreated();
    $this->assertMatchesOpenApi($created, 'post', '/holiday-calendars', 201);

    $add = holCall($this->admin, 'PATCH', '/holiday-calendars/'.$created->json('data.id'), ['add' => [['date' => '2026-08-15', 'name' => 'Aniversario de Arequipa', 'kind' => 'regional']]]);
    $add->assertOk()->assertJsonPath('data.holidays.0.kind', 'regional')->assertJsonPath('data.holidays.0.locked', true);
    $this->assertMatchesOpenApi($add, 'patch', '/holiday-calendars/{id}', 200);

    $future = holCall($this->admin, 'PATCH', '/holiday-calendars/'.$national['id'], ['add' => [['date' => '2026-12-24', 'name' => 'Nochebuena (día de la empresa)', 'kind' => 'company']]]);
    $future->assertOk();
    $xmas = collect($future->json('data.holidays'))->firstWhere('date', '2026-12-24');
    holCall($this->admin, 'PATCH', '/holiday-calendars/'.$national['id'], ['remove' => [$xmas['id']]])->assertOk();

    $past = collect($national['holidays'])->firstWhere('date', '2026-07-28');
    holCall($this->admin, 'PATCH', '/holiday-calendars/'.$national['id'], ['remove' => [$past['id']]])->assertStatus(422);
    holCall($this->admin, 'PATCH', '/holiday-calendars/'.$national['id'], ['add' => [['date' => '2026-12-25', 'name' => 'Otra']]])->assertStatus(409);
    // Un feriado nacional futuro tampoco se quita: lo fija la ley.
    $christmas = collect($national['holidays'])->firstWhere('date', '2026-12-25');
    expect($christmas['removable'])->toBeFalse();
    holCall($this->admin, 'PATCH', '/holiday-calendars/'.$national['id'], ['remove' => [$christmas['id']]])->assertStatus(422);
});

test('la papelera muestra quién eliminó, cuándo y cuándo se depura, y restaura con sus permisos', function (): void {
    $role = holCall($this->admin, 'POST', '/roles', ['name' => 'Supervisores de planta', 'permissions' => ['org.legal_entities.view'], 'scope' => 'organization']);
    $role->assertCreated();
    holCall($this->admin, 'DELETE', '/roles/'.$role->json('data.id'))->assertSuccessful();

    $trash = holCall($this->admin, 'GET', '/trash');
    $trash->assertOk();
    $this->assertMatchesOpenApi($trash, 'get', '/trash', 200);
    $item = collect($trash->json('data'))->firstWhere('id', $role->json('data.id'));
    expect($item)->toMatchArray(['type' => 'role', 'title' => 'Supervisores de planta', 'deleted_by' => 'Ana Admin'])
        ->and($item['purge_on'])->not->toBeNull();

    $restore = holCall($this->admin, 'POST', '/trash/'.$role->json('data.id').'/restore', []);
    $restore->assertOk();
    $this->assertMatchesOpenApi($restore, 'post', '/trash/{id}/restore', 200);
    holCall($this->admin, 'GET', '/roles/'.$role->json('data.id'))->assertOk()->assertJsonPath('data.permissions', ['org.legal_entities.view']);
    holCall($this->admin, 'POST', '/trash/'.$role->json('data.id').'/restore', [])->assertNotFound();

    $employee = TenancyScope::runAs(HOL_TENANT, function (): User {
        $u = User::factory()->create();
        $u->roleAssignments()->create(['role_id' => Role::query()->where('system_role_code', 'empleado')->value('id')]);

        return $u;
    });
    holCall($employee, 'GET', '/trash')->assertStatus(403);
});
