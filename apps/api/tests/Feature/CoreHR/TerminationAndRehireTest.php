<?php

/**
 * COL-11 · Registro del cese (ME-01): el cese no borra nada; con fecha futura la relación queda «En proceso
 * de cese» y rige ese día; al regir se cierra lo que tenía abierto y la posición queda vacante; se revierte
 * antes de la fecha. RN-COL-09 (periodo cerrado) y RN-COL-10 (pendientes que advierten o bloquean).
 * COL-10 · Reingreso y relaciones simultáneas: el reingreso reutiliza la persona y crea una relación nueva
 * con su propia compensación y contrato; la anterior queda intacta y consultable.
 */

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Organization\Infrastructure\Models\JobProfile;
use App\Modules\Organization\Infrastructure\Models\LegalEntity;
use App\Modules\Organization\Infrastructure\Models\OrgUnit;
use App\Modules\Organization\Infrastructure\Models\Position;
use App\Modules\Organization\Infrastructure\Models\Site;
use App\Shared\Permissions\ModuleGate;
use App\Shared\Permissions\PermissionResolver;
use App\Shared\Settings\ParameterResolver;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\IdentityFixtures;

uses(DatabaseTransactions::class);

const CES_TENANT = 'aaaaaaaa-0c11-4000-8000-00000000000a';
const CES_OTHER = 'bbbbbbbb-0c11-4000-8000-00000000000b';

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requiere PostgreSQL.');
    }
    TenancyScope::clear();
    foreach ([CES_TENANT => 'ces-org', CES_OTHER => 'ces-otra'] as $id => $slug) {
        DB::table('tenants')->insertOrIgnore(['id' => $id, 'slug' => $slug, 'name' => 'Grupo '.$slug, 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN']);
        DB::select('SELECT seed_tenant_defaults(?)', [$id]);
        TenancyScope::runAs($id, fn () => IdentityFixtures::enableAllModules());
    }
    $this->admin = cesUser(CES_TENANT, 'admin_empresa');
    TenancyScope::runAs(CES_TENANT, function (): void {
        $make = function (): array {
            $entity = LegalEntity::factory()->create();
            DB::table('legal_entity_regimes')->where('legal_entity_id', $entity->id)->update(['valid_from' => '2020-01-01']);

            return [
                'entity' => $entity->id,
                'unit' => OrgUnit::factory()->create(['legal_entity_id' => $entity->id])->id,
                'site' => Site::factory()->create(['legal_entity_id' => $entity->id])->id,
            ];
        };
        $this->a = $make();
        $this->b = $make();
        $this->profile = JobProfile::factory()->create()->id;
    });
    $options = cesCall($this->admin, 'get', '/employment-options')->assertOk();
    $this->indefinite = collect($options->json('data.contract_types'))->firstWhere('code', 'indeterminado')['id'];
    $reasons = cesCall($this->admin, 'get', '/termination-reasons');
    $this->assertMatchesOpenApi($reasons, 'get', '/termination-reasons', 200);
    $this->resignation = collect($reasons->json('data'))->firstWhere('code', 'resignation')['id'];
    $this->dismissal = collect($reasons->json('data'))->firstWhere('code', 'dismissal')['id'];
});

afterEach(fn () => TenancyScope::clear());

function cesUser(string $tenant, string $role): User
{
    return TenancyScope::runAs($tenant, function () use ($role): User {
        $u = User::factory()->create();
        $u->roleAssignments()->create(['role_id' => Role::query()->where('system_role_code', $role)->value('id')]);

        return $u;
    });
}

/**
 * @param  array<string, mixed>  $body
 */
function cesCall(User $user, string $method, string $path, array $body = []): TestResponse
{
    TenancyScope::clear();
    app('auth')->forgetGuards();
    app(PermissionResolver::class)->forget();
    app(ModuleGate::class)->forget();

    return test()->actingAs($user, 'ayni')
        ->withHeaders(['X-Tenant-Id' => $user->tenant_id, 'Idempotency-Key' => (string) Str::uuid()])
        ->json($method, '/api/v1'.$path, $body);
}

/**
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function cesHire(string $company = 'a', array $extra = []): array
{
    $t = test();
    $c = $t->{$company};

    return array_replace([
        'person' => ['first_names' => 'Rosa', 'paternal_surname' => 'Quispe', 'document_type' => 'dni', 'document_number' => (string) random_int(10000000, 89999999)],
        'legal_entity_id' => $c['entity'],
        'hire_date' => '2026-01-05',
        'job_profile_id' => $t->profile,
        'org_unit_id' => $c['unit'],
        'site_id' => $c['site'],
        'contract_type_id' => $t->indefinite,
        'base_salary' => ['amount' => '3000.00', 'currency' => 'PEN'],
    ], $extra);
}

function cesCount(string $event, string $aggregate): int
{
    return TenancyScope::runAs(CES_TENANT, fn () => DB::table('outbox_events')->where('event_type', $event)->where('aggregate_id', $aggregate)->count());
}

test('COL-11 · los motivos de cese vienen de la Tabla 17 del paquete Perú, sin el código 99', function (): void {
    $reasons = collect(cesCall($this->admin, 'get', '/termination-reasons')->json('data'));
    expect($reasons->pluck('statutory_code')->all())->toContain('01', '03', '07', '08', '09')
        ->and($reasons->pluck('code')->all())->not->toContain('no_link_plame')
        ->and($reasons->firstWhere('code', 'resignation')['initiated_by'])->toBe('employee')
        ->and($reasons->firstWhere('code', 'mutual_agreement')['initiated_by'])->toBe('mutual');
    // Pedirlos otra vez no los duplica.
    cesCall($this->admin, 'get', '/termination-reasons')->assertOk();
    expect(TenancyScope::runAs(CES_TENANT, fn () => DB::table('termination_reasons')->where('code', 'resignation')->count()))->toBe(1);
});

test('COL-11 · un cese con fecha futura no altera la relación hasta ese día; ese día rige y cierra lo abierto', function (): void {
    $id = (string) cesCall($this->admin, 'post', '/employments', cesHire())->json('data.id');
    $date = now()->addDays(10)->toDateString();

    $res = cesCall($this->admin, 'post', "/employments/{$id}/terminate", [
        'termination_reason_id' => $this->resignation, 'termination_date' => $date, 'notice_date' => now()->toDateString(), 'detail' => 'Se muda de ciudad',
    ]);
    $this->assertMatchesOpenApi($res, 'post', '/employments/{id}/terminate', 200);
    expect($res->json('data.status'))->toBe('terminating')
        ->and($res->json('data.termination_date'))->toBe($date)
        ->and($res->json('data.allowed_actions'))->toBe(['cancel-termination'])
        ->and($res->json('data.termination.reason_code'))->toBe('resignation')
        ->and($res->json('data.termination.statutory_code'))->toBe('01')
        ->and($res->json('data.termination.initiated_by'))->toBe('employee')
        ->and($res->json('data.termination.detail'))->toBe('Se muda de ciudad')
        // El preaviso de renuncia es un parámetro legal por validar: se advierte, no se bloquea.
        ->and(collect($res->json('meta.warnings'))->pluck('code')->all())->toContain('notice_rule_pending')
        ->and(cesCount('offboarding.started.v1', $id))->toBe(1)
        ->and(cesCount('employee.terminated.v1', $id))->toBe(0);
    // Hasta la fecha la asignación sigue abierta.
    expect(TenancyScope::runAs(CES_TENANT, fn () => DB::table('employment_assignments')->where('employment_id', $id)->whereNull('valid_to')->count()))->toBe(1);

    $this->travelTo(now()->addDays(10)->setTime(1, 0));
    Artisan::call('employments:apply-terminations');
    $after = cesCall($this->admin, 'get', "/employments/{$id}");
    expect($after->json('data.status'))->toBe('terminated')
        ->and($after->json('data.allowed_actions'))->toBe([])
        ->and($after->json('data.termination.status'))->toBe('executed')
        ->and(cesCount('employee.terminated.v1', $id))->toBe(1);
    TenancyScope::runAs(CES_TENANT, function () use ($id, $date): void {
        foreach (['employment_assignments', 'employment_compensations', 'employment_labor_regimes'] as $table) {
            expect(DB::table($table)->where('employment_id', $id)->value('valid_to'))->toBe($date);
        }
        expect(DB::table('contracts')->where('employment_id', $id)->value('status'))->toBe('cancelled')
            ->and(DB::table('employment_status_events')->where('employment_id', $id)->orderBy('created_at')->pluck('to_status')->all())->toBe(['active', 'terminating', 'terminated']);
    });
    // Volver a correr la tarea no repite el efecto.
    Artisan::call('employments:apply-terminations');
    expect(cesCount('employee.terminated.v1', $id))->toBe(1);
});

test('COL-11 · un cese con fecha pasada rige al registrarlo: nada se borra y la posición queda vacante', function (): void {
    $position = TenancyScope::runAs(CES_TENANT, fn () => Position::factory()->create([
        'legal_entity_id' => $this->a['entity'], 'job_profile_id' => $this->profile, 'org_unit_id' => $this->a['unit'], 'site_id' => $this->a['site'], 'status' => 'open',
    ])->id);
    $id = (string) cesCall($this->admin, 'post', '/employments', cesHire('a', ['position_id' => $position]))->json('data.id');
    expect(TenancyScope::runAs(CES_TENANT, fn () => DB::table('positions')->where('id', $position)->value('status')))->toBe('filled');

    $date = now()->subDay()->toDateString();
    $res = cesCall($this->admin, 'post', "/employments/{$id}/terminate", ['termination_reason_id' => $this->dismissal, 'termination_date' => $date, 'detail' => 'Abandono de trabajo']);
    $this->assertMatchesOpenApi($res, 'post', '/employments/{id}/terminate', 200);
    expect($res->json('data.status'))->toBe('terminated')
        ->and($res->json('data.termination.initiated_by'))->toBe('employer')
        ->and(cesCount('employee.terminated.v1', $id))->toBe(1);
    TenancyScope::runAs(CES_TENANT, function () use ($id, $position, $date): void {
        expect(DB::table('positions')->where('id', $position)->value('status'))->toBe('open')
            ->and(DB::table('employments')->where('id', $id)->whereNull('deleted_at')->exists())->toBeTrue()
            ->and(DB::table('employment_assignments')->where('employment_id', $id)->value('position_id'))->toBe($position)
            ->and(DB::table('employment_assignments')->where('employment_id', $id)->value('valid_to'))->toBe($date)
            ->and(DB::table('terminations')->where('employment_id', $id)->value('status'))->toBe('executed');
        // La auditoría no copia el detalle (sensible).
        $audit = DB::table('audit_events')->where('entity_id', $id)->where('action', 'employment.terminate')->first();
        expect($audit)->not->toBeNull()->and((string) $audit->after_data)->not->toContain('Abandono');
    });
    // La relación sigue consultable.
    cesCall($this->admin, 'get', "/employments/{$id}")->assertOk();
});

test('COL-11 · revertir el cese antes de la fecha exige motivo y devuelve la relación a Activa', function (): void {
    $id = (string) cesCall($this->admin, 'post', '/employments', cesHire())->json('data.id');
    cesCall($this->admin, 'post', "/employments/{$id}/terminate", ['termination_reason_id' => $this->dismissal, 'termination_date' => now()->addDays(20)->toDateString()])->assertOk();

    $noReason = cesCall($this->admin, 'post', "/employments/{$id}/cancel-termination", []);
    $this->assertMatchesOpenApi($noReason, 'post', '/employments/{id}/cancel-termination', 422);

    $ok = cesCall($this->admin, 'post', "/employments/{$id}/cancel-termination", ['reason' => 'Se retractó y la empresa aceptó']);
    $this->assertMatchesOpenApi($ok, 'post', '/employments/{id}/cancel-termination', 200);
    expect($ok->json('data.status'))->toBe('active')
        ->and($ok->json('data.termination_date'))->toBeNull()
        ->and($ok->json('data.termination'))->toBeNull()
        ->and(cesCount('offboarding.cancelled.v1', $id))->toBe(1)
        ->and(TenancyScope::runAs(CES_TENANT, fn () => DB::table('terminations')->where('employment_id', $id)->value('status')))->toBe('cancelled');

    $again = cesCall($this->admin, 'post', "/employments/{$id}/cancel-termination", ['reason' => 'Otra vez']);
    $this->assertMatchesOpenApi($again, 'post', '/employments/{id}/cancel-termination', 409);
    expect($again->json('error.code'))->toBe('invalid_transition');
});

test('COL-11 · transiciones inválidas, datos inválidos, permiso y aislamiento', function (): void {
    $pending = (string) cesCall($this->admin, 'post', '/employments', cesHire('a', ['hire_date' => now()->addDays(5)->toDateString()]))->json('data.id');
    $bad = cesCall($this->admin, 'post', "/employments/{$pending}/terminate", ['termination_reason_id' => $this->dismissal, 'termination_date' => now()->addDays(9)->toDateString()]);
    $this->assertMatchesOpenApi($bad, 'post', '/employments/{id}/terminate', 409);
    expect($bad->json('error.code'))->toBe('invalid_transition');

    $id = (string) cesCall($this->admin, 'post', '/employments', cesHire())->json('data.id');
    $invalid = cesCall($this->admin, 'post', "/employments/{$id}/terminate", ['termination_date' => '2026-02-01']);
    $this->assertMatchesOpenApi($invalid, 'post', '/employments/{id}/terminate', 422);
    $beforeHire = cesCall($this->admin, 'post', "/employments/{$id}/terminate", ['termination_reason_id' => $this->dismissal, 'termination_date' => '2025-12-31']);
    $this->assertMatchesOpenApi($beforeHire, 'post', '/employments/{id}/terminate', 422);

    $viewer = IdentityFixtures::userWithPermissions(CES_TENANT, ['people.employments.view']);
    $forbidden = cesCall($viewer, 'post', "/employments/{$id}/terminate", ['termination_reason_id' => $this->dismissal, 'termination_date' => '2026-02-01']);
    $this->assertMatchesOpenApi($forbidden, 'post', '/employments/{id}/terminate', 403);
    cesCall($viewer, 'get', '/termination-reasons')->assertForbidden();

    $outsider = cesUser(CES_OTHER, 'admin_empresa');
    $hidden = cesCall($outsider, 'post', "/employments/{$id}/terminate", ['termination_reason_id' => $this->dismissal, 'termination_date' => '2026-02-01']);
    $this->assertMatchesOpenApi($hidden, 'post', '/employments/{id}/terminate', 404);

    // Quien ve la relación sin poder cesar no recibe el detalle del cese.
    cesCall($this->admin, 'post', "/employments/{$id}/terminate", ['termination_reason_id' => $this->dismissal, 'termination_date' => now()->addDays(3)->toDateString(), 'detail' => 'Falta grave'])->assertOk();
    $seen = cesCall($viewer, 'get', "/employments/{$id}");
    expect($seen->json('data.termination.reason_code'))->toBe('dismissal')
        ->and(array_key_exists('detail', (array) $seen->json('data.termination')))->toBeFalse();
});

test('COL-11 · RN-COL-09 rechaza un cese dentro de un periodo de planilla cerrado', function (): void {
    $id = (string) cesCall($this->admin, 'post', '/employments', cesHire())->json('data.id');
    TenancyScope::runAs(CES_TENANT, function (): void {
        $group = DB::table('payroll_groups')->insertGetId(['tenant_id' => CES_TENANT, 'code' => 'MENS', 'name' => 'Mensual', 'legal_entity_id' => $this->a['entity'], 'currency' => 'PEN']);
        DB::table('payroll_periods')->insert(['tenant_id' => CES_TENANT, 'payroll_group_id' => $group, 'period_year' => 2026, 'period_number' => 3,
            'starts_on' => '2026-03-01', 'ends_on' => '2026-03-31', 'status' => 'closed']);
    });
    $closed = cesCall($this->admin, 'post', "/employments/{$id}/terminate", ['termination_reason_id' => $this->dismissal, 'termination_date' => '2026-03-15']);
    $this->assertMatchesOpenApi($closed, 'post', '/employments/{id}/terminate', 422);
    expect($closed->json('error.code'))->toBe('closed_period');
});

test('COL-11 · RN-COL-10 los pendientes advierten y, si la organización lo exige, bloquean', function (): void {
    $id = (string) cesCall($this->admin, 'post', '/employments', cesHire())->json('data.id');
    $date = now()->addDays(15)->toDateString();
    TenancyScope::runAs(CES_TENANT, fn () => DB::table('vacation_requests')->insert(['tenant_id' => CES_TENANT, 'employment_id' => $id,
        'starts_on' => now()->addDays(30)->toDateString(), 'ends_on' => now()->addDays(34)->toDateString(), 'days' => 5, 'status' => 'approved']));

    TenancyScope::runAs(CES_TENANT, fn () => app(ParameterResolver::class)->set('colaboradores.cese_bloquea_pendientes', true, 'organization', null, null, 'Prueba'));
    $blocked = cesCall($this->admin, 'post', "/employments/{$id}/terminate", ['termination_reason_id' => $this->dismissal, 'termination_date' => $date]);
    $this->assertMatchesOpenApi($blocked, 'post', '/employments/{id}/terminate', 422);
    expect($blocked->json('error.code'))->toBe('pending_items');

    TenancyScope::runAs(CES_TENANT, fn () => app(ParameterResolver::class)->set('colaboradores.cese_bloquea_pendientes', false, 'organization', null, null, 'Prueba'));
    $warned = cesCall($this->admin, 'post', "/employments/{$id}/terminate", ['termination_reason_id' => $this->dismissal, 'termination_date' => $date]);
    expect($warned->json('data.status'))->toBe('terminating')
        ->and(collect($warned->json('meta.warnings'))->pluck('code')->all())->toBe(['pending_vacations']);
});

test('COL-10 · el reingreso reutiliza la persona, crea una relación nueva y deja la anterior intacta y consultable', function (): void {
    $first = cesCall($this->admin, 'post', '/employments', cesHire());
    $old = (string) $first->json('data.id');
    $person = (string) $first->json('data.person_id');
    cesCall($this->admin, 'post', "/employments/{$old}/terminate", ['termination_reason_id' => $this->resignation, 'termination_date' => '2026-03-31'])->assertOk();
    $snapshot = TenancyScope::runAs(CES_TENANT, fn () => [
        DB::table('employments')->where('id', $old)->first(['status', 'termination_date', 'lock_version']),
        DB::table('employment_compensations')->where('employment_id', $old)->get(['base_salary', 'valid_from', 'valid_to'])->all(),
    ]);

    $rehire = cesCall($this->admin, 'post', '/employments', cesHire('a', ['person' => null, 'person_id' => $person, 'hire_date' => '2026-05-04',
        'base_salary' => ['amount' => '3800.00', 'currency' => 'PEN']]));
    $this->assertMatchesOpenApi($rehire, 'post', '/employments', 201);
    $new = (string) $rehire->json('data.id');
    expect($rehire->json('data.rehire_of_employment_id'))->toBe($old)
        ->and($rehire->json('data.person_id'))->toBe($person)
        ->and($rehire->json('data.base_salary'))->toBe(['amount' => '3800.00', 'currency' => 'PEN'])
        ->and(cesCount('employee.rehired.v1', $new))->toBe(1);

    TenancyScope::runAs(CES_TENANT, function () use ($old, $new, $snapshot): void {
        // La relación anterior no cambia: ni su estado ni su historia salarial.
        expect(DB::table('employments')->where('id', $old)->first(['status', 'termination_date', 'lock_version']))->toEqual($snapshot[0])
            ->and(DB::table('employment_compensations')->where('employment_id', $old)->get(['base_salary', 'valid_from', 'valid_to'])->all())->toEqual($snapshot[1])
            // Cada relación tiene su propia compensación y su propio contrato.
            ->and(DB::table('employment_compensations')->where('employment_id', $new)->count())->toBe(1)
            ->and(DB::table('contracts')->where('employment_id', $new)->count())->toBe(1)
            ->and(DB::table('contracts')->where('employment_id', $old)->count())->toBe(1)
            ->and(DB::table('people')->count())->toBe(1);
    });

    $oldView = cesCall($this->admin, 'get', "/employments/{$old}");
    $this->assertMatchesOpenApi($oldView, 'get', '/employments/{id}', 200);
    expect($oldView->json('data.status'))->toBe('terminated')
        ->and($oldView->json('data.base_salary.amount'))->toBe('3000.00')
        ->and(collect($oldView->json('data.other_employments'))->pluck('id')->all())->toBe([$new]);
    expect(collect(cesCall($this->admin, 'get', "/employments/{$new}")->json('data.other_employments'))->pluck('id')->all())->toBe([$old]);
});

test('COL-10 · relaciones simultáneas con otra empresa del grupo, salvo que la organización las apague', function (): void {
    $first = cesCall($this->admin, 'post', '/employments', cesHire());
    $person = (string) $first->json('data.person_id');

    $second = cesCall($this->admin, 'post', '/employments', cesHire('b', ['person' => null, 'person_id' => $person, 'hire_date' => '2026-02-01']));
    $this->assertMatchesOpenApi($second, 'post', '/employments', 201);
    expect($second->json('data.rehire_of_employment_id'))->toBeNull()
        ->and($second->json('data.legal_entity_id'))->toBe($this->b['entity'])
        ->and(collect($second->json('data.other_employments'))->pluck('id')->all())->toBe([$first->json('data.id')]);

    // Con la misma empresa nunca (RN-COL-02).
    $same = cesCall($this->admin, 'post', '/employments', cesHire('b', ['person' => null, 'person_id' => $person, 'hire_date' => '2026-03-01']));
    expect($same->json('error.code'))->toBe('employment_overlap');

    $other = cesCall($this->admin, 'post', '/employments', cesHire())->json('data.person_id');
    TenancyScope::runAs(CES_TENANT, fn () => app(ParameterResolver::class)->set('colaboradores.relaciones_simultaneas', false, 'organization', null, null, 'Prueba'));
    $denied = cesCall($this->admin, 'post', '/employments', cesHire('b', ['person' => null, 'person_id' => $other, 'hire_date' => '2026-02-01']));
    $this->assertMatchesOpenApi($denied, 'post', '/employments', 409);
    expect($denied->json('error.code'))->toBe('simultaneous_not_allowed');
});

test('COL-10 · el reingreso puede registrarse mientras el cese anterior aún no rige si empieza después', function (): void {
    $first = cesCall($this->admin, 'post', '/employments', cesHire());
    $old = (string) $first->json('data.id');
    $end = now()->addDays(10)->toDateString();
    cesCall($this->admin, 'post', "/employments/{$old}/terminate", ['termination_reason_id' => $this->dismissal, 'termination_date' => $end])->assertOk();

    $overlap = cesCall($this->admin, 'post', '/employments', cesHire('a', ['person' => null, 'person_id' => $first->json('data.person_id'), 'hire_date' => $end]));
    expect($overlap->json('error.code'))->toBe('employment_overlap');

    $rehire = cesCall($this->admin, 'post', '/employments', cesHire('a', ['person' => null, 'person_id' => $first->json('data.person_id'), 'hire_date' => now()->addDays(20)->toDateString()]));
    $this->assertMatchesOpenApi($rehire, 'post', '/employments', 201);
    expect($rehire->json('data.status'))->toBe('pending')
        ->and($rehire->json('data.rehire_of_employment_id'))->toBe($old);
});
