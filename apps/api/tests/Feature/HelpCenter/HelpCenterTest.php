<?php

/**
 * AYU-2 · Centro de ayuda para todos los roles: cada rol de sistema abre la ayuda y ve solo artículos de
 * módulos contratados, dirigidos a su rol y de pantallas que puede abrir (RN-AYU-01); no depende del
 * asistente de IA.
 * AYU-3 · Ayuda contextual: la ayuda de una pantalla trae primero sus artículos y aparte sus ayudas de campo,
 * del mismo contenido versionado. Sin traducción, el artículo sale en español con aviso (RN-AYU-07).
 */

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Platform\Application\Help\ProductHelpArticles;
use App\Shared\Help\HelpScreens;
use App\Shared\Permissions\DataScope;
use App\Shared\Permissions\ModuleGate;
use App\Shared\Permissions\PermissionResolver;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\IdentityFixtures;

uses(DatabaseTransactions::class);

const HC_TENANT = 'aaaaaaaa-a7a7-4000-8000-00000000000a';
const HC_OTHER = 'bbbbbbbb-a7a7-4000-8000-00000000000b';

/** Los diez roles de sistema de una organización. */
const HC_ROLES = ['admin_empresa', 'rrhh', 'payroll', 'recruiter', 'manager', 'empleado', 'auditor', 'sst', 'investigador_denuncias', 'contador'];

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requiere PostgreSQL.');
    }
    TenancyScope::clear();
    foreach ([HC_TENANT => 'hc-org', HC_OTHER => 'hc-otra'] as $id => $slug) {
        DB::table('tenants')->insertOrIgnore(['id' => $id, 'slug' => $slug, 'name' => 'Grupo '.$slug, 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN']);
        DB::select('SELECT seed_tenant_defaults(?)', [$id]);
        TenancyScope::runAs($id, fn () => IdentityFixtures::enableAllModules());
    }
    app(ProductHelpArticles::class)->syncBundled();
});

afterEach(fn () => TenancyScope::clear());

function hcUser(string $tenant, string $role, string $locale = 'es'): User
{
    return TenancyScope::runAs($tenant, function () use ($role, $locale): User {
        $u = User::factory()->create(['locale' => $locale]);
        $u->roleAssignments()->create(['role_id' => Role::query()->where('system_role_code', $role)->value('id')]);

        return $u;
    });
}

function hcCall(User $user, string $path): TestResponse
{
    TenancyScope::clear();
    app('auth')->forgetGuards();
    app(PermissionResolver::class)->forget();
    app(ModuleGate::class)->forget();
    app(DataScope::class)->forget();

    return test()->actingAs($user, 'ayni')->withHeaders(['X-Tenant-Id' => $user->tenant_id, 'Idempotency-Key' => (string) Str::uuid(), 'Accept' => 'application/json'])
        ->json('GET', '/api/v1'.$path);
}

/**
 * @return list<string>
 */
function hcSlugs(TestResponse $response): array
{
    return collect($response->json('data'))->pluck('slug')->all();
}

test('los diez roles de sistema abren la ayuda y cada uno ve las guías de su rol, no las de otros', function (): void {
    foreach (HC_ROLES as $role) {
        $response = hcCall(hcUser(HC_TENANT, $role), '/help/articles');
        $this->assertMatchesOpenApi($response, 'get', '/help/articles', 200);
        expect(hcSlugs($response))->toContain('primeros-pasos');
    }

    $employee = hcSlugs(hcCall(hcUser(HC_TENANT, 'empleado'), '/help/articles?filter[kind]=guide'));
    expect($employee)->toContain('guia-colaborador')->not->toContain('guia-rrhh')->not->toContain('guia-administrador')->not->toContain('guia-jefe');

    $hr = hcSlugs(hcCall(hcUser(HC_TENANT, 'rrhh'), '/help/articles?filter[kind]=guide'));
    expect($hr)->toContain('guia-rrhh', 'guia-colaboradores-y-legajo')->not->toContain('guia-colaborador')->not->toContain('guia-administrador');

    $auditor = hcSlugs(hcCall(hcUser(HC_TENANT, 'auditor'), '/help/articles?filter[kind]=guide'));
    expect($auditor)->toContain('guia-auditor')->not->toContain('guia-rrhh');
});

test('no se ven artículos de pantallas que el rol no puede abrir ni de módulos no contratados (RN-AYU-01)', function (): void {
    $employee = hcSlugs(hcCall(hcUser(HC_TENANT, 'empleado'), '/help/articles?filter[kind]=screen'));
    expect($employee)->toContain('pantalla-portal', 'pantalla-mis-tramites')
        ->not->toContain('pantalla-plantillas')->not->toContain('pantalla-constancias')->not->toContain('pantalla-subsidios');

    // La otra organización no contrató Vacaciones y ausencias: esa ayuda desaparece solo para ella.
    IdentityFixtures::disableModule(HC_OTHER, 'time_off');
    $other = hcCall(hcUser(HC_OTHER, 'rrhh'), '/help/articles');
    expect(hcSlugs($other))->not->toContain('pantalla-vacaciones')->not->toContain('guia-vacaciones-y-ausencias')->not->toContain('campo.time.vacations.dates')
        ->and(hcSlugs(hcCall(hcUser(HC_TENANT, 'rrhh'), '/help/articles')))->toContain('pantalla-vacaciones', 'guia-vacaciones-y-ausencias');
    $this->assertMatchesOpenApi(hcCall(hcUser(HC_OTHER, 'rrhh'), '/help/screens/time.vacations'), 'get', '/help/screens/{screen_key}', 404);

    // Un artículo que no puede ver responde 404 aunque exista.
    $settings = (string) DB::table('help_articles')->where('slug', 'pantalla-constancias')->where('locale', 'es')->value('id');
    $this->assertMatchesOpenApi(hcCall(hcUser(HC_TENANT, 'empleado'), "/help/articles/{$settings}"), 'get', '/help/articles/{id}', 404);
    $this->assertMatchesOpenApi(hcCall(hcUser(HC_TENANT, 'admin_empresa'), "/help/articles/{$settings}"), 'get', '/help/articles/{id}', 200);
});

test('sin el permiso de ver la ayuda se responde 403 y con datos inválidos 400', function (): void {
    $user = hcUser(HC_TENANT, 'empleado');
    TenancyScope::runAs(HC_TENANT, function () use ($user): void {
        $role = Role::query()->create(['code' => 'sin_ayuda', 'name' => 'Sin ayuda']);
        DB::table('user_roles')->where('user_id', $user->id)->delete();
        $user->roleAssignments()->create(['role_id' => $role->id]);
    });
    $this->assertMatchesOpenApi(hcCall($user, '/help/articles'), 'get', '/help/articles', 403);

    $hr = hcUser(HC_TENANT, 'rrhh');
    $this->assertMatchesOpenApi(hcCall($hr, '/help/articles?filter[kind]=otro'), 'get', '/help/articles', 400);
    $this->assertMatchesOpenApi(hcCall($hr, '/help/search'), 'get', '/help/search', 400);
});

test('un rol personalizado ve la ayuda dirigida a roles solo en las pantallas que puede abrir', function (): void {
    $user = hcUser(HC_TENANT, 'empleado');
    TenancyScope::runAs(HC_TENANT, function () use ($user): void {
        $role = Role::query()->create(['code' => 'analista_legajo', 'name' => 'Analista de legajo']);
        foreach (['help.articles.view', 'people.employments.view', 'documents.documents.view'] as $p) {
            DB::table('role_permissions')->insert(['tenant_id' => HC_TENANT, 'role_id' => $role->id, 'permission_code' => $p, 'scope' => 'organization']);
        }
        DB::table('user_roles')->where('user_id', $user->id)->delete();
        $user->roleAssignments()->create(['role_id' => $role->id]);
    });

    $slugs = hcSlugs(hcCall($user, '/help/articles'));
    expect($slugs)->toContain('guia-colaboradores-y-legajo', 'pantalla-colaboradores', 'pantalla-vencimientos')
        ->not->toContain('guia-administrador')->not->toContain('guia-auditor')->not->toContain('pantalla-constancias');
});

test('la ayuda de una pantalla trae primero sus artículos y aparte las ayudas de campo del mismo contenido', function (): void {
    $response = hcCall(hcUser(HC_TENANT, 'rrhh'), '/help/screens/people.employees');
    $this->assertMatchesOpenApi($response, 'get', '/help/screens/{screen_key}', 200);
    $articles = collect($response->json('data.articles'));
    expect($articles->first())->toMatchArray(['slug' => 'pantalla-colaboradores', 'kind' => 'screen'])
        ->and($articles->pluck('slug')->all())->toContain('guia-rrhh', 'guia-colaboradores-y-legajo');

    $vacations = hcCall(hcUser(HC_TENANT, 'rrhh'), '/help/screens/time.vacations')->assertOk();
    expect($vacations->json('data.fields.dates'))->toMatchArray(['slug' => 'campo.time.vacations.dates', 'kind' => 'field', 'version_number' => 1]);
    expect(collect($vacations->json('data.articles'))->pluck('kind')->all())->not->toContain('field');

    $this->assertMatchesOpenApi(hcCall(hcUser(HC_TENANT, 'rrhh'), '/help/screens/no.existe'), 'get', '/help/screens/{screen_key}', 404);
    hcCall(hcUser(HC_TENANT, 'empleado'), '/help/screens/people.certificates')->assertNotFound();
});

test('cada artículo sale en el idioma del usuario y, si falta la traducción, en español con aviso (RN-AYU-07)', function (): void {
    $service = app(ProductHelpArticles::class);
    $only = $service->create(['slug' => 'solo-espanol', 'locale' => 'es', 'module_code' => 'base', 'title' => 'Solo en español', 'body' => 'Texto en español.'], null);
    $service->publish((string) $only['id'], null);

    $en = hcCall(hcUser(HC_TENANT, 'empleado', 'en'), '/help/screens/portal.home')->assertOk();
    expect($en->json('data.articles.0'))->toMatchArray(['locale' => 'en', 'translation_missing' => false, 'title' => 'Your portal']);

    $pt = collect(hcCall(hcUser(HC_TENANT, 'empleado', 'pt'), '/help/articles')->json('data'))->keyBy('slug');
    expect($pt['solo-espanol'])->toMatchArray(['locale' => 'es', 'translation_missing' => true])
        ->and($pt['primeros-pasos'])->toMatchArray(['locale' => 'pt', 'translation_missing' => false]);
});

test('la búsqueda devuelve solo ayuda visible y registra las búsquedas sin resultado sin identificar a la persona (RN-AYU-05)', function (): void {
    $employee = hcUser(HC_TENANT, 'empleado');
    $found = hcCall($employee, '/help/search?q=constancia&screen_key=portal.requests');
    $this->assertMatchesOpenApi($found, 'get', '/help/search', 200);
    expect(hcSlugs($found))->toContain('pantalla-mis-tramites')->not->toContain('pantalla-constancias');

    hcCall($employee, '/help/search?q=zxqwv+inexistente&screen_key=portal.home')->assertOk()->assertJsonPath('meta.total', 0);
    $logged = TenancyScope::runAs(HC_TENANT, fn () => DB::table('help_search_queries')->where('query_text', 'zxqwv inexistente')->first());
    expect($logged)->not->toBeNull()
        ->and($logged->screen_key)->toBe('portal.home')
        ->and($logged->role_code)->toBe('empleado')
        ->and((int) $logged->result_count)->toBe(0)
        ->and(array_keys((array) $logged))->not->toContain('user_id');
    expect(TenancyScope::runAs(HC_OTHER, fn () => DB::table('help_search_queries')->count()))->toBe(0);
});

test('el contenido incluido usa pantallas, roles y módulos existentes y está en los tres idiomas', function (): void {
    $screens = app(HelpScreens::class);
    $catalog = require resource_path('help/catalog.php');
    $roles = DB::table('system_roles')->where('realm', 'tenant')->pluck('code')->all();
    $modules = DB::table('modules')->pluck('code')->all();
    foreach ($catalog as $slug => $meta) {
        expect($modules)->toContain($meta['module']);
        foreach ($meta['screens'] as $key) {
            expect($screens->exists($key))->toBeTrue("{$slug}: pantalla {$key}");
        }
        expect(array_diff($meta['roles'], $roles))->toBe([]);
        if ($meta['kind'] === 'field') {
            expect($meta['screens'])->toHaveCount(1)->and(str_starts_with($slug, 'campo.'.$meta['screens'][0].'.'))->toBeTrue($slug);
        }
    }
    foreach (['es', 'en', 'pt'] as $locale) {
        $texts = require resource_path("help/{$locale}.php");
        expect(array_keys($texts))->toEqualCanonicalizing(array_keys($catalog));
    }
    // Toda pantalla tiene su ayuda propia (AYU-3).
    $covered = collect($catalog)->filter(fn ($m) => $m['kind'] === 'screen')->flatMap(fn ($m) => $m['screens'])->unique()->all();
    expect(array_diff(array_keys($screens->all()), $covered))->toBe([]);
    foreach ($screens->all() as $key => $def) {
        foreach ($def['permissions'] as $permission) {
            expect(DB::table('permissions')->where('code', $permission)->exists())->toBeTrue("{$key}: {$permission}");
        }
    }
});
