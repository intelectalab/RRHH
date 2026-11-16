<?php

/**
 * IMP-5 · Organización demo coherente: estructura con jefaturas, contratos, sueldos e historia, con fechas
 * relativas al día de carga, por los servicios de la aplicación e idempotente.
 */

use App\Shared\Tenancy\TenancyScope;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;

uses(DatabaseTransactions::class);

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requiere PostgreSQL.');
    }
    TenancyScope::clear();
});

afterEach(function (): void {
    TenancyScope::clear();
});

test('la semilla demo recorre estructura, contratos, vacaciones, ausencias, movimientos y cese con fechas relativas', function (): void {
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

    // Idempotente: una segunda carga no duplica nada.
    $counts = fn (): array => TenancyScope::runAs($tenant, fn () => [DB::table('employments')->count(), DB::table('vacation_requests')->count(), DB::table('tenant_country_packs')->count()]);
    $before = $counts();
    $this->seed(DatabaseSeeder::class);
    expect($counts())->toBe($before);
});
