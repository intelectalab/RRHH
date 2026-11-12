<?php

/**
 * SAS-6 · Límites y consumo: el consumo se mide frente a tenant_limits (lo que fija la suscripción), el
 * servidor bloquea nuevas altas y subidas al llegar al tope con un mensaje que explica cómo ampliarlo
 * (RN-SAS-02, RN-SAS-03), avisa una sola vez al acercarse y al llegar, el almacenamiento se actualiza con
 * cada carga y borrado, y la consola ve el consumo actual e histórico y lo compara con otro plan.
 */

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Organization\Database\Factories\EmploymentCostDistributionFactory;
use App\Modules\Organization\Infrastructure\Models\LegalEntity;
use App\Modules\Platform\Application\Subscriptions\Entitlements;
use App\Modules\Platform\Infrastructure\Models\PlatformUser;
use App\Shared\Exceptions\PlanLimitReachedException;
use App\Shared\Files\FileService;
use App\Shared\Outbox\DomainEventTypes;
use App\Shared\Permissions\ModuleGate;
use App\Shared\Permissions\PermissionResolver;
use App\Shared\Security\Totp;
use App\Shared\Tenancy\TenancyScope;
use App\Shared\Usage\UsageLimits;
use App\Shared\Usage\UsageMetrics;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(DatabaseTransactions::class);

const UL_TENANT = 'cccccccc-5a56-4000-8000-00000000000c';
const UL_OTHER = 'cccccccc-5a56-4000-8000-00000000000d';
const UL_PASSWORD = 'Consola-Segura-2026!';

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requiere PostgreSQL.');
    }
    TenancyScope::clear();
    DB::table('tenants')->insertOrIgnore([
        ['id' => UL_TENANT, 'slug' => 'ul-sur', 'name' => 'Textiles del Sur', 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN'],
        ['id' => UL_OTHER, 'slug' => 'ul-otra', 'name' => 'Otra Organización', 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN'],
    ]);
    DB::select('SELECT seed_tenant_defaults(?)', [UL_TENANT]);
    DB::select('SELECT seed_tenant_defaults(?)', [UL_OTHER]);
    $this->admin = ulUser(UL_TENANT, 'admin_empresa');

    $this->owner = ulConsoleUser('duena-ul@gestia.test', 'superadmin');
    $this->token = ulEnrolled('duena-ul@gestia.test');

    Storage::fake('s3');
    config(['files.disk' => 's3']);
});

afterEach(fn () => TenancyScope::clear());

function ulUser(string $tenant, string $systemRole): User
{
    return TenancyScope::runAs($tenant, function () use ($systemRole): User {
        $user = User::factory()->create();
        $user->roleAssignments()->create(['role_id' => Role::query()->where('system_role_code', $systemRole)->firstOrFail()->id]);

        return $user;
    });
}

function ulLimit(string $tenant, string $code, ?string $value): void
{
    TenancyScope::runAs($tenant, fn () => DB::table('tenant_limits')->upsert([[
        'tenant_id' => $tenant, 'limit_code' => $code, 'limit_value' => $value, 'is_unlimited' => $value === null, 'source' => 'plan',
    ]], ['tenant_id', 'limit_code'], ['limit_value', 'is_unlimited', 'source']));
}

function ulEmployees(string $tenant, int $count): void
{
    TenancyScope::runAs($tenant, function () use ($count): void {
        $entity = LegalEntity::factory()->create()->id;
        for ($i = 0; $i < $count; $i++) {
            EmploymentCostDistributionFactory::employment($entity);
        }
    });
}

function ulConsoleUser(string $email, string $role): PlatformUser
{
    return PlatformUser::create([
        'email' => $email, 'full_name' => 'Consola '.$role, 'role' => $role,
        'status' => 'active', 'password_hash' => Hash::make(UL_PASSWORD),
    ]);
}

/**
 * @param  array<string, mixed>  $body
 */
function ulConsole(?string $token, string $method, string $path, array $body = [])
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

function ulEnrolled(string $email): string
{
    $login = ulConsole(null, 'POST', '/auth/login', ['email' => $email, 'password' => UL_PASSWORD])->assertOk();
    $token = (string) $login->getCookie('gestia_console_session', false)?->getValue();
    $secret = (string) ulConsole($token, 'POST', '/auth/mfa/totp')->assertOk()->json('data.secret');
    ulConsole($token, 'POST', '/auth/mfa/totp/confirm', ['code' => Totp::at(Totp::base32Decode($secret), Totp::currentStep())])->assertOk();

    return $token;
}

/**
 * @param  array<string, mixed>  $body
 */
function ulApp(User $user, string $method, string $path, array $body = [], string $tenant = UL_TENANT)
{
    TenancyScope::clear();
    app('auth')->forgetGuards();
    app(ModuleGate::class)->forget();
    app(PermissionResolver::class)->forget();

    $response = test()->flushHeaders()->actingAs($user, 'ayni')
        ->withHeaders(['X-Tenant-Id' => $tenant, 'Idempotency-Key' => (string) Str::uuid()])
        ->json($method, '/api/v1'.$path, $body);
    TenancyScope::clear();

    return $response;
}

/**
 * @return list<string>
 */
function ulEvents(string $tenant, string $type): array
{
    return TenancyScope::runAs($tenant, fn () => DB::table('outbox_events')->where('event_type', $type)->orderBy('occurred_at')
        ->pluck('payload')->map(fn ($p): string => (string) json_decode((string) $p, true)['metric'])->all());
}

test('al tope de colaboradores no se permiten nuevas altas y el mensaje explica cómo ampliarlo; lo existente sigue operativo', function (): void {
    app()->setLocale('es');
    ulLimit(UL_TENANT, 'employees', '3');
    ulEmployees(UL_TENANT, 3);

    TenancyScope::runAs(UL_TENANT, function (): void {
        $usage = app(UsageLimits::class);
        expect($usage->current(UsageMetrics::EMPLOYEES))->toBe(300);
        try {
            $usage->assertCanAdd(UsageMetrics::EMPLOYEES);
            $this->fail('Debió bloquear la nueva alta.');
        } catch (PlanLimitReachedException $e) {
            $body = json_decode((string) $e->render()->getContent(), true);
            expect($e->getStatusCode())->toBe(403)
                ->and($body['error']['code'])->toBe('plan_limit_reached')
                ->and($body['error']['meta'])->toMatchArray(['limit' => 'employees', 'current' => 3, 'max' => 3])
                ->and($body['error']['message'])->toContain('3 colaboradores')->toContain('amplía el plan');
        }
    });

    // El cliente ve el tope alcanzado; la otra organización no ve nada de esta.
    $usage = ulApp($this->admin, 'GET', '/usage');
    $this->assertMatchesOpenApi($usage, 'get', '/usage', 200);
    $employees = collect($usage->json('data.metrics'))->firstWhere('metric', 'employees');
    expect($employees)->toMatchArray(['current' => '3', 'limit' => '3', 'status' => 'reached', 'percent' => 100, 'label' => 'Colaboradores activos']);
    $other = ulApp(ulUser(UL_OTHER, 'admin_empresa'), 'GET', '/usage', [], UL_OTHER);
    expect(collect($other->json('data.metrics'))->firstWhere('metric', 'employees'))->toMatchArray(['current' => '0', 'has_limit' => false]);

    // Sin permiso para ver la organización: 403.
    $denied = ulApp(ulUser(UL_TENANT, 'empleado'), 'GET', '/usage');
    $this->assertMatchesOpenApi($denied, 'get', '/usage', 403);
});

test('el servidor aplica los límites de empresas y administradores con su mensaje', function (): void {
    ulLimit(UL_TENANT, 'legal_entities', '1');
    TenancyScope::runAs(UL_TENANT, fn () => LegalEntity::factory()->create(['status' => 'active']));
    $blocked = ulApp($this->admin, 'POST', '/legal-entities', [
        'name' => 'Segunda Empresa SAC', 'tax_id' => '20666666666', 'country' => 'PE', 'currency' => 'PEN',
        'timezone' => 'America/Lima', 'payroll_regime' => 'pe_general',
    ]);
    $this->assertMatchesOpenApi($blocked, 'post', '/legal-entities', 403);
    $blocked->assertJsonPath('error.code', 'plan_limit_reached')->assertJsonPath('error.meta.max', 1);
    expect((string) $blocked->json('error.message'))->toContain('1 empresas activas');

    // Un solo administrador permitido: dar el rol a otra persona se rechaza; un rol común, no.
    ulLimit(UL_TENANT, 'admins', '1');
    $adminRole = TenancyScope::runAs(UL_TENANT, fn () => (string) Role::query()->where('system_role_code', 'admin_empresa')->value('id'));
    $employeeRole = TenancyScope::runAs(UL_TENANT, fn () => (string) Role::query()->where('system_role_code', 'empleado')->value('id'));
    $second = ulApp($this->admin, 'POST', '/users', ['login' => 'segundoadmin', 'display_name' => 'Segundo Admin', 'role_ids' => [$adminRole]]);
    $this->assertMatchesOpenApi($second, 'post', '/users', 403);
    $second->assertJsonPath('error.code', 'plan_limit_reached')->assertJsonPath('error.meta.limit', 'admins');
    $plain = ulApp($this->admin, 'POST', '/users', ['login' => 'operaria', 'display_name' => 'Operaria', 'role_ids' => [$employeeRole]]);
    $plain->assertCreated();
    $promote = ulApp($this->admin, 'PATCH', '/users/'.$plain->json('data.id'), ['role_ids' => [$employeeRole, $adminRole]]);
    $promote->assertStatus(403)->assertJsonPath('error.meta.limit', 'admins');
});

test('el consumo de almacenamiento se actualiza con cada carga y borrado; con el plan lleno se rechaza la subida y la lectura sigue', function (): void {
    app()->setLocale('es');
    ulLimit(UL_TENANT, 'storage_mb', '1');
    $files = app(FileService::class);
    $first = $files->upload(str_repeat('a', 600 * 1024), 'informe.pdf', 'application/pdf', UL_TENANT);
    $stored = fn (): ?string => TenancyScope::runAs(UL_TENANT, fn () => DB::table('tenant_usage_counters')
        ->where('metric_code', 'storage_mb')->where('period_start', now()->toDateString())->value('value'));
    expect($stored())->toBe('0.59');

    try {
        $files->upload(str_repeat('b', 600 * 1024), 'otro.pdf', 'application/pdf', UL_TENANT);
        $this->fail('Debió rechazar la subida.');
    } catch (PlanLimitReachedException $e) {
        expect($e->getMeta()['limit'])->toBe('storage_mb')
            ->and($e->getMessage())->toContain('1 MB')->toContain('descargando');
    }
    // Lo guardado se sigue leyendo.
    expect($files->contentsForDownload($first->id, UL_TENANT)['contents'] ?? null)->toBe(str_repeat('a', 600 * 1024));

    $files->delete($first->id, UL_TENANT);
    expect($stored())->toBe('0.00');
    $files->upload(str_repeat('b', 600 * 1024), 'otro.pdf', 'application/pdf', UL_TENANT);
    expect($stored())->toBe('0.59');
});

test('avisa una sola vez al acercarse al tope y al llegar a él, y vuelve a avisar si el consumo baja y sube', function (): void {
    ulLimit(UL_TENANT, 'employees', '5');
    ulEmployees(UL_TENANT, 3);
    $record = fn (): array => TenancyScope::runAs(UL_TENANT, fn () => app(UsageLimits::class)->record(UsageMetrics::EMPLOYEES));

    expect($record()['status'])->toBe('ok');
    expect(ulEvents(UL_TENANT, DomainEventTypes::LIMIT_WARNING))->toBe([]);

    ulEmployees(UL_TENANT, 1); // 4 de 5 = 80 %: parámetro limites.porcentaje_alerta por defecto.
    expect($record()['status'])->toBe('warning');
    $record();
    expect(ulEvents(UL_TENANT, DomainEventTypes::LIMIT_WARNING))->toBe(['employees']);

    ulEmployees(UL_TENANT, 1);
    expect($record())->toMatchArray(['status' => 'reached', 'percent' => 100]);
    $record();
    expect(ulEvents(UL_TENANT, DomainEventTypes::LIMIT_REACHED))->toBe(['employees']);

    // Se amplía el límite: se limpian las marcas; si vuelve a subir, vuelve a avisar.
    ulLimit(UL_TENANT, 'employees', '50');
    expect($record()['status'])->toBe('ok');
    expect(TenancyScope::runAs(UL_TENANT, fn () => DB::table('tenant_limits')->where('limit_code', 'employees')->first(['warned_at', 'reached_at'])))
        ->toMatchArray(['warned_at' => null, 'reached_at' => null]);
    ulLimit(UL_TENANT, 'employees', '5');
    $record();
    expect(ulEvents(UL_TENANT, DomainEventTypes::LIMIT_REACHED))->toBe(['employees', 'employees']);
});

test('la consola ve el consumo actual e histórico por organización y lo compara con otro plan', function (): void {
    ulLimit(UL_TENANT, 'employees', '4');
    ulEmployees(UL_TENANT, 2);
    // Foto de ayer guardada por la tarea diaria anterior.
    TenancyScope::runAs(UL_TENANT, fn () => DB::table('tenant_usage_counters')->insert([
        'tenant_id' => UL_TENANT, 'metric_code' => 'employees', 'period_start' => now()->subDay()->toDateString(), 'value' => '1', 'measured_at' => now()->subDay(),
    ]));
    $this->artisan('usage:snapshot')->assertSuccessful();

    $usage = ulConsole($this->token, 'GET', '/tenants/'.UL_TENANT.'/usage');
    $this->assertMatchesOpenApi($usage, 'get', '/platform/tenants/{id}/usage', 200);
    expect(collect($usage->json('data.metrics'))->firstWhere('metric', 'employees'))->toMatchArray(['current' => '2', 'limit' => '4', 'percent' => 50]);
    $history = collect($usage->json('data.history'))->where('metric', 'employees')->values()->all();
    expect($history)->toBe([
        ['metric' => 'employees', 'period_start' => now()->subDay()->toDateString(), 'value' => '1'],
        ['metric' => 'employees', 'period_start' => now()->toDateString(), 'value' => '2'],
    ]);
    // Solo cifras (RN-SAS-12).
    expect(json_encode($usage->json()))->not->toContain('first_names');

    $bad = ulConsole($this->token, 'GET', '/tenants/'.UL_TENANT.'/usage?from=2026-12-31&to=2026-01-01');
    $this->assertMatchesOpenApi($bad, 'get', '/platform/tenants/{id}/usage', 400);
    $missing = ulConsole($this->token, 'GET', '/tenants/'.Str::uuid().'/usage');
    $this->assertMatchesOpenApi($missing, 'get', '/platform/tenants/{id}/usage', 404);

    // Comparación con un plan de tope menor: las altas quedarían bloqueadas.
    $plan = (string) DB::table('plans')->where('code', 'esencial')->whereNull('superseded_by_plan_id')->value('id');
    DB::table('plan_limits')->upsert([['plan_id' => $plan, 'limit_code' => 'employees', 'limit_value' => '1', 'is_unlimited' => false]], ['plan_id', 'limit_code'], ['limit_value', 'is_unlimited']);
    $subscription = (string) DB::table('subscriptions')->insertGetId([
        'tenant_id' => UL_TENANT, 'plan_id' => $plan, 'status' => 'active', 'starts_on' => now()->toDateString(),
    ]);
    $fit = ulConsole($this->token, 'GET', '/subscriptions/'.$subscription.'/plan-fit?plan_id='.$plan);
    $this->assertMatchesOpenApi($fit, 'get', '/platform/subscriptions/{id}/plan-fit', 200);
    expect(collect($fit->json('data'))->firstWhere('metric', 'employees'))->toMatchArray(['current' => '2', 'limit' => '1', 'over' => true]);
    expect($fit->json('meta.over'))->toBeGreaterThanOrEqual(1);
    $this->assertMatchesOpenApi(ulConsole($this->token, 'GET', '/subscriptions/'.$subscription.'/plan-fit'), 'get', '/platform/subscriptions/{id}/plan-fit', 400);

    // Soporte no ve suscripciones ni consumo.
    ulConsoleUser('soporte-ul@gestia.test', 'support');
    $support = ulEnrolled('soporte-ul@gestia.test');
    $this->assertMatchesOpenApi(ulConsole($support, 'GET', '/tenants/'.UL_TENANT.'/usage'), 'get', '/platform/tenants/{id}/usage', 403);
    $this->assertMatchesOpenApi(ulConsole(null, 'GET', '/tenants/'.UL_TENANT.'/usage'), 'get', '/platform/tenants/{id}/usage', 401);
});

test('bajar de plan con consumo por encima del nuevo tope se permite, pero bloquea las altas hasta regularizar', function (): void {
    ulEmployees(UL_TENANT, 3);
    $plan = (string) DB::table('plans')->where('code', 'esencial')->whereNull('superseded_by_plan_id')->value('id');
    DB::table('plan_limits')->upsert([['plan_id' => $plan, 'limit_code' => 'employees', 'limit_value' => '2', 'is_unlimited' => false]], ['plan_id', 'limit_code'], ['limit_value', 'is_unlimited']);
    $subscription = (string) DB::table('subscriptions')->insertGetId([
        'tenant_id' => UL_TENANT, 'plan_id' => $plan, 'status' => 'active', 'starts_on' => now()->toDateString(),
    ]);
    app(Entitlements::class)->sync($subscription, $this->owner);

    expect(TenancyScope::runAs(UL_TENANT, fn () => DB::table('tenant_limits')->where('limit_code', 'employees')->value('reached_at')))->not->toBeNull();
    expect(ulEvents(UL_TENANT, DomainEventTypes::LIMIT_REACHED))->toBe(['employees']);
    TenancyScope::runAs(UL_TENANT, fn () => expect(fn () => app(UsageLimits::class)->assertCanAdd(UsageMetrics::EMPLOYEES))
        ->toThrow(PlanLimitReachedException::class));
});
