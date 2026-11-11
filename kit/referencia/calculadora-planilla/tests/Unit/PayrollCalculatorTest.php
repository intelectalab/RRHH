<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Employee;
use App\Payroll\Calculation\Line;
use App\Payroll\Calculation\PayrollContext;
use App\Payroll\Calculation\PayrollResult;
use App\Payroll\Parameters\InMemoryParameterRepository;
use App\Payroll\Parameters\PensionFundRates;
use App\Payroll\Steps;
use App\Services\PayrollService;
use Brick\Math\BigDecimal;
use PHPUnit\Framework\TestCase;

/**
 * Casos de referencia con los parámetros del catálogo del kit (RMV S/ 1 130, ONP 13 %, EsSalud 9 %…).
 * Las tasas de la AFP son de prueba: en el producto se cargan de la fuente oficial cada periodo.
 */
final class PayrollCalculatorTest extends TestCase
{
    private InMemoryParameterRepository $params;

    protected function setUp(): void
    {
        $this->params = InMemoryParameterRepository::fromKitCatalog(__DIR__.'/../../kit/database/catalogos/parametros_legales_pe.json');
        $this->params->putPensionFundRates(new PensionFundRates(
            fundCode: 'AFP_PRUEBA',
            contributionRate: BigDecimal::of('10'),
            commissionFlowRate: BigDecimal::of('1.50'),
            commissionMixedRate: BigDecimal::of('0'),
            insurancePremiumRate: BigDecimal::of('1.37'),
            maxInsurableRemuneration: BigDecimal::of('12000'),
            verified: false,
            validFrom: '2026-01-01',
        ));
    }

    private function service(): PayrollService
    {
        return new PayrollService($this->params, [
            new Steps\BaseSalaryStep, new Steps\FamilyAllowanceStep, new Steps\AdditionalEarningsStep,
            new Steps\PensionStep, new Steps\FifthCategoryTaxStep, new Steps\OtherDeductionsStep,
            new Steps\HealthContributionStep, new Steps\GratificationProvisionStep, new Steps\CtsProvisionStep,
        ]);
    }

    private function employee(array $attrs): Employee
    {
        return (new Employee)->forceFill($attrs + [
            'id' => '0193f2a0-0000-7000-8000-000000000001', 'full_name' => 'Colaborador de prueba',
            'has_family_allowance' => false, 'health_scheme' => 'essalud', 'pension_fund_code' => null, 'commission_scheme' => null,
        ]);
    }

    public function test_regimen_general_con_asignacion_familiar_y_onp(): void
    {
        $r = $this->service()->calculate(
            $this->employee(['base_salary' => '2000.00', 'labor_regime_code' => 'pe_general', 'has_family_allowance' => true, 'pension_system' => 'onp']),
            PayrollContext::forMonth(2026, 11),
        );

        $this->assertSame(PayrollResult::STATUS_OK, $r->status, (string) $r->error);
        $this->assertSame('113.00', (string) $r->familyAllowance);      // 10 % de la RMV 1 130
        $this->assertSame('2113.00', (string) $r->grossSalary);
        $this->assertSame('274.69', (string) $r->pensionDeduction);     // 13 % ONP
        $this->assertSame('1838.31', (string) $r->netSalary);
        $this->assertSame('190.17', (string) $r->essaludContribution);  // 9 %
        $this->assertSame('352.17', (string) $r->provisions['prov_gratificacion']);        // 2 113 ÷ 6
        $this->assertSame('31.70', (string) $r->provisions['prov_bonif_extraordinaria']);  // 9 % de la provisión
        $this->assertSame('205.43', (string) $r->provisions['prov_cts']);  // (2 113 + 2 113 × 1/6) ÷ 12
    }

    public function test_pequena_empresa_con_afp_media_gratificacion_y_media_cts(): void
    {
        $r = $this->service()->calculate(
            $this->employee(['base_salary' => '2000.00', 'labor_regime_code' => 'pe_pequena', 'pension_system' => 'afp', 'pension_fund_code' => 'AFP_PRUEBA', 'commission_scheme' => 'flow']),
            PayrollContext::forMonth(2026, 11),
        );

        $this->assertSame(PayrollResult::STATUS_OK, $r->status, (string) $r->error);
        $this->assertSame('257.40', (string) $r->pensionDeduction);     // 10 % + 1,50 % + 1,37 %
        $this->assertSame('180.00', (string) $r->essaludContribution);
        $this->assertSame('166.67', (string) $r->provisions['prov_gratificacion']);  // 2 000 × 0,5 ÷ 6
        $this->assertSame('15.00', (string) $r->provisions['prov_bonif_extraordinaria']);
        $this->assertSame('90.28', (string) $r->provisions['prov_cts']);  // (2 000 + 1 000 × 1/6) ÷ 12 × 15/30
        $this->assertContains('Las tasas de AFP_PRUEBA vigentes desde 2026-01-01 están sin confirmar.', $r->warnings);
    }

    public function test_prima_de_seguro_se_topa_en_la_remuneracion_maxima_asegurable(): void
    {
        $r = $this->service()->calculate(
            $this->employee(['base_salary' => '15000.00', 'labor_regime_code' => 'pe_general', 'pension_system' => 'afp', 'pension_fund_code' => 'AFP_PRUEBA', 'commission_scheme' => 'flow']),
            PayrollContext::forMonth(2026, 11),
        );

        $prima = collect($r->deductions)->firstWhere('code', 'afp_prima');
        $this->assertSame('164.40', (string) $prima->amount);  // 12 000 × 1,37 %, no 15 000 × 1,37 %
    }

    public function test_microempresa_sin_gratificacion_ni_cts_y_sis_sin_valor_queda_con_error(): void
    {
        $r = $this->service()->calculate(
            $this->employee(['base_salary' => '1130.00', 'labor_regime_code' => 'pe_micro', 'health_scheme' => 'sis', 'pension_system' => 'onp']),
            PayrollContext::forMonth(2026, 11),
        );

        // El aporte al SIS no tiene valor verificado en el catálogo: la relación queda con error (RN-PAR-04)
        // en lugar de calcularse con un número inventado.
        $this->assertSame(PayrollResult::STATUS_ERROR, $r->status);
        $this->assertStringContainsString('mype_sis_aporte', (string) $r->error);
    }

    public function test_microempresa_con_sis_cargado(): void
    {
        $this->params->put('mype_sis_aporte', 'pe_micro', '15.00', '2026-01-01');  // monto de prueba
        $r = $this->service()->calculate(
            $this->employee(['base_salary' => '1130.00', 'labor_regime_code' => 'pe_micro', 'health_scheme' => 'sis', 'pension_system' => 'onp']),
            PayrollContext::forMonth(2026, 11),
        );

        $this->assertSame(PayrollResult::STATUS_OK, $r->status, (string) $r->error);
        $this->assertSame([], $r->provisions);            // sin gratificación ni CTS
        $this->assertSame('15.00', (string) $r->essaludContribution);
        $this->assertSame('146.90', (string) $r->pensionDeduction);
    }

    public function test_un_cambio_de_norma_es_un_dato_con_vigencia_no_un_cambio_de_codigo(): void
    {
        $employee = $this->employee(['base_salary' => '1500.00', 'labor_regime_code' => 'pe_general', 'has_family_allowance' => true, 'pension_system' => 'onp']);
        $this->params->put('rmv', null, '1200', '2027-01-01', unit: 'amount');  // RMV hipotética

        $nov2026 = $this->service()->calculate($employee, PayrollContext::forMonth(2026, 11));
        $ene2027 = $this->service()->calculate($employee, PayrollContext::forMonth(2027, 1));

        $this->assertSame('113.00', (string) $nov2026->familyAllowance);
        $this->assertSame('120.00', (string) $ene2027->familyAllowance);
    }

    public function test_bonos_y_descuentos_nuevos_entran_sin_cambiar_la_firma(): void
    {
        $context = new PayrollContext(
            periodDate: new \DateTimeImmutable('2026-11-30'),
            earnings: [
                new Line('he_tramo1', 'Horas extra, primer tramo', BigDecimal::of('150.00'), remunerative: true, computable: false),
                new Line('movilidad', 'Movilidad supeditada a la asistencia', BigDecimal::of('100.00'), remunerative: false, computable: false),
            ],
            deductions: [new Line('dscto_prestamo', 'Descuento de préstamo', BigDecimal::of('200.00'))],
            fifthCategoryWithholding: BigDecimal::of('35.00'),
        );
        $r = $this->service()->calculate($this->employee(['base_salary' => '3000.00', 'labor_regime_code' => 'pe_general', 'pension_system' => 'onp']), $context);

        $this->assertSame('3250.00', (string) $r->grossSalary);
        $this->assertSame('409.50', (string) $r->pensionDeduction);   // 13 % de 3 150: la movilidad no es remunerativa
        $this->assertSame('644.50', (string) $r->totalDeductions);    // 409,50 + 35 + 200
        $this->assertSame('2605.50', (string) $r->netSalary);
        $this->assertSame('500.00', (string) $r->provisions['prov_gratificacion']);  // solo el sueldo es computable
    }

    public function test_el_resultado_registra_los_parametros_usados(): void
    {
        $r = $this->service()->calculate(
            $this->employee(['base_salary' => '2000.00', 'labor_regime_code' => 'pe_pequena', 'pension_system' => 'onp']),
            PayrollContext::forMonth(2026, 11),
        );

        $used = $r->toArray()['parameters_used'];
        $this->assertSame('0.500000', BigDecimal::of($used['gratificacion_factor@pe_pequena']['value'])->toScale(6)->__toString());
        $this->assertSame('DS 013-2013-PRODUCE', $used['gratificacion_factor@pe_pequena']['legal_source']);
        $this->assertArrayHasKey('cts_dias_por_anio', $used);  // valor general, usado como denominador
    }
}
