<?php

/**
 * SEG-11 · Cifrado de datos sensibles y registro de acceso.
 */

use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Identity\Infrastructure\Models\UserIdentity;
use App\Shared\Permissions\SensitiveFields;
use App\Shared\Security\FieldCipher;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Support\IdentityFixtures;

uses(DatabaseTransactions::class);

const FEN_TENANT = 'aaaaaaaa-5e11-4000-8000-00000000000a';
const FEN_OTHER = 'bbbbbbbb-5e11-4000-8000-00000000000b';

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requiere PostgreSQL.');
    }

    TenancyScope::clear();
    DB::table('tenants')->insertOrIgnore([
        ['id' => FEN_TENANT, 'slug' => 'fen-org-a', 'name' => 'Fen A', 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN'],
        ['id' => FEN_OTHER, 'slug' => 'fen-org-b', 'name' => 'Fen B', 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN'],
    ]);
    DB::select('SELECT seed_tenant_defaults(?)', [FEN_TENANT]);
    DB::select('SELECT seed_tenant_defaults(?)', [FEN_OTHER]);
    config(['security.field_keys' => '', 'security.field_active_key' => null]);
});

afterEach(function (): void {
    TenancyScope::clear();
});

function fenKey(): string
{
    return 'base64:'.base64_encode(random_bytes(32));
}

test('el identificador de ingreso no es legible en la base y se recupera con la clave', function (): void {
    $email = 'sueldo.secreto@fen.test';
    $user = TenancyScope::runAs(FEN_TENANT, fn () => User::factory()->withEmail($email)->create());

    TenancyScope::runAs(FEN_TENANT, function () use ($user, $email): void {
        $raw = (string) DB::table('user_identities')->where('user_id', $user->id)->where('kind', 'email')->value('identifier_enc');
        expect($raw)->toStartWith('fe1:')->not->toContain('sueldo.secreto')->not->toContain(base64_encode($email))
            ->and(UserIdentity::query()->where('user_id', $user->id)->where('kind', 'email')->sole()->identifier())->toBe($email);
    });
});

test('un valor alterado o cifrado con una clave ausente no se descifra', function (): void {
    $enc = FieldCipher::encrypt('12345678');
    expect(FieldCipher::decrypt($enc))->toBe('12345678');

    $tampered = substr($enc, 0, -4).(str_ends_with($enc, 'AAA=') ? 'BBB=' : 'AAA=');
    expect(fn () => FieldCipher::decrypt($tampered))->toThrow(DecryptException::class);

    // Mover el valor a otro id de clave invalida la etiqueta (el id va como dato autenticado).
    config(['security.field_keys' => 'k1:'.fenKey()]);
    $moved = 'fe1:k1:'.explode(':', $enc, 3)[2];
    expect(fn () => FieldCipher::decrypt($moved))->toThrow(DecryptException::class)
        ->and(fn () => FieldCipher::decrypt('fe1:nope:'.explode(':', $enc, 3)[2]))->toThrow(DecryptException::class);
});

test('la clave activa debe existir en el anillo', function (): void {
    config(['security.field_keys' => 'k1:'.fenKey(), 'security.field_active_key' => 'k9']);
    expect(fn () => FieldCipher::encrypt('x'))->toThrow(RuntimeException::class);
});

test('rotación de claves: de k1 a k2 y desde el formato anterior, sin perder datos', function (): void {
    $k1 = 'k1:'.fenKey();
    $k2 = 'k2:'.fenKey();
    config(['security.field_keys' => $k1, 'security.field_active_key' => 'k1']);

    $ids = TenancyScope::runAs(FEN_TENANT, function (): array {
        $user = User::factory()->withEmail('rota.k1@fen.test')->create();
        $legacy = (string) Str::uuid();
        DB::table('user_identities')->insert([
            'id' => $legacy, 'tenant_id' => FEN_TENANT, 'user_id' => $user->id, 'kind' => 'username',
            'identifier_hash' => hash('sha256', 'legacy-'.$legacy), 'identifier_enc' => Crypt::encryptString('usuario.antiguo'), 'is_primary' => false,
        ]);

        return ['user' => $user->id, 'legacy' => $legacy];
    });

    // La clave nueva se agrega al anillo y pasa a ser la activa.
    config(['security.field_keys' => $k1.','.$k2, 'security.field_active_key' => 'k2']);
    $this->artisan('security:rotate-field-keys', ['--dry-run' => true])->expectsOutputToContain('Rotados: 0 (simulación)')->assertSuccessful();
    TenancyScope::runAs(FEN_TENANT, fn () => expect(FieldCipher::keyIdOf((string) DB::table('user_identities')->where('user_id', $ids['user'])->where('kind', 'email')->value('identifier_enc')))->toBe('k1'));

    $this->artisan('security:rotate-field-keys')->assertSuccessful();
    $this->artisan('security:rotate-field-keys', ['--dry-run' => true])->expectsOutputToContain('Pendientes: 0')->assertSuccessful();

    // Sin k1 en el anillo, todo sigue legible con k2.
    config(['security.field_keys' => $k2, 'security.field_active_key' => 'k2']);
    TenancyScope::runAs(FEN_TENANT, function () use ($ids): void {
        $rows = DB::table('user_identities')->where('user_id', $ids['user'])->pluck('identifier_enc', 'kind');
        expect(FieldCipher::keyIdOf((string) $rows['email']))->toBe('k2')
            ->and(FieldCipher::keyIdOf((string) $rows['username']))->toBe('k2')
            ->and(FieldCipher::decrypt((string) $rows['email']))->toBe('rota.k1@fen.test')
            ->and(FieldCipher::decrypt((string) $rows['username']))->toBe('usuario.antiguo');
    });
});

test('consultar el sueldo deja un registro de acceso consultable; sin permiso no se ve el registro', function (): void {
    $reader = IdentityFixtures::userWithPermissions(FEN_TENANT, ['people.employments.view', 'people.employments.salary.view']);
    $auditor = IdentityFixtures::userWithPermissions(FEN_TENANT, ['audit.sensitive_access.view']);
    $plain = IdentityFixtures::userWithPermissions(FEN_TENANT, ['people.employments.view']);
    $outsider = IdentityFixtures::userWithPermissions(FEN_OTHER, ['audit.sensitive_access.view']);
    $employmentId = (string) Str::uuid();

    Route::middleware(['api', 'auth:ayni', 'permission:people.employments.view'])->get('/api/v1/__seg11-salary', function (Request $request) use ($employmentId) {
        $row = ['id' => $employmentId, 'base_salary' => ['amount' => '4200.00', 'currency' => 'PEN']];

        return response()->json(['data' => app(SensitiveFields::class)->present('Employment', $row, $request->attributes->get('effective_permissions'))]);
    });

    $get = function (User $u, string $path) {
        TenancyScope::clear();
        app('auth')->forgetGuards();

        return test()->actingAs($u, 'ayni')->withHeaders(['X-Tenant-Id' => $u->tenant_id])->getJson('/api/v1'.$path);
    };

    $get($reader, '/__seg11-salary')->assertOk()->assertJsonPath('data.base_salary.amount', '4200.00');

    $logs = $get($auditor, '/sensitive-access-logs?filter[category]=salary')->assertOk();
    $this->assertMatchesOpenApi($logs, 'get', '/sensitive-access-logs', 200);
    $mine = collect($logs->json('data'))->firstWhere('actor_id', $reader->id);
    expect($mine)->not->toBeNull()->and($mine['fields'])->toBe(['base_salary'])->and($mine['entity_id'])->toBe($employmentId);

    $forbidden = $get($plain, '/sensitive-access-logs')->assertForbidden();
    $this->assertMatchesOpenApi($forbidden, 'get', '/sensitive-access-logs', 403);

    // Aislamiento: otra organización no ve el acceso.
    $other = $get($outsider, '/sensitive-access-logs?filter[category]=salary')->assertOk();
    expect(collect($other->json('data'))->pluck('actor_id')->all())->not->toContain($reader->id);

    $invalid = $get($auditor, '/sensitive-access-logs?filter[category]=nada')->assertStatus(400)->assertJsonPath('error.code', 'invalid_filter');
    $this->assertMatchesOpenApi($invalid, 'get', '/sensitive-access-logs', 400);
});
