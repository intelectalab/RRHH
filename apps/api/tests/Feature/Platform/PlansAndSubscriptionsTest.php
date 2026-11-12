<?php

/**
 * SAS-3 · Planes comerciales: nombres libres, duplicar con módulos, límites y funcionalidades, y versiones
 * al editar un plan en uso (los suscriptores conservan sus condiciones, RN-SAS-11).
 * SAS-4 · Suscripciones, complementos y pruebas: la suscripción es la fuente de los módulos y límites; un
 * complemento activa su módulo sin migrar; la prueba vencida deja a la organización en solo lectura y, al
 * terminar la gracia, suspendida (RN-SAS-04); el historial muestra cada cambio con su autor.
 */

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Platform\Infrastructure\Models\PlatformUser;
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

const PS_TENANT = 'bbbbbbbb-5a53-4000-8000-00000000000b';
const PS_PASSWORD = 'Consola-Segura-2026!';

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requiere PostgreSQL.');
    }
    TenancyScope::clear();
    DB::table('tenants')->insertOrIgnore([[
        'id' => PS_TENANT, 'slug' => 'ps-norte', 'name' => 'Agroexportadora Norte', 'status' => 'trial',
        'country_code' => 'PE', 'default_currency' => 'PEN', 'trial_ends_at' => now()->addDays(10)->endOfDay(),
    ]]);
    DB::select('SELECT seed_tenant_defaults(?)', [PS_TENANT]);
    $this->admin = psTenantUser();

    $this->owner = psConsoleUser('duena-ps@gestia.test', 'superadmin');
    $this->token = psEnrolled('duena-ps@gestia.test');

    // Rutas de prueba: un módulo que el plan no trae (Asistencia) y una escritura cualquiera de la organización.
    Route::middleware(['api', 'auth:ayni', 'permission:time.punches.view'])->get('/api/v1/__ps-attendance', fn () => response()->json(['data' => 'ok']));
    Route::middleware(['api', 'auth:ayni', 'permission:identity.roles.create'])->post('/api/v1/__ps-write', fn () => response()->json(['data' => 'ok']));
    Route::middleware(['api', 'auth:ayni', 'permission:identity.roles.view'])->get('/api/v1/__ps-read', fn () => response()->json(['data' => 'ok']));
});

afterEach(fn () => TenancyScope::clear());

function psConsoleUser(string $email, string $role): PlatformUser
{
    return PlatformUser::create([
        'email' => $email, 'full_name' => 'Dueña '.ucfirst(strtok($email, '@') ?: 'x'), 'role' => $role,
        'status' => 'active', 'password_hash' => Hash::make(PS_PASSWORD),
    ]);
}

function psTenantUser(): User
{
    return TenancyScope::runAs(PS_TENANT, function (): User {
        $user = User::factory()->create();
        $user->roleAssignments()->create(['role_id' => Role::query()->where('system_role_code', 'admin_empresa')->firstOrFail()->id]);

        return $user;
    });
}

/**
 * @param  array<string, mixed>  $body
 */
function psConsole(?string $token, string $method, string $path, array $body = [])
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

/**
 * @param  array<string, mixed>  $body
 */
function psApp(User $user, string $method, string $path, array $body = [])
{
    TenancyScope::clear();
    app('auth')->forgetGuards();
    app(ModuleGate::class)->forget();
    app(PermissionResolver::class)->forget();

    $response = test()->flushHeaders()->actingAs($user, 'ayni')
        ->withHeaders(['X-Tenant-Id' => PS_TENANT])
        ->json($method, '/api/v1'.$path, $body);
    // La prueba sigue con el rol de la consola: lee y escribe tablas de plataforma.
    TenancyScope::clear();

    return $response;
}

function psEnrolled(string $email): string
{
    $login = psConsole(null, 'POST', '/auth/login', ['email' => $email, 'password' => PS_PASSWORD])->assertOk();
    $token = (string) $login->getCookie('gestia_console_session', false)?->getValue();
    $secret = (string) psConsole($token, 'POST', '/auth/mfa/totp')->assertOk()->json('data.secret');
    psConsole($token, 'POST', '/auth/mfa/totp/confirm', ['code' => Totp::at(Totp::base32Decode($secret), Totp::currentStep())])->assertOk();

    return $token;
}

/**
 * Plan precargado (ADR 0013: nace en borrador y sin precio) listo para vender: el operador fija el precio.
 */
function psSellable(string $token, string $code): string
{
    $id = (string) DB::table('plans')->where('code', $code)->whereNull('superseded_by_plan_id')->value('id');
    psConsole($token, 'PATCH', '/plans/'.$id, ['price' => ['amount' => '0.00', 'currency' => 'PEN']])->assertOk();
    psConsole($token, 'POST', '/plans/'.$id.'/activate')->assertOk();

    return $id;
}

function psAddon(string $token, string $code): string
{
    $id = (string) DB::table('addons')->where('code', $code)->value('id');
    psConsole($token, 'PATCH', '/addons/'.$id, ['price' => ['amount' => '0.00', 'currency' => 'PEN']])->assertOk();
    psConsole($token, 'POST', '/addons/'.$id.'/activate')->assertOk();

    return $id;
}

function psModuleState(string $code): ?string
{
    return TenancyScope::runAs(PS_TENANT, fn () => DB::table('tenant_modules')->where('module_code', $code)->value('status'));
}

test('planes: nombres libres, módulos con sus dependencias, y sin precio no se activan para la venta', function (): void {
    $created = psConsole($this->token, 'POST', '/plans', [
        'code' => 'pyme-plus', 'name' => 'Pyme Plus Andina', 'billing_cycle' => 'annual',
        'modules' => ['time_off', 'payroll'], 'limits' => [['code' => 'employees', 'value' => '120']], 'features' => ['ayuda.asistente_ia'],
    ]);
    $this->assertMatchesOpenApi($created, 'post', '/platform/plans', 201);
    $created->assertJsonPath('data.name', 'Pyme Plus Andina')->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.revision', 1)->assertJsonPath('data.price', null);
    // Plataforma base y Core HR van siempre (ADR 0008).
    expect($created->json('data.modules'))->toBe(['base', 'core', 'payroll', 'time_off']);
    $id = (string) $created->json('data.id');

    // Planilla sin Vacaciones rompe una dependencia (ADR 0008).
    $broken = psConsole($this->token, 'POST', '/plans', ['code' => 'roto', 'name' => 'Roto', 'modules' => ['payroll']]);
    $this->assertMatchesOpenApi($broken, 'post', '/platform/plans', 422);
    $broken->assertJsonPath('error.meta.rule', 'plan_module_dependencies');
    psConsole($this->token, 'POST', '/plans', ['code' => 'pyme-plus', 'name' => 'Repetido'])->assertStatus(422);
    psConsole($this->token, 'POST', '/plans', ['code' => 'Con Espacio', 'name' => 'X'])->assertStatus(422);

    $noPrice = psConsole($this->token, 'POST', '/plans/'.$id.'/activate');
    $this->assertMatchesOpenApi($noPrice, 'post', '/platform/plans/{id}/activate', 422);
    $noPrice->assertJsonPath('error.meta.rule', 'plan_price_required');

    psConsole($this->token, 'PATCH', '/plans/'.$id, ['price' => ['amount' => '10.50', 'currency' => 'PEN']])
        ->assertOk()->assertJsonPath('data.price.amount', '10.50')->assertJsonPath('data.revision', 1);
    $active = psConsole($this->token, 'POST', '/plans/'.$id.'/activate');
    $this->assertMatchesOpenApi($active, 'post', '/platform/plans/{id}/activate', 200);
    $active->assertJsonPath('data.status', 'active');

    $retire = psConsole($this->token, 'POST', '/plans/'.$id.'/retire');
    $this->assertMatchesOpenApi($retire, 'post', '/platform/plans/{id}/retire', 200);
    $retire->assertJsonPath('data.status', 'inactive');
    $again = psConsole($this->token, 'POST', '/plans/'.$id.'/retire');
    $this->assertMatchesOpenApi($again, 'post', '/platform/plans/{id}/retire', 409);
    $again->assertJsonPath('error.code', 'invalid_transition');

    $list = psConsole($this->token, 'GET', '/plans?q=pyme');
    $this->assertMatchesOpenApi($list, 'get', '/platform/plans', 200);
    expect(collect($list->json('data'))->pluck('code')->all())->toBe(['pyme-plus']);
    $show = psConsole($this->token, 'GET', '/plans/'.$id);
    $this->assertMatchesOpenApi($show, 'get', '/platform/plans/{id}', 200);
    expect(DB::table('platform_audit_events')->where('entity_type', 'plan')->where('entity_id', $id)->pluck('action')->all())
        ->toContain('plan.created', 'plan.updated', 'plan.activate', 'plan.retire');
});

test('duplicar un plan copia sus módulos, límites y funcionalidades a un borrador nuevo', function (): void {
    $source = (string) DB::table('plans')->where('code', 'profesional')->value('id');
    $copy = psConsole($this->token, 'POST', '/plans/'.$source.'/duplicate', ['code' => 'profesional-norte']);
    $this->assertMatchesOpenApi($copy, 'post', '/platform/plans/{id}/duplicate', 200);
    $copy->assertJsonPath('data.code', 'profesional-norte')->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.duplicated_from_plan_id', $source);
    $original = psConsole($this->token, 'GET', '/plans/'.$source)->json('data');
    expect($copy->json('data.modules'))->toBe($original['modules'])
        ->and($copy->json('data.limits'))->toBe($original['limits'])
        ->and($copy->json('data.features'))->toBe($original['features']);
    expect((string) $copy->json('data.name'))->toContain('Profesional');

    // Sin código, propone uno libre.
    psConsole($this->token, 'POST', '/plans/'.$source.'/duplicate')->assertOk()->assertJsonPath('data.code', 'profesional-copia');
});

test('editar un plan en uso crea una versión nueva y la anterior se conserva para sus suscriptores', function (): void {
    $planId = psSellable($this->token, 'esencial');
    $sub = psConsole($this->token, 'POST', '/subscriptions', ['tenant_id' => PS_TENANT, 'plan_id' => $planId])->assertCreated();

    $edit = psConsole($this->token, 'PATCH', '/plans/'.$planId, [
        'price' => ['amount' => '25.00', 'currency' => 'PEN'], 'limits' => [['code' => 'employees', 'value' => '80']],
    ]);
    $this->assertMatchesOpenApi($edit, 'patch', '/platform/plans/{id}', 200);
    $edit->assertJsonPath('data.revision', 2)->assertJsonPath('data.code', 'esencial')->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.price.amount', '25.00');
    $newId = (string) $edit->json('data.id');
    expect($newId)->not->toBe($planId);

    // La versión anterior queda inactiva, apuntando a la nueva, con sus condiciones intactas.
    $old = psConsole($this->token, 'GET', '/plans/'.$planId);
    $old->assertJsonPath('data.status', 'inactive')->assertJsonPath('data.superseded_by_plan_id', $newId)
        ->assertJsonPath('data.price.amount', '0.00')->assertJsonPath('data.subscribers', 1);
    expect(collect($old->json('data.limits'))->firstWhere('code', 'employees')['value'])->toBe('50.00');
    psConsole($this->token, 'GET', '/subscriptions/'.$sub->json('data.id'))
        ->assertJsonPath('data.plan_id', $planId)->assertJsonPath('data.plan.revision', 1)->assertJsonPath('data.plan.is_current', false)
        ->assertJsonPath('data.price.amount', '0.00');
    expect(TenancyScope::runAs(PS_TENANT, fn () => DB::table('tenant_limits')->where('limit_code', 'employees')->value('limit_value')))->toBe('50.00');

    // Una versión reemplazada ya no se edita ni se activa; la lista muestra solo la vigente.
    psConsole($this->token, 'PATCH', '/plans/'.$planId, ['name' => 'Otro nombre'])->assertStatus(422)->assertJsonPath('error.meta.rule', 'plan_superseded');
    psConsole($this->token, 'POST', '/plans/'.$planId.'/activate')->assertStatus(422)->assertJsonPath('error.meta.rule', 'plan_superseded');
    expect(collect(psConsole($this->token, 'GET', '/plans')->json('data'))->where('code', 'esencial')->pluck('revision')->all())->toBe([2]);

    $versions = psConsole($this->token, 'GET', '/plans/'.$newId.'/versions');
    $this->assertMatchesOpenApi($versions, 'get', '/platform/plans/{id}/versions', 200);
    expect(collect($versions->json('data'))->pluck('revision')->all())->toBe([2, 1]);

    // Editar la versión nueva, sin suscriptores, la cambia en el lugar.
    psConsole($this->token, 'PATCH', '/plans/'.$newId, ['name' => 'Esencial 2026'])->assertOk()->assertJsonPath('data.revision', 2);
});

test('suscripción en prueba: activa los módulos y límites del plan; un complemento activa su módulo sin migrar', function (): void {
    $planId = psSellable($this->token, 'esencial');
    $created = psConsole($this->token, 'POST', '/subscriptions', [
        'tenant_id' => PS_TENANT, 'plan_id' => $planId, 'trial_ends_on' => now()->addDays(20)->toDateString(),
    ]);
    $this->assertMatchesOpenApi($created, 'post', '/platform/subscriptions', 201);
    $created->assertJsonPath('data.status', 'trialing')->assertJsonPath('data.trial_ends_on', now()->addDays(20)->toDateString())
        ->assertJsonPath('data.plan.code', 'esencial');
    $id = (string) $created->json('data.id');
    expect(psModuleState('time_off'))->toBe('active');
    expect(TenancyScope::runAs(PS_TENANT, fn () => DB::table('tenant_limits')->where('limit_code', 'employees')->value('limit_value')))->toBe('50.00');
    expect(DB::table('tenants')->where('id', PS_TENANT)->value('trial_ends_at'))->toStartWith(now()->addDays(20)->toDateString());

    // Una organización tiene una sola suscripción.
    psConsole($this->token, 'POST', '/subscriptions', ['tenant_id' => PS_TENANT, 'plan_id' => $planId])
        ->assertStatus(422)->assertJsonPath('error.meta.rule', 'subscription_exists');

    // Complemento Gestia Tiempo: Asistencia queda disponible al instante, sin reiniciar ni migrar.
    $data = psApp($this->admin, 'GET', '/absence-types');
    $data->assertOk();
    psApp($this->admin, 'GET', '/__ps-attendance')->assertStatus(403)->assertJsonPath('error.code', 'module_not_enabled');
    $addonId = psAddon($this->token, 'tiempo');
    $add = psConsole($this->token, 'POST', '/subscriptions/'.$id.'/addons', ['addon_id' => $addonId]);
    $this->assertMatchesOpenApi($add, 'post', '/platform/subscriptions/{id}/addons', 200);
    expect(collect($add->json('data.addons'))->pluck('code')->all())->toBe(['tiempo']);
    psApp($this->admin, 'GET', '/__ps-attendance')->assertOk();
    expect(count(psApp($this->admin, 'GET', '/absence-types')->json('data')))->toBe(count($data->json('data')));
    psConsole($this->token, 'POST', '/subscriptions/'.$id.'/addons', ['addon_id' => $addonId])->assertStatus(422)->assertJsonPath('error.meta.rule', 'addon_already_added');

    // Quitarlo: sus datos quedan en solo lectura (RN-SAS-06).
    $rowId = (string) $add->json('data.addons.0.id');
    $remove = psConsole($this->token, 'POST', '/subscriptions/'.$id.'/addons/'.$rowId.'/remove');
    $this->assertMatchesOpenApi($remove, 'post', '/platform/subscriptions/{id}/addons/{addonId}/remove', 200);
    expect(psModuleState('attendance'))->toBe('inactive');
    psApp($this->admin, 'GET', '/__ps-attendance')->assertOk();
    expect(psApp($this->admin, 'GET', '/me')->json('data.modules_read_only'))->toContain('attendance');

    // Un complemento inactivo no se vende.
    $inactive = (string) DB::table('addons')->where('code', 'talento')->value('id');
    psConsole($this->token, 'POST', '/subscriptions/'.$id.'/addons', ['addon_id' => $inactive])->assertStatus(422)->assertJsonPath('error.meta.rule', 'addon_not_active');
});

test('cambio de plan: subir activa módulos al instante y bajar los deja en solo lectura; el historial muestra cada cambio con su autor', function (): void {
    $esencial = psSellable($this->token, 'esencial');
    $profesional = psSellable($this->token, 'profesional');
    $id = (string) psConsole($this->token, 'POST', '/subscriptions', ['tenant_id' => PS_TENANT, 'plan_id' => $esencial])->json('data.id');
    // Sin prueba pedida y con la organización en prueba, toma la fecha de fin de la organización.
    psConsole($this->token, 'GET', '/subscriptions/'.$id)->assertJsonPath('data.status', 'trialing');

    $up = psConsole($this->token, 'POST', '/subscriptions/'.$id.'/change-plan', ['plan_id' => $profesional]);
    $this->assertMatchesOpenApi($up, 'post', '/platform/subscriptions/{id}/change-plan', 200);
    $up->assertJsonPath('data.plan.code', 'profesional');
    expect(psModuleState('payroll'))->toBe('active')->and(psModuleState('advances'))->toBe('active');
    expect(TenancyScope::runAs(PS_TENANT, fn () => DB::table('tenant_limits')->where('limit_code', 'employees')->value('limit_value')))->toBe('200.00');

    psConsole($this->token, 'POST', '/subscriptions/'.$id.'/change-plan', ['plan_id' => $profesional])->assertStatus(422)->assertJsonPath('error.meta.rule', 'same_plan');

    psConsole($this->token, 'POST', '/subscriptions/'.$id.'/change-plan', ['plan_id' => $esencial])->assertOk();
    expect(psModuleState('payroll'))->toBe('inactive')->and(psModuleState('advances'))->toBe('inactive')
        ->and(psModuleState('time_off'))->toBe('active');

    // Un plan que no está a la venta no se contrata.
    $draft = (string) DB::table('plans')->where('code', 'corporativo')->value('id');
    psConsole($this->token, 'POST', '/subscriptions/'.$id.'/change-plan', ['plan_id' => $draft])->assertStatus(422)->assertJsonPath('error.meta.rule', 'plan_not_for_sale');

    $history = psConsole($this->token, 'GET', '/subscriptions/'.$id.'/events');
    $this->assertMatchesOpenApi($history, 'get', '/platform/subscriptions/{id}/events', 200);
    expect(collect($history->json('data'))->pluck('action')->all())
        ->toBe(['subscription.plan_changed', 'subscription.plan_changed', 'subscription.created']);
    expect(collect($history->json('data'))->pluck('actor')->unique()->all())->toBe([$this->owner->full_name]);
    expect($history->json('data.0.before.plan.code'))->toBe('profesional')->and($history->json('data.0.after.plan.code'))->toBe('esencial');
    expect($history->json('data.0.after.modules_retired'))->toContain('payroll');

    $list = psConsole($this->token, 'GET', '/subscriptions?filter[tenant_id]='.PS_TENANT);
    $this->assertMatchesOpenApi($list, 'get', '/platform/subscriptions', 200);
    expect($list->json('meta.total'))->toBe(1);
});

test('prueba vencida sin conversión: la organización queda en solo lectura y, al terminar la gracia, suspendida', function (): void {
    $planId = psSellable($this->token, 'esencial');
    $id = (string) psConsole($this->token, 'POST', '/subscriptions', [
        'tenant_id' => PS_TENANT, 'plan_id' => $planId, 'trial_ends_on' => now()->addDays(5)->toDateString(),
    ])->json('data.id');
    psApp($this->admin, 'POST', '/__ps-write')->assertOk();

    // Pasa la fecha de fin de la prueba.
    DB::table('subscriptions')->where('id', $id)->update(['trial_ends_on' => now()->subDay()->toDateString()]);
    DB::table('tenants')->where('id', PS_TENANT)->update(['trial_ends_at' => now()->subDay()->endOfDay()]);
    $this->artisan('platform:subscriptions:trials')->assertSuccessful();

    psConsole($this->token, 'GET', '/subscriptions/'.$id)->assertJsonPath('data.status', 'expired')->assertJsonPath('data.tenant.status', 'grace_period');
    // Solo lectura: consulta sí, cambios no.
    psApp($this->admin, 'GET', '/__ps-read')->assertOk();
    $write = psApp($this->admin, 'POST', '/__ps-write');
    $write->assertStatus(403)->assertJsonPath('error.code', 'tenant_read_only');
    expect((string) $write->json('error.message'))->not->toBe('');

    // Termina la gracia sin contratar: se suspende (RN-SAS-04).
    $grace = (int) ((array) config('platform.settings'))['subscriptions.trial_grace_days'];
    DB::table('subscriptions')->where('id', $id)->update(['expired_at' => now()->subDays($grace)->subMinute()]);
    $this->artisan('platform:subscriptions:trials')->assertSuccessful();
    psConsole($this->token, 'GET', '/subscriptions/'.$id)->assertJsonPath('data.status', 'suspended')->assertJsonPath('data.tenant.status', 'suspended');
    psApp($this->admin, 'GET', '/__ps-read')->assertStatus(403)->assertJsonPath('error.code', 'tenant_suspended');

    // Al contratar, vuelve a operar con todos sus datos.
    $reactivate = psConsole($this->token, 'POST', '/subscriptions/'.$id.'/reactivate');
    $this->assertMatchesOpenApi($reactivate, 'post', '/platform/subscriptions/{id}/reactivate', 200);
    $reactivate->assertJsonPath('data.status', 'active')->assertJsonPath('data.tenant.status', 'active');
    psApp($this->admin, 'POST', '/__ps-write')->assertOk();

    // Historial con el sistema como autor de los pasos automáticos.
    $events = collect(psConsole($this->token, 'GET', '/subscriptions/'.$id.'/events')->json('data'));
    expect($events->pluck('action')->all())->toBe(['subscription.reactivate', 'subscription.suspend', 'subscription.expire', 'subscription.created']);
    expect($events->firstWhere('action', 'subscription.expire')['actor'])->toBeNull();
});

test('convertir la prueba: la organización pasa a activa; una gracia por prueba vencida se regulariza', function (): void {
    $planId = psSellable($this->token, 'esencial');
    $id = (string) psConsole($this->token, 'POST', '/subscriptions', ['tenant_id' => PS_TENANT, 'plan_id' => $planId])->json('data.id');

    DB::table('subscriptions')->where('id', $id)->update(['trial_ends_on' => now()->subDay()->toDateString()]);
    $this->artisan('platform:subscriptions:trials')->assertSuccessful();
    psApp($this->admin, 'POST', '/__ps-write')->assertStatus(403);

    $convert = psConsole($this->token, 'POST', '/subscriptions/'.$id.'/convert');
    $this->assertMatchesOpenApi($convert, 'post', '/platform/subscriptions/{id}/convert', 200);
    $convert->assertJsonPath('data.status', 'active')->assertJsonPath('data.tenant.status', 'active')->assertJsonPath('data.expired_at', null);
    psApp($this->admin, 'POST', '/__ps-write')->assertOk();

    $invalid = psConsole($this->token, 'POST', '/subscriptions/'.$id.'/convert');
    $this->assertMatchesOpenApi($invalid, 'post', '/platform/subscriptions/{id}/convert', 409);
    $invalid->assertJsonPath('error.code', 'invalid_transition');
    psConsole($this->token, 'POST', '/subscriptions/'.$id.'/reactivate')->assertStatus(409);

    // Suspender y cancelar exigen motivo; cancelar da de baja la organización sin borrar datos.
    psConsole($this->token, 'POST', '/subscriptions/'.$id.'/suspend')->assertStatus(422);
    $suspend = psConsole($this->token, 'POST', '/subscriptions/'.$id.'/suspend', ['reason' => 'Pago pendiente']);
    $this->assertMatchesOpenApi($suspend, 'post', '/platform/subscriptions/{id}/suspend', 200);
    $suspend->assertJsonPath('data.tenant.status', 'suspended');
    $cancel = psConsole($this->token, 'POST', '/subscriptions/'.$id.'/cancel', ['reason' => 'Fin del contrato']);
    $this->assertMatchesOpenApi($cancel, 'post', '/platform/subscriptions/{id}/cancel', 200);
    $cancel->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.cancel_reason', 'Fin del contrato')
        ->assertJsonPath('data.tenant.status', 'cancelled');
});

test('editar la suscripción no cambia su estado ni su plan; la prueba solo se extiende en prueba', function (): void {
    $planId = psSellable($this->token, 'esencial');
    $id = (string) psConsole($this->token, 'POST', '/subscriptions', ['tenant_id' => PS_TENANT, 'plan_id' => $planId])->json('data.id');
    $patch = psConsole($this->token, 'PATCH', '/subscriptions/'.$id, [
        'trial_ends_on' => now()->addDays(30)->toDateString(), 'price' => ['amount' => '0.00', 'currency' => 'USD'],
        'status' => 'cancelled', 'plan_id' => (string) Str::uuid(),
    ]);
    $this->assertMatchesOpenApi($patch, 'patch', '/platform/subscriptions/{id}', 200);
    $patch->assertJsonPath('data.status', 'trialing')->assertJsonPath('data.plan_id', $planId)
        ->assertJsonPath('data.trial_ends_on', now()->addDays(30)->toDateString())->assertJsonPath('data.price.currency', 'USD');
    expect(DB::table('tenants')->where('id', PS_TENANT)->value('trial_ends_at'))->toStartWith(now()->addDays(30)->toDateString());

    psConsole($this->token, 'POST', '/subscriptions/'.$id.'/convert')->assertOk();
    psConsole($this->token, 'PATCH', '/subscriptions/'.$id, ['trial_ends_on' => now()->addDays(40)->toDateString()])
        ->assertStatus(422)->assertJsonPath('error.meta.rule', 'not_trialing');
    psConsole($this->token, 'GET', '/subscriptions/'.Str::uuid())->assertNotFound();
});

test('alta de organización con plan: nace con su suscripción y los módulos del plan', function (): void {
    $planId = psSellable($this->token, 'profesional');
    $created = psConsole($this->token, 'POST', '/tenants', [
        'name' => 'Pesquera Austral', 'slug' => 'pesquera-austral', 'country' => 'PE', 'currency' => 'PEN',
        'timezone' => 'America/Lima', 'locale' => 'es', 'trial_ends_on' => now()->addDays(14)->toDateString(),
        'admin_name' => 'Luis Paredes', 'admin_email' => 'luis@austral.test',
        'legal_name' => 'Pesquera Austral S.A.C.', 'tax_id' => '20601234567', 'payroll_regime' => 'pe_general',
        'plan_id' => $planId,
    ]);
    $this->assertMatchesOpenApi($created, 'post', '/platform/tenants', 201);
    $created->assertJsonPath('data.account.subscription.plan', 'Profesional')->assertJsonPath('data.account.subscription.status', 'trialing');
    $modules = collect($created->json('data.modules'))->keyBy('code');
    expect($modules['payroll']['state'])->toBe('active')->and($modules['payroll']['source'])->toBe('plan');
});

test('permisos: el rol de solo lectura ve planes y suscripciones pero no los cambia; sin sesión, 401', function (): void {
    psConsoleUser('lectura-ps@gestia.test', 'readonly');
    $token = psEnrolled('lectura-ps@gestia.test');
    $planId = (string) DB::table('plans')->where('code', 'esencial')->value('id');

    psConsole($token, 'GET', '/plans')->assertOk();
    psConsole($token, 'GET', '/subscriptions')->assertOk();
    $create = psConsole($token, 'POST', '/plans', ['code' => 'nuevo', 'name' => 'Nuevo']);
    $this->assertMatchesOpenApi($create, 'post', '/platform/plans', 403);
    psConsole($token, 'PATCH', '/plans/'.$planId, ['name' => 'X'])->assertForbidden();
    psConsole($token, 'POST', '/subscriptions', ['tenant_id' => PS_TENANT, 'plan_id' => $planId])->assertForbidden();
    psConsole($token, 'GET', '/addons')->assertOk();

    // Finanzas cambia el plan pero no crea planes.
    psConsoleUser('finanzas-ps@gestia.test', 'billing');
    $billing = psEnrolled('finanzas-ps@gestia.test');
    psConsole($billing, 'POST', '/plans', ['code' => 'nuevo', 'name' => 'Nuevo'])->assertForbidden();

    $anon = psConsole(null, 'GET', '/plans');
    $this->assertMatchesOpenApi($anon, 'get', '/platform/plans', 401);
});

test('complementos: el precio lo fija el operador; los de módulo respetan sus dependencias', function (): void {
    $list = psConsole($this->token, 'GET', '/addons');
    $this->assertMatchesOpenApi($list, 'get', '/platform/addons', 200);
    expect(collect($list->json('data'))->pluck('status')->unique()->all())->toBe(['inactive']);

    $id = (string) DB::table('addons')->where('code', 'planilla')->value('id');
    $noPrice = psConsole($this->token, 'POST', '/addons/'.$id.'/activate');
    $this->assertMatchesOpenApi($noPrice, 'post', '/platform/addons/{id}/activate', 422);
    $patch = psConsole($this->token, 'PATCH', '/addons/'.$id, ['price' => ['amount' => '0.00', 'currency' => 'PEN']]);
    $this->assertMatchesOpenApi($patch, 'patch', '/platform/addons/{id}', 200);
    psConsole($this->token, 'PATCH', '/addons/'.$id, ['limit_increment' => 5])->assertStatus(422);
    $active = psConsole($this->token, 'POST', '/addons/'.$id.'/activate');
    $this->assertMatchesOpenApi($active, 'post', '/platform/addons/{id}/activate', 200);
    $show = psConsole($this->token, 'GET', '/addons/'.$id);
    $this->assertMatchesOpenApi($show, 'get', '/platform/addons/{id}', 200);
    expect($show->json('data.modules'))->toBe(['advances', 'contractors', 'payroll']);

    // Planilla exige Vacaciones: sobre un plan que no la trae, el complemento no cabe.
    $plan = psConsole($this->token, 'POST', '/plans', ['code' => 'solo-core', 'name' => 'Solo Core HR', 'price' => ['amount' => '0.00', 'currency' => 'PEN']])->json('data.id');
    psConsole($this->token, 'POST', '/plans/'.$plan.'/activate')->assertOk();
    $sub = (string) psConsole($this->token, 'POST', '/subscriptions', ['tenant_id' => PS_TENANT, 'plan_id' => $plan])->json('data.id');
    psConsole($this->token, 'POST', '/subscriptions/'.$sub.'/addons', ['addon_id' => $id])
        ->assertStatus(422)->assertJsonPath('error.meta.rule', 'plan_module_dependencies');

    $retire = psConsole($this->token, 'POST', '/addons/'.$id.'/retire');
    $this->assertMatchesOpenApi($retire, 'post', '/platform/addons/{id}/retire', 200);
});
