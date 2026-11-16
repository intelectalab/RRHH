<?php

/**
 * MSS-3 · Aprobaciones y solicitudes del equipo · MSS-4 · Fechas clave y alertas · HIS-6 · Servicio de datos maestros.
 *
 * Estructura: Marta (jefa, posición JEF) ← Juan (directo, por posición) ← Lucia (indirecta, reporta a Juan por
 * manager_employment_id). Pedro está en la misma empresa pero fuera del equipo. Hoy (congelado): 13/11/2026.
 */

use App\Modules\CoreHR\Application\TeamKeyDates;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Notifications\Infrastructure\Subscribers\NotifyContractEvents;
use App\Modules\Notifications\Infrastructure\Subscribers\NotifyTeamKeyDates;
use App\Modules\Organization\Infrastructure\Models\LegalEntity;
use App\Modules\Workflow\Application\ApprovalFlowService;
use App\Shared\Labor\MasterData;
use App\Shared\Outbox\DomainEventTypes;
use App\Shared\Outbox\Models\OutboxEvent;
use App\Shared\Permissions\ModuleGate;
use App\Shared\Permissions\PermissionResolver;
use App\Shared\Settings\ParameterResolver;
use App\Shared\Tenancy\TenancyScope;
use App\Shared\Workflow\ApprovalRequests;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\IdentityFixtures;

uses(DatabaseTransactions::class);

const MSS_TENANT = 'aaaaaaaa-0553-4000-8000-00000000000a';
const MSS_OTHER = 'bbbbbbbb-0553-4000-8000-00000000000b';

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requiere PostgreSQL.');
    }
    Carbon::setTestNow('2026-11-13 12:00:00');
    TenancyScope::clear();
    foreach ([MSS_TENANT => 'mss-org', MSS_OTHER => 'mss-otra'] as $id => $slug) {
        DB::table('tenants')->insertOrIgnore(['id' => $id, 'slug' => $slug, 'name' => 'Grupo '.$slug, 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN']);
        DB::select('SELECT seed_tenant_defaults(?)', [$id]);
        TenancyScope::runAs($id, fn () => IdentityFixtures::enableAllModules());
    }

    $this->org = TenancyScope::runAs(MSS_TENANT, function (): array {
        $entity = (string) LegalEntity::factory()->create(['timezone' => 'America/Lima'])->id;
        $unit = (string) Str::uuid();
        DB::table('org_units')->insert(['id' => $unit, 'tenant_id' => MSS_TENANT, 'code' => 'OPE', 'name' => 'Operaciones']);
        $unit2 = (string) Str::uuid();
        DB::table('org_units')->insert(['id' => $unit2, 'tenant_id' => MSS_TENANT, 'code' => 'VEN', 'name' => 'Ventas']);
        $site = (string) Str::uuid();
        DB::table('sites')->insert(['id' => $site, 'tenant_id' => MSS_TENANT, 'legal_entity_id' => $entity, 'code' => 'LIM', 'name' => 'Lima', 'timezone' => 'America/Lima']);
        $profile = (string) Str::uuid();
        DB::table('job_profiles')->insert(['id' => $profile, 'tenant_id' => MSS_TENANT, 'code' => 'ANA', 'name' => 'Analista']);
        $bossPosition = (string) Str::uuid();
        DB::table('positions')->insert(['id' => $bossPosition, 'tenant_id' => MSS_TENANT, 'code' => 'JEF', 'title' => 'Jefatura', 'job_profile_id' => $profile,
            'org_unit_id' => $unit, 'legal_entity_id' => $entity, 'status' => 'filled']);
        $juanPosition = (string) Str::uuid();
        DB::table('positions')->insert(['id' => $juanPosition, 'tenant_id' => MSS_TENANT, 'code' => 'ANA1', 'title' => 'Analista 1', 'job_profile_id' => $profile,
            'org_unit_id' => $unit, 'legal_entity_id' => $entity, 'status' => 'filled', 'reports_to_position_id' => $bossPosition]);

        $mk = function (string $name, ?string $manager, ?string $position, string $hire, ?string $birth = null) use ($entity, $unit, $profile, $site): array {
            $person = (string) Str::uuid();
            $employment = (string) Str::uuid();
            DB::table('people')->insert(['id' => $person, 'tenant_id' => MSS_TENANT, 'first_names' => $name, 'paternal_surname' => 'Prueba', 'birth_date' => $birth]);
            DB::table('employments')->insert(['id' => $employment, 'tenant_id' => MSS_TENANT, 'person_id' => $person, 'legal_entity_id' => $entity, 'employee_code' => strtoupper($name), 'hire_date' => $hire, 'status' => 'active']);
            DB::table('employment_assignments')->insert([
                'tenant_id' => MSS_TENANT, 'employment_id' => $employment, 'job_profile_id' => $profile, 'org_unit_id' => $unit, 'site_id' => $site,
                'position_id' => $position, 'manager_employment_id' => $manager, 'valid_from' => $hire,
            ]);

            return [$person, $employment];
        };
        [$martaPerson, $marta] = $mk('Marta', null, $bossPosition, '2020-03-01');
        [$juanPerson, $juan] = $mk('Juan', null, $juanPosition, '2024-11-25', '1990-11-20');
        [$luciaPerson, $lucia] = $mk('Lucia', $juan, null, '2026-11-02', '1995-12-01');
        [$pedroPerson, $pedro] = $mk('Pedro', null, null, '2023-11-20', '1991-11-15');

        $type = (string) Str::uuid();
        DB::table('contract_types')->insert(['id' => $type, 'tenant_id' => MSS_TENANT, 'code' => 'PLZ', 'name' => 'Plazo fijo', 'category' => 'fixed_term']);
        DB::table('contracts')->insert(['tenant_id' => MSS_TENANT, 'employment_id' => $lucia, 'contract_type_id' => $type, 'valid_from' => '2026-11-02', 'valid_to' => '2027-05-01',
            'trial_period_ends_on' => '2026-11-30', 'status' => 'active']);
        DB::table('contracts')->insert(['tenant_id' => MSS_TENANT, 'employment_id' => $juan, 'contract_type_id' => $type, 'valid_from' => '2025-12-01', 'valid_to' => '2026-11-28', 'status' => 'active']);
        DB::table('contracts')->insert(['tenant_id' => MSS_TENANT, 'employment_id' => $pedro, 'contract_type_id' => $type, 'valid_from' => '2026-01-01', 'valid_to' => '2026-11-27', 'status' => 'active']);

        // HIS-3 · Sueldo de Juan con un aumento programado.
        DB::table('employment_compensations')->insert(['tenant_id' => MSS_TENANT, 'employment_id' => $juan, 'base_salary' => '3000.00', 'currency' => 'PEN', 'valid_from' => '2024-11-25', 'valid_to' => '2026-11-30']);
        DB::table('employment_compensations')->insert(['tenant_id' => MSS_TENANT, 'employment_id' => $juan, 'base_salary' => '3450.50', 'currency' => 'PEN', 'valid_from' => '2026-12-01']);
        // HIS-2 · Juan pasa a Ventas con jefe explícito Pedro desde el 1 de diciembre (cerrar e insertar).
        DB::table('employment_assignments')->where('employment_id', $juan)->update(['valid_to' => '2026-11-30']);
        DB::table('employment_assignments')->insert(['tenant_id' => MSS_TENANT, 'employment_id' => $juan, 'job_profile_id' => $profile, 'org_unit_id' => $unit2, 'site_id' => $site,
            'position_id' => $juanPosition, 'manager_employment_id' => $pedro, 'valid_from' => '2026-12-01']);

        return compact('entity', 'martaPerson', 'marta', 'juanPerson', 'juan', 'luciaPerson', 'lucia', 'pedroPerson', 'pedro', 'unit', 'unit2');
    });

    $this->manager = mssUser(MSS_TENANT, 'manager', $this->org['martaPerson']);
    $this->juanUser = mssUser(MSS_TENANT, 'empleado', $this->org['juanPerson']);
    $this->pedroUser = mssUser(MSS_TENANT, 'empleado', $this->org['pedroPerson']);
    $this->hr = mssUser(MSS_TENANT, 'rrhh');
});

afterEach(function (): void {
    TenancyScope::clear();
    Carbon::setTestNow();
});

function mssUser(string $tenant, string $role, ?string $personId = null): User
{
    return TenancyScope::runAs($tenant, function () use ($role, $personId): User {
        $u = User::factory()->create(['person_id' => $personId]);
        $u->roleAssignments()->create(['role_id' => Role::query()->where('system_role_code', $role)->value('id')]);

        return $u;
    });
}

function mssCall(User $user, string $path): TestResponse
{
    TenancyScope::clear();
    app('auth')->forgetGuards();
    app(PermissionResolver::class)->forget();
    app(ModuleGate::class)->forget();

    return test()->actingAs($user, 'ayni')->withHeaders(['X-Tenant-Id' => $user->tenant_id])->getJson('/api/v1'.$path);
}

/** Flujo de vacaciones publicado: aprueba el jefe directo. */
function mssVacationFlow(): void
{
    TenancyScope::runAs(MSS_TENANT, function (): void {
        $flows = app(ApprovalFlowService::class);
        $flow = $flows->create(['name' => 'Vacaciones', 'subject_type' => 'vacation_request', 'steps' => ['levels' => [
            ['name' => 'Jefe directo', 'mode' => 'sequential', 'sla_hours' => 48, 'approvers' => [['kind' => 'manager']]],
        ]], 'conditions' => []]);
        $flows->publish($flow, null);
    });
}

test('MSS-4 · fechas clave del equipo: solo su equipo, el cumpleaños sin año y con registro de acceso', function (): void {
    $res = mssCall($this->manager, '/my-team/key-dates?from=2026-11-13&to=2026-12-12');
    $res->assertOk();
    $this->assertMatchesOpenApi($res, 'get', '/my-team/key-dates', 200);

    $rows = collect($res->json('data'));
    $o = $this->org;
    expect($rows->pluck('employment_id')->unique()->sort()->values()->all())->toEqual(collect([$o['juan'], $o['lucia']])->sort()->values()->all())
        ->and($rows->firstWhere('kind', 'birthday'))->toMatchArray(['employment_id' => $o['juan'], 'date' => '2026-11-20', 'is_direct' => true])
        ->and($rows->where('kind', 'birthday')->pluck('employment_id')->values()->all())->toBe([$o['juan'], $o['lucia']])
        ->and($rows->firstWhere('kind', 'anniversary'))->toMatchArray(['employment_id' => $o['juan'], 'date' => '2026-11-25', 'years' => 2])
        ->and($rows->firstWhere('kind', 'probation_end'))->toMatchArray(['employment_id' => $o['lucia'], 'date' => '2026-11-30', 'is_direct' => false])
        ->and($rows->firstWhere('kind', 'contract_end'))->toMatchArray(['employment_id' => $o['juan'], 'date' => '2026-11-28'])
        ->and(json_encode($res->json()))->not->toContain('1990')
        ->and($rows->pluck('date')->all())->toBe($rows->pluck('date')->sort()->values()->all());

    TenancyScope::runAs(MSS_TENANT, function (): void {
        expect(DB::table('data_access_logs')->where('user_id', $this->manager->id)->where('entity_type', 'team_key_dates')->count())->toBe(1);
    });

    // Filtro por tipo.
    $only = mssCall($this->manager, '/my-team/key-dates?from=2026-11-13&to=2026-12-12&filter[kind]=probation_end');
    expect(collect($only->json('data'))->pluck('kind')->unique()->all())->toBe(['probation_end']);
});

test('MSS-4 · la organización oculta los cumpleaños en las fechas clave, el inicio y los recordatorios', function (): void {
    TenancyScope::runAs(MSS_TENANT, fn () => app(ParameterResolver::class)->set('celebraciones.mostrar_cumpleanos', false, 'organization', null, null, 'prueba'));

    $res = mssCall($this->manager, '/my-team/key-dates?from=2026-11-13&to=2026-12-12');
    expect(collect($res->json('data'))->pluck('kind')->contains('birthday'))->toBeFalse()
        ->and($res->json('meta.birthdays_hidden'))->toBeTrue()
        ->and($res->json('meta.kinds'))->not->toContain('birthday');

    $home = mssCall($this->manager, '/home/manager');
    $home->assertOk();
    $birthdays = collect($home->json('data.cards'))->firstWhere('key', 'birthdays');
    if ($birthdays !== null) {
        expect($birthdays['value'])->toBe(0)->and($birthdays['items'])->toBe([]);
    }

    $count = TenancyScope::runAs(MSS_TENANT, fn (): int => app(TeamKeyDates::class)->remind('2026-11-19'));
    expect($count)->toBe(0);
});

test('MSS-4 · recordatorio al jefe del cumpleaños y del aniversario, sin repetir y respetando el equipo', function (): void {
    $first = TenancyScope::runAs(MSS_TENANT, fn (): int => app(TeamKeyDates::class)->remind('2026-11-19'));
    $again = TenancyScope::runAs(MSS_TENANT, fn (): int => app(TeamKeyDates::class)->remind('2026-11-19'));
    expect($first)->toBe(1)->and($again)->toBe(0);

    TenancyScope::runAs(MSS_TENANT, function (): void {
        $event = OutboxEvent::query()->where('event_type', DomainEventTypes::TEAM_KEY_DATE_UPCOMING)->firstOrFail();
        expect($event->payload)->toMatchArray(['employment_id' => $this->org['juan'], 'kind' => 'birthday', 'date' => '2026-11-20']);
        app(NotifyTeamKeyDates::class)->handle($event);
        $sent = DB::table('notifications')->where('category', NotifyTeamKeyDates::BIRTHDAY)->get();
        expect($sent)->toHaveCount(1)
            ->and((string) $sent[0]->recipient_user_id)->toBe((string) $this->manager->id)
            ->and((string) $sent[0]->title)->not->toContain('1990');
    });

    // Aniversario de Juan (2 años el 25/11) con el parámetro de anticipación por defecto (1 día).
    $anniversary = TenancyScope::runAs(MSS_TENANT, fn (): int => app(TeamKeyDates::class)->remind('2026-11-24'));
    expect($anniversary)->toBe(1);
});

test('MSS-4 · el manager recibe aviso antes del fin del periodo de prueba de un subordinado', function (): void {
    TenancyScope::runAs(MSS_TENANT, function (): void {
        // Lucia reporta a Juan: con Juan como jefe directo, el aviso de fin de prueba (CON-2) le llega a él.
        $contract = DB::table('contracts')->where('employment_id', $this->org['lucia'])->value('id');
        $event = OutboxEvent::query()->create(['tenant_id' => MSS_TENANT, 'event_type' => DomainEventTypes::CONTRACT_PROBATION_ENDING, 'aggregate_type' => 'contract', 'aggregate_id' => $contract,
            'payload' => ['contract_id' => $contract, 'employment_id' => $this->org['lucia'], 'trial_period_ends_on' => '2026-11-30']]);
        app(NotifyContractEvents::class)->handle($event);
        expect(DB::table('notifications')->where('category', 'contracts.probation_ending')->where('recipient_user_id', $this->juanUser->id)->count())->toBe(1);
    });
});

test('MSS-3 · solicitudes del equipo: el manager ve y decide solo las de su equipo', function (): void {
    mssVacationFlow();
    [$teamRequest, $outside] = TenancyScope::runAs(MSS_TENANT, function (): array {
        $approvals = app(ApprovalRequests::class);
        $team = $approvals->start('vacation_request', (string) Str::uuid(), (string) $this->juanUser->id, ['employment_id' => $this->org['juan'], 'legal_entity_id' => $this->org['entity']]);
        $outside = $approvals->start('vacation_request', (string) Str::uuid(), (string) $this->pedroUser->id, ['employment_id' => $this->org['pedro'], 'legal_entity_id' => $this->org['entity']]);

        return [$team, $outside];
    });

    $res = mssCall($this->manager, '/approval-requests?filter[team]=1&filter[status]=pending');
    $res->assertOk();
    $this->assertMatchesOpenApi($res, 'get', '/approval-requests', 200);
    $rows = collect($res->json('data'));
    expect($rows->pluck('id')->all())->toBe([$teamRequest])
        ->and($rows[0])->toMatchArray(['employment_id' => $this->org['juan'], 'employee_name' => 'Juan Prueba', 'can_decide' => true])
        ->and($rows->pluck('id'))->not->toContain($outside);

    // Pedro no es de su equipo: aunque el administrador amplíe el alcance, el filtro de equipo lo deja fuera.
    $hr = mssCall($this->hr, '/approval-requests?filter[team]=1');
    expect(collect($hr->json('data'))->pluck('id')->all())->toBe([]);
});

test('MSS-3 · RN-MSS-05 · durante la suplencia el suplente ve al equipo de quien delegó', function (): void {
    $deputy = mssUser(MSS_TENANT, 'manager');
    expect(collect(mssCall($deputy, '/my-team')->json('data'))->pluck('id')->all())->toBe([]);

    TenancyScope::runAs(MSS_TENANT, fn () => DB::table('approval_delegations')->insert([
        'tenant_id' => MSS_TENANT, 'from_user_id' => $this->manager->id, 'to_user_id' => $deputy->id, 'starts_on' => '2026-11-10', 'ends_on' => '2026-11-20', 'reason' => 'Vacaciones',
    ]));
    $team = mssCall($deputy, '/my-team');
    $team->assertOk();
    expect(collect($team->json('data'))->pluck('id')->sort()->values()->all())->toEqual(collect([$this->org['juan'], $this->org['lucia']])->sort()->values()->all());

    // La ficha del integrante también entra en su alcance «equipo» durante la suplencia.
    mssCall($deputy, '/employments/'.$this->org['juan'].'/work-data')->assertOk();

    // Una delegación solo de un tipo de trámite no le abre el equipo.
    TenancyScope::runAs(MSS_TENANT, fn () => DB::table('approval_delegations')->update(['subject_type' => 'vacation_request']));
    expect(collect(mssCall($deputy, '/my-team')->json('data'))->all())->toBe([]);
});

test('MSS-3 · MSS-4 · permisos, filtros y módulo', function (): void {
    mssCall($this->juanUser, '/my-team/key-dates')->assertStatus(403);
    mssCall($this->manager, '/my-team/key-dates?filter[kind]=salary')->assertStatus(400);
    $bad = mssCall($this->manager, '/my-team/key-dates?from=2026-11-13&to=2027-06-01');
    $bad->assertStatus(400);
    $this->assertMatchesOpenApi($bad, 'get', '/my-team/key-dates', 400);
    mssCall($this->manager, '/my-team/key-dates?from=2026-12-01&to=2026-11-01')->assertStatus(400);
    mssCall($this->manager, '/my-team/key-dates?from=2026-02-30')->assertStatus(400);

    IdentityFixtures::disableModule(MSS_TENANT, app(ModuleGate::class)->moduleOf('people.team.view') ?? 'core');
    mssCall($this->manager, '/my-team/key-dates')->assertForbidden()->assertJsonPath('error.code', 'module_not_enabled');

    // Otra organización: su manager no ve a nadie de la primera.
    $outsider = mssUser(MSS_OTHER, 'manager');
    expect(mssCall($outsider, '/my-team/key-dates')->json('data'))->toBe([]);
});

test('MSS-4 · sin permiso de contratos no viajan el fin de prueba ni el vencimiento', function (): void {
    TenancyScope::runAs(MSS_TENANT, function (): void {
        $role = Role::query()->where('system_role_code', 'manager')->value('id');
        DB::table('role_permissions')->where('role_id', $role)->where('permission_code', 'contracts.contracts.view')->delete();
    });
    $res = mssCall($this->manager, '/my-team/key-dates?from=2026-11-13&to=2026-12-12');
    expect(collect($res->json('data'))->pluck('kind')->intersect(['probation_end', 'contract_end'])->all())->toBe([])
        ->and($res->json('meta.kinds'))->not->toContain('contract_end');
});

test('HIS-6 · el servicio de datos maestros responde por relación laboral y fecha', function (): void {
    TenancyScope::runAs(MSS_TENANT, function (): void {
        $md = app(MasterData::class);
        $o = $this->org;
        $now = $md->at($o['juan'], '2026-11-13');
        $later = $md->at($o['juan'], '2026-12-15');
        expect($now)->toMatchArray(['org_unit_id' => $o['unit'], 'manager_employment_id' => $o['marta'], 'manager_source' => 'position', 'valid_to' => '2026-11-30'])
            ->and($later)->toMatchArray(['org_unit_id' => $o['unit2'], 'manager_employment_id' => $o['pedro'], 'manager_source' => 'assignment', 'valid_to' => null])
            ->and($md->at($o['juan'], '2020-01-01'))->toBeNull()
            ->and($md->managerAt($o['lucia'], '2026-11-13'))->toBe($o['juan'])
            ->and($md->managerAt($o['marta'], '2026-11-13'))->toBeNull()
            ->and($md->salaryAt($o['juan'], '2026-11-30'))->toMatchArray(['amount' => '3000.00', 'currency' => 'PEN'])
            ->and($md->salaryAt($o['juan'], '2026-12-01'))->toMatchArray(['amount' => '3450.50', 'valid_to' => null])
            ->and($md->salaryAt($o['lucia'], '2026-11-13'))->toBeNull()
            ->and(array_keys($md->many([$o['juan'], $o['lucia'], 'no-es-uuid'], '2026-11-13')))->toEqualCanonicalizing([$o['juan'], $o['lucia']]);
    });
    // Otra organización no ve los datos (RLS).
    TenancyScope::runAs(MSS_OTHER, fn () => expect(app(MasterData::class)->at($this->org['juan'], '2026-11-13'))->toBeNull());
});
