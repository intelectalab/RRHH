<?php

/**
 * CON-4 · Alertas de vencimiento y de tope acumulado.
 *
 * - Lista de contratos por vencer (ventana configurable) y vencidos sin regularizar, con el acumulado de la
 *   persona en la empresa frente al tope y la acción sugerida; con alcance, permiso y aislamiento.
 * - Tarea diaria: aviso de vencimiento una vez por anticipación (contratos.alerta_vencimiento_dias, por
 *   empresa) y aviso de tope acumulado cercano (contratos.alerta_tope_acumulado_dias), con explicación.
 * - Renovar por encima del tope exige confirmación expresa con motivo, que queda auditada.
 * - Los vencimientos aparecen en el calendario central (CFG-8).
 */

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Organization\Infrastructure\Models\JobProfile;
use App\Modules\Organization\Infrastructure\Models\LegalEntity;
use App\Modules\Organization\Infrastructure\Models\OrgUnit;
use App\Modules\Organization\Infrastructure\Models\Site;
use App\Shared\LegalParameters\LegalParameters;
use App\Shared\Outbox\DomainEventTypes;
use App\Shared\Permissions\ModuleGate;
use App\Shared\Permissions\PermissionResolver;
use App\Shared\Settings\ParameterResolver;
use App\Shared\Tenancy\TenancyScope;
use App\Shared\Vigencia\EffectiveDated;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\IdentityFixtures;

uses(DatabaseTransactions::class);

const CAL_TENANT = 'aaaaaaaa-0c04-4000-8000-00000000000a';
const CAL_OTHER = 'bbbbbbbb-0c04-4000-8000-00000000000b';

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requiere PostgreSQL.');
    }
    Storage::fake('s3');
    TenancyScope::clear();
    app(LegalParameters::class)->sync('PE');
    foreach ([CAL_TENANT => 'cav-org', CAL_OTHER => 'cav-otra'] as $id => $slug) {
        DB::table('tenants')->insertOrIgnore(['id' => $id, 'slug' => $slug, 'name' => 'Grupo '.$slug, 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN']);
        DB::select('SELECT seed_tenant_defaults(?)', [$id]);
        TenancyScope::runAs($id, fn () => IdentityFixtures::enableAllModules());
    }
    $this->admin = cavUser(CAL_TENANT, 'admin_empresa');
    $this->other = cavUser(CAL_OTHER, 'admin_empresa');
    TenancyScope::runAs(CAL_TENANT, function (): void {
        $entity = LegalEntity::factory()->create();
        DB::table('legal_entity_regimes')->where('legal_entity_id', $entity->id)->update(['valid_from' => '2020-01-01']);
        $this->entity = $entity->id;
        $this->unit = OrgUnit::factory()->create(['legal_entity_id' => $entity->id])->id;
        $this->site = Site::factory()->create(['legal_entity_id' => $entity->id])->id;
        $this->profile = JobProfile::factory()->create()->id;
    });
    $types = collect(cavCall($this->admin, 'get', '/employment-options')->assertOk()->json('data.contract_types'));
    $this->reconversion = $types->firstWhere('code', 'reconversion_empresarial')['id'];
});

afterEach(fn () => TenancyScope::clear());

function cavUser(string $tenant, string $role): User
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
function cavCall(User $user, string $method, string $path, array $body = [], bool $multipart = false): TestResponse
{
    TenancyScope::clear();
    app('auth')->forgetGuards();
    app(PermissionResolver::class)->forget();
    app(ModuleGate::class)->forget();
    test()->flushHeaders();
    $test = test()->actingAs($user, 'ayni')->withHeaders(['X-Tenant-Id' => $user->tenant_id, 'Idempotency-Key' => (string) Str::uuid(), 'Accept' => 'application/json']);

    return $multipart ? $test->post('/api/v1'.$path, $body) : $test->json($method, '/api/v1'.$path, $body);
}

/**
 * Ingreso con un contrato a modalidad entre $from y $to, registrado firmado. Devuelve la relación y el contrato.
 *
 * @return array{employment: string, contract: string}
 */
function cavFixedTerm(string $from, string $to, string $signedOn): array
{
    $t = test();
    $employment = (string) cavCall($t->admin, 'post', '/employments', [
        'person' => ['first_names' => 'Luis', 'paternal_surname' => 'Huamán', 'document_type' => 'dni', 'document_number' => (string) random_int(10000000, 89999999)],
        'legal_entity_id' => $t->entity, 'hire_date' => $from, 'job_profile_id' => $t->profile, 'org_unit_id' => $t->unit, 'site_id' => $t->site,
        'contract_type_id' => $t->reconversion, 'contract_end_date' => $to, 'contract_objective_cause' => 'Reconversión de la línea de envasado',
        'base_salary' => ['amount' => '2800.00', 'currency' => 'PEN'],
    ])->assertCreated()->json('data.id');
    $contract = (string) cavCall($t->admin, 'get', '/employments/'.$employment.'/contracts')->assertOk()->json('data.0.id');
    cavCall($t->admin, 'post', '/contracts/'.$contract.'/register-signature', [
        'file' => UploadedFile::fake()->createWithContent('firmado.pdf', "%PDF-1.4\n% firmado\n"), 'signed_on' => $signedOn,
    ], true)->assertOk();

    return ['employment' => $employment, 'contract' => $contract];
}

function cavLegal(string $code, int $value, string $unit): void
{
    TenancyScope::clear();
    $current = DB::table('legal_parameters')->where('country_code', 'PE')->where('code', $code)->whereNull('valid_to')->first();
    app(EffectiveDated::class)->change('legal_parameters', ['country_code' => 'PE', 'labor_regime_code' => $current->labor_regime_code, 'code' => $code], [
        'name' => $current->name, 'unit' => $unit, 'value_numeric' => (string) $value, 'verified' => true, 'legal_source' => 'Prueba',
    ], (string) $current->valid_from, EffectiveDated::SAME_DAY_REPLACE);
}

/**
 * @return list<array<string, mixed>>
 */
function cavEvents(string $type, string $aggregate): array
{
    return TenancyScope::runAs(CAL_TENANT, fn () => DB::table('outbox_events')->where('event_type', $type)->where('aggregate_id', $aggregate)
        ->orderBy('occurred_at')->pluck('payload')->map(fn ($p) => json_decode((string) $p, true))->all());
}

test('CON-4 · la lista de por vencer trae los que vencen en la ventana y los vencidos sin regularizar, con el acumulado frente al tope', function (): void {
    $today = CarbonImmutable::today();
    $soon = cavFixedTerm($today->subDays(30)->toDateString(), $today->addDays(40)->toDateString(), $today->subDays(30)->toDateString());
    $later = cavFixedTerm($today->subDays(10)->toDateString(), $today->addDays(200)->toDateString(), $today->subDays(10)->toDateString());
    $expired = cavFixedTerm($today->subDays(120)->toDateString(), $today->subDays(5)->toDateString(), $today->subDays(120)->toDateString());

    $res = cavCall($this->admin, 'get', '/contracts/expiring')->assertOk();
    $this->assertMatchesOpenApi($res, 'get', '/contracts/expiring', 200);
    $rows = collect($res->json('data'))->keyBy('id');
    // Por defecto, la mayor anticipación de aviso (60 días): el de 200 días no entra.
    expect($rows->keys()->all())->toContain($soon['contract'], $expired['contract'])->not->toContain($later['contract'])
        ->and($rows[$soon['contract']]['days_left'])->toBe(40)
        ->and($rows[$soon['contract']]['suggested_action'])->toBe('renew')
        ->and($rows[$soon['contract']]['alert_days'])->toBe([60, 30, 15])
        ->and($rows[$soon['contract']]['accumulation']['modality_days'])->toBe(71)
        ->and($rows[$soon['contract']]['accumulation']['explanation'])->toContain('71')
        ->and($rows[$expired['contract']]['status'])->toBe('expired')
        ->and($rows[$expired['contract']]['suggested_action'])->toBe('regularize');

    $wide = cavCall($this->admin, 'get', '/contracts/expiring?within=365&include_expired=false')->assertOk();
    expect(collect($wide->json('data'))->pluck('id')->all())->toContain($soon['contract'], $later['contract'])->not->toContain($expired['contract']);
    // La tabla de la interfaz manda los filtros como filter[...] y busca por nombre.
    $table = cavCall($this->admin, 'get', '/contracts/expiring?filter[within]=365&filter[include_expired]=false&q=huam')->assertOk();
    expect($table->json('meta.total'))->toBe(2);
    expect(cavCall($this->admin, 'get', '/contracts/expiring?q=nadie')->assertOk()->json('meta.total'))->toBe(0);

    // La anticipación se configura por empresa.
    TenancyScope::runAs(CAL_TENANT, fn () => app(ParameterResolver::class)->set('contratos.alerta_vencimiento_dias', [30, 7], 'legal_entity', $this->entity, null, 'Prueba'));
    $narrow = cavCall($this->admin, 'get', '/contracts/expiring')->assertOk();
    expect(collect($narrow->json('data'))->pluck('id')->all())->not->toContain($soon['contract']);

    cavCall($this->admin, 'get', '/contracts/expiring?within=900')->assertStatus(422);
});

test('CON-4 · la tarea diaria avisa una vez por anticipación con el acumulado, y vuelve a avisar si cambia la fecha de fin', function (): void {
    ['contract' => $id] = cavFixedTerm('2026-12-01', '2027-05-31', CarbonImmutable::today()->toDateString());

    Artisan::call('contracts:apply-due', ['--date' => '2027-04-05']);
    $events = cavEvents(DomainEventTypes::CONTRACT_EXPIRING, $id);
    expect($events)->toHaveCount(1)->and($events[0]['days_left'])->toBe(56)->and($events[0]['threshold_days'])->toBe(60)
        ->and($events[0]['explanation'])->toContain('182');
    Artisan::call('contracts:apply-due', ['--date' => '2027-04-06']);
    expect(cavEvents(DomainEventTypes::CONTRACT_EXPIRING, $id))->toHaveCount(1);
    Artisan::call('contracts:apply-due', ['--date' => '2027-05-02']);
    expect(cavEvents(DomainEventTypes::CONTRACT_EXPIRING, $id))->toHaveCount(2);
    Artisan::call('contracts:apply-due', ['--date' => '2027-05-20']);
    $all = cavEvents(DomainEventTypes::CONTRACT_EXPIRING, $id);
    expect($all)->toHaveCount(3)->and($all[2]['threshold_days'])->toBe(15);

    // Una prórroga cambia la fecha de fin: el aviso vuelve a correr para la nueva fecha.
    TenancyScope::runAs(CAL_TENANT, fn () => DB::table('contracts')->where('id', $id)->update(['valid_to' => '2027-06-30']));
    Artisan::call('contracts:apply-due', ['--date' => '2027-05-21']);
    expect(cavEvents(DomainEventTypes::CONTRACT_EXPIRING, $id))->toHaveCount(4);
});

test('CON-4 · con el tope verificado, la tarea diaria avisa una vez que el acumulado se acerca al tope', function (): void {
    cavLegal('contrato_modalidad_tope_acumulado_anios', 1, 'years');
    ['contract' => $id] = cavFixedTerm('2026-12-01', '2027-10-31', CarbonImmutable::today()->toDateString());

    Artisan::call('contracts:apply-due', ['--date' => '2026-12-02']);
    $events = cavEvents(DomainEventTypes::CONTRACT_CAP_APPROACHING, $id);
    expect($events)->toHaveCount(1)->and($events[0]['total_days'])->toBe(335)->and($events[0]['total_max_days'])->toBe(365)
        ->and($events[0]['remaining_days'])->toBe(30)->and($events[0]['explanation'])->toContain('365');
    Artisan::call('contracts:apply-due', ['--date' => '2026-12-03']);
    expect(cavEvents(DomainEventTypes::CONTRACT_CAP_APPROACHING, $id))->toHaveCount(1);
});

test('CON-4 · renovar por encima del tope exige confirmación expresa con motivo, que queda auditada', function (): void {
    cavLegal('contrato_modalidad_tope_acumulado_anios', 1, 'years');
    ['contract' => $first] = cavFixedTerm('2026-01-05', '2026-06-30', '2026-01-05');

    $over = cavCall($this->admin, 'post', '/contracts/'.$first.'/renew', ['ends_on' => '2027-03-31'])->assertStatus(422);
    expect($over->json('error.code'))->toBe('contract_limit_exceeded')->and($over->json('error.message'))->toContain('confirme');
    $short = cavCall($this->admin, 'post', '/contracts/'.$first.'/renew', ['ends_on' => '2027-03-31', 'confirm_over_limit' => true, 'over_limit_reason' => 'corto'])
        ->assertStatus(422);
    expect($short->json('error.details.0.field'))->toBe('over_limit_reason');

    $ok = cavCall($this->admin, 'post', '/contracts/'.$first.'/renew', [
        'ends_on' => '2027-03-31', 'confirm_over_limit' => true, 'over_limit_reason' => 'Cierre de la reconversión aprobado por la gerencia legal',
    ])->assertCreated();
    $this->assertMatchesOpenApi($ok, 'post', '/contracts/{id}/renew', 201);
    $successor = (string) $ok->json('data.id');
    expect($ok->json('data.over_limit_reason'))->toBe('Cierre de la reconversión aprobado por la gerencia legal')
        ->and(collect($ok->json('data.warnings'))->pluck('code'))->toContain('over_limit_confirmed');
    TenancyScope::runAs(CAL_TENANT, function () use ($successor): void {
        $audit = DB::table('audit_events')->where('entity_type', 'contract')->where('entity_id', $successor)->where('action', 'contract.renewal_over_limit')->first();
        expect($audit)->not->toBeNull()->and($audit->reason)->toBe('Cierre de la reconversión aprobado por la gerencia legal');
    });
    // Confirmada, la renovación no se vuelve a bloquear al enviarla.
    cavCall($this->admin, 'post', '/contracts/'.$successor.'/submit')->assertOk();
});

test('CON-4 · los vencimientos aparecen en el calendario central', function (): void {
    $today = CarbonImmutable::today();
    ['contract' => $id] = cavFixedTerm($today->subDays(30)->toDateString(), $today->addDays(10)->toDateString(), $today->subDays(30)->toDateString());
    $res = cavCall($this->admin, 'get', '/calendar?from='.$today->toDateString().'&to='.$today->addDays(20)->toDateString().'&sources=contracts')->assertOk();
    $item = collect($res->json('data'))->firstWhere('id', $id);
    expect($res->json('meta.sources'))->toContain('contracts')
        ->and($item)->not->toBeNull()->and($item['starts_on'])->toBe($today->addDays(10)->toDateString())
        ->and($item['link'])->toContain('tab=contracts');
});

test('CON-4 · sin permiso 403, con el módulo apagado 403 y otra organización no ve los contratos', function (): void {
    $today = CarbonImmutable::today();
    ['contract' => $id] = cavFixedTerm($today->subDays(30)->toDateString(), $today->addDays(20)->toDateString(), $today->subDays(30)->toDateString());
    $recruiter = cavUser(CAL_TENANT, 'recruiter');
    $res = cavCall($recruiter, 'get', '/contracts/expiring')->assertStatus(403);
    $this->assertMatchesOpenApi($res, 'get', '/contracts/expiring', 403);
    // El alcance se aplica en la consulta: un colaborador solo vería los suyos.
    $employee = cavUser(CAL_TENANT, 'empleado');
    expect(collect(cavCall($employee, 'get', '/contracts/expiring')->assertOk()->json('data'))->pluck('id')->all())->not->toContain($id);

    expect(collect(cavCall($this->other, 'get', '/contracts/expiring')->assertOk()->json('data'))->pluck('id')->all())->not->toContain($id);

    IdentityFixtures::disableModule(CAL_TENANT, 'core');
    cavCall($this->admin, 'get', '/contracts/expiring')->assertStatus(403)->assertJsonPath('error.code', 'module_not_enabled');
});
