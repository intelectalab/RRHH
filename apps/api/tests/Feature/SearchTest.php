<?php

/**
 * UI-7 · Búsqueda global: respeta permisos, alcance y organización.
 */

use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Organization\Infrastructure\Models\LegalEntity;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Support\IdentityFixtures;

uses(DatabaseTransactions::class);

const SRCH_A = 'aaaaaaaa-5ea7-4000-8000-00000000000a';
const SRCH_B = 'bbbbbbbb-5ea7-4000-8000-00000000000b';

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requiere PostgreSQL.');
    }
    TenancyScope::clear();
    DB::table('tenants')->insertOrIgnore([
        ['id' => SRCH_A, 'slug' => 'srch-a', 'name' => 'A', 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN'],
        ['id' => SRCH_B, 'slug' => 'srch-b', 'name' => 'B', 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN'],
    ]);
    TenancyScope::runAs(SRCH_A, function (): void {
        User::factory()->create(['display_name' => 'Marisol Huamán']);
        LegalEntity::factory()->create(['legal_name' => 'Marisol Textiles S.A.C.', 'tax_id' => '20500000001']);
    });
    TenancyScope::runAs(SRCH_B, fn () => User::factory()->create(['display_name' => 'Marisol de Otra Organización']));
});

afterEach(fn () => TenancyScope::clear());

function srch(User $user, string $q)
{
    TenancyScope::clear();
    app('auth')->forgetGuards();

    return test()->actingAs($user, 'ayni')->withHeaders(['X-Tenant-Id' => $user->tenant_id])->getJson('/api/v1/search?q='.urlencode($q));
}

test('encuentra personas y empresas de su organización según sus permisos', function (): void {
    $admin = IdentityFixtures::userWithPermissions(SRCH_A, ['identity.users.view', 'org.legal_entities.view']);

    $response = srch($admin, 'marisol')->assertOk();
    $this->assertMatchesOpenApi($response, 'get', '/search', 200);
    $titles = collect($response->json('data'))->map(fn ($r) => $r['type'].':'.$r['title'])->all();

    expect($titles)->toContain('user:Marisol Huamán', 'legal_entity:Marisol Textiles S.A.C.')
        ->and(implode(' ', $titles))->not->toContain('Otra Organización');
});

test('sin permiso sobre una fuente no aparecen sus resultados', function (): void {
    $employee = IdentityFixtures::userWithPermissions(SRCH_A, ['org.legal_entities.view']);

    $types = collect(srch($employee, 'marisol')->json('data'))->pluck('type')->unique()->values()->all();
    expect($types)->toBe(['legal_entity']);
});

test('un término muy corto no busca', function (): void {
    $admin = IdentityFixtures::userWithPermissions(SRCH_A);
    expect(srch($admin, 'm')->json('data'))->toBe([]);
});

test('sin sesión responde 401', function (): void {
    $this->withHeaders(['X-Tenant-Id' => SRCH_A])->getJson('/api/v1/search?q=marisol')->assertStatus(401);
});
