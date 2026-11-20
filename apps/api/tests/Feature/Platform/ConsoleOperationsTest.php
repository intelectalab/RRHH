<?php

/**
 * SAS-8 · SAS-9 · SAS-12 · SAS-13 · Soporte auditado, monitoreo, copias de seguridad, configuración global,
 * avisos de mantenimiento y registros de la consola.
 */

use App\Modules\Identity\Application\SessionManager;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Platform\Infrastructure\Models\PlatformUser;
use App\Shared\Integrations\Fake\FakeBackupStorageAdapter;
use App\Shared\Integrations\IntegrationManager;
use App\Shared\Permissions\ModuleGate;
use App\Shared\Permissions\PermissionResolver;
use App\Shared\Security\SupportContext;
use App\Shared\Security\Totp;
use App\Shared\Settings\ParameterResolver;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Support\IdentityFixtures;

uses(DatabaseTransactions::class);

const SX_TENANT = 'cccccccc-a1a1-4000-8000-0000000000b8';
const SX_PASSWORD = 'Consola-Segura-2026!';

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requiere PostgreSQL.');
    }
    TenancyScope::clear();
    DB::table('tenants')->insertOrIgnore(['id' => SX_TENANT, 'slug' => 'sx-org', 'name' => 'Comercial Soporte', 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN']);
    DB::select('SELECT seed_tenant_defaults(?)', [SX_TENANT]);
    TenancyScope::runAs(SX_TENANT, fn () => IdentityFixtures::enableAllModules());
    [$this->admin, $this->hr] = TenancyScope::runAs(SX_TENANT, function (): array {
        $admin = User::factory()->create(['display_name' => 'Admin Soporte']);
        $admin->roleAssignments()->create(['role_id' => Role::query()->where('system_role_code', 'admin_empresa')->value('id')]);
        $hr = User::factory()->create(['display_name' => 'Ana RRHH']);
        $hr->roleAssignments()->create(['role_id' => Role::query()->where('system_role_code', 'rrhh')->value('id')]);

        return [$admin, $hr];
    });

    foreach (['superadmin' => 'duena-ops@gestia.test', 'support' => 'soporte-ops@gestia.test', 'readonly' => 'lectura-ops@gestia.test'] as $role => $email) {
        PlatformUser::create(['email' => $email, 'full_name' => 'Consola '.$role, 'role' => $role, 'status' => 'active', 'password_hash' => Hash::make(SX_PASSWORD)]);
    }
    PlatformUser::create(['email' => 'segunda-ops@gestia.test', 'full_name' => 'Segunda dueña', 'role' => 'superadmin', 'status' => 'active', 'password_hash' => Hash::make(SX_PASSWORD)]);
    $this->owner = sxEnrolled('duena-ops@gestia.test');
    $this->owner2 = sxEnrolled('segunda-ops@gestia.test');
    $this->support = sxEnrolled('soporte-ops@gestia.test');
    $this->readonly = sxEnrolled('lectura-ops@gestia.test');
});

afterEach(function (): void {
    app(SupportContext::class)->clear();
    TenancyScope::clear();
});

/**
 * @param  array<string, mixed>  $body
 */
function sxConsole(?string $token, string $method, string $path, array $body = [])
{
    TenancyScope::clear();
    app('auth')->forgetGuards();
    $request = test()->flushHeaders();
    $headers = ['Idempotency-Key' => (string) Str::uuid(), 'Origin' => 'http://consola.localhost:3000'];
    if ($token !== null) {
        $request = $request->withCredentials()->withUnencryptedCookie('gestia_console_session', $token);
        $headers['X-XSRF-TOKEN'] = hash_hmac('sha256', 'console-xsrf|'.$token, (string) config('app.key'));
    } else {
        $request = $request->withUnencryptedCookie('gestia_console_session', '');
    }

    return $request->withHeaders($headers)->json($method, '/api/v1/platform'.$path, $body);
}

function sxEnrolled(string $email): string
{
    $login = sxConsole(null, 'POST', '/auth/login', ['email' => $email, 'password' => SX_PASSWORD])->assertOk();
    $token = (string) $login->getCookie('gestia_console_session', false)?->getValue();
    $secret = (string) sxConsole($token, 'POST', '/auth/mfa/totp')->assertOk()->json('data.secret');
    sxConsole($token, 'POST', '/auth/mfa/totp/confirm', ['code' => Totp::at(Totp::base32Decode($secret), Totp::currentStep())])->assertOk();

    return $token;
}

/**
 * @param  array<string, mixed>  $body
 */
function sxTenant(User $user, string $method, string $path, array $body = [])
{
    TenancyScope::clear();
    app('auth')->forgetGuards();
    app(ModuleGate::class)->forget();
    app(PermissionResolver::class)->forget();
    app(SupportContext::class)->clear();
    $response = test()->flushHeaders()->actingAs($user, 'ayni')
        ->withHeaders(['X-Tenant-Id' => SX_TENANT, 'Idempotency-Key' => (string) Str::uuid()])->json($method, '/api/v1'.$path, $body);
    TenancyScope::clear();

    return $response;
}

/** Petición a la organización con la cookie de sesión (como el navegador tras el enlace de soporte). */
function sxSession(string $sessionToken, string $method, string $path, array $body = [])
{
    TenancyScope::clear();
    app('auth')->forgetGuards();
    app(ModuleGate::class)->forget();
    app(PermissionResolver::class)->forget();
    $response = test()->flushHeaders()->withCredentials()
        ->withUnencryptedCookie((string) config('identity.session_cookie'), $sessionToken)
        ->withHeaders(['X-Tenant-Id' => SX_TENANT, 'Idempotency-Key' => (string) Str::uuid(),
            'X-XSRF-TOKEN' => SessionManager::xsrfFor($sessionToken)])
        ->json($method, '/api/v1'.$path, $body);
    TenancyScope::clear();

    return $response;
}

/** Abre la sesión de soporte con el enlace y devuelve la cookie de sesión. */
function sxEnter(string $entryUrl): string
{
    $token = Str::after($entryUrl, '#t=');
    TenancyScope::clear();
    app('auth')->forgetGuards();
    $response = test()->flushHeaders()->withHeaders(['X-Tenant-Id' => SX_TENANT, 'Idempotency-Key' => (string) Str::uuid()])
        ->postJson('/api/v1/auth/support/enter', ['token' => $token]);
    test()->assertMatchesOpenApi($response, 'post', '/auth/support/enter', 200);
    TenancyScope::clear();

    return (string) $response->getCookie((string) config('identity.session_cookie'), false)?->getValue();
}

test('SAS-8 · soporte entra con motivo y enlace de un solo uso; sin autorización no ve sueldos y todo queda marcado «soporte»', function (): void {
    $users = sxConsole($this->support, 'GET', '/tenants/'.SX_TENANT.'/users');
    $this->assertMatchesOpenApi($users, 'get', '/platform/tenants/{id}/users', 200);
    expect(collect($users->json('data'))->pluck('display_name'))->toContain('Ana RRHH');

    $bad = sxConsole($this->support, 'POST', '/support-sessions', ['tenant_id' => SX_TENANT, 'target_user_id' => $this->hr->id, 'reason' => 'corto']);
    $this->assertMatchesOpenApi($bad, 'post', '/platform/support-sessions', 422);

    $created = sxConsole($this->support, 'POST', '/support-sessions', [
        'tenant_id' => SX_TENANT, 'target_user_id' => $this->hr->id, 'reason' => 'Ticket 4512: no carga el legajo', 'ticket_reference' => 'T-4512', 'duration_minutes' => 30,
    ]);
    $this->assertMatchesOpenApi($created, 'post', '/platform/support-sessions', 201);
    expect($created->json('data.status'))->toBe('pending')
        ->and($created->json('data.allow_sensitive'))->toBeFalse()
        ->and($created->json('data.entry_url'))->toStartWith('http://sx-org.')
        ->and(DB::table('support_sessions')->where('id', $created->json('data.id'))->value('entry_token_hash'))->not->toContain(Str::after((string) $created->json('data.entry_url'), '#t='));
    $id = (string) $created->json('data.id');

    $cookie = sxEnter((string) $created->json('data.entry_url'));
    // El enlace sirve una sola vez.
    $again = $this->flushHeaders()->withHeaders(['X-Tenant-Id' => SX_TENANT])->postJson('/api/v1/auth/support/enter', ['token' => Str::after((string) $created->json('data.entry_url'), '#t=')]);
    $this->assertMatchesOpenApi($again, 'post', '/auth/support/enter', 422);

    $me = sxSession($cookie, 'GET', '/me')->assertOk();
    expect($me->json('data.support_session.agent_name'))->toBe('Consola support')
        ->and($me->json('data.field_permissions'))->not->toContain('people.employments.salary.view');

    // Una acción del soporte queda en la auditoría de la organización como «platform_support».
    sxSession($cookie, 'PATCH', '/me/preferences', ['locale' => 'en'])->assertOk();
    $kinds = TenancyScope::runAs(SX_TENANT, fn () => DB::table('audit_events')->where('actor_kind', 'platform_support')->pluck('action')->all());
    expect($kinds)->toContain('support.session_started');

    $show = sxConsole($this->support, 'GET', '/support-sessions/'.$id);
    $this->assertMatchesOpenApi($show, 'get', '/platform/support-sessions/{id}', 200);
    expect($show->json('data.status'))->toBe('active')->and(collect($show->json('data.actions'))->pluck('action'))->toContain('support.session_started');

    // El administrador del cliente ve la sesión en su configuración de soporte.
    $access = sxTenant($this->admin, 'GET', '/support-access');
    $this->assertMatchesOpenApi($access, 'get', '/support-access', 200);
    expect($access->json('data.sessions.0.reason'))->toBe('Ticket 4512: no carga el legajo');

    $ended = sxConsole($this->support, 'POST', '/support-sessions/'.$id.'/end', ['reason' => 'Resuelto']);
    $this->assertMatchesOpenApi($ended, 'post', '/platform/support-sessions/{id}/end', 200);
    $this->assertMatchesOpenApi(sxConsole($this->support, 'POST', '/support-sessions/'.$id.'/end'), 'post', '/platform/support-sessions/{id}/end', 409);
    sxSession($cookie, 'GET', '/me')->assertUnauthorized();
    expect(TenancyScope::runAs(SX_TENANT, fn () => DB::table('audit_events')->where('action', 'support.session_ended')->value('actor_kind')))->toBe('platform_support');
});

test('SAS-8 · la sesión de soporte caduca sola, y con autorización «full» del cliente ve campos sensibles', function (): void {
    $grant = sxTenant($this->admin, 'POST', '/support-access/grants', ['reason' => 'Revisión de planilla', 'access_level' => 'full', 'expires_at' => now()->addDay()->toIso8601String()]);
    $this->assertMatchesOpenApi($grant, 'post', '/support-access/grants', 201);

    $created = sxConsole($this->support, 'POST', '/support-sessions', ['tenant_id' => SX_TENANT, 'target_user_id' => $this->hr->id, 'reason' => 'Revisión de cálculo de sueldos', 'duration_minutes' => 10])->assertCreated();
    expect($created->json('data.allow_sensitive'))->toBeTrue();
    $revoke = sxTenant($this->admin, 'POST', '/support-access/grants/'.$grant->json('data.id').'/revoke');
    $this->assertMatchesOpenApi($revoke, 'post', '/support-access/grants/{id}/revoke', 200);
    $this->assertMatchesOpenApi(sxTenant($this->admin, 'POST', '/support-access/grants/'.$grant->json('data.id').'/revoke'), 'post', '/support-access/grants/{id}/revoke', 409);
    $cookie = sxEnter((string) $created->json('data.entry_url'));
    expect(sxSession($cookie, 'GET', '/me')->json('data.field_permissions'))->toContain('people.employments.salary.view');

    $this->travel(11)->minutes();
    sxSession($cookie, 'GET', '/me')->assertUnauthorized();
    expect(sxConsole($this->support, 'GET', '/support-sessions?status=expired')->json('data.0.status'))->toBe('expired');
    $this->travelBack();
});

test('SAS-8 · el cliente desactiva por completo el acceso de soporte y la consola no puede abrir sesiones', function (): void {
    $pending = sxConsole($this->support, 'POST', '/support-sessions', ['tenant_id' => SX_TENANT, 'target_user_id' => $this->hr->id, 'reason' => 'Revisión previa al cambio'])->assertCreated();

    $off = sxTenant($this->admin, 'PATCH', '/support-access', ['enabled' => false, 'reason' => 'Política interna']);
    $this->assertMatchesOpenApi($off, 'patch', '/support-access', 200);
    expect($off->json('data.enabled'))->toBeFalse()->and($off->json('data.sessions.0.status'))->toBe('ended');

    $denied = sxConsole($this->support, 'POST', '/support-sessions', ['tenant_id' => SX_TENANT, 'target_user_id' => $this->hr->id, 'reason' => 'Ticket nuevo de la organización']);
    $this->assertMatchesOpenApi($denied, 'post', '/platform/support-sessions', 409);
    expect($denied->json('error.code'))->toBe('support_access_disabled');
    $this->assertMatchesOpenApi(sxTenant($this->hr, 'PATCH', '/support-access', ['enabled' => true, 'reason' => 'Sin permiso']), 'patch', '/support-access', 403);
    // El enlace pendiente ya no abre sesión.
    $late = $this->flushHeaders()->withHeaders(['X-Tenant-Id' => SX_TENANT])->postJson('/api/v1/auth/support/enter', ['token' => Str::after((string) $pending->json('data.entry_url'), '#t=')]);
    expect($late->status())->toBe(422);
});

test('SAS-9 · el monitoreo muestra componentes, errores por organización y reintenta trabajos y eventos fallidos', function (): void {
    $eventId = (string) Str::uuid();
    TenancyScope::runAs(SX_TENANT, fn () => DB::table('outbox_events')->insert([
        'id' => $eventId, 'tenant_id' => SX_TENANT, 'event_type' => 'employee.hired.v1', 'aggregate_type' => 'employment',
        'aggregate_id' => (string) Str::uuid(), 'payload' => '{}', 'status' => 'dead', 'attempts' => 5, 'last_error' => "Timeout\nstack",
    ]));
    $jobUuid = (string) Str::uuid();
    DB::table('failed_jobs')->insert(['uuid' => $jobUuid, 'connection' => 'redis', 'queue' => 'default',
        'payload' => json_encode(['displayName' => 'App\\Jobs\\Demo', 'tenant_id' => SX_TENANT]), 'exception' => "RuntimeException: caída\n#0 trace"]);

    $health = sxConsole($this->readonly, 'GET', '/health?tenant_id='.SX_TENANT);
    $this->assertMatchesOpenApi($health, 'get', '/platform/health', 200);
    expect(collect($health->json('data.components'))->pluck('code'))->toContain('database')
        ->and($health->json('data.tenants.0.errors.outbox_events'))->toBe(1)
        ->and($health->json('data.tenants.0.errors.failed_jobs'))->toBe(1);

    $jobs = sxConsole($this->owner, 'GET', '/health/failed-jobs?tenant_id='.SX_TENANT);
    $this->assertMatchesOpenApi($jobs, 'get', '/platform/health/failed-jobs', 200);
    expect($jobs->json('data.0.error'))->toBe('RuntimeException: caída');

    $events = sxConsole($this->owner, 'GET', '/health/outbox-events');
    $this->assertMatchesOpenApi($events, 'get', '/platform/health/outbox-events', 200);
    expect($events->json('data.0'))->not->toHaveKey('payload');

    $this->assertMatchesOpenApi(sxConsole($this->readonly, 'POST', '/health/outbox-events/'.$eventId.'/retry'), 'post', '/platform/health/outbox-events/{id}/retry', 403);
    $retry = sxConsole($this->owner, 'POST', '/health/outbox-events/'.$eventId.'/retry');
    $this->assertMatchesOpenApi($retry, 'post', '/platform/health/outbox-events/{id}/retry', 200);
    $this->assertMatchesOpenApi(sxConsole($this->owner, 'POST', '/health/outbox-events/'.$eventId.'/retry'), 'post', '/platform/health/outbox-events/{id}/retry', 409);

    $job = sxConsole($this->owner, 'POST', '/health/failed-jobs/'.$jobUuid.'/retry');
    $this->assertMatchesOpenApi($job, 'post', '/platform/health/failed-jobs/{id}/retry', 200);
    expect(DB::table('failed_jobs')->where('uuid', $jobUuid)->exists())->toBeFalse()
        ->and(DB::table('platform_audit_events')->whereIn('action', ['health.job_retried', 'health.event_retried'])->count())->toBe(2);
});

test('SAS-12 · copia manual, restauración en aislado con doble aprobación y a producción solo tras verificar', function (): void {
    $backup = sxConsole($this->owner, 'POST', '/backups', ['coverage' => 'tenant', 'tenant_id' => SX_TENANT]);
    $this->assertMatchesOpenApi($backup, 'post', '/platform/backups', 201);
    expect($backup->json('data.status'))->toBe('completed')->and($backup->json('data.size_bytes'))->toBeGreaterThan(0);
    $id = (string) $backup->json('data.id');
    $this->assertMatchesOpenApi(sxConsole($this->support, 'POST', '/backups', ['coverage' => 'platform']), 'post', '/platform/backups', 403);

    $prod = sxConsole($this->owner, 'POST', '/backups/'.$id.'/restores', ['target' => 'production', 'reason' => 'Recuperar datos borrados por error']);
    $this->assertMatchesOpenApi($prod, 'post', '/platform/backups/{id}/restores', 409);
    expect($prod->json('error.code'))->toBe('sandbox_verification_required');

    $sandbox = sxConsole($this->owner, 'POST', '/backups/'.$id.'/restores', ['reason' => 'Verificar la copia antes de usarla']);
    $this->assertMatchesOpenApi($sandbox, 'post', '/platform/backups/{id}/restores', 201);
    $restoreId = (string) $sandbox->json('data.id');
    $same = sxConsole($this->owner, 'POST', '/backup-restores/'.$restoreId.'/approve');
    $this->assertMatchesOpenApi($same, 'post', '/platform/backup-restores/{id}/approve', 409);
    expect($same->json('error.code'))->toBe('same_approver');

    $approved = sxConsole($this->owner2, 'POST', '/backup-restores/'.$restoreId.'/approve');
    $this->assertMatchesOpenApi($approved, 'post', '/platform/backup-restores/{id}/approve', 200);
    expect($approved->json('data.status'))->toBe('completed')->and($approved->json('data.verification.verified'))->toBeTrue();
    $this->assertMatchesOpenApi(sxConsole($this->owner2, 'POST', '/backup-restores/'.$restoreId.'/cancel'), 'post', '/platform/backup-restores/{id}/cancel', 409);

    $prod = sxConsole($this->owner, 'POST', '/backups/'.$id.'/restores', ['target' => 'production', 'reason' => 'Recuperar datos borrados por error'])->assertCreated();
    sxConsole($this->owner2, 'POST', '/backup-restores/'.$prod->json('data.id').'/approve')->assertOk();
    expect(TenancyScope::runAs(SX_TENANT, fn () => DB::table('audit_events')->where('action', 'platform.backup_restored')->exists()))->toBeTrue();

    $list = sxConsole($this->readonly, 'GET', '/backups');
    expect($list->status())->toBe(403);
    $list = sxConsole($this->owner, 'GET', '/backups?tenant_id='.SX_TENANT);
    $this->assertMatchesOpenApi($list, 'get', '/platform/backups', 200);
    expect($list->json('data.0.verified_in_sandbox'))->toBeTrue();
    $this->assertMatchesOpenApi(sxConsole($this->owner, 'GET', '/backups/'.$id), 'get', '/platform/backups/{id}', 200);
    $this->assertMatchesOpenApi(sxConsole($this->owner, 'GET', '/backup-restores'), 'get', '/platform/backup-restores', 200);
    expect(DB::table('platform_audit_events')->where('action', 'backup.restore_approved')->count())->toBe(2);
});

test('SAS-12 · la copia diaria vence las antiguas y la prueba periódica de restauración deja evidencia', function (): void {
    /** @var FakeBackupStorageAdapter $fake */
    $fake = app(IntegrationManager::class)->backups();
    DB::table('backup_runs')->insert(['id' => (string) Str::uuid(), 'kind' => 'automatic', 'coverage' => 'platform', 'status' => 'completed',
        'storage_key' => 'old-key', 'checksum' => hash('sha256', 'old-key'), 'started_at' => now()->subDays(60), 'retention_until' => now()->subDays(30)->toDateString()]);

    $this->artisan('backups:run', ['--force' => true])->assertSuccessful();
    expect(DB::table('backup_runs')->where('storage_key', 'old-key')->value('status'))->toBe('expired')
        ->and($fake->purgedKeys())->toContain('old-key')
        ->and(DB::table('backup_restores')->where('kind', 'scheduled_test')->value('status'))->toBe('completed')
        ->and(DB::table('backup_restores')->where('kind', 'scheduled_test')->value('verification'))->toContain('checksum');
});

test('SAS-13 · configuración global validada y aplicada a las organizaciones nuevas; avisos para todas o las seleccionadas', function (): void {
    $settings = sxConsole($this->owner, 'GET', '/settings');
    $this->assertMatchesOpenApi($settings, 'get', '/platform/settings', 200);
    expect(collect($settings->json('data'))->pluck('key'))->toContain('support.max_session_minutes');

    $bad = sxConsole($this->owner, 'PATCH', '/settings/tenants.enabled_countries', ['value' => ['XX']]);
    $this->assertMatchesOpenApi($bad, 'patch', '/platform/settings/{key}', 422);
    $this->assertMatchesOpenApi(sxConsole($this->owner, 'PATCH', '/settings/no.existe', ['value' => 1]), 'patch', '/platform/settings/{key}', 404);
    $this->assertMatchesOpenApi(sxConsole($this->readonly, 'PATCH', '/settings/support.max_session_minutes', ['value' => 30]), 'patch', '/platform/settings/{key}', 403);
    $ok = sxConsole($this->owner, 'PATCH', '/settings/tenants.default_parameters', ['value' => ['seguridad.mfa_todos' => true]]);
    $this->assertMatchesOpenApi($ok, 'patch', '/platform/settings/{key}', 200);
    expect($ok->json('data.customized'))->toBeTrue();

    // La organización nueva nace con la moneda, zona e idioma del país y con los parámetros por defecto vigentes.
    $created = sxConsole($this->owner, 'POST', '/tenants', [
        'name' => 'Nueva Global', 'slug' => 'nueva-global', 'country' => 'PE',
        'admin_name' => 'Admin Nueva', 'admin_email' => 'admin@nueva-global.test', 'legal_name' => 'Nueva Global S.A.C.',
        'tax_id' => '20999999977', 'payroll_regime' => 'pe_general',
    ])->assertCreated();
    expect($created->json('data.currency'))->toBe('PEN')->and($created->json('data.timezone'))->toBe('America/Lima');
    $newId = (string) $created->json('data.id');
    expect(TenancyScope::runAs($newId, fn () => app(ParameterResolver::class)->value('seguridad.mfa_todos')))->toBeTrue();

    $all = sxConsole($this->owner, 'POST', '/notices', ['title' => 'Mantenimiento programado', 'message' => 'El sábado de 22:00 a 23:00.', 'severity' => 'warning']);
    $this->assertMatchesOpenApi($all, 'post', '/platform/notices', 201);
    $only = sxConsole($this->owner, 'POST', '/notices', ['title' => 'Solo la nueva', 'message' => 'Aviso dirigido.', 'audience' => 'selected', 'tenant_ids' => [$newId]])->assertCreated();
    $this->assertMatchesOpenApi(sxConsole($this->owner, 'POST', '/notices', ['title' => 'Sin destino', 'message' => 'x', 'audience' => 'selected', 'tenant_ids' => []]), 'post', '/platform/notices', 422);

    $seen = sxTenant($this->hr, 'GET', '/system-notices');
    $this->assertMatchesOpenApi($seen, 'get', '/system-notices', 200);
    expect(collect($seen->json('data'))->pluck('title')->all())->toBe(['Mantenimiento programado']);

    $edit = sxConsole($this->owner, 'PATCH', '/notices/'.$all->json('data.id'), ['message' => 'El sábado de 22:00 a 23:30.']);
    $this->assertMatchesOpenApi($edit, 'patch', '/platform/notices/{id}', 200);
    $cancel = sxConsole($this->owner, 'POST', '/notices/'.$all->json('data.id').'/cancel');
    $this->assertMatchesOpenApi($cancel, 'post', '/platform/notices/{id}/cancel', 200);
    $this->assertMatchesOpenApi(sxConsole($this->owner, 'PATCH', '/notices/'.$all->json('data.id'), ['title' => 'x']), 'patch', '/platform/notices/{id}', 409);
    $this->assertMatchesOpenApi(sxConsole($this->owner, 'POST', '/notices/'.$all->json('data.id').'/cancel'), 'post', '/platform/notices/{id}/cancel', 409);
    expect(sxTenant($this->hr, 'GET', '/system-notices')->json('data'))->toBe([]);
    $this->assertMatchesOpenApi(sxConsole($this->owner, 'GET', '/notices'), 'get', '/platform/notices', 200);
    expect($only->json('data.tenant_ids'))->toBe([$newId]);
});

test('SAS-13 · registros por separado: auditoría con filtros, actividad de una organización y logs técnicos', function (): void {
    sxConsole($this->owner, 'PATCH', '/settings/support.max_session_minutes', ['value' => 45])->assertOk();
    $audit = sxConsole($this->owner, 'GET', '/audit-events?action=settings.updated&from='.now()->toDateString().'&to='.now()->toDateString());
    $this->assertMatchesOpenApi($audit, 'get', '/platform/audit-events', 200);
    expect($audit->json('meta.total'))->toBe(1);
    expect(sxConsole($this->owner, 'GET', '/audit-events?tenant_id='.SX_TENANT.'&action=settings.updated')->json('meta.total'))->toBe(0);

    TenancyScope::runAs(SX_TENANT, fn () => DB::table('activity_events')->insert(['tenant_id' => SX_TENANT, 'user_id' => $this->hr->id, 'activity' => 'screen.viewed', 'subject_type' => 'vacations']));
    $activity = sxConsole($this->support, 'GET', '/activity-events?tenant_id='.SX_TENANT.'&user_id='.$this->hr->id);
    $this->assertMatchesOpenApi($activity, 'get', '/platform/activity-events', 200);
    expect($activity->json('data.0.activity'))->toBe('screen.viewed');
    $this->assertMatchesOpenApi(sxConsole($this->support, 'GET', '/activity-events'), 'get', '/platform/activity-events', 422);

    $log = tempnam(sys_get_temp_dir(), 'gestia-log');
    file_put_contents($log, json_encode(['message' => 'Fallo de integración', 'level_name' => 'ERROR', 'datetime' => now()->toIso8601String(), 'context' => [], 'extra' => ['tenant_id' => SX_TENANT]])."\n"
        .json_encode(['message' => 'Ingreso', 'level_name' => 'INFO', 'datetime' => now()->toIso8601String(), 'context' => [], 'extra' => ['tenant_id' => (string) Str::uuid()]])."\n");
    config(['platform.technical_log_path' => $log]);
    $logs = sxConsole($this->support, 'GET', '/technical-logs?tenant_id='.SX_TENANT.'&level=warning');
    $this->assertMatchesOpenApi($logs, 'get', '/platform/technical-logs', 200);
    expect(collect($logs->json('data'))->pluck('message')->all())->toBe(['Fallo de integración']);
    @unlink($log);
});

test('SAS-8 · regla 1: el rol de la aplicación no ve las sesiones de soporte de otra organización', function (): void {
    $created = sxConsole($this->support, 'POST', '/support-sessions', ['tenant_id' => SX_TENANT, 'target_user_id' => $this->hr->id, 'reason' => 'Prueba de aislamiento entre organizaciones'])->assertCreated();
    $id = (string) $created->json('data.id');
    $other = 'cccccccc-a1a1-4000-8000-0000000000b9';
    DB::table('tenants')->insertOrIgnore(['id' => $other, 'slug' => 'sx-otra', 'name' => 'Otra Organización', 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN']);

    expect(TenancyScope::runAs($other, fn () => DB::table('support_sessions')->where('id', $id)->count()))->toBe(0)
        ->and(TenancyScope::runAs(SX_TENANT, fn () => DB::table('support_sessions')->where('id', $id)->count()))->toBe(1)
        // La consola la sigue viendo con el rol de plataforma.
        ->and(sxConsole($this->support, 'GET', '/support-sessions/'.$id)->json('data.tenant_id'))->toBe(SX_TENANT);
});
