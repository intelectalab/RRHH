<?php

/**
 * NOM-6 a NOM-10 · Corridas en segundo plano con avance, reanudación sin duplicar y error por relación
 * (NOM-8); explicación de cada monto con versión del concepto y parámetros (NOM-6); recálculo retroactivo
 * que deja la diferencia en el periodo abierto sin tocar el cerrado (NOM-7); cálculo por tramos de régimen
 * y procesos que el régimen no genera (NOM-9); ventas, permanencia, propuesta de régimen y cambio masivo
 * de micro y pequeña empresa (NOM-10).
 */

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Payroll\Application\PayrollEngine;
use App\Modules\Payroll\Application\PayrollRuns;
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

const NRR_TENANT = 'aaaaaaaa-0e06-4000-8000-00000000000a';

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requiere PostgreSQL.');
    }
    TenancyScope::clear();
    DB::table('tenants')->insertOrIgnore(['id' => NRR_TENANT, 'slug' => 'nrr-org', 'name' => 'Grupo NRR', 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN']);
    DB::select('SELECT seed_tenant_defaults(?)', [NRR_TENANT]);
    TenancyScope::runAs(NRR_TENANT, fn () => IdentityFixtures::enableAllModules());
    $this->admin = nrrUser('admin_empresa');
    $this->employee = nrrUser('empleado');
    [$this->entity, $this->employment, $this->group] = TenancyScope::runAs(NRR_TENANT, function (): array {
        $entity = PayrollFixtures::entity();
        $employment = PayrollFixtures::employment($entity, '2025-01-01', [['amount' => '3000.00', 'valid_from' => '2025-01-01']]);
        PayrollFixtures::parameter('dias_base_mes', '30');
        PayrollFixtures::concept(['code' => 'BASICO', 'kind' => 'earning', 'formula' => 'sueldo_tramos', 'sort_order' => 1]);
        PayrollFixtures::concept(['code' => 'DESC', 'kind' => 'deduction', 'formula' => 'redondear(concepto.BASICO / 10, 2)', 'sort_order' => 2]);
        $group = (string) DB::table('payroll_groups')->insertGetId(['tenant_id' => NRR_TENANT, 'code' => 'G1', 'name' => 'Mensual', 'legal_entity_id' => $entity, 'currency' => 'PEN']);

        return [$entity, $employment, $group];
    });
});

afterEach(fn () => TenancyScope::clear());

function nrrUser(string $role): User
{
    return TenancyScope::runAs(NRR_TENANT, function () use ($role): User {
        $u = User::factory()->create();
        $u->roleAssignments()->create(['role_id' => Role::query()->where('system_role_code', $role)->value('id')]);

        return $u;
    });
}

/** @param array<string, mixed> $body */
function nrrCall(User $user, string $method, string $path, array $body = []): TestResponse
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

function nrrPeriod(string $group, int $month, string $status = 'open', string $kind = 'regular'): string
{
    $start = sprintf('2026-%02d-01', $month);

    return (string) DB::table('payroll_periods')->insertGetId(['tenant_id' => NRR_TENANT, 'payroll_group_id' => $group, 'period_year' => 2026, 'period_number' => $month,
        'kind' => $kind, 'starts_on' => $start, 'ends_on' => date('Y-m-t', (int) strtotime($start)), 'status' => $status]);
}

test('NOM-8 · la corrida se abre en cola, se procesa por relación con avance, una relación con error no detiene a las demás y reanudar no duplica', function (): void {
    [$period, $broken] = TenancyScope::runAs(NRR_TENANT, fn () => [nrrPeriod($this->group, 3), PayrollFixtures::employment($this->entity, '2025-01-01', [])]);

    nrrCall($this->employee, 'POST', '/payroll-runs', ['period_id' => $period])->assertStatus(403);
    $r = nrrCall($this->admin, 'POST', '/payroll-runs', ['period_id' => $period, 'kind' => 'calculation'])->assertCreated();
    $this->assertMatchesOpenApi($r, 'post', '/payroll-runs', 201);
    $runId = (string) $r->json('data.id');
    expect($r->json('data.status'))->toBe('queued')->and($r->json('data.progress.total'))->toBe(2)->and($r->json('data.progress.pending'))->toBe(2);
    $queued = TenancyScope::runAs(NRR_TENANT, fn () => DB::table('outbox_events')->where('event_type', 'payroll.run_requested.v1')->where('aggregate_id', $runId)->count());
    expect($queued)->toBe(1);

    TenancyScope::runAs(NRR_TENANT, fn () => app(PayrollRuns::class)->process($runId));
    $show = nrrCall($this->admin, 'GET', "/payroll-runs/{$runId}")->assertOk();
    $this->assertMatchesOpenApi($show, 'get', '/payroll-runs/{id}', 200);
    expect($show->json('data.status'))->toBe('completed')->and($show->json('data.progress'))->toMatchArray(['total' => 2, 'calculated' => 1, 'failed' => 1, 'pending' => 0, 'percent' => 100])
        ->and($show->json('data.totals.net'))->toBe('2700.00');

    // Una entrega doble del evento no repite nada; reanudar una corrida terminada no procede.
    TenancyScope::runAs(NRR_TENANT, fn () => app(PayrollRuns::class)->process($runId));
    expect(TenancyScope::runAs(NRR_TENANT, fn () => DB::table('payroll_run_employments')->where('payroll_run_id', $runId)->count()))->toBe(2);
    $again = nrrCall($this->admin, 'POST', "/payroll-runs/{$runId}/resume")->assertStatus(409);
    $this->assertMatchesOpenApi($again, 'post', '/payroll-runs/{id}/resume', 409);

    $emps = nrrCall($this->admin, 'GET', "/payroll-runs/{$runId}/employments")->assertOk();
    $this->assertMatchesOpenApi($emps, 'get', '/payroll-runs/{id}/employments', 200);
    $byId = collect($emps->json('data'))->keyBy('employment_id');
    expect($byId[$broken]['status'])->toBe('error')->and($byId[$broken]['errors'][0]['code'])->toBe('missing_salary')
        ->and($byId[$this->employment]['totals']['net']['amount'])->toBe('2700.00');

    // Una corrida a medias se reanuda y calcula solo lo que falta (mismo resultado).
    $partial = TenancyScope::runAs(NRR_TENANT, fn () => app(PayrollRuns::class)->open($period, ['employment_ids' => [$this->employment]], 'simulation', null, false));
    TenancyScope::runAs(NRR_TENANT, fn () => DB::table('payroll_runs')->where('id', $partial)->update(['status' => 'failed', 'failure_reason' => 'caída simulada']));
    $resumed = nrrCall($this->admin, 'POST', "/payroll-runs/{$partial}/resume")->assertOk();
    $this->assertMatchesOpenApi($resumed, 'post', '/payroll-runs/{id}/resume', 200);
    TenancyScope::runAs(NRR_TENANT, fn () => app(PayrollRuns::class)->process($partial));
    expect(nrrCall($this->admin, 'GET', "/payroll-runs/{$partial}")->json('data.totals.net'))->toBe('2700.00');

    $list = nrrCall($this->admin, 'GET', "/payroll-runs?filter[period_id]={$period}")->assertOk();
    $this->assertMatchesOpenApi($list, 'get', '/payroll-runs', 200);
    expect($list->json('meta.total'))->toBe(2);
    $discard = nrrCall($this->admin, 'POST', "/payroll-runs/{$partial}/discard")->assertOk();
    $this->assertMatchesOpenApi($discard, 'post', '/payroll-runs/{id}/discard', 200);

    // RN-NOM-03 · Un periodo cerrado no se recalcula.
    $closed = TenancyScope::runAs(NRR_TENANT, fn () => nrrPeriod($this->group, 2, 'closed'));
    $c = nrrCall($this->admin, 'POST', '/payroll-runs', ['period_id' => $closed])->assertStatus(409);
    $this->assertMatchesOpenApi($c, 'post', '/payroll-runs', 409);
});

test('NOM-6 · desde cada monto se llega a cómo se calculó, con la versión del concepto y los parámetros; sin permiso de traza no viaja', function (): void {
    $period = TenancyScope::runAs(NRR_TENANT, fn () => nrrPeriod($this->group, 3));
    $runId = TenancyScope::runAs(NRR_TENANT, fn () => app(PayrollEngine::class)->storeRun($period, [$this->employment], 'calculation', null));
    $row = (string) TenancyScope::runAs(NRR_TENANT, fn () => DB::table('payroll_run_employments')->where('payroll_run_id', $runId)->value('id'));

    $r = nrrCall($this->admin, 'GET', "/payroll-run-employments/{$row}/lines")->assertOk();
    $this->assertMatchesOpenApi($r, 'get', '/payroll-run-employments/{id}/lines', 200);
    $lines = collect($r->json('data'))->keyBy('concept_code');
    $basico = $lines['BASICO']['explanation'];
    expect($lines['BASICO']['amount']['amount'])->toBe('3000.00')
        ->and($basico['formula'])->toBe('sueldo_tramos')->and($basico['version']['concept_version_id'])->not->toBeNull()
        ->and($basico['version']['valid_from'])->toBe('2020-01-01')
        ->and(collect($basico['inputs'])->firstWhere('name', 'sueldo_tramos')['value'])->toStartWith('3000')
        ->and($basico['steps'])->not->toBeEmpty();
    $desc = $lines['DESC']['explanation'];
    expect(collect($desc['inputs'])->firstWhere('code', 'BASICO')['amount'])->toBe('3000.00');

    $e = nrrCall($this->admin, 'GET', '/payroll-run-lines/'.$lines['DESC']['id'].'/explanation')->assertOk();
    $this->assertMatchesOpenApi($e, 'get', '/payroll-run-lines/{id}/explanation', 200);
    expect($e->json('data.explanation.result.amount.amount'))->toBe('300.00');
    // Cada lectura de montos queda en el registro de accesos (regla 7).
    expect(TenancyScope::runAs(NRR_TENANT, fn () => DB::table('data_access_logs')->where('entity_type', 'PayrollRunLine')->count()))->toBeGreaterThan(0);

    nrrCall($this->employee, 'GET', "/payroll-run-employments/{$row}/lines")->assertStatus(403);
});

test('NOM-9 · un cambio de régimen a mitad de periodo calcula cada tramo con su estrategia y conserva la traza; un proceso que el régimen no genera queda excluido', function (): void {
    TenancyScope::runAs(NRR_TENANT, function (): void {
        PayrollFixtures::concept(['code' => 'SOLO_MICRO', 'kind' => 'earning', 'formula' => 'dias_relacion', 'sort_order' => 3, 'regimes' => ['pe_micro']]);
        DB::table('employment_labor_regimes')->insert([
            ['tenant_id' => NRR_TENANT, 'employment_id' => $this->employment, 'labor_regime_code' => 'pe_general', 'valid_from' => '2025-01-01', 'valid_to' => '2026-03-15'],
            ['tenant_id' => NRR_TENANT, 'employment_id' => $this->employment, 'labor_regime_code' => 'pe_micro', 'valid_from' => '2026-03-16', 'valid_to' => null],
        ]);
    });
    $calc = TenancyScope::runAs(NRR_TENANT, fn () => app(PayrollEngine::class)->calculate($this->employment, '2026-03-01', '2026-03-31'));
    $lines = collect($calc['result']['lines'])->keyBy('concept_code');
    expect($calc['result']['status'])->toBe('calculated')
        ->and($lines['BASICO']['amount']['amount'])->toBe('3000.00')
        ->and($lines['BASICO']['trace']['source'])->toBe('tramos')
        ->and(array_column($lines['BASICO']['trace']['tramos'], 'regime'))->toBe(['pe_general', 'pe_micro'])
        ->and(array_column($lines['BASICO']['trace']['tramos'], 'amount'))->toBe(['1500.00', '1500.00'])
        ->and($lines['SOLO_MICRO']['amount']['amount'])->toBe('15.00')
        ->and($calc['context']['tramos'])->toHaveCount(2);

    // RN-PE-MYP-08 · La microempresa no genera gratificación: la relación queda fuera de ese proceso.
    $grat = TenancyScope::runAs(NRR_TENANT, fn () => app(PayrollEngine::class)->calculate($this->employment, '2026-07-01', '2026-07-31', 'gratification'));
    expect($grat['result']['status'])->toBe('excluded')->and($grat['result']['warnings'][0]['code'])->toBe('process_not_applicable');
});

test('NOM-7 · un cambio con fecha pasada recalcula en sombra el periodo cerrado y deja la diferencia en el abierto con su origen; repetirlo no duplica', function (): void {
    [$closed, $open] = TenancyScope::runAs(NRR_TENANT, fn () => [nrrPeriod($this->group, 2), nrrPeriod($this->group, 3)]);
    $runId = TenancyScope::runAs(NRR_TENANT, fn () => app(PayrollEngine::class)->storeRun($closed, [$this->employment], 'calculation', null));
    TenancyScope::runAs(NRR_TENANT, function () use ($closed, $runId): void {
        DB::table('payroll_runs')->where('id', $runId)->update(['status' => 'closed']);
        DB::table('payroll_periods')->where('id', $closed)->update(['status' => 'closed']);
        // Corrección retroactiva del sueldo desde febrero (en la historia con vigencia).
        DB::table('employment_compensations')->where('employment_id', $this->employment)->update(['valid_to' => '2026-01-31']);
        DB::table('employment_compensations')->insert(['tenant_id' => NRR_TENANT, 'employment_id' => $this->employment, 'base_salary' => '3300.00', 'currency' => 'PEN', 'valid_from' => '2026-02-01']);
    });
    $paidBefore = TenancyScope::runAs(NRR_TENANT, fn () => DB::table('payroll_run_lines')->where('payroll_run_id', $runId)->orderBy('id')->pluck('amount')->all());

    $preview = nrrCall($this->admin, 'POST', "/employments/{$this->employment}/payroll-retroactive", ['from' => '2026-02-01', 'preview' => true])->assertOk();
    $this->assertMatchesOpenApi($preview, 'post', '/employments/{employment_id}/payroll-retroactive', 200);
    $diff = collect($preview->json('data.0.differences'))->keyBy('concept_code');
    expect($preview->json('data.0.period'))->toBe('2026-02')->and($preview->json('data.0.target_period_id'))->toBe($open)
        ->and($diff['BASICO']['difference'])->toBe('300.00')->and($diff['DESC']['difference'])->toBe('30.00');
    expect(TenancyScope::runAs(NRR_TENANT, fn () => DB::table('payroll_incidents')->where('source', 'retroactive')->count()))->toBe(0);

    nrrCall($this->admin, 'POST', "/employments/{$this->employment}/payroll-retroactive", ['from' => '2026-02-01', 'reason' => 'Aumento con fecha pasada'])->assertOk();
    nrrCall($this->admin, 'POST', "/employments/{$this->employment}/payroll-retroactive", ['from' => '2026-02-01'])->assertOk();
    $incidents = TenancyScope::runAs(NRR_TENANT, fn () => DB::table('payroll_incidents')->where('source', 'retroactive')->whereNull('deleted_at')->orderBy('amount')->get());
    expect($incidents)->toHaveCount(2)->and($incidents->pluck('payroll_period_id')->unique()->all())->toBe([$open])
        ->and($incidents->pluck('retro_period_id')->unique()->all())->toBe([$closed])
        ->and($incidents->pluck('amount')->all())->toBe(['30.00', '300.00']);
    // El periodo cerrado no se tocó.
    expect(TenancyScope::runAs(NRR_TENANT, fn () => DB::table('payroll_run_lines')->where('payroll_run_id', $runId)->orderBy('id')->pluck('amount')->all()))->toBe($paidBefore);

    // El cálculo del periodo abierto incluye las diferencias como líneas propias con el periodo de origen.
    $calc = TenancyScope::runAs(NRR_TENANT, fn () => app(PayrollEngine::class)->calculate($this->employment, '2026-03-01', '2026-03-31', 'regular', $open));
    $retro = collect($calc['result']['lines'])->where('origin', 'retroactive')->values();
    expect($retro)->toHaveCount(2)->and($retro[0]['trace']['origin_period']['label'])->toBe('2026-02')
        ->and($calc['result']['totals']['net'])->toBe('3240.00');
});

test('NOM-10 · ventas en UIT, permanencia al superar el tope dos ejercicios, régimen propuesto a nuevos ingresos y cambio masivo', function (): void {
    $micro = TenancyScope::runAs(NRR_TENANT, function (): string {
        $entity = PayrollFixtures::entity('pe_micro', 'accredited');
        DB::table('legal_entity_regimes')->where('legal_entity_id', $entity)->update(['registered_on' => '2025-06-01', 'registry_number' => 'REMYPE-1']);
        PayrollFixtures::parameter('mype_ventas_max_uit', '150');
        PayrollFixtures::parameter('mype_anios_consecutivos_exceso', '2');
        PayrollFixtures::parameter('mype_permanencia_anios', '1');

        return $entity;
    });
    // Contratado antes de la acreditación: conserva el régimen general; después: hereda micro.
    [$before, $after] = TenancyScope::runAs(NRR_TENANT, fn () => [
        PayrollFixtures::employment($micro, '2025-01-01', [['amount' => '1500.00', 'valid_from' => '2025-01-01']]),
        PayrollFixtures::employment($micro, '2025-08-01', [['amount' => '1500.00', 'valid_from' => '2025-08-01']]),
    ]);
    expect(nrrCall($this->admin, 'GET', "/employments/{$before}/payroll-regime?as_of=2026-03-01")->json('data'))->toMatchArray(['labor_regime_code' => 'pe_general', 'source' => 'legal_entity_default'])
        ->and(nrrCall($this->admin, 'GET', "/employments/{$after}/payroll-regime?as_of=2026-03-01")->json('data.labor_regime_code'))->toBe('pe_micro');

    $money = fn (string $a) => ['amount' => $a, 'currency' => 'PEN'];
    $y1 = nrrCall($this->admin, 'POST', '/legal-entity-annual-figures', ['legal_entity_id' => $micro, 'fiscal_year' => 2024, 'net_sales' => $money('900000.00'), 'uit_value' => $money('5150.00'), 'average_headcount' => 8])
        ->assertCreated();
    $this->assertMatchesOpenApi($y1, 'post', '/legal-entity-annual-figures', 201);
    $status = nrrCall($this->admin, 'GET', "/legal-entities/{$micro}/small-business-status")->assertOk();
    $this->assertMatchesOpenApi($status, 'get', '/legal-entities/{id}/small-business-status', 200);
    expect($status->json('data.alert'))->toBe('exit_risk')->and($status->json('data.years.0.exceeds'))->toBeTrue();

    nrrCall($this->admin, 'POST', '/legal-entity-annual-figures', ['legal_entity_id' => $micro, 'fiscal_year' => 2024, 'net_sales' => $money('1.00'), 'uit_value' => $money('5150.00')])->assertStatus(409);
    nrrCall($this->admin, 'POST', '/legal-entity-annual-figures', ['legal_entity_id' => $micro, 'fiscal_year' => 2025, 'net_sales' => $money('1000000.00'), 'uit_value' => $money('5350.00')])->assertCreated();
    $s2 = nrrCall($this->admin, 'GET', "/legal-entities/{$micro}/small-business-status")->json('data');
    expect($s2['accreditation_status'])->toBe('in_grace_period')->and($s2['grace_period_ends_on'])->toBe('2026-12-31')->and($s2['alert'])->toBe('permanence');
    expect(TenancyScope::runAs(NRR_TENANT, fn () => DB::table('legal_entity_regimes')->where('legal_entity_id', $micro)->count()))->toBe(2);

    $list = nrrCall($this->admin, 'GET', "/legal-entity-annual-figures?filter[legal_entity_id]={$micro}")->assertOk();
    $this->assertMatchesOpenApi($list, 'get', '/legal-entity-annual-figures', 200);
    $id = (string) $list->json('data.0.id');
    $upd = nrrCall($this->admin, 'PATCH', "/legal-entity-annual-figures/{$id}", ['average_headcount' => 9])->assertOk();
    $this->assertMatchesOpenApi($upd, 'patch', '/legal-entity-annual-figures/{id}', 200);

    // Al vencer la permanencia: cambio masivo al régimen general con fecha efectiva.
    $chg = nrrCall($this->admin, 'POST', "/legal-entities/{$micro}/labor-regime-change", ['labor_regime_code' => 'pe_general', 'effective_on' => '2027-01-01', 'reason' => 'Fin de la permanencia'])
        ->assertCreated();
    $this->assertMatchesOpenApi($chg, 'post', '/legal-entities/{id}/labor-regime-change', 201);
    expect($chg->json('data.changed'))->toBe(2);
    expect(nrrCall($this->admin, 'GET', "/employments/{$after}/payroll-regime?as_of=2027-01-15")->json('data'))->toMatchArray(['labor_regime_code' => 'pe_general', 'source' => 'employment']);
    nrrCall($this->employee, 'GET', "/legal-entities/{$micro}/small-business-status")->assertStatus(403);
});

test('NOM-9 · el núcleo y el módulo de nómina no tienen códigos de régimen de un país', function (): void {
    $found = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 3).'/app/Modules/Payroll', FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (str_ends_with($file->getPathname(), '.php') && preg_match('/[\'"]pe_[a-z]+[\'"]/', (string) file_get_contents($file->getPathname()), $m) === 1) {
            $found[] = $file->getPathname().': '.$m[0];
        }
    }
    expect($found)->toBe([]);
});
