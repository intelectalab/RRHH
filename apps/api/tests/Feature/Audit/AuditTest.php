<?php

/**
 * AUD-1 · Bitácora de solo inserción · AUD-2 · Antes y después · AUD-4 · Visor.
 */

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Organization\Infrastructure\Models\LegalEntity;
use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditLogger;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Support\IdentityFixtures;

uses(DatabaseTransactions::class);

const AUD_TENANT = 'aaaaaaaa-a0d1-4000-8000-00000000000a';
const AUD_OTHER = 'bbbbbbbb-a0d1-4000-8000-00000000000b';

/** Modelo auditable de prueba sobre legal_entities. */
class AuditedLegalEntity extends LegalEntity
{
    use Auditable;

    protected string $auditEntity = 'legal_entity';
}

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requiere PostgreSQL.');
    }

    TenancyScope::clear();
    DB::table('tenants')->insertOrIgnore([
        ['id' => AUD_TENANT, 'slug' => 'aud-org-a', 'name' => 'Aud A', 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN'],
        ['id' => AUD_OTHER, 'slug' => 'aud-org-b', 'name' => 'Aud B', 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN'],
    ]);
    DB::select('SELECT seed_tenant_defaults(?)', [AUD_TENANT]);
    DB::select('SELECT seed_tenant_defaults(?)', [AUD_OTHER]);
});

afterEach(function (): void {
    TenancyScope::clear();
});

function audUser(string $systemRole, string $tenant = AUD_TENANT): User
{
    return TenancyScope::runAs($tenant, function () use ($systemRole): User {
        $user = User::factory()->create(['display_name' => ucfirst($systemRole)]);
        $user->roleAssignments()->create(['role_id' => Role::query()->where('system_role_code', $systemRole)->value('id')]);

        return $user;
    });
}

function audGet(User $user, string $path)
{
    TenancyScope::clear();
    app('auth')->forgetGuards();

    return test()->actingAs($user, 'ayni')->withHeaders(['X-Tenant-Id' => $user->tenant_id])->getJson('/api/v1'.$path);
}

test('AUD-1: el rol de la aplicación no puede modificar ni borrar la auditoría', function (): void {
    TenancyScope::runAs(AUD_TENANT, function (): void {
        DB::table('audit_events')->insert(['tenant_id' => AUD_TENANT, 'action' => 'x.created', 'entity_type' => 'x', 'occurred_at' => now()]);

        expect(fn () => DB::transaction(fn () => DB::table('audit_events')->update(['action' => 'alterado'])))->toThrow(QueryException::class);
        expect(fn () => DB::transaction(fn () => DB::table('audit_events')->delete()))->toThrow(QueryException::class);
    });
});

test('AUD-1: no existe ruta que modifique la auditoría', function (): void {
    $writes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $r) => preg_match('#api/v1/(audit|sensitive-access)#', $r->uri()) === 1)
        ->flatMap(fn (RoutingRoute $r) => array_diff($r->methods(), ['GET', 'HEAD']));

    expect($writes->all())->toBe([]);
});

test('AUD-2: un cambio genera una entrada con el valor anterior y el nuevo por campo', function (): void {
    $id = TenancyScope::runAs(AUD_TENANT, function (): string {
        $e = AuditedLegalEntity::query()->create([
            'legal_name' => 'Antes S.A.C.', 'tax_id' => '20123123123', 'country_code' => 'PE', 'currency' => 'PEN', 'timezone' => 'America/Lima', 'status' => 'active',
        ]);
        $e->update(['legal_name' => 'Después S.A.C.']);

        return $e->id;
    });

    TenancyScope::runAs(AUD_TENANT, function () use ($id): void {
        $entry = DB::table('audit_events')->where('entity_id', $id)->where('action', 'legal_entity.updated')->sole();
        expect(json_decode((string) $entry->before_data, true))->toBe(['legal_name' => 'Antes S.A.C.'])
            ->and(json_decode((string) $entry->after_data, true))->toBe(['legal_name' => 'Después S.A.C.'])
            ->and($entry->changed_fields)->toBe('{legal_name}')
            ->and(DB::table('audit_events')->where('entity_id', $id)->where('action', 'legal_entity.created')->count())->toBe(1);
    });
});

test('AUD-2: las acciones masivas generan una entrada por registro', function (): void {
    TenancyScope::runAs(AUD_TENANT, function (): void {
        $ids = [];
        foreach (['20111000111', '20111000222', '20111000333'] as $tax) {
            $ids[] = AuditedLegalEntity::query()->create(['legal_name' => $tax, 'tax_id' => $tax, 'country_code' => 'PE', 'currency' => 'PEN', 'timezone' => 'America/Lima', 'status' => 'active'])->id;
        }
        AuditedLegalEntity::query()->whereIn('id', $ids)->get()->each->update(['status' => 'inactive']);

        expect(DB::table('audit_events')->whereIn('entity_id', $ids)->where('action', 'legal_entity.updated')->count())->toBe(3);
    });
});

test('AUD-2: un sueldo cambiado queda cifrado y se enmascara a quien no tiene permiso', function (): void {
    $employmentId = (string) Str::uuid();
    TenancyScope::runAs(AUD_TENANT, function () use ($employmentId): void {
        app(AuditLogger::class)->record('employment.updated', 'employment', $employmentId,
            ['base_salary' => ['amount' => '3000.00', 'currency' => 'PEN'], 'position' => 'Vendedor'],
            ['base_salary' => ['amount' => '3500.00', 'currency' => 'PEN'], 'position' => 'Supervisor']);

        $raw = DB::table('audit_events')->where('entity_id', $employmentId)->sole();
        expect((string) $raw->after_data)->not->toContain('3500');
    });

    $hr = IdentityFixtures::userWithPermissions(AUD_TENANT, ['audit.logs.view']);
    $masked = audGet($hr, '/audit-logs?filter[entity_id]='.$employmentId)->assertOk();
    expect($masked->json('data.0.after.base_salary'))->toBe('••••')
        ->and($masked->json('data.0.after.position'))->toBe('Supervisor')
        ->and($masked->json('data.0.sensitive'))->toBeTrue();
    $this->assertMatchesOpenApi($masked, 'get', '/audit-logs', 200);

    $payroll = IdentityFixtures::userWithPermissions(AUD_TENANT, ['audit.logs.view', 'people.employments.salary.view']);
    $visible = audGet($payroll, '/audit-logs?filter[entity_id]='.$employmentId)->assertOk();
    expect($visible->json('data.0.before.base_salary.amount'))->toBe('3000.00')
        ->and($visible->json('data.0.after.base_salary.amount'))->toBe('3500.00');
});

test('AUD-4: se reconstruye la historia completa de cambios de un registro', function (): void {
    $admin = audUser('admin_empresa');
    TenancyScope::clear();
    app('auth')->forgetGuards();
    $roleId = $this->actingAs($admin, 'ayni')->withHeaders(['X-Tenant-Id' => AUD_TENANT])
        ->postJson('/api/v1/roles', ['name' => 'Historia', 'permissions' => ['identity.roles.view']])->json('data.id');
    foreach (['Historia 2', 'Historia 3'] as $name) {
        TenancyScope::clear();
        app('auth')->forgetGuards();
        $this->actingAs($admin, 'ayni')->withHeaders(['X-Tenant-Id' => AUD_TENANT])->patchJson('/api/v1/roles/'.$roleId, ['name' => $name])->assertOk();
    }

    $history = audGet(audUser('auditor'), '/audit-logs?filter[entity]=role&filter[entity_id]='.$roleId)->assertOk();
    expect(collect($history->json('data'))->pluck('action')->all())->toBe(['role.updated', 'role.updated', 'role.created'])
        ->and($history->json('data.0.before.name'))->toBe('Historia 2')
        ->and($history->json('data.0.after.name'))->toBe('Historia 3')
        ->and($history->json('data.0.actor_id'))->toBe($admin->id);

    $one = audGet(audUser('auditor'), '/audit-logs/'.$history->json('data.2.id'))->assertOk()->assertJsonPath('data.action', 'role.created');
    $this->assertMatchesOpenApi($one, 'get', '/audit-logs/{id}', 200);
});

test('AUD-4: filtros por usuario, acción y fechas, y paginación por cursor', function (): void {
    $auditor = audUser('auditor');
    TenancyScope::runAs(AUD_TENANT, function () use ($auditor): void {
        foreach (range(1, 5) as $i) {
            DB::table('audit_events')->insert(['tenant_id' => AUD_TENANT, 'actor_user_id' => $auditor->id, 'action' => 'demo.done', 'entity_type' => 'demo', 'occurred_at' => now()->subMinutes($i)]);
        }
        DB::table('audit_events')->insert(['tenant_id' => AUD_TENANT, 'action' => 'demo.old', 'entity_type' => 'demo', 'occurred_at' => '2025-01-15 10:00:00+00']);
    });

    $page1 = audGet($auditor, '/audit-logs?per_page=3&filter[action]=demo.done&filter[actor_id]='.$auditor->id)->assertOk();
    expect($page1->json('data'))->toHaveCount(3)->and($page1->json('meta.next_cursor'))->not->toBeNull();
    $page2 = audGet($auditor, '/audit-logs?per_page=3&filter[action]=demo.done&cursor='.urlencode((string) $page1->json('meta.next_cursor')))->assertOk();
    expect($page2->json('data'))->toHaveCount(2);

    expect(audGet($auditor, '/audit-logs?filter[from]=2025-01-01&filter[to]=2025-01-31')->json('data.0.action'))->toBe('demo.old');
});

test('el Auditor consulta todo y no puede modificar nada en el sistema', function (): void {
    $permissions = TenancyScope::runAs(AUD_TENANT, fn () => DB::table('role_permissions as rp')->join('roles as r', 'r.id', '=', 'rp.role_id')
        ->where('r.system_role_code', 'auditor')->pluck('rp.permission_code')->all());

    // SEG-12: exportar un listado es una lectura (genera un archivo, no cambia datos del sistema);
    // el catálogo (kit/docs/07) le da reports.exports.create al Auditor.
    // UI-4: las vistas guardadas son preferencias propias de la tabla (no datos del sistema); el
    // catálogo da settings.saved_views.* a todos los roles, solo sobre lo propio.
    // AYU-5 · AYU-7: valorar un artículo de ayuda y marcar el recorrido propio como visto son registros del
    // propio usuario sobre la ayuda (no datos del sistema); el catálogo los deja con help.articles.view.
    // IAR-9 · IAR-11: preguntar al asistente de ayuda es una lectura de la ayuda y valorar su respuesta es un
    // registro propio; el catálogo le da ai.help_assistant.use a todos los roles.
    $readOnlyPosts = ['api/v1/ai/help-assistant/ask', 'api/v1/ai/help-assistant/answers/{id}/feedback', 'api/v1/exports', 'api/v1/saved-views', 'api/v1/saved-views/{id}', 'api/v1/help/articles/{id}/feedback', 'api/v1/help/tours/{tour_key}/complete'];

    $writes = [];
    foreach (Route::getRoutes()->getRoutes() as $route) {
        if (array_diff($route->methods(), ['GET', 'HEAD']) === [] || in_array($route->uri(), $readOnlyPosts, true)) {
            continue;
        }
        foreach ($route->gatherMiddleware() as $m) {
            if (is_string($m) && str_starts_with($m, 'permission:') && in_array(substr($m, 11), $permissions, true)) {
                $writes[] = implode('|', $route->methods()).' '.$route->uri();
            }
        }
    }

    expect($writes)->toBe([]);
    audGet(audUser('auditor'), '/audit-logs')->assertOk();
});

test('registro de accesos a datos sensibles y permisos del visor', function (): void {
    TenancyScope::runAs(AUD_TENANT, fn () => DB::table('data_access_logs')->insert(['tenant_id' => AUD_TENANT, 'access_kind' => 'api', 'data_category' => 'salary', 'entity_type' => 'Employment', 'fields' => '{base_salary}', 'occurred_at' => now()]));

    $response = audGet(audUser('auditor'), '/sensitive-access-logs')->assertOk();
    expect($response->json('data.0.fields'))->toBe(['base_salary']);
    $this->assertMatchesOpenApi($response, 'get', '/sensitive-access-logs', 200);

    $forbidden = audGet(audUser('empleado'), '/audit-logs');
    $forbidden->assertStatus(403);
    $this->assertMatchesOpenApi($forbidden, 'get', '/audit-logs', 403);
});

test('una entrada de auditoría de otra organización responde 404', function (): void {
    $foreign = TenancyScope::runAs(AUD_OTHER, function (): string {
        $id = (string) Str::uuid();
        DB::table('audit_events')->insert(['id' => $id, 'tenant_id' => AUD_OTHER, 'action' => 'x.created', 'entity_type' => 'x', 'occurred_at' => now()]);

        return $id;
    });

    audGet(audUser('auditor'), '/audit-logs/'.$foreign)->assertNotFound();
});
