<?php

/**
 * CFG-6 · Internacionalización: idioma por usuario con el de la organización por defecto, sin
 * volver a iniciar sesión, y archivos de traducción del servidor completos en es, en y pt.
 */

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Shared\Permissions\PermissionResolver;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(DatabaseTransactions::class);

const LOC_TENANT = 'aaaaaaaa-6565-4000-8000-00000000000a';

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requiere PostgreSQL.');
    }
    TenancyScope::clear();
    DB::table('tenants')->insertOrIgnore(['id' => LOC_TENANT, 'slug' => 'loc-org', 'name' => 'Org idiomas', 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN', 'default_locale' => 'pt']);
    DB::select('SELECT seed_tenant_defaults(?)', [LOC_TENANT]);
    $this->user = TenancyScope::runAs(LOC_TENANT, function (): User {
        $u = User::factory()->create(['locale' => null]);
        $u->roleAssignments()->create(['role_id' => Role::query()->where('system_role_code', 'empleado')->value('id')]);

        return $u;
    });
});

afterEach(fn () => TenancyScope::clear());

function locCall(User $user, string $method, string $path, array $body = [])
{
    TenancyScope::clear();
    app('auth')->forgetGuards();
    app(PermissionResolver::class)->forget();

    return test()->actingAs($user, 'ayni')->withHeaders(['X-Tenant-Id' => $user->tenant_id, 'Idempotency-Key' => (string) Str::uuid(), 'Accept-Language' => 'en'])->json($method, '/api/v1'.$path, $body);
}

test('sin preferencia propia rige el idioma de la organización; al elegir uno cambia en la solicitud siguiente', function (): void {
    $me = locCall($this->user, 'GET', '/me');
    $me->assertOk()->assertJsonPath('data.user.locale', 'pt')->assertJsonPath('data.user.locale_preference', null);
    $me->assertHeader('Content-Language', 'pt');

    $set = locCall($this->user, 'PATCH', '/me/preferences', ['locale' => 'en']);
    $set->assertOk()->assertJsonPath('data.locale', 'en')->assertJsonPath('data.effective_locale', 'en');
    $this->assertMatchesOpenApi($set, 'patch', '/me/preferences', 200);
    TenancyScope::runAs(LOC_TENANT, fn () => expect(DB::table('audit_events')->where('entity_id', $this->user->id)->where('action', 'user.preferences_updated')->exists())->toBeTrue());

    // Misma sesión, sin volver a ingresar: errores y catálogos ya salen en inglés.
    $this->user->refresh();
    $err = locCall($this->user, 'GET', '/catalogs/no_existe/items');
    $err->assertNotFound()->assertHeader('Content-Language', 'en');
    expect(locCall($this->user, 'GET', '/me')->json('data.user.locale'))->toBe('en');
    // Las notificaciones se generan con users.locale.
    expect(DB::table('users')->where('id', $this->user->id)->value('locale'))->toBe('en');

    // null vuelve al idioma de la organización.
    locCall($this->user, 'PATCH', '/me/preferences', ['locale' => null])->assertOk()->assertJsonPath('data.effective_locale', 'pt');
});

test('un idioma no admitido se rechaza', function (): void {
    $bad = locCall($this->user, 'PATCH', '/me/preferences', ['locale' => 'fr']);
    $bad->assertStatus(422);
    $this->assertMatchesOpenApi($bad, 'patch', '/me/preferences', 422);
    locCall($this->user, 'PATCH', '/me/preferences', [])->assertStatus(422);
});

test('sin sesión no se cambian preferencias; las rutas públicas usan Accept-Language', function (): void {
    TenancyScope::clear();
    app('auth')->forgetGuards();
    $this->withHeaders(['X-Tenant-Id' => LOC_TENANT])->json('PATCH', '/api/v1/me/preferences', ['locale' => 'en'])->assertUnauthorized();
    $this->withHeaders(['Accept-Language' => 'pt-BR,pt;q=0.9'])->json('GET', '/api/v1/health')->assertHeader('Content-Language', 'pt');
});

test('los archivos de traducción del servidor tienen las mismas claves en es, en y pt', function (): void {
    $keys = function (string $locale): array {
        $out = [];
        foreach (glob(lang_path("{$locale}/*.php")) ?: [] as $file) {
            $group = basename($file, '.php');
            foreach (array_keys(Arr::dot(require $file)) as $k) {
                $out[] = "{$group}.{$k}";
            }
        }
        sort($out);

        return $out;
    };
    $es = $keys('es');
    expect($es)->not->toBeEmpty();
    foreach (['en', 'pt'] as $l) {
        $other = $keys($l);
        expect(array_values(array_diff($es, $other)))->toBe([], "Faltan en {$l}")
            ->and(array_values(array_diff($other, $es)))->toBe([], "Sobran en {$l}");
    }
});
