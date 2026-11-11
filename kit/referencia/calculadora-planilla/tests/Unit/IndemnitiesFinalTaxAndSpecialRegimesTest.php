<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Employee;
use App\Payroll\Calculation\PayrollContext;
use App\Payroll\Calculation\PayrollResult;
use App\Payroll\Parameters\InMemoryParameterRepository;
use App\Payroll\Settlement\Dismissal;
use App\Payroll\Settlement\SettlementContext;
use App\Payroll\Settlement\SettlementResult;
use App\Payroll\Settlement\SettlementService;
use App\Payroll\Steps;
use App\Payroll\Tax\FifthCategoryCalculator;
use App\Payroll\Tax\FifthCategoryHistory;
use App\Services\PayrollService;
use Brick\Math\BigDecimal;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * v1.13: indemnización por despido y vacacional, quinta del cese, regularización con devolución,
 * régimen agrario y construcción civil, con el catálogo del kit.
 */
final class IndemnitiesFinalTaxAndSpecialRegimesTest extends TestCase
{
    private InMemoryParameterRepository $params;

    protected function setUp(): void
    {
        $this->params = InMemoryParameterRepository::fromKitCatalog(__DIR__.'/../../kit/database/catalogos/parametros_legales_pe.json');
        // El régimen general aún no tiene días de vacaciones verificados en el catálogo.
        $this->params->put('vacaciones_dias', 'pe_general', 30, '2025-01-01', verified: false, unit: 'days');
    }

    private function employee(array $attrs): Employee
    {
        $dates = array_intersect_key($attrs, array_flip(['hire_date', 'termination_date']));
        $e = (new Employee)->forceFill(array_diff_key($attrs, $dates) + [
            'id' => '0193f2a0-0000-7000-8000-000000000003', 'full_name' => 'Colaborador de prueba', 'labor_regime_code' => 'pe_general',
            'has_family_allowance' => false, 'health_scheme' => 'essalud', 'pension_system' => 'onp', 'pension_fund_code' => null, 'commission_scheme' => null,
            'construction_category' => null, 'agrarian_prorated_benefits' => false, 'agrarian_tier' => null,
        ]);

        return $e->setRawAttributes($e->getAttributes() + $dates + ['hire_date' => null, 'termination_date' => null]);
    }

    private static function d(string $date): DateTimeImmutable
    {
        return new DateTimeImmutable($date);
    }

    private function settlement(): SettlementService
    {
        return new SettlementService($this->params);
    }

    private function payroll(): PayrollService
    {
        $general = [new Steps\BaseSalaryStep, new Steps\FamilyAllowanceStep, new Steps\AdditionalEarningsStep, new Steps\PensionStep,
            new Steps\FifthCategoryTaxStep, new Steps\OtherDeductionsStep, new Steps\HealthContributionStep, new Steps\GratificationProvisionStep, new Steps\CtsProvisionStep];

        return new PayrollService($this->params, $general, 2, [
            'pe_agrario' => [new Steps\BaseSalaryStep, new Steps\FamilyAllowanceStep, new Steps\AgrarianBonusStep, new Steps\AdditionalEarningsStep,
                new Steps\AgrarianBenefitsStep, new Steps\PensionStep, new Steps\FifthCategoryTaxStep, new Steps\OtherDeductionsStep, new Steps\AgrarianHealthContributionStep],
            'pe_construccion' => [new Steps\ConstructionWageStep, new Steps\AdditionalEarningsStep, new Steps\PensionStep, new Steps\FifthCategoryTaxStep,
                new Steps\OtherDeductionsStep, new Steps\HealthContributionStep, new Steps\ConstructionBenefitsStep],
        ]);
    }

    // ── Indemnización por despido ─────────────────────────────────────────────

    public function test_despido_arbitrario_plazo_indeterminado_paga_45_remuneraciones_diarias_por_anio(): void
    {
        $e = $this->employee(['base_salary' => '3000.00', 'has_family_allowance' => true, 'hire_date' => '2024-03-10', 'termination_date' => '2026-11-20']);

        $r = $this->settlement()->calculate($e, new SettlementContext(dismissal: new Dismissal));

        $this->assertSame(SettlementResult::STATUS_OK, $r->status, (string) $r->error);
        // 3 113 ÷ 30 × 45 × (32 ÷ 12 + 11 ÷ 360): 2 años, 8 meses y 11 días
        $this->assertSame('12594.68', $r->amount('indemnizacion_despido'));
        $this->assertSame(['months' => 32, 'days' => 11], $r->periods['indemnizacion_despido']['service']);
        $this->assertFalse($r->periods['indemnizacion_despido']['capped']);
        $this->assertSame('282.16', $r->amount('onp_aporte'));                      // 13 % de los 20 días del mes: la indemnización no lleva aportes
    }

    public function test_despido_con_muchos_anios_llega_al_tope_de_12_remuneraciones(): void
    {
        $e = $this->employee(['base_salary' => '3000.00', 'has_family_allowance' => true, 'hire_date' => '2010-01-01', 'termination_date' => '2026-11-20']);

        $r = $this->settlement()->calculate($e, new SettlementContext(dismissal: new Dismissal));

        $this->assertSame('37356.00', $r->amount('indemnizacion_despido'));         // 3 113 × 12
        $this->assertTrue($r->periods['indemnizacion_despido']['capped']);
    }

    public function test_despido_en_contrato_a_plazo_fijo_paga_los_meses_que_faltaban(): void
    {
        $e = $this->employee(['base_salary' => '2000.00', 'hire_date' => '2026-01-01', 'termination_date' => '2026-06-30']);

        $r = $this->settlement()->calculate($e, new SettlementContext(dismissal: new Dismissal(Dismissal::FIXED_TERM, self::d('2026-09-30'))));

        $this->assertSame('9000.00', $r->amount('indemnizacion_despido'));          // 2 000 × 1,5 × 3 meses
        $this->assertSame(['months' => 3, 'days' => 0], $r->periods['indemnizacion_despido']['remaining']);
    }

    public function test_despido_en_periodo_de_prueba_no_paga_indemnizacion(): void
    {
        $e = $this->employee(['base_salary' => '2000.00', 'hire_date' => '2026-09-01', 'termination_date' => '2026-10-15']);

        $r = $this->settlement()->calculate($e, new SettlementContext(dismissal: new Dismissal(inProbation: true)));

        $this->assertNull($r->amount('indemnizacion_despido'));
        $this->assertContains('El despido ocurre dentro del periodo de prueba: no corresponde indemnización.', $r->warnings);
    }

    public function test_despido_en_pequena_empresa_usa_los_dias_de_su_regimen(): void
    {
        $e = $this->employee(['base_salary' => '2000.00', 'labor_regime_code' => 'pe_pequena', 'hire_date' => '2025-08-01', 'termination_date' => '2026-03-31']);

        $r = $this->settlement()->calculate($e, new SettlementContext(dismissal: new Dismissal));

        $this->assertSame('888.89', $r->amount('indemnizacion_despido'));           // 2 000 ÷ 30 × 20 × 8 ÷ 12
    }

    public function test_plazo_fijo_sin_fecha_de_fin_queda_con_error(): void
    {
        $e = $this->employee(['base_salary' => '2000.00', 'hire_date' => '2026-01-01', 'termination_date' => '2026-06-30']);

        $r = $this->settlement()->calculate($e, new SettlementContext(dismissal: new Dismissal(Dismissal::FIXED_TERM)));

        $this->assertSame(SettlementResult::STATUS_ERROR, $r->status);
        $this->assertStringContainsString('plazo fijo', (string) $r->error);
    }

    // ── Indemnización vacacional ──────────────────────────────────────────────

    public function test_indemnizacion_vacacional_por_periodo_vencido_sin_gozar(): void
    {
        $e = $this->employee(['base_salary' => '3000.00', 'has_family_allowance' => true, 'hire_date' => '2024-03-10', 'termination_date' => '2026-11-20']);

        $r = $this->settlement()->calculate($e, new SettlementContext(pendingVacationDays: 30, expiredVacationDays: 30));

        $this->assertSame('3113.00', $r->amount('indemnizacion_vacacional'));       // una remuneración
        $this->assertSame('3113.00', $r->amount('vacaciones_pendientes'));          // más la remuneración vacacional
        $this->assertSame('686.85', $r->amount('onp_aporte'));                      // la indemnización no lleva aportes
    }

    // ── Quinta del cese y regularización ──────────────────────────────────────

    private function ceseJunio(): Employee
    {
        return $this->employee(['base_salary' => '10000.00', 'hire_date' => '2020-01-01', 'termination_date' => '2026-06-30']);
    }

    public function test_quinta_del_cese_sobre_la_liquidacion_sin_proyectar(): void
    {
        $history = new FifthCategoryHistory(BigDecimal::of('50000'), [1 => BigDecimal::of('600'), 2 => BigDecimal::of('600'), 3 => BigDecimal::of('600'), 4 => BigDecimal::of('600'), 5 => BigDecimal::of('600')], BigDecimal::of('21800'));

        $r = $this->settlement()->calculate($this->ceseJunio(), new SettlementContext(
            fifthCategoryHistory: $history, lastMonthFifthIncome: BigDecimal::of('10000'), lastMonthWithholding: BigDecimal::of('300')));

        $this->assertSame(SettlementResult::STATUS_OK, $r->status, (string) $r->error);
        // 50 000 + junio 10 000 + gratificación trunca 10 000 + bonificación 900 + vacaciones truncas 5 000 = 75 900
        // (la gratificación de diciembre que traía el historial no se proyecta en el cese)
        $this->assertSame('75900.00', $r->toArray()['fifth_category']['projected_annual_income'] ?? $this->fifth($r)['projected_annual_income']);
        $this->assertSame('286.00', $r->amount('renta_quinta_cese'));               // 3 586 − (3 000 + 300), sin divisor
    }

    public function test_exceso_de_retencion_en_el_cese_espera_el_parametro_y_luego_se_devuelve(): void
    {
        $history = new FifthCategoryHistory(BigDecimal::of('50000'), array_fill(1, 5, BigDecimal::of('800')));
        $context = new SettlementContext(fifthCategoryHistory: $history, lastMonthFifthIncome: BigDecimal::of('10000'));

        $r = $this->settlement()->calculate($this->ceseJunio(), $context);
        $this->assertNull($r->amount('renta_quinta_cese'));
        $this->assertNull($r->amount('devolucion_quinta'));
        $this->assertContains('Hay un exceso de retención de quinta de S/ 414.00: la devolución espera que se confirme «quinta_devuelve_exceso».', $r->warnings);

        $this->params->put('quinta_devuelve_exceso', null, 1, '2025-01-01', verified: false, unit: 'count');
        $r = $this->settlement()->calculate($this->ceseJunio(), $context);
        $this->assertSame('414.00', $r->amount('devolucion_quinta'));               // 4 000 retenido − 3 586 de impuesto
    }

    public function test_regularizacion_de_diciembre_calcula_el_exceso_a_devolver(): void
    {
        $calc = new FifthCategoryCalculator(fn (string $code) => $this->params->get($code, null, self::d('2026-12-31')));

        $r = $calc->calculate(12, BigDecimal::of('5000'), BigDecimal::of('5000'), BigDecimal::zero(),
            new FifthCategoryHistory(BigDecimal::of('55000'), array_fill(1, 11, BigDecimal::of('300'))), 0);

        $this->assertSame('1720.00', (string) $r->annualTax);                       // (60 000 − 38 500) × 8 %
        $this->assertSame('0.00', (string) $r->withholding);
        $this->assertSame('1580.00', (string) $r->refund);                          // 3 300 retenido − 1 720
    }

    public function test_noviembre_no_devuelve_aunque_haya_retenido_de_mas(): void
    {
        $calc = new FifthCategoryCalculator(fn (string $code) => $this->params->get($code, null, self::d('2026-11-30')));

        $r = $calc->calculate(11, BigDecimal::of('5000'), BigDecimal::of('5000'), BigDecimal::zero(),
            new FifthCategoryHistory(BigDecimal::of('50000'), array_fill(1, 10, BigDecimal::of('500'))), 1);

        $this->assertSame('0.00', (string) $r->withholding);
        $this->assertSame('0.00', (string) $r->refund);                             // se compensa en diciembre
    }

    // ── Régimen agrario ──────────────────────────────────────────────────────

    private function agrario(): Employee
    {
        return $this->employee(['base_salary' => '1500.00', 'labor_regime_code' => 'pe_agrario', 'agrarian_prorated_benefits' => true, 'agrarian_tier' => 'A', 'hire_date' => '2026-01-05']);
    }

    public function test_agrario_sin_tasa_de_essalud_confirmada_queda_con_error(): void
    {
        $r = $this->payroll()->calculate($this->agrario(), PayrollContext::forMonth(2026, 11));

        $this->assertSame(PayrollResult::STATUS_ERROR, $r->status);
        $this->assertStringContainsString('agr_essalud_pct', (string) $r->error);
    }

    public function test_agrario_con_beneficios_prorrateados_y_beta(): void
    {
        $this->params->putJson('agr_essalud_pct', 'pe_agrario', ['A' => 9, 'B' => 6], '2025-01-01', verified: false);

        $r = $this->payroll()->calculate($this->agrario(), PayrollContext::forMonth(2026, 11));
        $lines = array_column(array_map(fn ($l) => $l->toArray(), array_merge($r->earnings, $r->deductions, $r->employerContributions)), 'amount', 'code');

        $this->assertSame(PayrollResult::STATUS_OK, $r->status, (string) $r->error);
        $this->assertSame('339.00', $lines['agr_beta']);                            // 30 % de la RMV de 1 130
        $this->assertSame('249.90', $lines['agr_gratif_pror']);                     // 16,66 % de 1 500
        $this->assertSame('145.80', $lines['agr_cts_pror']);                        // 9,72 % de 1 500
        $this->assertSame('195.00', $lines['onp_aporte']);                          // 13 % solo de la remuneración básica
        $this->assertSame('135.00', $lines['essalud']);                             // tramo A, 9 %
        $this->assertSame([], $r->provisions);                                      // ya se pagaron prorrateadas
    }

    // ── Construcción civil ───────────────────────────────────────────────────

    public function test_construccion_civil_operario(): void
    {
        $e = $this->employee(['base_salary' => '0.00', 'labor_regime_code' => 'pe_construccion', 'construction_category' => 'operario', 'hire_date' => '2026-10-01']);

        $r = $this->payroll()->calculate($e, PayrollContext::forMonth(2026, 11, constructionPaidDays: 26, constructionWorkedDays: 22));
        $lines = array_column(array_map(fn ($l) => $l->toArray(), array_merge($r->earnings, $r->deductions, $r->employerContributions)), 'amount', 'code');

        $this->assertSame(PayrollResult::STATUS_OK, $r->status, (string) $r->error);
        $this->assertSame('2321.80', $lines['rem_basica']);                         // 89,30 × 26
        $this->assertSame('742.98', $lines['cc_buc']);                              // 32 %
        $this->assertSame('189.20', $lines['cc_movilidad']);                        // 8,60 × 22
        $this->assertSame('398.42', $lines['onp_aporte']);                          // 13 % de jornal + BUC, sin movilidad
        $this->assertSame('275.83', $lines['essalud']);
        $this->assertSame('348.27', (string) $r->provisions['prov_cc_cts']);         // 15 %
        $this->assertSame('232.18', (string) $r->provisions['prov_cc_vacaciones']);  // 10 %
        $this->assertSame('714.40', (string) $r->provisions['prov_gratificacion']);  // 89,30 × 40 ÷ 5 (quintos de agosto a diciembre)
    }

    public function test_construccion_civil_sin_jornales_queda_con_error(): void
    {
        $e = $this->employee(['base_salary' => '0.00', 'labor_regime_code' => 'pe_construccion', 'construction_category' => 'operario', 'hire_date' => '2026-10-01']);

        $r = $this->payroll()->calculate($e, PayrollContext::forMonth(2026, 11));

        $this->assertSame(PayrollResult::STATUS_ERROR, $r->status);
        $this->assertStringContainsString('jornales pagados', (string) $r->error);
    }

    /** @return array<string, mixed> */
    private function fifth(SettlementResult $r): array
    {
        return $r->fifthCategory ?? [];
    }
}
