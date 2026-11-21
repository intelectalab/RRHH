<?php

/**
 * PER-13 · Planilla en paralelo (comparación con el sistema anterior) y CUM-9 · tablero de obligaciones de planilla.
 * Caso feliz, permiso, aislamiento entre organizaciones, transiciones inválidas, auditoría, registro de accesos a
 * montos y avisos sin repetir.
 */

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Imports\Application\ImportRunner;
use App\Modules\Payroll\Application\PayrollRuns;
use App\Shared\Permissions\ModuleGate;
use App\Shared\Permissions\PermissionResolver;
use App\Shared\Security\BlindIndex;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\IdentityFixtures;
use Tests\Support\PayrollFixtures;

uses(DatabaseTransactions::class);

const PPO_TENANT = 'aaaaaaaa-0c4d-4000-8000-00000000000a';
const PPO_OTHER = 'bbbbbbbb-0c4d-4000-8000-00000000000b';

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requiere PostgreSQL.');
    }
    Storage::fake('s3');
    config(['files.disk' => 's3']);
    TenancyScope::clear();
    foreach ([PPO_TENANT => 'ppo-org', PPO_OTHER => 'ppo-otra'] as $id => $slug) {
        DB::table('tenants')->insertOrIgnore(['id' => $id, 'slug' => $slug, 'name' => 'Grupo '.$slug, 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN']);
        DB::select('SELECT seed_tenant_defaults(?)', [$id]);
        TenancyScope::runAs($id, fn () => IdentityFixtures::enableAllModules());
    }
    $this->payroll = ppoUser(PPO_TENANT, 'payroll');
    $this->admin = ppoUser(PPO_TENANT, 'admin_empresa');
    $this->employee = ppoUser(PPO_TENANT, 'empleado');
    $this->other = ppoUser(PPO_OTHER, 'admin_empresa');
    [$this->entity, $this->a] = TenancyScope::runAs(PPO_TENANT, function (): array {
        $entity = PayrollFixtures::entity();
        $a = PayrollFixtures::employment($entity, '2025-01-01', [['amount' => '6000.00', 'valid_from' => '2025-01-01']]);
        $person = DB::table('employments')->where('id', $a)->value('person_id');
        DB::table('person_documents')->insert(['tenant_id' => PPO_TENANT, 'person_id' => $person, 'doc_type' => 'dni', 'issuing_country' => 'PE',
            'number_enc' => Crypt::encryptString('45678913'), 'number_hash' => BlindIndex::for('national_id', 'dni:45678913'), 'is_primary' => true]);
        PayrollFixtures::concept(['code' => 'BASICO', 'kind' => 'earning', 'formula' => 'sueldo_tramos', 'sort_order' => 1]);
        PayrollFixtures::concept(['code' => 'MOVILIDAD', 'kind' => 'earning', 'sort_order' => 2]);
        PayrollFixtures::parameter('dias_base_mes', '30');

        return [$entity, $a];
    });
});

afterEach(fn () => TenancyScope::clear());

function ppoUser(string $tenant, string $role): User
{
    return TenancyScope::runAs($tenant, function () use ($role): User {
        $u = User::factory()->create();
        $u->roleAssignments()->create(['role_id' => Role::query()->where('system_role_code', $role)->value('id')]);

        return $u;
    });
}

/** @param array<string, mixed> $body */
function ppoCall(User $user, string $method, string $path, array $body = []): TestResponse
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

/** Periodo de mayo calculado (corrida vigente procesada). */
function ppoCalculatedPeriod(object $t): string
{
    $group = (string) ppoCall($t->admin, 'POST', '/payroll-groups', ['code' => 'PARA', 'name' => 'Paralelo', 'legal_entity_id' => $t->entity, 'payment_day' => 28, 'cutoff_day' => 25])
        ->assertCreated()->json('data.id');
    $p = (string) ppoCall($t->admin, 'POST', '/payroll-periods', ['payroll_group_id' => $group, 'period_year' => 2026, 'period_number' => 5])->assertCreated()->json('data.id');
    $run = (string) ppoCall($t->payroll, 'POST', "/payroll-periods/{$p}/calculate")->assertOk()->json('data.last_run.id');
    TenancyScope::runAs(PPO_TENANT, fn () => app(PayrollRuns::class)->process($run));

    return $p;
}

/** @param list<list<string>> $rows */
function ppoImportLegacy(User $user, array $rows): array
{
    $lines = array_merge([['Grupo de planilla', 'Periodo', 'Tipo de documento', 'Número de documento', 'Código del concepto', 'Monto']], $rows);
    $csv = implode("\n", array_map(fn ($cells) => implode(';', $cells), $lines))."\n";
    $up = ppoCall($user, 'POST', '/imports', ['entity' => 'legacy_payroll_results', 'file_name' => 'anterior.csv', 'content_base64' => base64_encode($csv)])->assertCreated();
    $id = (string) $up->json('data.id');
    ppoCall($user, 'POST', "/imports/{$id}/map", ['mapping' => $up->json('data.suggested_mapping')])->assertOk();
    $validated = (array) ppoCall($user, 'POST', "/imports/{$id}/validate")->assertOk()->json('data');
    ppoCall($user, 'POST', "/imports/{$id}/execute", ['confirm' => true])->assertOk();
    TenancyScope::runAs((string) $user->tenant_id, fn () => app(ImportRunner::class)->run($id));

    return ['id' => $id, 'validated' => $validated];
}

test('PER-13 · compara la corrida con el sistema anterior, exige explicar cada diferencia y archiva el informe al quedar lista', function (): void {
    $p = ppoCalculatedPeriod($this);

    // Sin resultados del sistema anterior no hay nada que comparar.
    $none = ppoCall($this->payroll, 'POST', "/payroll-periods/{$p}/parallel-comparisons")->assertStatus(409);
    expect($none->json('error.code'))->toBe('no_legacy_results');
    $this->assertMatchesOpenApi($none, 'post', '/payroll-periods/{id}/parallel-comparisons', 409);

    $import = ppoImportLegacy($this->payroll, [
        ['PARA', '2026-05', 'dni', '45678913', 'basico', '6000.05'],
        ['PARA', '2026-05', 'dni', '45678913', 'MOVILIDAD', '150.00'],
        ['PARA', '2026-05', 'dni', '99999999', 'BASICO', '100.00'],
        ['PARA', '2026-05', 'dni', '45678913', 'NO_EXISTE', '1.00'],
    ]);
    expect(TenancyScope::runAs(PPO_TENANT, fn () => DB::table('payroll_legacy_results')->where('payroll_period_id', $p)->count()))->toBe(2);

    $created = ppoCall($this->payroll, 'POST', "/payroll-periods/{$p}/parallel-comparisons", ['legacy_system' => 'Sistema X'])->assertCreated();
    $this->assertMatchesOpenApi($created, 'post', '/payroll-periods/{id}/parallel-comparisons', 201);
    $id = (string) $created->json('data.id');
    expect($created->json('data.status'))->toBe('open')
        ->and($created->json('data.summary.differences'))->toBe(2)
        ->and($created->json('data.summary.unexplained'))->toBe(1);

    $list = ppoCall($this->payroll, 'GET', "/payroll-periods/{$p}/parallel-comparisons")->assertOk();
    $this->assertMatchesOpenApi($list, 'get', '/payroll-periods/{id}/parallel-comparisons', 200);
    expect($list->json('data'))->toHaveCount(1);

    $show = ppoCall($this->payroll, 'GET', "/payroll-parallel-comparisons/{$id}")->assertOk();
    $this->assertMatchesOpenApi($show, 'get', '/payroll-parallel-comparisons/{id}', 200);
    $diffs = collect($show->json('data.differences'))->keyBy('concept_code');
    expect($diffs['BASICO']['classification'])->toBe('rounding')->and($diffs['BASICO']['explained'])->toBeTrue()
        ->and($diffs['BASICO']['difference'])->toBe('-0.05')
        ->and($diffs['MOVILIDAD']['classification'])->toBe('unclassified')->and($diffs['MOVILIDAD']['difference'])->toBe('-150.00');
    // Regla 7 · la lectura de los montos queda registrada.
    expect(TenancyScope::runAs(PPO_TENANT, fn () => DB::table('data_access_logs')->where('entity_type', 'PayrollParallelDifference')->where('user_id', $this->payroll->id)->exists()))->toBeTrue();
    // Regla 6 · sin payroll.results.view el monto no viaja.
    $canSee = TenancyScope::runAs(PPO_TENANT, fn () => app(PermissionResolver::class)->for((string) $this->admin->id)->has('payroll.results.view'));
    $adminShow = ppoCall($this->admin, 'GET', "/payroll-parallel-comparisons/{$id}");
    if ($adminShow->status() === 200 && ! $canSee) {
        expect($adminShow->json('data.differences.0'))->not->toHaveKey('difference');
    }

    $missing = ppoCall($this->payroll, 'POST', "/payroll-parallel-comparisons/{$id}/mark-ready")->assertStatus(409);
    expect($missing->json('error.code'))->toBe('unexplained_differences');
    $this->assertMatchesOpenApi($missing, 'post', '/payroll-parallel-comparisons/{id}/mark-ready', 409);

    $mov = (string) $diffs['MOVILIDAD']['id'];
    $this->assertMatchesOpenApi(ppoCall($this->payroll, 'POST', "/payroll-parallel-differences/{$mov}/explain", ['classification' => 'rule'])->assertStatus(422),
        'post', '/payroll-parallel-differences/{id}/explain', 422);
    $this->assertMatchesOpenApi(ppoCall($this->payroll, 'POST', "/payroll-parallel-differences/{$mov}/explain", ['classification' => 'otra'])->assertStatus(422),
        'post', '/payroll-parallel-differences/{id}/explain', 422);
    $explained = ppoCall($this->payroll, 'POST', "/payroll-parallel-differences/{$mov}/explain", ['classification' => 'rule', 'comment' => 'El sistema anterior pagaba movilidad fija; aquí es por asistencia.'])->assertOk();
    $this->assertMatchesOpenApi($explained, 'post', '/payroll-parallel-differences/{id}/explain', 200);

    $ready = ppoCall($this->payroll, 'POST', "/payroll-parallel-comparisons/{$id}/mark-ready", ['note' => 'Paralelo de mayo conforme'])->assertOk();
    $this->assertMatchesOpenApi($ready, 'post', '/payroll-parallel-comparisons/{id}/mark-ready', 200);
    expect($ready->json('data.status'))->toBe('ready')->and($ready->json('data.report_document_id'))->not->toBeNull();

    // Regla 3 · lista no admite otra vez «mark_ready» ni explicaciones; se reabre con su acción.
    $again = ppoCall($this->payroll, 'POST', "/payroll-parallel-comparisons/{$id}/mark-ready")->assertStatus(409);
    expect($again->json('error.code'))->toBe('invalid_transition');
    $this->assertMatchesOpenApi(ppoCall($this->payroll, 'POST', "/payroll-parallel-differences/{$mov}/explain", ['classification' => 'input', 'comment' => 'x'])->assertStatus(409),
        'post', '/payroll-parallel-differences/{id}/explain', 409);

    $csv = ppoCall($this->payroll, 'GET', "/payroll-parallel-comparisons/{$id}/download")->assertOk();
    expect((string) $csv->headers->get('Content-Type'))->toContain('text/csv')->and($csv->getContent())->toContain('MOVILIDAD');

    $reopened = ppoCall($this->payroll, 'POST', "/payroll-parallel-comparisons/{$id}/reopen", ['reason' => 'Revisar otra vez'])->assertOk();
    $this->assertMatchesOpenApi($reopened, 'post', '/payroll-parallel-comparisons/{id}/reopen', 200);
    $this->assertMatchesOpenApi(ppoCall($this->payroll, 'POST', "/payroll-parallel-comparisons/{$id}/reopen")->assertStatus(409), 'post', '/payroll-parallel-comparisons/{id}/reopen', 409);

    // Regla 5 · auditoría de cada paso.
    $actions = TenancyScope::runAs(PPO_TENANT, fn () => DB::table('audit_events')->whereIn('action', ['payroll_parallel.compared', 'payroll_parallel.difference_explained', 'payroll_parallel.ready', 'payroll_parallel.reopened'])->pluck('action')->unique()->values()->all());
    expect($actions)->toHaveCount(4);

    // Permiso y aislamiento.
    $this->assertMatchesOpenApi(ppoCall($this->employee, 'GET', "/payroll-parallel-comparisons/{$id}")->assertForbidden(), 'get', '/payroll-parallel-comparisons/{id}', 403);
    $this->assertMatchesOpenApi(ppoCall($this->employee, 'POST', "/payroll-periods/{$p}/parallel-comparisons")->assertForbidden(), 'post', '/payroll-periods/{id}/parallel-comparisons', 403);
    $this->assertMatchesOpenApi(ppoCall($this->other, 'GET', "/payroll-parallel-comparisons/{$id}")->assertNotFound(), 'get', '/payroll-parallel-comparisons/{id}', 404);
    ppoCall($this->other, 'GET', "/payroll-periods/{$p}/parallel-comparisons")->assertNotFound();
    ppoCall($this->other, 'POST', "/payroll-parallel-differences/{$mov}/explain", ['classification' => 'input', 'comment' => 'x'])->assertNotFound();

    // Deshacer la importación devuelve el periodo a «sin resultados del sistema anterior».
    ppoCall($this->admin, 'POST', "/imports/{$import['id']}/revert")->assertOk();
    expect(TenancyScope::runAs(PPO_TENANT, fn () => DB::table('payroll_legacy_results')->where('payroll_period_id', $p)->whereNull('deleted_at')->count()))->toBe(0);
});

test('PER-13 · sin corrida vigente la comparación responde 409', function (): void {
    $group = (string) ppoCall($this->admin, 'POST', '/payroll-groups', ['code' => 'PARB', 'name' => 'Paralelo B', 'legal_entity_id' => $this->entity, 'payment_day' => 28, 'cutoff_day' => 25])->assertCreated()->json('data.id');
    $p = (string) ppoCall($this->admin, 'POST', '/payroll-periods', ['payroll_group_id' => $group, 'period_year' => 2026, 'period_number' => 6])->assertCreated()->json('data.id');
    $r = ppoCall($this->payroll, 'POST', "/payroll-periods/{$p}/parallel-comparisons")->assertStatus(409);
    $this->assertMatchesOpenApi($r, 'post', '/payroll-periods/{id}/parallel-comparisons', 409);
    expect($r->json('error.code'))->toBe('no_payroll_run');
});

test('CUM-9 · el tablero calcula vencimientos con parámetros con vigencia, muestra el que falta y avisa una sola vez', function (): void {
    TenancyScope::clear();
    DB::statement('RESET ROLE');
    foreach ([['afpnet_plazo_dias_habiles', '5', 'business_days'], ['cts_deposito_dia_limite', '15', 'days'], ['gratificacion_pago_dia_limite', '15', 'days'], ['sst_comite_minimo_trabajadores', '20', 'count']] as [$code, $value, $unit]) {
        if (! DB::table('legal_parameters')->where('country_code', 'PE')->where('code', $code)->whereNull('labor_regime_code')->exists()) {
            DB::table('legal_parameters')->insert(['country_code' => 'PE', 'code' => $code, 'name' => $code, 'value_numeric' => $value, 'unit' => $unit, 'valid_from' => '2000-01-01', 'verified' => true]);
        }
    }
    foreach ([['cts_meses_deposito', [5, 11]], ['gratificacion_meses', [7, 12]]] as [$code, $value]) {
        if (! DB::table('legal_parameters')->where('country_code', 'PE')->where('code', $code)->whereNull('labor_regime_code')->exists()) {
            DB::table('legal_parameters')->insert(['country_code' => 'PE', 'code' => $code, 'name' => $code, 'value_json' => json_encode($value), 'unit' => 'text', 'valid_from' => '2000-01-01', 'verified' => true]);
        }
    }
    DB::table('legal_parameters')->where('country_code', 'PE')->where('code', 'plame_vencimiento_dia_habil')->update(['value_numeric' => null]);

    $board = ppoCall($this->payroll, 'GET', "/payroll-obligations?legal_entity_id={$this->entity}&as_of=2026-11-10")->assertOk();
    $this->assertMatchesOpenApi($board, 'get', '/payroll-obligations', 200);
    $items = collect($board->json('data'))->keyBy('code');
    expect($items->keys()->all())->toEqualCanonicalizing(['plame', 'afpnet', 't_registro', 'cts_deposit', 'gratification_payment', 'contracts_expiring', 'sst_committee'])
        // Regla 4 · el cronograma de PLAME no está cargado: «sin datos» con el parámetro que falta, sin inventar la fecha.
        ->and($items['plame']['status'])->toBe('no_data')->and($items['plame']['missing_parameter'])->toBe('plame_vencimiento_dia_habil')
        ->and($items['plame']['due_on'])->toBeNull()
        ->and($items['cts_deposit']['due_on'])->toBe('2026-11-15')->and($items['cts_deposit']['status'])->toBe('due_soon')
        ->and($items['gratification_payment']['due_on'])->toBe('2026-12-15')->and($items['gratification_payment']['status'])->toBe('pending')
        ->and($items['afpnet']['due_on'])->not->toBeNull()
        ->and($items['sst_committee']['status'])->toBe('not_applicable')
        ->and($items['t_registro']['status'])->toBe('met');
    foreach ($items as $i) {
        expect($i['explanation'])->not->toBe('');
    }

    // Responsable por obligación.
    $assign = ppoCall($this->admin, 'PUT', '/payroll-obligations/cts_deposit/responsible', ['legal_entity_id' => $this->entity, 'responsible_user_id' => $this->payroll->id])->assertOk();
    $this->assertMatchesOpenApi($assign, 'put', '/payroll-obligations/{code}/responsible', 200);
    $this->assertMatchesOpenApi(ppoCall($this->admin, 'PUT', '/payroll-obligations/cts_deposit/responsible', ['legal_entity_id' => $this->entity, 'responsible_user_id' => (string) Str::uuid()])->assertStatus(422),
        'put', '/payroll-obligations/{code}/responsible', 422);
    ppoCall($this->admin, 'PUT', '/payroll-obligations/otra/responsible', ['legal_entity_id' => $this->entity, 'responsible_user_id' => $this->payroll->id])->assertNotFound();
    $this->assertMatchesOpenApi(ppoCall($this->employee, 'PUT', '/payroll-obligations/cts_deposit/responsible', ['legal_entity_id' => $this->entity, 'responsible_user_id' => $this->payroll->id])->assertForbidden(),
        'put', '/payroll-obligations/{code}/responsible', 403);
    expect(ppoCall($this->payroll, 'GET', "/payroll-obligations?legal_entity_id={$this->entity}&as_of=2026-11-10")->json('data.3.responsible.user_id'))->toBe($this->payroll->id);

    // Permiso, validación y aislamiento.
    $this->assertMatchesOpenApi(ppoCall($this->employee, 'GET', "/payroll-obligations?legal_entity_id={$this->entity}")->assertForbidden(), 'get', '/payroll-obligations', 403);
    $this->assertMatchesOpenApi(ppoCall($this->payroll, 'GET', '/payroll-obligations')->assertStatus(422), 'get', '/payroll-obligations', 422);
    ppoCall($this->other, 'GET', "/payroll-obligations?legal_entity_id={$this->entity}")->assertNotFound();
    ppoCall($this->other, 'PUT', '/payroll-obligations/cts_deposit/responsible', ['legal_entity_id' => $this->entity, 'responsible_user_id' => $this->other->id])->assertNotFound();

    // NOT-2 · el aviso previo sale una vez; volver a correr la tarea no lo repite.
    Artisan::call('payroll:obligation-alerts', ['--date' => '2026-11-10']);
    Artisan::call('payroll:obligation-alerts', ['--date' => '2026-11-10']);
    $alerts = TenancyScope::runAs(PPO_TENANT, fn () => DB::table('payroll_obligation_alerts')->where('legal_entity_id', $this->entity)->get());
    expect($alerts->where('obligation_code', 'cts_deposit')->where('kind', 'before_due'))->toHaveCount(1);
    expect(TenancyScope::runAs(PPO_TENANT, fn () => DB::table('notifications')->where('recipient_user_id', $this->payroll->id)->where('category', 'payroll.obligation_due')->count()))->toBe(1);
    // Vencido sin cumplirse: un segundo aviso, también una sola vez.
    Artisan::call('payroll:obligation-alerts', ['--date' => '2026-11-20']);
    Artisan::call('payroll:obligation-alerts', ['--date' => '2026-11-21']);
    expect(TenancyScope::runAs(PPO_TENANT, fn () => DB::table('payroll_obligation_alerts')->where('legal_entity_id', $this->entity)->where('obligation_code', 'cts_deposit')->where('kind', 'overdue')->count()))->toBe(1);
    // Solo inserción.
    expect(fn () => TenancyScope::runAs(PPO_TENANT, fn () => DB::table('payroll_obligation_alerts')->where('legal_entity_id', $this->entity)->delete()))->toThrow(Exception::class);
});
