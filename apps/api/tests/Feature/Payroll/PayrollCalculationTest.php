<?php

/**
 * PER-4 · Cálculo del periodo (corrida en segundo plano, resultado por trabajador, recálculo que reemplaza y
 * alcance parcial); PER-5 · retención de quinta con proyección y libro de solo inserción; PER-6 · aportes AFP
 * con la tasa de la administradora de cada periodo y prima con tope de la remuneración asegurable.
 */

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Payroll\Application\PayrollEngine;
use App\Modules\Payroll\Application\PayrollRuns;
use App\Modules\Payroll\Domain\Tax\ProjectedAnnualWithholding;
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

const PCA_TENANT = 'aaaaaaaa-0c4a-4000-8000-00000000000a';
const PCA_OTHER = 'bbbbbbbb-0c4a-4000-8000-00000000000b';

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requiere PostgreSQL.');
    }
    TenancyScope::clear();
    foreach ([PCA_TENANT => 'pca-org', PCA_OTHER => 'pca-otra'] as $id => $slug) {
        DB::table('tenants')->insertOrIgnore(['id' => $id, 'slug' => $slug, 'name' => 'Grupo '.$slug, 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN']);
        DB::select('SELECT seed_tenant_defaults(?)', [$id]);
        TenancyScope::runAs($id, fn () => IdentityFixtures::enableAllModules());
    }
    $this->payroll = pcaUser(PCA_TENANT, 'payroll');
    $this->admin = pcaUser(PCA_TENANT, 'admin_empresa');
    $this->employee = pcaUser(PCA_TENANT, 'empleado');
    $this->other = pcaUser(PCA_OTHER, 'admin_empresa');
    [$this->entity, $this->a, $this->b] = TenancyScope::runAs(PCA_TENANT, function (): array {
        $entity = PayrollFixtures::entity();
        $a = PayrollFixtures::employment($entity, '2025-01-01', [['amount' => '6000.00', 'valid_from' => '2025-01-01']]);
        $b = PayrollFixtures::employment($entity, '2025-01-01', [['amount' => '2000.00', 'valid_from' => '2025-01-01']]);
        // Conceptos sintéticos del caso: básico afecto a quinta (ordinario), la retención del paquete y un aporte.
        PayrollFixtures::concept(['code' => 'BASICO', 'kind' => 'earning', 'formula' => 'sueldo_tramos', 'sort_order' => 1, 'affects' => ['quinta:ordinary', 'pension', 'essalud']]);
        PayrollFixtures::concept(['code' => 'renta_quinta', 'kind' => 'tax', 'sort_order' => 90]);
        PayrollFixtures::concept(['code' => 'APORTE', 'kind' => 'employer_contribution', 'formula' => 'base.essalud * param.pca_aporte_pct / 100', 'sort_order' => 95]);
        PayrollFixtures::parameter('pca_aporte_pct', '9');
        PayrollFixtures::parameter('dias_base_mes', '30');
        PayrollFixtures::incomeTaxParameters('5500');
        // Gratificaciones proyectadas: una remuneración y su bonificación (valores del caso, documento 18 §2.9).
        PayrollFixtures::parameter('gratificacion_factor', '1');
        PayrollFixtures::parameter('gratificacion_bonificacion_extraordinaria_pct', '9');

        return [$entity, $a, $b];
    });
});

afterEach(fn () => TenancyScope::clear());

function pcaUser(string $tenant, string $role): User
{
    return TenancyScope::runAs($tenant, function () use ($role): User {
        $u = User::factory()->create();
        $u->roleAssignments()->create(['role_id' => Role::query()->where('system_role_code', $role)->value('id')]);

        return $u;
    });
}

/** @param array<string, mixed> $body */
function pcaCall(User $user, string $method, string $path, array $body = []): TestResponse
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

function pcaPeriod(object $t, int $month): string
{
    $group = TenancyScope::runAs(PCA_TENANT, fn () => DB::table('payroll_groups')->where('code', 'MENS')->value('id'));
    if ($group === null) {
        $group = (string) pcaCall($t->admin, 'POST', '/payroll-groups', ['code' => 'MENS', 'name' => 'Mensual', 'legal_entity_id' => $t->entity, 'payment_day' => 28, 'cutoff_day' => 25])
            ->assertCreated()->json('data.id');
    }

    return (string) pcaCall($t->admin, 'POST', '/payroll-periods', ['payroll_group_id' => $group, 'period_year' => 2026, 'period_number' => $month])
        ->assertCreated()->json('data.id');
}

function pcaProcess(string $runId): void
{
    TenancyScope::runAs(PCA_TENANT, fn () => app(PayrollRuns::class)->process($runId));
}

test('PER-4 · calcular el periodo: corrida en segundo plano, resultado por trabajador y recálculo que reemplaza', function (): void {
    $jan = pcaPeriod($this, 1);
    $calc = pcaCall($this->payroll, 'POST', "/payroll-periods/{$jan}/calculate")->assertOk();
    $this->assertMatchesOpenApi($calc, 'post', '/payroll-periods/{id}/calculate', 200);
    $run = (string) $calc->json('data.last_run.id');
    expect($calc->json('data.status'))->toBe('calculated')->and($calc->json('data.last_run.status'))->toBe('queued')
        ->and($calc->json('data.allowed_actions'))->toContain('calculate', 'submit');

    // Con la corrida en curso no se vuelve a calcular ni se envía a aprobación.
    $busy = pcaCall($this->payroll, 'POST', "/payroll-periods/{$jan}/calculate")->assertStatus(409);
    expect($busy->json('error.code'))->toBe('run_in_progress');
    pcaCall($this->payroll, 'POST', "/payroll-periods/{$jan}/submit")->assertStatus(409);

    pcaProcess($run);
    $people = pcaCall($this->payroll, 'GET', "/payroll-runs/{$run}/employments")->assertOk();
    $rows = collect($people->json('data'))->keyBy('employment_id');
    expect($rows[$this->a]['totals']['earnings']['amount'])->toBe('6000.00')
        ->and($rows[$this->a]['totals']['deductions']['amount'])->toBe('405.93')
        ->and($rows[$this->a]['totals']['net']['amount'])->toBe('5594.07')
        ->and($rows[$this->a]['totals']['employer_contributions']['amount'])->toBe('540.00');

    // Cada monto abre su explicación: la retención trae la proyección completa.
    $lines = collect(pcaCall($this->payroll, 'GET', '/payroll-run-employments/'.$rows[$this->a]['id'].'/lines')->assertOk()->json('data'))->keyBy('concept_code');
    $quinta = pcaCall($this->payroll, 'GET', '/payroll-run-lines/'.$lines['renta_quinta']['id'].'/explanation')->assertOk();
    expect(json_encode($quinta->json('data')))->toContain('85080.00');

    // Recalcular (tras corregir el sueldo) reemplaza el resultado: la corrida anterior queda descartada, no borrada.
    TenancyScope::runAs(PCA_TENANT, fn () => DB::table('employment_compensations')->where('employment_id', $this->b)->update(['base_salary' => '2100.00']));
    $again = pcaCall($this->payroll, 'POST', "/payroll-periods/{$jan}/calculate", ['employment_ids' => [$this->b]])->assertOk();
    $run2 = (string) $again->json('data.last_run.id');
    pcaProcess($run2);
    TenancyScope::runAs(PCA_TENANT, function () use ($run, $run2): void {
        expect(DB::table('payroll_runs')->where('id', $run)->value('status'))->toBe('discarded')
            ->and(DB::table('payroll_runs')->where('id', $run2)->value('kind'))->toBe('recalculation')
            ->and(DB::table('payroll_runs')->where('id', $run2)->value('status'))->toBe('completed');
    });
    $rows2 = collect(pcaCall($this->payroll, 'GET', "/payroll-runs/{$run2}/employments")->assertOk()->json('data'))->keyBy('employment_id');
    // Alcance parcial: solo se recalculó B; A pasa sin cambios a la corrida nueva.
    expect($rows2[$this->b]['totals']['earnings']['amount'])->toBe('2100.00')
        ->and($rows2[$this->a]['totals']['net']['amount'])->toBe('5594.07');
    // El libro de quinta solo inserta: A tiene una fila (no se recalculó) y B dos secuencias de enero.
    TenancyScope::runAs(PCA_TENANT, function (): void {
        expect(DB::table('fifth_category_ledger')->where('employment_id', $this->a)->count())->toBe(1)
            ->and(DB::table('fifth_category_ledger')->where('employment_id', $this->b)->orderBy('sequence')->pluck('sequence')->all())->toBe([1, 2]);
    });

    // Aprobado, ya no se recalcula: el estado solo cambia por acciones de la máquina (409).
    TenancyScope::runAs(PCA_TENANT, fn () => DB::table('payroll_periods')->where('id', $jan)->update(['status' => 'approved']));
    $bad = pcaCall($this->payroll, 'POST', "/payroll-periods/{$jan}/calculate")->assertStatus(409);
    $this->assertMatchesOpenApi($bad, 'post', '/payroll-periods/{id}/calculate', 409);
});

test('PER-4 · permiso, módulo, datos inválidos y aislamiento entre organizaciones', function (): void {
    $jan = pcaPeriod($this, 1);
    $this->assertMatchesOpenApi(pcaCall($this->employee, 'POST', "/payroll-periods/{$jan}/calculate")->assertForbidden(), 'post', '/payroll-periods/{id}/calculate', 403);
    $this->assertMatchesOpenApi(pcaCall($this->payroll, 'POST', "/payroll-periods/{$jan}/calculate", ['employment_ids' => ['no-es-uuid']])->assertStatus(422), 'post', '/payroll-periods/{id}/calculate', 422);
    $this->assertMatchesOpenApi(pcaCall($this->other, 'POST', "/payroll-periods/{$jan}/calculate")->assertNotFound(), 'post', '/payroll-periods/{id}/calculate', 404);
    IdentityFixtures::disableModule(PCA_TENANT, 'payroll');
    $off = pcaCall($this->payroll, 'POST', "/payroll-periods/{$jan}/calculate")->assertForbidden();
    expect($off->json('error.code'))->toBe('module_not_enabled');
});

test('PER-5 · la retención de febrero proyecta con lo pagado en enero y deduce según el corte; sin parámetro no calcula', function (): void {
    $jan = pcaPeriod($this, 1);
    $run = (string) pcaCall($this->payroll, 'POST', "/payroll-periods/{$jan}/calculate")->assertOk()->json('data.last_run.id');
    pcaProcess($run);
    $feb = pcaPeriod($this, 2);
    $calc = TenancyScope::runAs(PCA_TENANT, fn () => app(PayrollEngine::class)->calculate($this->a, '2026-02-01', '2026-02-28', 'regular', $feb));
    $line = collect($calc['result']['lines'])->firstWhere('concept_code', 'renta_quinta');
    $tax = $line['trace']['income_tax'];
    // Caso 1 del documento 18 §2.9: RBA = 6 000 × 11 + 13 080 + 6 000 (enero) = 85 080; IA = 4 871,20; febrero ÷ 12.
    expect($tax['prior_income'])->toBe('6000.00')->and($tax['remaining_months'])->toBe(11)
        ->and($tax['gross_annual_income'])->toBe('85080.00')->and($tax['annual_tax'])->toBe('4871.20')
        ->and($tax['divisor'])->toBe(12)->and($line['amount']['amount'])->toBe('405.93');

    // Sin la escala vigente la relación no se calcula y se informa el parámetro que falta (RN-PAR-04).
    TenancyScope::runAs(PCA_TENANT, fn () => DB::table('legal_parameter_overrides')->where('parameter_code', 'quinta_escala')->delete());
    $missing = TenancyScope::runAs(PCA_TENANT, fn () => app(PayrollEngine::class)->calculate($this->a, '2026-02-01', '2026-02-28', 'regular', $feb));
    expect($missing['result']['status'])->toBe('error')
        ->and(collect($missing['result']['errors'])->pluck('parameter')->all())->toContain('quinta_escala');
});

test('PER-5 · no domiciliado, exonerado, otro empleador que retiene y ajuste final al cese (función pura)', function (): void {
    $p = ['uit' => '5500', 'deduction_uit' => '7', 'scale' => [['hasta_uit' => 5, 'tasa' => 8], ['hasta_uit' => null, 'tasa' => 14]],
        'divisors' => ['1' => 12], 'cutoffs' => ['1' => 0], 'non_resident_rate_pct' => '30'];
    $in = ['month' => 1, 'ordinary_month' => '6000', 'pending_gratifications' => '0', 'prior_income' => '0', 'external_received' => '0',
        'external_projected' => '0', 'extraordinary_month' => '0', 'taxable_month_total' => '6000', 'withholdings_by_month' => []];
    expect(ProjectedAnnualWithholding::calculate($in + ['resident' => false], $p))->toMatchArray(['withholding' => '1800.00', 'reason' => 'non_resident'])
        ->and(ProjectedAnnualWithholding::calculate($in + ['exempt' => true], $p))->toMatchArray(['withholding' => '0.00', 'reason' => 'exempt'])
        ->and(ProjectedAnnualWithholding::calculate($in + ['this_employer_withholds' => false], $p))->toMatchArray(['withholding' => '0.00', 'reason' => 'other_employer_withholds']);
    // Cese en agosto: impuesto sobre lo percibido menos lo retenido; negativo = devolución en la liquidación.
    $end = ProjectedAnnualWithholding::calculate(['month' => 8, 'termination' => true, 'prior_income' => '42000', 'taxable_month_total' => '3000',
        'external_received' => '0', 'withholdings_by_month' => ['1' => '900', '2' => '900']] + $in, $p);
    expect($end['kind'])->toBe('final_adjustment')->and($end['annual_tax'])->toBe('520.00')->and($end['withholding'])->toBe('-1280.00');
});

test('PER-6 · AFP con la tasa de la administradora de cada periodo y prima con tope; ONP y EsSalud según el trabajador', function (): void {
    // Tasas de prueba por administradora (tabla de la plataforma; valores del caso, no oficiales).
    foreach (['pe_afp_integra' => ['1.50', '1.00', '1.70', '5000.00'], 'pe_afp_prima' => ['0.80', '0.50', '1.80', '5000.00']] as $code => [$flow, $mixed, $prima, $max]) {
        $fund = (string) DB::table('pension_funds')->where('code', $code)->value('id');
        DB::table('pension_fund_rates')->where('pension_fund_id', $fund)->delete();
        DB::table('pension_fund_rates')->insert(['pension_fund_id' => $fund, 'contribution_rate' => '10.00', 'commission_flow_rate' => $flow, 'commission_mixed_rate' => $mixed,
            'insurance_premium_rate' => $prima, 'max_insurable_remuneration' => $max, 'currency' => 'PEN', 'verified' => true, 'valid_from' => '2026-01-01']);
    }
    TenancyScope::runAs(PCA_TENANT, function (): void {
        // Afiliación a Integra hasta junio y a Prima desde julio (comisión sobre el flujo).
        DB::table('employment_pension_affiliations')->insert([
            ['tenant_id' => PCA_TENANT, 'employment_id' => $this->a, 'pension_system' => 'afp', 'pension_fund_code' => 'pe_afp_integra', 'commission_scheme' => 'flow', 'valid_from' => '2025-01-01', 'valid_to' => '2026-06-30'],
            ['tenant_id' => PCA_TENANT, 'employment_id' => $this->a, 'pension_system' => 'afp', 'pension_fund_code' => 'pe_afp_prima', 'commission_scheme' => 'flow', 'valid_from' => '2026-07-01', 'valid_to' => null],
        ]);
        DB::table('employment_pension_affiliations')->insert(['tenant_id' => PCA_TENANT, 'employment_id' => $this->b, 'pension_system' => 'onp', 'valid_from' => '2025-01-01']);
        DB::table('employment_labor_regimes')->insert(['tenant_id' => PCA_TENANT, 'employment_id' => $this->b, 'labor_regime_code' => 'pe_general', 'health_scheme' => 'sis', 'valid_from' => '2025-01-01']);
        PayrollFixtures::parameter('onp_aporte_pct', '13');
        PayrollFixtures::parameter('rmv', '1130');
        PayrollFixtures::parameter('essalud_aporte_pct', '9');
        // Conceptos del paquete país (fórmulas del motor de conceptos_pe.json) sobre la base de pensión.
        $pack = collect(json_decode((string) file_get_contents(base_path('packages/country-pe/data/conceptos_pe.json')), true)['conceptos'])->keyBy('codigo');
        foreach (['afp_aporte', 'afp_comision', 'afp_prima', 'onp_aporte', 'essalud'] as $i => $code) {
            PayrollFixtures::concept(['code' => $code, 'kind' => (string) $pack[$code]['tipo'], 'formula' => $pack[$code]['formula_motor'], 'condition' => $pack[$code]['condicion_motor'] ?? null, 'sort_order' => 100 + $i]);
        }
    });
    $lines = function (string $employment, string $from, string $to): array {
        $calc = TenancyScope::runAs(PCA_TENANT, fn () => app(PayrollEngine::class)->calculate($employment, $from, $to));
        expect($calc['result']['status'])->toBe('calculated', json_encode($calc['result']['errors']) ?: '');

        return collect($calc['result']['lines'])->mapWithKeys(fn ($l) => [$l['concept_code'] => $l['amount']['amount']])->all();
    };
    $mar = $lines($this->a, '2026-03-01', '2026-03-31');
    $aug = $lines($this->a, '2026-08-01', '2026-08-31');
    // Marzo con PCA_UNO (comisión 1,50 %) y agosto con PCA_DOS (0,80 %); la prima se calcula sobre el tope (5 000), no sobre 6 000.
    expect($mar)->toMatchArray(['afp_aporte' => '600.00', 'afp_comision' => '90.00', 'afp_prima' => '85.00', 'essalud' => '540.00'])
        ->and($aug)->toMatchArray(['afp_comision' => '48.00', 'afp_prima' => '90.00'])
        ->and($mar)->not->toHaveKey('onp_aporte');
    // ONP para B y sin EsSalud porque su seguro de salud es el SIS.
    $b = $lines($this->b, '2026-03-01', '2026-03-31');
    expect($b)->toMatchArray(['onp_aporte' => '260.00'])->and($b)->not->toHaveKey('afp_aporte')->and($b)->not->toHaveKey('essalud');
});
