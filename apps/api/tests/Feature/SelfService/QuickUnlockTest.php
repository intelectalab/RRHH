<?php

/**
 * ESS-6 · Reingreso rápido en el teléfono con PIN: se activa desde una sesión abierta, exige el teléfono
 * (cookie HttpOnly) y el PIN, caduca y se olvida tras varios errores.
 */

use App\Modules\Identity\Application\QuickUnlock;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(DatabaseTransactions::class);

const QU_TENANT = 'aaaaaaaa-6060-4000-8000-00000000006a';

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requiere PostgreSQL.');
    }
    TenancyScope::clear();
    DB::table('tenants')->insertOrIgnore(['id' => QU_TENANT, 'slug' => 'qu-org', 'name' => 'Org QU', 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN']);
    DB::select('SELECT seed_tenant_defaults(?)', [QU_TENANT]);
    $this->worker = TenancyScope::runAs(QU_TENANT, function (): User {
        DB::table('roles')->where('system_role_code', 'empleado')->update(['mfa_required' => false]);
        $u = User::factory()->create(['display_name' => 'Juan Quispe', 'locale' => 'es']);
        $u->roleAssignments()->create(['role_id' => Role::query()->where('system_role_code', 'empleado')->value('id')]);

        return $u;
    });
});

afterEach(fn () => TenancyScope::clear());

function quCall(?User $user, string $method, string $path, array $body = [], ?string $device = null)
{
    TenancyScope::clear();
    app('auth')->forgetGuards();
    $req = $user === null ? test() : test()->actingAs($user, 'ayni');
    if ($device !== null) {
        $req = $req->withCredentials()->withUnencryptedCookie(QuickUnlock::cookieName(), $device);
    }

    return $req->withHeaders(['X-Tenant-Id' => QU_TENANT, 'Idempotency-Key' => (string) Str::uuid()])->json($method, '/api/v1'.$path, $body);
}

function quDeviceCookie($response): string
{
    $cookie = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === QuickUnlock::cookieName());
    expect($cookie)->not->toBeNull()->and($cookie->isHttpOnly())->toBeTrue();

    return (string) $cookie->getValue();
}

test('activar el PIN deja la cookie HttpOnly del teléfono y con PIN correcto se reingresa', function (): void {
    quCall($this->worker, 'POST', '/me/quick-unlock', ['pin' => '1111', 'pin_confirmation' => '1111'])->assertStatus(422);
    quCall($this->worker, 'POST', '/me/quick-unlock', ['pin' => '1234', 'pin_confirmation' => '1234'])->assertStatus(422);

    $enrolled = quCall($this->worker, 'POST', '/me/quick-unlock', ['pin' => '4827', 'pin_confirmation' => '4827']);
    $enrolled->assertCreated();
    $this->assertMatchesOpenApi($enrolled, 'post', '/me/quick-unlock', 201);
    $device = quDeviceCookie($enrolled);

    // En base no hay ni el token ni el PIN en claro.
    $row = TenancyScope::runAs(QU_TENANT, fn () => DB::table('user_quick_unlocks')->first());
    expect($row->device_token_hash)->not->toBe($device)->and($row->pin_hash)->not->toContain('4827');

    $status = quCall(null, 'GET', '/auth/quick-unlock', [], $device);
    $status->assertOk()->assertJsonPath('data.available', true)->assertJsonPath('data.display_name', 'Juan Quispe');
    $this->assertMatchesOpenApi($status, 'get', '/auth/quick-unlock', 200);
    quCall(null, 'GET', '/auth/quick-unlock', [], '')->assertJsonPath('data.available', false);

    $in = quCall(null, 'POST', '/auth/quick-unlock/sign-in', ['pin' => '4827'], $device);
    $in->assertOk()->assertJsonPath('data.user.id', $this->worker->id);
    $this->assertMatchesOpenApi($in, 'post', '/auth/quick-unlock/sign-in', 200);
    expect(collect($in->headers->getCookies())->map(fn ($c) => $c->getName())->all())->toContain((string) config('identity.session_cookie'));
    $audited = TenancyScope::runAs(QU_TENANT, fn () => DB::table('audit_events')->where('action', 'auth.quick_unlock_used')->exists());
    expect($audited)->toBeTrue();
});

test('varios PIN errados olvidan el teléfono; sin el teléfono el PIN no sirve', function (): void {
    $device = quDeviceCookie(quCall($this->worker, 'POST', '/me/quick-unlock', ['pin' => '4827', 'pin_confirmation' => '4827']));
    quCall(null, 'POST', '/auth/quick-unlock/sign-in', ['pin' => '4827'])->assertStatus(401);
    for ($i = 0; $i < 4; $i++) {
        quCall(null, 'POST', '/auth/quick-unlock/sign-in', ['pin' => '0000'], $device)->assertStatus(422);
    }
    quCall(null, 'POST', '/auth/quick-unlock/sign-in', ['pin' => '0000'], $device)->assertStatus(422);
    // Al quinto error (seguridad.pin_intentos) el teléfono lo olvida: ni el PIN correcto entra.
    quCall(null, 'POST', '/auth/quick-unlock/sign-in', ['pin' => '4827'], $device)->assertStatus(401);
});

test('el PIN caduca, se puede olvidar y no se ofrece si la organización lo desactiva', function (): void {
    $device = quDeviceCookie(quCall($this->worker, 'POST', '/me/quick-unlock', ['pin' => '4827', 'pin_confirmation' => '4827']));
    $forget = quCall(null, 'POST', '/auth/quick-unlock/forget', [], $device);
    $forget->assertNoContent();
    quCall(null, 'POST', '/auth/quick-unlock/sign-in', ['pin' => '4827'], $device)->assertStatus(401);

    $device = quDeviceCookie(quCall($this->worker, 'POST', '/me/quick-unlock', ['pin' => '4827', 'pin_confirmation' => '4827']));
    TenancyScope::runAs(QU_TENANT, fn () => DB::table('user_quick_unlocks')->update(['expires_at' => now()->subMinute()]));
    quCall(null, 'POST', '/auth/quick-unlock/sign-in', ['pin' => '4827'], $device)->assertStatus(401);

    TenancyScope::runAs(QU_TENANT, fn () => DB::table('tenant_settings')->insert(['tenant_id' => QU_TENANT, 'parameter_code' => 'seguridad.pin_dias_vigencia', 'scope_kind' => 'organization', 'value' => '0']));
    quCall($this->worker, 'POST', '/me/quick-unlock', ['pin' => '4827', 'pin_confirmation' => '4827'])->assertStatus(422);
});
