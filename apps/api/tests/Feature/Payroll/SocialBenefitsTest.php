<?php

/**
 * BSO-1 · Gratificaciones de julio y diciembre con bonificación extraordinaria por régimen del paquete de país;
 * BSO-2 · CTS semestral (remuneración computable con un sexto de la gratificación, entidad depositaria de COL-8,
 * liquidación de depósito); BSO-3 · distribución de utilidades por días laborados (AUS-6) y remuneraciones, cesados
 * incluidos, a partir de la renta declarada.
 */

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Payroll\Application\PayrollEngine;
use App\Modules\Payroll\Application\PayrollRuns;
use App\Shared\LegalParameters\LegalParameters;
use App\Shared\Permissions\ModuleGate;
use App\Shared\Permissions\PermissionResolver;
use App\Shared\Tenancy\TenancyScope;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\IdentityFixtures;
use Tests\Support\PayrollFixtures;

uses(DatabaseTransactions::class);

const BSO_TENANT = 'aaaaaaaa-0b50-4000-8000-00000000000a';
const BSO_OTHER = 'bbbbbbbb-0b50-4000-8000-00000000000b';

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requiere PostgreSQL.');
    }
    Storage::fake('s3');
    config(['files.disk' => 's3']);
    TenancyScope::clear();
    // Valores legales del paquete de país (regla 4): factores por régimen, tasas y mínimos con su vigencia.
    app(LegalParameters::class)->sync('PE');
    foreach ([BSO_TENANT => 'bso-org', BSO_OTHER => 'bso-otra'] as $id => $slug) {
        DB::table('tenants')->insertOrIgnore(['id' => $id, 'slug' => $slug, 'name' => 'Grupo '.$slug, 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN']);
        DB::select('SELECT seed_tenant_defaults(?)', [$id]);
        TenancyScope::runAs($id, fn () => IdentityFixtures::enableAllModules());
    }
    $this->payroll = bsoUser(BSO_TENANT, 'payroll');
    $this->admin = bsoUser(BSO_TENANT, 'admin_empresa');
    $this->employee = bsoUser(BSO_TENANT, 'empleado');
    $this->other = bsoUser(BSO_OTHER, 'admin_empresa');
});

afterEach(fn () => TenancyScope::clear());

function bsoUser(string $tenant, string $role): User
{
    return TenancyScope::runAs($tenant, function () use ($role): User {
        $u = User::factory()->create();
        $u->roleAssignments()->create(['role_id' => Role::query()->where('system_role_code', $role)->value('id')]);

        return $u;
    });
}

/** @param array<string, mixed> $body */
function bsoCall(User $user, string $method, string $path, array $body = []): TestResponse
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

/** Concepto del paquete de país con su fórmula del motor, su condición y los procesos en que corre. */
function bsoPackConcept(string $code, string $kind, int $order): void
{
    $all = json_decode((string) file_get_contents(base_path('packages/country-pe/data/conceptos_pe.json')), true)['conceptos'];
    $c = collect($all)->firstWhere('codigo', $code);
    PayrollFixtures::concept(['code' => $code, 'kind' => $kind, 'formula' => $c['formula_motor'], 'condition' => $c['condicion_motor'] ?? null,
        'sort_order' => $order, 'kinds' => $c['procesos']]);
}

/** Crea el periodo, lo calcula y procesa la corrida; con $close deja la corrida y el periodo cerrados. */
function bsoRunPeriod(object $t, string $entity, string $kind, int $year, int $number, bool $close = false): string
{
    $code = strtoupper(substr($kind, 0, 4));
    $group = TenancyScope::runAs(BSO_TENANT, fn () => DB::table('payroll_groups')->where('code', $code)->where('legal_entity_id', $entity)->value('id'));
    if ($group === null) {
        $group = (string) bsoCall($t->admin, 'POST', '/payroll-groups', ['code' => $code, 'name' => 'Grupo '.$kind, 'legal_entity_id' => $entity, 'payment_day' => 28, 'cutoff_day' => 25])
            ->assertCreated()->json('data.id');
    }
    $p = (string) bsoCall($t->admin, 'POST', '/payroll-periods', ['payroll_group_id' => $group, 'period_year' => $year, 'period_number' => $number, 'kind' => $kind])
        ->assertCreated()->json('data.id');
    $run = (string) bsoCall($t->payroll, 'POST', "/payroll-periods/{$p}/calculate")->assertOk()->json('data.last_run.id');
    TenancyScope::runAs(BSO_TENANT, function () use ($run, $p, $close): void {
        app(PayrollRuns::class)->process($run);
        if ($close) {
            DB::table('payroll_runs')->where('id', $run)->update(['status' => 'closed']);
            DB::table('payroll_periods')->where('id', $p)->update(['status' => 'closed']);
        }
    });

    return $p;
}

it('calcula la gratificación de julio y la bonificación extraordinaria según el régimen del paquete', function (): void {
    $calc = TenancyScope::runAs(BSO_TENANT, function (): array {
        bsoPackConcept('gratificacion', 'earning', 50);
        bsoPackConcept('bonif_extraordinaria', 'earning', 60);
        PayrollFixtures::concept(['code' => 'BASICO', 'kind' => 'earning', 'formula' => 'sueldo_tramos', 'sort_order' => 1, 'kinds' => ['regular']]);
        $out = [];
        foreach (['pe_general' => '2025-01-01', 'pe_pequena' => '2026-03-01', 'pe_micro' => '2025-01-01'] as $regime => $hire) {
            $e = PayrollFixtures::employment(PayrollFixtures::entity($regime), $hire, [['amount' => '2400.00', 'valid_from' => $hire]], $regime);
            $r = app(PayrollEngine::class)->calculate($e, '2026-07-01', '2026-07-31', 'gratification', null);
            $out[$regime] = collect($r['result']['lines'])->mapWithKeys(fn ($l) => [$l['concept_code'] => $l['amount']['amount']])->all();
            $out[$regime.'_months'] = (string) ($r['context']['variables']['meses_computables'] ?? '');
        }

        return $out;
    });

    // General: semestre completo (factor 1); pequeña: factor 1/2 del paquete con 4 meses (marzo a junio); micro: no aplica.
    expect($calc['pe_general'])->toBe(['gratificacion' => '2400.00', 'bonif_extraordinaria' => '216.00'])
        ->and($calc['pe_general_months'])->toBe('6')
        ->and($calc['pe_pequena'])->toBe(['gratificacion' => '800.00', 'bonif_extraordinaria' => '72.00'])
        ->and($calc['pe_pequena_months'])->toBe('4')
        ->and($calc['pe_micro'])->toBe([]);
});

it('calcula la CTS con un sexto de la gratificación, exige la entidad depositaria y emite la liquidación de depósito', function (): void {
    [$entity, $a, $b] = TenancyScope::runAs(BSO_TENANT, function (): array {
        $entity = PayrollFixtures::entity();
        $a = PayrollFixtures::employment($entity, '2025-01-01', [['amount' => '3000.00', 'valid_from' => '2025-01-01']]);
        $b = PayrollFixtures::employment($entity, '2025-01-01', [['amount' => '2000.00', 'valid_from' => '2025-01-01']]);
        PayrollFixtures::ctsAccount($a, 'BBVA');
        bsoPackConcept('cts', 'benefit', 70);
        PayrollFixtures::concept(['code' => 'BASICO', 'kind' => 'earning', 'formula' => 'sueldo_tramos', 'sort_order' => 1, 'kinds' => ['regular']]);
        // Gratificación de diciembre traída del sistema anterior (PER-12).
        DB::table('payroll_opening_balances')->insert(['tenant_id' => BSO_TENANT, 'employment_id' => $a, 'fiscal_year' => 2025, 'cutoff_date' => '2025-12-15',
            'kind' => 'gratification_base', 'amount' => '3000.00', 'currency' => 'PEN', 'evidence' => 'Planilla de diciembre del sistema anterior']);

        return [$entity, $a, $b];
    });
    $p = bsoRunPeriod($this, $entity, 'cts', 2026, 5);

    // B no tiene cuenta de CTS: queda con error y sin depósito (RN-BSO-05).
    TenancyScope::runAs(BSO_TENANT, function () use ($b): void {
        $row = DB::table('payroll_run_employments')->where('employment_id', $b)->first(['status', 'error']);
        expect($row->status)->toBe('error')->and((string) $row->error)->toContain('cts_account_missing');
    });

    $this->assertMatchesOpenApi(bsoCall($this->employee, 'GET', "/payroll-periods/{$p}/cts-deposits")->assertForbidden(), 'get', '/payroll-periods/{id}/cts-deposits', 403);
    $this->assertMatchesOpenApi(bsoCall($this->other, 'GET', "/payroll-periods/{$p}/cts-deposits")->assertNotFound(), 'get', '/payroll-periods/{id}/cts-deposits', 404);
    $list = bsoCall($this->payroll, 'GET', "/payroll-periods/{$p}/cts-deposits")->assertOk();
    $this->assertMatchesOpenApi($list, 'get', '/payroll-periods/{id}/cts-deposits', 200);
    expect($list->json('data'))->toHaveCount(1);
    $d = $list->json('data.0');
    // (3000 + 3000 × 0.166667) × 6 / 12 = 1750.0005 → 1750.00
    expect($d['employment_id'])->toBe($a)->and($d['period_code'])->toBe('CTS-2026-05')->and($d['computable_months'])->toBe(6)
        ->and($d['deposit_bank_code'])->toBe('BBVA')->and($d['amount']['amount'])->toBe('1750.00')
        ->and($d['last_gratification']['amount'])->toBe('3000.00')->and($d['allowed_actions'])->toBe(['deposit']);

    $this->assertMatchesOpenApi(bsoCall($this->employee, 'POST', "/cts-deposits/{$d['id']}/deposit", ['deposited_on' => '2026-05-14', 'deposit_reference' => 'OP-1'])->assertForbidden(), 'post', '/cts-deposits/{id}/deposit', 403);
    $this->assertMatchesOpenApi(bsoCall($this->payroll, 'POST', "/cts-deposits/{$d['id']}/deposit", ['deposited_on' => '14/05/2026'])->assertUnprocessable(), 'post', '/cts-deposits/{id}/deposit', 422);
    $ok = bsoCall($this->payroll, 'POST', "/cts-deposits/{$d['id']}/deposit", ['deposited_on' => '2026-05-14', 'deposit_reference' => 'OP-778899'])->assertOk();
    $this->assertMatchesOpenApi($ok, 'post', '/cts-deposits/{id}/deposit', 200);
    expect($ok->json('data.status'))->toBe('notified')->and($ok->json('data.statement_document_id'))->not->toBeNull();
    // Repetir con la misma referencia no duplica la liquidación; otra acción sobre un depósito emitido es 409.
    expect(bsoCall($this->payroll, 'POST', "/cts-deposits/{$d['id']}/deposit", ['deposited_on' => '2026-05-14', 'deposit_reference' => 'OP-778899'])->assertOk()->json('data.statement_document_id'))
        ->toBe($ok->json('data.statement_document_id'));
    $this->assertMatchesOpenApi(bsoCall($this->payroll, 'POST', "/cts-deposits/{$d['id']}/deposit", ['deposited_on' => '2026-05-15', 'deposit_reference' => 'OTRA'])->assertStatus(409), 'post', '/cts-deposits/{id}/deposit', 409);

    TenancyScope::runAs(BSO_TENANT, function () use ($d, $ok): void {
        $doc = DB::table('documents')->where('id', $ok->json('data.statement_document_id'))->first(['employment_id', 'visible_to_employee']);
        expect($doc->employment_id)->toBe($d['employment_id'])->and($doc->visible_to_employee)->toBeTrue()
            ->and(DB::table('audit_events')->where('action', 'cts_deposit.deposited')->where('entity_id', $d['id'])->count())->toBe(1);
    });
});

it('reparte utilidades por días laborados y remuneraciones, incluye cesados y exige el mínimo de trabajadores', function (): void {
    [$entity, $a, $b] = TenancyScope::runAs(BSO_TENANT, function (): array {
        $entity = PayrollFixtures::entity();
        $a = PayrollFixtures::employment($entity, '2025-01-01', [['amount' => '6000.00', 'valid_from' => '2025-01-01']]);
        $b = PayrollFixtures::employment($entity, '2025-01-01', [['amount' => '3000.00', 'valid_from' => '2025-01-01']]);
        PayrollFixtures::concept(['code' => 'BASICO', 'kind' => 'earning', 'formula' => 'sueldo_tramos', 'sort_order' => 1, 'kinds' => ['regular'], 'affects' => ['utilidades']]);
        PayrollFixtures::parameter('dias_base_mes', '30');

        return [$entity, $a, $b];
    });
    bsoRunPeriod($this, $entity, 'regular', 2025, 1, true);
    // B cesa el 30 de junio de 2025: participa por lo laborado en el ejercicio (RN-BSO-10).
    TenancyScope::runAs(BSO_TENANT, fn () => DB::table('employments')->where('id', $b)->update(['termination_date' => '2025-06-30', 'status' => 'terminated']));

    $body = ['legal_entity_id' => $entity, 'fiscal_year' => 2025, 'net_income_before_tax' => ['amount' => '1000000.00'], 'activity' => 'otras_actividades'];
    $this->assertMatchesOpenApi(bsoCall($this->employee, 'POST', '/profit-sharing-distributions', $body)->assertForbidden(), 'post', '/profit-sharing-distributions', 403);
    $this->assertMatchesOpenApi(bsoCall($this->payroll, 'POST', '/profit-sharing-distributions', array_merge($body, ['activity' => 'x']))->assertUnprocessable(), 'post', '/profit-sharing-distributions', 422);
    $created = bsoCall($this->payroll, 'POST', '/profit-sharing-distributions', $body)->assertCreated();
    $this->assertMatchesOpenApi($created, 'post', '/profit-sharing-distributions', 201);
    $id = (string) $created->json('data.id');
    $this->assertMatchesOpenApi(bsoCall($this->payroll, 'POST', '/profit-sharing-distributions', $body)->assertStatus(409), 'post', '/profit-sharing-distributions', 409);

    // Con el mínimo legal del paquete (más de 20 trabajadores) no corresponde repartir: 422.
    $this->assertMatchesOpenApi(bsoCall($this->payroll, 'POST', "/profit-sharing-distributions/{$id}/calculate")->assertUnprocessable(), 'post', '/profit-sharing-distributions/{id}/calculate', 422);
    TenancyScope::runAs(BSO_TENANT, fn () => PayrollFixtures::parameter('utilidades_minimo_trabajadores', '1'));
    $this->assertMatchesOpenApi(bsoCall($this->payroll, 'POST', "/profit-sharing-distributions/{$id}/approve")->assertForbidden(), 'post', '/profit-sharing-distributions/{id}/approve', 403);
    $this->assertMatchesOpenApi(bsoCall($this->admin, 'POST', "/profit-sharing-distributions/{$id}/approve")->assertStatus(409), 'post', '/profit-sharing-distributions/{id}/approve', 409);

    $calc = bsoCall($this->payroll, 'POST', "/profit-sharing-distributions/{$id}/calculate")->assertOk();
    $this->assertMatchesOpenApi($calc, 'post', '/profit-sharing-distributions/{id}/calculate', 200);
    // 5 % de la renta (otras actividades, paquete) = 50000: 25000 por días y 25000 por remuneraciones.
    expect($calc->json('data.participation_rate'))->toBe('5.0000')->and($calc->json('data.total_amount.amount'))->toBe('50000.00')
        ->and($calc->json('data.workers_count'))->toBe(2)->and($calc->json('data.warnings.0.code'))->toBe('cap_pending_validation');
    $items = collect($calc->json('data.items'))->keyBy('employment_id');
    $ia = $items[$a];
    $ib = $items[$b];
    expect($ib['terminated'])->toBeTrue()
        ->and($ia['remunerations']['amount'])->toBe('6000.00')->and($ib['remunerations']['amount'])->toBe('3000.00')
        ->and($ia['amount_by_remuneration']['amount'])->toBe('16666.67')->and($ib['amount_by_remuneration']['amount'])->toBe('8333.33');
    $da = BigDecimal::of($ia['days_worked']);
    $db = BigDecimal::of($ib['days_worked']);
    expect($da->isGreaterThan($db))->toBeTrue()->and($db->isGreaterThan(0))->toBeTrue()
        ->and($ia['amount_by_days']['amount'])->toBe((string) BigDecimal::of('25000')->multipliedBy($da)->dividedBy($da->plus($db), 2, RoundingMode::HALF_UP))
        ->and((string) BigDecimal::of($ia['amount_by_days']['amount'])->plus($ia['amount_by_remuneration']['amount']))->toBe($ia['amount']['amount']);

    $show = bsoCall($this->payroll, 'GET', "/profit-sharing-distributions/{$id}")->assertOk();
    $this->assertMatchesOpenApi($show, 'get', '/profit-sharing-distributions/{id}', 200);
    $this->assertMatchesOpenApi(bsoCall($this->other, 'GET', "/profit-sharing-distributions/{$id}")->assertNotFound(), 'get', '/profit-sharing-distributions/{id}', 404);
    $index = bsoCall($this->payroll, 'GET', '/profit-sharing-distributions?filter[fiscal_year]=2025')->assertOk();
    $this->assertMatchesOpenApi($index, 'get', '/profit-sharing-distributions', 200);
    expect($index->json('data'))->toHaveCount(1);
    expect(bsoCall($this->other, 'GET', '/profit-sharing-distributions')->assertOk()->json('data'))->toBe([]);

    $approved = bsoCall($this->admin, 'POST', "/profit-sharing-distributions/{$id}/approve")->assertOk();
    $this->assertMatchesOpenApi($approved, 'post', '/profit-sharing-distributions/{id}/approve', 200);
    expect($approved->json('data.status'))->toBe('approved');

    // El proceso de utilidades de planilla (2026) toma el monto asignado de la distribución aprobada del ejercicio 2025.
    $line = TenancyScope::runAs(BSO_TENANT, function () use ($a): ?array {
        bsoPackConcept('utilidades', 'earning', 80);
        $r = app(PayrollEngine::class)->calculate($a, '2026-04-01', '2026-04-30', 'profit_sharing', null);

        return collect($r['result']['lines'])->firstWhere('concept_code', 'utilidades');
    });
    expect($line['amount']['amount'] ?? null)->toBe($ia['amount']['amount']);
});
