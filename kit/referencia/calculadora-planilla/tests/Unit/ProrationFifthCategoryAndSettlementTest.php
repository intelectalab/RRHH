<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Employee;
use App\Payroll\Calculation\Line;
use App\Payroll\Calculation\PayrollContext;
use App\Payroll\Calculation\PayrollResult;
use App\Payroll\Calculation\SalaryChange;
use App\Payroll\Calculation\ServiceTime;
use App\Payroll\Parameters\InMemoryParameterRepository;
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
 * Prorrateo (RN-NOM-05), proyección de quinta (RN-NOM-13 a RN-NOM-16) y liquidación de beneficios
 * sociales, con el catálogo del kit (RMV S/ 1 130, UIT 2026 S/ 5 500, escala 8 % a 30 %…).
 */
final class ProrationFifthCategoryAndSettlementTest extends TestCase
{
    private InMemoryParameterRepository $params;

    protected function setUp(): void
    {
        $this->params = InMemoryParameterRepository::fromKitCatalog(__DIR__.'/../../kit/database/catalogos/parametros_legales_pe.json');
    }

    private function payroll(): PayrollService
    {
        return new PayrollService($this->params, [
            new Steps\BaseSalaryStep, new Steps\FamilyAllowanceStep, new Steps\AdditionalEarningsStep,
            new Steps\PensionStep, new Steps\FifthCategoryTaxStep, new Steps\OtherDeductionsStep,
            new Steps\HealthContributionStep, new Steps\GratificationProvisionStep, new Steps\CtsProvisionStep,
        ]);
    }

    private function employee(array $attrs): Employee
    {
        // Las fechas van crudas: así la prueba unitaria no necesita conexión a la base para el formato.
        $dates = array_intersect_key($attrs, array_flip(['hire_date', 'termination_date']));
        $e = (new Employee)->forceFill(array_diff_key($attrs, $dates) + [
            'id' => '0193f2a0-0000-7000-8000-000000000002', 'full_name' => 'Colaborador de prueba', 'labor_regime_code' => 'pe_general',
            'has_family_allowance' => false, 'health_scheme' => 'essalud', 'pension_system' => 'onp', 'pension_fund_code' => null, 'commission_scheme' => null,
        ]);

        return $e->setRawAttributes($e->getAttributes() + $dates + ['hire_date' => null, 'termination_date' => null]);
    }

    private static function d(string $date): DateTimeImmutable
    {
        return new DateTimeImmutable($date);
    }

    // ── Prorrateo ──────────────────────────────────────────────────────────────

    public function test_ingreso_a_mitad_de_mes_paga_los_dias_laborados(): void
    {
        $r = $this->payroll()->calculate($this->employee(['base_salary' => '3000.00', 'hire_date' => '2026-11-16']), PayrollContext::forMonth(2026, 11));

        $this->assertSame(PayrollResult::STATUS_OK, $r->status, (string) $r->error);
        $this->assertSame(15, $r->workedDays);
        $this->assertSame('1500.00', (string) $r->grossSalary);      // 3 000 ÷ 30 × 15
        $this->assertSame('195.00', (string) $r->pensionDeduction);
    }

    public function test_cese_a_mitad_de_mes_paga_hasta_el_ultimo_dia(): void
    {
        $r = $this->payroll()->calculate($this->employee(['base_salary' => '3000.00', 'hire_date' => '2024-03-10', 'termination_date' => '2026-11-20']), PayrollContext::forMonth(2026, 11));

        $this->assertSame(20, $r->workedDays);
        $this->assertSame('2000.00', (string) $r->grossSalary);
    }

    public function test_mes_completo_cuenta_30_dias_aunque_tenga_31_o_28(): void
    {
        foreach ([[2026, 10], [2026, 2]] as [$y, $m]) {
            $r = $this->payroll()->calculate($this->employee(['base_salary' => '3000.00', 'hire_date' => '2024-03-10']), PayrollContext::forMonth($y, $m));
            $this->assertSame(30, $r->workedDays);
            $this->assertSame('3000.00', (string) $r->grossSalary);
        }
    }

    public function test_cambio_de_sueldo_dentro_del_mes_se_paga_por_tramos(): void
    {
        $r = $this->payroll()->calculate(
            $this->employee(['base_salary' => '3000.00']),
            PayrollContext::forMonth(2026, 10, salaryChanges: [new SalaryChange(self::d('2026-10-21'), BigDecimal::of('3600'))]),
        );

        $this->assertSame(30, $r->workedDays);
        $this->assertSame('3200.00', (string) $r->grossSalary);   // 3 000 ÷ 30 × 20 + 3 600 ÷ 30 × 10
    }

    public function test_sin_dias_en_el_periodo_queda_con_error(): void
    {
        $r = $this->payroll()->calculate($this->employee(['base_salary' => '3000.00', 'hire_date' => '2026-12-01']), PayrollContext::forMonth(2026, 11));

        $this->assertSame(PayrollResult::STATUS_ERROR, $r->status);
        $this->assertStringContainsString('no tiene días laborados', (string) $r->error);
    }

    public function test_asignacion_familiar_no_corresponde_en_pequena_empresa(): void
    {
        $r = $this->payroll()->calculate($this->employee(['base_salary' => '2000.00', 'labor_regime_code' => 'pe_pequena', 'has_family_allowance' => true]), PayrollContext::forMonth(2026, 11));

        $this->assertSame('0.00', (string) $r->familyAllowance);
        $this->assertArrayHasKey('asignacion_familiar_aplica@pe_pequena', $r->parametersUsed);
    }

    // ── Quinta categoría ───────────────────────────────────────────────────────

    public function test_escala_acumulativa_de_quinta(): void
    {
        $calc = new FifthCategoryCalculator(fn (string $c) => $this->params->get($c, null, self::d('2026-11-30')));
        $scale = $this->params->get('quinta_escala', null, self::d('2026-11-30'))->json();

        // 5 UIT al 8 %, 15 al 14 %, 15 al 17 %, 10 al 20 % y el resto al 30 %, con UIT 5 500.
        $this->assertSame('54525.00', (string) $calc->tax(BigDecimal::of('300000'), BigDecimal::of('5500'), $scale));
        $this->assertSame('2886.00', (string) $calc->tax(BigDecimal::of('32400'), BigDecimal::of('5500'), $scale));
    }

    public function test_quinta_de_enero_divide_entre_12(): void
    {
        $r = $this->payroll()->calculate(
            $this->employee(['base_salary' => '5000.00']),
            PayrollContext::forMonth(2026, 1, fifthCategoryHistory: new FifthCategoryHistory(BigDecimal::zero(), [], BigDecimal::of('10900'))),
        );

        // 5 000 × 12 + 10 900 de gratificaciones − 7 UIT = 32 400 → impuesto 2 886 ÷ 12
        $this->assertSame('240.50', (string) $r->deductions[1]->amount);
        $this->assertSame('70900.00', $r->fifthCategory['projected_annual_income']);
        $this->assertSame(12, $r->fifthCategory['divisor']);
    }

    public function test_quinta_de_noviembre_resta_retenciones_hasta_agosto_y_divide_entre_4(): void
    {
        $withheld = array_fill(1, 10, BigDecimal::of('700'));   // setiembre y octubre no se restan
        $history = new FifthCategoryHistory(BigDecimal::of('110900'), $withheld, BigDecimal::of('10900'));

        $r = $this->payroll()->calculate($this->employee(['base_salary' => '10000.00']), PayrollContext::forMonth(2026, 11, fifthCategoryHistory: $history));

        $this->assertSame('141800.00', $r->fifthCategory['projected_annual_income']);
        $this->assertSame('12812.00', $r->fifthCategory['annual_tax']);
        $this->assertSame('5600.00', $r->fifthCategory['previous_withholdings']);
        $this->assertSame('1803.00', $r->fifthCategory['withholding']);   // (12 812 − 5 600) ÷ 4
    }

    public function test_pago_extraordinario_retiene_su_impuesto_completo_en_el_mes(): void
    {
        $history = new FifthCategoryHistory(BigDecimal::of('110900'), array_fill(1, 10, BigDecimal::of('700')), BigDecimal::of('10900'));
        $bonus = new Line('bono_desempeno', 'Bono por desempeño', BigDecimal::of('5000'), computable: false, extraordinary: true);

        $r = $this->payroll()->calculate($this->employee(['base_salary' => '10000.00']), PayrollContext::forMonth(2026, 11, earnings: [$bonus], fifthCategoryHistory: $history));

        $this->assertSame('1803.00', $r->fifthCategory['ordinary_withholding']);
        $this->assertSame('700.00', $r->fifthCategory['extraordinary_withholding']);  // 5 000 × 14 %
        $this->assertSame('2503.00', $r->fifthCategory['withholding']);
    }

    public function test_quinta_en_mes_de_ingreso_proyecta_el_sueldo_completo(): void
    {
        $r = $this->payroll()->calculate(
            $this->employee(['base_salary' => '9000.00', 'hire_date' => '2026-11-16']),
            PayrollContext::forMonth(2026, 11, fifthCategoryHistory: new FifthCategoryHistory(BigDecimal::zero(), [], BigDecimal::of('9810'))),
        );

        // 4 500 de noviembre + 9 000 de diciembre + gratificación de diciembre 9 810 = 23 310, menos de 7 UIT
        $this->assertSame('23310.00', $r->fifthCategory['projected_annual_income']);
        $this->assertSame('0.00', $r->fifthCategory['withholding']);
    }

    // ── Liquidación ────────────────────────────────────────────────────────────

    private function settlement(): SettlementService
    {
        return new SettlementService($this->params);
    }

    public function test_liquidacion_regimen_general(): void
    {
        // Régimen general: los días de vacaciones aún no tienen valor verificado en el catálogo.
        $this->params->put('vacaciones_dias', 'pe_general', 30, '2025-01-01', verified: false, unit: 'days');
        $e = $this->employee(['base_salary' => '3000.00', 'has_family_allowance' => true, 'hire_date' => '2024-03-10', 'termination_date' => '2026-11-20']);

        $r = $this->settlement()->calculate($e, new SettlementContext(pendingVacationDays: 30));

        $this->assertSame(SettlementResult::STATUS_OK, $r->status, (string) $r->error);
        $this->assertSame('3113.00', (string) $r->computableRemuneration);          // 3 000 + asignación familiar 113
        $this->assertSame('2075.33', $r->amount('gratificacion_trunca'));           // 3 113 ÷ 6 × 4 (julio a octubre)
        $this->assertSame('186.78', $r->amount('bonif_extraordinaria_trunca'));     // 9 %
        $this->assertSame('172.94', $r->amount('cts_trunca'));                      // 3 113 ÷ 360 × 20 días de noviembre
        $this->assertSame('2170.45', $r->amount('vacaciones_truncas'));             // 3 113 × (8 ÷ 12 + 11 ÷ 360)
        $this->assertSame('3113.00', $r->amount('vacaciones_pendientes'));          // 30 días ganados
        $this->assertSame('686.85', $r->amount('onp_aporte'));                      // 13 % solo de las vacaciones
        $this->assertSame('475.51', $r->amount('essalud'));                         // 9 % solo de las vacaciones
        $this->assertSame(['from' => '2026-03-10', 'to' => '2026-11-20', 'months' => 8, 'days' => 11, 'pending_days' => 30], $r->periods['vacaciones']);
        $this->assertContains('El parámetro «vacaciones_dias» está sin confirmar.', $r->warnings);
    }

    public function test_liquidacion_regimen_general_sin_dias_de_vacaciones_queda_con_error(): void
    {
        $r = $this->settlement()->calculate($this->employee(['base_salary' => '3000.00', 'hire_date' => '2024-03-10', 'termination_date' => '2026-11-20']));

        $this->assertSame(SettlementResult::STATUS_ERROR, $r->status);
        $this->assertStringContainsString('vacaciones_dias', (string) $r->error);
    }

    public function test_liquidacion_pequena_empresa(): void
    {
        $e = $this->employee(['base_salary' => '2000.00', 'labor_regime_code' => 'pe_pequena', 'has_family_allowance' => true, 'hire_date' => '2025-08-01', 'termination_date' => '2026-03-31']);

        $r = $this->settlement()->calculate($e, new SettlementContext(gratificationInCtsPeriod: BigDecimal::of('833.33')));

        $this->assertSame('2000.00', (string) $r->computableRemuneration);          // sin asignación familiar
        $this->assertSame('500.00', $r->amount('gratificacion_trunca'));            // 2 000 × 0,5 ÷ 6 × 3
        $this->assertSame('45.00', $r->amount('bonif_extraordinaria_trunca'));
        $this->assertSame(['from' => '2025-11-01', 'to' => '2026-03-31', 'months' => 5, 'days' => 0], $r->periods['cts']);
        $this->assertSame('445.60', $r->amount('cts_trunca'));                      // (2 000 + 833,33 ÷ 6) ÷ 12 × 5 × 0,5
        $this->assertSame('666.67', $r->amount('vacaciones_truncas'));              // 2 000 × 15 ÷ 30 × 8 ÷ 12
    }

    public function test_liquidacion_microempresa_solo_vacaciones_y_sin_aporte_al_sis(): void
    {
        $e = $this->employee(['base_salary' => '1500.00', 'labor_regime_code' => 'pe_micro', 'health_scheme' => 'sis', 'hire_date' => '2026-01-01', 'termination_date' => '2026-06-30']);

        $r = $this->settlement()->calculate($e);

        $this->assertSame(SettlementResult::STATUS_OK, $r->status, (string) $r->error);
        $this->assertNull($r->amount('gratificacion_trunca'));
        $this->assertNull($r->amount('cts_trunca'));
        $this->assertSame('375.00', $r->amount('vacaciones_truncas'));              // 1 500 × 15 ÷ 30 × 6 ÷ 12
        $this->assertNull($r->amount('sis_mype'));
    }

    public function test_liquidacion_sin_fecha_de_cese_queda_con_error(): void
    {
        $r = $this->settlement()->calculate($this->employee(['base_salary' => '3000.00', 'hire_date' => '2024-03-10']));

        $this->assertSame(SettlementResult::STATUS_ERROR, $r->status);
        $this->assertStringContainsString('fecha de ingreso o de cese', (string) $r->error);
    }

    public function test_conteo_de_tiempo_de_servicios(): void
    {
        $this->assertSame(['months' => 4, 'days' => 20], ServiceTime::monthsAndDays(self::d('2026-07-01'), self::d('2026-11-20')));
        $this->assertSame(2, ServiceTime::fullCalendarMonths(self::d('2026-03-15'), self::d('2026-06-20')));
        $this->assertSame('2026-02-28', ServiceTime::addMonths(self::d('2026-01-31'), 1)->format('Y-m-d'));
    }
}
