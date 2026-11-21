<?php

/**
 * IAR-11 · Casos de evaluación del asistente de ayuda, por rol, con el proveedor falso (corre en CI).
 * Cada caso: rol que pregunta, pregunta de referencia y artículos que se espera ver citados (basta uno). Falla si
 * no cita ninguno de los esperados o si cita un artículo que ese usuario no puede abrir (RN-AYU-01).
 * Umbral: todos los casos deben pasar; una pregunta nueva se agrega aquí junto con el artículo que la responde.
 */

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Platform\Application\Help\ProductHelpArticles;
use App\Shared\Cache\CacheVersions;
use App\Shared\Permissions\DataScope;
use App\Shared\Permissions\ModuleGate;
use App\Shared\Permissions\PermissionResolver;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\IdentityFixtures;

uses(DatabaseTransactions::class);

const IAE_TENANT = 'aaaaaaaa-1e1e-4000-8000-00000000000a';
const IAE_ROLES = ['admin_empresa', 'rrhh', 'payroll', 'manager', 'empleado', 'auditor'];

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requiere PostgreSQL.');
    }
    TenancyScope::clear();
    DB::table('feature_flags')->insertOrIgnore(['code' => 'ai_help_assistant', 'name' => 'Asistente de ayuda', 'default_enabled' => false, 'is_beta' => false, 'status' => 'active']);
    DB::table('tenants')->insertOrIgnore(['id' => IAE_TENANT, 'slug' => 'iae-org', 'name' => 'Grupo iae', 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN']);
    DB::select('SELECT seed_tenant_defaults(?)', [IAE_TENANT]);
    TenancyScope::runAs(IAE_TENANT, fn () => IdentityFixtures::enableAllModules());
    DB::table('tenant_feature_flags')->where('tenant_id', IAE_TENANT)->where('feature_flag_code', 'ai_help_assistant')->delete();
    DB::table('tenant_feature_flags')->insert(['tenant_id' => IAE_TENANT, 'feature_flag_code' => 'ai_help_assistant', 'enabled' => true]);
    app(CacheVersions::class)->bump('feature_flags');
    app(ProductHelpArticles::class)->syncBundled();
    $this->users = [];
    foreach (IAE_ROLES as $role) {
        $this->users[$role] = TenancyScope::runAs(IAE_TENANT, function () use ($role): User {
            $u = User::factory()->create(['locale' => 'es']);
            $u->roleAssignments()->create(['role_id' => Role::query()->where('system_role_code', $role)->value('id')]);

            return $u;
        });
    }
    $roleIds = TenancyScope::runAs(IAE_TENANT, fn (): array => Role::query()->whereIn('system_role_code', IAE_ROLES)->pluck('id')->map(fn ($id): string => (string) $id)->all());
    iaeCall($this->users['admin_empresa'], 'patch', '/ai/use-case-settings/help_assistant', ['enabled' => true, 'role_ids' => $roleIds])->assertOk();
});

afterEach(fn () => TenancyScope::clear());

/**
 * @param  array<string, mixed>  $body
 */
function iaeCall(User $user, string $method, string $path, array $body = []): TestResponse
{
    TenancyScope::clear();
    app('auth')->forgetGuards();
    app(PermissionResolver::class)->forget();
    app(ModuleGate::class)->forget();
    app(DataScope::class)->forget();

    return test()->actingAs($user, 'ayni')->withHeaders(['X-Tenant-Id' => $user->tenant_id, 'Idempotency-Key' => (string) Str::uuid(), 'Accept' => 'application/json'])
        ->json(strtoupper($method), '/api/v1'.$path, $body);
}

test('IAR-11 · pregunta de referencia por rol: cita lo esperado y solo lo que el usuario puede abrir', function (string $role, string $question, array $expected): void {
    $user = $this->users[$role];
    $res = iaeCall($user, 'post', '/ai/help-assistant/ask', ['question' => $question])->assertOk();
    test()->assertMatchesOpenApi($res, 'post', '/ai/help-assistant/ask', 200);
    $cited = array_column((array) $res->json('data.citations'), 'slug');
    expect($res->json('data.answered'))->toBeTrue("«{$question}» ({$role}) quedó sin respuesta");
    expect(array_intersect($expected, $cited))->not->toBeEmpty("«{$question}» ({$role}) citó: ".implode(', ', $cited));
    foreach ((array) $res->json('data.citations') as $c) {
        expect(iaeCall($user, 'get', '/help/articles/'.$c['id'])->status())->toBe(200, "«{$question}» ({$role}) citó «{$c['slug']}», que no puede abrir");
    }
})->with([
    'colaborador · boletas' => ['empleado', '¿Dónde veo mis boletas de pago?', ['pantalla-mis-boletas']],
    'colaborador · clave' => ['empleado', '¿Cómo cambio mi clave y activo el segundo factor?', ['pantalla-seguridad-cuenta']],
    'colaborador · constancia' => ['empleado', '¿Cómo pido una constancia de trabajo?', ['pantalla-mis-tramites', 'pantalla-constancias']],
    'colaborador · vacaciones' => ['empleado', '¿Cómo solicito vacaciones?', ['guia-vacaciones-y-ausencias', 'pantalla-vacaciones', 'campo.time.vacations.dates']],
    'rrhh · renovaciones' => ['rrhh', '¿Cómo renuevo los contratos por vencer?', ['pantalla-renovaciones', 'pantalla-contratos']],
    'rrhh · ficha' => ['rrhh', '¿Dónde veo la ficha y el legajo de un colaborador?', ['guia-colaboradores-y-legajo', 'pantalla-colaboradores']],
    'planilla · boletas' => ['payroll', '¿Cómo publico las boletas del periodo?', ['pantalla-boletas']],
    'jefe · equipo' => ['manager', '¿Dónde veo a las personas de mi equipo?', ['pantalla-mi-equipo', 'guia-jefe']],
    'administrador · configuración' => ['admin_empresa', '¿Qué configuro primero en la organización?', ['guia-administrador', 'pantalla-primeros-pasos', 'pantalla-configuracion']],
    'auditor · registro' => ['auditor', '¿Qué información puedo consultar como auditor?', ['guia-auditor', 'recorrido.auditor']],
]);

test('IAR-11 · el colaborador no recibe citas de ayuda que no puede abrir aunque pregunte por ella', function (): void {
    $user = $this->users['empleado'];
    $res = iaeCall($user, 'post', '/ai/help-assistant/ask', ['question' => '¿Cómo actualizo los parámetros legales y los conceptos de planilla?'])->assertOk();
    expect(array_column((array) $res->json('data.citations'), 'slug'))->not->toContain('pantalla-parametros-legales')->not->toContain('pantalla-conceptos-planilla');
    foreach ((array) $res->json('data.citations') as $c) {
        expect(iaeCall($user, 'get', '/help/articles/'.$c['id'])->status())->toBe(200);
    }
});
