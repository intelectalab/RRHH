<?php

/**
 * HIS-3 · Compensación con vigencia: el cambio de sueldo se registra con fecha efectiva y motivo y el
 * anterior queda en la historia (RN-HIS-01); ver y editar exigen permisos por campo distintos; cada cambio
 * queda auditado con valor anterior y nuevo; la remuneración mínima es un parámetro legal con vigencia;
 * la banda de la posición es referencia; el aumento pasa por el flujo de aprobación de salary_change.
 */

use App\Modules\CoreHR\Infrastructure\Models\Compensation;
use App\Modules\CoreHR\Infrastructure\Models\Employment;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Organization\Infrastructure\Models\Position;
use App\Modules\Organization\Infrastructure\Models\Site;
use App\Shared\LegalParameters\LegalParameters;
use App\Shared\Outbox\DomainEventTypes;
use App\Shared\Permissions\ModuleGate;
use App\Shared\Permissions\PermissionResolver;
use App\Shared\Settings\ParameterResolver;
use App\Shared\Tenancy\TenancyScope;
use Brick\Math\BigDecimal;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\IdentityFixtures;

uses(DatabaseTransactions::class);

const H3_TENANT = 'aaaaaaaa-0c03-4000-8000-0000000000a3';
const H3_OTHER = 'bbbbbbbb-0c03-4000-8000-0000000000b3';

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requiere PostgreSQL.');
    }
    TenancyScope::clear();
    app(LegalParameters::class)->sync('PE');
    foreach ([H3_TENANT => 'h3-org', H3_OTHER => 'h3-otra'] as $id => $slug) {
        DB::table('tenants')->insertOrIgnore(['id' => $id, 'slug' => $slug, 'name' => 'Grupo '.$slug, 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN']);
        DB::select('SELECT seed_tenant_defaults(?)', [$id]);
        TenancyScope::runAs($id, fn () => IdentityFixtures::enableAllModules());
    }
    $this->admin = h3User(H3_TENANT, 'admin_empresa');
    $this->approver = h3User(H3_TENANT, 'admin_empresa');
    $this->payroll = h3User(H3_TENANT, 'payroll');
    $this->rrhh = h3User(H3_TENANT, 'rrhh');
    $this->employment = TenancyScope::runAs(H3_TENANT, fn (): Employment => Employment::factory()->create(['hire_date' => '2025-01-01']));
    TenancyScope::runAs(H3_TENANT, fn () => Compensation::factory()->create(['employment_id' => $this->employment->id, 'base_salary' => '3000.00', 'valid_from' => '2025-01-01']));
    $this->emp = '/employments/'.$this->employment->id;
});

afterEach(fn () => TenancyScope::clear());

function h3User(string $tenant, string $role): User
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
function h3Call(User $user, string $method, string $path, array $body = []): TestResponse
{
    TenancyScope::clear();
    app('auth')->forgetGuards();
    app(PermissionResolver::class)->forget();
    app(ModuleGate::class)->forget();

    return test()->actingAs($user, 'ayni')
        ->withHeaders(['X-Tenant-Id' => $user->tenant_id, 'Idempotency-Key' => (string) Str::uuid(), 'Accept' => 'application/json'])
        ->json($method, '/api/v1'.$path, $body);
}

function h3Direct(): void
{
    TenancyScope::runAs(H3_TENANT, fn () => app(ParameterResolver::class)->set('compensacion.cambio_requiere_aprobacion', false, 'organization', null, null, 'Pruebas'));
}

/**
 * @return array<string, mixed>
 */
function h3Raise(string $amount = '3600.00', string $date = '2026-03-01', array $extra = []): array
{
    return array_merge(['base_salary' => ['amount' => $amount, 'currency' => 'PEN'], 'effective_on' => $date, 'reason' => 'merit'], $extra);
}

function h3Flow(User $approver): void
{
    $flow = h3Call($approver, 'POST', '/approval-flows', ['name' => 'Sueldos', 'subject_type' => 'salary_change',
        'steps' => ['levels' => [['mode' => 'sequential', 'approvers' => [['kind' => 'user', 'user_id' => $approver->id]]]]]]);
    $flow->assertCreated();
    h3Call($approver, 'POST', '/approval-flows/'.$flow->json('data.id').'/publish')->assertOk();
}

test('HIS-3 · un aumento con fecha efectiva y motivo cierra el sueldo anterior el día previo y queda en la historia, auditado con antes y después', function (): void {
    h3Direct();
    $res = h3Call($this->admin, 'POST', $this->emp.'/compensation-changes', h3Raise('3600.00', '2026-03-01', [
        'components' => [['concept_code' => 'movilidad', 'amount' => ['amount' => '200', 'currency' => 'PEN']]],
    ]))->assertCreated();
    $this->assertMatchesOpenApi($res, 'post', '/employments/{employment_id}/compensation-changes', 201);
    expect($res->json('data.status'))->toBe('applied')
        ->and($res->json('data.base_salary'))->toBe(['amount' => '3600.00', 'currency' => 'PEN'])
        ->and($res->json('data.previous_salary'))->toBe(['amount' => '3000.00', 'currency' => 'PEN'])
        ->and($res->json('data.compensation_id'))->not->toBeNull();

    $history = h3Call($this->admin, 'GET', $this->emp.'/compensations')->assertOk();
    $this->assertMatchesOpenApi($history, 'get', '/employments/{employment_id}/compensations', 200);
    expect($history->json('data'))->toHaveCount(2)
        ->and($history->json('data.0.valid_from'))->toBe('2026-03-01')
        ->and($history->json('data.0.change_reason'))->toBe('merit')
        ->and($history->json('data.0.components.0.concept_code'))->toBe('movilidad')
        ->and($history->json('data.1.valid_to'))->toBe('2026-02-28')
        ->and($history->json('data.1.base_salary.amount'))->toBe('3000.00');

    // RN-HIS-09: a cada fecha, el sueldo vigente ese día.
    expect(h3Call($this->admin, 'GET', $this->emp.'/compensations?as_of=2026-02-15')->json('data.0.base_salary.amount'))->toBe('3000.00')
        ->and(h3Call($this->admin, 'GET', $this->emp.'/compensations?as_of=2026-03-01')->json('data.0.base_salary.amount'))->toBe('3600.00');
    $one = h3Call($this->admin, 'GET', $this->emp.'/compensations/'.$res->json('data.compensation_id'))->assertOk();
    $this->assertMatchesOpenApi($one, 'get', '/employments/{employment_id}/compensations/{id}', 200);

    TenancyScope::runAs(H3_TENANT, function () use ($res): void {
        $audit = DB::table('audit_events')->where('action', 'compensation.changed')->where('entity_id', $res->json('data.compensation_id'))->first();
        expect($audit)->not->toBeNull()->and($audit->before_data)->not->toBeNull()->and($audit->after_data)->not->toBeNull()->and($audit->changed_fields)->toContain('base_salary');
        $event = DB::table('outbox_events')->where('event_type', DomainEventTypes::EMPLOYEE_COMPENSATION_CHANGED)->where('aggregate_id', $this->employment->id)->first();
        expect($event)->not->toBeNull()->and(json_decode((string) $event->payload, true)['effective_from'])->toBe('2026-03-01');
        // Nada se sobrescribe: dos filas, la anterior cerrada.
        expect(DB::table('employment_compensations')->where('employment_id', $this->employment->id)->count())->toBe(2);
    });
});

test('HIS-3 · RN-HIS-08: con aprobación requerida el aumento espera el flujo; al aprobarse se registra y queda enlazado a su origen', function (): void {
    h3Flow($this->approver);
    $res = h3Call($this->admin, 'POST', $this->emp.'/compensation-changes', h3Raise())->assertCreated();
    expect($res->json('data.status'))->toBe('pending_approval')->and($res->json('data.approval_request_id'))->not->toBeNull();
    expect(h3Call($this->admin, 'GET', $this->emp.'/compensations')->json('data'))->toHaveCount(1);

    h3Call($this->approver, 'POST', '/approval-requests/'.$res->json('data.approval_request_id').'/approve')->assertOk();
    $show = h3Call($this->admin, 'GET', $this->emp.'/compensation-changes/'.$res->json('data.id'))->assertOk();
    $this->assertMatchesOpenApi($show, 'get', '/employments/{employment_id}/compensation-changes/{id}', 200);
    expect($show->json('data.status'))->toBe('applied');
    $current = h3Call($this->admin, 'GET', $this->emp.'/compensations')->json('data.0');
    expect($current['base_salary']['amount'])->toBe('3600.00')->and($current['personnel_action_id'])->toBe($res->json('data.id'));

    $list = h3Call($this->admin, 'GET', $this->emp.'/compensation-changes')->assertOk();
    $this->assertMatchesOpenApi($list, 'get', '/employments/{employment_id}/compensation-changes', 200);
    expect($list->json('data'))->toHaveCount(1);
});

test('HIS-3 · cancelar un cambio pendiente anula la aprobación; cancelarlo de nuevo es una transición inválida (409)', function (): void {
    h3Flow($this->approver);
    $id = h3Call($this->admin, 'POST', $this->emp.'/compensation-changes', h3Raise())->json('data.id');
    $res = h3Call($this->admin, 'POST', $this->emp."/compensation-changes/{$id}/cancel", ['reason' => 'Se revisará en el ciclo anual'])->assertOk();
    $this->assertMatchesOpenApi($res, 'post', '/employments/{employment_id}/compensation-changes/{id}/cancel', 200);
    expect($res->json('data.status'))->toBe('cancelled');

    $again = h3Call($this->admin, 'POST', $this->emp."/compensation-changes/{$id}/cancel", ['reason' => 'Otra vez']);
    $this->assertMatchesOpenApi($again, 'post', '/employments/{employment_id}/compensation-changes/{id}/cancel', 409);
    expect($again->json('error.code'))->toBe('invalid_transition');
});

test('HIS-3 · la remuneración mínima vigente es un parámetro legal: un sueldo mensual menor se rechaza', function (): void {
    h3Direct();
    $rmv = TenancyScope::runAs(H3_TENANT, fn () => app(LegalParameters::class)->at('PE', 'rmv', '2026-03-01'));
    expect($rmv)->not->toBeNull();
    $below = (string) (BigDecimal::of((string) $rmv['value'])->minus(1));
    $res = h3Call($this->admin, 'POST', $this->emp.'/compensation-changes', h3Raise($below));
    $this->assertMatchesOpenApi($res, 'post', '/employments/{employment_id}/compensation-changes', 422);
    expect($res->json('error.code'))->toBe('below_minimum_wage');

    // En otra moneda no se compara (no hay tipo de cambio aquí) y se registra.
    h3Call($this->admin, 'POST', $this->emp.'/compensation-changes', ['base_salary' => ['amount' => '900', 'currency' => 'USD'], 'effective_on' => '2026-04-01', 'reason' => 'adjustment'])->assertCreated();
});

test('HIS-3 · la banda salarial de la posición es referencia: fuera de banda se advierte y se registra la posición relativa', function (): void {
    h3Direct();
    TenancyScope::runAs(H3_TENANT, function (): void {
        $band = (string) DB::table('salary_bands')->insertGetId(['tenant_id' => H3_TENANT, 'name' => 'Analistas', 'min_amount' => '2500', 'mid_amount' => '3000', 'max_amount' => '3500', 'currency' => 'PEN', 'valid_from' => '2025-01-01']);
        $position = Position::factory()->create(['salary_band_id' => $band, 'legal_entity_id' => $this->employment->legal_entity_id]);
        $site = Site::factory()->create();
        DB::table('employment_assignments')->insert(['tenant_id' => H3_TENANT, 'employment_id' => $this->employment->id, 'job_profile_id' => $position->job_profile_id,
            'position_id' => $position->id, 'org_unit_id' => $position->org_unit_id, 'site_id' => $site->id, 'valid_from' => '2025-01-01']);
    });
    $res = h3Call($this->admin, 'POST', $this->emp.'/compensation-changes', h3Raise('4000.00'))->assertCreated();
    expect($res->json('data.band_position'))->toBe('above')
        ->and(collect($res->json('meta.warnings'))->pluck('code')->all())->toContain('out_of_band');
});

test('HIS-3 · ver y editar la compensación exigen permisos por campo distintos; leer el sueldo queda en el registro de accesos', function (): void {
    // Sin people.employments.salary.edit no se pide un cambio aunque se tenga people.compensations.create.
    $creator = IdentityFixtures::userWithPermissions(H3_TENANT, ['people.employments.view', 'people.compensations.view', 'people.compensations.create']);
    $res = h3Call($creator, 'POST', $this->emp.'/compensation-changes', h3Raise());
    $this->assertMatchesOpenApi($res, 'post', '/employments/{employment_id}/compensation-changes', 403);

    // RRHH ve la historia pero no crea cambios.
    $this->assertMatchesOpenApi(h3Call($this->rrhh, 'POST', $this->emp.'/compensation-changes', h3Raise()), 'post', '/employments/{employment_id}/compensation-changes', 403);

    // Sin people.employments.salary.view el monto no viaja (no se enmascara en el cliente).
    $viewer = IdentityFixtures::userWithPermissions(H3_TENANT, ['people.employments.view', 'people.compensations.view']);
    $list = h3Call($viewer, 'GET', $this->emp.'/compensations')->assertOk();
    expect($list->json('data.0'))->not->toHaveKey('base_salary')->and($list->json('data.0.valid_from'))->toBe('2025-01-01');

    // Con el permiso de campo viaja y la lectura queda registrada.
    h3Call($this->payroll, 'GET', $this->emp.'/compensations')->assertOk()->assertJsonPath('data.0.base_salary.amount', '3000.00');
    TenancyScope::runAs(H3_TENANT, fn () => expect(DB::table('data_access_logs')->where('user_id', $this->payroll->id)->where('data_category', 'salary')->exists())->toBeTrue());

    // Sin permiso sobre compensaciones, 403.
    $manager = IdentityFixtures::userWithPermissions(H3_TENANT, ['people.employments.view']);
    $this->assertMatchesOpenApi(h3Call($manager, 'GET', $this->emp.'/compensations'), 'get', '/employments/{employment_id}/compensations', 403);
});

test('HIS-3 · datos inválidos responden 422 y una fecha anterior al ingreso no se acepta', function (): void {
    h3Direct();
    $res = h3Call($this->admin, 'POST', $this->emp.'/compensation-changes', ['base_salary' => ['amount' => 'abc', 'currency' => 'PEN'], 'reason' => 'bonus']);
    $this->assertMatchesOpenApi($res, 'post', '/employments/{employment_id}/compensation-changes', 422);
    $fields = collect($res->json('error.details'))->pluck('field')->all();
    expect($fields)->toContain('base_salary.amount')->toContain('effective_on')->toContain('reason');

    h3Call($this->admin, 'POST', $this->emp.'/compensation-changes', h3Raise('3600.00', '2024-12-01'))->assertStatus(422);
    h3Call($this->admin, 'POST', $this->emp.'/compensation-changes', h3Raise('3600.00', '2026-03-01', ['components' => [['concept_code' => 'gratificacion', 'amount' => ['amount' => '10']]]]))->assertStatus(422);
});

test('HIS-3 · dos cambios el mismo día en la misma relación: el segundo responde 409 (nada se sobrescribe)', function (): void {
    h3Direct();
    h3Call($this->admin, 'POST', $this->emp.'/compensation-changes', h3Raise('3600.00', '2026-03-01'))->assertCreated();
    $res = h3Call($this->admin, 'POST', $this->emp.'/compensation-changes', h3Raise('3700.00', '2026-03-01'));
    expect($res->status())->toBe(409);
});

test('HIS-3 · aislamiento y módulo: otra organización no ve la compensación (404) y sin Core HR responde module_not_enabled', function (): void {
    $other = h3User(H3_OTHER, 'admin_empresa');
    h3Call($other, 'GET', $this->emp.'/compensations')->assertNotFound();
    h3Call($other, 'POST', $this->emp.'/compensation-changes', h3Raise())->assertNotFound();

    IdentityFixtures::disableModule(H3_TENANT, 'core');
    $res = h3Call($this->admin, 'GET', $this->emp.'/compensations');
    $this->assertMatchesOpenApi($res, 'get', '/employments/{employment_id}/compensations', 403);
    expect($res->json('error.code'))->toBe('module_not_enabled');
});

test('HIS-3 · los conceptos fijos disponibles salen del paquete de país (solo ingresos que se registran, no los calculados)', function (): void {
    $res = h3Call($this->admin, 'GET', '/compensation-concepts')->assertOk();
    $this->assertMatchesOpenApi($res, 'get', '/compensation-concepts', 200);
    $codes = collect($res->json('data'))->pluck('code')->all();
    expect($codes)->toContain('movilidad')->not->toContain('gratificacion')->not->toContain('asig_familiar');
});
