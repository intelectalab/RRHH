<?php

/**
 * IMP-3 · Asistente de primeros pasos · IMP-4 · Plantillas peruanas listas para usar.
 */

use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Organization\Infrastructure\Models\LegalEntity;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Support\IdentityFixtures;

uses(DatabaseTransactions::class);

const SET_TENANT = 'aaaaaaaa-1a73-4000-8000-00000000000a';
const SET_OTHER = 'bbbbbbbb-1a73-4000-8000-00000000000b';

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requiere PostgreSQL.');
    }
    TenancyScope::clear();
    DB::table('tenants')->insertOrIgnore([
        ['id' => SET_TENANT, 'slug' => 'set-org-a', 'name' => 'Set A', 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN'],
        ['id' => SET_OTHER, 'slug' => 'set-org-b', 'name' => 'Set B', 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN'],
    ]);
    DB::select('SELECT seed_tenant_defaults(?)', [SET_TENANT]);
    DB::select('SELECT seed_tenant_defaults(?)', [SET_OTHER]);
    $this->admin = IdentityFixtures::userWithPermissions(SET_TENANT, ['imports.setup.use']);
    TenancyScope::runAs(SET_TENANT, fn () => DB::table('tenant_modules')->insertOrIgnore(['tenant_id' => SET_TENANT, 'module_code' => 'time_off',
        'status' => 'active', 'source' => 'manual', 'activated_at' => now()]));
});

afterEach(function (): void {
    TenancyScope::clear();
});

function setCall(User $user, string $method, string $path, array $body = [])
{
    TenancyScope::clear();
    app('auth')->forgetGuards();
    $req = test()->actingAs($user, 'ayni')->withHeaders(['X-Tenant-Id' => $user->tenant_id]);

    return $method === 'get' ? $req->get('/api/v1'.$path, ['Accept' => 'application/json']) : $req->postJson('/api/v1'.$path, $body);
}

function setStep(array $data, string $code): array
{
    return collect($data['steps'])->firstWhere('code', $code);
}

test('el paquete declara lo que crea; aplicarlo crea solo lo que falta, registra la versión y deja todo editable', function (): void {
    $list = setCall($this->admin, 'get', '/setup/country-packs')->assertOk();
    $this->assertMatchesOpenApi($list, 'get', '/setup/country-packs', 200);
    $pack = $list->json('data.0');
    expect($pack['code'])->toBe('pe-base')
        ->and($pack['installed_version'])->toBeNull()
        ->and(array_column($pack['components'], 'code'))->toBe(['contract_types', 'absence_types', 'holidays', 'document_templates', 'vacation_policies', 'termination_reasons', 'roles', 'payroll_concepts']);
    $contracts = collect($pack['components'])->firstWhere('code', 'contract_types');
    expect($contracts['total'])->toBeGreaterThan(0)->and($contracts['present'])->toBe(0)->and($contracts['link'])->toBe('/configuracion/tipos-contrato');

    $applied = setCall($this->admin, 'post', '/setup/country-packs/pe-base/apply')->assertOk();
    $this->assertMatchesOpenApi($applied, 'post', '/setup/country-packs/{code}/apply', 200);
    expect($applied->json('data.created.contract_types'))->toBe($contracts['total'])
        ->and($applied->json('data.created.vacation_policies'))->toBe(3)
        ->and($applied->json('data.created.holidays'))->toBeGreaterThan(0)
        ->and($applied->json('data.pack.installed_version'))->toBe('1.4.0');

    TenancyScope::runAs(SET_TENANT, function (): void {
        expect(DB::table('vacation_policies')->where('code', 'pe_general')->value('labor_regime_code'))->toBe('pe_general')
            ->and(DB::table('document_templates')->where('code', 'certificado_trabajo')->exists())->toBeTrue()
            ->and(DB::table('audit_events')->where('action', 'country_pack.applied')->exists())->toBeTrue()
            ->and(DB::table('outbox_events')->where('event_type', 'country_pack.applied.v1')->exists())->toBeTrue();
        // La organización edita una política creada por el paquete; volver a aplicar no la pisa.
        DB::table('vacation_policies')->where('code', 'pe_general')->update(['name' => 'Vacaciones de la casa']);
    });

    $again = setCall($this->admin, 'post', '/setup/country-packs/pe-base/apply')->assertOk();
    expect(array_sum($again->json('data.created')))->toBe(0);
    TenancyScope::runAs(SET_TENANT, function (): void {
        expect(DB::table('vacation_policies')->where('code', 'pe_general')->value('name'))->toBe('Vacaciones de la casa')
            ->and(DB::table('tenant_country_packs')->count())->toBe(1);
    });

    setCall($this->admin, 'post', '/setup/country-packs/xx-otro/apply')->assertStatus(404);
});

test('una parte de un módulo no contratado no se aplica', function (): void {
    IdentityFixtures::disableModule(SET_TENANT, 'time_off');
    $res = setCall($this->admin, 'post', '/setup/country-packs/pe-base/apply')->assertOk();
    expect($res->json('data.created'))->not->toHaveKey('vacation_policies')->not->toHaveKey('absence_types')
        ->and(collect($res->json('data.pack.components'))->firstWhere('code', 'vacation_policies')['module_enabled'])->toBeFalse();
    TenancyScope::runAs(SET_TENANT, fn () => expect(DB::table('vacation_policies')->count())->toBe(0));
});

test('el asistente muestra el avance, lleva a las pantallas reales y se puede saltar, retomar y ocultar', function (): void {
    $res = setCall($this->admin, 'get', '/setup-checklist')->assertOk();
    $this->assertMatchesOpenApi($res, 'get', '/setup-checklist', 200);
    $data = $res->json('data');
    expect($data['visible'])->toBeTrue()->and($data['progress'])->toBe(0)
        ->and(setStep($data, 'structure')['link'])->toBe('/organizacion/unidades')
        ->and(setStep($data, 'company')['status'])->toBe('pending');

    // Un paso se da por hecho cuando su dato existe (no con un clic).
    TenancyScope::runAs(SET_TENANT, fn () => LegalEntity::create(['legal_name' => 'Empresa Set S.A.C.', 'tax_id' => '20999999981',
        'country_code' => 'PE', 'currency' => 'PEN', 'timezone' => 'America/Lima', 'status' => 'active']));
    setCall($this->admin, 'post', '/setup/country-packs/pe-base/apply')->assertOk();
    $data = setCall($this->admin, 'get', '/setup-checklist')->json('data');
    expect(setStep($data, 'company')['status'])->toBe('done')
        ->and(setStep($data, 'country_pack')['status'])->toBe('done')
        ->and(setStep($data, 'policies')['status'])->toBe('done')
        ->and($data['progress'])->toBe(intdiv(300, $data['total']));

    $skip = setCall($this->admin, 'post', '/setup-checklist/sites/skip')->assertOk();
    $this->assertMatchesOpenApi($skip, 'post', '/setup-checklist/{code}/skip', 200);
    expect(setStep($skip->json('data'), 'sites')['status'])->toBe('skipped')
        ->and(setStep($skip->json('data'), 'sites')['allowed_actions'])->toBe(['reopen']);

    // Un paso hecho no se salta; uno pendiente no se retoma.
    $invalid = setCall($this->admin, 'post', '/setup-checklist/company/skip')->assertStatus(409)->assertJsonPath('error.code', 'invalid_transition');
    $this->assertMatchesOpenApi($invalid, 'post', '/setup-checklist/{code}/skip', 409);
    setCall($this->admin, 'post', '/setup-checklist/jobs/reopen')->assertStatus(409);
    setCall($this->admin, 'post', '/setup-checklist/no-existe/skip')->assertStatus(404);

    $reopen = setCall($this->admin, 'post', '/setup-checklist/sites/reopen')->assertOk();
    $this->assertMatchesOpenApi($reopen, 'post', '/setup-checklist/{code}/reopen', 200);
    expect(setStep($reopen->json('data'), 'sites')['status'])->toBe('pending');

    // Retomar donde quedó: el avance queda guardado en la organización.
    TenancyScope::runAs(SET_TENANT, fn () => expect(DB::table('setup_checklist_items')->where('code', 'company')->value('status'))->toBe('done'));

    $hidden = setCall($this->admin, 'post', '/setup-checklist/dismiss', ['hidden' => true])->assertOk();
    $this->assertMatchesOpenApi($hidden, 'post', '/setup-checklist/dismiss', 200);
    expect($hidden->json('data.visible'))->toBeFalse()->and($hidden->json('data.dismissed'))->toBeTrue();
    expect(setCall($this->admin, 'post', '/setup-checklist/dismiss', ['hidden' => false])->json('data.visible'))->toBeTrue();
    setCall($this->admin, 'post', '/setup-checklist/dismiss', [])->assertStatus(422);
});

test('con todo hecho u omitido el asistente se oculta y avisa una sola vez', function (): void {
    $codes = array_column(setCall($this->admin, 'get', '/setup-checklist')->json('data.steps'), 'code');
    foreach ($codes as $code) {
        setCall($this->admin, 'post', '/setup-checklist/'.$code.'/skip')->assertOk();
    }
    $data = setCall($this->admin, 'get', '/setup-checklist')->json('data');
    expect($data['progress'])->toBe(100)->and($data['completed'])->toBeTrue()->and($data['visible'])->toBeFalse();
    TenancyScope::runAs(SET_TENANT, fn () => expect(DB::table('outbox_events')->where('event_type', 'onboarding_setup.completed.v1')->count())->toBe(1));
});

test('sin permiso 403, con el módulo apagado 403 module_not_enabled y la otra organización no ve el avance ajeno', function (): void {
    $employee = IdentityFixtures::userWithPermissions(SET_TENANT, ['people.employments.view']);
    $denied = setCall($employee, 'get', '/setup-checklist')->assertStatus(403);
    $this->assertMatchesOpenApi($denied, 'get', '/setup-checklist', 403);
    setCall($employee, 'post', '/setup/country-packs/pe-base/apply')->assertStatus(403);

    setCall($this->admin, 'post', '/setup-checklist/sites/skip')->assertOk();
    $other = IdentityFixtures::userWithPermissions(SET_OTHER, ['imports.setup.use']);
    expect(setStep(setCall($other, 'get', '/setup-checklist')->json('data'), 'sites')['status'])->toBe('pending');
    TenancyScope::runAs(SET_OTHER, fn () => expect(DB::table('tenant_country_packs')->count())->toBe(0));

    IdentityFixtures::disableModule(SET_TENANT, 'core');
    setCall($this->admin, 'get', '/setup-checklist')->assertStatus(403)->assertJsonPath('error.code', 'module_not_enabled');
});
