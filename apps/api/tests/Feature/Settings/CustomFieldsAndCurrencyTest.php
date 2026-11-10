<?php

/**
 * CFG-4 · Campos personalizados. CFG-7 · Multimoneda y tipos de cambio.
 */

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Organization\Infrastructure\Models\LegalEntity;
use App\Shared\Permissions\PermissionResolver;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(DatabaseTransactions::class);

const CF_TENANT = 'aaaaaaaa-6363-4000-8000-00000000000a';

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requiere PostgreSQL.');
    }
    TenancyScope::clear();
    DB::table('tenants')->insertOrIgnore(['id' => CF_TENANT, 'slug' => 'cf-org', 'name' => 'Grupo CF', 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN']);
    DB::select('SELECT seed_tenant_defaults(?)', [CF_TENANT]);
    $this->admin = cfUser('admin_empresa');
    $this->company = TenancyScope::runAs(CF_TENANT, fn () => LegalEntity::factory()->create(['tax_id' => '20112233445']));
});

afterEach(fn () => TenancyScope::clear());

function cfUser(string $role): User
{
    return TenancyScope::runAs(CF_TENANT, function () use ($role): User {
        $u = User::factory()->create();
        $u->roleAssignments()->create(['role_id' => Role::query()->where('system_role_code', $role)->value('id')]);

        return $u;
    });
}

/**
 * @param  array<int, string>  $permissions
 */
function cfRoleUser(string $code, array $permissions): User
{
    return TenancyScope::runAs(CF_TENANT, function () use ($code, $permissions): User {
        $roleId = (string) Str::uuid();
        DB::table('roles')->insert(['id' => $roleId, 'tenant_id' => CF_TENANT, 'code' => $code, 'name' => ucfirst($code), 'is_system' => false]);
        foreach ($permissions as $p) {
            DB::table('role_permissions')->insert(['tenant_id' => CF_TENANT, 'role_id' => $roleId, 'permission_code' => $p, 'scope' => 'organization']);
        }
        $u = User::factory()->create();
        $u->roleAssignments()->create(['role_id' => $roleId]);

        return $u;
    });
}

function cfCall(User $user, string $method, string $path, array $body = [])
{
    TenancyScope::clear();
    app('auth')->forgetGuards();
    app(PermissionResolver::class)->forget();

    return test()->actingAs($user, 'ayni')->withHeaders(['X-Tenant-Id' => $user->tenant_id, 'Idempotency-Key' => (string) Str::uuid()])->json($method, '/api/v1'.$path, $body);
}

test('un campo nuevo aparece en el formulario de la entidad y sus valores se validan por tipo', function (): void {
    $created = cfCall($this->admin, 'POST', '/custom-fields', ['entity' => 'legal_entity', 'key' => 'zona_comercial', 'label' => 'Zona comercial', 'type' => 'select', 'options' => ['Norte', 'Sur'], 'required' => false, 'sensitive' => false]);
    $created->assertCreated();
    $this->assertMatchesOpenApi($created, 'post', '/custom-fields', 201);
    cfCall($this->admin, 'POST', '/custom-fields', ['entity' => 'legal_entity', 'key' => 'capital_social', 'label' => 'Capital social', 'type' => 'currency', 'required' => false, 'sensitive' => false])->assertCreated();

    $bad = cfCall($this->admin, 'PATCH', '/legal-entities/'.$this->company->id, ['custom_fields' => ['zona_comercial' => 'Oeste', 'capital_social' => ['amount' => '1.5e3', 'currency' => 'PEN']]]);
    $bad->assertStatus(422);
    expect(array_keys($bad->json('error.details') ?? $bad->json('error.fields') ?? []))->not->toBeEmpty();

    $ok = cfCall($this->admin, 'PATCH', '/legal-entities/'.$this->company->id, ['custom_fields' => ['zona_comercial' => 'Sur', 'capital_social' => ['amount' => '150000.00', 'currency' => 'PEN']]]);
    $ok->assertOk()->assertJsonPath('data.custom_fields.zona_comercial', 'Sur')->assertJsonPath('data.custom_fields.capital_social', ['amount' => '150000.00', 'currency' => 'PEN']);
    $this->assertMatchesOpenApi($ok, 'patch', '/legal-entities/{id}', 200);

    // Obligatorio al crear.
    cfCall($this->admin, 'POST', '/custom-fields', ['entity' => 'task', 'key' => 'centro_costo', 'label' => 'Centro de costo', 'type' => 'text', 'required' => true, 'sensitive' => false])->assertCreated();
    cfCall($this->admin, 'POST', '/tasks', ['title' => 'X', 'assignee_user_id' => $this->admin->id])->assertStatus(422);
    cfCall($this->admin, 'POST', '/tasks', ['title' => 'X', 'assignee_user_id' => $this->admin->id, 'custom_fields' => ['centro_costo' => 'CC-100']])
        ->assertCreated()->assertJsonPath('data.custom_fields.centro_costo', 'CC-100');
});

test('un campo sensible aplica su permiso por campo y registra la lectura', function (): void {
    cfCall($this->admin, 'POST', '/custom-fields', ['entity' => 'legal_entity', 'key' => 'cuenta_bancaria', 'label' => 'Cuenta bancaria', 'type' => 'text', 'required' => false, 'sensitive' => true])->assertStatus(422);
    cfCall($this->admin, 'POST', '/custom-fields', ['entity' => 'legal_entity', 'key' => 'cuenta_bancaria', 'label' => 'Cuenta bancaria', 'type' => 'text', 'required' => false, 'sensitive' => true, 'permission' => 'people.people.bank_account.view'])->assertCreated();

    $plain = cfRoleUser('sin_campo', ['org.legal_entities.view', 'org.legal_entities.update']);
    $treasury = cfRoleUser('tesoreria', ['org.legal_entities.view', 'org.legal_entities.update', 'people.people.bank_account.view']);

    // Sin el permiso por campo no se escribe ni viaja en la respuesta.
    cfCall($plain, 'PATCH', '/legal-entities/'.$this->company->id, ['custom_fields' => ['cuenta_bancaria' => '191-123']])->assertStatus(422);
    cfCall($treasury, 'PATCH', '/legal-entities/'.$this->company->id, ['custom_fields' => ['cuenta_bancaria' => '191-123']])->assertOk();
    expect(cfCall($plain, 'GET', '/legal-entities/'.$this->company->id)->json('data.custom_fields'))->not->toHaveKey('cuenta_bancaria');
    $form = cfCall($plain, 'GET', '/custom-fields/form?entity=legal_entity');
    $form->assertOk();
    $this->assertMatchesOpenApi($form, 'get', '/custom-fields/form', 200);
    expect(collect($form->json('data.fields'))->pluck('key'))->not->toContain('cuenta_bancaria');
    expect(collect(cfCall($treasury, 'GET', '/custom-fields/form?entity=legal_entity')->json('data.fields'))->pluck('key'))->toContain('cuenta_bancaria');

    $seen = cfCall($treasury, 'GET', '/legal-entities/'.$this->company->id);
    $seen->assertOk()->assertJsonPath('data.custom_fields.cuenta_bancaria', '191-123');
    TenancyScope::runAs(CF_TENANT, fn () => expect(DB::table('data_access_logs')->where('entity_id', $this->company->id)->where('user_id', $treasury->id)->count())->toBeGreaterThanOrEqual(1)
        ->and(DB::table('data_access_logs')->where('entity_id', $this->company->id)->where('user_id', $plain->id)->count())->toBe(0));
});

test('desactivar un campo conserva los valores; restaurarlo desde la papelera los devuelve', function (): void {
    $id = cfCall($this->admin, 'POST', '/custom-fields', ['entity' => 'legal_entity', 'key' => 'codigo_sap', 'label' => 'Código SAP', 'type' => 'text', 'required' => false, 'sensitive' => false])->json('data.id');
    cfCall($this->admin, 'PATCH', '/legal-entities/'.$this->company->id, ['custom_fields' => ['codigo_sap' => 'SAP-001']])->assertOk();

    $del = cfCall($this->admin, 'DELETE', '/custom-fields/'.$id);
    $del->assertNoContent();
    expect(cfCall($this->admin, 'GET', '/legal-entities/'.$this->company->id)->json('data.custom_fields'))->not->toHaveKey('codigo_sap');
    TenancyScope::runAs(CF_TENANT, fn () => expect(DB::table('custom_field_values')->where('custom_field_definition_id', $id)->count())->toBe(1));

    cfCall($this->admin, 'POST', '/trash/'.$id.'/restore', [])->assertOk();
    cfCall($this->admin, 'GET', '/legal-entities/'.$this->company->id)->assertJsonPath('data.custom_fields.codigo_sap', 'SAP-001');

    // Con valores, el tipo ya no cambia.
    cfCall($this->admin, 'PATCH', '/custom-fields/'.$id, ['type' => 'number'])->assertStatus(422);
    $list = cfCall($this->admin, 'GET', '/custom-fields?filter[entity]=legal_entity');
    $list->assertOk();
    $this->assertMatchesOpenApi($list, 'get', '/custom-fields', 200);
});

test('tipos de cambio con fecha: la conversión indica el tipo aplicado y no altera el original', function (): void {
    $r = cfCall($this->admin, 'POST', '/exchange-rates', ['from_currency' => 'USD', 'to_currency' => 'PEN', 'rate' => '3.75', 'valid_on' => '2026-10-01', 'source' => 'SBS']);
    $r->assertCreated()->assertJsonPath('data.rate', '3.75');
    $this->assertMatchesOpenApi($r, 'post', '/exchange-rates', 201);
    cfCall($this->admin, 'POST', '/exchange-rates', ['from_currency' => 'USD', 'to_currency' => 'PEN', 'rate' => '3.80', 'valid_on' => '2026-11-01', 'source' => 'SBS'])->assertCreated();
    cfCall($this->admin, 'POST', '/exchange-rates', ['from_currency' => 'USD', 'to_currency' => 'PEN', 'rate' => '3.90', 'valid_on' => '2026-11-01', 'source' => 'SBS'])->assertStatus(409);
    cfCall($this->admin, 'POST', '/exchange-rates', ['from_currency' => 'USD', 'to_currency' => 'USD', 'rate' => '1', 'valid_on' => '2026-11-02', 'source' => 'x'])->assertStatus(422);

    $oct = cfCall($this->admin, 'GET', '/exchange-rates/convert?amount=1000.00&from=USD&to=PEN&date=2026-10-15');
    $oct->assertOk()->assertJsonPath('data.converted', ['amount' => '3750.00', 'currency' => 'PEN'])->assertJsonPath('data.rate_date', '2026-10-01')
        ->assertJsonPath('data.original', ['amount' => '1000.00', 'currency' => 'USD']);
    $this->assertMatchesOpenApi($oct, 'get', '/exchange-rates/convert', 200);

    // Inverso con decimales exactos.
    cfCall($this->admin, 'GET', '/exchange-rates/convert?amount=380.00&from=PEN&to=USD&date=2026-11-05')
        ->assertJsonPath('data.converted', ['amount' => '100.00', 'currency' => 'USD'])->assertJsonPath('data.inverse', true);
    cfCall($this->admin, 'GET', '/exchange-rates/convert?amount=1&from=EUR&to=PEN&date=2026-11-05')->assertStatus(422);

    $list = cfCall($this->admin, 'GET', '/exchange-rates');
    $list->assertOk()->assertJsonPath('data.0.valid_on', '2026-11-01');
    $this->assertMatchesOpenApi($list, 'get', '/exchange-rates', 200);
});

test('ningún monto del esquema se guarda sin su moneda', function (): void {
    $missing = DB::select("
        SELECT c.table_name, c.column_name FROM information_schema.columns c
        WHERE c.table_schema = 'public' AND c.data_type = 'numeric'
          AND (c.column_name LIKE '%amount%' OR c.column_name IN ('price', 'total', 'salary', 'cost'))
          AND NOT EXISTS (SELECT 1 FROM information_schema.columns x WHERE x.table_schema = 'public' AND x.table_name = c.table_name AND x.column_name LIKE '%currency%')");
    expect($missing)->toBe([]);
});
