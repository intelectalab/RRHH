<?php

/**
 * CON-1 · Tipos de contrato y modalidades: catálogo del paquete Perú, plazos leídos de parámetros
 * legales con vigencia (nunca del código) y tipos propios de la organización.
 */

use App\Modules\Contracts\Application\ContractTypeCatalog;
use App\Modules\Contracts\Infrastructure\Models\ContractType;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Shared\LegalParameters\LegalParameters;
use App\Shared\Permissions\PermissionResolver;
use App\Shared\Tenancy\TenancyScope;
use App\Shared\Vigencia\EffectiveDated;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\IdentityFixtures;

uses(DatabaseTransactions::class);

const CT_TENANT = 'aaaaaaaa-c0c1-4000-8000-00000000000a';
const CT_OTHER = 'bbbbbbbb-c0c1-4000-8000-00000000000b';

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requiere PostgreSQL.');
    }
    TenancyScope::clear();
    foreach ([CT_TENANT => 'ct-org', CT_OTHER => 'ct-otra'] as $id => $slug) {
        DB::table('tenants')->insertOrIgnore(['id' => $id, 'slug' => $slug, 'name' => 'Grupo '.$slug, 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN']);
        DB::select('SELECT seed_tenant_defaults(?)', [$id]);
        TenancyScope::runAs($id, fn () => IdentityFixtures::enableAllModules());
    }
    app(LegalParameters::class)->sync('PE');
    $this->admin = ctUser(CT_TENANT, 'admin_empresa');
});

afterEach(fn () => TenancyScope::clear());

function ctUser(string $tenant, string $role): User
{
    return TenancyScope::runAs($tenant, function () use ($role): User {
        $u = User::factory()->create();
        $u->roleAssignments()->create(['role_id' => Role::query()->where('system_role_code', $role)->value('id')]);

        return $u;
    });
}

function ctCall(User $user, string $method, string $path, array $body = [])
{
    TenancyScope::clear();
    app('auth')->forgetGuards();
    app(PermissionResolver::class)->forget();

    return test()->actingAs($user, 'ayni')->withHeaders(['X-Tenant-Id' => $user->tenant_id, 'Idempotency-Key' => (string) Str::uuid()])->json($method, '/api/v1'.$path, $body);
}

function ctNum(mixed $v): string
{
    $s = (string) $v;

    return str_contains($s, '.') ? rtrim(rtrim($s, '0'), '.') : $s;
}

/**
 * @return array<string, array<string, mixed>>
 */
function ctByCode(TestResponse $r): array
{
    return collect($r->json('data'))->keyBy('code')->all();
}

test('el catálogo del paquete Perú trae indeterminado, modalidades, tiempo parcial y convenios con plazos desde parámetros', function (): void {
    $r = ctCall($this->admin, 'GET', '/contract-types');
    $r->assertOk();
    $this->assertMatchesOpenApi($r, 'get', '/contract-types', 200);
    $types = ctByCode($r);

    $codes = array_column(ContractTypeCatalog::countryTypes('PE'), 'code');
    expect(array_keys($types))->toEqualCanonicalizing($codes)
        ->and(collect($types)->pluck('category')->unique()->sort()->values()->all())->toBe(['fixed_term', 'indefinite', 'internship', 'part_time']);

    // Modalidad: exige fin y causa objetiva; el plazo sale del parámetro legal, no del tipo.
    $inicio = $types['inicio_actividad'];
    expect($inicio['is_system'])->toBeTrue()
        ->and($inicio['requires_end_date'])->toBeTrue()
        ->and($inicio['requires_objective_cause'])->toBeTrue()
        ->and($inicio['max_duration_parameter_code'])->toBe('contrato_inicio_actividad_max_anios')
        ->and($inicio['max_duration']['unit'])->toBe('years')
        ->and($inicio['max_duration']['value'])->toBe(ctNum(DB::table('legal_parameters')->where('code', 'contrato_inicio_actividad_max_anios')->value('value_numeric')))
        ->and($inicio['max_duration']['pending_validation'])->toBeFalse();

    // Periodo de prueba sin valor verificado en el paquete → «por validar», sin número inventado.
    expect($inicio['trial_period']['parameter_code'])->toBe('periodo_prueba_meses')
        ->and($inicio['trial_period']['value'])->toBeNull()
        ->and($inicio['trial_period']['pending_validation'])->toBeTrue()
        ->and($types['indeterminado']['requires_end_date'])->toBeFalse()
        ->and($types['indeterminado']['max_duration'])->toBeNull()
        ->and($types['practicas_profesionales']['trial_period'])->toBeNull();

    // Idempotente: listar otra vez no duplica.
    expect(ctCall($this->admin, 'GET', '/contract-types')->json('data'))->toHaveCount(count($codes));

    $one = ctCall($this->admin, 'GET', '/contract-types/'.$inicio['id']);
    $one->assertOk()->assertJsonPath('data.code', 'inicio_actividad');
    $this->assertMatchesOpenApi($one, 'get', '/contract-types/{id}', 200);
});

test('el plazo máximo se resuelve con la vigencia del parámetro en fecha pasada, presente y futura', function (): void {
    $relation = ['country_code' => 'PE', 'labor_regime_code' => null, 'code' => 'contrato_inicio_actividad_max_anios'];
    $current = DB::table('legal_parameters')->where('code', $relation['code'])->first();
    $from = (string) $current->valid_from;
    $future = now()->addYear()->startOfMonth()->toDateString();
    // Un cambio futuro del parámetro (cierra la vigencia actual, no la sobrescribe).
    app(EffectiveDated::class)->change('legal_parameters', $relation, [
        'name' => $current->name, 'unit' => 'years', 'value_numeric' => '7', 'verified' => false, 'legal_source' => 'Prueba de vigencia',
    ], $future);
    $typeId = collect(ctCall($this->admin, 'GET', '/contract-types')->json('data'))->firstWhere('code', 'inicio_actividad')['id'];

    $before = ctCall($this->admin, 'GET', '/contract-types/'.$typeId.'?as_of='.Carbon\Carbon::parse($from)->subDay()->toDateString());
    $before->assertOk();
    expect($before->json('data.max_duration.value'))->toBeNull()->and($before->json('data.max_duration.pending_validation'))->toBeTrue();

    $now = ctCall($this->admin, 'GET', '/contract-types/'.$typeId);
    expect($now->json('data.max_duration.value'))->toBe(ctNum($current->value_numeric))
        ->and($now->json('data.max_duration.valid_from'))->toBe($from);

    $later = ctCall($this->admin, 'GET', '/contract-types/'.$typeId.'?as_of='.$future);
    expect($later->json('data.max_duration.value'))->toBe('7')
        ->and($later->json('data.max_duration.valid_from'))->toBe($future)
        ->and($later->json('data.max_duration.pending_validation'))->toBeTrue();

    // La vigencia anterior sigue intacta, cerrada el día previo.
    expect(DB::table('legal_parameters')->where('id', $current->id)->value('valid_to'))->toBe(Carbon\Carbon::parse($future)->subDay()->toDateString());

    ctCall($this->admin, 'GET', '/contract-types?as_of=2026-13-40')->assertStatus(422);
});

test('la organización agrega y edita sus tipos propios; los del paquete son de solo lectura', function (): void {
    $created = ctCall($this->admin, 'POST', '/contract-types', [
        'code' => 'proyecto_minero', 'name' => 'Proyecto minero', 'category' => 'fixed_term', 'modality_code' => 'obra_servicio',
        'requires_end_date' => true, 'requires_objective_cause' => true, 'max_duration_parameter_code' => 'contrato_necesidad_mercado_max_anios',
    ]);
    $created->assertCreated()->assertJsonPath('data.is_system', false)->assertJsonPath('data.max_duration.unit', 'years');
    $this->assertMatchesOpenApi($created, 'post', '/contract-types', 201);
    $id = $created->json('data.id');

    // Un plazo solo puede venir de un parámetro legal del país, nunca como número del tipo.
    ctCall($this->admin, 'POST', '/contract-types', ['code' => 'otro', 'name' => 'Otro', 'category' => 'other', 'max_duration_parameter_code' => 'inventado'])
        ->assertStatus(422);
    $bad = ctCall($this->admin, 'POST', '/contract-types', ['code' => 'Con Espacios', 'name' => '', 'category' => 'temporal']);
    $bad->assertStatus(422);
    $this->assertMatchesOpenApi($bad, 'post', '/contract-types', 422);
    ctCall($this->admin, 'POST', '/contract-types', ['code' => 'proyecto_minero', 'name' => 'Repetido', 'category' => 'other'])->assertStatus(409);
    ctCall($this->admin, 'POST', '/contract-types', ['code' => 'indeterminado', 'name' => 'Repetido', 'category' => 'other'])->assertStatus(409);

    $patched = ctCall($this->admin, 'PATCH', '/contract-types/'.$id, ['name' => 'Proyecto minero (obra)', 'max_duration_parameter_code' => null]);
    $patched->assertOk()->assertJsonPath('data.name', 'Proyecto minero (obra)')->assertJsonPath('data.max_duration', null);
    $this->assertMatchesOpenApi($patched, 'patch', '/contract-types/{id}', 200);

    $system = TenancyScope::runAs(CT_TENANT, fn () => ContractType::query()->where('code', 'inicio_actividad')->value('id'));
    $ro = ctCall($this->admin, 'PATCH', '/contract-types/'.$system, ['name' => 'Cambiado']);
    $ro->assertStatus(422)->assertJsonPath('error.meta.rule', 'contract_type_is_system');
    $this->assertMatchesOpenApi($ro, 'patch', '/contract-types/{id}', 422);

    TenancyScope::runAs(CT_TENANT, function () use ($id): void {
        $audit = DB::table('audit_events')->where('entity_id', $id)->orderBy('occurred_at')->pluck('action')->all();
        expect($audit)->toContain('contract_type.created')->toContain('contract_type.updated');
        expect(ContractType::query()->where('code', 'inicio_actividad')->value('name'))->toBe('Por inicio o incremento de actividad');
    });
});

test('sin permiso responde 403 y con el módulo apagado 403 module_not_enabled', function (): void {
    $employee = ctUser(CT_TENANT, 'empleado');
    $denied = ctCall($employee, 'GET', '/contract-types');
    $denied->assertStatus(403)->assertJsonPath('error.meta.permission', 'contracts.contract_types.view');
    $this->assertMatchesOpenApi($denied, 'get', '/contract-types', 403);

    // RRHH ve el catálogo pero no crea tipos.
    $hr = ctUser(CT_TENANT, 'rrhh');
    ctCall($hr, 'GET', '/contract-types')->assertOk();
    ctCall($hr, 'POST', '/contract-types', ['code' => 'x', 'name' => 'X', 'category' => 'other'])->assertStatus(403);

    IdentityFixtures::disableModule(CT_TENANT, 'core');
    ctCall($this->admin, 'GET', '/contract-types')->assertStatus(403)->assertJsonPath('error.code', 'module_not_enabled');
});

test('un tipo de otra organización responde 404', function (): void {
    $own = ctCall($this->admin, 'POST', '/contract-types', ['code' => 'solo_a', 'name' => 'Solo A', 'category' => 'other'])->json('data.id');
    $intruder = ctUser(CT_OTHER, 'admin_empresa');

    ctCall($intruder, 'GET', '/contract-types/'.$own)->assertNotFound();
    ctCall($intruder, 'PATCH', '/contract-types/'.$own, ['name' => 'Tomado'])->assertNotFound();
    expect(collect(ctCall($intruder, 'GET', '/contract-types')->json('data'))->pluck('code'))->not->toContain('solo_a');
});

test('la carga de parámetros legales es idempotente y no pisa un valor existente', function (): void {
    $sync = app(LegalParameters::class);
    $count = DB::table('legal_parameters')->where('country_code', 'PE')->count();
    expect($count)->toBeGreaterThan(0)->and($sync->sync('PE'))->toBe(['inserted' => 0, 'updated' => 0])
        ->and(DB::table('legal_parameters')->where('country_code', 'PE')->count())->toBe($count);

    // La copia del paquete coincide con el catálogo generado del kit (si el kit está disponible).
    $kit = base_path('../../kit/database/catalogos/parametros_legales_pe.json');
    if (is_file($kit)) {
        expect(json_decode((string) file_get_contents(base_path('packages/country-pe/data/parametros_legales_pe.json')), true))
            ->toBe(json_decode((string) file_get_contents($kit), true));
    }
});
