<?php

/**
 * NOM-1 a NOM-5 · Motor de nómina por la API y por su servicio: regímenes con vigencia por trabajador,
 * revisión de fórmulas, orden de cálculo con ciclos, banco de pruebas (cálculo por tramos, traza, nada se
 * guarda, montos solo con permiso) y corrida guardada con su contexto para auditoría.
 */

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Payroll\Application\PayrollEngine;
use App\Shared\Permissions\ModuleGate;
use App\Shared\Permissions\PermissionResolver;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\IdentityFixtures;
use Tests\Support\PayrollFixtures;

uses(DatabaseTransactions::class);

const NOM_TENANT = 'aaaaaaaa-0e01-4000-8000-00000000000a';
const NOM_OTHER = 'bbbbbbbb-0e01-4000-8000-00000000000b';

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requiere PostgreSQL.');
    }
    TenancyScope::clear();
    foreach ([NOM_TENANT => 'nom-org', NOM_OTHER => 'nom-otra'] as $id => $slug) {
        DB::table('tenants')->insertOrIgnore(['id' => $id, 'slug' => $slug, 'name' => 'Grupo '.$slug, 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN']);
        DB::select('SELECT seed_tenant_defaults(?)', [$id]);
        TenancyScope::runAs($id, fn () => IdentityFixtures::enableAllModules());
    }
    $this->payroll = nomUser(NOM_TENANT, 'payroll');
    $this->admin = nomUser(NOM_TENANT, 'admin_empresa');
    $this->employee = nomUser(NOM_TENANT, 'empleado');
    $this->other = nomUser(NOM_OTHER, 'admin_empresa');
    [$this->entity, $this->employment] = TenancyScope::runAs(NOM_TENANT, function (): array {
        $entity = PayrollFixtures::entity();
        $employment = PayrollFixtures::employment($entity, '2025-01-01', [
            ['amount' => '3000.00', 'valid_from' => '2025-01-01', 'valid_to' => '2026-03-15'],
            ['amount' => '3600.00', 'valid_from' => '2026-03-16'],
        ]);
        PayrollFixtures::parameter('dias_base_mes', '30');
        PayrollFixtures::concept(['code' => 'BASICO', 'kind' => 'earning', 'formula' => 'sueldo_tramos', 'sort_order' => 1]);
        PayrollFixtures::concept(['code' => 'DESC', 'kind' => 'deduction', 'formula' => 'redondear(concepto.BASICO / 10, 2)', 'sort_order' => 2]);
        PayrollFixtures::concept(['code' => 'MICRO', 'kind' => 'earning', 'formula' => '100', 'regimes' => ['pe_micro']]);

        return [$entity, $employment];
    });
});

afterEach(fn () => TenancyScope::clear());

function nomUser(string $tenant, string $role): User
{
    return TenancyScope::runAs($tenant, function () use ($role): User {
        $u = User::factory()->create();
        $u->roleAssignments()->create(['role_id' => Role::query()->where('system_role_code', $role)->value('id')]);

        return $u;
    });
}

/** @param array<string, mixed> $body */
function nomCall(User $user, string $method, string $path, array $body = []): TestResponse
{
    TenancyScope::clear();
    app('auth')->forgetGuards();
    app(PermissionResolver::class)->forget();
    app(ModuleGate::class)->forget();
    test()->flushHeaders();

    return test()->actingAs($user, 'ayni')
        ->withHeaders(['X-Tenant-Id' => $user->tenant_id, 'Idempotency-Key' => (string) Str::uuid(), 'Accept' => 'application/json'])
        ->json($method, '/api/v1'.$path, $body);
}

test('NOM-2 · los regímenes vienen del país con la estrategia del paquete; construcción y agrario aún no se soportan', function (): void {
    $r = nomCall($this->payroll, 'GET', '/payroll-regimes')->assertOk();
    $this->assertMatchesOpenApi($r, 'get', '/payroll-regimes', 200);
    $byCode = collect($r->json('data'))->keyBy('code');
    expect($byCode['pe_general']['supported'])->toBeTrue()->and($byCode['pe_general']['rules']['proration_parameter'])->toBe('dias_base_mes')
        ->and($byCode['pe_micro']['rules']['requires_accreditation'])->toBeTrue()
        ->and($byCode['pe_construccion']['supported'])->toBeFalse();
    $one = nomCall($this->payroll, 'GET', '/payroll-regimes/'.$byCode['pe_general']['id'])->assertOk();
    $this->assertMatchesOpenApi($one, 'get', '/payroll-regimes/{id}', 200);
    nomCall($this->employee, 'GET', '/payroll-regimes')->assertStatus(403);
});

test('NOM-2 · el régimen se hereda de la empresa y cambia por trabajador con fecha efectiva; micro exige acreditación', function (): void {
    $r = nomCall($this->payroll, 'GET', "/employments/{$this->employment}/payroll-regime?as_of=2026-03-01")->assertOk();
    $this->assertMatchesOpenApi($r, 'get', '/employments/{employment_id}/payroll-regime', 200);
    expect($r->json('data.labor_regime_code'))->toBe('pe_general')->and($r->json('data.source'))->toBe('legal_entity');
    // El ingreso registra el régimen propio del trabajador (el de la empresa a esa fecha).
    TenancyScope::runAs(NOM_TENANT, fn () => DB::table('employment_labor_regimes')->insert(['tenant_id' => NOM_TENANT, 'employment_id' => $this->employment,
        'labor_regime_code' => 'pe_general', 'valid_from' => '2025-01-01']));

    // RN-NOM-19: la empresa no tiene acreditación de microempresa.
    $denied = nomCall($this->admin, 'POST', "/employments/{$this->employment}/payroll-regime", ['labor_regime_code' => 'pe_micro', 'valid_from' => '2026-04-01', 'reason' => 'Prueba'])
        ->assertStatus(422);
    $this->assertMatchesOpenApi($denied, 'post', '/employments/{employment_id}/payroll-regime', 422);
    expect($denied->json('error.code'))->toBe('regime_not_accredited');

    TenancyScope::runAs(NOM_TENANT, fn () => DB::table('legal_entity_regimes')->where('legal_entity_id', $this->entity)
        ->update(['labor_regime_code' => 'pe_micro', 'accreditation_status' => 'accredited']));
    $ok = nomCall($this->admin, 'POST', "/employments/{$this->employment}/payroll-regime", ['labor_regime_code' => 'pe_micro', 'valid_from' => '2026-04-01', 'reason' => 'Ingresa a la REMYPE'])
        ->assertStatus(201);
    $this->assertMatchesOpenApi($ok, 'post', '/employments/{employment_id}/payroll-regime', 201);
    expect($ok->json('data.labor_regime_code'))->toBe('pe_micro')->and($ok->json('data.source'))->toBe('employment');

    // Dos trabajadores de la misma empresa en regímenes distintos; el régimen a la fecha decide qué conceptos aplican.
    $other = TenancyScope::runAs(NOM_TENANT, fn () => PayrollFixtures::employment($this->entity, '2025-01-01', [['amount' => '2000.00', 'valid_from' => '2025-01-01']], 'pe_general'));
    expect(nomCall($this->payroll, 'GET', "/employments/{$other}/payroll-regime?as_of=2026-04-10")->json('data.labor_regime_code'))->toBe('pe_general');
    $april = TenancyScope::runAs(NOM_TENANT, fn () => app(PayrollEngine::class)->calculate($this->employment, '2026-04-01', '2026-04-30'));
    $march = TenancyScope::runAs(NOM_TENANT, fn () => app(PayrollEngine::class)->calculate($this->employment, '2026-03-01', '2026-03-31'));
    expect(array_column($april['result']['lines'], 'concept_code'))->toContain('MICRO')
        ->and(array_column($march['result']['lines'], 'concept_code'))->not->toContain('MICRO');

    // Auditoría y aislamiento.
    expect(TenancyScope::runAs(NOM_TENANT, fn () => DB::table('audit_events')->where('action', 'employment.labor_regime_changed')->where('entity_id', $this->employment)->exists()))->toBeTrue();
    nomCall($this->other, 'GET', "/employments/{$this->employment}/payroll-regime")->assertStatus(404);
    nomCall($this->other, 'POST', "/employments/{$this->employment}/payroll-regime", ['labor_regime_code' => 'pe_general', 'valid_from' => '2026-05-01', 'reason' => 'x'])->assertStatus(404);
    nomCall($this->admin, 'POST', "/employments/{$this->employment}/payroll-regime", ['labor_regime_code' => 'pe_general'])->assertStatus(422);
});

test('NOM-3 · una fórmula inválida o con referencias inexistentes no se puede guardar; un ciclo se rechaza', function (): void {
    $ok = nomCall($this->payroll, 'POST', '/payroll-formulas/validate', ['formula' => 'redondear(sueldo_tramos * param.dias_base_mes / 30, 2) + concepto.BASICO', 'concept_code' => 'NUEVO'])->assertOk();
    $this->assertMatchesOpenApi($ok, 'post', '/payroll-formulas/validate', 200);
    expect($ok->json('data.valid'))->toBeTrue()->and($ok->json('data.references.concept'))->toBe(['BASICO'])
        ->and($ok->json('data.order'))->toContain('NUEVO');

    $syntax = nomCall($this->payroll, 'POST', '/payroll-formulas/validate', ['formula' => 'sueldo * (2 +'])->assertOk();
    expect($syntax->json('data.valid'))->toBeFalse()->and($syntax->json('data.errors.0.code'))->toBe('unexpected_end');

    $refs = nomCall($this->payroll, 'POST', '/payroll-formulas/validate', ['formula' => 'concepto.NO_EXISTE + param.no_existe + dato_raro'])->assertOk();
    expect(collect($refs->json('data.errors'))->pluck('code')->all())->toBe(['unknown_concept', 'unknown_variable', 'unknown_parameter']);

    // BASICO pasaría a depender de DESC, que ya depende de BASICO.
    $cycle = nomCall($this->payroll, 'POST', '/payroll-formulas/validate', ['formula' => 'concepto.DESC * 2', 'concept_code' => 'BASICO'])->assertOk();
    expect($cycle->json('data.valid'))->toBeFalse()->and($cycle->json('data.errors.0.code'))->toBe('cycle')
        ->and($cycle->json('data.errors.0.concepts'))->toBe(['BASICO', 'DESC']);

    nomCall($this->employee, 'POST', '/payroll-formulas/validate', ['formula' => '1'])->assertStatus(403);
    nomCall($this->payroll, 'POST', '/payroll-formulas/validate', [])->assertStatus(422);
});

test('NOM-4 · el orden de cálculo es determinista y consultable; un ciclo guardado responde 422 con los conceptos', function (): void {
    $r = nomCall($this->payroll, 'GET', '/payroll-calculation-order?as_of=2026-03-31&regime=pe_general')->assertOk();
    $this->assertMatchesOpenApi($r, 'get', '/payroll-calculation-order', 200);
    expect(array_column($r->json('data'), 'code'))->toBe(['BASICO', 'DESC'])->and($r->json('data.1.depends_on'))->toBe(['BASICO']);

    TenancyScope::runAs(NOM_TENANT, function (): void {
        PayrollFixtures::concept(['code' => 'CICLO_A', 'kind' => 'earning', 'formula' => 'concepto.CICLO_B']);
        PayrollFixtures::concept(['code' => 'CICLO_B', 'kind' => 'earning', 'formula' => 'concepto.CICLO_A + 1']);
    });
    $cycle = nomCall($this->payroll, 'GET', '/payroll-calculation-order?as_of=2026-03-31')->assertStatus(422);
    $this->assertMatchesOpenApi($cycle, 'get', '/payroll-calculation-order', 422);
    expect($cycle->json('error.code'))->toBe('cycle')->and($cycle->json('error.meta.concepts'))->toBe(['CICLO_A', 'CICLO_B']);
    nomCall($this->payroll, 'GET', '/payroll-calculation-order?as_of=31-03-2026')->assertStatus(400);
});

test('NOM-5 · el banco de pruebas calcula por tramos con traza, no guarda nada y solo muestra montos con permiso', function (): void {
    $before = TenancyScope::runAs(NOM_TENANT, fn () => DB::table('payroll_runs')->count());
    $r = nomCall($this->payroll, 'POST', '/payroll-test-bench', ['employment_id' => $this->employment, 'from' => '2026-03-01', 'to' => '2026-03-31'])->assertOk();
    $this->assertMatchesOpenApi($r, 'post', '/payroll-test-bench', 200);
    expect($r->json('data.status'))->toBe('calculated')->and($r->json('data.saved'))->toBeFalse()
        ->and($r->json('data.lines.0.amount'))->toBe(['amount' => '3300.00', 'currency' => 'PEN'])
        ->and($r->json('data.lines.1.amount.amount'))->toBe('330.00')
        ->and($r->json('data.totals.net'))->toBe('2970.00')
        ->and(count($r->json('data.context.segments')))->toBe(2)
        ->and($r->json('data.lines.1.trace.concepts'))->toBe(['BASICO' => '3300.00'])
        ->and($r->json('data.lines.0.trace.variables.sueldo_tramos'))->toBe('3300.0000000000')
        ->and($r->json('data.context.sources.proration_parameter.origin'))->toBe('organization');
    expect(TenancyScope::runAs(NOM_TENANT, fn () => DB::table('payroll_runs')->count()))->toBe($before)
        ->and(TenancyScope::runAs(NOM_TENANT, fn () => DB::table('data_access_logs')->exists()))->toBeTrue();

    // Caso hipotético: datos e incidencias propuestos sin tocar nada guardado.
    $hypo = nomCall($this->payroll, 'POST', '/payroll-test-bench', ['employment_id' => $this->employment, 'from' => '2026-03-01', 'to' => '2026-03-31',
        'variables' => ['sueldo_tramos' => '1000'], 'incidents' => [['concept_code' => 'BASICO', 'amount' => '5']]])->assertOk();
    expect($hypo->json('data.lines.0.amount.amount'))->toBe('1000.00');

    // Sin permiso de resultados: el cálculo corre pero montos, contexto y totales no viajan.
    $limited = IdentityFixtures::userWithPermissions(NOM_TENANT, ['payroll.concepts.test']);
    $hidden = nomCall($limited, 'POST', '/payroll-test-bench', ['employment_id' => $this->employment, 'from' => '2026-03-01', 'to' => '2026-03-31'])->assertOk();
    expect($hidden->json('data'))->not->toHaveKey('lines')->and($hidden->json('data'))->not->toHaveKey('context')->and($hidden->json('data.status'))->toBe('calculated');

    nomCall($this->other, 'POST', '/payroll-test-bench', ['employment_id' => $this->employment, 'from' => '2026-03-01', 'to' => '2026-03-31'])->assertStatus(404);
    nomCall($this->payroll, 'POST', '/payroll-test-bench', ['employment_id' => $this->employment, 'from' => '2026-01-01', 'to' => '2026-06-30'])->assertStatus(422);
    nomCall($this->employee, 'POST', '/payroll-test-bench', ['employment_id' => $this->employment, 'from' => '2026-03-01', 'to' => '2026-03-31'])->assertStatus(403);
    IdentityFixtures::disableModule(NOM_TENANT, 'payroll');
    $off = nomCall($this->payroll, 'POST', '/payroll-test-bench', ['employment_id' => $this->employment, 'from' => '2026-03-01', 'to' => '2026-03-31'])->assertStatus(403);
    expect($off->json('error.code'))->toBe('module_not_enabled');
});

test('NOM-5 · RN-NOM-10 · sin sueldo la relación queda con error y no detiene a las demás; la corrida guarda el contexto', function (): void {
    [$period, $broken] = TenancyScope::runAs(NOM_TENANT, function (): array {
        $broken = PayrollFixtures::employment($this->entity, '2025-01-01', []);
        $group = DB::table('payroll_groups')->insertGetId(['tenant_id' => NOM_TENANT, 'code' => 'G1', 'name' => 'Mensual', 'legal_entity_id' => $this->entity, 'currency' => 'PEN']);
        $period = DB::table('payroll_periods')->insertGetId(['tenant_id' => NOM_TENANT, 'payroll_group_id' => $group, 'period_year' => 2026, 'period_number' => 3,
            'starts_on' => '2026-03-01', 'ends_on' => '2026-03-31']);
        $bono = DB::table('payroll_concepts')->insertGetId(['tenant_id' => NOM_TENANT, 'code' => 'BONO', 'name' => 'Bono', 'kind' => 'earning']);
        DB::table('payroll_concept_versions')->insert(['tenant_id' => NOM_TENANT, 'payroll_concept_id' => $bono, 'valid_from' => '2020-01-01']);
        DB::table('payroll_incidents')->insert(['tenant_id' => NOM_TENANT, 'payroll_period_id' => $period, 'employment_id' => $this->employment,
            'payroll_concept_id' => $bono, 'amount' => '120.00', 'status' => 'approved']);

        return [(string) $period, $broken];
    });

    $runId = TenancyScope::runAs(NOM_TENANT, fn () => app(PayrollEngine::class)->storeRun($period, [$this->employment, $broken], 'simulation', null));
    $rows = TenancyScope::runAs(NOM_TENANT, fn () => DB::table('payroll_run_employments')->where('payroll_run_id', $runId)->get()->keyBy('employment_id'));
    $ok = $rows[$this->employment];
    $context = json_decode((string) $ok->context_snapshot, true);
    expect($ok->status)->toBe('calculated')->and($ok->gross_amount)->toBe('3420.00')
        ->and($context['incidents']['BONO']['amount'])->toBe('120.00')
        ->and($context['segments'])->toHaveCount(2)
        ->and($rows[$broken]->status)->toBe('error')
        ->and(json_decode((string) $rows[$broken]->error, true)[0]['code'])->toBe('missing_salary');
    $run = TenancyScope::runAs(NOM_TENANT, fn () => DB::table('payroll_runs')->where('id', $runId)->first());
    $lines = TenancyScope::runAs(NOM_TENANT, fn () => DB::table('payroll_run_lines')->where('payroll_run_id', $runId)->count());
    expect($run->status)->toBe('completed')->and($run->engine_version)->not->toBeNull()->and($lines)->toBe(3)
        ->and(collect(json_decode((string) $run->parameters_snapshot, true))->pluck('code')->all())->toBe(['dias_base_mes']);

    // RN-NOM-02: repetir con las mismas entradas da el mismo resultado.
    $a = TenancyScope::runAs(NOM_TENANT, fn () => app(PayrollEngine::class)->calculate($this->employment, '2026-03-01', '2026-03-31', 'regular', $period));
    $b = TenancyScope::runAs(NOM_TENANT, fn () => app(PayrollEngine::class)->calculate($this->employment, '2026-03-01', '2026-03-31', 'regular', $period));
    expect($a)->toBe($b);
});
