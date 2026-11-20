<?php

/**
 * PER-11 · Libro de retenciones de quinta (consulta, resumen conciliado, solo inserción); PER-12 · acumulados del
 * ejercicio importados que alimentan la proyección de quinta; BSO-4 · asignación familiar por hijos con sustento;
 * BSO-5 · remuneración vacacional por días gozados e indemnización por periodos vencidos.
 */

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Imports\Application\ImportRunner;
use App\Modules\Payroll\Application\PayrollEngine;
use App\Modules\Payroll\Application\PayrollRuns;
use App\Modules\Payroll\Application\PayrollTimeConsumption;
use App\Modules\Payroll\Infrastructure\Models\PayrollPeriod;
use App\Shared\Permissions\ModuleGate;
use App\Shared\Permissions\PermissionResolver;
use App\Shared\Security\BlindIndex;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\IdentityFixtures;
use Tests\Support\PayrollFixtures;

uses(DatabaseTransactions::class);

const PLB_TENANT = 'aaaaaaaa-0c4b-4000-8000-00000000000a';
const PLB_OTHER = 'bbbbbbbb-0c4b-4000-8000-00000000000b';

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requiere PostgreSQL.');
    }
    Storage::fake('s3');
    config(['files.disk' => 's3']);
    TenancyScope::clear();
    foreach ([PLB_TENANT => 'plb-org', PLB_OTHER => 'plb-otra'] as $id => $slug) {
        DB::table('tenants')->insertOrIgnore(['id' => $id, 'slug' => $slug, 'name' => 'Grupo '.$slug, 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN']);
        DB::select('SELECT seed_tenant_defaults(?)', [$id]);
        TenancyScope::runAs($id, fn () => IdentityFixtures::enableAllModules());
    }
    $this->payroll = plbUser(PLB_TENANT, 'payroll');
    $this->admin = plbUser(PLB_TENANT, 'admin_empresa');
    $this->employee = plbUser(PLB_TENANT, 'empleado');
    $this->other = plbUser(PLB_OTHER, 'admin_empresa');
    [$this->entity, $this->a, $this->ruc] = TenancyScope::runAs(PLB_TENANT, function (): array {
        $entity = PayrollFixtures::entity();
        $a = PayrollFixtures::employment($entity, '2025-01-01', [['amount' => '6000.00', 'valid_from' => '2025-01-01']]);
        $person = DB::table('employments')->where('id', $a)->value('person_id');
        DB::table('person_documents')->insert(['tenant_id' => PLB_TENANT, 'person_id' => $person, 'doc_type' => 'dni', 'issuing_country' => 'PE',
            'number_enc' => Crypt::encryptString('45678912'), 'number_hash' => BlindIndex::for('national_id', 'dni:45678912'), 'is_primary' => true]);
        PayrollFixtures::concept(['code' => 'BASICO', 'kind' => 'earning', 'formula' => 'sueldo_tramos', 'sort_order' => 1, 'affects' => ['quinta:ordinary']]);
        PayrollFixtures::concept(['code' => 'renta_quinta', 'kind' => 'tax', 'sort_order' => 90]);
        PayrollFixtures::parameter('dias_base_mes', '30');
        PayrollFixtures::incomeTaxParameters('5500');
        PayrollFixtures::parameter('gratificacion_factor', '1');
        PayrollFixtures::parameter('gratificacion_bonificacion_extraordinaria_pct', '9');

        return [$entity, $a, (string) DB::table('legal_entities')->where('id', $entity)->value('tax_id')];
    });
});

afterEach(fn () => TenancyScope::clear());

function plbUser(string $tenant, string $role): User
{
    return TenancyScope::runAs($tenant, function () use ($role): User {
        $u = User::factory()->create();
        $u->roleAssignments()->create(['role_id' => Role::query()->where('system_role_code', $role)->value('id')]);

        return $u;
    });
}

/** @param array<string, mixed> $body */
function plbCall(User $user, string $method, string $path, array $body = []): TestResponse
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

function plbPeriod(object $t, int $month): string
{
    $group = TenancyScope::runAs(PLB_TENANT, fn () => DB::table('payroll_groups')->where('code', 'MENS')->value('id'));
    if ($group === null) {
        $group = (string) plbCall($t->admin, 'POST', '/payroll-groups', ['code' => 'MENS', 'name' => 'Mensual', 'legal_entity_id' => $t->entity, 'payment_day' => 28, 'cutoff_day' => 25])
            ->assertCreated()->json('data.id');
    }

    return (string) plbCall($t->admin, 'POST', '/payroll-periods', ['payroll_group_id' => $group, 'period_year' => 2026, 'period_number' => $month])
        ->assertCreated()->json('data.id');
}

/** Calcula el periodo, procesa la corrida y lo deja cerrado (el cierre es de PER-9; aquí solo se fija el estado). */
function plbCloseMonth(object $t, int $month): string
{
    $p = plbPeriod($t, $month);
    $run = (string) plbCall($t->payroll, 'POST', "/payroll-periods/{$p}/calculate")->assertOk()->json('data.last_run.id');
    TenancyScope::runAs(PLB_TENANT, function () use ($run, $p): void {
        app(PayrollRuns::class)->process($run);
        DB::table('payroll_periods')->where('id', $p)->update(['status' => 'closed']);
    });

    return $p;
}

/**
 * @param  list<list<string>>  $lines
 * @return array{job: array<string, mixed>, validated: array<string, mixed>}
 */
function plbImport(User $user, array $lines, bool $execute = true): array
{
    $csv = implode("\n", array_map(fn ($cells) => implode(';', $cells), $lines))."\n";
    $up = plbCall($user, 'POST', '/imports', ['entity' => 'payroll_balances', 'file_name' => 'acumulados.csv', 'content_base64' => base64_encode($csv)])->assertCreated();
    $id = (string) $up->json('data.id');
    plbCall($user, 'POST', "/imports/{$id}/map", ['mapping' => $up->json('data.suggested_mapping')])->assertOk();
    $validated = (array) plbCall($user, 'POST', "/imports/{$id}/validate")->assertOk()->json('data');
    if ($execute) {
        plbCall($user, 'POST', "/imports/{$id}/execute", ['confirm' => true])->assertOk();
        TenancyScope::runAs((string) $user->tenant_id, fn () => app(ImportRunner::class)->run($id));
    }

    return ['job' => (array) plbCall($user, 'GET', "/imports/{$id}")->json('data'), 'validated' => $validated];
}

/** @return list<list<string>> */
function plbBalances(string $ruc): array
{
    $h = ['Tipo de documento', 'Número de documento', 'Empresa (RUC)', 'Ejercicio', 'Fecha de corte', 'Tipo de acumulado', 'Monto', 'Mes', 'Semestre', 'Código del concepto', 'Sustento'];

    return [$h,
        ['dni', '45678912', $ruc, '2026', '31/01/2026', 'fifth_income', '6000.00', '', '', '', 'Reporte de acumulados del sistema anterior al 31/01'],
        ['dni', '45678912', $ruc, '2026', '31/01/2026', 'fifth_withheld', '405.93', '1', '', '', 'Reporte de acumulados del sistema anterior al 31/01'],
    ];
}

test('PER-11 · el libro de quinta se consulta con alcance, su resumen concilia con las boletas cerradas y es de solo inserción', function (): void {
    plbCloseMonth($this, 1);
    $list = plbCall($this->payroll, 'GET', '/fifth-category-ledger?filter[fiscal_year]=2026')->assertOk();
    $this->assertMatchesOpenApi($list, 'get', '/fifth-category-ledger', 200);
    $entry = collect($list->json('data'))->firstWhere('employment_id', $this->a);
    expect($entry['withholding_amount']['amount'])->toBe('405.93')->and($entry['entry_kind'])->toBe('monthly');

    $show = plbCall($this->payroll, 'GET', '/fifth-category-ledger/'.$entry['id'])->assertOk();
    $this->assertMatchesOpenApi($show, 'get', '/fifth-category-ledger/{id}', 200);

    $summary = plbCall($this->payroll, 'GET', "/employments/{$this->a}/fifth-category-summary?fiscal_year=2026")->assertOk();
    $this->assertMatchesOpenApi($summary, 'get', '/employments/{employment_id}/fifth-category-summary', 200);
    expect($summary->json('data.accumulated'))->toBe('405.93')
        ->and($summary->json('data.closed_payslips'))->toBe('405.93')
        ->and($summary->json('data.closed_ledger'))->toBe('405.93')
        ->and($summary->json('data.reconciled'))->toBeTrue();

    // Ejercicio obligatorio (422), sin permiso (403), otra organización (404) y módulo apagado.
    $this->assertMatchesOpenApi(plbCall($this->payroll, 'GET', "/employments/{$this->a}/fifth-category-summary")->assertStatus(422), 'get', '/employments/{employment_id}/fifth-category-summary', 422);
    $this->assertMatchesOpenApi(plbCall(plbUser(PLB_TENANT, 'rrhh'), 'GET', '/fifth-category-ledger')->assertForbidden(), 'get', '/fifth-category-ledger', 403);
    // El colaborador solo ve lo propio (alcance en la consulta), no la retención de otro.
    expect(plbCall($this->employee, 'GET', '/fifth-category-ledger')->assertOk()->json('data'))->toBe([]);
    plbCall($this->employee, 'GET', '/fifth-category-ledger/'.$entry['id'])->assertNotFound();
    $this->assertMatchesOpenApi(plbCall($this->other, 'GET', '/fifth-category-ledger/'.$entry['id'])->assertNotFound(), 'get', '/fifth-category-ledger/{id}', 404);
    $this->assertMatchesOpenApi(plbCall($this->other, 'GET', "/employments/{$this->a}/fifth-category-summary?fiscal_year=2026")->assertNotFound(), 'get', '/employments/{employment_id}/fifth-category-summary', 404);
    expect(plbCall($this->other, 'GET', '/fifth-category-ledger')->assertOk()->json('data'))->toBe([]);
    IdentityFixtures::disableModule(PLB_TENANT, 'payroll');
    expect(plbCall($this->payroll, 'GET', '/fifth-category-ledger')->assertForbidden()->json('error.code'))->toBe('module_not_enabled');

    // El libro no admite cambios ni borrado.
    TenancyScope::runAs(PLB_TENANT, function () use ($entry): void {
        expect(fn () => DB::transaction(fn () => DB::table('fifth_category_ledger')->where('id', $entry['id'])->update(['withholding_amount' => '1.00'])))->toThrow(Exception::class)
            ->and(fn () => DB::transaction(fn () => DB::table('fifth_category_ledger')->where('id', $entry['id'])->delete()))->toThrow(Exception::class);
    });
});

test('PER-12 · los acumulados importados alimentan la proyección de quinta, se revisan, se revierten y un periodo cerrado los bloquea', function (): void {
    $r = plbImport($this->admin, plbBalances($this->ruc));
    expect($r['job']['status'] ?? null)->toBe('completed');
    $list = plbCall($this->payroll, 'GET', '/payroll-opening-balances?filter[fiscal_year]=2026')->assertOk();
    $this->assertMatchesOpenApi($list, 'get', '/payroll-opening-balances', 200);
    expect(collect($list->json('data'))->pluck('kind')->sort()->values()->all())->toBe(['fifth_income', 'fifth_withheld']);
    $this->assertMatchesOpenApi(plbCall($this->employee, 'GET', '/payroll-opening-balances')->assertForbidden(), 'get', '/payroll-opening-balances', 403);
    expect(plbCall($this->other, 'GET', '/payroll-opening-balances')->assertOk()->json('data'))->toBe([]);

    // Febrero proyecta con enero traído del sistema anterior: igual que si enero se hubiera calculado aquí (PER-5).
    $calc = TenancyScope::runAs(PLB_TENANT, fn () => app(PayrollEngine::class)->calculate($this->a, '2026-02-01', '2026-02-28', 'regular', null));
    $line = collect($calc['result']['lines'])->firstWhere('concept_code', 'renta_quinta');
    expect($line['trace']['income_tax']['prior_income'])->toBe('6000.00')
        ->and($line['trace']['income_tax']['gross_annual_income'])->toBe('85080.00')
        ->and($line['trace']['income_tax']['opening']['cutoff'])->toBe('2026-01-31')
        ->and($line['amount']['amount'])->toBe('405.93');

    // Revertir la importación deja los acumulados en la papelera y la proyección vuelve a cero.
    plbCall($this->admin, 'POST', "/imports/{$r['job']['id']}/revert")->assertOk();
    TenancyScope::runAs(PLB_TENANT, function (): void {
        expect(DB::table('payroll_opening_balances')->whereNull('deleted_at')->count())->toBe(0)
            ->and(DB::table('payroll_opening_balances')->whereNotNull('deleted_at')->count())->toBe(2);
    });
    $after = TenancyScope::runAs(PLB_TENANT, fn () => app(PayrollEngine::class)->calculate($this->a, '2026-02-01', '2026-02-28', 'regular', null));
    expect(collect($after['result']['lines'])->firstWhere('concept_code', 'renta_quinta')['trace']['income_tax']['prior_income'])->toBe('0.00');

    // Con un periodo cerrado del ejercicio ya no se traen acumulados (se duplicaría lo calculado).
    plbCloseMonth($this, 1);
    $locked = plbImport($this->admin, plbBalances($this->ruc), false);
    expect($locked['validated']['valid_rows'] ?? $locked['validated']['summary']['valid'] ?? 0)->toBe(0);
    // Sin permiso de incidencias no se importa.
    plbCall($this->employee, 'POST', '/imports', ['entity' => 'payroll_balances', 'file_name' => 'a.csv', 'content_base64' => base64_encode("a\n")])->assertForbidden();
});

test('BSO-4 · la asignación familiar se otorga solo con un hijo con sustento vigente', function (): void {
    [$emp, $person] = TenancyScope::runAs(PLB_TENANT, function (): array {
        PayrollFixtures::concept(['code' => 'asig_familiar', 'kind' => 'earning', 'formula' => 'param.rmv * param.asignacion_familiar_pct_rmv / 100',
            'condition' => 'tiene_asignacion_familiar y param.asignacion_familiar_aplica = 1', 'sort_order' => 20]);
        PayrollFixtures::parameter('rmv', '1000');
        PayrollFixtures::parameter('asignacion_familiar_pct_rmv', '10');
        PayrollFixtures::parameter('asignacion_familiar_aplica', '1');
        PayrollFixtures::parameter('mayoria_edad_anios', '18');
        $emp = PayrollFixtures::employment($this->entity, '2025-01-01', [['amount' => '3000.00', 'valid_from' => '2025-01-01']]);

        return [$emp, (string) DB::table('employments')->where('id', $emp)->value('person_id')];
    });
    $calc = fn () => TenancyScope::runAs(PLB_TENANT, fn () => app(PayrollEngine::class)->calculate($emp, '2026-03-01', '2026-03-31', 'regular', null));
    $af = fn (array $c) => collect($c['result']['lines'])->firstWhere('concept_code', 'asig_familiar');

    // Hijo registrado sin sustento: no da derecho.
    $child = TenancyScope::runAs(PLB_TENANT, fn () => (string) DB::table('dependents')->insertGetId(['tenant_id' => PLB_TENANT, 'person_id' => $person,
        'full_name' => 'Hijo de prueba', 'relationship' => 'child', 'birth_date' => '2020-05-01', 'entitles_family_allowance' => true]));
    expect($af($calc()))->toBeNull();

    // Con el sustento adjunto se otorga y la traza dice qué derechohabiente lo sostiene.
    TenancyScope::runAs(PLB_TENANT, function () use ($child): void {
        $file = (string) DB::table('stored_files')->insertGetId(['tenant_id' => PLB_TENANT, 'storage_key' => 'pruebas/partida.pdf', 'original_name' => 'partida.pdf',
            'mime_type' => 'application/pdf', 'size_bytes' => 10, 'sha256' => str_repeat('a', 64)]);
        DB::table('dependents')->where('id', $child)->update(['proof_file_id' => $file]);
    });
    $with = $calc();
    expect($af($with)['amount']['amount'])->toBe('100.00')
        ->and($with['context']['variables']['hijos_asignacion_familiar'])->toBe('1')
        ->and($with['context']['sources']['family_allowance']['children'][0]['dependent_id'])->toBe($child);

    // Dado de baja antes del periodo, deja de darlo.
    TenancyScope::runAs(PLB_TENANT, fn () => DB::table('dependents')->where('id', $child)->update(['deregistered_on' => '2026-02-15']));
    expect($af($calc()))->toBeNull();
});

test('BSO-5 · los días gozados se pagan como remuneración vacacional y el periodo vencido entra una vez como indemnización pendiente', function (): void {
    [$emp, $vacPeriod, $old] = TenancyScope::runAs(PLB_TENANT, function (): array {
        PayrollFixtures::concept(['code' => 'rem_basica', 'kind' => 'earning', 'formula' => 'sueldo_tramos - sueldo / dias_base * (dias_sin_goce + dias_subsidio + dias_vacaciones)', 'sort_order' => 10]);
        PayrollFixtures::concept(['code' => 'rem_vacacional', 'kind' => 'earning', 'formula' => 'sueldo / dias_base * dias_vacaciones', 'condition' => 'dias_vacaciones > 0', 'sort_order' => 15]);
        PayrollFixtures::concept(['code' => 'indemnizacion_vacacional', 'kind' => 'earning', 'formula' => 'sueldo / dias_base * cantidad.indemnizacion_vacacional', 'condition' => 'cantidad.indemnizacion_vacacional > 0', 'sort_order' => 17]);
        $emp = PayrollFixtures::employment($this->entity, '2024-01-01', [['amount' => '3000.00', 'valid_from' => '2024-01-01']]);
        $period = (string) DB::table('vacation_accrual_periods')->insertGetId(['tenant_id' => PLB_TENANT, 'employment_id' => $emp,
            'period_start' => '2025-01-01', 'period_end' => '2025-12-31', 'entitled_days' => '30', 'enjoy_by' => '2026-12-31', 'status' => 'earned']);
        $old = (string) DB::table('vacation_accrual_periods')->insertGetId(['tenant_id' => PLB_TENANT, 'employment_id' => $emp,
            'period_start' => '2024-01-01', 'period_end' => '2024-12-31', 'entitled_days' => '30', 'enjoy_by' => '2025-12-31', 'status' => 'earned']);
        $req = (string) DB::table('vacation_requests')->insertGetId(['tenant_id' => PLB_TENANT, 'employment_id' => $emp, 'kind' => 'leave', 'status' => 'approved',
            'starts_on' => '2026-03-10', 'ends_on' => '2026-03-19', 'days' => '10']);
        foreach ([[$period, null, 'accrual', '30', '2025-12-31'], [$period, $req, 'taken', '-10', '2026-03-10'], [$old, null, 'accrual', '30', '2024-12-31'], [$old, null, 'taken', '-12', '2025-06-01']] as [$p, $r, $type, $days, $on]) {
            DB::table('vacation_ledger_entries')->insert(['tenant_id' => PLB_TENANT, 'employment_id' => $emp, 'vacation_accrual_period_id' => $p,
                'vacation_request_id' => $r, 'entry_type' => $type, 'days' => $days, 'effective_on' => $on]);
        }

        return [$emp, $period, $old];
    });

    $calc = TenancyScope::runAs(PLB_TENANT, fn () => app(PayrollEngine::class)->calculate($emp, '2026-03-01', '2026-03-31', 'regular', null));
    $lines = collect($calc['result']['lines'])->keyBy('concept_code');
    expect($lines['rem_basica']['amount']['amount'])->toBe('2000.00')
        ->and($lines['rem_vacacional']['amount']['amount'])->toBe('1000.00')
        ->and($calc['context']['sources']['vacations'][0]['accrual_period_id'])->toBe($vacPeriod);

    // El periodo 2024 venció con 18 días sin gozar: entra como incidencia pendiente de visto bueno, una sola vez.
    $mar = plbPeriod($this, 3);
    $consume = fn (string $id) => TenancyScope::runAs(PLB_TENANT, fn () => app(PayrollTimeConsumption::class)->consume(PayrollPeriod::query()->findOrFail($id), null));
    $consume($mar);
    $consume($mar);
    $incidents = fn (string $id) => TenancyScope::runAs(PLB_TENANT, fn () => DB::table('payroll_incidents')->where('payroll_period_id', $id)
        ->where('employment_id', $emp)->where('source_type', 'vacation_expired')->get());
    expect($incidents($mar))->toHaveCount(1)
        ->and($incidents($mar)->first()->status)->toBe('pending')
        ->and($incidents($mar)->first()->source_id)->toBe($old)
        ->and((string) $incidents($mar)->first()->quantity)->toStartWith('18');
    $apr = plbPeriod($this, 4);
    $consume($apr);
    expect($incidents($apr))->toHaveCount(0);
});
