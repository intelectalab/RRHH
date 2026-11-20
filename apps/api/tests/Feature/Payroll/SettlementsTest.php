<?php

/**
 * LIQ-1 · Cálculo de la liquidación al cese con el motor de nómina (truncos, vacaciones no gozadas, indemnización con
 * el tope del régimen, descuentos hasta el tope, explicación por línea); LIQ-2 · simulador sin guardar con dos fechas;
 * LIQ-3 · envío, aprobación con segregación de funciones, plazo legal de pago con aviso y pago; LIQ-4 · documentos de
 * cese, firma y entrega al portal.
 */

use App\Modules\CoreHR\Application\TerminationReasonCatalog;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Payroll\Application\Settlements\Settlements;
use App\Modules\Payroll\Infrastructure\Subscribers\OpenSettlementOnTermination;
use App\Shared\LegalParameters\LegalParameters;
use App\Shared\Outbox\DomainEventTypes;
use App\Shared\Outbox\Models\OutboxEvent;
use App\Shared\Permissions\ModuleGate;
use App\Shared\Permissions\PermissionResolver;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\IdentityFixtures;
use Tests\Support\PayrollFixtures;

uses(DatabaseTransactions::class);

const LIQ_TENANT = 'aaaaaaaa-0119-4000-8000-00000000000a';
const LIQ_OTHER = 'bbbbbbbb-0119-4000-8000-00000000000b';

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requiere PostgreSQL.');
    }
    Storage::fake('s3');
    config(['files.disk' => 's3']);
    TenancyScope::clear();
    app(LegalParameters::class)->sync('PE');
    foreach ([LIQ_TENANT => 'liq-org', LIQ_OTHER => 'liq-otra'] as $id => $slug) {
        DB::table('tenants')->insertOrIgnore(['id' => $id, 'slug' => $slug, 'name' => 'Grupo '.$slug, 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN']);
        DB::select('SELECT seed_tenant_defaults(?)', [$id]);
        TenancyScope::runAs($id, fn () => IdentityFixtures::enableAllModules());
    }
    $this->payroll = liqUser(LIQ_TENANT, 'payroll');
    $this->admin = liqUser(LIQ_TENANT, 'admin_empresa');
    $this->employee = liqUser(LIQ_TENANT, 'empleado');
    $this->other = liqUser(LIQ_OTHER, 'admin_empresa');
    $this->employment = TenancyScope::runAs(LIQ_TENANT, function (): string {
        foreach (['gratificacion_trunca' => ['earning', 50], 'bonif_extraordinaria_trunca' => ['earning', 60], 'cts_trunca' => ['benefit', 70],
            'vacaciones_truncas' => ['earning', 30], 'vacaciones_no_gozadas' => ['earning', 31], 'indemnizacion_despido' => ['earning', 40]] as $code => [$kind, $order]) {
            liqPackConcept($code, $kind, $order);
        }
        PayrollFixtures::concept(['code' => 'dscto_prestamo', 'kind' => 'deduction', 'sort_order' => 300]);
        // Regular: no corre en la liquidación salvo que se pida la remuneración pendiente.
        liqPackConcept('rem_basica', 'earning', 10);
        app(TerminationReasonCatalog::class)->ensureCountryReasons();

        return PayrollFixtures::employment(PayrollFixtures::entity(), '2024-03-01', [['amount' => '3000.00', 'valid_from' => '2024-03-01']]);
    });
});

afterEach(fn () => TenancyScope::clear());

function liqUser(string $tenant, string $role): User
{
    return TenancyScope::runAs($tenant, function () use ($role): User {
        $u = User::factory()->create();
        $u->roleAssignments()->create(['role_id' => Role::query()->where('system_role_code', $role)->value('id')]);

        return $u;
    });
}

/** @param array<string, mixed> $body */
function liqCall(User $user, string $method, string $path, array $body = []): TestResponse
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

function liqPackConcept(string $code, string $kind, int $order): void
{
    $all = json_decode((string) file_get_contents(base_path('packages/country-pe/data/conceptos_pe.json')), true)['conceptos'];
    $c = collect($all)->firstWhere('codigo', $code);
    PayrollFixtures::concept(['code' => $code, 'kind' => $kind, 'formula' => $c['formula_motor'], 'condition' => $c['condicion_motor'] ?? null,
        'sort_order' => $order, 'kinds' => $c['procesos']]);
}

/** Liquidación creada por el cese registrado (RN-LIQ-01), como lo hace el suscriptor del evento. */
function liqOpen(string $employment, string $date = '2026-08-15', string $reason = 'dismissal'): string
{
    return TenancyScope::runAs(LIQ_TENANT, function () use ($employment, $date, $reason): string {
        $event = new OutboxEvent(['event_type' => DomainEventTypes::OFFBOARDING_STARTED, 'payload' => [
            'employment_id' => $employment, 'termination_id' => null, 'termination_date' => $date, 'reason' => $reason]]);
        $event->tenant_id = LIQ_TENANT;
        app(OpenSettlementOnTermination::class)->handle($event);

        return (string) DB::table('settlements')->where('employment_id', $employment)->orderByDesc('created_at')->value('id');
    });
}

it('crea la liquidación en borrador al registrarse el cese y calcula truncos e indemnización con su explicación (LIQ-1)', function (): void {
    $id = liqOpen($this->employment);
    $draft = liqCall($this->payroll, 'GET', "/settlements/{$id}")->assertOk();
    $this->assertMatchesOpenApi($draft, 'get', '/settlements/{id}', 200);
    expect($draft->json('data.status'))->toBe('draft')->and($draft->json('data.termination_date'))->toBe('2026-08-15')
        ->and($draft->json('data.reason_code'))->toBe('dismissal')->and($draft->json('data.allowed_actions'))->toBe(['calculate', 'void']);

    // 422 con datos inválidos, 403 sin permiso, 404 desde otra organización.
    $this->assertMatchesOpenApi(liqCall($this->payroll, 'PATCH', "/settlements/{$id}", ['deductions' => [['kind' => 'x', 'amount' => '-1']]])->assertUnprocessable(), 'patch', '/settlements/{id}', 422);
    $this->assertMatchesOpenApi(liqCall($this->employee, 'POST', "/settlements/{$id}/calculate")->assertForbidden(), 'post', '/settlements/{id}/calculate', 403);
    $this->assertMatchesOpenApi(liqCall($this->other, 'POST', "/settlements/{$id}/calculate")->assertNotFound(), 'post', '/settlements/{id}/calculate', 404);
    // Estados solo por acciones: aprobar un borrador es una transición inválida; status en PATCH se ignora.
    $this->assertMatchesOpenApi(liqCall($this->admin, 'POST', "/settlements/{$id}/approve")->assertStatus(409), 'post', '/settlements/{id}/approve', 409);
    $patched = liqCall($this->payroll, 'PATCH', "/settlements/{$id}", ['severance_applies' => true, 'status' => 'paid',
        'deductions' => [['kind' => 'loan_balance', 'amount' => '500.00', 'description' => 'Préstamo personal']]])->assertOk();
    $this->assertMatchesOpenApi($patched, 'patch', '/settlements/{id}', 200);
    expect($patched->json('data.status'))->toBe('draft')->and($patched->json('data.severance_applies'))->toBeTrue();

    $calc = liqCall($this->payroll, 'POST', "/settlements/{$id}/calculate")->assertOk();
    $this->assertMatchesOpenApi($calc, 'post', '/settlements/{id}/calculate', 200);
    $lines = collect($calc->json('data.lines'))->keyBy('concept_code');
    // Ingreso 01-03-2024, cese 15-08-2026, sueldo 3000, régimen general (parámetros del paquete):
    // gratificación trunca: julio (1 mes) → 3000 / 6 = 500; bonificación 9 % → 45.
    // CTS trunca: 01-05 al 15-08 (3 meses y 15 días) → 3000 × (3/12 + 15/360) = 875.
    // Vacaciones truncas: 01-03 al 15-08 (5 meses y 15 días) → 3000 × (5/12 + 15/360) = 1375.
    // Indemnización: 29 meses y 15 días × 45 remuneraciones diarias por año → 110.625 días × 100 = 11062.50.
    expect($lines->map(fn ($l) => $l['amount']['amount'])->sortKeys()->all())->toBe([
        'bonif_extraordinaria_trunca' => '45.00', 'cts_trunca' => '875.00', 'dscto_prestamo' => '500.00',
        'gratificacion_trunca' => '500.00', 'indemnizacion_despido' => '11062.50', 'vacaciones_truncas' => '1375.00',
    ])->and($lines['cts_trunca']['kind'])->toBe('cts_truncated')->and($lines['indemnizacion_despido']['kind'])->toBe('severance_indemnity')
        ->and($lines['dscto_prestamo']['kind'])->toBe('loan_balance')
        ->and($lines['cts_trunca']['explanation'])->toContain('3')->toContain('15')
        ->and($lines['indemnizacion_despido']['trace']['formula'])->toContain('param.indemnizacion_tope_dias')
        ->and($calc->json('data.total.amount'))->toBe('13357.50')->and($calc->json('data.status'))->toBe('calculated')
        ->and(collect($calc->json('data.warnings'))->pluck('code')->all())->toContain('payment_deadline_unconfirmed')->toContain('deduction_limit_unconfirmed');
    // El sueldo del mes no se paga dos veces: la planilla regular lo paga salvo que se pida.
    expect($lines->has('rem_basica'))->toBeFalse();

    TenancyScope::runAs(LIQ_TENANT, function () use ($id): void {
        expect(DB::table('audit_events')->where('entity_type', 'settlement')->where('entity_id', $id)->where('action', 'settlement.calculated')->exists())->toBeTrue()
            ->and(DB::table('outbox_events')->where('event_type', DomainEventTypes::SETTLEMENT_CALCULATED)->where('aggregate_id', $id)->exists())->toBeTrue()
            ->and(DB::table('data_access_logs')->where('entity_type', 'Settlement')->where('entity_id', $id)->exists())->toBeTrue();
    });

    // Recalcular conserva las líneas del cálculo anterior (nada se sobrescribe).
    liqCall($this->payroll, 'PATCH', "/settlements/{$id}", ['severance_applies' => false, 'deductions' => []])->assertOk();
    $again = liqCall($this->payroll, 'POST', "/settlements/{$id}/calculate")->assertOk();
    expect(collect($again->json('data.lines'))->pluck('concept_code')->all())->not->toContain('indemnizacion_despido')
        ->and(collect($again->json('data.warnings'))->pluck('code')->all())->toContain('severance_not_requested');
    TenancyScope::runAs(LIQ_TENANT, fn () => expect(DB::table('settlement_lines')->where('settlement_id', $id)->where('calculation_number', 1)->count())->toBe(6));

    $list = liqCall($this->payroll, 'GET', '/settlements')->assertOk();
    $this->assertMatchesOpenApi($list, 'get', '/settlements', 200);
    expect(collect($list->json('data'))->pluck('id')->all())->toContain($id);
    expect(collect(liqCall($this->other, 'GET', '/settlements')->assertOk()->json('data'))->pluck('id')->all())->not->toContain($id);
});

it('no paga indemnización en periodo de prueba y limita los descuentos al tope del neto (LIQ-1)', function (): void {
    TenancyScope::runAs(LIQ_TENANT, function (): void {
        DB::table('employments')->where('id', $this->employment)->update(['probation_ends_on' => '2026-09-30']);
        PayrollFixtures::parameter('liquidacion_descuento_tope_pct', '10');
    });
    $id = liqOpen($this->employment);
    liqCall($this->payroll, 'PATCH', "/settlements/{$id}", ['severance_applies' => true, 'deductions' => [['kind' => 'loan_balance', 'amount' => '9000.00']]])->assertOk();
    $calc = liqCall($this->payroll, 'POST', "/settlements/{$id}/calculate")->assertOk();
    $lines = collect($calc->json('data.lines'))->keyBy('concept_code');
    // Sin indemnización: 1375 + 500 + 45 + 875 = 2795 de neto; el préstamo se descuenta hasta el 10 % (279.50).
    expect($lines->has('indemnizacion_despido'))->toBeFalse()->and($lines['dscto_prestamo']['amount']['amount'])->toBe('279.50')
        ->and(collect($calc->json('data.warnings'))->pluck('code')->all())->toContain('severance_in_probation')->toContain('deduction_capped')
        ->and($calc->json('data.periods.deductions.0.remaining'))->toBe('8720.50');
});

it('simula una o dos fechas de cese sin guardar nada y deja el acceso sensible registrado (LIQ-2)', function (): void {
    $before = TenancyScope::runAs(LIQ_TENANT, fn (): array => [DB::table('settlements')->count(), DB::table('terminations')->count(),
        DB::table('employments')->where('id', $this->employment)->value('status'), DB::table('audit_events')->count()]);
    $this->assertMatchesOpenApi(liqCall($this->employee, 'POST', '/settlements/simulate', ['employment_id' => $this->employment, 'termination_dates' => ['2026-08-15']])->assertForbidden(), 'post', '/settlements/simulate', 403);
    $this->assertMatchesOpenApi(liqCall($this->payroll, 'POST', '/settlements/simulate', ['employment_id' => $this->employment, 'termination_dates' => ['15/08/2026']])->assertUnprocessable(), 'post', '/settlements/simulate', 422);
    liqCall($this->other, 'POST', '/settlements/simulate', ['employment_id' => $this->employment, 'termination_dates' => ['2026-08-15']])->assertUnprocessable();

    $sim = liqCall($this->payroll, 'POST', '/settlements/simulate', ['employment_id' => $this->employment, 'termination_dates' => ['2026-08-15', '2026-09-15'],
        'severance_applies' => true])->assertOk();
    $this->assertMatchesOpenApi($sim, 'post', '/settlements/simulate', 200);
    expect($sim->json('data.simulated'))->toBeTrue()->and($sim->json('data.scenarios'))->toHaveCount(2)
        ->and($sim->json('data.scenarios.0.total.amount'))->toBe('13857.50');
    // Un mes más: la diferencia sale del cálculo, no del navegador.
    expect($sim->json('data.comparison.net_difference.amount'))->not->toBe('0');

    TenancyScope::runAs(LIQ_TENANT, function () use ($before): void {
        expect([DB::table('settlements')->count(), DB::table('terminations')->count(),
            DB::table('employments')->where('id', $this->employment)->value('status'), DB::table('audit_events')->count()])->toBe($before)
            ->and(DB::table('data_access_logs')->where('entity_type', 'SettlementSimulation')->where('entity_id', $this->employment)->count())->toBe(1);
    });
});

it('envía, aprueba con segregación, controla el plazo de pago, paga y entrega los documentos de cese (LIQ-3 y LIQ-4)', function (): void {
    TenancyScope::runAs(LIQ_TENANT, fn () => PayrollFixtures::parameter('liquidacion_plazo_pago_horas', '48'));
    $id = liqOpen($this->employment, now()->subDays(2)->toDateString(), 'resignation');
    // Quien calcula no aprueba (RN-LIQ-06).
    liqCall($this->admin, 'POST', "/settlements/{$id}/calculate")->assertOk();
    $sent = liqCall($this->admin, 'POST', "/settlements/{$id}/submit")->assertOk();
    $this->assertMatchesOpenApi($sent, 'post', '/settlements/{id}/submit', 200);
    expect($sent->json('data.status'))->toBe('pending_approval')->and($sent->json('data.due_on'))->not->toBeNull()
        ->and($sent->json('data.due_status'))->toBe('due_soon');
    $this->assertMatchesOpenApi(liqCall($this->admin, 'POST', "/settlements/{$id}/approve")->assertStatus(409), 'post', '/settlements/{id}/approve', 409);
    $this->assertMatchesOpenApi(liqCall($this->payroll, 'POST', "/settlements/{$id}/approve")->assertForbidden(), 'post', '/settlements/{id}/approve', 403);

    // Devuelta con observación y vuelta a enviar por quien calcula (Payroll).
    $this->assertMatchesOpenApi(liqCall($this->admin, 'POST', "/settlements/{$id}/return", [])->assertUnprocessable(), 'post', '/settlements/{id}/return', 422);
    $returned = liqCall($this->admin, 'POST', "/settlements/{$id}/return", ['reason' => 'Revisar descuentos'])->assertOk();
    $this->assertMatchesOpenApi($returned, 'post', '/settlements/{id}/return', 200);
    expect($returned->json('data.status'))->toBe('calculated')->and($returned->json('data.return_reason'))->toBe('Revisar descuentos');
    liqCall($this->payroll, 'POST', "/settlements/{$id}/calculate")->assertOk();
    liqCall($this->payroll, 'POST', "/settlements/{$id}/submit")->assertOk();
    $approved = liqCall($this->admin, 'POST', "/settlements/{$id}/approve")->assertOk();
    $this->assertMatchesOpenApi($approved, 'post', '/settlements/{id}/approve', 200);
    expect($approved->json('data.status'))->toBe('approved')->and($approved->json('data.allowed_actions'))->toBe(['mark-paid']);
    // Inmutable una vez aprobada (RN-LIQ-07).
    liqCall($this->payroll, 'PATCH', "/settlements/{$id}", ['severance_applies' => true])->assertStatus(409);
    $this->assertMatchesOpenApi(liqCall($this->payroll, 'POST', "/settlements/{$id}/void", ['reason' => 'x'])->assertStatus(409), 'post', '/settlements/{id}/void', 409);

    // RN-LIQ-02 · Aviso del plazo: una sola vez.
    $alerted = TenancyScope::runAs(LIQ_TENANT, fn (): array => [app(Settlements::class)->alertDue(), app(Settlements::class)->alertDue()]);
    expect($alerted)->toBe([1, 0]);
    TenancyScope::runAs(LIQ_TENANT, fn () => expect(DB::table('outbox_events')->where('event_type', DomainEventTypes::SETTLEMENT_PAYMENT_DUE)->where('aggregate_id', $id)->count())->toBe(1));

    $paid = liqCall($this->payroll, 'POST', "/settlements/{$id}/mark-paid", ['paid_on' => now()->toDateString(), 'payment_reference' => 'TRF-9001'])->assertOk();
    $this->assertMatchesOpenApi($paid, 'post', '/settlements/{id}/mark-paid', 200);
    expect($paid->json('data.status'))->toBe('paid')->and($paid->json('data.due_status'))->toBe('paid_on_time')
        ->and(collect($paid->json('data.documents'))->pluck('kind')->all())->toBe(['settlement_statement', 'work_certificate', 'cts_release_letter', 'termination_record']);

    // Sin firmas no se entregan; se envían a firma (DOC-6) o se confirma la firma física.
    $this->assertMatchesOpenApi(liqCall($this->payroll, 'POST', "/settlements/{$id}/deliver-documents")->assertStatus(409), 'post', '/settlements/{id}/deliver-documents', 409);
    $docs = liqCall($this->payroll, 'POST', "/settlements/{$id}/documents", ['send_for_signature' => true])->assertOk();
    $this->assertMatchesOpenApi($docs, 'post', '/settlements/{id}/documents', 200);
    expect(collect($docs->json('data.documents'))->pluck('status')->unique()->all())->toBe(['pending_signature']);
    $delivered = liqCall($this->payroll, 'POST', "/settlements/{$id}/deliver-documents", ['confirm_physical_signature' => true, 'delivery_method' => 'physical'])->assertOk();
    $this->assertMatchesOpenApi($delivered, 'post', '/settlements/{id}/deliver-documents', 200);
    expect($delivered->json('data.status'))->toBe('documents_delivered');
    TenancyScope::runAs(LIQ_TENANT, function () use ($id): void {
        $docIds = DB::table('termination_documents')->where('settlement_id', $id)->pluck('document_id')->all();
        expect(DB::table('documents')->whereIn('id', $docIds)->where('visible_to_employee', true)->count())->toBe(4)
            ->and(DB::table('outbox_events')->where('event_type', DomainEventTypes::SETTLEMENT_PAID)->where('aggregate_id', $id)->exists())->toBeTrue()
            ->and(DB::table('outbox_events')->where('event_type', DomainEventTypes::SETTLEMENT_DOCUMENTS_ISSUED)->where('aggregate_id', $id)->exists())->toBeTrue();
    });
});

it('crea manualmente y anula con motivo antes de aprobar (ME-23)', function (): void {
    $this->assertMatchesOpenApi(liqCall($this->payroll, 'POST', '/settlements', ['employment_id' => $this->employment])->assertUnprocessable(), 'post', '/settlements', 422);
    $this->assertMatchesOpenApi(liqCall($this->employee, 'POST', '/settlements', ['employment_id' => $this->employment, 'termination_date' => '2026-08-15'])->assertForbidden(), 'post', '/settlements', 403);
    $created = liqCall($this->payroll, 'POST', '/settlements', ['employment_id' => $this->employment, 'termination_date' => '2026-08-15', 'reason_code' => 'resignation'])->assertCreated();
    $this->assertMatchesOpenApi($created, 'post', '/settlements', 201);
    $id = (string) $created->json('data.id');
    $this->assertMatchesOpenApi(liqCall($this->payroll, 'POST', "/settlements/{$id}/void", [])->assertUnprocessable(), 'post', '/settlements/{id}/void', 422);
    $void = liqCall($this->payroll, 'POST', "/settlements/{$id}/void", ['reason' => 'Registrada por error'])->assertOk();
    $this->assertMatchesOpenApi($void, 'post', '/settlements/{id}/void', 200);
    expect($void->json('data.status'))->toBe('voided')->and($void->json('data.allowed_actions'))->toBe([]);

});
