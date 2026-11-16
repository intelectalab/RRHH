<?php

/**
 * AYU-1 · Ayuda del producto con versiones: GestiaHR la redacta en la consola con módulo, roles, pantallas,
 * idioma y versión del producto. Publicar crea una versión nueva e inmutable y conserva las anteriores
 * consultables (RN-AYU-02); las organizaciones ven la última versión publicada.
 */

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Platform\Application\Help\ProductHelpArticles;
use App\Modules\Platform\Infrastructure\Models\PlatformUser;
use App\Shared\Permissions\ModuleGate;
use App\Shared\Permissions\PermissionResolver;
use App\Shared\Security\Totp;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Support\IdentityFixtures;

uses(DatabaseTransactions::class);

const PH_TENANT = 'cccccccc-a1a1-4000-8000-00000000000a';
const PH_PASSWORD = 'Consola-Segura-2026!';

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requiere PostgreSQL.');
    }
    TenancyScope::clear();
    DB::table('tenants')->insertOrIgnore(['id' => PH_TENANT, 'slug' => 'ph-org', 'name' => 'Comercial Ayuda', 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN']);
    DB::select('SELECT seed_tenant_defaults(?)', [PH_TENANT]);
    TenancyScope::runAs(PH_TENANT, fn () => IdentityFixtures::enableAllModules());
    $this->hr = TenancyScope::runAs(PH_TENANT, function (): User {
        $u = User::factory()->create(['locale' => 'es']);
        $u->roleAssignments()->create(['role_id' => Role::query()->where('system_role_code', 'rrhh')->value('id')]);

        return $u;
    });

    foreach (['superadmin' => 'duena-ayuda@gestia.test', 'support' => 'soporte-ayuda@gestia.test'] as $role => $email) {
        PlatformUser::create(['email' => $email, 'full_name' => 'Consola '.$role, 'role' => $role, 'status' => 'active', 'password_hash' => Hash::make(PH_PASSWORD)]);
    }
    $this->owner = phEnrolled('duena-ayuda@gestia.test');
    $this->support = phEnrolled('soporte-ayuda@gestia.test');
});

afterEach(fn () => TenancyScope::clear());

/**
 * @param  array<string, mixed>  $body
 */
function phConsole(?string $token, string $method, string $path, array $body = [])
{
    TenancyScope::clear();
    app('auth')->forgetGuards();
    $request = test()->flushHeaders();
    $headers = ['Idempotency-Key' => (string) Str::uuid()];
    if ($token !== null) {
        $request = $request->withCredentials()->withUnencryptedCookie('gestia_console_session', $token);
        $headers['X-XSRF-TOKEN'] = hash_hmac('sha256', 'console-xsrf|'.$token, (string) config('app.key'));
    } else {
        $request = $request->withUnencryptedCookie('gestia_console_session', '');
    }

    return $request->withHeaders($headers)->json($method, '/api/v1/platform'.$path, $body);
}

function phEnrolled(string $email): string
{
    $login = phConsole(null, 'POST', '/auth/login', ['email' => $email, 'password' => PH_PASSWORD])->assertOk();
    $token = (string) $login->getCookie('gestia_console_session', false)?->getValue();
    $secret = (string) phConsole($token, 'POST', '/auth/mfa/totp')->assertOk()->json('data.secret');
    phConsole($token, 'POST', '/auth/mfa/totp/confirm', ['code' => Totp::at(Totp::base32Decode($secret), Totp::currentStep())])->assertOk();

    return $token;
}

function phTenant(User $user, string $path)
{
    TenancyScope::clear();
    app('auth')->forgetGuards();
    app(ModuleGate::class)->forget();
    app(PermissionResolver::class)->forget();
    $response = test()->flushHeaders()->actingAs($user, 'ayni')->withHeaders(['X-Tenant-Id' => PH_TENANT])->json('GET', '/api/v1'.$path);
    TenancyScope::clear();

    return $response;
}

test('el artículo declara módulo, roles y pantallas; cada publicación conserva la versión anterior (RN-AYU-02)', function (): void {
    $created = phConsole($this->owner, 'POST', '/help-articles', [
        'slug' => 'guia-legajo-avanzado', 'locale' => 'es', 'kind' => 'guide', 'module_code' => 'core',
        'roles' => ['rrhh'], 'screen_keys' => ['people.employees'], 'product_version' => '1.1',
        'title' => 'Legajo avanzado', 'summary' => 'Carpetas y versiones', 'body' => 'Primera versión del texto.',
    ]);
    $this->assertMatchesOpenApi($created, 'post', '/platform/help-articles', 201);
    $created->assertJsonPath('data.status', 'draft')->assertJsonPath('data.roles', ['rrhh'])
        ->assertJsonPath('data.screen_keys', ['people.employees'])->assertJsonPath('data.version_number', null);
    $id = (string) $created->json('data.id');

    // Un borrador no llega a las organizaciones.
    expect(collect(phTenant($this->hr, '/help/articles')->json('data'))->pluck('slug'))->not->toContain('guia-legajo-avanzado');

    $first = phConsole($this->owner, 'POST', "/help-articles/{$id}/publish");
    $this->assertMatchesOpenApi($first, 'post', '/platform/help-articles/{id}/publish', 200);
    $first->assertJsonPath('data.status', 'published')->assertJsonPath('data.version_number', 1)->assertJsonPath('data.has_unpublished_changes', false);
    expect(phTenant($this->hr, "/help/articles/{$id}")->assertOk()->json('data'))->toMatchArray(['body' => 'Primera versión del texto.', 'version_number' => 1, 'product_version' => '1.1']);

    // Editar no cambia lo que ven las organizaciones hasta volver a publicar.
    $edit = phConsole($this->owner, 'PATCH', "/help-articles/{$id}", ['body' => 'Segunda versión del texto.', 'product_version' => '1.2', 'status' => 'archived']);
    $this->assertMatchesOpenApi($edit, 'patch', '/platform/help-articles/{id}', 200);
    $edit->assertJsonPath('data.status', 'published')->assertJsonPath('data.has_unpublished_changes', true);
    expect(phTenant($this->hr, "/help/articles/{$id}")->json('data.body'))->toBe('Primera versión del texto.');

    phConsole($this->owner, 'POST', "/help-articles/{$id}/publish")->assertOk()->assertJsonPath('data.version_number', 2);
    expect(phTenant($this->hr, "/help/articles/{$id}")->json('data'))->toMatchArray(['body' => 'Segunda versión del texto.', 'version_number' => 2, 'product_version' => '1.2']);

    $show = phConsole($this->owner, 'GET', "/help-articles/{$id}");
    $this->assertMatchesOpenApi($show, 'get', '/platform/help-articles/{id}', 200);
    expect(collect($show->json('data.versions'))->map(fn ($v) => [$v['version_number'], $v['product_version'], $v['body']])->all())->toBe([
        [2, '1.2', 'Segunda versión del texto.'],
        [1, '1.1', 'Primera versión del texto.'],
    ]);

    // Publicar sin cambios no crea otra versión; las versiones publicadas no se modifican.
    phConsole($this->owner, 'POST', "/help-articles/{$id}/publish")->assertOk()->assertJsonPath('data.version_number', 2);
    expect(fn () => DB::transaction(fn () => DB::table('help_article_versions')->where('help_article_id', $id)->update(['body' => 'otro'])))->toThrow(Exception::class);

    // Archivar lo retira de las organizaciones; archivar otra vez es una transición inválida.
    $archived = phConsole($this->owner, 'POST', "/help-articles/{$id}/archive");
    $this->assertMatchesOpenApi($archived, 'post', '/platform/help-articles/{id}/archive', 200);
    phTenant($this->hr, "/help/articles/{$id}")->assertNotFound();
    $again = phConsole($this->owner, 'POST', "/help-articles/{$id}/archive");
    $this->assertMatchesOpenApi($again, 'post', '/platform/help-articles/{id}/archive', 409);
    $again->assertJsonPath('error.code', 'invalid_transition');

    expect(DB::table('platform_audit_events')->where('entity_id', $id)->pluck('action')->all())
        ->toContain('help_article.created', 'help_article.updated', 'help_article.published', 'help_article.archived');
});

test('la consola valida referencias, idioma e identificador, y lista con filtros', function (): void {
    $base = ['slug' => 'pantalla-x', 'locale' => 'es', 'module_code' => 'core', 'title' => 'Pantalla', 'body' => 'Texto'];

    $bad = phConsole($this->owner, 'POST', '/help-articles', $base + ['screen_keys' => ['no.existe'], 'roles' => ['superadmin_saas']]);
    $this->assertMatchesOpenApi($bad, 'post', '/platform/help-articles', 422);
    expect(array_keys($bad->json('error.fields') ?? $bad->json('error.details') ?? []))->not->toBeEmpty();
    phConsole($this->owner, 'POST', '/help-articles', ['locale' => 'fr'] + $base)->assertStatus(422);
    phConsole($this->owner, 'POST', '/help-articles', ['module_code' => 'inexistente'] + $base)->assertStatus(422);

    phConsole($this->owner, 'POST', '/help-articles', $base)->assertCreated();
    phConsole($this->owner, 'POST', '/help-articles', $base)->assertStatus(422);
    phConsole($this->owner, 'POST', '/help-articles', ['locale' => 'en'] + $base)->assertCreated();

    $list = phConsole($this->owner, 'GET', '/help-articles?filter[status]=draft&q=pantalla-x');
    $this->assertMatchesOpenApi($list, 'get', '/platform/help-articles', 200);
    expect(collect($list->json('data'))->pluck('locale')->all())->toBe(['en', 'es']);
    $this->assertMatchesOpenApi(phConsole($this->owner, 'GET', '/help-articles?filter[status]=otro'), 'get', '/platform/help-articles', 400);
    $this->assertMatchesOpenApi(phConsole($this->owner, 'GET', '/help-articles/'.Str::uuid()), 'get', '/platform/help-articles/{id}', 404);
});

test('soporte consulta la ayuda del producto pero no la crea ni la publica', function (): void {
    $this->assertMatchesOpenApi(phConsole($this->support, 'GET', '/help-articles'), 'get', '/platform/help-articles', 200);
    $denied = phConsole($this->support, 'POST', '/help-articles', ['slug' => 'x-y', 'locale' => 'es', 'module_code' => 'core', 'title' => 'X', 'body' => 'Y']);
    $this->assertMatchesOpenApi($denied, 'post', '/platform/help-articles', 403);
    // Desde el host de una organización la consola no existe.
    TenancyScope::clear();
    $this->withHeaders(['X-Tenant-Id' => PH_TENANT])->getJson('/api/v1/platform/help-articles')->assertNotFound();
});

test('la ayuda incluida en el producto se carga una sola vez y no pisa lo editado en la consola', function (): void {
    $service = app(ProductHelpArticles::class);
    $service->syncBundled();
    $slugs = array_keys(require resource_path('help/catalog.php'));
    // Cada artículo incluido, en los tres idiomas; una segunda carga no crea nada.
    expect(DB::table('help_articles')->whereIn('slug', $slugs)->where('status', 'published')->count())->toBe(count($slugs) * 3)
        ->and($service->syncBundled())->toBe(0);

    $id = (string) DB::table('help_articles')->where('slug', 'primeros-pasos')->where('locale', 'es')->value('id');
    phConsole($this->owner, 'PATCH', "/help-articles/{$id}", ['title' => 'Bienvenida'])->assertOk();
    expect($service->syncBundled())->toBe(0)
        ->and(DB::table('help_articles')->where('id', $id)->value('title'))->toBe('Bienvenida');
    expect(DB::table('help_article_versions')->where('help_article_id', $id)->count())->toBe(1);
});
