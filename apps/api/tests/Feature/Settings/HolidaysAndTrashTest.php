<?php

/**
 * CFG-8 · Feriados del Perú precargados y calendarios propios. CFG-9 · Papelera y restauración.
 */

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Workflow\Infrastructure\Models\Task;
use App\Shared\Calendar\CalendarRegistry;
use App\Shared\Calendar\CalendarSource;
use App\Shared\Permissions\EffectivePermissions;
use App\Shared\Permissions\PermissionResolver;
use App\Shared\Tenancy\TenancyScope;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
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

test('el calendario central une feriados y tareas visibles, con enlace a cada registro', function (): void {
    $employee = TenancyScope::runAs(HOL_TENANT, function (): User {
        $u = User::factory()->create();
        $u->roleAssignments()->create(['role_id' => Role::query()->where('system_role_code', 'empleado')->value('id')]);

        return $u;
    });
    [$mine, $other] = TenancyScope::runAs(HOL_TENANT, fn () => [
        Task::factory()->create(['title' => 'Revisar contratos por vencer', 'assignee_user_id' => $this->admin->id, 'due_on' => '2026-07-15', 'status' => 'pending']),
        Task::factory()->create(['title' => 'Tarea de otra persona', 'assignee_user_id' => $this->admin->id, 'created_by_user_id' => $this->admin->id, 'due_on' => '2026-07-20', 'status' => 'pending']),
    ]);
    TenancyScope::runAs(HOL_TENANT, fn () => Task::factory()->create(['title' => 'Cerrada', 'assignee_user_id' => $this->admin->id, 'due_on' => '2026-07-16', 'status' => 'done']));

    $cal = holCall($this->admin, 'GET', '/calendar?from=2026-07-01&to=2026-07-31');
    $cal->assertOk();
    $this->assertMatchesOpenApi($cal, 'get', '/calendar', 200);
    $items = collect($cal->json('data'));
    $holiday = $items->firstWhere('starts_on', '2026-07-28');
    $task = $items->firstWhere('id', $mine->id);
    expect($holiday)->toMatchArray(['source' => 'holidays', 'tone' => 'national'])
        ->and($holiday['link'])->toContain('/configuracion/feriados?calendario=')
        ->and($task)->toMatchArray(['source' => 'tasks', 'starts_on' => '2026-07-15', 'link' => '/tareas?id='.$mine->id])
        ->and($items->pluck('title'))->not->toContain('Cerrada')
        ->and($items->pluck('starts_on')->all())->toBe($items->pluck('starts_on')->sort()->values()->all())
        ->and($cal->json('meta.sources'))->toContain('holidays', 'tasks');

    // Filtro por fuente.
    $only = collect(holCall($this->admin, 'GET', '/calendar?from=2026-07-01&to=2026-07-31&sources=tasks')->json('data'));
    expect($only->pluck('source')->unique()->values()->all())->toBe(['tasks']);

    // Lo que el usuario no puede ver no aparece: el colaborador ve los feriados, no las tareas de otros.
    $emp = collect(holCall($employee, 'GET', '/calendar?from=2026-07-01&to=2026-07-31')->json('data'));
    expect($emp->where('source', 'holidays')->count())->toBeGreaterThan(0)
        ->and($emp->pluck('id'))->not->toContain($mine->id)->not->toContain($other->id);

    // Rango inválido o fuente desconocida: 400.
    holCall($this->admin, 'GET', '/calendar?from=2026-07-31&to=2026-07-01')->assertStatus(400)->assertJsonPath('error.code', 'invalid_filter');
    holCall($this->admin, 'GET', '/calendar?from=2026-01-01&to=2026-12-31')->assertStatus(400);
    holCall($this->admin, 'GET', '/calendar?from=2026-07-01')->assertStatus(400);
    holCall($this->admin, 'GET', '/calendar?from=2026-07-01&to=2026-07-31&sources=nada')->assertStatus(400);
});

test('el calendario solo consulta fuentes de módulos contratados', function (): void {
    $fake = fn (string $key, string $permission) => new class($key, $permission) implements CalendarSource
    {
        public function __construct(private string $k, private string $p) {}

        public function key(): string
        {
            return $this->k;
        }

        public function permission(): string
        {
            return $this->p;
        }

        public function items(CarbonImmutable $from, CarbonImmutable $to, Request $request, ?EffectivePermissions $permissions): array
        {
            return [['id' => 'x-'.$this->k, 'title' => $this->k, 'subtitle' => null, 'starts_on' => $from->toDateString(), 'ends_on' => $from->toDateString(), 'link' => '/', 'tone' => null]];
        }
    };
    app(CalendarRegistry::class)->register($fake('base_demo', 'org.legal_entities.view'));
    // Asistencia no está contratada en esta organización.
    app(CalendarRegistry::class)->register($fake('punches_demo', 'time.punches.view'));

    $cal = holCall($this->admin, 'GET', '/calendar?from=2026-07-01&to=2026-07-02');
    $cal->assertOk();
    expect($cal->json('meta.sources'))->toContain('base_demo')->not->toContain('punches_demo')
        ->and(collect($cal->json('data'))->pluck('source'))->not->toContain('punches_demo');

    // Sin el permiso del calendario, 403.
    TenancyScope::runAs(HOL_TENANT, fn () => DB::table('role_permissions')->where('role_id', Role::query()->where('system_role_code', 'admin_empresa')->value('id'))
        ->where('permission_code', 'time.calendar.view')->delete());
    app(PermissionResolver::class)->forget();
    holCall($this->admin, 'GET', '/calendar?from=2026-07-01&to=2026-07-02')->assertStatus(403);
});

test('al vencer el plazo de la papelera se depura; lo que sigue referenciado se conserva', function (): void {
    $this->travelTo('2026-11-01 10:00:00');
    $free = holCall($this->admin, 'POST', '/roles', ['name' => 'Rol temporal', 'permissions' => ['org.legal_entities.view'], 'scope' => 'organization'])->json('data.id');
    $used = holCall($this->admin, 'POST', '/roles', ['name' => 'Rol con tareas', 'permissions' => ['org.legal_entities.view'], 'scope' => 'organization'])->json('data.id');
    TenancyScope::runAs(HOL_TENANT, fn () => Task::factory()->create(['assignee_role_id' => $used]));
    holCall($this->admin, 'DELETE', '/roles/'.$free)->assertSuccessful();
    holCall($this->admin, 'DELETE', '/roles/'.$used)->assertSuccessful();

    // Antes del plazo (30 días por defecto) no se toca nada.
    $this->travelTo('2026-11-20 10:00:00');
    $this->artisan('trash:purge')->assertSuccessful();
    TenancyScope::runAs(HOL_TENANT, fn () => expect(Role::withTrashed()->whereIn('id', [$free, $used])->count())->toBe(2));

    $this->travelTo('2026-12-02 10:00:00');
    $this->artisan('trash:purge')->assertSuccessful();
    TenancyScope::runAs(HOL_TENANT, function () use ($free, $used): void {
        expect(Role::withTrashed()->whereKey($free)->exists())->toBeFalse()
            ->and(DB::table('role_permissions')->where('role_id', $free)->exists())->toBeFalse()
            ->and(Role::onlyTrashed()->whereKey($used)->exists())->toBeTrue()
            ->and(DB::table('audit_events')->where('entity_id', $free)->where('action', 'role.purged')->exists())->toBeTrue();
    });
    $trash = collect(holCall($this->admin, 'GET', '/trash')->json('data'))->pluck('id');
    expect($trash)->toContain($used)->not->toContain($free);
});
