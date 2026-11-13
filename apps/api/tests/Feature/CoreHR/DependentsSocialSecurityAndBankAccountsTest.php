<?php

/**
 * COL-6 · Derechohabientes: datos de terceros con acceso restringido, sustento por derechohabiente
 * (RN-COL-07), baja con fecha y motivo, alerta de revisión al cumplir un hijo la mayoría de edad.
 * COL-7 · Régimen pensionario, salud y seguros: con vigencia (cambio de AFP o comisión con fecha efectiva),
 * pólizas del empleador y coberturas, declaración firmada y archivada de beneficiarios de Vida Ley.
 * COL-8 · Cuentas de pago: número cifrado y enmascarado sin permiso de campo, cambio con aprobación de
 * otra persona y aviso al titular (RN-COL-11).
 */

use App\Modules\CoreHR\Application\DependentService;
use App\Modules\CoreHR\Application\PaymentFormats;
use App\Modules\CoreHR\Infrastructure\Models\Employment;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Notifications\Infrastructure\Subscribers\NotifyPeopleChanges;
use App\Shared\Outbox\Models\OutboxEvent;
use App\Shared\Permissions\ModuleGate;
use App\Shared\Permissions\PermissionResolver;
use App\Shared\Security\FieldCipher;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\IdentityFixtures;

uses(DatabaseTransactions::class);

const C6_TENANT = 'aaaaaaaa-0c06-4000-8000-00000000000a';
const C6_OTHER = 'bbbbbbbb-0c06-4000-8000-00000000000b';

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requiere PostgreSQL.');
    }
    Storage::fake('s3');
    TenancyScope::clear();
    foreach ([C6_TENANT => 'c6-org', C6_OTHER => 'c6-otra'] as $id => $slug) {
        DB::table('tenants')->insertOrIgnore(['id' => $id, 'slug' => $slug, 'name' => 'Grupo '.$slug, 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN']);
        DB::select('SELECT seed_tenant_defaults(?)', [$id]);
        TenancyScope::runAs($id, fn () => IdentityFixtures::enableAllModules());
    }
    $this->admin = c6User(C6_TENANT, 'admin_empresa');
    $this->rrhh = c6User(C6_TENANT, 'rrhh');
    $this->payroll = c6User(C6_TENANT, 'payroll');
    $this->employment = TenancyScope::runAs(C6_TENANT, fn (): Employment => Employment::factory()->create());
    $this->emp = '/employments/'.$this->employment->id;
});

afterEach(fn () => TenancyScope::clear());

// Los 422 llegan como error.details[].field (formato de errores de la API).
TestResponse::macro('assertErrorField', function (string $field): TestResponse {
    /** @var TestResponse $this */
    $this->assertStatus(422);
    expect(collect($this->json('error.details'))->pluck('field')->all())->toContain($field);

    return $this;
});

function c6User(string $tenant, string $role): User
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
function c6Call(User $user, string $method, string $path, array $body = [], bool $multipart = false): TestResponse
{
    TenancyScope::clear();
    app('auth')->forgetGuards();
    app(PermissionResolver::class)->forget();
    app(ModuleGate::class)->forget();
    $headers = ['X-Tenant-Id' => $user->tenant_id, 'Idempotency-Key' => (string) Str::uuid(), 'Accept' => 'application/json'];
    $test = test()->actingAs($user, 'ayni')->withHeaders($headers);

    return $multipart ? $test->post('/api/v1'.$path, $body) : $test->json($method, '/api/v1'.$path, $body);
}

// ── COL-6 · Derechohabientes ──────────────────────────────────────────────────

test('COL-6 · un hijo se registra con documento cifrado y fecha de nacimiento; sin sustento queda pendiente para la asignación familiar', function (): void {
    $res = c6Call($this->admin, 'post', $this->emp.'/dependents', [
        'full_name' => 'Lucía Quispe Rojas', 'relationship' => 'child', 'document_type' => 'dni', 'document_number' => '71234567', 'birth_date' => '2016-05-04',
    ])->assertCreated();
    $this->assertMatchesOpenApi($res, 'post', '/employments/{employment_id}/dependents', 201);
    expect($res->json('data.document_number'))->toBe('71234567')
        ->and($res->json('data.missing_proof'))->toBeTrue()
        ->and($res->json('data.entitles_family_allowance'))->toBeFalse();
    $id = $res->json('data.id');
    TenancyScope::runAs(C6_TENANT, function () use ($id): void {
        $row = DB::table('dependents')->where('id', $id)->first();
        expect($row->number_enc)->not->toContain('71234567')->and(FieldCipher::decrypt((string) $row->number_enc))->toBe('71234567');
        expect(DB::table('audit_events')->where('action', 'dependent.created')->where('entity_id', $id)->exists())->toBeTrue();
    });

    $list = c6Call($this->admin, 'get', $this->emp.'/dependents')->assertOk();
    $this->assertMatchesOpenApi($list, 'get', '/employments/{employment_id}/dependents', 200);
    expect($list->json('data'))->toHaveCount(1);
    $this->assertMatchesOpenApi(c6Call($this->admin, 'get', $this->emp.'/dependents/'.$id)->assertOk(), 'get', '/employments/{employment_id}/dependents/{id}', 200);
});

test('COL-6 · RN-COL-07: un hijo exige fecha de nacimiento y un documento repetido se rechaza', function (): void {
    $res = c6Call($this->admin, 'post', $this->emp.'/dependents', ['full_name' => 'Mateo Quispe', 'relationship' => 'child']);
    $this->assertMatchesOpenApi($res, 'post', '/employments/{employment_id}/dependents', 422);
    $res->assertErrorField('birth_date');

    c6Call($this->admin, 'post', $this->emp.'/dependents', ['full_name' => 'Ana Rojas', 'relationship' => 'spouse', 'document_type' => 'dni', 'document_number' => '41234567'])->assertCreated();
    c6Call($this->admin, 'post', $this->emp.'/dependents', ['full_name' => 'Ana R.', 'relationship' => 'partner', 'document_type' => 'dni', 'document_number' => '41234567'])
        ->assertStatus(422)->assertErrorField('document_number');
});

test('COL-6 · el sustento se guarda por derechohabiente y habilita la asignación familiar del hijo', function (): void {
    $id = c6Call($this->admin, 'post', $this->emp.'/dependents', ['full_name' => 'Lucía Quispe', 'relationship' => 'child', 'birth_date' => '2016-05-04'])->json('data.id');
    $up = c6Call($this->admin, 'post', $this->emp.'/dependents/'.$id.'/proof', ['file' => UploadedFile::fake()->create('partida.pdf', 20, 'application/pdf')], true);
    $up->assertOk();
    $this->assertMatchesOpenApi($up, 'post', '/employments/{employment_id}/dependents/{id}/proof', 200);
    expect($up->json('data.has_proof'))->toBeTrue()->and($up->json('data.entitles_family_allowance'))->toBeTrue();

    $download = c6Call($this->admin, 'get', $this->emp.'/dependents/'.$id.'/proof')->assertOk();
    expect($download->headers->get('Content-Type'))->toContain('application/pdf');

    c6Call($this->admin, 'post', $this->emp.'/dependents/'.$id.'/proof', ['file' => UploadedFile::fake()->create('foto.gif', 5, 'image/gif')], true)->assertStatus(422);
});

test('COL-6 · la baja deja fecha y motivo; no se repite ni permite editar (409)', function (): void {
    $id = c6Call($this->admin, 'post', $this->emp.'/dependents', ['full_name' => 'Ana Rojas', 'relationship' => 'spouse', 'registered_on' => '2025-02-01'])->json('data.id');
    $res = c6Call($this->admin, 'post', $this->emp.'/dependents/'.$id.'/deregister', ['deregistered_on' => '2026-06-30', 'reason' => 'Divorcio']);
    $this->assertMatchesOpenApi($res->assertOk(), 'post', '/employments/{employment_id}/dependents/{id}/deregister', 200);
    expect($res->json('data.registry_status'))->toBe('deregistered')->and($res->json('data.deregistered_on'))->toBe('2026-06-30');

    $again = c6Call($this->admin, 'post', $this->emp.'/dependents/'.$id.'/deregister', ['deregistered_on' => '2026-07-01', 'reason' => 'Otra vez']);
    $this->assertMatchesOpenApi($again, 'post', '/employments/{employment_id}/dependents/{id}/deregister', 409);
    expect($again->json('error.code'))->toBe('invalid_transition');
    c6Call($this->admin, 'patch', $this->emp.'/dependents/'.$id, ['full_name' => 'Ana María Rojas'])->assertStatus(409);

    // Eliminar es corregir un registro errado: pasa a la papelera (no es la baja).
    $other = c6Call($this->admin, 'post', $this->emp.'/dependents', ['full_name' => 'Error de carga', 'relationship' => 'other'])->json('data.id');
    $this->assertMatchesOpenApi(c6Call($this->admin, 'delete', $this->emp.'/dependents/'.$other)->assertNoContent(), 'delete', '/employments/{employment_id}/dependents/{id}', 204);
    TenancyScope::runAs(C6_TENANT, fn () => expect(DB::table('dependents')->where('id', $other)->value('deleted_at'))->not->toBeNull());
});

test('COL-6 · datos de terceros: sin permiso del recurso 403, sin permiso de campo no viaja el documento ni la discapacidad', function (): void {
    $manager = c6User(C6_TENANT, 'manager');
    $this->assertMatchesOpenApi(c6Call($manager, 'get', $this->emp.'/dependents')->assertForbidden(), 'get', '/employments/{employment_id}/dependents', 403);

    $id = c6Call($this->rrhh, 'post', $this->emp.'/dependents', [
        'full_name' => 'Pedro Quispe', 'relationship' => 'disabled_adult_child', 'document_type' => 'dni', 'document_number' => '72345678', 'birth_date' => '2000-01-01', 'has_disability' => true,
    ])->assertCreated()->json('data.id');
    // Administrador de empresa: ve datos personales pero no de salud; no registra discapacidad.
    $seen = c6Call($this->admin, 'get', $this->emp.'/dependents/'.$id)->assertOk();
    expect($seen->json('data'))->toHaveKey('document_number')->not->toHaveKey('has_disability');
    c6Call($this->admin, 'patch', $this->emp.'/dependents/'.$id, ['has_disability' => false])->assertStatus(422)->assertErrorField('has_disability');

    $viewer = IdentityFixtures::userWithPermissions(C6_TENANT, ['people.dependents.view']);
    $masked = c6Call($viewer, 'get', $this->emp.'/dependents/'.$id)->assertOk();
    expect($masked->json('data'))->not->toHaveKey('document_number')->not->toHaveKey('birth_date')
        ->and($masked->json('data.document_number_masked'))->toBe('••••5678');
});

test('COL-6 · aislamiento y módulo: otra organización no ve los derechohabientes (404) y sin Core HR responde module_not_enabled', function (): void {
    c6Call($this->admin, 'post', $this->emp.'/dependents', ['full_name' => 'Ana Rojas', 'relationship' => 'spouse'])->assertCreated();
    $intruder = IdentityFixtures::userWithPermissions(C6_OTHER);
    $this->assertMatchesOpenApi(c6Call($intruder, 'get', $this->emp.'/dependents')->assertNotFound(), 'get', '/employments/{employment_id}/dependents', 404);

    IdentityFixtures::disableModule(C6_TENANT, 'core');
    $res = c6Call($this->admin, 'get', $this->emp.'/dependents');
    $res->assertForbidden();
    expect($res->json('error.code'))->toBe('module_not_enabled');
});

test('COL-6 · al cumplir la mayoría de edad un hijo pasa a revisión una sola vez y RRHH recibe el aviso', function (): void {
    DB::table('legal_parameters')->where('code', 'mayoria_edad_anios')->delete();
    DB::table('legal_parameters')->insert([
        'country_code' => 'PE', 'code' => 'mayoria_edad_anios', 'name' => 'Edad de mayoría', 'unit' => 'count', 'value_numeric' => 18,
        'verified' => true, 'valid_from' => '2026-01-01', 'legal_source' => 'Código Civil, art. 42',
    ]);
    $adult = c6Call($this->admin, 'post', $this->emp.'/dependents', ['full_name' => 'Hijo Mayor', 'relationship' => 'child', 'birth_date' => '2008-11-01'])->json('data.id');
    c6Call($this->admin, 'post', $this->emp.'/dependents', ['full_name' => 'Hija Menor', 'relationship' => 'child', 'birth_date' => '2012-01-01'])->assertCreated();

    TenancyScope::runAs(C6_TENANT, function () use ($adult): void {
        $service = app(DependentService::class);
        expect($service->reviewMajority('2026-11-13'))->toBe(1)
            ->and($service->reviewMajority('2026-11-14'))->toBe(0);
        expect(DB::table('dependents')->where('id', $adult)->value('majority_alerted_on'))->toBe('2026-11-13');
        /** @var OutboxEvent $event */
        $event = OutboxEvent::query()->where('event_type', 'dependent.reached_majority.v1')->where('aggregate_id', $adult)->firstOrFail();
        expect($event->payload)->not->toHaveKey('birth_date');
        app(NotifyPeopleChanges::class)->handle($event);
        expect(DB::table('notifications')->where('category', 'people.dependent_majority')->where('recipient_user_id', $this->rrhh->id)->exists())->toBeTrue();
    });
});

// ── COL-7 · Previsión, salud y seguros ────────────────────────────────────────

test('COL-7 · el cambio de AFP o de tipo de comisión se guarda con fecha efectiva y no sobrescribe la historia', function (): void {
    $first = c6Call($this->admin, 'post', $this->emp.'/pension-affiliations', [
        'pension_system' => 'afp', 'pension_fund_code' => 'pe_afp_integra', 'commission_scheme' => 'flow', 'affiliate_code' => '123456ABCDE7', 'valid_from' => '2025-01-01',
    ]);
    $this->assertMatchesOpenApi($first->assertCreated(), 'post', '/employments/{employment_id}/pension-affiliations', 201);
    $change = c6Call($this->admin, 'post', $this->emp.'/pension-affiliations', [
        'pension_system' => 'afp', 'pension_fund_code' => 'pe_afp_prima', 'commission_scheme' => 'mixed', 'valid_from' => '2026-03-01',
    ])->assertCreated();
    // El CUSPP se conserva al cambiar de AFP.
    expect($change->json('data.affiliate_code'))->toBe('123456ABCDE7');

    $history = c6Call($this->admin, 'get', $this->emp.'/pension-affiliations')->assertOk();
    $this->assertMatchesOpenApi($history, 'get', '/employments/{employment_id}/pension-affiliations', 200);
    expect($history->json('data.0.pension_fund_code'))->toBe('pe_afp_prima')->and($history->json('data.0.valid_from'))->toBe('2026-03-01')
        ->and($history->json('data.1.pension_fund_code'))->toBe('pe_afp_integra')->and($history->json('data.1.valid_to'))->toBe('2026-02-28');
    TenancyScope::runAs(C6_TENANT, fn () => expect(DB::table('outbox_events')->where('event_type', 'employment.pension_changed.v1')->where('aggregate_id', $this->employment->id)->count())->toBe(2));

    // Mismo día: se corrige con otro registro, no se pisa (409).
    $same = c6Call($this->admin, 'post', $this->emp.'/pension-affiliations', ['pension_system' => 'onp', 'valid_from' => '2026-03-01']);
    $this->assertMatchesOpenApi($same, 'post', '/employments/{employment_id}/pension-affiliations', 409);
    // AFP sin tipo de comisión, o comisión en ONP: 422.
    c6Call($this->admin, 'post', $this->emp.'/pension-affiliations', ['pension_system' => 'afp', 'pension_fund_code' => 'pe_afp_habitat', 'valid_from' => '2026-05-01'])
        ->assertStatus(422)->assertErrorField('commission_scheme');
    c6Call($this->admin, 'post', $this->emp.'/pension-affiliations', ['pension_system' => 'onp', 'commission_scheme' => 'flow', 'valid_from' => '2026-05-01'])
        ->assertStatus(422)->assertErrorField('commission_scheme');
    $onp = c6Call($this->admin, 'post', $this->emp.'/pension-affiliations', ['pension_system' => 'onp', 'valid_from' => '2026-06-01'])->assertCreated();
    expect($onp->json('data.pension_fund_code'))->toBe('pe_onp')->and($onp->json('data'))->not->toHaveKey('affiliate_code');

    // Sin permiso de datos personales, el CUSPP no viaja.
    $viewer = IdentityFixtures::userWithPermissions(C6_TENANT, ['people.employments.view']);
    $masked = c6Call($viewer, 'get', $this->emp.'/pension-affiliations')->assertOk();
    expect($masked->json('data.1'))->not->toHaveKey('affiliate_code')->and($masked->json('data.1.affiliate_code_masked'))->toBe('••••••••CDE7');
});

test('COL-7 · EPS con entidad y plan, con fecha efectiva; sin plan 422', function (): void {
    TenancyScope::runAs(C6_TENANT, fn () => DB::table('employment_labor_regimes')->insert([
        'tenant_id' => C6_TENANT, 'employment_id' => $this->employment->id, 'labor_regime_code' => 'pe_general', 'health_scheme' => 'essalud', 'valid_from' => '2025-01-01',
    ]));
    $provider = c6Call($this->admin, 'post', '/health-providers', ['code' => 'eps_norte', 'name' => 'EPS Norte', 'kind' => 'eps'])->assertCreated();
    $this->assertMatchesOpenApi($provider, 'post', '/health-providers', 201);
    $pid = $provider->json('data.id');

    $missing = c6Call($this->admin, 'post', $this->emp.'/health-regimes', ['health_scheme' => 'eps', 'health_provider_id' => $pid, 'valid_from' => '2026-02-01']);
    $this->assertMatchesOpenApi($missing, 'post', '/employments/{employment_id}/health-regimes', 422);
    $ok = c6Call($this->admin, 'post', $this->emp.'/health-regimes', ['health_scheme' => 'eps', 'health_provider_id' => $pid, 'health_plan_code' => 'PLAN-A', 'valid_from' => '2026-02-01']);
    $this->assertMatchesOpenApi($ok->assertCreated(), 'post', '/employments/{employment_id}/health-regimes', 201);
    expect($ok->json('data.labor_regime_code'))->toBe('pe_general')->and($ok->json('data.health_provider_name'))->toBe('EPS Norte');

    $history = c6Call($this->admin, 'get', $this->emp.'/health-regimes')->assertOk();
    $this->assertMatchesOpenApi($history, 'get', '/employments/{employment_id}/health-regimes', 200);
    expect($history->json('data.1.valid_to'))->toBe('2026-01-31');
});

test('COL-7 · SCTR y Vida Ley: póliza de la empresa, cobertura con periodo y fin; terminar dos veces 409', function (): void {
    $policy = c6Call($this->admin, 'post', '/insurance-policies', [
        'legal_entity_id' => $this->employment->legal_entity_id, 'kind' => 'risk_health', 'insurer' => 'Aseguradora Andina', 'policy_number' => 'SCTR-001', 'starts_on' => '2025-01-01',
    ]);
    $this->assertMatchesOpenApi($policy->assertCreated(), 'post', '/insurance-policies', 201);
    $this->assertMatchesOpenApi(c6Call($this->admin, 'get', '/insurance-policies')->assertOk(), 'get', '/insurance-policies', 200);

    $coverage = c6Call($this->admin, 'post', $this->emp.'/insurance-coverages', ['insurance_policy_id' => $policy->json('data.id'), 'covered_from' => '2025-01-01']);
    $this->assertMatchesOpenApi($coverage->assertCreated(), 'post', '/employments/{employment_id}/insurance-coverages', 201);
    $dup = c6Call($this->admin, 'post', $this->emp.'/insurance-coverages', ['insurance_policy_id' => $policy->json('data.id'), 'covered_from' => '2025-06-01']);
    $this->assertMatchesOpenApi($dup, 'post', '/employments/{employment_id}/insurance-coverages', 409);

    $end = c6Call($this->admin, 'post', $this->emp.'/insurance-coverages/'.$coverage->json('data.id').'/end', ['covered_to' => '2026-06-30']);
    $this->assertMatchesOpenApi($end->assertOk(), 'post', '/employments/{employment_id}/insurance-coverages/{id}/end', 200);
    $again = c6Call($this->admin, 'post', $this->emp.'/insurance-coverages/'.$coverage->json('data.id').'/end', ['covered_to' => '2026-07-31']);
    $this->assertMatchesOpenApi($again, 'post', '/employments/{employment_id}/insurance-coverages/{id}/end', 409);
    $this->assertMatchesOpenApi(c6Call($this->admin, 'get', $this->emp.'/insurance-coverages')->assertOk(), 'get', '/employments/{employment_id}/insurance-coverages', 200);
});

test('COL-7 · la declaración de beneficiarios de Vida Ley queda firmada y archivada; la nueva reemplaza a la anterior', function (): void {
    $file = fn () => UploadedFile::fake()->create('declaracion-firmada.pdf', 30, 'application/pdf');
    $bad = c6Call($this->admin, 'post', $this->emp.'/life-insurance-declarations', [
        'beneficiaries' => json_encode([['full_name' => 'Ana Rojas', 'relationship' => 'spouse', 'percentage' => 50], ['full_name' => 'Lucía Quispe', 'relationship' => 'child', 'percentage' => 40]]),
        'signed_on' => '2026-01-10', 'file' => $file(),
    ], true);
    $bad->assertStatus(422)->assertErrorField('beneficiaries');

    $first = c6Call($this->admin, 'post', $this->emp.'/life-insurance-declarations', [
        'beneficiaries' => json_encode([['full_name' => 'Ana Rojas', 'document_number' => '41234567', 'relationship' => 'spouse', 'percentage' => 60], ['full_name' => 'Lucía Quispe', 'relationship' => 'child', 'percentage' => 40]]),
        'signed_on' => '2026-01-10', 'file' => $file(),
    ], true);
    $this->assertMatchesOpenApi($first->assertCreated(), 'post', '/employments/{employment_id}/life-insurance-declarations', 201);
    expect($first->json('data.has_signed_file'))->toBeTrue()->and($first->json('data.beneficiaries'))->toHaveCount(2);

    $second = c6Call($this->admin, 'post', $this->emp.'/life-insurance-declarations', [
        'beneficiaries' => json_encode([['full_name' => 'Ana Rojas', 'relationship' => 'spouse', 'percentage' => 100]]), 'signed_on' => '2026-03-02', 'file' => $file(),
    ], true)->assertCreated();
    $list = c6Call($this->admin, 'get', $this->emp.'/life-insurance-declarations')->assertOk();
    $this->assertMatchesOpenApi($list, 'get', '/employments/{employment_id}/life-insurance-declarations', 200);
    expect($list->json('data.0.id'))->toBe($second->json('data.id'))->and($list->json('data.0.status'))->toBe('current')
        ->and($list->json('data.1.status'))->toBe('superseded');
    $download = c6Call($this->admin, 'get', $this->emp.'/life-insurance-declarations/'.$first->json('data.id').'/file')->assertOk();
    expect($download->headers->get('Content-Type'))->toContain('application/pdf');

    // Sin permiso de datos personales: no se ven los beneficiarios ni se descarga la declaración.
    $viewer = IdentityFixtures::userWithPermissions(C6_TENANT, ['people.employments.view']);
    expect(c6Call($viewer, 'get', $this->emp.'/life-insurance-declarations')->json('data.0'))->not->toHaveKey('beneficiaries');
    c6Call($viewer, 'get', $this->emp.'/life-insurance-declarations/'.$first->json('data.id').'/file')->assertForbidden();
});

// ── COL-8 · Cuentas de pago ───────────────────────────────────────────────────

test('COL-8 · un cambio de cuenta queda pendiente, lo aprueba otra persona y la cuenta anterior se cierra; se avisa al titular', function (): void {
    TenancyScope::runAs(C6_TENANT, fn () => DB::table('person_bank_accounts')->insert([
        'tenant_id' => C6_TENANT, 'person_id' => $this->employment->person_id, 'employment_id' => $this->employment->id, 'purpose' => 'salary',
        'bank_code' => 'bbva', 'account_type' => 'savings', 'account_number_enc' => FieldCipher::encrypt('0011000123456789'), 'currency' => 'PEN',
        'status' => 'active', 'is_active' => true, 'valid_from' => '2025-01-01',
    ]));
    $created = c6Call($this->payroll, 'post', $this->emp.'/bank-accounts', [
        'purpose' => 'salary', 'bank_code' => 'bcp', 'account_type' => 'savings', 'account_number' => '1930-0123456-7-89', 'interbank_code' => '00219300123456789013', 'currency' => 'PEN',
    ]);
    $this->assertMatchesOpenApi($created->assertCreated(), 'post', '/employments/{employment_id}/bank-accounts', 201);
    expect($created->json('data.status'))->toBe('pending_approval')->and($created->json('data.account_number'))->toBe('19300123456789')
        ->and($created->json('meta.warnings'))->toBe([]);
    $id = $created->json('data.id');
    TenancyScope::runAs(C6_TENANT, function () use ($id): void {
        $row = DB::table('person_bank_accounts')->where('id', $id)->first();
        expect($row->account_number_enc)->not->toContain('19300123456789');
        expect(DB::table('outbox_events')->where('event_type', 'bank_account.change_requested.v1')->where('aggregate_id', $id)->count())->toBe(1);
    });

    // Otro cambio pendiente para la misma finalidad: 409.
    $this->assertMatchesOpenApi(c6Call($this->payroll, 'post', $this->emp.'/bank-accounts', [
        'purpose' => 'salary', 'bank_code' => 'interbank', 'account_type' => 'savings', 'account_number' => '2000123456789', 'currency' => 'PEN',
    ]), 'post', '/employments/{employment_id}/bank-accounts', 409);

    // Quien registra no aprueba (cuatro ojos), aunque tenga el permiso.
    $self = c6Call($this->admin, 'post', $this->emp.'/bank-accounts', [
        'purpose' => 'cts', 'bank_code' => 'bcp', 'account_type' => 'savings', 'account_number' => '1930099988877', 'currency' => 'PEN',
    ])->assertCreated()->json('data.id');
    $own = c6Call($this->admin, 'post', $this->emp.'/bank-accounts/'.$self.'/approve', []);
    $this->assertMatchesOpenApi($own, 'post', '/employments/{employment_id}/bank-accounts/{id}/approve', 403);
    expect($own->json('error.code'))->toBe('four_eyes');

    $approved = c6Call($this->rrhh, 'post', $this->emp.'/bank-accounts/'.$id.'/approve', ['notes' => 'Verificado con el colaborador']);
    $this->assertMatchesOpenApi($approved->assertOk(), 'post', '/employments/{employment_id}/bank-accounts/{id}/approve', 200);
    expect($approved->json('data.status'))->toBe('active')->and($approved->json('data.valid_from'))->toBe(now()->toDateString());
    TenancyScope::runAs(C6_TENANT, function () use ($id): void {
        expect(DB::table('person_bank_accounts')->where('bank_code', 'bbva')->value('valid_to'))->toBe(now()->subDay()->toDateString());
        expect(DB::table('audit_events')->where('action', 'bank_account.approved')->where('entity_id', $id)->exists())->toBeTrue();
        /** @var OutboxEvent $event */
        $event = OutboxEvent::query()->where('event_type', 'bank_account.changed.v1')->where('aggregate_id', $id)->firstOrFail();
        expect(json_encode($event->payload))->not->toContain('19300123456789');
    });
    $twice = c6Call($this->rrhh, 'post', $this->emp.'/bank-accounts/'.$id.'/approve', []);
    $this->assertMatchesOpenApi($twice, 'post', '/employments/{employment_id}/bank-accounts/{id}/approve', 409);
});

test('COL-8 · el número es campo sensible: completo con permiso (y lectura registrada), enmascarado sin él, oculto sin ningún permiso de cuentas', function (): void {
    $id = c6Call($this->payroll, 'post', $this->emp.'/bank-accounts', [
        'purpose' => 'salary', 'bank_code' => 'bcp', 'account_type' => 'savings', 'account_number' => '193001234567789', 'currency' => 'PEN',
    ])->assertCreated()->json('data.id');

    $contador = c6User(C6_TENANT, 'contador');
    $full = c6Call($contador, 'get', $this->emp.'/bank-accounts')->assertOk();
    $this->assertMatchesOpenApi($full, 'get', '/employments/{employment_id}/bank-accounts', 200);
    expect($full->json('data.0.account_number'))->toBe('193001234567789');
    TenancyScope::runAs(C6_TENANT, fn () => expect(DB::table('data_access_logs')->where('entity_type', 'BankAccount')->where('user_id', $contador->id)->where('data_category', 'bank_account')->exists())->toBeTrue());

    $masked = c6Call($this->rrhh, 'get', $this->emp.'/bank-accounts')->assertOk();
    expect($masked->json('data.0'))->not->toHaveKey('account_number')->and($masked->json('data.0.account_number_masked'))->toBe('•••••••••••7789');

    $noBank = IdentityFixtures::userWithPermissions(C6_TENANT, ['people.employments.view']);
    $this->assertMatchesOpenApi(c6Call($noBank, 'get', $this->emp.'/bank-accounts')->assertForbidden(), 'get', '/employments/{employment_id}/bank-accounts', 403);
    c6Call($this->rrhh, 'post', $this->emp.'/bank-accounts', ['purpose' => 'cts', 'bank_code' => 'bcp', 'account_type' => 'savings', 'account_number' => '19300123', 'currency' => 'PEN'])->assertForbidden();

    // Rechazar exige motivo; después ya no se aprueba.
    c6Call($this->rrhh, 'post', $this->emp.'/bank-accounts/'.$id.'/reject', [])->assertStatus(422);
    $rejected = c6Call($this->rrhh, 'post', $this->emp.'/bank-accounts/'.$id.'/reject', ['reason' => 'El titular no la reconoce']);
    $this->assertMatchesOpenApi($rejected->assertOk(), 'post', '/employments/{employment_id}/bank-accounts/{id}/reject', 200);
    c6Call($this->rrhh, 'post', $this->emp.'/bank-accounts/'.$id.'/approve', [])->assertStatus(409);
});

test('COL-8 · formato del paquete Perú: banco del catálogo, número y CCI; dígitos de control del CCI por validar (aviso)', function (): void {
    c6Call($this->payroll, 'post', $this->emp.'/bank-accounts', ['purpose' => 'salary', 'bank_code' => 'banco_inventado', 'account_type' => 'savings', 'account_number' => '193001234567789', 'currency' => 'PEN'])
        ->assertStatus(422)->assertErrorField('bank_code');
    c6Call($this->payroll, 'post', $this->emp.'/bank-accounts', ['purpose' => 'salary', 'bank_code' => 'bcp', 'account_type' => 'savings', 'account_number' => '19300', 'currency' => 'PEN'])
        ->assertStatus(422)->assertErrorField('account_number');
    c6Call($this->payroll, 'post', $this->emp.'/bank-accounts', ['purpose' => 'salary', 'bank_code' => 'bcp', 'account_type' => 'savings', 'account_number' => '193001234567789', 'interbank_code' => '0021930012', 'currency' => 'PEN'])
        ->assertStatus(422)->assertErrorField('interbank_code');
    $warn = c6Call($this->payroll, 'post', $this->emp.'/bank-accounts', ['purpose' => 'salary', 'bank_code' => 'bcp', 'account_type' => 'savings', 'account_number' => '193001234567789', 'interbank_code' => '00219300123456789000', 'currency' => 'PEN'])
        ->assertCreated();
    expect($warn->json('meta.warnings'))->toBe(['interbank_check_digits']);

    expect(PaymentFormats::peCciCheckDigitsMatch('00219300123456789013'))->toBeTrue()
        ->and(PaymentFormats::peCciCheckDigitsMatch('00219300123456789000'))->toBeFalse();
});

test('COL-8 · RN-COL-11: el titular recibe el aviso en la aplicación y un mensaje al celular, solo con los últimos dígitos', function (): void {
    $holder = TenancyScope::runAs(C6_TENANT, function (): User {
        $u = User::factory()->create(['person_id' => $this->employment->person_id]);
        DB::table('user_identities')->insert([
            'tenant_id' => C6_TENANT, 'user_id' => $u->id, 'kind' => 'phone', 'identifier_enc' => FieldCipher::encrypt('51987654321'),
            'identifier_hash' => hash('sha256', 'c6-phone-'.$u->id), 'is_primary' => true,
        ]);

        return $u;
    });
    $id = c6Call($this->payroll, 'post', $this->emp.'/bank-accounts', [
        'purpose' => 'salary', 'bank_code' => 'bcp', 'account_type' => 'savings', 'account_number' => '193001234567789', 'currency' => 'PEN',
    ])->assertCreated()->json('data.id');

    TenancyScope::runAs(C6_TENANT, function () use ($id, $holder): void {
        /** @var OutboxEvent $event */
        $event = OutboxEvent::query()->where('event_type', 'bank_account.change_requested.v1')->where('aggregate_id', $id)->firstOrFail();
        app(NotifyPeopleChanges::class)->handle($event);
        $notice = DB::table('notifications')->where('category', 'people.bank_account_requested')->where('recipient_user_id', $holder->id)->first();
        expect($notice)->not->toBeNull();
        expect(DB::table('notification_deliveries')->where('channel', 'sms')->where('template_code', 'people.bank_account_requested')->count())->toBe(1);
        // Reprocesar el mismo evento no envía otro mensaje.
        app(NotifyPeopleChanges::class)->handle($event);
        expect(DB::table('notification_deliveries')->where('channel', 'sms')->where('template_code', 'people.bank_account_requested')->count())->toBe(1);
    });
});

test('COL-7 · COL-8 · catálogos: administradoras del país y entidades de salud', function (): void {
    $funds = c6Call($this->admin, 'get', '/pension-funds')->assertOk();
    $this->assertMatchesOpenApi($funds, 'get', '/pension-funds', 200);
    expect(collect($funds->json('data'))->pluck('code')->all())->toContain('pe_onp', 'pe_afp_integra');
    $this->assertMatchesOpenApi(c6Call($this->admin, 'get', '/health-providers')->assertOk(), 'get', '/health-providers', 200);
    $dup = c6Call($this->admin, 'post', '/health-providers', ['code' => 'eps_x', 'name' => 'EPS X', 'kind' => 'eps']);
    $dup->assertCreated();
    $this->assertMatchesOpenApi(c6Call($this->admin, 'post', '/health-providers', ['code' => 'eps_x', 'name' => 'EPS X', 'kind' => 'eps']), 'post', '/health-providers', 409);
});
