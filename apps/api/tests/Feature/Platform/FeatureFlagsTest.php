<?php

/**
 * SAS-7 · Banderas de funcionalidad: se evalúan por organización, plan, grupo beta y país (en ese orden,
 * RN-SAS-07), después el porcentaje gradual y el valor por defecto. La API y la web (/me) consultan la misma
 * evaluación, cada cambio surte efecto sin desplegar y queda en la auditoría de la consola.
 */

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Platform\Infrastructure\Models\PlatformUser;
use App\Shared\Features\FeatureFlags;
use App\Shared\Permissions\ModuleGate;
use App\Shared\Permissions\PermissionResolver;
use App\Shared\Security\Totp;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

uses(DatabaseTransactions::class);

const FF_LIMA = 'dddddddd-5a57-4000-8000-00000000000a';
const FF_BOGOTA = 'dddddddd-5a57-4000-8000-00000000000b';
const FF_PASSWORD = 'Consola-Segura-2026!';

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requiere PostgreSQL.');
    }
    TenancyScope::clear();
    DB::table('countries')->insertOrIgnore([['code' => 'CO', 'name' => 'Colombia', 'default_currency' => 'COP', 'default_locale' => 'es']]);
    DB::table('currencies')->insertOrIgnore([['code' => 'COP', 'name' => 'Peso colombiano']]);
    DB::table('tenants')->insertOrIgnore([
        ['id' => FF_LIMA, 'slug' => 'ff-lima', 'name' => 'Comercial Lima', 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN'],
        ['id' => FF_BOGOTA, 'slug' => 'ff-bogota', 'name' => 'Comercial Bogotá', 'status' => 'active', 'country_code' => 'CO', 'default_currency' => 'COP'],
    ]);
    DB::select('SELECT seed_tenant_defaults(?)', [FF_LIMA]);
    DB::select('SELECT seed_tenant_defaults(?)', [FF_BOGOTA]);
    $this->lima = ffUser(FF_LIMA);
    $this->bogota = ffUser(FF_BOGOTA);

    ffConsoleUser('duena-ff@gestia.test', 'superadmin');
    $this->token = ffEnrolled('duena-ff@gestia.test');

    // Una ruta de la organización detrás de la bandera (regla 10: el backend valida).
    Route::middleware(['api', 'auth:ayni', 'feature:asistente.nuevo'])->get('/api/v1/__ff-asistente', fn () => response()->json(['data' => 'ok']));
});

afterEach(fn () => TenancyScope::clear());

function ffUser(string $tenant): User
{
    return TenancyScope::runAs($tenant, function (): User {
        $user = User::factory()->create();
        $user->roleAssignments()->create(['role_id' => Role::query()->where('system_role_code', 'admin_empresa')->firstOrFail()->id]);

        return $user;
    });
}

function ffConsoleUser(string $email, string $role): PlatformUser
{
    return PlatformUser::create([
        'email' => $email, 'full_name' => 'Consola '.$role, 'role' => $role,
        'status' => 'active', 'password_hash' => Hash::make(FF_PASSWORD),
    ]);
}

/**
 * @param  array<string, mixed>  $body
 */
function ffConsole(?string $token, string $method, string $path, array $body = [])
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

function ffEnrolled(string $email): string
{
    $login = ffConsole(null, 'POST', '/auth/login', ['email' => $email, 'password' => FF_PASSWORD])->assertOk();
    $token = (string) $login->getCookie('gestia_console_session', false)?->getValue();
    $secret = (string) ffConsole($token, 'POST', '/auth/mfa/totp')->assertOk()->json('data.secret');
    ffConsole($token, 'POST', '/auth/mfa/totp/confirm', ['code' => Totp::at(Totp::base32Decode($secret), Totp::currentStep())])->assertOk();

    return $token;
}

function ffApp(User $user, string $tenant, string $method, string $path)
{
    TenancyScope::clear();
    app('auth')->forgetGuards();
    app(ModuleGate::class)->forget();
    app(PermissionResolver::class)->forget();
    app(FeatureFlags::class)->forget();

    $response = test()->flushHeaders()->actingAs($user, 'ayni')->withHeaders(['X-Tenant-Id' => $tenant])->json($method, '/api/v1'.$path);
    TenancyScope::clear();

    return $response;
}

/**
 * @return array<string, array{enabled: bool, source: string}>
 */
function ffEval(string $tenant): array
{
    app(FeatureFlags::class)->forget();

    return TenancyScope::runAs($tenant, fn () => app(FeatureFlags::class)->all());
}

test('una función se activa solo para un país; la API y la web ven lo mismo y el cambio surte efecto sin desplegar', function (): void {
    ffApp($this->lima, FF_LIMA, 'GET', '/__ff-asistente')->assertStatus(403)->assertJsonPath('error.code', 'feature_not_enabled');
    expect(ffApp($this->lima, FF_LIMA, 'GET', '/me')->json('data.features'))->not->toContain('asistente.nuevo');

    $created = ffConsole($this->token, 'POST', '/feature-flags', [
        'code' => 'asistente.nuevo', 'name' => 'Nuevo asistente', 'description' => 'Asistente de ayuda renovado',
        'rules' => [['target_kind' => 'country', 'target_value' => 'PE']],
    ]);
    $this->assertMatchesOpenApi($created, 'post', '/platform/feature-flags', 201);
    $created->assertJsonPath('data.status', 'active')->assertJsonPath('data.default_enabled', false)
        ->assertJsonPath('data.rules', [['target_kind' => 'country', 'target_value' => 'PE', 'enabled' => true]]);

    // Sin desplegar ni reiniciar: la siguiente petición ya evalúa la regla.
    ffApp($this->lima, FF_LIMA, 'GET', '/__ff-asistente')->assertOk();
    expect(ffApp($this->lima, FF_LIMA, 'GET', '/me')->json('data.features'))->toContain('asistente.nuevo');
    ffApp($this->bogota, FF_BOGOTA, 'GET', '/__ff-asistente')->assertStatus(403);
    expect(ffApp($this->bogota, FF_BOGOTA, 'GET', '/me')->json('data.features'))->not->toContain('asistente.nuevo');
    expect(ffEval(FF_LIMA)['asistente.nuevo'])->toBe(['enabled' => true, 'source' => 'country']);

    $list = ffConsole($this->token, 'GET', '/feature-flags?q=asistente');
    $this->assertMatchesOpenApi($list, 'get', '/platform/feature-flags', 200);
    expect(collect($list->json('data'))->pluck('code')->all())->toBe(['asistente.nuevo']);
    $this->assertMatchesOpenApi(ffConsole($this->token, 'GET', '/feature-flags?filter[status]=otro'), 'get', '/platform/feature-flags', 400);
});

test('una función se activa solo para una organización: la organización manda sobre plan, grupo beta y país (RN-SAS-07)', function (): void {
    $id = (string) ffConsole($this->token, 'POST', '/feature-flags', ['code' => 'asistente.nuevo', 'name' => 'Nuevo asistente'])->assertCreated()->json('data.id');

    $set = ffConsole($this->token, 'POST', "/feature-flags/{$id}/tenants", ['tenant_id' => FF_BOGOTA, 'enabled' => true, 'reason' => 'Piloto con el cliente']);
    $this->assertMatchesOpenApi($set, 'post', '/platform/feature-flags/{id}/tenants', 200);
    expect($set->json('data.0'))->toMatchArray(['tenant_id' => FF_BOGOTA, 'enabled' => true, 'reason' => 'Piloto con el cliente']);
    ffApp($this->bogota, FF_BOGOTA, 'GET', '/__ff-asistente')->assertOk();
    ffApp($this->lima, FF_LIMA, 'GET', '/__ff-asistente')->assertStatus(403);

    // Orden de evaluación: país < grupo beta < plan < organización.
    $plan = (string) DB::table('plans')->where('code', 'profesional')->value('id');
    DB::table('subscriptions')->insert(['tenant_id' => FF_LIMA, 'plan_id' => $plan, 'status' => 'active', 'starts_on' => now()->toDateString()]);
    $update = ffConsole($this->token, 'PATCH', "/feature-flags/{$id}", ['rules' => [
        ['target_kind' => 'country', 'target_value' => 'PE', 'enabled' => true],
        ['target_kind' => 'beta_group', 'target_value' => 'pioneros', 'enabled' => false],
        ['target_kind' => 'plan', 'target_value' => 'profesional', 'enabled' => true],
    ]]);
    $this->assertMatchesOpenApi($update, 'patch', '/platform/feature-flags/{id}', 200);
    expect(ffEval(FF_LIMA)['asistente.nuevo'])->toBe(['enabled' => true, 'source' => 'plan']);

    $beta = ffConsole($this->token, 'PATCH', '/tenants/'.FF_LIMA, ['beta_groups' => ['pioneros']]);
    $this->assertMatchesOpenApi($beta, 'patch', '/platform/tenants/{id}', 200);
    $beta->assertJsonPath('data.beta_groups', ['pioneros']);
    expect(ffEval(FF_LIMA)['asistente.nuevo'])->toBe(['enabled' => true, 'source' => 'plan']);
    ffConsole($this->token, 'PATCH', "/feature-flags/{$id}", ['rules' => [
        ['target_kind' => 'country', 'target_value' => 'PE', 'enabled' => true],
        ['target_kind' => 'beta_group', 'target_value' => 'pioneros', 'enabled' => false],
    ]])->assertOk();
    expect(ffEval(FF_LIMA)['asistente.nuevo'])->toBe(['enabled' => false, 'source' => 'beta_group']);

    ffConsole($this->token, 'POST', "/feature-flags/{$id}/tenants", ['tenant_id' => FF_LIMA, 'enabled' => true])->assertOk();
    expect(ffEval(FF_LIMA)['asistente.nuevo'])->toBe(['enabled' => true, 'source' => 'tenant']);

    $evaluated = ffConsole($this->token, 'GET', '/tenants/'.FF_LIMA.'/feature-flags');
    $this->assertMatchesOpenApi($evaluated, 'get', '/platform/tenants/{id}/feature-flags', 200);
    expect(collect($evaluated->json('data'))->firstWhere('code', 'asistente.nuevo'))->toMatchArray(['enabled' => true, 'source' => 'tenant']);

    $remove = ffConsole($this->token, 'POST', "/feature-flags/{$id}/tenants/".FF_LIMA.'/remove');
    $this->assertMatchesOpenApi($remove, 'post', '/platform/feature-flags/{id}/tenants/{tenantId}/remove', 200);
    expect(ffEval(FF_LIMA)['asistente.nuevo']['source'])->toBe('beta_group');
    $this->assertMatchesOpenApi(ffConsole($this->token, 'POST', "/feature-flags/{$id}/tenants/".FF_LIMA.'/remove'), 'post', '/platform/feature-flags/{id}/tenants/{tenantId}/remove', 404);

    $tenants = ffConsole($this->token, 'GET', "/feature-flags/{$id}/tenants");
    $this->assertMatchesOpenApi($tenants, 'get', '/platform/feature-flags/{id}/tenants', 200);
    expect(collect($tenants->json('data'))->pluck('tenant_id')->all())->toBe([FF_BOGOTA]);
});

test('el porcentaje gradual reparte por organización de forma estable y el valor por defecto decide al final', function (): void {
    $id = (string) ffConsole($this->token, 'POST', '/feature-flags', [
        'code' => 'tablero.nuevo', 'name' => 'Tablero nuevo', 'default_enabled' => true,
        'rules' => [['target_kind' => 'percentage', 'target_value' => '100']],
    ])->assertCreated()->json('data.id');
    expect(ffEval(FF_LIMA)['tablero.nuevo'])->toBe(['enabled' => true, 'source' => 'percentage']);

    ffConsole($this->token, 'PATCH', "/feature-flags/{$id}", ['rules' => [['target_kind' => 'percentage', 'target_value' => '0']]])->assertOk();
    expect(ffEval(FF_LIMA)['tablero.nuevo'])->toBe(['enabled' => true, 'source' => 'default']);
    ffConsole($this->token, 'PATCH', "/feature-flags/{$id}", ['default_enabled' => false])->assertOk();
    expect(ffEval(FF_LIMA)['tablero.nuevo'])->toBe(['enabled' => false, 'source' => 'default']);
});

test('cada cambio de bandera queda auditado; archivar es una acción y deja de evaluarse', function (): void {
    $id = (string) ffConsole($this->token, 'POST', '/feature-flags', [
        'code' => 'asistente.nuevo', 'name' => 'Nuevo asistente', 'default_enabled' => true,
    ])->assertCreated()->json('data.id');
    ffConsole($this->token, 'PATCH', "/feature-flags/{$id}", ['name' => 'Asistente renovado'])->assertOk()->assertJsonPath('data.name', 'Asistente renovado');
    ffConsole($this->token, 'POST', "/feature-flags/{$id}/tenants", ['tenant_id' => FF_LIMA, 'enabled' => false, 'reason' => 'Lo pidió el cliente'])->assertOk();
    ffConsole($this->token, 'POST', "/feature-flags/{$id}/tenants/".FF_LIMA.'/remove')->assertOk();

    $archive = ffConsole($this->token, 'POST', "/feature-flags/{$id}/archive");
    $this->assertMatchesOpenApi($archive, 'post', '/platform/feature-flags/{id}/archive', 200);
    $archive->assertJsonPath('data.status', 'archived')->assertJsonPath('data.allowed_actions', ['restore']);
    expect(ffEval(FF_LIMA))->not->toHaveKey('asistente.nuevo');
    ffApp($this->lima, FF_LIMA, 'GET', '/__ff-asistente')->assertStatus(403);
    $again = ffConsole($this->token, 'POST', "/feature-flags/{$id}/archive");
    $this->assertMatchesOpenApi($again, 'post', '/platform/feature-flags/{id}/archive', 409);
    $again->assertJsonPath('error.code', 'invalid_transition');
    ffConsole($this->token, 'POST', "/feature-flags/{$id}/tenants", ['tenant_id' => FF_LIMA, 'enabled' => true])->assertStatus(422);
    $restore = ffConsole($this->token, 'POST', "/feature-flags/{$id}/restore");
    $this->assertMatchesOpenApi($restore, 'post', '/platform/feature-flags/{id}/restore', 200);
    ffApp($this->lima, FF_LIMA, 'GET', '/__ff-asistente')->assertOk();

    // El estado no se edita por PATCH (regla 3).
    ffConsole($this->token, 'PATCH', "/feature-flags/{$id}", ['status' => 'archived'])->assertOk()->assertJsonPath('data.status', 'active');

    $actions = DB::table('platform_audit_events')->where('entity_type', 'feature_flag')->where('entity_id', $id)->orderBy('occurred_at')->pluck('action')->all();
    expect($actions)->toBe([
        'feature_flag.created', 'feature_flag.updated', 'feature_flag.tenant_set', 'feature_flag.tenant_removed',
        'feature_flag.archived', 'feature_flag.restored', 'feature_flag.updated',
    ]);
    $tenantSet = DB::table('platform_audit_events')->where('action', 'feature_flag.tenant_set')->where('entity_id', $id)->first(['tenant_id', 'after_data']);
    expect($tenantSet->tenant_id)->toBe(FF_LIMA)
        ->and(json_decode((string) $tenantSet->after_data, true))->toMatchArray(['enabled' => false, 'reason' => 'Lo pidió el cliente']);

    $show = ffConsole($this->token, 'GET', "/feature-flags/{$id}");
    $this->assertMatchesOpenApi($show, 'get', '/platform/feature-flags/{id}', 200);
});

test('validación y permisos de la consola sobre banderas', function (): void {
    $invalid = ffConsole($this->token, 'POST', '/feature-flags', [
        'code' => 'x.y', 'name' => 'Regla mala', 'rules' => [['target_kind' => 'plan', 'target_value' => 'no-existe']],
    ]);
    $this->assertMatchesOpenApi($invalid, 'post', '/platform/feature-flags', 422);
    ffConsole($this->token, 'POST', '/feature-flags', ['code' => 'Con Espacio', 'name' => 'Mal código'])->assertStatus(422);
    ffConsole($this->token, 'POST', '/feature-flags', ['code' => 'x.y', 'name' => 'Porcentaje', 'rules' => [['target_kind' => 'percentage', 'target_value' => '101']]])->assertStatus(422);
    ffConsole($this->token, 'POST', '/feature-flags', ['code' => 'x.y', 'name' => 'Válida'])->assertCreated();
    ffConsole($this->token, 'POST', '/feature-flags', ['code' => 'x.y', 'name' => 'Repetida'])->assertStatus(422);
    ffConsole($this->token, 'PATCH', '/tenants/'.FF_LIMA, ['beta_groups' => ['Con Espacio']])->assertStatus(422);
    $this->assertMatchesOpenApi(ffConsole($this->token, 'GET', '/feature-flags/'.Str::uuid()), 'get', '/platform/feature-flags/{id}', 404);

    // Solo lectura ve, pero no crea ni cambia.
    ffConsoleUser('lectura-ff@gestia.test', 'readonly');
    $readonly = ffEnrolled('lectura-ff@gestia.test');
    $this->assertMatchesOpenApi(ffConsole($readonly, 'GET', '/feature-flags'), 'get', '/platform/feature-flags', 403);
    $denied = ffConsole($readonly, 'POST', '/feature-flags', ['code' => 'z.z', 'name' => 'No']);
    $this->assertMatchesOpenApi($denied, 'post', '/platform/feature-flags', 403);
    $this->assertMatchesOpenApi(ffConsole(null, 'GET', '/feature-flags'), 'get', '/platform/feature-flags', 401);
});
