<?php

/**
 * CPT-1 a CPT-5 · Conceptos de planilla: alta en borrador, revisión y publicación con doble control, versiones
 * con vigencia (el cálculo usa la de la fecha), retiro, afectaciones por base (base.pension), probador sobre
 * una persona real, contabilización con excepciones por centro de costo y el paquete Perú precargado.
 */

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Payroll\Application\ConceptAccounting;
use App\Modules\Payroll\Application\PayrollEngine;
use App\Modules\Payroll\Infrastructure\Setup\PayrollConceptsPackComponent;
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

const CPT_TENANT = 'aaaaaaaa-0c01-4000-8000-00000000000a';

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requiere PostgreSQL.');
    }
    TenancyScope::clear();
    DB::table('tenants')->insertOrIgnore(['id' => CPT_TENANT, 'slug' => 'cpt-org', 'name' => 'Grupo CPT', 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN']);
    DB::select('SELECT seed_tenant_defaults(?)', [CPT_TENANT]);
    TenancyScope::runAs(CPT_TENANT, fn () => IdentityFixtures::enableAllModules());
    $this->payroll = cptUser('payroll');
    $this->admin = cptUser('admin_empresa');
    $this->admin2 = cptUser('admin_empresa');
    $this->employee = cptUser('empleado');
    [$this->entity, $this->employment] = TenancyScope::runAs(CPT_TENANT, function (): array {
        $entity = PayrollFixtures::entity();
        $employment = PayrollFixtures::employment($entity, '2025-01-01', [['amount' => '3000.00', 'valid_from' => '2025-01-01']]);
        PayrollFixtures::parameter('dias_base_mes', '30');
        PayrollFixtures::parameter('cpt_tasa', '10');
        PayrollFixtures::concept(['code' => 'basico', 'kind' => 'earning', 'formula' => 'sueldo_tramos', 'sort_order' => 1]);

        return [$entity, $employment];
    });
});

afterEach(fn () => TenancyScope::clear());

function cptUser(string $role): User
{
    return TenancyScope::runAs(CPT_TENANT, function () use ($role): User {
        $u = User::factory()->create();
        $u->roleAssignments()->create(['role_id' => Role::query()->where('system_role_code', $role)->value('id')]);

        return $u;
    });
}

/** @param array<string, mixed> $body */
function cptCall(User $user, string $method, string $path, array $body = []): TestResponse
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

/** Líneas calculadas por el motor para la relación de prueba en el periodo. */
function cptLines(string $employment, string $from, string $to): array
{
    $calc = TenancyScope::runAs(CPT_TENANT, fn () => app(PayrollEngine::class)->calculate($employment, $from, $to));

    return collect($calc['result']['lines'])->mapWithKeys(fn ($l) => [$l['concept_code'] => $l['amount']['amount']])->all();
}

test('CPT-1 · CPT-2 · un concepto nuevo nace en borrador, se revisa, otro lo publica y el siguiente cálculo lo usa sin desplegar', function (): void {
    $created = cptCall($this->payroll, 'POST', '/payroll-concepts', [
        'code' => 'bono_productividad', 'name' => 'Bono de productividad', 'kind' => 'earning',
        'formula' => 'concepto.basico * param.cpt_tasa / 100', 'valid_from' => '2026-01-01',
        'affectations' => [['base_code' => 'pension'], ['base_code' => 'quinta', 'projection_kind' => 'ordinary']],
    ])->assertCreated();
    $this->assertMatchesOpenApi($created, 'post', '/payroll-concepts', 201);
    $id = $created->json('data.id');
    expect($created->json('data.status'))->toBe('draft')->and($created->json('data.has_draft'))->toBeTrue()
        ->and(cptLines($this->employment, '2026-01-01', '2026-01-31'))->not->toHaveKey('bono_productividad');

    $review = cptCall($this->payroll, 'POST', "/payroll-concepts/{$id}/validate")->assertOk();
    $this->assertMatchesOpenApi($review, 'post', '/payroll-concepts/{id}/validate', 200);
    expect($review->json('data.valid'))->toBeTrue()->and($review->json('data.depends_on'))->toBe(['basico']);

    // Payroll no publica (no tiene el permiso); el administrador sí.
    cptCall($this->payroll, 'POST', "/payroll-concepts/{$id}/publish")->assertForbidden();
    $published = cptCall($this->admin, 'POST', "/payroll-concepts/{$id}/publish")->assertOk();
    $this->assertMatchesOpenApi($published, 'post', '/payroll-concepts/{id}/publish', 200);
    expect($published->json('data.status'))->toBe('active')->and($published->json('data.has_draft'))->toBeFalse()
        ->and($published->json('data.valid_from'))->toBe('2026-01-01')
        ->and(collect($published->json('data.affectations'))->pluck('base_code')->all())->toBe(['pension', 'quinta']);
    expect(cptLines($this->employment, '2026-01-01', '2026-01-31'))->toMatchArray(['basico' => '3000.00', 'bono_productividad' => '300.00']);

    $list = cptCall($this->payroll, 'GET', '/payroll-concepts?as_of=2026-01-15')->assertOk();
    $this->assertMatchesOpenApi($list, 'get', '/payroll-concepts', 200);
    $show = cptCall($this->payroll, 'GET', "/payroll-concepts/{$id}")->assertOk();
    $this->assertMatchesOpenApi($show, 'get', '/payroll-concepts/{id}', 200);
    expect($show->json('data.versions'))->toHaveCount(1);
    TenancyScope::runAs(CPT_TENANT, fn () => expect(DB::table('outbox_events')->where('event_type', 'payroll_concept.version_published.v1')->where('aggregate_id', $id)->exists())->toBeTrue());

    cptCall($this->employee, 'GET', '/payroll-concepts')->assertForbidden();
    $dup = cptCall($this->payroll, 'POST', '/payroll-concepts', ['code' => 'bono_productividad', 'name' => 'Otro', 'kind' => 'earning'])->assertUnprocessable();
    $this->assertMatchesOpenApi($dup, 'post', '/payroll-concepts', 422);
});

test('CPT-2 · RN-CPT-04 · RN-CPT-07 · no se publica una fórmula con errores ni lo publica quien lo editó', function (): void {
    $id = cptCall($this->admin, 'POST', '/payroll-concepts', ['code' => 'malo', 'name' => 'Malo', 'kind' => 'earning',
        'formula' => 'concepto.no_existe + param.tampoco', 'valid_from' => '2026-01-01'])->assertCreated()->json('data.id');
    $review = cptCall($this->admin, 'POST', "/payroll-concepts/{$id}/validate")->assertOk();
    expect($review->json('data.valid'))->toBeFalse()
        ->and(collect($review->json('data.errors'))->pluck('code')->all())->toContain('unknown_concept', 'unknown_parameter');

    // Doble control: quien editó (admin) no publica, aunque tenga el permiso.
    $self = cptCall($this->admin, 'POST', "/payroll-concepts/{$id}/publish")->assertForbidden();
    expect($self->json('error.code'))->toBe('dual_control_required');
    $bad = cptCall($this->admin2, 'POST', "/payroll-concepts/{$id}/publish")->assertUnprocessable();
    $this->assertMatchesOpenApi($bad, 'post', '/payroll-concepts/{id}/publish', 422);
    expect($bad->json('error.code'))->toBe('concept_invalid');

    $fix = cptCall($this->admin, 'PATCH', "/payroll-concepts/{$id}", ['formula' => 'concepto.basico / 2'])->assertOk();
    $this->assertMatchesOpenApi($fix, 'patch', '/payroll-concepts/{id}', 200);
    cptCall($this->admin2, 'POST', "/payroll-concepts/{$id}/publish")->assertOk();
    // Sin borrador no hay nada que publicar (transición inválida).
    $again = cptCall($this->admin2, 'POST', "/payroll-concepts/{$id}/publish")->assertStatus(409);
    $this->assertMatchesOpenApi($again, 'post', '/payroll-concepts/{id}/publish', 409);
});

test('CPT-1 · RN-CPT-01 · RN-CPT-02 · cambiar la fórmula crea otra versión con vigencia; retirar no borra', function (): void {
    $id = cptCall($this->payroll, 'POST', '/payroll-concepts', ['code' => 'movilidad', 'name' => 'Movilidad', 'kind' => 'earning',
        'formula' => '100', 'valid_from' => '2026-01-01'])->json('data.id');
    cptCall($this->admin, 'POST', "/payroll-concepts/{$id}/publish")->assertOk();
    cptCall($this->payroll, 'PATCH', "/payroll-concepts/{$id}", ['formula' => '150', 'valid_from' => '2026-03-01'])->assertOk();
    // El borrador no cambia el cálculo hasta publicarse.
    expect(cptLines($this->employment, '2026-03-01', '2026-03-31')['movilidad'])->toBe('100.00');
    cptCall($this->admin, 'POST', "/payroll-concepts/{$id}/publish")->assertOk();
    expect(cptLines($this->employment, '2026-02-01', '2026-02-28')['movilidad'])->toBe('100.00')
        ->and(cptLines($this->employment, '2026-03-01', '2026-03-31')['movilidad'])->toBe('150.00');
    $show = cptCall($this->payroll, 'GET', "/payroll-concepts/{$id}")->json('data');
    expect(collect($show['versions'])->pluck('valid_to', 'valid_from')->all())->toBe(['2026-03-01' => null, '2026-01-01' => '2026-02-28']);

    // Una versión anterior a la última publicada no se acepta (la historia no se reescribe).
    cptCall($this->payroll, 'PATCH', "/payroll-concepts/{$id}", ['formula' => '90', 'valid_from' => '2026-02-01'])->assertOk();
    cptCall($this->admin, 'POST', "/payroll-concepts/{$id}/publish")->assertUnprocessable();

    $retired = cptCall($this->payroll, 'POST', "/payroll-concepts/{$id}/retire", ['reason' => 'Ya no se paga'])->assertOk();
    $this->assertMatchesOpenApi($retired, 'post', '/payroll-concepts/{id}/retire', 200);
    expect($retired->json('data.status'))->toBe('inactive')
        ->and(cptLines($this->employment, '2026-03-01', '2026-03-31'))->not->toHaveKey('movilidad');
    TenancyScope::runAs(CPT_TENANT, fn () => expect(DB::table('payroll_concept_versions')->where('payroll_concept_id', $id)->count())->toBe(2));
    $twice = cptCall($this->payroll, 'POST', "/payroll-concepts/{$id}/retire")->assertStatus(409);
    $this->assertMatchesOpenApi($twice, 'post', '/payroll-concepts/{id}/retire', 409);
});

test('CPT-3 · las afectaciones arman las bases: un aporte sobre base.pension suma solo lo que afecta a pensión', function (): void {
    $mk = function (array $body): string {
        $id = cptCall($this->payroll, 'POST', '/payroll-concepts', $body + ['valid_from' => '2026-01-01'])->assertCreated()->json('data.id');
        cptCall($this->admin, 'POST', "/payroll-concepts/{$id}/publish")->assertOk();

        return $id;
    };
    // basico (de la prueba) aún no afecta a nada: se publica una versión que suma a pensión.
    $basico = TenancyScope::runAs(CPT_TENANT, fn () => DB::table('payroll_concepts')->where('code', 'basico')->value('id'));
    cptCall($this->payroll, 'PATCH', "/payroll-concepts/{$basico}", ['affectations' => [['base_code' => 'pension']], 'valid_from' => '2026-01-01'])->assertOk();
    cptCall($this->admin, 'POST', "/payroll-concepts/{$basico}/publish")->assertOk();
    $mk(['code' => 'viaticos', 'name' => 'Viáticos', 'kind' => 'earning', 'formula' => '500', 'is_remunerative' => false]);
    $mk(['code' => 'aporte', 'name' => 'Aporte', 'kind' => 'employee_contribution', 'formula' => 'base.pension * param.cpt_tasa / 100']);
    expect(cptLines($this->employment, '2026-01-01', '2026-01-31'))->toMatchArray(['basico' => '3000.00', 'viaticos' => '500.00', 'aporte' => '300.00']);

    // RN-CPT-06: un no remunerativo solo suma a una base con sustento; RN-CPT-09: quinta exige su naturaleza.
    $v = TenancyScope::runAs(CPT_TENANT, fn () => DB::table('payroll_concepts')->where('code', 'viaticos')->value('id'));
    cptCall($this->payroll, 'PATCH', "/payroll-concepts/{$v}", ['affectations' => [['base_code' => 'pension'], ['base_code' => 'quinta']], 'valid_from' => '2026-02-01'])->assertOk();
    $codes = collect(cptCall($this->payroll, 'POST', "/payroll-concepts/{$v}/validate")->json('data.errors'))->pluck('code')->all();
    expect($codes)->toContain('justification_required', 'projection_required');

    $matrix = cptCall($this->payroll, 'GET', '/payroll-affectation-matrix?as_of=2026-01-15')->assertOk();
    $this->assertMatchesOpenApi($matrix, 'get', '/payroll-affectation-matrix', 200);
    expect(collect($matrix->json('data.bases'))->pluck('code')->all())->toContain('pension', 'essalud', 'quinta');
    $bases = cptCall($this->payroll, 'GET', '/payroll-concept-bases')->assertOk();
    $this->assertMatchesOpenApi($bases, 'get', '/payroll-concept-bases', 200);
});

test('CPT-2 · el probador calcula el borrador sobre una persona real sin guardar y explica el detalle', function (): void {
    $id = cptCall($this->payroll, 'POST', '/payroll-concepts', ['code' => 'bono', 'name' => 'Bono', 'kind' => 'earning',
        'formula' => 'si(dias_laborados > 0, concepto.basico * 5 / 100, 0)', 'condition' => 'sueldo > 1000', 'valid_from' => '2026-01-01'])->json('data.id');
    $r = cptCall($this->payroll, 'POST', "/payroll-concepts/{$id}/test", ['employment_id' => $this->employment, 'from' => '2026-01-01', 'to' => '2026-01-31'])->assertOk();
    $this->assertMatchesOpenApi($r, 'post', '/payroll-concepts/{id}/test', 200);
    $line = collect($r->json('data.lines'))->firstWhere('concept_code', 'bono');
    expect($r->json('data.applied'))->toBeTrue()->and($r->json('data.saved'))->toBeFalse()
        ->and($line['amount']['amount'])->toBe('150.00')->and($line['trace']['condition'])->toBe('sueldo > 1000')
        ->and(collect($r->json('data.lines'))->pluck('concept_code')->all())->toContain('basico');
    TenancyScope::runAs(CPT_TENANT, fn () => expect(DB::table('payroll_runs')->count())->toBe(0));
    cptCall($this->employee, 'POST', "/payroll-concepts/{$id}/test", ['employment_id' => $this->employment, 'from' => '2026-01-01', 'to' => '2026-01-31'])->assertForbidden();
});

test('CPT-4 · cuentas por concepto con excepción por centro de costo; el control de cierre lista lo que falta', function (): void {
    $acct = fn (string $code, string $kind) => cptCall($this->admin, 'POST', '/accounting-accounts', ['code' => $code, 'name' => 'Cuenta '.$code, 'kind' => $kind])->assertCreated();
    $created = $acct('6211', 'expense');
    $this->assertMatchesOpenApi($created, 'post', '/accounting-accounts', 201);
    $expense = $created->json('data.id');
    $expenseSales = $acct('6212', 'expense')->json('data.id');
    $payable = $acct('4111', 'liability')->json('data.id');
    $basico = TenancyScope::runAs(CPT_TENANT, fn () => DB::table('payroll_concepts')->where('code', 'basico')->value('id'));
    $cc = TenancyScope::runAs(CPT_TENANT, fn () => (string) DB::table('cost_centers')->insertGetId(['tenant_id' => CPT_TENANT, 'code' => 'VTA', 'name' => 'Ventas']));

    $check = cptCall($this->payroll, 'GET', "/payroll-accounting-check?legal_entity_id={$this->entity}&as_of=2026-01-31")->assertOk();
    $this->assertMatchesOpenApi($check, 'get', '/payroll-accounting-check', 200);
    expect($check->json('data.complete'))->toBeFalse()->and(collect($check->json('data.missing'))->pluck('code')->all())->toContain('basico');

    // Solo excepciones, sin regla general: no se acepta.
    cptCall($this->payroll, 'PUT', "/payroll-concepts/{$basico}/accounting", ['valid_from' => '2026-01-01', 'rules' => [
        ['cost_center_id' => $cc, 'debit_account_id' => $expenseSales, 'credit_account_id' => $payable]]])->assertUnprocessable();
    $put = cptCall($this->payroll, 'PUT', "/payroll-concepts/{$basico}/accounting", ['valid_from' => '2026-01-01', 'rules' => [
        ['debit_account_id' => $expense, 'credit_account_id' => $payable],
        ['cost_center_id' => $cc, 'debit_account_id' => $expenseSales, 'credit_account_id' => $payable]]])->assertOk();
    $this->assertMatchesOpenApi($put, 'put', '/payroll-concepts/{id}/accounting', 200);

    $resolved = TenancyScope::runAs(CPT_TENANT, fn () => [
        app(ConceptAccounting::class)->resolve($basico, $this->entity, null, 'employee', '2026-01-31'),
        app(ConceptAccounting::class)->resolve($basico, $this->entity, $cc, 'employee', '2026-01-31'),
    ]);
    expect($resolved[0]['debit_account_code'])->toBe('6211')->and($resolved[1]['debit_account_code'])->toBe('6212')
        ->and(cptCall($this->payroll, 'GET', "/payroll-accounting-check?legal_entity_id={$this->entity}&as_of=2026-01-31")->json('data.complete'))->toBeTrue();

    $list = cptCall($this->payroll, 'GET', '/accounting-accounts')->assertOk();
    $this->assertMatchesOpenApi($list, 'get', '/accounting-accounts', 200);
    $edit = cptCall($this->admin, 'PATCH', "/accounting-accounts/{$expense}", ['name' => 'Sueldos y salarios'])->assertOk();
    $this->assertMatchesOpenApi($edit, 'patch', '/accounting-accounts/{id}', 200);
    // El código de la declaración es un mapeo: payroll lo tiene; el contador no edita conceptos.
    $contador = cptUser('contador');
    cptCall($contador, 'GET', '/payroll-concepts')->assertOk();
    cptCall($contador, 'PATCH', "/payroll-concepts/{$basico}", ['statutory_code' => '0121'])->assertForbidden();
});

test('CPT-5 · el paquete Perú precarga conceptos con código SUNAT y calcula una planilla del régimen general sin crear conceptos', function (): void {
    TenancyScope::runAs(CPT_TENANT, function (): void {
        DB::table('payroll_concept_versions')->delete();
        DB::table('payroll_concepts')->delete();
        foreach (['rmv' => '1130', 'asignacion_familiar_pct_rmv' => '10', 'onp_aporte_pct' => '13', 'afp_aporte_obligatorio_pct' => '10',
            'essalud_aporte_pct' => '9', 'gratificacion_bonificacion_extraordinaria_pct' => '9'] as $code => $value) {
            PayrollFixtures::parameter($code, $value);
        }
        app(PayrollConceptsPackComponent::class)->apply('PE', null);
        app(PayrollConceptsPackComponent::class)->apply('PE', null);
        DB::table('employment_pension_affiliations')->insert(['tenant_id' => CPT_TENANT, 'employment_id' => $this->employment, 'pension_system' => 'onp', 'valid_from' => '2025-01-01']);
    });
    $component = app(PayrollConceptsPackComponent::class);
    TenancyScope::runAs(CPT_TENANT, fn () => expect($component->present('PE'))->toBe(count($component->items('PE')))
        ->and(DB::table('payroll_concepts')->where('code', 'rem_basica')->value('statutory_code'))->toBe('0121')
        ->and(DB::table('payroll_concepts')->where('is_system', true)->whereNull('statutory_code')->whereIn('kind', ['earning', 'tax'])->where('code', 'rem_basica')->exists())->toBeFalse());

    $lines = cptLines($this->employment, '2026-01-01', '2026-01-31');
    expect($lines)->toMatchArray(['rem_basica' => '3000.00', 'onp_aporte' => '390.00', 'essalud' => '270.00'])
        ->and($lines)->not->toHaveKey('afp_aporte')->and($lines)->not->toHaveKey('asig_familiar');

    // RN-CPT-03: la afectación legal de un concepto del paquete no cambia; se duplica como propio.
    $id = TenancyScope::runAs(CPT_TENANT, fn () => DB::table('payroll_concepts')->where('code', 'comisiones')->value('id'));
    $locked = cptCall($this->payroll, 'PATCH', "/payroll-concepts/{$id}", ['affectations' => [['base_code' => 'pension']]])->assertUnprocessable();
    expect($locked->json('error.details.0.rule'))->toBe('pack_locked');
    cptCall($this->payroll, 'PATCH', "/payroll-concepts/{$id}", ['name' => 'Comisiones por ventas'])->assertOk();
    $copy = cptCall($this->payroll, 'POST', "/payroll-concepts/{$id}/duplicate", ['code' => 'comisiones_propias', 'name' => 'Comisiones (propias)'])->assertCreated();
    $this->assertMatchesOpenApi($copy, 'post', '/payroll-concepts/{id}/duplicate', 201);
    expect($copy->json('data.is_system'))->toBeFalse()->and($copy->json('data.draft.affectations'))->not->toBeEmpty();
    cptCall($this->payroll, 'PATCH', '/payroll-concepts/'.$copy->json('data.id'), ['affectations' => [['base_code' => 'pension']]])->assertOk();
});

test('CPT · sin el módulo de planilla los conceptos responden module_not_enabled', function (): void {
    IdentityFixtures::disableModule(CPT_TENANT, 'payroll');
    $off = cptCall($this->admin, 'GET', '/payroll-concepts')->assertForbidden();
    expect($off->json('error.code'))->toBe('module_not_enabled');
});
