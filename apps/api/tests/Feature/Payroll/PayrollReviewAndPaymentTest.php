<?php

/**
 * PER-7 · Simulación sin guardar y anomalías explicadas contra el periodo anterior (umbrales por empresa y concepto).
 * PER-8 · Envío con anomalías confirmadas, aprobación y cierre bloqueado por anomalías críticas.
 * PER-9 · Planilla complementaria de un periodo cerrado o pagado (ADR 0017).
 * PER-10 · Lotes por banco con las cuentas de COL-8, archivo por formato (ADR 0020), confirmación idempotente y estado pagado.
 */

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Payroll\Application\PayrollRuns;
use App\Shared\Permissions\ModuleGate;
use App\Shared\Permissions\PermissionResolver;
use App\Shared\Security\FieldCipher;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\IdentityFixtures;
use Tests\Support\PayrollFixtures;

uses(DatabaseTransactions::class);

const PRV_TENANT = 'aaaaaaaa-0c4b-4000-8000-00000000000a';
const PRV_OTHER = 'bbbbbbbb-0c4b-4000-8000-00000000000b';

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requiere PostgreSQL.');
    }
    TenancyScope::clear();
    Storage::fake('s3');
    foreach ([PRV_TENANT => 'prv-org', PRV_OTHER => 'prv-otra'] as $id => $slug) {
        DB::table('tenants')->insertOrIgnore(['id' => $id, 'slug' => $slug, 'name' => 'Grupo '.$slug, 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN']);
        DB::select('SELECT seed_tenant_defaults(?)', [$id]);
        TenancyScope::runAs($id, fn () => IdentityFixtures::enableAllModules());
    }
    $this->payroll = prvUser(PRV_TENANT, 'payroll');
    $this->admin = prvUser(PRV_TENANT, 'admin_empresa');
    $this->employee = prvUser(PRV_TENANT, 'empleado');
    $this->other = prvUser(PRV_OTHER, 'admin_empresa');
    [$this->entity, $this->a, $this->b] = TenancyScope::runAs(PRV_TENANT, function (): array {
        $entity = PayrollFixtures::entity();
        $a = PayrollFixtures::employment($entity, '2025-01-01', [['amount' => '6000.00', 'valid_from' => '2025-01-01']]);
        // B sube de sueldo en febrero (historia con vigencia): la variación del neto supera el umbral.
        $b = PayrollFixtures::employment($entity, '2025-01-01', [['amount' => '2000.00', 'valid_from' => '2025-01-01', 'valid_to' => '2026-01-31'], ['amount' => '3000.00', 'valid_from' => '2026-02-01']]);
        PayrollFixtures::concept(['code' => 'BASICO', 'kind' => 'earning', 'formula' => 'sueldo_tramos', 'sort_order' => 1, 'affects' => []]);
        PayrollFixtures::parameter('dias_base_mes', '30');

        return [$entity, $a, $b];
    });
});

afterEach(fn () => TenancyScope::clear());

function prvUser(string $tenant, string $role): User
{
    return TenancyScope::runAs($tenant, function () use ($role): User {
        $u = User::factory()->create();
        $u->roleAssignments()->create(['role_id' => Role::query()->where('system_role_code', $role)->value('id')]);

        return $u;
    });
}

/** @param array<string, mixed> $body */
function prvCall(User $user, string $method, string $path, array $body = []): TestResponse
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

function prvPeriod(object $t, int $month): string
{
    $group = TenancyScope::runAs(PRV_TENANT, fn () => DB::table('payroll_groups')->where('code', 'MENS')->value('id'));
    if ($group === null) {
        $group = (string) prvCall($t->admin, 'POST', '/payroll-groups', ['code' => 'MENS', 'name' => 'Mensual', 'legal_entity_id' => $t->entity, 'payment_day' => 28, 'cutoff_day' => 25])
            ->assertCreated()->json('data.id');
    }

    return (string) prvCall($t->admin, 'POST', '/payroll-periods', ['payroll_group_id' => $group, 'period_year' => 2026, 'period_number' => $month])
        ->assertCreated()->json('data.id');
}

/** Calcula, procesa la corrida y devuelve su id. */
function prvCalculate(object $t, string $period): string
{
    $run = (string) prvCall($t->payroll, 'POST', "/payroll-periods/{$period}/calculate")->assertOk()->json('data.last_run.id');
    TenancyScope::runAs(PRV_TENANT, fn () => app(PayrollRuns::class)->process($run));

    return $run;
}

/** Envía (confirmando anomalías si las hay), aprueba otra persona y cierra. */
function prvCloseFlow(object $t, string $period): void
{
    prvCall($t->payroll, 'POST', "/payroll-periods/{$period}/submit", ['confirm_anomalies' => true])->assertOk();
    prvCall($t->admin, 'POST', "/payroll-periods/{$period}/approve")->assertOk();
    prvCall($t->payroll, 'POST', "/payroll-periods/{$period}/close")->assertOk();
}

test('PER-7 · PER-8 · anomalías explicadas, simulación sin guardar, envío con confirmación y cierre bloqueado por críticas', function (): void {
    $threshold = prvCall($this->admin, 'POST', '/payroll-variance-thresholds', ['legal_entity_id' => $this->entity, 'threshold_pct' => 20])->assertCreated();
    $this->assertMatchesOpenApi($threshold, 'post', '/payroll-variance-thresholds', 201);
    $this->assertMatchesOpenApi(prvCall($this->admin, 'POST', '/payroll-variance-thresholds', ['threshold_pct' => -1])->assertStatus(422), 'post', '/payroll-variance-thresholds', 422);
    $list = prvCall($this->payroll, 'GET', '/payroll-variance-thresholds')->assertOk();
    $this->assertMatchesOpenApi($list, 'get', '/payroll-variance-thresholds', 200);
    expect($list->json('data'))->toHaveCount(1);

    $jan = prvPeriod($this, 1);
    prvCalculate($this, $jan);
    prvCloseFlow($this, $jan);

    $feb = prvPeriod($this, 2);
    // Simulación sin guardar: abre una corrida de simulación y el periodo no cambia de estado.
    $sim = prvCall($this->payroll, 'POST', "/payroll-periods/{$feb}/simulate")->assertOk();
    $this->assertMatchesOpenApi($sim, 'post', '/payroll-periods/{id}/simulate', 200);
    expect($sim->json('data.status'))->toBe('open')->and($sim->json('data.simulation_run_id'))->toBeString();
    TenancyScope::runAs(PRV_TENANT, fn () => expect(DB::table('payroll_runs')->where('id', $sim->json('data.simulation_run_id'))->value('kind'))->toBe('simulation'));

    $run = prvCalculate($this, $feb);
    $anomalies = prvCall($this->payroll, 'GET', "/payroll-periods/{$feb}/anomalies")->assertOk();
    $this->assertMatchesOpenApi($anomalies, 'get', '/payroll-periods/{id}/anomalies', 200);
    $byEmp = collect($anomalies->json('data'))->keyBy('employment_id');
    expect($byEmp)->toHaveKey($this->b)->not->toHaveKey($this->a)
        ->and($byEmp[$this->b]['rule_code'])->toBe('net_increase')
        ->and($byEmp[$this->b]['explanation'])->not->toBe('')
        ->and($byEmp[$this->b]['previous_value'])->toBe('2000.00')
        ->and($byEmp[$this->b]['current_value'])->toBe('3000.00')
        ->and($byEmp[$this->b]['causes'][0]['concept_code'] ?? null)->toBe('BASICO');
    $variance = prvCall($this->payroll, 'GET', "/payroll-periods/{$feb}/variance")->assertOk();
    $this->assertMatchesOpenApi($variance, 'get', '/payroll-periods/{id}/variance', 200);

    // Los montos son sensibles: sin payroll.results.view no se ven las anomalías.
    $this->assertMatchesOpenApi(prvCall($this->employee, 'GET', "/payroll-periods/{$feb}/anomalies")->assertForbidden(), 'get', '/payroll-periods/{id}/anomalies', 403);
    $this->assertMatchesOpenApi(prvCall($this->other, 'GET', "/payroll-periods/{$feb}/anomalies")->assertNotFound(), 'get', '/payroll-periods/{id}/anomalies', 404);

    // Enviar con anomalías abiertas exige confirmarlas.
    $pending = prvCall($this->payroll, 'POST', "/payroll-periods/{$feb}/submit")->assertStatus(409);
    $this->assertMatchesOpenApi($pending, 'post', '/payroll-periods/{id}/submit', 409);
    expect($pending->json('error.code'))->toBe('anomalies_pending');

    $anomalyId = (string) $byEmp[$this->b]['id'];
    $this->assertMatchesOpenApi(prvCall($this->payroll, 'POST', "/payroll-run-anomalies/{$anomalyId}/review")->assertStatus(422), 'post', '/payroll-run-anomalies/{id}/review', 422);
    $reviewed = prvCall($this->payroll, 'POST', "/payroll-run-anomalies/{$anomalyId}/review", ['note' => 'Aumento aprobado en enero'])->assertOk();
    $this->assertMatchesOpenApi($reviewed, 'post', '/payroll-run-anomalies/{id}/review', 200);
    expect($reviewed->json('data.status'))->toBe('reviewed');
    $again = prvCall($this->payroll, 'POST', "/payroll-run-anomalies/{$anomalyId}/dismiss", ['note' => 'otra vez'])->assertStatus(409);
    $this->assertMatchesOpenApi($again, 'post', '/payroll-run-anomalies/{id}/dismiss', 409);

    prvCall($this->payroll, 'POST', "/payroll-periods/{$feb}/submit")->assertOk();
    prvCall($this->admin, 'POST', "/payroll-periods/{$feb}/approve")->assertOk();

    // Una anomalía crítica abierta impide cerrar; descartada con nota, el cierre procede y la corrida queda cerrada.
    $critical = TenancyScope::runAs(PRV_TENANT, fn () => (string) DB::table('payroll_run_anomalies')->insertGetId(['tenant_id' => PRV_TENANT, 'payroll_run_id' => $run,
        'employment_id' => $this->a, 'rule_code' => 'negative_net', 'severity' => 'critical', 'explanation' => 'Neto negativo']));
    $blocked = prvCall($this->payroll, 'POST', "/payroll-periods/{$feb}/close")->assertStatus(409);
    expect($blocked->json('error.code'))->toBe('critical_anomalies');
    $dismissed = prvCall($this->payroll, 'POST', "/payroll-run-anomalies/{$critical}/dismiss", ['note' => 'Corregido fuera de planilla']);
    $this->assertMatchesOpenApi($dismissed->assertOk(), 'post', '/payroll-run-anomalies/{id}/dismiss', 200);
    $closed = prvCall($this->payroll, 'POST', "/payroll-periods/{$feb}/close")->assertOk();
    expect($closed->json('data.status'))->toBe('closed')->and($closed->json('data.allowed_actions'))->toContain('complementary');
    TenancyScope::runAs(PRV_TENANT, fn () => expect(DB::table('payroll_runs')->where('id', $run)->value('status'))->toBe('closed'));

    $tid = (string) $threshold->json('data.id');
    $this->assertMatchesOpenApi(prvCall($this->admin, 'DELETE', "/payroll-variance-thresholds/{$tid}")->assertOk(), 'delete', '/payroll-variance-thresholds/{id}', 200);
    $this->assertMatchesOpenApi(prvCall($this->other, 'DELETE', "/payroll-variance-thresholds/{$tid}")->assertNotFound(), 'delete', '/payroll-variance-thresholds/{id}', 404);
});

test('PER-10 · PER-9 · lotes por banco, archivo, confirmación idempotente, pagado y planilla complementaria', function (): void {
    $jan = prvPeriod($this, 1);
    prvCalculate($this, $jan);

    // Antes del cierre no se preparan lotes ni hay complementaria.
    $early = prvCall($this->payroll, 'POST', "/payroll-periods/{$jan}/payment-batches")->assertStatus(409);
    $this->assertMatchesOpenApi($early, 'post', '/payroll-periods/{id}/payment-batches', 409);
    $this->assertMatchesOpenApi(prvCall($this->admin, 'POST', "/payroll-periods/{$jan}/complementary")->assertStatus(409), 'post', '/payroll-periods/{id}/complementary', 409);
    prvCloseFlow($this, $jan);

    // Solo A tiene cuenta de haberes activa (COL-8); B queda en la lista de trabajadores sin cuenta.
    TenancyScope::runAs(PRV_TENANT, fn () => DB::table('person_bank_accounts')->insert(['tenant_id' => PRV_TENANT,
        'person_id' => DB::table('employments')->where('id', $this->a)->value('person_id'), 'employment_id' => $this->a, 'purpose' => 'salary',
        'bank_code' => 'BCP', 'account_type' => 'savings', 'account_number_enc' => FieldCipher::encrypt('19100000000001'),
        'currency' => 'PEN', 'status' => 'active', 'valid_from' => '2025-01-01']));

    $this->assertMatchesOpenApi(prvCall($this->employee, 'POST', "/payroll-periods/{$jan}/payment-batches")->assertForbidden(), 'post', '/payroll-periods/{id}/payment-batches', 403);
    $prep = prvCall($this->payroll, 'POST', "/payroll-periods/{$jan}/payment-batches", ['payment_date' => '2026-01-28'])->assertCreated();
    $this->assertMatchesOpenApi($prep, 'post', '/payroll-periods/{id}/payment-batches', 201);
    expect($prep->json('data.batches'))->toHaveCount(1)
        ->and($prep->json('data.batches.0.bank_code'))->toBe('BCP')
        ->and($prep->json('data.batches.0.total.amount'))->toBe('6000.00')
        ->and(collect($prep->json('data.unassigned'))->pluck('employment_id')->all())->toBe([$this->b])
        ->and($prep->json('data.totals.balanced'))->toBeTrue();
    $dup = prvCall($this->payroll, 'POST', "/payroll-periods/{$jan}/payment-batches")->assertStatus(409);
    expect($dup->json('error.code'))->toBe('batches_exist');
    $batch = (string) $prep->json('data.batches.0.id');

    $this->assertMatchesOpenApi(prvCall($this->payroll, 'GET', "/payroll-periods/{$jan}/payment-batches")->assertOk(), 'get', '/payroll-periods/{id}/payment-batches', 200);
    $this->assertMatchesOpenApi(prvCall($this->payroll, 'GET', "/payroll-payment-batches/{$batch}")->assertOk(), 'get', '/payroll-payment-batches/{id}', 200);
    $this->assertMatchesOpenApi(prvCall($this->other, 'GET', "/payroll-payment-batches/{$batch}")->assertNotFound(), 'get', '/payroll-payment-batches/{id}', 404);
    $this->assertMatchesOpenApi(prvCall($this->payroll, 'GET', "/payroll-payment-batches/{$batch}/file")->assertStatus(409), 'get', '/payroll-payment-batches/{id}/file', 409);

    $gen = prvCall($this->payroll, 'POST', "/payroll-payment-batches/{$batch}/generate")->assertOk();
    $this->assertMatchesOpenApi($gen, 'post', '/payroll-payment-batches/{id}/generate', 200);
    expect($gen->json('data.has_file'))->toBeTrue()->and($gen->json('data.file_format'))->toBe('generic_csv');
    $file = prvCall($this->payroll, 'GET', "/payroll-payment-batches/{$batch}/file")->assertOk();
    expect($file->headers->get('Content-Type'))->toContain('text/csv')->and($file->getContent())->toContain('19100000000001')->toContain('6000.00');

    // Pagado exige todos los lotes confirmados.
    $notYet = prvCall($this->payroll, 'POST', "/payroll-periods/{$jan}/mark-paid")->assertStatus(409);
    $this->assertMatchesOpenApi($notYet, 'post', '/payroll-periods/{id}/mark-paid', 409);
    $this->assertMatchesOpenApi(prvCall($this->payroll, 'POST', "/payroll-payment-batches/{$batch}/confirm")->assertStatus(422), 'post', '/payroll-payment-batches/{id}/confirm', 422);
    $conf = prvCall($this->payroll, 'POST', "/payroll-payment-batches/{$batch}/confirm", ['external_reference' => 'OP-778'])->assertOk();
    $this->assertMatchesOpenApi($conf, 'post', '/payroll-payment-batches/{id}/confirm', 200);
    expect($conf->json('data.status'))->toBe('confirmed');
    prvCall($this->payroll, 'POST', "/payroll-payment-batches/{$batch}/confirm", ['external_reference' => 'OP-778'])->assertOk(); // idempotente
    $cancel = prvCall($this->payroll, 'POST', "/payroll-payment-batches/{$batch}/cancel", ['reason' => 'tarde'])->assertStatus(409);
    $this->assertMatchesOpenApi($cancel, 'post', '/payroll-payment-batches/{id}/cancel', 409);

    $paid = prvCall($this->payroll, 'POST', "/payroll-periods/{$jan}/mark-paid", ['payment_method' => 'bank_file'])->assertOk();
    $this->assertMatchesOpenApi($paid, 'post', '/payroll-periods/{id}/mark-paid', 200);
    expect($paid->json('data.status'))->toBe('paid')->and($paid->json('data.paid_at'))->toBeString();
    TenancyScope::runAs(PRV_TENANT, function () use ($batch): void {
        expect(DB::table('outbox_events')->where('event_type', 'payroll.paid.v1')->count())->toBe(1)
            ->and(DB::table('payroll_payment_items')->where('payroll_payment_batch_id', $batch)->value('status'))->toBe('paid')
            ->and(DB::table('audit_events')->where('entity_id', $batch)->where('action', 'payroll_payment_batch.confirmed')->count())->toBe(1);
    });

    // PER-9 · Complementaria del periodo pagado: nuevo periodo special enlazado; el original y su corrida cerrada no cambian.
    $comp = prvCall($this->admin, 'POST', "/payroll-periods/{$jan}/complementary", ['reason' => 'Bono omitido'])->assertCreated();
    $this->assertMatchesOpenApi($comp, 'post', '/payroll-periods/{id}/complementary', 201);
    expect($comp->json('data.kind'))->toBe('special')->and($comp->json('data.complements_period_id'))->toBe($jan)->and($comp->json('data.status'))->toBe('open');
    $this->assertMatchesOpenApi(prvCall($this->employee, 'POST', "/payroll-periods/{$jan}/complementary")->assertStatus(403), 'post', '/payroll-periods/{id}/complementary', 403);
    expect(prvCall($this->payroll, 'GET', "/payroll-periods/{$jan}")->assertOk()->json('data.status'))->toBe('paid');
});

test('PER-8 · con flujo WFL activo el envío lo arranca, la aprobación directa se rechaza y el flujo decide', function (): void {
    $approver = prvUser(PRV_TENANT, 'admin_empresa');
    $flow = prvCall($this->admin, 'POST', '/approval-flows', ['name' => 'Planilla', 'subject_type' => 'payroll_period',
        'steps' => ['levels' => [['mode' => 'sequential', 'approvers' => [['kind' => 'user', 'user_id' => $approver->id]]]]]])->assertCreated();
    prvCall($this->admin, 'POST', '/approval-flows/'.$flow->json('data.id').'/publish')->assertOk();
    $instance = fn (string $p): string => (string) TenancyScope::runAs(PRV_TENANT, fn () => DB::table('workflow_instances')
        ->where('subject_type', 'payroll_period')->where('subject_id', $p)->whereIn('status', ['pending', 'running', 'escalated'])->value('id'));

    $jan = prvPeriod($this, 1);
    prvCalculate($this, $jan);
    $sent = prvCall($this->payroll, 'POST', "/payroll-periods/{$jan}/submit")->assertOk();
    $this->assertMatchesOpenApi($sent, 'post', '/payroll-periods/{id}/submit', 200);
    expect($sent->json('data.status'))->toBe('in_review')->and($sent->json('data.approval'))->not->toBeNull();
    $first = $instance($jan);
    expect($first)->not->toBe('');

    // Con el flujo en curso la aprobación directa no procede.
    $direct = prvCall($this->admin, 'POST', "/payroll-periods/{$jan}/approve")->assertStatus(409);
    $this->assertMatchesOpenApi($direct, 'post', '/payroll-periods/{id}/approve', 409);
    expect($direct->json('error.code'))->toBe('managed_by_flow');
    prvCall($this->admin, 'POST', "/payroll-periods/{$jan}/reject", ['reason' => 'x'])->assertStatus(409);

    // El rechazo del flujo devuelve el periodo a calculado con el motivo.
    $rej = prvCall($approver, 'POST', "/approval-requests/{$first}/reject", ['comment' => 'Revisar bonos'])->assertOk();
    $this->assertMatchesOpenApi($rej, 'post', '/approval-requests/{id}/reject', 200);
    $back = prvCall($this->payroll, 'GET', "/payroll-periods/{$jan}")->assertOk();
    expect($back->json('data.status'))->toBe('calculated')->and($back->json('data.return_reason'))->toBe('Revisar bonos');

    // Reenviado, la aprobación del flujo deja el periodo aprobado y la corrida aprobada.
    prvCall($this->payroll, 'POST', "/payroll-periods/{$jan}/submit")->assertOk();
    $second = $instance($jan);
    expect($second)->not->toBe($first);
    $ok = prvCall($approver, 'POST', "/approval-requests/{$second}/approve")->assertOk();
    $this->assertMatchesOpenApi($ok, 'post', '/approval-requests/{id}/approve', 200);
    $done = prvCall($this->payroll, 'GET', "/payroll-periods/{$jan}")->assertOk();
    $this->assertMatchesOpenApi($done, 'get', '/payroll-periods/{id}', 200);
    expect($done->json('data.status'))->toBe('approved');
    TenancyScope::runAs(PRV_TENANT, fn () => expect(DB::table('payroll_runs')->where('payroll_period_id', $jan)->where('status', 'approved')->exists())->toBeTrue());
});
