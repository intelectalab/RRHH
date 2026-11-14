<?php

/**
 * VAC-3 · Solicitud y aprobación de vacaciones (ME-15): borrador → enviada (flujo vacation_request) →
 * aprobada por el jefe (reserva en el libro) → en goce (consume) → gozada; RRHH la registra directamente;
 * saldo, anticipación y solapes validados por el servidor; cancelar devuelve los días.
 * VAC-4 · Calendario de ausencias del equipo: jefe con su alcance, compañeros sin detalle y alerta de
 * cobertura por unidad.
 *
 * Hoy (congelado): viernes 13 de noviembre de 2026. Régimen de microempresa: 15 días ganados el 1 de enero.
 */

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Organization\Infrastructure\Models\LegalEntity;
use App\Shared\LegalParameters\LegalParameters;
use App\Shared\Permissions\ModuleGate;
use App\Shared\Permissions\PermissionResolver;
use App\Shared\Tenancy\TenancyScope;
use App\Shared\Time\VacationBalances;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\IdentityFixtures;

uses(DatabaseTransactions::class);

const VACR_TENANT = 'aaaaaaaa-0ac3-4000-8000-00000000000a';
const VACR_OTHER = 'bbbbbbbb-0ac3-4000-8000-00000000000b';

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requiere PostgreSQL.');
    }
    Carbon::setTestNow('2026-11-13 12:00:00');
    TenancyScope::clear();
    foreach ([VACR_TENANT => 'vacr-org', VACR_OTHER => 'vacr-otra'] as $id => $slug) {
        DB::table('tenants')->insertOrIgnore(['id' => $id, 'slug' => $slug, 'name' => 'Grupo '.$slug, 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN']);
        DB::select('SELECT seed_tenant_defaults(?)', [$id]);
        TenancyScope::runAs($id, fn () => IdentityFixtures::enableAllModules());
    }
    app(LegalParameters::class)->sync('PE');

    $this->admin = vacrUser(VACR_TENANT, 'admin_empresa');
    $this->hr = vacrUser(VACR_TENANT, 'rrhh');

    $org = TenancyScope::runAs(VACR_TENANT, function (): array {
        $entity = (string) LegalEntity::factory()->create(['timezone' => 'America/Lima'])->id;
        $calendar = (string) Str::uuid();
        DB::table('holiday_calendars')->insert(['id' => $calendar, 'tenant_id' => VACR_TENANT, 'code' => 'LIM', 'name' => 'Feriados Lima', 'country_code' => 'PE']);
        DB::table('holidays')->insert(['tenant_id' => VACR_TENANT, 'holiday_calendar_id' => $calendar, 'holiday_date' => '2026-12-08', 'name' => 'Feriado de prueba']);
        $site = (string) Str::uuid();
        DB::table('sites')->insert(['id' => $site, 'tenant_id' => VACR_TENANT, 'legal_entity_id' => $entity, 'code' => 'LIM', 'name' => 'Lima', 'timezone' => 'America/Lima', 'holiday_calendar_id' => $calendar]);
        $unit = (string) Str::uuid();
        DB::table('org_units')->insert(['id' => $unit, 'tenant_id' => VACR_TENANT, 'code' => 'OPE', 'name' => 'Operaciones']);
        $profile = (string) Str::uuid();
        DB::table('job_profiles')->insert(['id' => $profile, 'tenant_id' => VACR_TENANT, 'code' => 'ANA', 'name' => 'Analista']);

        $mk = function (string $name, ?string $manager) use ($entity, $site, $unit, $profile): array {
            $person = (string) Str::uuid();
            $employment = (string) Str::uuid();
            DB::table('people')->insert(['id' => $person, 'tenant_id' => VACR_TENANT, 'first_names' => $name, 'paternal_surname' => 'Prueba']);
            DB::table('employments')->insert(['id' => $employment, 'tenant_id' => VACR_TENANT, 'person_id' => $person, 'legal_entity_id' => $entity, 'employee_code' => strtoupper($name), 'hire_date' => '2025-01-01', 'status' => 'active']);
            DB::table('employment_labor_regimes')->insert(['tenant_id' => VACR_TENANT, 'employment_id' => $employment, 'labor_regime_code' => 'pe_micro', 'valid_from' => '2025-01-01']);
            DB::table('employment_assignments')->insert([
                'tenant_id' => VACR_TENANT, 'employment_id' => $employment, 'job_profile_id' => $profile, 'org_unit_id' => $unit,
                'site_id' => $site, 'manager_employment_id' => $manager, 'valid_from' => '2025-01-01',
            ]);

            return [$person, $employment];
        };
        [$bossPerson, $bossEmployment] = $mk('Marta', null);
        [$person, $employment] = $mk('Juan', $bossEmployment);
        [$peerPerson, $peerEmployment] = $mk('Lucia', $bossEmployment);

        return compact('entity', 'unit', 'bossPerson', 'bossEmployment', 'person', 'employment', 'peerPerson', 'peerEmployment');
    });
    $this->org = $org;
    $this->boss = vacrUser(VACR_TENANT, 'manager', $org['bossPerson']);
    $this->employee = vacrUser(VACR_TENANT, 'empleado', $org['person']);
    $this->peer = vacrUser(VACR_TENANT, 'empleado', $org['peerPerson']);
    $this->emp = $org['employment'];

    // Flujo publicado: aprueba el jefe directo.
    $flow = vacrCall($this->admin, 'POST', '/approval-flows', ['name' => 'Vacaciones', 'subject_type' => 'vacation_request',
        'steps' => ['levels' => [['mode' => 'sequential', 'approvers' => [['kind' => 'manager']]]]]]);
    $flow->assertCreated();
    vacrCall($this->admin, 'POST', '/approval-flows/'.$flow->json('data.id').'/publish')->assertOk();
});

afterEach(function (): void {
    TenancyScope::clear();
    Carbon::setTestNow();
});

function vacrUser(string $tenant, string $role, ?string $personId = null): User
{
    return TenancyScope::runAs($tenant, function () use ($role, $personId): User {
        $u = User::factory()->create(['person_id' => $personId]);
        $u->roleAssignments()->create(['role_id' => Role::query()->where('system_role_code', $role)->value('id')]);

        return $u;
    });
}

/**
 * @param  array<string, mixed>  $body
 */
function vacrCall(User $user, string $method, string $path, array $body = []): TestResponse
{
    TenancyScope::clear();
    app('auth')->forgetGuards();
    app(PermissionResolver::class)->forget();
    app(ModuleGate::class)->forget();

    return test()->actingAs($user, 'ayni')->withHeaders(['X-Tenant-Id' => $user->tenant_id, 'Idempotency-Key' => (string) Str::uuid()])->json($method, '/api/v1'.$path, $body);
}

/**
 * @param  array<string, mixed>  $extra
 */
function vacrDraft(User $user, string $from, string $to, array $extra = [], ?string $employment = null): TestResponse
{
    return vacrCall($user, 'POST', '/vacation-requests', $extra + ['employment_id' => $employment ?? test()->emp, 'starts_on' => $from, 'ends_on' => $to]);
}

function vacrAvailable(string $employment): string
{
    return (string) TenancyScope::runAs(VACR_TENANT, fn (): string => (string) app(VacationBalances::class)->balance($employment)['totals']['available']);
}

function vacrSetting(string $code, mixed $value): void
{
    TenancyScope::runAs(VACR_TENANT, fn () => DB::table('tenant_settings')->insert([
        'tenant_id' => VACR_TENANT, 'parameter_code' => $code, 'scope_kind' => 'organization', 'value' => json_encode($value), 'valid_from' => '2020-01-01',
    ]));
}

test('VAC-3 · el colaborador solicita, el jefe aprueba en el centro de aprobaciones y los días quedan reservados', function (): void {
    expect(vacrAvailable($this->emp))->toBe('15.00');

    // Simulación: del lunes 30 de noviembre al viernes 4 de diciembre son 5 días calendario (cómputo de la política).
    $sim = vacrCall($this->employee, 'POST', '/vacation-requests/simulate', ['employment_id' => $this->emp, 'starts_on' => '2026-11-30', 'ends_on' => '2026-12-04']);
    $sim->assertOk();
    $this->assertMatchesOpenApi($sim, 'post', '/vacation-requests/simulate', 200);
    expect($sim->json('data'))->toMatchArray(['days' => '5', 'calendar_days' => 5, 'working_days' => 5, 'issues' => [], 'role' => 'employee', 'advance_days' => '0'])
        ->and($sim->json('data.balance.available'))->toBe('15');

    $created = vacrDraft($this->employee, '2026-11-30', '2026-12-06', ['notes' => 'Viaje familiar']);
    $created->assertCreated();
    $this->assertMatchesOpenApi($created, 'post', '/vacation-requests', 201);
    expect($created->json('data'))->toMatchArray(['status' => 'draft', 'days' => '7', 'calendar_days' => '7', 'notes' => 'Viaje familiar', 'person_name' => 'Juan Prueba'])
        ->and($created->json('data.allowed_actions'))->toContain('submit', 'cancel', 'update');
    $id = (string) $created->json('data.id');

    // Editar el borrador recalcula; el estado no se cambia por PATCH (regla 3).
    $patched = vacrCall($this->employee, 'PATCH', "/vacation-requests/{$id}", ['ends_on' => '2026-12-04', 'status' => 'approved']);
    $patched->assertOk();
    $this->assertMatchesOpenApi($patched, 'patch', '/vacation-requests/{id}', 200);
    expect($patched->json('data'))->toMatchArray(['status' => 'draft', 'days' => '5', 'ends_on' => '2026-12-04']);

    $sent = vacrCall($this->employee, 'POST', "/vacation-requests/{$id}/submit");
    $sent->assertOk();
    $this->assertMatchesOpenApi($sent, 'post', '/vacation-requests/{id}/submit', 200);
    expect($sent->json('data.status'))->toBe('pending')->and($sent->json('data.approval_request_id'))->not->toBeNull();
    // Enviar no reserva: el saldo cambia al aprobar (RN-VAC-03).
    expect(vacrAvailable($this->emp))->toBe('15.00');

    // El jefe ve los cruces del equipo antes de decidir.
    $over = vacrCall($this->boss, 'GET', "/vacation-requests/{$id}/overlaps");
    $over->assertOk();
    $this->assertMatchesOpenApi($over, 'get', '/vacation-requests/{id}/overlaps', 200);
    expect($over->json('data.people'))->toBe([])->and($over->json('data.coverage'))->toBe([]);

    vacrCall($this->boss, 'POST', '/approval-requests/'.$sent->json('data.approval_request_id').'/approve')->assertOk()->assertJsonPath('data.status', 'approved');

    $show = vacrCall($this->boss, 'GET', "/vacation-requests/{$id}");
    $show->assertOk();
    $this->assertMatchesOpenApi($show, 'get', '/vacation-requests/{id}', 200);
    expect($show->json('data.status'))->toBe('approved')->and($show->json('data.decided_at'))->not->toBeNull();
    expect(vacrAvailable($this->emp))->toBe('10.00');

    $list = vacrCall($this->boss, 'GET', '/vacation-requests?filter[status]=approved');
    $list->assertOk();
    $this->assertMatchesOpenApi($list, 'get', '/vacation-requests', 200);
    expect(collect($list->json('data'))->pluck('id')->all())->toBe([$id]);
    vacrCall($this->boss, 'GET', '/vacation-requests?filter[status]=nope')->assertStatus(400);

    TenancyScope::runAs(VACR_TENANT, function () use ($id): void {
        expect(DB::table('outbox_events')->where('aggregate_id', $id)->pluck('event_type')->all())->toContain('leave.requested.v1', 'leave.approved.v1');
        expect(DB::table('audit_events')->where('entity_id', $id)->pluck('action')->all())->toContain('vacation_request.created', 'vacation_request.submitted', 'vacation_request.approved');
        expect(DB::table('vacation_ledger_entries')->where('vacation_request_id', $id)->where('entry_type', 'scheduled')->sum('days'))->toEqual(-5);
    });

    // Aparece en el calendario general con su fuente.
    $cal = vacrCall($this->boss, 'GET', '/calendar?from=2026-11-01&to=2026-12-31&sources=vacations');
    $cal->assertOk();
    $this->assertMatchesOpenApi($cal, 'get', '/calendar', 200);
    expect(collect($cal->json('data'))->pluck('id')->all())->toContain($id);

    // Cancelar antes del goce exige motivo y devuelve los días al saldo.
    vacrCall($this->employee, 'POST', "/vacation-requests/{$id}/cancel")->assertStatus(422);
    $cancel = vacrCall($this->employee, 'POST', "/vacation-requests/{$id}/cancel", ['reason' => 'Cambio de planes']);
    $cancel->assertOk();
    $this->assertMatchesOpenApi($cancel, 'post', '/vacation-requests/{id}/cancel', 200);
    expect($cancel->json('data.status'))->toBe('cancelled');
    expect(vacrAvailable($this->emp))->toBe('15.00');

    // Una cancelada no admite más acciones: 409 invalid_transition.
    $again = vacrCall($this->employee, 'POST', "/vacation-requests/{$id}/cancel", ['reason' => 'Otra vez']);
    $again->assertStatus(409)->assertJsonPath('error.code', 'invalid_transition');
    $this->assertMatchesOpenApi($again, 'post', '/vacation-requests/{id}/cancel', 409);
});

test('VAC-3 · el servidor valida saldo, anticipación y solapes con vacaciones y ausencias', function (): void {
    // Sin saldo suficiente (sin adelanto en la política): 422 al enviar.
    $big = vacrDraft($this->employee, '2026-12-01', '2026-12-31');
    $big->assertCreated();
    $sent = vacrCall($this->employee, 'POST', '/vacation-requests/'.$big->json('data.id').'/submit');
    $sent->assertStatus(422)->assertJsonPath('error.code', 'insufficient_vacation_balance');
    $this->assertMatchesOpenApi($sent, 'post', '/vacation-requests/{id}/submit', 422);

    // Anticipación mínima (7 días por defecto): el colaborador no puede pedir para el lunes 16.
    $soon = vacrCall($this->employee, 'POST', '/vacation-requests/simulate', ['employment_id' => $this->emp, 'starts_on' => '2026-11-16', 'ends_on' => '2026-11-18']);
    expect(collect($soon->json('data.issues'))->pluck('code')->all())->toContain('insufficient_notice');

    // Una solicitud en trámite ocupa sus fechas: otra solapada responde 409.
    $first = vacrDraft($this->employee, '2026-12-14', '2026-12-18');
    vacrCall($this->employee, 'POST', '/vacation-requests/'.$first->json('data.id').'/submit')->assertOk();
    $second = vacrDraft($this->employee, '2026-12-17', '2026-12-19');
    $overlap = vacrCall($this->employee, 'POST', '/vacation-requests/'.$second->json('data.id').'/submit');
    $overlap->assertStatus(409)->assertJsonPath('error.code', 'vacation_overlap');
    $this->assertMatchesOpenApi($overlap, 'post', '/vacation-requests/{id}/submit', 409);

    // Una ausencia aprobada también (AUS-2), y al revés: la ausencia no puede pisar las vacaciones.
    $type = vacrCall($this->admin, 'POST', '/absence-types', ['code' => 'dia_libre', 'name' => 'Día libre', 'category' => 'personal',
        'effective_from' => '2025-01-01', 'rules' => ['unit' => 'days', 'requires_document' => false, 'requestable_by' => 'employee']])->assertCreated()->json('data.id');
    $flow = vacrCall($this->admin, 'POST', '/approval-flows', ['name' => 'Ausencias', 'subject_type' => 'absence_request',
        'steps' => ['levels' => [['mode' => 'sequential', 'approvers' => [['kind' => 'manager']]]]]])->assertCreated();
    vacrCall($this->admin, 'POST', '/approval-flows/'.$flow->json('data.id').'/publish')->assertOk();
    $absence = vacrCall($this->employee, 'POST', '/absences', ['employment_id' => $this->emp, 'absence_type_id' => $type, 'starts_on' => '2026-12-22', 'ends_on' => '2026-12-22'])->assertCreated();
    vacrCall($this->employee, 'POST', '/absences/'.$absence->json('data.id').'/submit')->assertOk();
    $third = vacrDraft($this->employee, '2026-12-21', '2026-12-23');
    vacrCall($this->employee, 'POST', '/vacation-requests/'.$third->json('data.id').'/submit')->assertStatus(409)->assertJsonPath('error.code', 'absence_overlap');
    $absSim = vacrCall($this->employee, 'POST', '/absences/simulate', ['employment_id' => $this->emp, 'absence_type_id' => $type, 'starts_on' => '2026-12-15', 'ends_on' => '2026-12-15']);
    expect(collect($absSim->json('data.issues'))->pluck('code')->all())->toContain('vacation_overlap');
});

test('VAC-3 · permisos y alcance: el compañero no ve la solicitud, el colaborador no registra directo y otra organización recibe 404', function (): void {
    $id = (string) vacrDraft($this->employee, '2026-11-30', '2026-12-04')->assertCreated()->json('data.id');

    $peerShow = vacrCall($this->peer, 'GET', "/vacation-requests/{$id}");
    $peerShow->assertNotFound();
    $this->assertMatchesOpenApi($peerShow, 'get', '/vacation-requests/{id}', 404);
    // Pedir a nombre de otro fuera del alcance: 404.
    vacrDraft($this->peer, '2026-11-30', '2026-12-04')->assertNotFound();

    $register = vacrCall($this->employee, 'POST', "/vacation-requests/{$id}/register");
    $register->assertForbidden();
    $this->assertMatchesOpenApi($register, 'post', '/vacation-requests/{id}/register', 403);

    $outsider = vacrUser(VACR_OTHER, 'admin_empresa');
    $foreign = vacrCall($outsider, 'GET', "/vacation-requests/{$id}");
    $foreign->assertNotFound();
    vacrCall($outsider, 'POST', "/vacation-requests/{$id}/submit")->assertNotFound();
    expect(collect(vacrCall($outsider, 'GET', '/vacation-requests')->assertOk()->json('data'))->pluck('id')->all())->not->toContain($id);
});

test('VAC-3 · RRHH registra directamente con excepción y la tarea diaria inicia y finaliza el goce', function (): void {
    // Con menos anticipación que la mínima: RRHH lo registra indicando el motivo de la excepción.
    $draft = vacrDraft($this->hr, '2026-11-16', '2026-11-20', ['exception_reason' => 'Acordado con el jefe por cierre de proyecto']);
    $draft->assertCreated();
    $id = (string) $draft->json('data.id');
    $reg = vacrCall($this->hr, 'POST', "/vacation-requests/{$id}/register");
    $reg->assertOk();
    $this->assertMatchesOpenApi($reg, 'post', '/vacation-requests/{id}/register', 200);
    expect($reg->json('data.status'))->toBe('approved')
        ->and(collect($reg->json('meta.warnings'))->pluck('code')->all())->toContain('insufficient_notice_exception');
    expect(vacrAvailable($this->emp))->toBe('10.00');

    // Registrar de nuevo: 409.
    vacrCall($this->hr, 'POST', "/vacation-requests/{$id}/register")->assertStatus(409)->assertJsonPath('error.code', 'invalid_transition');

    Carbon::setTestNow('2026-11-16 08:00:00');
    Artisan::call('vacations:advance');
    Artisan::call('vacations:advance');
    TenancyScope::runAs(VACR_TENANT, function () use ($id): void {
        expect(DB::table('vacation_requests')->where('id', $id)->value('status'))->toBe('in_progress');
        expect(DB::table('vacation_ledger_entries')->where('vacation_request_id', $id)->where('entry_type', 'taken')->sum('days'))->toEqual(-5);
    });
    // En goce ya no se cancela.
    vacrCall($this->hr, 'POST', "/vacation-requests/{$id}/cancel", ['reason' => 'Tarde'])->assertStatus(409);

    Carbon::setTestNow('2026-11-21 08:00:00');
    Artisan::call('vacations:advance');
    TenancyScope::runAs(VACR_TENANT, function () use ($id): void {
        expect(DB::table('vacation_requests')->where('id', $id)->value('status'))->toBe('taken');
        expect(DB::table('outbox_events')->where('aggregate_id', $id)->pluck('event_type')->all())->toContain('leave.started.v1', 'leave.completed.v1');
    });
    expect(vacrAvailable($this->emp))->toBe('10.00');
});

test('VAC-4 · el jefe ve el calendario del equipo con lo aprobado y lo pendiente y la alerta de cobertura; el compañero solo ve «Ausente»', function (): void {
    vacrSetting('vacaciones.maximo_ausentes_simultaneos', 1);
    $approved = (string) vacrDraft($this->hr, '2026-11-30', '2026-12-04')->json('data.id');
    vacrCall($this->hr, 'POST', "/vacation-requests/{$approved}/register")->assertOk();
    $pending = vacrDraft($this->peer, '2026-12-03', '2026-12-07', [], $this->org['peerEmployment']);
    $pending->assertCreated();
    $sent = vacrCall($this->peer, 'POST', '/vacation-requests/'.$pending->json('data.id').'/submit');
    $sent->assertOk();
    expect(collect($sent->json('meta.warnings'))->pluck('code')->all())->toContain('coverage_exceeded');

    $team = vacrCall($this->boss, 'GET', '/team-calendar?from=2026-11-30&to=2026-12-06');
    $team->assertOk();
    $this->assertMatchesOpenApi($team, 'get', '/team-calendar', 200);
    expect($team->json('data.mode'))->toBe('team');
    $people = collect($team->json('data.people'))->keyBy('person_name');
    expect($people['Juan Prueba']['items'][0])->toMatchArray(['source' => 'vacation', 'status' => 'approved', 'pending' => false, 'id' => $approved])
        ->and($people['Lucia Prueba']['items'][0])->toMatchArray(['source' => 'vacation', 'status' => 'pending', 'pending' => true]);
    $days = collect($team->json('data.days'))->keyBy('date');
    expect($days['2026-12-03'])->toMatchArray(['absent' => 2, 'coverage_exceeded' => true])
        ->and($days['2026-11-30'])->toMatchArray(['absent' => 1, 'coverage_exceeded' => false]);
    expect($team->json('data.alerts'))->toHaveCount(1)
        ->and($team->json('data.alerts.0'))->toMatchArray(['from' => '2026-12-03', 'to' => '2026-12-04', 'count' => 2, 'limit' => 1, 'org_unit_name' => 'Operaciones']);

    // El compañero (alcance propio) ve a su unidad, solo lo aprobado y sin tipo ni enlace.
    $peers = vacrCall($this->peer, 'GET', '/team-calendar?from=2026-11-30&to=2026-12-06');
    $peers->assertOk();
    $this->assertMatchesOpenApi($peers, 'get', '/team-calendar', 200);
    expect($peers->json('data.mode'))->toBe('peers')->and($peers->json('data.alerts'))->toBe([]);
    $seen = collect($peers->json('data.people'))->keyBy('person_name');
    expect($seen['Juan Prueba']['items'])->toHaveCount(1)
        ->and($seen['Juan Prueba']['items'][0])->toMatchArray(['id' => null, 'source' => 'other', 'label' => 'Ausente', 'link' => null])
        ->and($seen['Juan Prueba']['employee_code'])->toBeNull()
        ->and($seen['Lucia Prueba']['items'][0])->toMatchArray(['source' => 'vacation', 'pending' => true]);

    vacrCall($this->boss, 'GET', '/team-calendar?from=2026-12-06&to=2026-11-30')->assertStatus(422);
    vacrCall(vacrUser(VACR_OTHER, 'admin_empresa'), 'GET', '/team-calendar?from=2026-11-30&to=2026-12-06')->assertOk()->assertJsonPath('data.people', []);
});
