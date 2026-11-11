<?php

/**
 * SEG-2 · RN-SEG-09 · Personal sin correo: alta con documento, activación con código e ingreso
 * con documento + código por mensaje (caduca, un uso, tope de intentos).
 */

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Shared\Integrations\Fake\FakeMessagingAdapter;
use App\Shared\Integrations\IntegrationManager;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(DatabaseTransactions::class);

const CS_TENANT = 'aaaaaaaa-5e62-4000-8000-00000000000b';
const CS_PASSWORD = 'Clave-Segura-2026';

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requiere PostgreSQL.');
    }
    TenancyScope::clear();
    DB::table('tenants')->insertOrIgnore(['id' => CS_TENANT, 'slug' => 'cs-org', 'name' => 'Andes Planta', 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN']);
    DB::select('SELECT seed_tenant_defaults(?)', [CS_TENANT]);
    $this->messages = new FakeMessagingAdapter;
    app(IntegrationManager::class)->setMessagingAdapter($this->messages);
    $this->admin = TenancyScope::runAs(CS_TENANT, function (): User {
        DB::table('roles')->where('system_role_code', 'admin_empresa')->update(['mfa_required' => false]);
        $u = User::factory()->withPassword(CS_PASSWORD)->withEmail('admin@andes.pe')->create(['display_name' => 'Admin']);
        $u->roleAssignments()->create(['role_id' => Role::query()->where('system_role_code', 'admin_empresa')->value('id')]);

        return $u;
    });
});

afterEach(fn () => TenancyScope::clear());

function csCall(string $method, string $path, array $body = [], ?string $cookie = null)
{
    TenancyScope::clear();
    app('auth')->forgetGuards();
    $req = test()->withHeaders(['X-Tenant-Id' => CS_TENANT, 'Idempotency-Key' => (string) Str::uuid()]);
    if ($cookie !== null) {
        $req = $req->withCredentials()->withUnencryptedCookie('ayni_session', $cookie)
            ->withHeaders(['X-XSRF-TOKEN' => hash_hmac('sha256', 'xsrf|'.$cookie, (string) config('app.key'))]);
    }

    return $req->json($method, '/api/v1'.$path, $body);
}

function csAdmin(): string
{
    return csCall('POST', '/auth/login', ['email' => 'admin@andes.pe', 'password' => CS_PASSWORD])->getCookie('ayni_session', false)->getValue();
}

/** Alta de un operario sin correo ni usuario; devuelve su id. */
function csWorker(string $admin, ?string $phone = '+51 987 654 321'): string
{
    $roleId = TenancyScope::runAs(CS_TENANT, fn () => Role::query()->where('system_role_code', 'empleado')->value('id'));
    $r = csCall('POST', '/users', array_filter(['display_name' => 'Juan Quispe', 'document_type' => 'dni', 'document_number' => '45678912', 'phone' => $phone, 'role_ids' => [$roleId]]), $admin);
    $r->assertCreated();

    return (string) $r->json('data.id');
}

function csLastCode(FakeMessagingAdapter $messages): string
{
    $all = $messages->getMessages();
    $last = end($all);
    preg_match('/\b(\d{6,8})\b/', (string) $last['message'], $m);

    return $m[1];
}

test('un operario sin correo se da de alta solo con documento y celular', function (): void {
    $admin = csAdmin();
    $roleId = TenancyScope::runAs(CS_TENANT, fn () => Role::query()->where('system_role_code', 'empleado')->value('id'));
    $r = csCall('POST', '/users', ['display_name' => 'Juan Quispe', 'document_type' => 'dni', 'document_number' => '45678912', 'phone' => '+51 987 654 321', 'role_ids' => [$roleId]], $admin);
    $r->assertCreated()->assertJsonPath('data.email', null)->assertJsonPath('data.document_type', 'dni')
        ->assertJsonPath('data.document_number_masked', '••••8912')->assertJsonPath('data.phone_masked', '+••••••••321')
        ->assertJsonPath('data.status', 'invited');
    $this->assertMatchesOpenApi($r, 'post', '/users', 201);
    expect($r->json('data'))->not->toHaveKey('document_number');

    // El documento no se repite en la organización.
    csCall('POST', '/users', ['display_name' => 'Otro', 'document_type' => 'dni', 'document_number' => '45678912'], $admin)
        ->assertStatus(409)->assertJsonPath('error.meta.fields.0', 'document_number');
    // Sin documento ni usuario, falta cómo ingresar.
    csCall('POST', '/users', ['display_name' => 'Nadie'], $admin)->assertStatus(422);
});

test('RRHH entrega el código de activación y el operario activa su cuenta con su documento', function (): void {
    $admin = csAdmin();
    $id = csWorker($admin);

    $r = csCall('POST', "/users/{$id}/activation-code", ['channel' => 'in_app'], $admin);
    $r->assertOk();
    $this->assertMatchesOpenApi($r, 'post', '/users/{id}/activation-code', 200);
    $code = (string) $r->json('data.code');
    expect($code)->toMatch('/^\d{8}$/');
    TenancyScope::runAs(CS_TENANT, function () use ($code, $id): void {
        $audit = DB::table('audit_events')->where('action', 'user.activation_code_issued')->where('entity_id', $id)->sole();
        expect(json_encode($audit))->not->toContain($code)
            ->and(DB::table('user_tokens')->where('user_id', $id)->pluck('token_hash')->all())->not->toContain($code);
    });

    // Clave débil: no se gasta el código.
    csCall('POST', '/auth/activation/code', ['document_type' => 'dni', 'document_number' => '45678912', 'code' => $code, 'password' => 'corta', 'password_confirmation' => 'corta'])->assertStatus(422);
    $bad = csCall('POST', '/auth/activation/code', ['document_type' => 'dni', 'document_number' => '45678912', 'code' => '00000000', 'password' => CS_PASSWORD, 'password_confirmation' => CS_PASSWORD]);
    $bad->assertStatus(422);
    $this->assertMatchesOpenApi($bad, 'post', '/auth/activation/code', 422);

    $ok = csCall('POST', '/auth/activation/code', ['document_type' => 'dni', 'document_number' => '4567-8912', 'code' => $code, 'password' => CS_PASSWORD, 'password_confirmation' => CS_PASSWORD]);
    $ok->assertOk()->assertJsonPath('data.mfa_step', 'ok');
    $this->assertMatchesOpenApi($ok, 'post', '/auth/activation/code', 200);
    TenancyScope::runAs(CS_TENANT, fn () => expect(User::query()->find($id)->status)->toBe('active'));

    // Ya activa: ingresa con documento + clave y el código no sirve otra vez.
    csCall('POST', '/auth/login', ['document_type' => 'dni', 'document_number' => '45678912', 'password' => CS_PASSWORD])->assertOk();
    csCall('POST', '/auth/activation/code', ['document_type' => 'dni', 'document_number' => '45678912', 'code' => $code, 'password' => CS_PASSWORD, 'password_confirmation' => CS_PASSWORD])->assertStatus(422);
    csCall('POST', "/users/{$id}/activation-code", ['channel' => 'in_app'], $admin)->assertStatus(422)->assertJsonPath('error.meta.rule', 'activation_not_pending');
});

test('el código de activación puede llegar por SMS y exige celular registrado', function (): void {
    $admin = csAdmin();
    $noPhone = csWorker($admin, null);
    csCall('POST', "/users/{$noPhone}/activation-code", ['channel' => 'sms'], $admin)->assertStatus(422)->assertJsonPath('error.meta.rule', 'no_phone');

    TenancyScope::runAs(CS_TENANT, fn () => DB::table('user_identities')->where('user_id', $noPhone)->where('kind', 'national_id')->delete());
    $id = csCall('POST', '/users', ['display_name' => 'Rosa', 'document_type' => 'ce', 'document_number' => 'X1234567', 'phone' => '+51 912 345 678'], $admin)->json('data.id');
    $r = csCall('POST', "/users/{$id}/activation-code", ['channel' => 'sms'], $admin);
    $r->assertOk()->assertJsonPath('data.code', null);
    $all = $this->messages->getMessages();
    $msg = end($all);
    expect($msg['phone'])->toBe('+51912345678')->and($msg['message'])->toContain('activar');
    csCall('POST', '/auth/activation/code', ['document_type' => 'ce', 'document_number' => 'X1234567', 'code' => csLastCode($this->messages), 'password' => CS_PASSWORD, 'password_confirmation' => CS_PASSWORD])->assertOk();
});

test('ingreso con documento y código por mensaje: caduca, sirve una vez y tiene tope de intentos', function (): void {
    $admin = csAdmin();
    $id = csWorker($admin);
    $code = (string) csCall('POST', "/users/{$id}/activation-code", ['channel' => 'in_app'], $admin)->json('data.code');
    csCall('POST', '/auth/activation/code', ['document_type' => 'dni', 'document_number' => '45678912', 'code' => $code, 'password' => CS_PASSWORD, 'password_confirmation' => CS_PASSWORD])->assertOk();
    $before = count($this->messages->getMessages());

    // Un documento que no existe recibe la misma respuesta y no envía nada.
    $unknown = csCall('POST', '/auth/otp/request', ['document_type' => 'dni', 'document_number' => '99999999']);
    $unknown->assertOk()->assertJsonPath('data.sent', true);
    $this->assertMatchesOpenApi($unknown, 'post', '/auth/otp/request', 200);
    expect($this->messages->getMessages())->toHaveCount($before);

    csCall('POST', '/auth/otp/request', ['document_type' => 'dni', 'document_number' => '45678912'])->assertOk();
    expect($this->messages->getMessages())->toHaveCount($before + 1);
    $otp = csLastCode($this->messages);
    expect($otp)->toMatch('/^\d{6}$/');
    // Pedir otro enseguida no envía otro mensaje (espera entre envíos).
    csCall('POST', '/auth/otp/request', ['document_type' => 'dni', 'document_number' => '45678912'])->assertOk();
    expect($this->messages->getMessages())->toHaveCount($before + 1);

    $ok = csCall('POST', '/auth/otp/verify', ['document_type' => 'dni', 'document_number' => '45678912', 'code' => $otp]);
    $ok->assertOk()->assertJsonPath('data.user.display_name', 'Juan Quispe');
    $this->assertMatchesOpenApi($ok, 'post', '/auth/otp/verify', 200);
    expect($ok->getCookie('ayni_session', false))->not->toBeNull();
    // Un solo uso.
    $reused = csCall('POST', '/auth/otp/verify', ['document_type' => 'dni', 'document_number' => '45678912', 'code' => $otp]);
    $reused->assertStatus(422);
    $this->assertMatchesOpenApi($reused, 'post', '/auth/otp/verify', 422);

    // Caduca.
    $this->travel(2)->minutes();
    csCall('POST', '/auth/otp/request', ['document_type' => 'dni', 'document_number' => '45678912']);
    $expiring = csLastCode($this->messages);
    $this->travel(6)->minutes();
    csCall('POST', '/auth/otp/verify', ['document_type' => 'dni', 'document_number' => '45678912', 'code' => $expiring])->assertStatus(422);

    // Tope de intentos: tras 5 fallos el código se anula aunque luego llegue el correcto.
    csCall('POST', '/auth/otp/request', ['document_type' => 'dni', 'document_number' => '45678912']);
    $last = csLastCode($this->messages);
    foreach (range(1, 5) as $i) {
        csCall('POST', '/auth/otp/verify', ['document_type' => 'dni', 'document_number' => '45678912', 'code' => '000000'])->assertStatus(422);
    }
    csCall('POST', '/auth/otp/verify', ['document_type' => 'dni', 'document_number' => '45678912', 'code' => $last])->assertStatus(422);
    TenancyScope::runAs(CS_TENANT, function () use ($id): void {
        expect(DB::table('activity_events')->where('user_id', $id)->where('activity', 'auth.login_code_failed')->count())->toBeGreaterThanOrEqual(5)
            ->and(DB::table('users')->where('id', $id)->value('locked_until'))->not->toBeNull();
    });
});

test('sin permiso de invitar no se emiten códigos de activación', function (): void {
    $admin = csAdmin();
    $id = csWorker($admin);
    $emp = TenancyScope::runAs(CS_TENANT, function (): User {
        $u = User::factory()->withPassword(CS_PASSWORD)->withEmail('rosa@andes.pe')->create();
        $u->roleAssignments()->create(['role_id' => Role::query()->where('system_role_code', 'empleado')->value('id')]);

        return $u;
    });
    $cookie = csCall('POST', '/auth/login', ['email' => 'rosa@andes.pe', 'password' => CS_PASSWORD])->getCookie('ayni_session', false)->getValue();
    csCall('POST', "/users/{$id}/activation-code", ['channel' => 'in_app'], $cookie)->assertStatus(403);
});
