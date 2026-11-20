<?php

/**
 * AUS-5 · AUS-6 · Resumen de ausencias del periodo (lo que la planilla tomará como incidencias): cada día
 * explicado, los días remotos distinguidos (no son falta ni ausencia), el efecto remunerativo de cada ausencia,
 * la huella reproducible y el cierre que congela el resumen para informar después las diferencias. La
 * desconexión digital difiere lo no urgente fuera de la jornada del teletrabajador.
 */

use App\Modules\Absences\Database\Factories\AbsenceRequestFactory;
use App\Modules\Absences\Infrastructure\Models\AbsenceType;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Shared\LegalParameters\LegalParameters;
use App\Shared\Permissions\ModuleGate;
use App\Shared\Permissions\PermissionResolver;
use App\Shared\Tenancy\TenancyScope;
use App\Shared\Time\AbsenceIncidents;
use App\Shared\Time\DisconnectionWindow;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\IdentityFixtures;

uses(DatabaseTransactions::class);

const APS_TENANT = 'aaaaaaaa-0a56-4000-8000-00000000000a';
const APS_OTHER = 'bbbbbbbb-0a56-4000-8000-00000000000b';

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requiere PostgreSQL.');
    }
    TenancyScope::clear();
    app(LegalParameters::class)->sync('PE');
    foreach ([APS_TENANT => 'aps-org', APS_OTHER => 'aps-otra'] as $id => $slug) {
        DB::table('tenants')->insertOrIgnore(['id' => $id, 'slug' => $slug, 'name' => 'Grupo '.$slug, 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN']);
        DB::select('SELECT seed_tenant_defaults(?)', [$id]);
        TenancyScope::runAs($id, fn () => IdentityFixtures::enableAllModules());
    }
    $this->hr = apsUser(APS_TENANT, 'rrhh');
    $this->employee = apsUser(APS_TENANT, 'empleado');
    $this->other = apsUser(APS_OTHER, 'admin_empresa');
    $this->employment = TenancyScope::runAs(APS_TENANT, fn (): string => AbsenceRequestFactory::employment());
});

afterEach(fn () => TenancyScope::clear());

function apsUser(string $tenant, string $role): User
{
    return TenancyScope::runAs($tenant, function () use ($role): User {
        $u = User::factory()->create();
        $u->roleAssignments()->create(['role_id' => Role::query()->where('system_role_code', $role)->value('id')]);

        return $u;
    });
}

/** @param array<string, mixed> $body */
function apsCall(User $user, string $method, string $path, array $body = []): TestResponse
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

/** @param array<string, mixed> $rule */
function apsType(string $category, array $rule, bool $confidential = false): string
{
    $type = AbsenceType::factory()->create(['category' => $category, 'is_confidential' => $confidential]);
    DB::table('absence_type_rules')->where('absence_type_id', $type->id)->update($rule + ['valid_from' => '2025-01-01']);

    return (string) $type->id;
}

/** @param array<string, mixed> $extra */
function apsAbsence(string $employment, string $type, string $from, string $to, array $extra = []): void
{
    DB::table('absence_requests')->insert($extra + [
        'tenant_id' => APS_TENANT, 'employment_id' => $employment, 'absence_type_id' => $type, 'status' => 'approved',
        'starts_on' => $from, 'ends_on' => $to, 'starts_at' => $from.' 05:00:00+00', 'ends_at' => CarbonImmutable::parse($to)->addDay()->toDateString().' 05:00:00+00',
    ]);
}

test('AUS-5 · AUS-6 · cada día del periodo queda explicado con su efecto y los días remotos no son falta', function (): void {
    TenancyScope::runAs(APS_TENANT, function (): void {
        $unpaid = apsType('unpaid', ['is_paid' => false]);
        $medical = apsType('medical_leave', ['subsidy_kind' => 'illness', 'payroll_concept_code' => 'descanso_medico'], true);
        $remote = apsType('remote_work', ['affects_attendance' => false]);
        $hourly = apsType('personal', ['unit' => 'hours']);
        // Lunes 1 de junio de 2026. Sin ausencia el martes 2 sería laborable.
        apsAbsence($this->employment, $unpaid, '2026-06-02', '2026-06-02', ['days' => 1]);
        apsAbsence($this->employment, $remote, '2026-06-03', '2026-06-03', ['days' => 1]);
        apsAbsence($this->employment, $hourly, '2026-06-04', '2026-06-04', ['hours' => 2, 'start_time' => '09:00', 'end_time' => '11:00', 'starts_at' => '2026-06-04 14:00:00+00', 'ends_at' => '2026-06-04 16:00:00+00']);
        // Descanso médico de viernes a lunes (días calendario): dos a cargo del empleador, dos de subsidio.
        apsAbsence($this->employment, $medical, '2026-06-05', '2026-06-08', ['days' => 4, 'calendar_days' => 4, 'employer_paid_days' => 2, 'subsidized_days' => 2]);
        // Acuerdo de teletrabajo total desde el miércoles 10 (CON-6).
        DB::table('remote_work_agreements')->insert([
            'tenant_id' => APS_TENANT, 'employment_id' => $this->employment, 'modality' => 'total', 'starts_on' => '2026-06-10',
            'status' => 'active', 'disconnection_hours' => 12, 'signed_at' => now(), 'signature_origin' => 'physical_scan',
        ]);
    });

    $r = apsCall($this->hr, 'GET', "/employments/{$this->employment}/absence-summary?from=2026-06-01&to=2026-06-14")->assertOk();
    test()->assertMatchesOpenApi($r, 'get', '/employments/{employment_id}/absence-summary', 200);
    $days = collect($r->json('data.days'))->keyBy('date');
    expect($days['2026-06-01']['kind'])->toBe('worked')
        ->and($days['2026-06-02']['kind'])->toBe('absence')->and($days['2026-06-02']['absence']['effect'])->toBe('unpaid')
        ->and($days['2026-06-03']['kind'])->toBe('remote')->and($days['2026-06-03']['remote_source'])->toBe('absence')
        ->and($days['2026-06-04']['kind'])->toBe('worked')->and($days['2026-06-04']['hours_absent'])->toBe('2.00')
        ->and($days['2026-06-05']['absence']['effect'])->toBe('employer_paid')
        ->and($days['2026-06-06']['kind'])->toBe('absence')->and($days['2026-06-06']['absence']['effect'])->toBe('employer_paid')
        ->and($days['2026-06-07']['absence']['effect'])->toBe('subsidy')
        ->and($days['2026-06-08']['absence']['effect'])->toBe('subsidy')
        ->and($days['2026-06-09']['kind'])->toBe('worked')
        ->and($days['2026-06-10']['kind'])->toBe('remote')->and($days['2026-06-10']['remote_source'])->toBe('agreement')
        ->and($days['2026-06-13']['kind'])->toBe('rest_day')
        ->and($days['2026-06-01']['explanation'])->not->toBe('')
        ->and($r->json('data.totals.remote_days'))->toBe(4)
        ->and($r->json('data.totals.absence_days'))->toBe(5)
        ->and($r->json('data.source'))->toBe('calendar')
        ->and($r->json('data.closing'))->toBeNull();
    $medicalTotals = collect($r->json('data.types'))->firstWhere('category', 'medical_leave');
    expect($medicalTotals['days'])->toBe(4)->and($medicalTotals['employer_paid_days'])->toBe(2)->and($medicalTotals['subsidized_days'])->toBe(2)
        ->and($medicalTotals['payroll_concept_code'])->toBe('descanso_medico');

    // Reproducible: la misma entrada da la misma huella (el puerto que lee la planilla y la API coinciden).
    $port = TenancyScope::runAs(APS_TENANT, fn () => app(AbsenceIncidents::class)->summary($this->employment, '2026-06-01', '2026-06-14'));
    expect($port['fingerprint'])->toBe($r->json('data.fingerprint'));
});

test('AUS-6 · el cierre congela el resumen y un cambio posterior se informa como diferencia', function (): void {
    $type = TenancyScope::runAs(APS_TENANT, fn () => apsType('unpaid', ['is_paid' => false]));
    $close = apsCall($this->hr, 'POST', '/absence-summaries/close', ['from' => '2026-06-01', 'to' => '2026-06-14', 'employment_ids' => [$this->employment]])->assertOk();
    test()->assertMatchesOpenApi($close, 'post', '/absence-summaries/close', 200);
    expect($close->json('data.closed'))->toBe(1);
    // Volver a cerrar sin cambios no agrega nada.
    expect(apsCall($this->hr, 'POST', '/absence-summaries/close', ['from' => '2026-06-01', 'to' => '2026-06-14', 'employment_ids' => [$this->employment]])->json('data.unchanged'))->toBe(1);
    expect(apsCall($this->hr, 'GET', "/employments/{$this->employment}/absence-summary?from=2026-06-01&to=2026-06-14")->json('data.closing.changed'))->toBeFalse();

    TenancyScope::runAs(APS_TENANT, fn () => apsAbsence($this->employment, $type, '2026-06-09', '2026-06-09', ['days' => 1]));
    $r = apsCall($this->hr, 'GET', "/employments/{$this->employment}/absence-summary?from=2026-06-01&to=2026-06-14")->assertOk();
    expect($r->json('data.closing.changed'))->toBeTrue()
        ->and(collect($r->json('data.closing.days'))->pluck('date')->all())->toBe(['2026-06-09'])
        ->and($r->json('data.closing.days.0.before.kind'))->toBe('worked')
        ->and($r->json('data.closing.days.0.after.kind'))->toBe('absence')
        ->and($r->json('data.closing.types.0.days_delta'))->toBe(1);

    // El cierre es de solo inserción.
    expect(fn () => TenancyScope::runAs(APS_TENANT, fn () => DB::table('absence_period_closings')->update(['fingerprint' => 'x'])))->toThrow(Exception::class);
});

test('AUS-6 · fechas inválidas 422, otra organización 404 y cerrar sin permiso 403', function (): void {
    $r = apsCall($this->hr, 'GET', "/employments/{$this->employment}/absence-summary?from=2026-06-14&to=2026-06-01")->assertStatus(422);
    test()->assertMatchesOpenApi($r, 'get', '/employments/{employment_id}/absence-summary', 422);
    apsCall($this->hr, 'GET', "/employments/{$this->employment}/absence-summary?from=2026-01-01&to=2026-12-31")->assertStatus(422);
    apsCall($this->other, 'GET', "/employments/{$this->employment}/absence-summary?from=2026-06-01&to=2026-06-14")->assertNotFound();
    apsCall($this->employee, 'POST', '/absence-summaries/close', ['from' => '2026-06-01', 'to' => '2026-06-14'])->assertForbidden();
});

test('AUS-5 · fuera de la jornada lo no urgente espera al teletrabajador; dentro de la jornada o sin teletrabajo sale ya', function (): void {
    $user = TenancyScope::runAs(APS_TENANT, function (): User {
        $person = DB::table('employments')->where('id', $this->employment)->value('person_id');
        $u = apsUser(APS_TENANT, 'empleado');
        DB::table('users')->where('id', $u->id)->update(['person_id' => $person]);
        foreach (['jornada.hora_inicio' => '"09:00"', 'jornada.hora_fin' => '"18:00"'] as $code => $value) {
            DB::table('tenant_settings')->insert(['tenant_id' => APS_TENANT, 'parameter_code' => $code, 'value' => $value, 'valid_from' => '2020-01-01']);
        }

        return $u;
    });
    $window = fn (string $utc) => TenancyScope::runAs(APS_TENANT, fn () => app(DisconnectionWindow::class)->resumeAt((string) $user->id, CarbonImmutable::parse($utc)));

    // Sin acuerdo de teletrabajo no hay desconexión que respetar.
    expect($window('2026-06-01 23:00:00'))->toBeNull();

    TenancyScope::runAs(APS_TENANT, fn () => DB::table('remote_work_agreements')->insert([
        'tenant_id' => APS_TENANT, 'employment_id' => $this->employment, 'modality' => 'total', 'starts_on' => '2026-01-01',
        'status' => 'active', 'disconnection_hours' => 12, 'signed_at' => now(), 'signature_origin' => 'physical_scan',
    ]));
    // Lunes 1 de junio, 18:00 en Lima (23:00 UTC): espera al martes 09:00 en Lima (14:00 UTC).
    expect($window('2026-06-01 23:00:00')?->toIso8601ZuluString())->toBe('2026-06-02T14:00:00Z');
    // Dentro de la jornada (lunes 10:00 en Lima) sale ya.
    expect($window('2026-06-01 15:00:00'))->toBeNull();
    // Viernes 5, 20:00 en Lima: el fin de semana es desconexión completa; espera al lunes 8 a las 09:00.
    expect($window('2026-06-06 01:00:00')?->toIso8601ZuluString())->toBe('2026-06-08T14:00:00Z');
});
