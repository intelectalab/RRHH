<?php

/**
 * SEG-12 · Control de descargas y exportaciones.
 */

use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Organization\Infrastructure\Models\LegalEntity;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\IdentityFixtures;

uses(DatabaseTransactions::class);

const EXP_TENANT = 'aaaaaaaa-5e12-4000-8000-00000000000a';
const EXP_OTHER = 'bbbbbbbb-5e12-4000-8000-00000000000b';
const EXP_CAN = ['reports.exports.view', 'reports.exports.create', 'org.legal_entities.view', 'identity.users.view'];

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requiere PostgreSQL.');
    }

    Storage::fake('s3');
    config(['files.disk' => 's3']);
    TenancyScope::clear();
    DB::table('tenants')->insertOrIgnore([
        ['id' => EXP_TENANT, 'slug' => 'exp-org-a', 'name' => 'Exp A', 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN'],
        ['id' => EXP_OTHER, 'slug' => 'exp-org-b', 'name' => 'Exp B', 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN'],
    ]);
    DB::select('SELECT seed_tenant_defaults(?)', [EXP_TENANT]);
    DB::select('SELECT seed_tenant_defaults(?)', [EXP_OTHER]);

    $this->entities = TenancyScope::runAs(EXP_TENANT, function (): array {
        $a = LegalEntity::factory()->create(['legal_name' => 'Andina S.A.C.', 'tax_id' => '20111111111']);
        $b = LegalEntity::factory()->create(['legal_name' => 'Borde S.A.C.', 'tax_id' => '20222222222']);
        $c = LegalEntity::factory()->create(['legal_name' => '=HYPERLINK("x") S.A.C.', 'tax_id' => '20333333333']);
        $def = (string) Str::uuid();
        DB::table('custom_field_definitions')->insert([
            ['id' => $def, 'tenant_id' => EXP_TENANT, 'entity_type' => 'legal_entity', 'key' => 'margen', 'label' => 'Margen', 'data_type' => 'text',
                'is_sensitive' => true, 'visibility_permission_code' => 'people.employments.salary.view', 'sort_order' => 1],
            ['id' => (string) Str::uuid(), 'tenant_id' => EXP_TENANT, 'entity_type' => 'legal_entity', 'key' => 'zona', 'label' => 'Zona', 'data_type' => 'text',
                'is_sensitive' => false, 'visibility_permission_code' => null, 'sort_order' => 2],
        ]);
        DB::table('custom_field_values')->insert([
            'tenant_id' => EXP_TENANT, 'custom_field_definition_id' => $def, 'entity_type' => 'legal_entity', 'entity_id' => $a->id, 'value' => json_encode('MARGEN-SECRETO-42'),
        ]);

        return ['a' => $a->id, 'b' => $b->id, 'c' => $c->id];
    });
});

afterEach(function (): void {
    TenancyScope::clear();
});

function expCall(User $user, string $method, string $path, array $body = [])
{
    TenancyScope::clear();
    app('auth')->forgetGuards();
    $req = test()->actingAs($user, 'ayni')->withHeaders(['X-Tenant-Id' => $user->tenant_id]);

    return $method === 'get' ? $req->get('/api/v1'.$path, ['Accept' => 'application/json']) : $req->postJson('/api/v1'.$path, $body);
}

test('exportar empresas: archivo con filtros, filas, auditoría y descarga contada', function (): void {
    $user = IdentityFixtures::userWithPermissions(EXP_TENANT, EXP_CAN);

    $created = expCall($user, 'post', '/exports', ['resource' => 'legal_entities', 'format' => 'csv', 'filters' => ['q' => 'S.A.C']])->assertCreated();
    $this->assertMatchesOpenApi($created, 'post', '/exports', 201);
    expect($created->json('data.status'))->toBe('completed')
        ->and($created->json('data.row_count'))->toBe(3)
        ->and($created->json('data.filters'))->toBe(['q' => 'S.A.C'])
        ->and($created->json('data.contains_sensitive'))->toBeFalse();
    $id = (string) $created->json('data.id');

    TenancyScope::runAs(EXP_TENANT, function () use ($id, $user): void {
        $job = DB::table('export_jobs')->where('id', $id)->sole();
        expect($job->row_count)->toBe(3)->and(json_decode((string) $job->filters, true))->toBe(['q' => 'S.A.C'])->and($job->requested_by_user_id)->toBe($user->id);
        $audit = DB::table('audit_events')->where('action', 'export.created')->where('entity_id', $id)->sole();
        expect($audit->actor_user_id)->toBe($user->id);
    });

    $download = expCall($user, 'get', '/exports/'.$id.'/download')->assertOk();
    $this->assertMatchesOpenApi($download, 'get', '/exports/{id}/download', 200);
    $csv = (string) $download->getContent();
    expect($csv)->toContain('Razón social')->toContain('Andina S.A.C.')->toContain('Zona')
        // Sin permiso del campo sensible, la columna no existe en el archivo (regla 6).
        ->not->toContain('MARGEN-SECRETO-42')->not->toContain('custom.margen')
        // Inyección de fórmulas neutralizada.
        ->toContain("'=HYPERLINK");

    $shown = expCall($user, 'get', '/exports/'.$id)->assertOk();
    $this->assertMatchesOpenApi($shown, 'get', '/exports/{id}', 200);
    expect($shown->json('data.download_count'))->toBe(1);

    $list = expCall($user, 'get', '/exports')->assertOk();
    $this->assertMatchesOpenApi($list, 'get', '/exports', 200);
    expect(collect($list->json('data'))->pluck('id')->all())->toContain($id);

    TenancyScope::runAs(EXP_TENANT, fn () => expect(DB::table('activity_events')->where('activity', 'file.downloaded')->where('user_id', $user->id)->count())->toBe(1));
});

test('con permiso del campo sensible se exporta y queda en el registro de accesos como exportación', function (): void {
    $user = IdentityFixtures::userWithPermissions(EXP_TENANT, [...EXP_CAN, 'people.employments.salary.view']);

    $created = expCall($user, 'post', '/exports', ['resource' => 'legal_entities', 'format' => 'csv'])->assertCreated();
    expect($created->json('data.contains_sensitive'))->toBeTrue();
    $id = (string) $created->json('data.id');

    $csv = (string) expCall($user, 'get', '/exports/'.$id.'/download')->assertOk()->getContent();
    expect($csv)->toContain('MARGEN-SECRETO-42');

    TenancyScope::runAs(EXP_TENANT, function () use ($user, $id): void {
        $log = DB::table('data_access_logs')->where('user_id', $user->id)->where('entity_type', 'export_job')->sole();
        expect($log->access_kind)->toBe('export')->and($log->entity_id)->toBe($id)->and($log->fields)->toBe('{custom.margen}');
        expect(DB::table('data_access_logs')->where('user_id', $user->id)->where('access_kind', 'download')->count())->toBe(1);
    });
});

test('marca de agua con el nombre de quien exporta cuando la organización la activa', function (): void {
    $user = IdentityFixtures::userWithPermissions(EXP_TENANT, EXP_CAN);
    TenancyScope::runAs(EXP_TENANT, fn () => DB::table('tenant_settings')->insert([
        'tenant_id' => EXP_TENANT, 'parameter_code' => 'seguridad.marca_agua_descargas', 'value' => 'true', 'valid_from' => '2020-01-01',
    ]));

    $id = (string) expCall($user, 'post', '/exports', ['resource' => 'users', 'format' => 'csv'])->assertCreated()->json('data.id');
    $csv = (string) expCall($user, 'get', '/exports/'.$id.'/download')->assertOk()->getContent();
    expect($csv)->toContain('Exportado por '.$user->display_name);
});

test('el alcance de empresa se respeta en la exportación', function (): void {
    $user = IdentityFixtures::userWithPermissions(EXP_TENANT, EXP_CAN, 'legal_entity');
    $b = $this->entities['b'];
    TenancyScope::runAs(EXP_TENANT, function () use ($user, $b): void {
        $userRole = DB::table('user_roles')->where('user_id', $user->id)->value('id');
        DB::table('user_role_scopes')->insert(['tenant_id' => EXP_TENANT, 'user_role_id' => $userRole, 'legal_entity_id' => $b]);
    });

    $id = (string) expCall($user, 'post', '/exports', ['resource' => 'legal_entities', 'format' => 'csv'])->assertCreated()->json('data.id');
    $csv = (string) expCall($user, 'get', '/exports/'.$id.'/download')->assertOk()->getContent();
    expect($csv)->toContain('Borde S.A.C.')->not->toContain('Andina S.A.C.');
});

test('sin permiso de exportación responde 403; sin permiso del listado también', function (): void {
    $noExport = IdentityFixtures::userWithPermissions(EXP_TENANT, ['reports.exports.view', 'org.legal_entities.view']);
    $forbidden = expCall($noExport, 'post', '/exports', ['resource' => 'legal_entities', 'format' => 'csv'])->assertForbidden();
    $this->assertMatchesOpenApi($forbidden, 'post', '/exports', 403);

    $noList = IdentityFixtures::userWithPermissions(EXP_TENANT, ['reports.exports.view', 'reports.exports.create']);
    $denied = expCall($noList, 'post', '/exports', ['resource' => 'legal_entities', 'format' => 'csv'])->assertForbidden();
    $this->assertMatchesOpenApi($denied, 'post', '/exports', 403);

    $noView = IdentityFixtures::userWithPermissions(EXP_TENANT, ['org.legal_entities.view']);
    $this->assertMatchesOpenApi(expCall($noView, 'get', '/exports'), 'get', '/exports', 403);
});

test('datos inválidos responden 422', function (): void {
    $user = IdentityFixtures::userWithPermissions(EXP_TENANT, EXP_CAN);

    $badResource = expCall($user, 'post', '/exports', ['resource' => 'payslips', 'format' => 'csv'])->assertStatus(422);
    $this->assertMatchesOpenApi($badResource, 'post', '/exports', 422);
    expCall($user, 'post', '/exports', ['resource' => 'legal_entities', 'format' => 'xlsx'])->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    expCall($user, 'post', '/exports', ['resource' => 'legal_entities', 'format' => 'csv', 'filters' => ['salary' => '1']])->assertStatus(422);
});

test('otra organización y otro usuario no ven ni descargan la exportación (404)', function (): void {
    $owner = IdentityFixtures::userWithPermissions(EXP_TENANT, EXP_CAN);
    $id = (string) expCall($owner, 'post', '/exports', ['resource' => 'legal_entities', 'format' => 'csv'])->assertCreated()->json('data.id');

    $outsider = IdentityFixtures::userWithPermissions(EXP_OTHER, EXP_CAN);
    $missing = expCall($outsider, 'get', '/exports/'.$id)->assertNotFound();
    $this->assertMatchesOpenApi($missing, 'get', '/exports/{id}', 404);
    $this->assertMatchesOpenApi(expCall($outsider, 'get', '/exports/'.$id.'/download'), 'get', '/exports/{id}/download', 404);
    expect(collect(expCall($outsider, 'get', '/exports')->json('data'))->pluck('id')->all())->not->toContain($id);

    $colleague = IdentityFixtures::userWithPermissions(EXP_TENANT, EXP_CAN);
    expCall($colleague, 'get', '/exports/'.$id.'/download')->assertNotFound();
});

test('una exportación vencida no se descarga (409)', function (): void {
    $user = IdentityFixtures::userWithPermissions(EXP_TENANT, EXP_CAN);
    $id = (string) expCall($user, 'post', '/exports', ['resource' => 'users', 'format' => 'csv'])->assertCreated()->json('data.id');
    TenancyScope::runAs(EXP_TENANT, fn () => DB::table('export_jobs')->where('id', $id)->update(['expires_at' => now()->subDay()]));

    $expired = expCall($user, 'get', '/exports/'.$id.'/download')->assertStatus(409)->assertJsonPath('error.code', 'export_expired');
    $this->assertMatchesOpenApi($expired, 'get', '/exports/{id}/download', 409);
});
