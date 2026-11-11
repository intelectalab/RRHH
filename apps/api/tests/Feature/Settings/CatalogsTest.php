<?php

/**
 * CFG-10 · Catálogos maestros y de la organización.
 */

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Settings\Application\MasterCatalogImporter;
use App\Shared\Permissions\PermissionResolver;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(DatabaseTransactions::class);

const CAT_TENANT = 'aaaaaaaa-6464-4000-8000-00000000000a';
const CAT_OTHER = 'bbbbbbbb-6464-4000-8000-00000000000b';

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requiere PostgreSQL.');
    }
    TenancyScope::clear();
    foreach ([[CAT_TENANT, 'cat-org', 'es'], [CAT_OTHER, 'cat-otra', 'en']] as [$id, $slug, $locale]) {
        DB::table('tenants')->insertOrIgnore(['id' => $id, 'slug' => $slug, 'name' => 'Org '.$slug, 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN', 'default_locale' => $locale]);
        DB::select('SELECT seed_tenant_defaults(?)', [$id]);
    }
    $this->admin = catUser(CAT_TENANT, 'admin_empresa');
    $this->employee = catUser(CAT_TENANT, 'empleado');
});

afterEach(fn () => TenancyScope::clear());

function catUser(string $tenant, string $role, ?string $locale = 'es'): User
{
    return TenancyScope::runAs($tenant, function () use ($role, $locale): User {
        $u = User::factory()->create(['locale' => $locale]);
        $u->roleAssignments()->create(['role_id' => Role::query()->where('system_role_code', $role)->value('id')]);

        return $u;
    });
}

function catCall(User $user, string $method, string $path, array $body = [], array $headers = [])
{
    TenancyScope::clear();
    app('auth')->forgetGuards();
    app(PermissionResolver::class)->forget();

    return test()->actingAs($user, 'ayni')->withHeaders(['X-Tenant-Id' => $user->tenant_id, 'Idempotency-Key' => (string) Str::uuid()] + $headers)->json($method, '/api/v1'.$path, $body);
}

test('los catálogos maestros tienen código estable, código oficial y etiqueta en el idioma del usuario', function (): void {
    $list = catCall($this->employee, 'GET', '/catalogs');
    $list->assertOk();
    $this->assertMatchesOpenApi($list, 'get', '/catalogs', 200);
    $codes = collect($list->json('data'))->pluck('code');
    expect($codes)->toContain('document_types', 'countries', 'ubigeo_pe', 'banks_pe', 'termination_reasons_pe', 'currencies', 'nationalities', 'pension_funds');

    $docs = catCall($this->employee, 'GET', '/catalogs/document_types/items');
    $docs->assertOk();
    $this->assertMatchesOpenApi($docs, 'get', '/catalogs/{code}/items', 200);
    $dni = collect($docs->json('data'))->firstWhere('code', 'dni');
    expect($dni['statutory_code'])->toBe('01')->and($dni['label'])->toBe('Documento nacional de identidad')->and($dni['source'])->toBe('master');

    // El mismo catálogo en inglés para un usuario con esa preferencia.
    $english = catUser(CAT_TENANT, 'empleado', 'en');
    $en = catCall($english, 'GET', '/catalogs/document_types/items');
    expect(collect($en->json('data'))->firstWhere('code', 'dni')['label'])->toBe('National identity document');
    $en->assertHeader('Content-Language', 'en');

    // Motivos de fin con el código de la tabla 17 y nacionalidades desde países.
    expect(collect(catCall($this->employee, 'GET', '/catalogs/termination_reasons_pe/items')->json('data'))->firstWhere('code', 'resignation')['statutory_code'])->toBe('01');
    expect(collect(catCall($this->employee, 'GET', '/catalogs/nationalities/items')->json('data'))->firstWhere('code', 'PE')['label'])->toBe('Perú');
    expect(collect(catCall($this->employee, 'GET', '/catalogs/currencies/items')->json('data'))->pluck('code'))->toContain('PEN', 'USD');

    catCall($this->employee, 'GET', '/catalogs/no_existe/items')->assertNotFound();
});

test('la organización extiende un catálogo extensible y archiva sus elementos sin borrarlos', function (): void {
    $add = catCall($this->admin, 'POST', '/catalogs/banks_pe/items', ['code' => 'coop_santa_maria', 'labels' => ['es' => 'Cooperativa Santa María', 'en' => 'Santa María Credit Union']]);
    $add->assertCreated()->assertJsonPath('data.source', 'organization')->assertJsonPath('data.label', 'Cooperativa Santa María');
    $this->assertMatchesOpenApi($add, 'post', '/catalogs/{code}/items', 201);
    $id = (string) $add->json('data.id');
    TenancyScope::runAs(CAT_TENANT, fn () => expect(DB::table('audit_events')->where('entity_id', $id)->where('action', 'catalog_item.created')->exists())->toBeTrue());

    $items = collect(catCall($this->employee, 'GET', '/catalogs/banks_pe/items')->json('data'));
    expect($items->pluck('code'))->toContain('bcp', 'coop_santa_maria');

    // Mismo código que uno del maestro: no se admite.
    catCall($this->admin, 'POST', '/catalogs/banks_pe/items', ['code' => 'bcp', 'labels' => ['es' => 'Otro']])->assertStatus(422);
    // Un catálogo no extensible tampoco.
    catCall($this->admin, 'POST', '/catalogs/document_types/items', ['code' => 'otro_doc', 'labels' => ['es' => 'Otro']])->assertStatus(422)->assertJsonPath('error.code', 'catalog_not_extensible');
    // Sin etiqueta en español: 422.
    catCall($this->admin, 'POST', '/catalogs/banks_pe/items', ['code' => 'sin_es', 'labels' => ['en' => 'No Spanish']])->assertStatus(422);

    $upd = catCall($this->admin, 'PATCH', '/catalog-items/'.$id, ['labels' => ['pt' => 'Cooperativa Santa Maria'], 'sort_order' => 5]);
    $upd->assertOk()->assertJsonPath('data.labels.pt', 'Cooperativa Santa Maria')->assertJsonPath('data.labels.es', 'Cooperativa Santa María');
    $this->assertMatchesOpenApi($upd, 'patch', '/catalog-items/{id}', 200);

    $arch = catCall($this->admin, 'POST', '/catalog-items/'.$id.'/archive', ['reason' => 'Ya no se usa']);
    $arch->assertOk()->assertJsonPath('data.is_active', false);
    $this->assertMatchesOpenApi($arch, 'post', '/catalog-items/{id}/archive', 200);
    catCall($this->admin, 'POST', '/catalog-items/'.$id.'/archive', [])->assertStatus(409)->assertJsonPath('error.code', 'invalid_transition');
    expect(collect(catCall($this->employee, 'GET', '/catalogs/banks_pe/items')->json('data'))->pluck('code'))->not->toContain('coop_santa_maria');
    expect(collect(catCall($this->admin, 'GET', '/catalogs/banks_pe/items?include_archived=1')->json('data'))->pluck('code'))->toContain('coop_santa_maria');
    TenancyScope::runAs(CAT_TENANT, fn () => expect(DB::table('catalog_items')->where('id', $id)->whereNull('deleted_at')->exists())->toBeTrue());

    $re = catCall($this->admin, 'POST', '/catalog-items/'.$id.'/reactivate', []);
    $re->assertOk()->assertJsonPath('data.is_active', true);
    $this->assertMatchesOpenApi($re, 'post', '/catalog-items/{id}/reactivate', 200);
});

test('la organización crea catálogos propios; los códigos maestros están reservados', function (): void {
    $c = catCall($this->admin, 'POST', '/catalogs', ['code' => 'motivos_permiso', 'name' => 'Motivos de permiso']);
    $c->assertCreated()->assertJsonPath('data.source', 'organization');
    $this->assertMatchesOpenApi($c, 'post', '/catalogs', 201);
    catCall($this->admin, 'POST', '/catalogs', ['code' => 'motivos_permiso', 'name' => 'Repetido'])->assertStatus(409);
    catCall($this->admin, 'POST', '/catalogs', ['code' => 'countries', 'name' => 'Países propios'])->assertStatus(409);
    catCall($this->admin, 'POST', '/catalogs', ['code' => 'Con Espacios', 'name' => 'X'])->assertStatus(422);

    catCall($this->admin, 'POST', '/catalogs/motivos_permiso/items', ['code' => 'tramite', 'labels' => ['es' => 'Trámite personal']])->assertCreated();
    expect(collect(catCall($this->employee, 'GET', '/catalogs/motivos_permiso/items')->json('data'))->pluck('label'))->toContain('Trámite personal');
});

test('sin settings.catalogs.update no se edita; otra organización no ve los catálogos propios', function (): void {
    catCall($this->employee, 'POST', '/catalogs', ['code' => 'lista_x', 'name' => 'X'])->assertForbidden();
    catCall($this->employee, 'POST', '/catalogs/banks_pe/items', ['code' => 'x', 'labels' => ['es' => 'X']])->assertForbidden();

    $item = catCall($this->admin, 'POST', '/catalogs/banks_pe/items', ['code' => 'caja_propia', 'labels' => ['es' => 'Caja propia']])->json('data.id');
    catCall($this->admin, 'POST', '/catalogs', ['code' => 'solo_a', 'name' => 'Solo A'])->assertCreated();
    catCall($this->employee, 'PATCH', '/catalog-items/'.$item, ['sort_order' => 1])->assertForbidden();
    catCall($this->employee, 'POST', '/catalog-items/'.$item.'/archive', [])->assertForbidden();

    $other = catUser(CAT_OTHER, 'admin_empresa', null);
    expect(collect(catCall($other, 'GET', '/catalogs/banks_pe/items')->json('data'))->pluck('code'))->not->toContain('caja_propia');
    catCall($other, 'GET', '/catalogs/solo_a/items')->assertNotFound();
    catCall($other, 'POST', '/catalogs/solo_a/items', ['code' => 'x', 'labels' => ['es' => 'X']])->assertNotFound();
    catCall($other, 'PATCH', '/catalog-items/'.$item, ['sort_order' => 1])->assertNotFound();
    catCall($other, 'POST', '/catalog-items/'.$item.'/archive', [])->assertNotFound();
    expect(collect(catCall($other, 'GET', '/catalogs')->json('data'))->pluck('code'))->not->toContain('solo_a');
    // Sin preferencia propia, la otra organización responde en su idioma (en).
    expect(collect(catCall($other, 'GET', '/catalogs')->json('data'))->firstWhere('code', 'countries')['name'])->toBe('Countries');
});

test('la plataforma actualiza los catálogos maestros sin desplegar y nunca borra', function (): void {
    // La consola carga los maestros con el rol de plataforma (sin contexto de organización).
    TenancyScope::clear();
    $importer = app(MasterCatalogImporter::class);
    $file = tempnam(sys_get_temp_dir(), 'ubi').'.csv';
    file_put_contents($file, "code,label_es,parent_code,is_verified\n1501,Lima,15,1\n1502,Barranca,15,0\n");
    $totals = $importer->importCsv('ubigeo_pe', $file);
    expect($totals['inserted'])->toBe(2);
    // Repetir la carga no duplica.
    expect($importer->importCsv('ubigeo_pe', $file)['inserted'])->toBe(0);
    unlink($file);

    $prov = catCall($this->employee, 'GET', '/catalogs/ubigeo_pe/items?parent=15');
    expect(collect($prov->json('data'))->pluck('code')->all())->toBe(['1501', '1502']);

    // Cerrar la vigencia de un elemento: deja de ofrecerse desde esa fecha, pero se conserva.
    TenancyScope::clear();
    $importer->importJson(['catalogos' => [['code' => 'banks_pe', 'name' => 'Bancos', 'is_extensible' => true, 'country_code' => 'PE', 'items' => [['code' => 'gnb', 'labels' => ['es' => 'Banco GNB Perú'], 'valid_from' => '2000-01-01', 'valid_to' => '2026-01-31']]]]]);
    expect(collect(catCall($this->employee, 'GET', '/catalogs/banks_pe/items')->json('data'))->pluck('code'))->not->toContain('gnb');
    expect(collect(catCall($this->employee, 'GET', '/catalogs/banks_pe/items?as_of=2025-12-31')->json('data'))->pluck('code'))->toContain('gnb');
    expect(DB::table('master_catalog_items')->where('code', 'bcp')->exists())->toBeTrue();

    // La aplicación de las organizaciones no puede escribir los maestros.
    $grants = DB::select("SELECT has_table_privilege('gestia_app', 'master_catalog_items', 'INSERT') AS ins, has_table_privilege('gestia_app', 'master_catalog_items', 'SELECT') AS sel");
    expect($grants[0]->ins)->toBeFalse()->and($grants[0]->sel)->toBeTrue();
});
