<?php

/**
 * IMP-5 · Organización demo coherente: estructura con jefaturas, contratos, sueldos e historia, con fechas
 * relativas al día de carga, por los servicios de la aplicación e idempotente.
 */

use App\Shared\Tenancy\TenancyScope;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses(DatabaseTransactions::class);

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requiere PostgreSQL.');
    }
    TenancyScope::clear();
    Storage::fake('local');
    Storage::fake('s3');
});

afterEach(function (): void {
    TenancyScope::clear();
});

test('la semilla demo recorre estructura, contratos, vacaciones, ausencias, movimientos, cese y planilla con fechas relativas', function (): void {
    $this->seed(DatabaseSeeder::class);
    $tenant = (string) DB::table('tenants')->where('slug', 'demo')->value('id');

    TenancyScope::runAs($tenant, function (): void {
        expect(DB::table('org_units')->pluck('code')->all())->toContain('gerencia_general', 'recursos_humanos', 'comercial', 'tecnologia', 'operaciones_inti');

        // Fechas relativas al día de carga: la historia termina hoy y lo próximo cae cerca.
        $first = (string) DB::table('employments')->min('hire_date');
        $last = (string) DB::table('employments')->max('hire_date');
        expect($first)->toBeGreaterThanOrEqual(now()->subMonths(32)->toDateString())
            ->and($last)->toBe(now()->addDays(14)->toDateString());

        // Cada colaborador tiene contrato y sueldo; hay contratos a plazo que vencen pronto.
        $employments = DB::table('employments')->count();
        expect(DB::table('contracts')->distinct()->count('employment_id'))->toBe($employments)
            ->and(DB::table('employment_compensations')->distinct()->count('employment_id'))->toBe($employments);

        // Paquete del país aplicado y la vacante en reclutamiento.
        expect(DB::table('tenant_country_packs')->where('country_pack_code', 'pe-base')->exists())->toBeTrue()
            ->and(DB::table('positions')->where('code', 'P-ANL')->value('status'))->toBe('recruiting');

        // Historia: vacaciones gozadas, aprobadas y en aprobación; ausencia en curso; promoción en flujo; un cese.
        expect(DB::table('vacation_requests')->pluck('status')->unique()->sort()->values()->all())->toBe(['approved', 'pending', 'taken'])
            ->and(DB::table('absence_requests')->count())->toBe(1)
            ->and(DB::table('personnel_actions')->where('kind', 'promotion')->exists())->toBeTrue()
            ->and(DB::table('employments')->where('status', 'terminated')->count())->toBe(1);
    });

    // Planilla calculada por el motor: meses cerrados con boletas, el mes en curso abierto y la liquidación del cese.
    TenancyScope::runAs($tenant, function (): void {
        // Nómina Perú contratada y activa, con los conceptos del paquete del país.
        expect(DB::table('tenant_modules')->where('module_code', 'payroll')->value('status'))->toBe('active')
            ->and(DB::table('payroll_concepts')->where('is_system', true)->count())->toBeGreaterThan(20);

        // Un grupo por empresa (régimen general y pequeña empresa).
        $groups = DB::table('payroll_groups')->orderBy('code')->pluck('id', 'code');
        expect($groups->keys()->all())->toBe(['ANDES', 'INTI']);

        $closedMonths = now()->month >= 3 ? now()->month - 1 : 2;
        foreach ($groups as $group) {
            $periods = DB::table('payroll_periods')->where('payroll_group_id', $group)->orderBy('starts_on')->get(['id', 'status', 'starts_on']);
            expect($periods->where('status', 'closed')->count())->toBe($closedMonths)
                ->and($periods->last()->status)->toBe('open')
                ->and(substr((string) $periods->last()->starts_on, 0, 10))->toBe(now()->startOfMonth()->toDateString());
            // Cada mes cerrado: corrida del motor sin errores y una boleta publicada por trabajador calculado.
            foreach ($periods->where('status', 'closed') as $p) {
                $run = DB::table('payroll_runs')->where('payroll_period_id', $p->id)->where('status', 'closed')->value('id');
                $rows = DB::table('payroll_run_employments')->where('payroll_run_id', $run);
                expect((clone $rows)->where('status', 'error')->count())->toBe(0)
                    ->and(DB::table('payslips')->where('payroll_period_id', $p->id)->whereIn('status', ['published', 'viewed', 'acknowledged'])->count())
                    ->toBe((clone $rows)->where('status', 'calculated')->count());
            }
        }

        // El cálculo es del motor: neto = ingresos − descuentos en cada trabajador del último mes cerrado.
        $last = DB::table('payroll_runs')->where('status', 'closed')->orderByDesc('created_at')->value('id');
        foreach (DB::table('payroll_run_employments')->where('payroll_run_id', $last)->get(['gross_amount', 'deductions_amount', 'net_amount']) as $r) {
            expect(bcsub((string) $r->gross_amount, (string) $r->deductions_amount, 2))->toBe((string) $r->net_amount);
        }

        // Una boleta acusada, incidencias en el mes en curso y la liquidación en borrador del cese.
        expect(DB::table('payslips')->where('status', 'acknowledged')->count())->toBe(1)
            ->and(DB::table('payroll_incidents as i')->join('payroll_periods as p', 'p.id', '=', 'i.payroll_period_id')->where('p.status', 'open')->count())->toBeGreaterThan(0)
            ->and(DB::table('settlements')->where('status', 'draft')->count())->toBe(1);

        // Las vacaciones en aprobación llegan a la bandeja del administrador demo: su rol decide en el flujo y las pidió
        // cada trabajador desde su cuenta (nadie aprueba en su bandeja lo que inició).
        $admin = (string) DB::table('users')->orderBy('created_at')->value('id');
        $adminRoles = DB::table('user_roles')->where('user_id', $admin)->pluck('role_id')->all();
        $inbox = DB::table('workflow_tasks as t')->join('workflow_instances as i', 'i.id', '=', 't.workflow_instance_id')
            ->where('i.subject_type', 'vacation_request')->where('t.status', 'pending')->whereIn('t.assignee_role_id', $adminRoles)
            ->where('i.started_by_user_id', '<>', $admin)->distinct()->count('i.id');
        expect($inbox)->toBeGreaterThanOrEqual(1);
    });

    // Idempotente: una segunda carga no duplica nada.
    $counts = fn (): array => TenancyScope::runAs($tenant, fn () => [DB::table('employments')->count(), DB::table('vacation_requests')->count(), DB::table('tenant_country_packs')->count(),
        DB::table('payroll_periods')->count(), DB::table('payslips')->count(), DB::table('settlements')->count()]);
    $before = $counts();
    $this->seed(DatabaseSeeder::class);
    expect($counts())->toBe($before);
});
