<?php

namespace Database\Seeders;

use App\Modules\CoreHR\Application\SocialSecurityService;
use App\Modules\CoreHR\Infrastructure\Models\Employment;
use App\Modules\Payroll\Application\LegalParameterCatalog;
use App\Modules\Payroll\Application\PayrollIncidents;
use App\Modules\Payroll\Application\PayrollPeriods;
use App\Modules\Payroll\Application\PayrollRuns;
use App\Modules\Payroll\Application\Payslips\Payslips;
use App\Modules\Payroll\Application\Settlements\Settlements;
use App\Modules\Payroll\Infrastructure\Models\PayrollGroup;
use App\Modules\Payroll\Infrastructure\Models\PayrollPeriod;
use App\Shared\LegalParameters\LegalParameters;
use App\Shared\Tenancy\TenancyScope;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Planilla de la organización demo, calculada por el motor real y con los mismos servicios que usa la aplicación:
 *
 * - Afiliación previsional de cada trabajador desde su ingreso: ONP. Las tasas de cada AFP (aporte, comisión, prima y
 *   tope) salen de la tabla mensual de la administradora (pension_fund_rates), que carga la plataforma; mientras no
 *   esté cargada, un afiliado a una AFP queda «con error» al calcular (RN-PAR-04) y el periodo no se puede cerrar.
 * - Un grupo de planilla mensual por empresa: Comercial Los Andes (régimen general) y Transportes Inti (pequeña empresa).
 * - Los meses del año hasta el anterior (al menos dos) calculados, aprobados y cerrados, con boletas publicadas en el
 *   portal (una ya acusada): la renta de quinta proyecta el año con lo ya pagado.
 * - El mes en curso abierto con incidencias: comisiones de ventas, horas extra por valorizar y una falta.
 * - La liquidación en borrador del trabajador que cesó (DemoStorySeeder).
 *
 * Los parámetros legales que el cálculo usa y siguen «por validar» se confirman a nombre del administrador demo
 * (RN-PAR-03): es lo que haría la organización antes de cerrar. Los montos de comisiones y bonos son de ejemplo
 * (no son valores legales); la falta se valoriza con los días base del mes, que es un parámetro legal.
 *
 * Idempotente: si la organización demo ya tiene grupos de planilla, no hace nada.
 */
class DemoPayrollSeeder extends Seeder
{
    public function run(): void
    {
        $tenantId = DB::table('tenants')->where('slug', DemoOrganizationSeeder::SLUG)->value('id');
        if ($tenantId === null) {
            return;
        }
        $wasUnguarded = Model::isUnguarded();
        Model::reguard();
        try {
            TenancyScope::runAs((string) $tenantId, function (): void {
                if (! DB::table('employments')->exists() || DB::table('payroll_groups')->exists()) {
                    return;
                }
                $admin = (string) DB::table('users')->orderBy('created_at')->value('id');
                $this->pensions();
                foreach ([['20999999901', 'ANDES', 'Comercial Los Andes · mensual'], ['20999999902', 'INTI', 'Transportes Inti · mensual']] as [$ruc, $code, $name]) {
                    $entity = DB::table('legal_entities')->where('tax_id', $ruc)->value('id');
                    if ($entity === null) {
                        continue;
                    }
                    $group = app(PayrollPeriods::class)->createGroup(['code' => $code, 'name' => $name, 'legal_entity_id' => (string) $entity,
                        'pay_frequency' => 'monthly', 'payment_day' => 28, 'cutoff_day' => 25]);
                    // Desde enero, para que la renta de quinta proyecte el año con lo ya pagado (como en una empresa real);
                    // en enero y febrero, los dos meses anteriores.
                    $closed = now()->month >= 3 ? now()->month - 1 : 2;
                    for ($monthsAgo = $closed; $monthsAgo >= 1; $monthsAgo--) {
                        $this->closedMonth($group, $monthsAgo, $admin);
                    }
                    $this->openMonth($group);
                }
                $this->acknowledgeOne();
                $this->settlement();
            });
        } finally {
            if ($wasUnguarded) {
                Model::unguard();
            }
        }
    }

    /** Afiliación previsional desde el ingreso para quien todavía no la tiene (COL-7, con vigencia). */
    private function pensions(): void
    {
        $service = app(SocialSecurityService::class);
        $rows = Employment::query()->whereNotIn('id', DB::table('employment_pension_affiliations')->select('employment_id'))->get();
        foreach ($rows as $e) {
            $service->changePension($e, ['pension_system' => 'onp', 'valid_from' => $e->hire_date->toDateString()]);
        }
    }

    /** Mes anterior completo: incidencias, cálculo con el motor, envío, aprobación, cierre y boletas publicadas. */
    private function closedMonth(PayrollGroup $group, int $monthsAgo, string $admin): void
    {
        $month = Carbon::parse(DemoDates::monthsAgo($monthsAgo));
        $periods = app(PayrollPeriods::class);
        $period = $periods->open($group, ['period_year' => $month->year, 'period_number' => $month->month], null, false);
        $this->commissions($period, $monthsAgo);
        $periods->calculate($period, null);
        $this->processRuns($period);
        $period->refresh();
        $periods->transition($period, 'submit', null, ['confirm_anomalies' => true]);
        $periods->transition($period->refresh(), 'approve', $admin);
        $this->confirmParameters($period->id, $admin);
        $periods->transition($period->refresh(), 'close', $admin);
        app(Payslips::class)->generate($period->id, $admin, true);
    }

    /** Mes en curso abierto con incidencias por revisar. */
    private function openMonth(PayrollGroup $group): void
    {
        $month = Carbon::parse(DemoDates::monthsAgo(0));
        $period = app(PayrollPeriods::class)->open($group, ['period_year' => $month->year, 'period_number' => $month->month], null, false);
        $this->commissions($period, 0);
        $incidents = app(PayrollIncidents::class);
        $population = app(PayrollPeriods::class)->population($period);
        $drivers = Employment::query()->whereIn('id', $population)->whereIn('work_email', ['wilmer.ccori@demo.gestiahr.test', 'rodrigo.villanueva@demo.gestiahr.test'])->get();
        foreach ($drivers as $d) {
            // Horas extra por valorizar: la tasa del sobretiempo es un parámetro legal que la organización confirma.
            $incidents->create($period, ['employment_id' => $d->id, 'concept_code' => 'he_tramo1', 'quantity' => '6',
                'notes' => 'Ruta adicional a Chilca por campaña.'], null);
        }
        $absent = Employment::query()->whereIn('id', $population)->where('work_email', 'martin.ortiz@demo.gestiahr.test')->first();
        if ($absent !== null) {
            $incidents->create($period, ['employment_id' => $absent->id, 'concept_code' => 'faltas', 'quantity' => '1',
                'amount' => $this->dayValue($absent), 'notes' => 'Falta injustificada sin aviso.'], null);
        }
    }

    /** Comisiones de ventas y un bono de productividad de ejemplo (montos comerciales, no legales). */
    private function commissions(PayrollPeriod $period, int $monthsAgo): void
    {
        $incidents = app(PayrollIncidents::class);
        $population = app(PayrollPeriods::class)->population($period);
        $amounts = ['valeria.ramos' => ['850.00', '1120.00', '640.00', '980.00'], 'martin.ortiz' => ['520.00', '760.00', '410.00', '600.00'],
            'gabriela.nunez' => ['690.00', '580.00', '930.00', '720.00'], 'hugo.mendoza' => ['300.00', '450.00', '380.00', '520.00']];
        foreach ($amounts as $who => $byMonth) {
            $e = Employment::query()->whereIn('id', $population)->where('work_email', $who.'@demo.gestiahr.test')->first();
            if ($e !== null) {
                $incidents->create($period, ['employment_id' => $e->id, 'concept_code' => 'comisiones', 'amount' => $byMonth[$monthsAgo % count($byMonth)],
                    'notes' => 'Comisión por ventas del mes.'], null);
            }
        }
        $dev = Employment::query()->whereIn('id', $population)->where('work_email', 'daniela.cornejo@demo.gestiahr.test')->first();
        if ($dev !== null && $monthsAgo === 1) {
            $incidents->create($period, ['employment_id' => $dev->id, 'concept_code' => 'bono_productividad', 'amount' => '500.00',
                'notes' => 'Entrega del portal de clientes.'], null);
        }
    }

    /** Valor de un día con los días base del mes (parámetro legal), sobre el sueldo vigente. */
    private function dayValue(Employment $e): string
    {
        $salary = (string) DB::table('employment_compensations')->where('employment_id', $e->id)->whereNull('valid_to')->value('base_salary');
        $value = app(LegalParameters::class)->at('PE', 'dias_base_mes')['value'] ?? null;
        $days = is_string($value) ? $value : '';
        if ($days === '' || BigDecimal::of($days)->isZero()) {
            return '0.00';
        }

        return (string) BigDecimal::of($salary)->dividedBy(BigDecimal::of($days), 2, RoundingMode::HALF_UP);
    }

    /** El cálculo corre en segundo plano en la aplicación; aquí se procesa en el momento. */
    private function processRuns(PayrollPeriod $period): void
    {
        $runs = DB::table('payroll_runs')->where('payroll_period_id', $period->id)->whereIn('status', ['queued', 'running'])->pluck('id');
        foreach ($runs as $run) {
            app(PayrollRuns::class)->process((string) $run);
        }
    }

    /** RN-PAR-03 · Confirma los parámetros «por validar» que usó el cálculo del periodo (lo que exige el cierre). */
    private function confirmParameters(string $periodId, string $admin): void
    {
        $run = DB::table('payroll_runs')->where('payroll_period_id', $periodId)->whereIn('status', ['approved', 'completed'])->orderByDesc('run_number')->value('parameters_snapshot');
        $snapshot = json_decode((string) $run, true);
        $catalog = app(LegalParameterCatalog::class);
        foreach (is_array($snapshot) ? $snapshot : [] as $param) {
            if (! is_array($param) || ($param['pending_validation'] ?? false) !== true || ($param['origin'] ?? '') === 'organization') {
                continue;
            }
            $code = (string) $param['code'];
            if (DB::table('legal_parameter_confirmations')->where('parameter_code', $code)->exists()) {
                continue;
            }
            $id = isset($param['id']) ? (string) $param['id']
                : DB::table('legal_parameters')->where('code', $code)->orderByDesc('valid_from')->value('id');
            if ($id !== null) {
                $catalog->confirm((string) $id, 'Confirmado para la organización demo.', $admin);
            }
        }
    }

    /** Una boleta del último mes vista y acusada por su titular (BOL-2). */
    private function acknowledgeOne(): void
    {
        $month = Carbon::parse(DemoDates::monthsAgo(1));
        $id = DB::table('payslips as p')->join('payroll_periods as pp', 'pp.id', '=', 'p.payroll_period_id')
            ->join('employments as e', 'e.id', '=', 'p.employment_id')
            ->where('pp.period_year', $month->year)->where('pp.period_number', $month->month)
            ->where('e.work_email', 'lucia.fernandez@demo.gestiahr.test')->value('p.id');
        if ($id !== null) {
            $payslips = app(Payslips::class);
            $payslips->recordView((string) $id, false, '190.40.10.21');
            $payslips->acknowledge((string) $id, '190.40.10.21', 'Mozilla/5.0 (Linux; Android 14) Chrome/130 Mobile');
        }
    }

    /** RN-LIQ-01 · Liquidación en borrador del cese registrado en la historia demo. */
    private function settlement(): void
    {
        $t = DB::table('terminations as t')->join('termination_reasons as r', 'r.id', '=', 't.termination_reason_id')
            ->whereNull('t.deleted_at')->whereNotIn('t.status', ['cancelled'])->orderByDesc('t.created_at')
            ->first(['t.id', 't.employment_id', 't.termination_date', 'r.code']);
        if ($t !== null) {
            app(Settlements::class)->createForTermination((string) $t->employment_id, (string) $t->id, substr((string) $t->termination_date, 0, 10), (string) $t->code);
        }
    }
}
