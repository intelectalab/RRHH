<?php

/**
 * VAC-1 · Política de vacaciones por régimen (reglas con vigencia, límites legales leídos de los
 * parámetros legales, «por validar» como advertencia) y VAC-2 · Devengo y saldos (periodos por año
 * vacacional, libro inmutable, saldo = suma del libro, vencidos, reservas, ajustes y liquidación).
 */

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Organization\Infrastructure\Models\LegalEntity;
use App\Modules\Vacations\Application\VacationAccrual;
use App\Shared\LegalParameters\LegalParameters;
use App\Shared\Outbox\DomainEventTypes;
use App\Shared\Permissions\ModuleGate;
use App\Shared\Permissions\PermissionResolver;
use App\Shared\Tenancy\TenancyScope;
use App\Shared\Time\InsufficientVacationBalance;
use App\Shared\Time\VacationBalances;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\IdentityFixtures;

uses(DatabaseTransactions::class);

const VAC_TENANT = 'aaaaaaaa-0ac1-4000-8000-00000000000a';
const VAC_OTHER = 'bbbbbbbb-0ac1-4000-8000-00000000000b';

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requiere PostgreSQL.');
    }
    Carbon::setTestNow('2026-11-13 12:00:00');
    TenancyScope::clear();
    foreach ([VAC_TENANT => 'vac-org', VAC_OTHER => 'vac-otra'] as $id => $slug) {
        DB::table('tenants')->insertOrIgnore(['id' => $id, 'slug' => $slug, 'name' => 'Grupo '.$slug, 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN']);
        DB::select('SELECT seed_tenant_defaults(?)', [$id]);
        TenancyScope::runAs($id, fn () => IdentityFixtures::enableAllModules());
    }
    app(LegalParameters::class)->sync('PE');
    $this->admin = vacUser(VAC_TENANT, 'admin_empresa');
    $this->entity = TenancyScope::runAs(VAC_TENANT, fn (): string => (string) LegalEntity::factory()->create()->id);
});

afterEach(function (): void {
    TenancyScope::clear();
    Carbon::setTestNow();
});

function vacUser(string $tenant, string $role, ?string $personId = null): User
{
    return TenancyScope::runAs($tenant, function () use ($role, $personId): User {
        $u = User::factory()->create($personId === null ? [] : ['person_id' => $personId]);
        $u->roleAssignments()->create(['role_id' => Role::query()->where('system_role_code', $role)->value('id')]);

        return $u;
    });
}

function vacCall(User $user, string $method, string $path, array $body = []): TestResponse
{
    TenancyScope::clear();
    app('auth')->forgetGuards();
    app(PermissionResolver::class)->forget();
    app(ModuleGate::class)->forget();

    return test()->actingAs($user, 'ayni')->withHeaders(['X-Tenant-Id' => $user->tenant_id, 'Idempotency-Key' => (string) Str::uuid()])->json($method, '/api/v1'.$path, $body);
}

/**
 * Relación laboral con su régimen desde el ingreso; devuelve [employment_id, person_id].
 *
 * @param  array<string, mixed>  $extra
 * @return array{0: string, 1: string}
 */
function vacHire(string $entity, string $regime, string $hire, array $extra = [], ?string $personId = null, string $tenant = VAC_TENANT): array
{
    return TenancyScope::runAs($tenant, function () use ($entity, $regime, $hire, $extra, $personId, $tenant): array {
        $personId ??= (string) DB::table('people')->insertGetId(['tenant_id' => $tenant, 'first_names' => 'Rosa '.Str::random(4), 'paternal_surname' => 'Quispe']);
        $id = (string) DB::table('employments')->insertGetId($extra + [
            'tenant_id' => $tenant, 'person_id' => $personId, 'legal_entity_id' => $entity, 'employee_code' => 'V'.random_int(10000, 99999),
            'hire_date' => $hire, 'status' => 'active',
        ]);
        DB::table('employment_labor_regimes')->insert(['tenant_id' => $tenant, 'employment_id' => $id, 'labor_regime_code' => $regime, 'valid_from' => $extra['seniority_date'] ?? $hire]);

        return [$id, $personId];
    });
}

function vacBalance(string $employmentId, string $tenant = VAC_TENANT): array
{
    return TenancyScope::runAs($tenant, fn (): array => app(VacationBalances::class)->balance($employmentId));
}

function vacRequest(string $employmentId, string $days): string
{
    return TenancyScope::runAs(VAC_TENANT, fn (): string => (string) DB::table('vacation_requests')->insertGetId([
        'tenant_id' => VAC_TENANT, 'employment_id' => $employmentId, 'starts_on' => '2026-12-01', 'ends_on' => '2026-12-10', 'days' => $days, 'status' => 'draft',
    ]));
}

/** Para la prueba: fija un valor verificado del régimen general (en el catálogo real sigue «por validar»). */
function vacGeneralDays(string $days): void
{
    DB::table('legal_parameters')->where('code', 'vacaciones_dias')->where('labor_regime_code', 'pe_general')
        ->update(['value_numeric' => $days, 'verified' => true, 'valid_from' => '2020-01-01']);
}

test('VAC-1 · la política hereda los días del régimen, rechaza reglas por debajo de la ley verificada y advierte lo «por validar»', function (): void {
    $limits = vacCall($this->admin, 'GET', '/vacation-legal-limits?labor_regime_code=pe_micro');
    $limits->assertOk();
    $this->assertMatchesOpenApi($limits, 'get', '/vacation-legal-limits', 200);
    expect($limits->json('data.days_per_year.parameter_code'))->toBe('vacaciones_dias')
        ->and($limits->json('data.days_per_year.pending_validation'))->toBeFalse()
        ->and($limits->json('data.max_sale_days.pending_validation'))->toBeTrue();
    $micro = (string) $limits->json('data.days_per_year.value');

    // Bajo el mínimo legal verificado: 422 con el campo señalado.
    $low = vacCall($this->admin, 'POST', '/vacation-policies', ['code' => 'micro_baja', 'name' => 'Micro baja', 'labor_regime_code' => 'pe_micro',
        'rules' => ['days_per_year' => (string) ((int) $micro - 1)]]);
    $low->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    $this->assertMatchesOpenApi($low, 'post', '/vacation-policies', 422);

    // Sin días propios: hereda los del régimen. El tope de venta, con su parámetro «por validar», advierte.
    $created = vacCall($this->admin, 'POST', '/vacation-policies', ['code' => 'micro', 'name' => 'Microempresa', 'labor_regime_code' => 'pe_micro',
        'effective_from' => '2020-01-01', 'rules' => ['allow_sale' => true, 'max_sale_days' => '5']]);
    $created->assertCreated();
    $this->assertMatchesOpenApi($created, 'post', '/vacation-policies', 201);
    expect($created->json('data.status'))->toBe('active')
        ->and(collect($created->json('meta.warnings'))->pluck('code')->all())->toContain('legal_pending_validation');

    // Activar el tope sin la opción: error.
    $id = $created->json('data.id');
    vacCall($this->admin, 'PATCH', '/vacation-policies/'.$id, ['rules' => ['allow_advance' => false, 'max_advance_days' => '3']])->assertStatus(422);

    // Duplicado de código: 409.
    vacCall($this->admin, 'POST', '/vacation-policies', ['code' => 'micro', 'name' => 'Otra'])->assertStatus(409);

    $list = vacCall($this->admin, 'GET', '/vacation-policies?filter[labor_regime_code]=pe_micro');
    $list->assertOk();
    $this->assertMatchesOpenApi($list, 'get', '/vacation-policies', 200);
    expect(collect($list->json('data'))->pluck('code')->all())->toBe(['micro']);
    vacCall($this->admin, 'GET', '/vacation-policies?filter[nope]=1')->assertStatus(400)->assertJsonPath('error.code', 'invalid_filter');

    $audit = TenancyScope::runAs(VAC_TENANT, fn () => DB::table('audit_events')->where('entity_id', $id)->count());
    expect($audit)->toBeGreaterThan(0);
});

test('VAC-1 · las reglas cambian con vigencia: una regla futura no altera el pasado y la historia se conserva', function (): void {
    $created = vacCall($this->admin, 'POST', '/vacation-policies', ['code' => 'micro_plus', 'name' => 'Micro con días extra', 'labor_regime_code' => 'pe_micro',
        'legal_entity_id' => $this->entity, 'effective_from' => '2020-01-01', 'rules' => ['days_per_year' => '20']])->assertCreated();
    $id = $created->json('data.id');
    [$emp] = vacHire($this->entity, 'pe_micro', '2025-01-01');
    $before = vacBalance($emp);
    expect($before['periods'][0]['entitled_days'])->toBe('20.00')
        ->and($before['policy']['policy_code'])->toBe('micro_plus');

    $patch = vacCall($this->admin, 'PATCH', '/vacation-policies/'.$id, ['effective_from' => '2027-01-01', 'rules' => ['days_per_year' => '25']]);
    $patch->assertOk();
    $this->assertMatchesOpenApi($patch, 'patch', '/vacation-policies/{id}', 200);

    $rules = vacCall($this->admin, 'GET', '/vacation-policies/'.$id.'/rules');
    $rules->assertOk();
    $this->assertMatchesOpenApi($rules, 'get', '/vacation-policies/{id}/rules', 200);
    expect($rules->json('data'))->toHaveCount(2)
        ->and($rules->json('data.0.valid_from'))->toBe('2027-01-01')
        ->and($rules->json('data.1.valid_to'))->toBe('2026-12-31');

    $after = vacBalance($emp);
    expect($after['periods'][0]['entitled_days'])->toBe('20.00')
        ->and($after['totals']['available'])->toBe($before['totals']['available']);

    $show = vacCall($this->admin, 'GET', '/vacation-policies/'.$id.'?as_of=2027-02-01');
    $show->assertOk();
    $this->assertMatchesOpenApi($show, 'get', '/vacation-policies/{id}', 200);
    expect((float) $show->json('data.rules.days_per_year'))->toBe(25.0);
});

test('VAC-1 · archivar y restaurar son acciones; archivar dos veces es 409 y una archivada no se edita', function (): void {
    $id = vacCall($this->admin, 'POST', '/vacation-policies', ['code' => 'arch', 'name' => 'Para archivar'])->assertCreated()->json('data.id');
    $a = vacCall($this->admin, 'POST', '/vacation-policies/'.$id.'/archive', ['reason' => 'Ya no aplica']);
    $a->assertOk()->assertJsonPath('data.status', 'archived');
    $this->assertMatchesOpenApi($a, 'post', '/vacation-policies/{id}/archive', 200);
    $twice = vacCall($this->admin, 'POST', '/vacation-policies/'.$id.'/archive');
    $twice->assertStatus(409)->assertJsonPath('error.code', 'invalid_transition');
    $this->assertMatchesOpenApi($twice, 'post', '/vacation-policies/{id}/archive', 409);
    vacCall($this->admin, 'PATCH', '/vacation-policies/'.$id, ['name' => 'X'])->assertStatus(422);
    $r = vacCall($this->admin, 'POST', '/vacation-policies/'.$id.'/restore');
    $r->assertOk()->assertJsonPath('data.status', 'active');
    $this->assertMatchesOpenApi($r, 'post', '/vacation-policies/{id}/restore', 200);
    vacCall($this->admin, 'PATCH', '/vacation-policies/'.$id, ['status' => 'archived'])->assertOk()->assertJsonPath('data.status', 'active');
});

test('VAC-2 · micro y general devengan días distintos; el periodo cumplido entra al libro y el en curso devenga proporcional', function (): void {
    vacGeneralDays('30');
    [$micro] = vacHire($this->entity, 'pe_micro', '2025-01-01');
    [$general] = vacHire($this->entity, 'pe_general', '2025-01-01');

    $m = vacBalance($micro);
    $g = vacBalance($general);
    expect($m['periods'])->toHaveCount(2)
        ->and($m['periods'][0]['status'])->toBe('earned')
        ->and($m['periods'][0]['entitled_days'])->toBe('15.00')
        ->and($m['periods'][0]['enjoy_by'])->toBe('2026-12-31')
        ->and($m['totals']['generated'])->toBe('15.00')
        ->and($m['totals']['available'])->toBe('15.00')
        ->and($m['accruing']['entitled_days'])->toBe('15.00')
        ->and((float) $m['accruing']['accrued_days'])->toBeGreaterThan(0)->toBeLessThan(15)
        ->and($m['pending_validation'])->toBeFalse()
        ->and($g['totals']['available'])->toBe('30.00')
        ->and($m['policy']['source'])->toBe('legal');

    // RN-VAC-02: el saldo es la suma del libro.
    $sum = TenancyScope::runAs(VAC_TENANT, fn () => (string) DB::table('vacation_ledger_entries')->where('employment_id', $micro)->sum('days'));
    expect(number_format((float) $sum, 2, '.', ''))->toBe($m['totals']['available']);

    // Idempotente: sincronizar otra vez no duplica el devengo.
    vacBalance($micro);
    expect(TenancyScope::runAs(VAC_TENANT, fn () => DB::table('vacation_ledger_entries')->where('employment_id', $micro)->where('entry_type', 'accrual')->count()))->toBe(1)
        ->and(TenancyScope::runAs(VAC_TENANT, fn () => DB::table('outbox_events')->where('event_type', DomainEventTypes::LEAVE_PERIOD_EARNED)->where('aggregate_id', $micro)->count()))->toBe(1);

    $show = vacCall($this->admin, 'GET', '/vacation-balances/'.$micro);
    $show->assertOk()->assertJsonPath('data.totals.available', '15.00');
    $this->assertMatchesOpenApi($show, 'get', '/vacation-balances/{id}', 200);

    $ledger = vacCall($this->admin, 'GET', '/vacation-balances/'.$micro.'/ledger');
    $ledger->assertOk();
    $this->assertMatchesOpenApi($ledger, 'get', '/vacation-balances/{id}/ledger', 200);
    expect($ledger->json('data.0.entry_type'))->toBe('accrual')->and($ledger->json('data.0.column'))->toBe('generated');

    $list = vacCall($this->admin, 'GET', '/vacation-balances?q=Quispe');
    $list->assertOk();
    $this->assertMatchesOpenApi($list, 'get', '/vacation-balances', 200);
    expect(collect($list->json('data'))->pluck('employment_id')->all())->toContain($micro, $general);
    vacCall($this->admin, 'GET', '/vacation-balances?filter[status]=nope')->assertStatus(400)->assertJsonPath('error.code', 'invalid_filter');
});

test('VAC-2 · el régimen general sin días verificados queda «por validar» y no inventa días', function (): void {
    [$emp] = vacHire($this->entity, 'pe_general', '2025-01-01');
    $b = vacBalance($emp);
    expect($b['pending_validation'])->toBeTrue()
        ->and($b['periods'][0]['pending_validation'])->toBeTrue()
        ->and($b['totals']['available'])->toBe('0.00');
});

test('VAC-2 · los periodos no gozados en plazo quedan vencidos y se identifican', function (): void {
    [$emp] = vacHire($this->entity, 'pe_micro', '2025-01-01');
    Carbon::setTestNow('2027-02-15 12:00:00');
    $b = vacBalance($emp);
    expect($b['periods'][0]['status'])->toBe('overdue')
        ->and($b['overdue_days'])->toBe('15.00')
        ->and($b['periods'][1]['status'])->toBe('earned')
        ->and($b['totals']['available'])->toBe('30.00')
        ->and(TenancyScope::runAs(VAC_TENANT, fn () => DB::table('outbox_events')->where('event_type', DomainEventTypes::LEAVE_PERIOD_EXPIRING)->where('aggregate_id', $emp)->count()))->toBe(1);

    $list = vacCall($this->admin, 'GET', '/vacation-balances?filter[overdue]=true');
    $list->assertOk();
    expect(collect($list->json('data'))->firstWhere('employment_id', $emp)['overdue_periods'])->toBe(1);
});

test('VAC-2 · el saldo es por relación: el reingreso empieza su récord salvo antigüedad reconocida', function (): void {
    [$old, $person] = vacHire($this->entity, 'pe_micro', '2024-01-01', ['status' => 'terminated', 'termination_date' => '2025-06-30']);
    [$new] = vacHire($this->entity, 'pe_micro', '2025-08-01', ['rehire_of_employment_id' => $old], $person);
    [$kept] = vacHire($this->entity, 'pe_micro', '2026-01-10', ['seniority_date' => '2025-01-01'], $person);

    expect(vacBalance($new)['periods'][0]['period_start'])->toBe('2025-08-01')
        ->and(vacBalance($new)['totals']['available'])->toBe('15.00')
        ->and(vacBalance($kept)['periods'][0]['period_start'])->toBe('2025-01-01');

    // La relación anterior no mezcla sus movimientos con la nueva.
    $ids = TenancyScope::runAs(VAC_TENANT, fn () => DB::table('vacation_ledger_entries')->where('employment_id', $new)->pluck('employment_id')->unique()->all());
    expect($ids)->toBe([$new]);
});

test('VAC-2 · reservar, liberar y consumir por el contrato compartido, idempotente y FIFO; sin saldo, error', function (): void {
    [$emp] = vacHire($this->entity, 'pe_micro', '2025-01-01');
    Carbon::setTestNow('2027-02-15 12:00:00');
    $svc = fn () => app(VacationBalances::class);
    expect(vacBalance($emp)['totals']['available'])->toBe('30.00');

    $req = vacRequest($emp, '20');
    $ids = TenancyScope::runAs(VAC_TENANT, fn () => $svc()->reserve($emp, $req, '20', '2026-12-01', null));
    $again = TenancyScope::runAs(VAC_TENANT, fn () => $svc()->reserve($emp, $req, '20', '2026-12-01', null));
    expect($ids)->toHaveCount(2)->and($again)->toBe($ids);

    $b = vacBalance($emp);
    expect($b['totals']['scheduled'])->toBe('20.00')->and($b['totals']['available'])->toBe('10.00');

    $big = vacRequest($emp, '40');
    expect(fn () => TenancyScope::runAs(VAC_TENANT, fn () => $svc()->reserve($emp, $big, '40', '2026-12-01', null)))->toThrow(InsufficientVacationBalance::class);

    TenancyScope::runAs(VAC_TENANT, fn () => $svc()->release($req, 'Solicitud cancelada', null));
    TenancyScope::runAs(VAC_TENANT, fn () => $svc()->release($req, 'Solicitud cancelada', null));
    expect(vacBalance($emp)['totals']['available'])->toBe('30.00');

    $req2 = vacRequest($emp, '5');
    TenancyScope::runAs(VAC_TENANT, fn () => $svc()->reserve($emp, $req2, '5', '2026-12-01', null));
    TenancyScope::runAs(VAC_TENANT, fn () => $svc()->consume($req2, '2026-12-01', null));
    TenancyScope::runAs(VAC_TENANT, fn () => $svc()->consume($req2, '2026-12-01', null));
    $c = vacBalance($emp);
    expect($c['totals']['taken'])->toBe('5.00')->and($c['totals']['scheduled'])->toBe('0.00')->and($c['totals']['available'])->toBe('25.00');
});

test('VAC-2 · el adelanto llega hasta lo devengado del año en curso con el tope de la política', function (): void {
    vacCall($this->admin, 'POST', '/vacation-policies', ['code' => 'adelanto', 'name' => 'Con adelanto', 'labor_regime_code' => 'pe_micro',
        'effective_from' => '2020-01-01', 'rules' => ['allow_advance' => true, 'max_advance_days' => '4']])->assertCreated();
    [$emp] = vacHire($this->entity, 'pe_micro', '2026-01-01');
    $b = vacBalance($emp);
    expect($b['totals']['available'])->toBe('0.00')->and($b['advance_available'])->toBe('4.00');

    $req = vacRequest($emp, '3');
    TenancyScope::runAs(VAC_TENANT, fn () => app(VacationBalances::class)->reserve($emp, $req, '3', '2026-12-01', null, $b['advance_available']));
    expect(vacBalance($emp)['totals']['scheduled'])->toBe('3.00');
});

test('VAC-2 · el ajuste exige motivo y sustento, se audita y emite leave.balance_adjusted; el colaborador solo ve lo propio', function (): void {
    [$emp, $person] = vacHire($this->entity, 'pe_micro', '2025-01-01');
    [$other] = vacHire($this->entity, 'pe_micro', '2025-01-01');

    $bad = vacCall($this->admin, 'POST', '/vacation-balances/'.$emp.'/adjust', ['days' => '2', 'reason' => 'Corrección']);
    $bad->assertStatus(422);
    $this->assertMatchesOpenApi($bad, 'post', '/vacation-balances/{id}/adjust', 422);

    $ok = vacCall($this->admin, 'POST', '/vacation-balances/'.$emp.'/adjust', ['days' => '2', 'reason' => 'Saldo inicial del sistema anterior', 'evidence' => 'Acta 12-2026']);
    $ok->assertOk()->assertJsonPath('data.totals.adjusted', '2.00')->assertJsonPath('data.totals.available', '17.00');
    $this->assertMatchesOpenApi($ok, 'post', '/vacation-balances/{id}/adjust', 200);
    TenancyScope::runAs(VAC_TENANT, function () use ($emp): void {
        expect(DB::table('audit_events')->where('action', 'vacation_balance.adjusted')->where('entity_id', $emp)->count())->toBe(1)
            ->and(DB::table('outbox_events')->where('event_type', DomainEventTypes::LEAVE_BALANCE_ADJUSTED)->where('aggregate_id', $emp)->count())->toBe(1);
    });

    $employee = vacUser(VAC_TENANT, 'empleado', $person);
    vacCall($employee, 'GET', '/vacation-balances/'.$emp)->assertOk();
    vacCall($employee, 'GET', '/vacation-balances/'.$other)->assertNotFound();
    expect(collect(vacCall($employee, 'GET', '/vacation-balances')->json('data'))->pluck('employment_id')->all())->toBe([$emp]);
    $denied = vacCall($employee, 'POST', '/vacation-balances/'.$emp.'/adjust', ['days' => '1', 'reason' => 'Intento propio', 'evidence' => 'Ninguna']);
    $denied->assertForbidden();
    $this->assertMatchesOpenApi($denied, 'post', '/vacation-balances/{id}/adjust', 403);
    vacCall($employee, 'POST', '/vacation-policies', ['code' => 'x', 'name' => 'X'])->assertForbidden();

    IdentityFixtures::disableModule(VAC_TENANT, 'time_off');
    $off = vacCall($this->admin, 'GET', '/vacation-balances');
    $off->assertStatus(403)->assertJsonPath('error.code', 'module_not_enabled');
});

test('VAC-2 · al cesar, los días pendientes pasan a la liquidación y los periodos se cierran', function (): void {
    [$emp] = vacHire($this->entity, 'pe_micro', '2025-01-01', ['status' => 'terminated', 'termination_date' => '2026-12-31']);
    Carbon::setTestNow('2027-02-15 12:00:00');
    expect(vacBalance($emp)['totals']['available'])->toBe('30.00');
    TenancyScope::runAs(VAC_TENANT, fn () => app(VacationAccrual::class)->settle($emp, '2026-12-31', null));
    TenancyScope::runAs(VAC_TENANT, fn () => app(VacationAccrual::class)->settle($emp, '2026-12-31', null));
    $b = vacBalance($emp);
    expect($b['totals']['settled'])->toBe('30.00')
        ->and($b['totals']['available'])->toBe('0.00')
        ->and(collect($b['periods'])->pluck('status')->unique()->values()->all())->toBe(['closed']);
});

test('VAC-2 · el libro es de solo inserción y otra organización no ve los saldos', function (): void {
    [$emp] = vacHire($this->entity, 'pe_micro', '2025-01-01');
    vacBalance($emp);
    expect(fn () => TenancyScope::runAs(VAC_TENANT, fn () => DB::transaction(fn () => DB::table('vacation_ledger_entries')->where('employment_id', $emp)->update(['days' => 99]))))
        ->toThrow(QueryException::class);

    $outsider = IdentityFixtures::userWithPermissions(VAC_OTHER);
    vacCall($outsider, 'GET', '/vacation-balances/'.$emp)->assertNotFound();
    vacCall($outsider, 'GET', '/vacation-balances/'.$emp.'/ledger')->assertNotFound();
    expect(collect(vacCall($outsider, 'GET', '/vacation-balances')->json('data'))->pluck('employment_id')->all())->not->toContain($emp);
    $policy = vacCall($this->admin, 'POST', '/vacation-policies', ['code' => 'solo_a', 'name' => 'Solo A'])->json('data.id');
    vacCall($outsider, 'GET', '/vacation-policies/'.$policy)->assertNotFound();
});
