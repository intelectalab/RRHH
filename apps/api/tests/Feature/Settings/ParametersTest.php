<?php

/**
 * CFG-1 · CFG-2 · CFG-3 · Parámetros con vigencia, herencia organización → empresa y secciones.
 */

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Organization\Infrastructure\Models\LegalEntity;
use App\Shared\Settings\ParameterResolver;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(DatabaseTransactions::class);

const CFG_TENANT = 'aaaaaaaa-6161-4000-8000-00000000000a';

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requiere PostgreSQL.');
    }
    TenancyScope::clear();
    DB::table('tenants')->insertOrIgnore(['id' => CFG_TENANT, 'slug' => 'cfg-org', 'name' => 'Grupo C', 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN']);
    DB::select('SELECT seed_tenant_defaults(?)', [CFG_TENANT]);
    $this->admin = cfgUser('admin_empresa');
    [$this->lima, $this->bogota] = TenancyScope::runAs(CFG_TENANT, fn () => [
        LegalEntity::factory()->create(['legal_name' => 'Andes Perú S.A.C.', 'tax_id' => '20123456781']),
        LegalEntity::factory()->create(['legal_name' => 'Andes Colombia S.A.S.', 'tax_id' => '20123456782']),
    ]);
});

afterEach(fn () => TenancyScope::clear());

function cfgUser(string $role): User
{
    return TenancyScope::runAs(CFG_TENANT, function () use ($role): User {
        $u = User::factory()->create();
        $u->roleAssignments()->create(['role_id' => Role::query()->where('system_role_code', $role)->value('id')]);

        return $u;
    });
}

function cfgCall(User $user, string $method, string $path, array $body = [])
{
    TenancyScope::clear();
    app('auth')->forgetGuards();

    return test()->actingAs($user, 'ayni')->withHeaders(['X-Tenant-Id' => $user->tenant_id, 'Idempotency-Key' => (string) Str::uuid()])->json($method, '/api/v1'.$path, $body);
}

test('consultar a una fecha pasada devuelve el valor de entonces y un valor futuro no altera lo anterior', function (): void {
    $resolver = app(ParameterResolver::class);
    TenancyScope::runAs(CFG_TENANT, function () use ($resolver): void {
        expect($resolver->resolve('vacaciones.anticipacion_minima_dias'))->toMatchArray(['source' => 'default']);

        $resolver->set('papelera.dias_restauracion', 60, 'organization', null, '2026-01-01', 'Política 2026');
        $resolver->set('papelera.dias_restauracion', 90, 'organization', null, '2026-07-01', 'Ajuste de política');

        expect($resolver->value('papelera.dias_restauracion', null, '2026-03-15'))->toBe(60)
            ->and($resolver->value('papelera.dias_restauracion', null, '2026-08-01'))->toBe(90)
            ->and($resolver->value('papelera.dias_restauracion', null, '2025-12-31'))->toBe(json_decode((string) DB::table('parameter_definitions')->where('code', 'papelera.dias_restauracion')->value('default_value'), true));

        $rows = DB::table('tenant_settings')->where('parameter_code', 'papelera.dias_restauracion')->orderBy('valid_from')->get();
        expect($rows)->toHaveCount(2)->and($rows[0]->valid_to)->toBe('2026-06-30')->and($rows[1]->valid_to)->toBeNull();

        // Cada cambio queda auditado con su fuente.
        expect(DB::table('audit_events')->where('action', 'setting.changed')->where('reason', 'Ajuste de política')->count())->toBe(1);
    });
});

test('dos empresas del mismo grupo con zona horaria distinta; la herencia es visible y se puede volver a heredar', function (): void {
    $org = cfgCall($this->admin, 'PUT', '/settings/org', ['values' => [['code' => 'org.zona_horaria', 'value' => 'America/Lima']], 'reason' => 'Sede central']);
    $org->assertOk();
    $this->assertMatchesOpenApi($org, 'put', '/settings/{group}', 200);

    cfgCall($this->admin, 'PUT', '/settings/org', ['legal_entity_id' => $this->bogota->id, 'values' => [['code' => 'org.zona_horaria', 'value' => 'America/Bogota']]])->assertOk();

    $lima = collect(cfgCall($this->admin, 'GET', '/settings/org?legal_entity_id='.$this->lima->id)->json('data.parameters'))->firstWhere('code', 'org.zona_horaria');
    $bog = collect(cfgCall($this->admin, 'GET', '/settings/org?legal_entity_id='.$this->bogota->id)->json('data.parameters'))->firstWhere('code', 'org.zona_horaria');
    expect($lima)->toMatchArray(['value' => 'America/Lima', 'source' => 'organization'])
        ->and($bog)->toMatchArray(['value' => 'America/Bogota', 'source' => 'legal_entity', 'organization_value' => 'America/Lima']);

    cfgCall($this->admin, 'PUT', '/settings/org', ['legal_entity_id' => $this->bogota->id, 'values' => [['code' => 'org.zona_horaria', 'value' => null]]])->assertOk();
    $again = collect(cfgCall($this->admin, 'GET', '/settings/org?legal_entity_id='.$this->bogota->id)->json('data.parameters'))->firstWhere('code', 'org.zona_horaria');
    expect($again['source'])->toBe('organization');
});

test('valores inválidos, ámbitos no permitidos y grupos de módulos no contratados se rechazan', function (): void {
    $bad = cfgCall($this->admin, 'PUT', '/settings/notificaciones', ['values' => [['code' => 'notificaciones.silencio_desde', 'value' => '25:99']]]);
    $bad->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    $this->assertMatchesOpenApi($bad, 'put', '/settings/{group}', 422);

    // Solo organización: no se puede definir por empresa.
    cfgCall($this->admin, 'PUT', '/settings/notificaciones', ['legal_entity_id' => $this->lima->id, 'values' => [['code' => 'notificaciones.silencio_desde', 'value' => '20:00']]])->assertStatus(422);
    // Selector fuera de las opciones.
    cfgCall($this->admin, 'PUT', '/settings/org', ['values' => [['code' => 'org.idioma_defecto', 'value' => 'fr']]])->assertStatus(422);
    // Código de otro grupo en la URL de este grupo.
    cfgCall($this->admin, 'PUT', '/settings/org', ['values' => [['code' => 'notificaciones.silencio_desde', 'value' => '20:00']]])->assertStatus(404);

    DB::table('tenant_modules')->where('tenant_id', CFG_TENANT)->where('module_code', 'payroll')->delete();
    cfgCall($this->admin, 'GET', '/settings/nomina')->assertStatus(404);
    $groups = cfgCall($this->admin, 'GET', '/settings');
    $groups->assertOk();
    $this->assertMatchesOpenApi($groups, 'get', '/settings', 200);
    expect(collect($groups->json('data'))->pluck('group'))->toContain('org', 'notificaciones')->not->toContain('nomina');
});

test('el horario de silencio definido en la configuración lo usa el motor de avisos', function (): void {
    $r = cfgCall($this->admin, 'PUT', '/settings/notificaciones', ['values' => [
        ['code' => 'notificaciones.silencio_desde', 'value' => '20:00'], ['code' => 'notificaciones.silencio_hasta', 'value' => '07:00'],
    ]]);
    $r->assertOk();
    $prefs = cfgCall($this->admin, 'GET', '/notification-preferences');
    expect($prefs->json('data.quiet_hours.from'))->toBe('20:00')->and($prefs->json('data.quiet_hours.to'))->toBe('07:00');
});

test('sin permiso responde 403 y una empresa ajena 404', function (): void {
    $employee = cfgUser('empleado');
    $r = cfgCall($employee, 'GET', '/settings/org');
    $r->assertStatus(403);
    $this->assertMatchesOpenApi($r, 'get', '/settings/{group}', 403);
    cfgCall($this->admin, 'GET', '/settings/org?legal_entity_id='.Str::uuid())->assertNotFound();
});
