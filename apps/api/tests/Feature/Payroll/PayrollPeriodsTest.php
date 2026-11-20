<?php

/**
 * PER-1 · Periodos de planilla (ME-19) con grupos y población; PER-2 · incidencias con origen, autor, visto bueno
 * y bloqueo; PER-3 · consumo de ausencias y vacaciones con corte registrado y diferencias al periodo siguiente.
 */

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Shared\Permissions\ModuleGate;
use App\Shared\Permissions\PermissionResolver;
use App\Shared\Settings\ParameterResolver;
use App\Shared\Tenancy\TenancyScope;
use App\Shared\Time\AbsenceIncidents;
use App\Shared\Time\VacationPayrollIncidents;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\IdentityFixtures;
use Tests\Support\PayrollFixtures;

uses(DatabaseTransactions::class);

const PER_TENANT = 'aaaaaaaa-0e41-4000-8000-00000000000a';
const PER_OTHER = 'bbbbbbbb-0e41-4000-8000-00000000000b';

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requiere PostgreSQL.');
    }
    TenancyScope::clear();
    foreach ([PER_TENANT => 'per-org', PER_OTHER => 'per-otra'] as $id => $slug) {
        DB::table('tenants')->insertOrIgnore(['id' => $id, 'slug' => $slug, 'name' => 'Grupo '.$slug, 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN']);
        DB::select('SELECT seed_tenant_defaults(?)', [$id]);
        TenancyScope::runAs($id, fn () => IdentityFixtures::enableAllModules());
    }
    $this->payroll = perUser(PER_TENANT, 'payroll');
    $this->admin = perUser(PER_TENANT, 'admin_empresa');
    $this->employee = perUser(PER_TENANT, 'empleado');
    $this->other = perUser(PER_OTHER, 'admin_empresa');
    [$this->entity, $this->a, $this->b] = TenancyScope::runAs(PER_TENANT, function (): array {
        $entity = PayrollFixtures::entity();
        $a = PayrollFixtures::employment($entity, '2025-01-01', [['amount' => '3000.00', 'valid_from' => '2025-01-01']]);
        $b = PayrollFixtures::employment($entity, '2026-11-15', [['amount' => '2500.00', 'valid_from' => '2026-11-15']]);
        PayrollFixtures::concept(['code' => 'BONO', 'kind' => 'earning']);
        PayrollFixtures::concept(['code' => 'DESC_FALTA', 'kind' => 'deduction']);
        PayrollFixtures::concept(['code' => 'VAC_COMPRA', 'kind' => 'earning']);

        return [$entity, $a, $b];
    });
});

afterEach(fn () => TenancyScope::clear());

function perUser(string $tenant, string $role): User
{
    return TenancyScope::runAs($tenant, function () use ($role): User {
        $u = User::factory()->create();
        $u->roleAssignments()->create(['role_id' => Role::query()->where('system_role_code', $role)->value('id')]);

        return $u;
    });
}

/** @param array<string, mixed> $body */
function perCall(User $user, string $method, string $path, array $body = []): TestResponse
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

/** Grupo mensual de la empresa con pago el día 28 y corte el 25. */
function perGroup(object $t): string
{
    $r = perCall($t->admin, 'POST', '/payroll-groups', ['code' => 'MENS', 'name' => 'Mensual', 'legal_entity_id' => $t->entity, 'payment_day' => 28, 'cutoff_day' => 25])->assertCreated();
    test()->assertMatchesOpenApi($r, 'post', '/payroll-groups', 201);

    return (string) $r->json('data.id');
}

function perStatus(string $period, string $status): void
{
    TenancyScope::runAs(PER_TENANT, fn () => DB::table('payroll_periods')->where('id', $period)->update(['status' => $status]));
}

test('PER-1 · abrir periodos: fechas, pago y corte del grupo; el anterior debe estar cerrado salvo permiso especial', function (): void {
    $group = perGroup($this);
    $groups = perCall($this->payroll, 'GET', '/payroll-groups')->assertOk();
    $this->assertMatchesOpenApi($groups, 'get', '/payroll-groups', 200);
    expect($groups->json('data.0.legal_entity_id'))->toBe($this->entity);

    $oct = perCall($this->payroll, 'POST', '/payroll-periods', ['payroll_group_id' => $group, 'period_year' => 2026, 'period_number' => 10])->assertCreated();
    $this->assertMatchesOpenApi($oct, 'post', '/payroll-periods', 201);
    expect($oct->json('data'))->toMatchArray(['starts_on' => '2026-10-01', 'ends_on' => '2026-10-31', 'payment_date' => '2026-10-28',
        'attendance_from' => '2026-10-01', 'attendance_to' => '2026-10-25', 'statutory_period_code' => '202610', 'status' => 'open']);
    perCall($this->payroll, 'POST', '/payroll-periods', ['payroll_group_id' => $group, 'period_year' => 2026, 'period_number' => 10])->assertStatus(409);

    // El anterior sigue abierto: Payroll no puede abrir noviembre; el administrador (payroll.periods.reopen) sí.
    $denied = perCall($this->payroll, 'POST', '/payroll-periods', ['payroll_group_id' => $group, 'period_year' => 2026, 'period_number' => 11])->assertStatus(409);
    $this->assertMatchesOpenApi($denied, 'post', '/payroll-periods', 409);
    expect($denied->json('error.code'))->toBe('previous_period_open');
    $nov = perCall($this->admin, 'POST', '/payroll-periods', ['payroll_group_id' => $group, 'period_year' => 2026, 'period_number' => 11])->assertCreated();
    // El corte de noviembre empieza el día siguiente al corte de octubre.
    expect($nov->json('data.attendance_from'))->toBe('2026-10-26')->and($nov->json('data.attendance_to'))->toBe('2026-11-25');

    $pop = perCall($this->payroll, 'GET', '/payroll-periods/'.$oct->json('data.id').'/population')->assertOk();
    $this->assertMatchesOpenApi($pop, 'get', '/payroll-periods/{id}/population', 200);
    expect(array_column($pop->json('data'), 'employment_id'))->toBe([$this->a]); // b ingresa en noviembre
    expect(array_column(perCall($this->payroll, 'GET', '/payroll-periods/'.$nov->json('data.id').'/population')->json('data'), 'employment_id'))
        ->toContain($this->a, $this->b);

    // Con un miembro explícito, el grupo solo incluye a sus miembros.
    $m = perCall($this->admin, 'POST', "/payroll-groups/{$group}/members", ['employment_id' => $this->b, 'valid_from' => '2026-11-15'])->assertCreated();
    $this->assertMatchesOpenApi($m, 'post', '/payroll-groups/{id}/members', 201);
    expect(array_column(perCall($this->payroll, 'GET', '/payroll-periods/'.$nov->json('data.id').'/population')->json('data'), 'employment_id'))->toBe([$this->b]);

    $list = perCall($this->payroll, 'GET', '/payroll-periods?filter[payroll_group_id]='.$group)->assertOk();
    $this->assertMatchesOpenApi($list, 'get', '/payroll-periods', 200);
    expect($list->json('meta.total'))->toBe(2);
    expect(TenancyScope::runAs(PER_TENANT, fn () => DB::table('audit_events')->where('entity_type', 'payroll_period')->where('action', 'payroll_period.opened')->count()))->toBe(2);
    expect(TenancyScope::runAs(PER_TENANT, fn () => DB::table('outbox_events')->where('event_type', 'payroll.period_opened.v1')->count()))->toBe(2);
});

test('PER-1 · el estado solo cambia por acciones con permiso; segregación al aprobar; cierre y reapertura con sus reglas', function (): void {
    $group = perGroup($this);
    $p = (string) perCall($this->payroll, 'POST', '/payroll-periods', ['payroll_group_id' => $group, 'period_year' => 2026, 'period_number' => 10])->json('data.id');

    $closed = perCall($this->payroll, 'POST', "/payroll-periods/{$p}/close-incidents")->assertOk();
    $this->assertMatchesOpenApi($closed, 'post', '/payroll-periods/{id}/close-incidents', 200);
    expect($closed->json('data.status'))->toBe('incidents_closed')->and($closed->json('data.allowed_actions'))->toBe(['reopen-incidents', 'calculate']);
    $this->assertMatchesOpenApi(perCall($this->payroll, 'POST', "/payroll-periods/{$p}/reopen-incidents")->assertOk(), 'post', '/payroll-periods/{id}/reopen-incidents', 200);

    $bad = perCall($this->payroll, 'POST', "/payroll-periods/{$p}/submit")->assertStatus(409);
    $this->assertMatchesOpenApi($bad, 'post', '/payroll-periods/{id}/submit', 409);
    expect($bad->json('error.code'))->toBe('invalid_transition');
    perCall($this->employee, 'POST', "/payroll-periods/{$p}/close-incidents")->assertForbidden();
    perCall($this->payroll, 'POST', "/payroll-periods/{$p}/approve")->assertForbidden();

    perStatus($p, 'calculated'); // el cálculo llega con PER-4
    $submitted = perCall($this->admin, 'POST', "/payroll-periods/{$p}/submit")->assertOk();
    $this->assertMatchesOpenApi($submitted, 'post', '/payroll-periods/{id}/submit', 200);
    // RN-PER-01: quien envió no aprueba.
    $seg = perCall($this->admin, 'POST', "/payroll-periods/{$p}/approve")->assertStatus(409);
    expect($seg->json('error.code'))->toBe('segregation_of_duties');
    $approver = perUser(PER_TENANT, 'admin_empresa');
    perCall($approver, 'POST', "/payroll-periods/{$p}/reject")->assertStatus(422); // motivo obligatorio
    $returned = perCall($approver, 'POST', "/payroll-periods/{$p}/reject", ['reason' => 'Revisar bonos'])->assertOk();
    $this->assertMatchesOpenApi($returned, 'post', '/payroll-periods/{id}/reject', 200);
    expect($returned->json('data.status'))->toBe('calculated')->and($returned->json('data.return_reason'))->toBe('Revisar bonos');

    perCall($this->payroll, 'POST', "/payroll-periods/{$p}/submit")->assertOk();
    $approved = perCall($approver, 'POST', "/payroll-periods/{$p}/approve")->assertOk();
    $this->assertMatchesOpenApi($approved, 'post', '/payroll-periods/{id}/approve', 200);
    $recalc = perCall($this->payroll, 'POST', "/payroll-periods/{$p}/reopen-calculation", ['reason' => 'Falta un ingreso'])->assertOk();
    $this->assertMatchesOpenApi($recalc, 'post', '/payroll-periods/{id}/reopen-calculation', 200);
    expect($recalc->json('data.status'))->toBe('calculated');
    perCall($this->payroll, 'POST', "/payroll-periods/{$p}/submit")->assertOk();
    perCall($approver, 'POST', "/payroll-periods/{$p}/approve")->assertOk();

    // RN-PER-11: el anterior del mismo grupo debe estar cerrado para cerrar el siguiente.
    $nov = (string) perCall($this->admin, 'POST', '/payroll-periods', ['payroll_group_id' => $group, 'period_year' => 2026, 'period_number' => 11])->json('data.id');
    perStatus($nov, 'approved');
    $prev = perCall($this->payroll, 'POST', "/payroll-periods/{$nov}/close")->assertStatus(409);
    expect($prev->json('error.code'))->toBe('previous_period_open');
    $done = perCall($this->payroll, 'POST', "/payroll-periods/{$p}/close")->assertOk();
    $this->assertMatchesOpenApi($done, 'post', '/payroll-periods/{id}/close', 200);
    expect($done->json('data.status'))->toBe('closed');

    // RN-PER-08 · ADR 0017: con declaración presentada no se reabre.
    TenancyScope::runAs(PER_TENANT, fn () => DB::table('statutory_filings')->insert(['tenant_id' => PER_TENANT, 'legal_entity_id' => $this->entity,
        'kind' => 'plame', 'period_code' => '202610', 'payroll_period_id' => $p, 'status' => 'submitted']));
    $declared = perCall($this->admin, 'POST', "/payroll-periods/{$p}/reopen", ['reason' => 'Corrección'])->assertStatus(409);
    $this->assertMatchesOpenApi($declared, 'post', '/payroll-periods/{id}/reopen', 409);
    expect($declared->json('error.code'))->toBe('period_declared');
    TenancyScope::runAs(PER_TENANT, fn () => DB::table('statutory_filings')->where('payroll_period_id', $p)->update(['status' => 'draft']));
    $reopened = perCall($this->admin, 'POST', "/payroll-periods/{$p}/reopen", ['reason' => 'Corrección'])->assertOk();
    expect($reopened->json('data.status'))->toBe('open')->and($reopened->json('data.reopen_reason'))->toBe('Corrección');

    $actions = TenancyScope::runAs(PER_TENANT, fn () => DB::table('audit_events')->where('entity_type', 'payroll_period')->where('entity_id', $p)->pluck('action')->all());
    expect($actions)->toContain('payroll_period.submit', 'payroll_period.approve', 'payroll_period.reject', 'payroll_period.close', 'payroll_period.reopen');
    expect(TenancyScope::runAs(PER_TENANT, fn () => DB::table('outbox_events')->where('aggregate_id', $p)->pluck('event_type')->all()))
        ->toContain('payroll.submitted.v1', 'payroll.approved.v1', 'payroll.returned.v1', 'payroll.closed.v1', 'payroll.reopened.v1');
});

test('PER-1 · aislamiento, módulo y permisos', function (): void {
    $group = perGroup($this);
    $p = (string) perCall($this->payroll, 'POST', '/payroll-periods', ['payroll_group_id' => $group, 'period_year' => 2026, 'period_number' => 10])->json('data.id');
    $this->assertMatchesOpenApi(perCall($this->other, 'GET', "/payroll-periods/{$p}")->assertNotFound(), 'get', '/payroll-periods/{id}', 404);
    perCall($this->other, 'POST', "/payroll-periods/{$p}/close-incidents")->assertNotFound();
    expect(perCall($this->other, 'GET', '/payroll-periods')->json('meta.total'))->toBe(0);
    perCall($this->employee, 'GET', '/payroll-periods')->assertForbidden();
    perCall($this->payroll, 'POST', '/payroll-periods', ['payroll_group_id' => $group])->assertStatus(422);
    IdentityFixtures::disableModule(PER_TENANT, 'payroll');
    perCall($this->payroll, 'GET', '/payroll-periods')->assertStatus(403)->assertJsonPath('error.code', 'module_not_enabled');
});

test('PER-2 · incidencias con origen y autor, visto bueno sobre el monto del parámetro, carga masiva y bloqueo', function (): void {
    $group = perGroup($this);
    $p = (string) perCall($this->payroll, 'POST', '/payroll-periods', ['payroll_group_id' => $group, 'period_year' => 2026, 'period_number' => 10])->json('data.id');

    $ok = perCall($this->payroll, 'POST', '/payroll-incidents', ['payroll_period_id' => $p, 'employment_id' => $this->a, 'concept_code' => 'BONO', 'amount' => '250', 'notes' => 'Meta de ventas'])->assertCreated();
    $this->assertMatchesOpenApi($ok, 'post', '/payroll-incidents', 201);
    expect($ok->json('data'))->toMatchArray(['source' => 'manual', 'status' => 'approved', 'concept_code' => 'BONO', 'editable' => true])
        ->and($ok->json('data.amount'))->toBe(['amount' => '250.00', 'currency' => 'PEN'])
        ->and($ok->json('data.created_by_name'))->not->toBeNull();

    // RN-PER-10: sobre el monto del parámetro espera visto bueno.
    TenancyScope::runAs(PER_TENANT, fn () => app(ParameterResolver::class)->set('nomina.incidencia_monto_visto_bueno', 1000, 'organization', null, '2020-01-01', 'Prueba'));
    $big = perCall($this->payroll, 'POST', '/payroll-incidents', ['payroll_period_id' => $p, 'employment_id' => $this->a, 'concept_code' => 'BONO', 'amount' => '1500'])->assertCreated();
    expect($big->json('data.status'))->toBe('pending')->and($big->json('data.allowed_actions'))->toBe(['approve', 'reject']);
    perCall($this->payroll, 'POST', '/payroll-incidents/'.$big->json('data.id').'/reject')->assertForbidden(); // Payroll no da visto bueno
    perCall($this->admin, 'POST', '/payroll-incidents/'.$big->json('data.id').'/reject')->assertStatus(422);
    $rejected = perCall($this->admin, 'POST', '/payroll-incidents/'.$big->json('data.id').'/reject', ['reason' => 'Sin sustento'])->assertOk();
    $this->assertMatchesOpenApi($rejected, 'post', '/payroll-incidents/{id}/reject', 200);
    $again = perCall($this->admin, 'POST', '/payroll-incidents/'.$big->json('data.id').'/approve')->assertStatus(409);
    $this->assertMatchesOpenApi($again, 'post', '/payroll-incidents/{id}/approve', 409);

    $upd = perCall($this->payroll, 'PATCH', '/payroll-incidents/'.$ok->json('data.id'), ['amount' => '300'])->assertOk();
    $this->assertMatchesOpenApi($upd, 'patch', '/payroll-incidents/{id}', 200);
    expect($upd->json('data.amount.amount'))->toBe('300.00');

    // Carga masiva: todo o nada.
    $bulkBad = perCall($this->payroll, 'POST', "/payroll-periods/{$p}/incidents/bulk", ['rows' => [
        ['employment_id' => $this->a, 'concept_code' => 'DESC_FALTA', 'quantity' => '1'],
        ['employment_id' => $this->b, 'concept_code' => 'BONO', 'amount' => '10'], // b no está en octubre
        ['employment_id' => $this->a, 'concept_code' => 'NOEXISTE', 'amount' => '10'],
    ]])->assertStatus(422);
    $this->assertMatchesOpenApi($bulkBad, 'post', '/payroll-periods/{id}/incidents/bulk', 422);
    expect($bulkBad->json('error.details'))->toHaveCount(2);
    $bulk = perCall($this->payroll, 'POST', "/payroll-periods/{$p}/incidents/bulk", ['rows' => [
        ['employment_id' => $this->a, 'concept_code' => 'DESC_FALTA', 'quantity' => '1'],
    ]])->assertCreated();
    $this->assertMatchesOpenApi($bulk, 'post', '/payroll-periods/{id}/incidents/bulk', 201);
    expect($bulk->json('data.created'))->toBe(1)->and($bulk->json('data.items.0.source'))->toBe('import');

    $list = perCall($this->payroll, 'GET', "/payroll-incidents?filter[payroll_period_id]={$p}")->assertOk();
    $this->assertMatchesOpenApi($list, 'get', '/payroll-incidents', 200);
    expect($list->json('meta.total'))->toBe(3);
    $one = perCall($this->payroll, 'GET', '/payroll-incidents/'.$ok->json('data.id'))->assertOk();
    $this->assertMatchesOpenApi($one, 'get', '/payroll-incidents/{id}', 200);

    // RN-PER-09: lo que viene de otro módulo no se edita aquí.
    $fromModule = TenancyScope::runAs(PER_TENANT, fn () => (string) DB::table('payroll_incidents')->insertGetId(['tenant_id' => PER_TENANT, 'payroll_period_id' => $p,
        'employment_id' => $this->a, 'payroll_concept_id' => DB::table('payroll_concepts')->where('code', 'DESC_FALTA')->value('id'), 'quantity' => 2, 'source' => 'absence', 'status' => 'approved']));
    $managed = perCall($this->payroll, 'PATCH', "/payroll-incidents/{$fromModule}", ['quantity' => '1'])->assertStatus(409);
    expect($managed->json('error.code'))->toBe('managed_by_source');

    // Al enviar a aprobación quedan bloqueadas; se borran a la papelera mientras el periodo está abierto.
    $del = perCall($this->payroll, 'DELETE', '/payroll-incidents/'.$bulk->json('data.items.0.id'))->assertNoContent();
    $this->assertMatchesOpenApi($del, 'delete', '/payroll-incidents/{id}', 204);
    expect(TenancyScope::runAs(PER_TENANT, fn () => DB::table('payroll_incidents')->where('id', $bulk->json('data.items.0.id'))->whereNotNull('deleted_at')->exists()))->toBeTrue();
    perStatus($p, 'in_review');
    $locked = perCall($this->payroll, 'PATCH', '/payroll-incidents/'.$ok->json('data.id'), ['amount' => '1'])->assertStatus(409);
    expect($locked->json('error.code'))->toBe('period_locked');
    perCall($this->payroll, 'POST', '/payroll-incidents', ['payroll_period_id' => $p, 'employment_id' => $this->a, 'concept_code' => 'BONO', 'amount' => '5'])->assertStatus(409);

    perCall($this->employee, 'GET', '/payroll-incidents')->assertForbidden();
    perCall($this->other, 'GET', '/payroll-incidents/'.$ok->json('data.id'))->assertNotFound();
    expect(TenancyScope::runAs(PER_TENANT, fn () => DB::table('audit_events')->where('entity_type', 'payroll_incident')->pluck('action')->all()))
        ->toContain('payroll_incident.created', 'payroll_incident.updated', 'payroll_incident.rejected', 'payroll_incident.deleted');
});

test('PER-3 · toma ausencias y vacaciones hasta el corte, lo registra y trae lo posterior como diferencia', function (): void {
    $absences = new class implements AbsenceIncidents
    {
        /** @var array<string, int> */
        public array $days = ['FALTA' => 2];

        public function summary(string $employmentId, string $from, string $to): array
        {
            $types = [];
            foreach ($this->days as $code => $days) {
                $types[] = ['type_code' => $code, 'type_name' => $code, 'category' => 'unpaid', 'is_confidential' => false, 'unit' => 'days',
                    'payroll_concept_code' => 'DESC_FALTA', 'statutory_suspension_code' => null, 'counts_as_worked_for_benefits' => false,
                    'days' => $days, 'hours' => '0', 'paid_days' => 0, 'unpaid_days' => $days, 'employer_paid_days' => 0, 'subsidized_days' => 0, 'absence_ids' => []];
            }

            return ['employment_id' => $employmentId, 'from' => $from, 'to' => $to, 'source' => 'calendar', 'days' => [], 'types' => $types,
                'totals' => ['absence_days' => array_sum($this->days)], 'imported_incidents' => [], 'fingerprint' => hash('sha256', $from.$to.json_encode($this->days))];
        }

        public function differences(string $employmentId, string $from, string $to): ?array
        {
            return null;
        }
    };
    $vacations = new class implements VacationPayrollIncidents
    {
        public function sales(string $employmentId, string $from, string $to): array
        {
            return [['agreement_id' => '0195f000-0000-7000-8000-00000000aaaa', 'days' => '5', 'effective_on' => $from, 'concept_code' => 'VAC_COMPRA']];
        }

        public function taken(string $employmentId, string $from, string $to): array
        {
            return [];
        }

        public function expired(string $employmentId, string $asOf): array
        {
            return [];
        }
    };
    app()->instance(AbsenceIncidents::class, $absences);
    app()->instance(VacationPayrollIncidents::class, $vacations);

    $group = perGroup($this);
    $oct = (string) perCall($this->payroll, 'POST', '/payroll-periods', ['payroll_group_id' => $group, 'period_year' => 2026, 'period_number' => 10])->json('data.id');
    $first = perCall($this->payroll, 'POST', "/payroll-periods/{$oct}/consume-time")->assertOk();
    $this->assertMatchesOpenApi($first, 'post', '/payroll-periods/{id}/consume-time', 200);
    expect($first->json('data'))->toMatchArray(['employments' => 1, 'created' => 2, 'cutoffs' => 1, 'from' => '2026-10-01', 'to' => '2026-10-25'])
        ->and($first->json('data.out_of_cutoff'))->toBe(['from' => '2026-10-26', 'to' => '2026-10-31']);
    $sources = TenancyScope::runAs(PER_TENANT, fn () => DB::table('payroll_incidents')->where('payroll_period_id', $oct)->whereNull('deleted_at')->orderBy('source')->pluck('quantity', 'source')->all());
    expect(array_map('floatval', $sources))->toBe(['absence' => 2.0, 'benefit' => 5.0]);

    // Repetir sin cambios no duplica ni agrega otro corte.
    expect(perCall($this->payroll, 'POST', "/payroll-periods/{$oct}/consume-time")->json('data'))->toMatchArray(['created' => 0, 'updated' => 0, 'cutoffs' => 0]);

    // Una falta registrada después del corte (en días ya tomados) aparece como cambio y entra al periodo siguiente.
    $absences->days = ['FALTA' => 3];
    $summary = perCall($this->payroll, 'GET', "/payroll-periods/{$oct}/time-summary")->assertOk();
    $this->assertMatchesOpenApi($summary, 'get', '/payroll-periods/{id}/time-summary', 200);
    expect($summary->json('data.0.changed_since_cutoff'))->toBeTrue()->and($summary->json('data.0.out_of_cutoff.from'))->toBe('2026-10-26');

    perStatus($oct, 'closed');
    $nov = (string) perCall($this->payroll, 'POST', '/payroll-periods', ['payroll_group_id' => $group, 'period_year' => 2026, 'period_number' => 11])->json('data.id');
    $second = perCall($this->payroll, 'POST', "/payroll-periods/{$nov}/consume-time")->assertOk();
    expect($second->json('data.differences'))->toBe(1);
    $retro = TenancyScope::runAs(PER_TENANT, fn () => DB::table('payroll_incidents')->where('payroll_period_id', $nov)->where('source', 'retroactive')->first());
    expect($retro->retro_period_id)->toBe($oct)->and((float) $retro->quantity)->toBe(1.0);
    $editRetro = perCall($this->payroll, 'PATCH', "/payroll-incidents/{$retro->id}", ['quantity' => '2'])->assertStatus(409);
    expect($editRetro->json('error.code'))->toBe('managed_by_source');

    // El corte es de solo inserción.
    expect(fn () => TenancyScope::runAs(PER_TENANT, fn () => DB::transaction(fn () => DB::table('payroll_period_time_cutoffs')->where('payroll_period_id', $oct)->update(['fingerprint' => 'x']))))
        ->toThrow(Exception::class);
    perCall($this->employee, 'POST', "/payroll-periods/{$nov}/consume-time")->assertForbidden();
    perCall($this->other, 'POST', "/payroll-periods/{$nov}/consume-time")->assertNotFound();
    // Cerrado, no se vuelve a tomar.
    perCall($this->payroll, 'POST', "/payroll-periods/{$oct}/consume-time")->assertStatus(409);
});
