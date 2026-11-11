<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\LegalParameter;
use App\Models\PensionFund;
use App\Payroll\Calculation\PayrollContext;
use App\Payroll\Calculation\PayrollResult;
use App\Payroll\Settlement\SettlementService;
use App\Payroll\Tax\FifthCategoryHistory;
use App\Services\PayrollService;
use Brick\Math\BigDecimal;
use Database\Seeders\LegalParametersSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** La calculadora sobre PostgreSQL, con el catálogo del kit cargado por el seeder. */
final class PayrollServiceDatabaseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(LegalParametersSeeder::class);
    }

    public function test_calcula_desde_la_base_con_los_parametros_del_kit(): void
    {
        $employee = Employee::query()->create([
            'tenant_id' => (string) Str::uuid7(), 'full_name' => 'Ana Torres', 'base_salary' => '2000.00',
            'has_family_allowance' => true, 'labor_regime_code' => 'pe_general', 'pension_system' => 'onp',
        ]);

        $r = app(PayrollService::class)->calculate($employee->fresh(), PayrollContext::forMonth(2026, 11));

        $this->assertSame(PayrollResult::STATUS_OK, $r->status, (string) $r->error);
        $this->assertSame('1838.31', (string) $r->netSalary);
        $this->assertSame('205.43', (string) $r->provisions['prov_cts']);
        $this->assertSame('pe_general', $employee->laborRegime->code);
    }

    public function test_quinta_y_prorrateo_con_las_escalas_guardadas_en_la_base(): void
    {
        $employee = Employee::query()->create([
            'tenant_id' => (string) Str::uuid7(), 'full_name' => 'Rosa Quispe', 'base_salary' => '10000.00',
            'labor_regime_code' => 'pe_general', 'pension_system' => 'onp', 'hire_date' => '2020-01-01',
        ]);
        $history = new FifthCategoryHistory(BigDecimal::of('110900'), array_fill(1, 10, BigDecimal::of('700')), BigDecimal::of('10900'));

        $r = app(PayrollService::class)->calculate($employee->fresh(), PayrollContext::forMonth(2026, 11, fifthCategoryHistory: $history));

        $this->assertSame(PayrollResult::STATUS_OK, $r->status, (string) $r->error);
        $this->assertSame('1803.00', $r->fifthCategory['withholding']);
        $this->assertEquals([['hasta_uit' => 5, 'tasa' => 8], ['hasta_uit' => 20, 'tasa' => 14], ['hasta_uit' => 35, 'tasa' => 17], ['hasta_uit' => 45, 'tasa' => 20], ['hasta_uit' => null, 'tasa' => 30]], $r->parametersUsed['quinta_escala']['value']);
    }

    public function test_liquidacion_desde_la_base(): void
    {
        $employee = Employee::query()->create([
            'tenant_id' => (string) Str::uuid7(), 'full_name' => 'Pedro Huamán', 'base_salary' => '2000.00',
            'labor_regime_code' => 'pe_pequena', 'pension_system' => 'onp', 'hire_date' => '2025-08-01', 'termination_date' => '2026-03-31',
        ]);

        $r = app(SettlementService::class)->calculate($employee->fresh());

        $this->assertSame('ok', $r->status, (string) $r->error);
        $this->assertSame('500.00', $r->amount('gratificacion_trunca'));
        $this->assertSame('416.67', $r->amount('cts_trunca'));        // 2 000 ÷ 12 × 5 × 0,5, sin gratificación en el periodo
        $this->assertSame('666.67', $r->amount('vacaciones_truncas'));
        $this->assertSame('86.67', $r->amount('onp_aporte'));         // 13 % de las vacaciones truncas
    }

    public function test_afp_sin_tasas_del_periodo_queda_con_error(): void
    {
        PensionFund::query()->create(['code' => 'AFP_PRUEBA', 'country_code' => 'PE', 'name' => 'AFP de prueba', 'kind' => 'private']);
        $employee = Employee::query()->create([
            'tenant_id' => (string) Str::uuid7(), 'full_name' => 'Luis Paredes', 'base_salary' => '2500.00',
            'labor_regime_code' => 'pe_general', 'pension_system' => 'afp', 'pension_fund_code' => 'AFP_PRUEBA', 'commission_scheme' => 'flow',
        ]);

        $r = app(PayrollService::class)->calculate($employee->fresh(), PayrollContext::forMonth(2026, 11));

        $this->assertSame(PayrollResult::STATUS_ERROR, $r->status);
        $this->assertStringContainsString('AFP_PRUEBA', (string) $r->error);
    }

    public function test_la_base_rechaza_dos_valores_del_mismo_parametro_con_vigencias_que_se_cruzan(): void
    {
        $this->expectException(QueryException::class);
        LegalParameter::query()->create([
            'country_code' => 'PE', 'code' => 'rmv', 'name' => 'RMV duplicada', 'value_numeric' => 1200,
            'unit' => 'amount', 'valid_from' => '2026-06-01', 'verified' => true,
        ]);
    }

    public function test_los_identificadores_son_uuid_v7(): void
    {
        $employee = Employee::query()->create([
            'tenant_id' => (string) Str::uuid7(), 'full_name' => 'Sofía Navarro', 'base_salary' => '1800.00',
            'labor_regime_code' => 'pe_pequena', 'pension_system' => 'onp',
        ]);

        $this->assertSame('7', $employee->getKey()[14]);
    }
}
