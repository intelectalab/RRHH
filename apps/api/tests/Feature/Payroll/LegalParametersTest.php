<?php

/**
 * PAR-1 a PAR-7 · Parámetros legales con vigencia: historial y norma, valor propio solo más favorable, confirmación
 * de un «por validar», tablas de AFP por periodo (consola y motor), escala de quinta con vigencia futura, parámetros
 * requeridos por régimen, tabla salarial por convenio con verificación y vencimiento, actualización normativa con
 * doble revisión y novedades con los periodos que tocan.
 */

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Payroll\Application\PayrollEngine;
use App\Modules\Payroll\Infrastructure\PayrollParameters;
use App\Modules\Platform\Infrastructure\Models\PlatformUser;
use App\Shared\LegalParameters\LegalParameters;
use App\Shared\Permissions\ModuleGate;
use App\Shared\Permissions\PermissionResolver;
use App\Shared\Security\Totp;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\IdentityFixtures;
use Tests\Support\PayrollFixtures;

uses(DatabaseTransactions::class);

const PAR_TENANT = 'aaaaaaaa-0a01-4000-8000-00000000000a';
const PAR_OTHER = 'bbbbbbbb-0a01-4000-8000-00000000000b';
const PAR_PASSWORD = 'Consola-Planilla-2026!';

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requiere PostgreSQL.');
    }
    TenancyScope::clear();
    app(LegalParameters::class)->sync('PE');
    foreach (['par-duena' => 'superadmin', 'par-soporte' => 'support', 'par-prepara' => 'superadmin', 'par-revisa' => 'superadmin', 'par-lectura' => 'readonly', 'par-novedad' => 'superadmin', 'par-novedad2' => 'superadmin'] as $name => $role) {
        PlatformUser::create(['email' => $name.'@gestia.test', 'full_name' => 'Consola '.$name, 'role' => $role, 'status' => 'active', 'password_hash' => Hash::make(PAR_PASSWORD)]);
    }
    foreach ([PAR_TENANT => 'par-org', PAR_OTHER => 'par-otra'] as $id => $slug) {
        DB::table('tenants')->insertOrIgnore(['id' => $id, 'slug' => $slug, 'name' => 'Grupo '.$slug, 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN']);
        DB::select('SELECT seed_tenant_defaults(?)', [$id]);
        TenancyScope::runAs($id, fn () => IdentityFixtures::enableAllModules());
    }
    $this->payroll = parUser(PAR_TENANT, 'payroll');
    $this->employee = parUser(PAR_TENANT, 'empleado');
    $this->other = parUser(PAR_OTHER, 'payroll');
    $this->entity = TenancyScope::runAs(PAR_TENANT, fn () => PayrollFixtures::entity());
    TenancyScope::runAs(PAR_OTHER, fn () => PayrollFixtures::entity());
});

afterEach(fn () => TenancyScope::clear());

function parUser(string $tenant, string $role): User
{
    return TenancyScope::runAs($tenant, function () use ($role): User {
        $u = User::factory()->create();
        $u->roleAssignments()->create(['role_id' => Role::query()->where('system_role_code', $role)->value('id')]);

        return $u;
    });
}

/** @param array<string, mixed> $body */
function parCall(User $user, string $method, string $path, array $body = []): TestResponse
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

/** @param array<string, mixed> $body */
function parConsole(string $token, string $method, string $path, array $body = []): TestResponse
{
    TenancyScope::clear();
    app('auth')->forgetGuards();
    $headers = ['Idempotency-Key' => (string) Str::uuid(), 'Origin' => 'http://consola.localhost:3000',
        'X-XSRF-TOKEN' => hash_hmac('sha256', 'console-xsrf|'.$token, (string) config('app.key'))];

    return test()->flushHeaders()->withCredentials()->withUnencryptedCookie('gestia_console_session', $token)
        ->withHeaders($headers)->json($method, '/api/v1/platform'.$path, $body);
}

function parConsoleUser(string $email): string
{

    TenancyScope::clear();
    app('auth')->forgetGuards();
    $login = test()->flushHeaders()->withUnencryptedCookie('gestia_console_session', '')
        ->withHeaders(['Origin' => 'http://consola.localhost:3000'])->json('POST', '/api/v1/platform/auth/login', ['email' => $email, 'password' => PAR_PASSWORD])->assertOk();
    $token = (string) $login->getCookie('gestia_console_session', false)?->getValue();
    $secret = (string) parConsole($token, 'POST', '/auth/mfa/totp')->assertOk()->json('data.secret');
    parConsole($token, 'POST', '/auth/mfa/totp/confirm', ['code' => Totp::at(Totp::base32Decode($secret), Totp::currentStep())])->assertOk();

    return $token;
}

function parId(string $code, ?string $regime = null, ?string $asOf = null): string
{
    $q = DB::table('legal_parameters')->where('country_code', 'PE')->where('code', $code);
    $regime === null ? $q->whereNull('labor_regime_code') : $q->where('labor_regime_code', $regime);
    if ($asOf !== null) {
        $q->where('valid_from', '<=', $asOf)->where(fn ($w) => $w->whereNull('valid_to')->orWhere('valid_to', '>=', $asOf));
    }

    return (string) $q->orderByDesc('valid_from')->value('id');
}

test('PAR-1 · los parámetros muestran valor vigente, norma e historial; la organización no los edita', function (): void {
    $r = parCall($this->payroll, 'GET', '/legal-parameters?as_of=2026-06-01&filter[code]=uit')->assertOk();
    $this->assertMatchesOpenApi($r, 'get', '/legal-parameters', 200);
    expect($r->json('data'))->toHaveCount(1)
        ->and($r->json('data.0.value'))->toBe(DB::table('legal_parameters')->where('code', 'uit')->where('valid_from', '2026-01-01')->value('value_numeric') === null ? null : LegalParameters::valueOf(DB::table('legal_parameters')->where('code', 'uit')->where('valid_from', '2026-01-01')->first()))
        ->and($r->json('data.0.legal_source'))->not->toBeNull();

    $show = parCall($this->payroll, 'GET', '/legal-parameters/'.$r->json('data.0.id'))->assertOk();
    $this->assertMatchesOpenApi($show, 'get', '/legal-parameters/{id}', 200);
    expect(count($show->json('data.history')))->toBeGreaterThanOrEqual(2)
        ->and($show->json('data.history.0.valid_from'))->toBe('2026-01-01')
        ->and($show->json('data.adjustable'))->toBeNull();

    $pending = parCall($this->payroll, 'GET', '/legal-parameters?filter[pending]=1')->assertOk();
    expect(collect($pending->json('data'))->every(fn ($p) => $p['pending_validation'] === true))->toBeTrue();

    parCall($this->employee, 'GET', '/legal-parameters')->assertForbidden();
    parCall($this->payroll, 'GET', '/legal-parameters?as_of=ayer')->assertStatus(422);
    parCall($this->payroll, 'GET', '/legal-parameters/'.Str::uuid())->assertNotFound();
    // La organización no publica valores del país: no hay ruta de edición del catálogo.
    parCall($this->payroll, 'PATCH', '/legal-parameters/'.$r->json('data.0.id'), ['value' => 1])->assertStatus(405);
});

test('PAR-1 · RN-PAR-07 · valor propio solo donde la norma lo permite y solo más favorable; el motor lo usa', function (): void {
    $uit = parId('uit', null, '2026-06-01');
    parCall($this->payroll, 'POST', "/legal-parameters/$uit/add-value", ['value' => 6000, 'valid_from' => '2026-06-01', 'reason' => 'Prueba'])
        ->assertStatus(409)->assertJsonPath('error.code', 'parameter_not_adjustable');

    $vac = parId('vacaciones_dias', 'pe_general', '2026-06-01');
    $legal = (string) LegalParameters::valueOf(DB::table('legal_parameters')->where('id', $vac)->first());
    parCall($this->payroll, 'POST', "/legal-parameters/$vac/add-value", ['value' => (int) $legal - 1, 'valid_from' => '2026-06-01', 'reason' => 'Menos'])
        ->assertStatus(422)->assertJsonPath('error.details.0.field', 'value');

    $ok = parCall($this->payroll, 'POST', "/legal-parameters/$vac/add-value", ['value' => (int) $legal + 5, 'valid_from' => '2026-06-01', 'reason' => 'Convenio interno'])->assertCreated();
    $this->assertMatchesOpenApi($ok, 'post', '/legal-parameters/{id}/add-value', 201);
    parCall($this->payroll, 'POST', "/legal-parameters/$vac/add-value", ['value' => (int) $legal + 6, 'valid_from' => '2026-05-01', 'reason' => 'Atrás'])->assertStatus(409);

    $resolved = TenancyScope::runAs(PAR_TENANT, fn () => (new PayrollParameters(app(LegalParameters::class), 'PE', 'pe_general', $this->entity, '2026-07-01'))->resolve('vacaciones_dias'));
    $before = TenancyScope::runAs(PAR_TENANT, fn () => (new PayrollParameters(app(LegalParameters::class), 'PE', 'pe_general', $this->entity, '2026-05-01'))->resolve('vacaciones_dias'));
    expect((float) $resolved['value'])->toBe((float) ((int) $legal + 5))->and($resolved['origin'])->toBe('organization')
        ->and($before['origin'])->toBe('country');
    // Otra organización no ve el valor propio.
    $other = TenancyScope::runAs(PAR_OTHER, fn () => (new PayrollParameters(app(LegalParameters::class), 'PE', 'pe_general', null, '2026-07-01'))->resolve('vacaciones_dias'));
    expect($other['origin'])->toBe('country');
    expect(DB::table('audit_events')->where('action', 'legal_parameter.own_value_added')->where('tenant_id', PAR_TENANT)->exists())->toBeTrue();
});

test('PAR-1 · RN-PAR-03 · un parámetro «por validar» con valor se confirma una vez; uno verificado no', function (): void {
    $id = (string) Str::uuid();
    DB::table('legal_parameters')->insert(['id' => $id, 'country_code' => 'PE', 'code' => 'par_prueba_tasa', 'name' => 'Tasa de prueba', 'value_numeric' => '1.5',
        'unit' => 'percent', 'valid_from' => '2026-01-01', 'verified' => false, 'legal_source' => 'Norma de prueba']);
    $r = parCall($this->payroll, 'POST', "/legal-parameters/$id/confirm", ['note' => 'Revisado con el contador'])->assertOk();
    $this->assertMatchesOpenApi($r, 'post', '/legal-parameters/{id}/confirm', 200);
    expect($r->json('data.confirmed'))->toBeTrue()->and($r->json('data.confirmations'))->toHaveCount(1);
    parCall($this->payroll, 'POST', "/legal-parameters/$id/confirm")->assertStatus(409)->assertJsonPath('error.code', 'parameter_already_confirmed');
    // La confirmación es de cada organización.
    expect(parCall($this->other, 'GET', "/legal-parameters/$id")->assertOk()->json('data.confirmed'))->toBeFalse();
    parCall($this->payroll, 'POST', '/legal-parameters/'.parId('uit', null, '2026-06-01').'/confirm')->assertStatus(409)->assertJsonPath('error.code', 'parameter_already_verified');
    parCall($this->employee, 'POST', "/legal-parameters/$id/confirm")->assertForbidden();
    expect(fn () => DB::table('legal_parameter_confirmations')->where('legal_parameter_id', $id)->update(['note' => 'x']))->toThrow(Exception::class);
});

test('PAR-2 · la consola carga las tasas de una AFP por periodo y el cálculo usa las del periodo; sin tabla, falta', function (): void {
    $owner = parConsoleUser('par-duena@gestia.test');
    $r = parConsole($owner, 'POST', '/pension-fund-rates', ['fund_code' => 'pe_afp_integra', 'valid_from' => '2026-05-01', 'contribution_rate' => '10',
        'commission_flow_rate' => '1.55', 'insurance_premium_rate' => '1.37', 'max_insurable_remuneration' => '12000.50', 'source' => 'SBS, boletín de prueba'])->assertCreated();
    $this->assertMatchesOpenApi($r, 'post', '/platform/pension-fund-rates', 201);
    $file = UploadedFile::fake()->createWithContent('afp.csv', "fund_code,valid_from,contribution_rate,commission_flow_rate,insurance_premium_rate,max_insurable_remuneration,source\npe_afp_integra,2026-06-01,10,1.60,1.37,12100,SBS junio\n");
    parConsole($owner, 'POST', '/pension-fund-rates/import', ['file' => $file])->assertCreated()->assertJsonPath('data.loaded', 1);
    parConsole($owner, 'POST', '/pension-fund-rates', ['fund_code' => 'pe_afp_integra', 'valid_from' => '2026-05-15', 'contribution_rate' => '10', 'source' => 'x'])->assertStatus(422);

    expect(DB::table('pension_fund_rates AS r')->join('pension_funds AS f', 'f.id', '=', 'r.pension_fund_id')->where('f.code', 'pe_afp_integra')->where('r.valid_from', '2026-05-01')->value('r.valid_to'))->toBe('2026-05-31');

    $may = parCall($this->payroll, 'GET', '/pension-fund-rates?as_of=2026-05-20')->assertOk();
    $this->assertMatchesOpenApi($may, 'get', '/pension-fund-rates', 200);
    $integra = collect($may->json('data.funds'))->firstWhere('fund_code', 'pe_afp_integra');
    expect($integra['commission_flow_rate'])->toBe('1.55')->and($may->json('data.missing'))->not->toContain('pe_afp_integra')
        ->and($may->json('data.missing'))->toContain('pe_afp_habitat');

    $employment = TenancyScope::runAs(PAR_TENANT, function (): string {
        $e = PayrollFixtures::employment($this->entity, '2025-01-01', [['amount' => '3000.00', 'valid_from' => '2025-01-01']]);
        DB::table('employment_pension_affiliations')->insert(['tenant_id' => PAR_TENANT, 'employment_id' => $e, 'pension_system' => 'afp',
            'pension_fund_code' => 'pe_afp_integra', 'commission_scheme' => 'flow', 'valid_from' => '2025-01-01']);

        return $e;
    });
    $resolve = fn (string $date) => TenancyScope::runAs(PAR_TENANT, fn () => (new PayrollParameters(app(LegalParameters::class), 'PE', 'pe_general', $this->entity, $date, $employment))->resolve('afp_comision_flujo_pct'));
    expect($resolve('2026-05-31')['value'])->toBe('1.55')->and($resolve('2026-06-30')['value'])->toBe('1.6')
        ->and($resolve('2026-06-30')['origin'])->toBe('pension_fund:pe_afp_integra')
        ->and($resolve('2026-04-30'))->toBeNull();

    // Soporte no carga tasas.
    $support = parConsoleUser('par-soporte@gestia.test');
    parConsole($support, 'POST', '/pension-fund-rates', ['fund_code' => 'pe_afp_integra', 'valid_from' => '2026-07-01', 'contribution_rate' => '10', 'source' => 'x'])->assertForbidden();
});

test('PAR-3 · PAR-4 · un cambio de escala con vigencia futura publicado con doble revisión no altera periodos anteriores', function (): void {
    $before = parCall($this->payroll, 'GET', '/fifth-category-scale?as_of=2026-06-30')->assertOk();
    $this->assertMatchesOpenApi($before, 'get', '/fifth-category-scale', 200);
    $scale = $before->json('data.scale.value');
    expect($scale)->toBeArray()->and($before->json('data.deduction_uit.value'))->not->toBeNull();

    $owner = parConsoleUser('par-prepara@gestia.test');
    $reviewer = parConsoleUser('par-revisa@gestia.test');
    $newScale = array_map(fn ($t) => ['hasta_uit' => $t['hasta_uit'], 'tasa' => $t['tasa'] + 1], $scale);
    $c = parConsole($owner, 'POST', '/legal-parameter-publications', ['country' => 'PE', 'title' => 'Escala de quinta de prueba', 'legal_source' => 'Ley de prueba 2027',
        'changes' => [['code' => 'quinta_escala', 'value_json' => $newScale, 'valid_from' => '2027-01-01']]])->assertCreated();
    $this->assertMatchesOpenApi($c, 'post', '/platform/legal-parameter-publications', 201);
    $id = $c->json('data.id');
    parConsole($owner, 'POST', "/legal-parameter-publications/$id/publish")->assertStatus(409);
    parConsole($owner, 'POST', "/legal-parameter-publications/$id/submit")->assertOk()->assertJsonPath('data.status', 'in_review');
    parConsole($owner, 'POST', "/legal-parameter-publications/$id/publish")->assertStatus(409)->assertJsonPath('error.code', 'same_reviewer');
    $pub = parConsole($reviewer, 'POST', "/legal-parameter-publications/$id/publish")->assertOk()->assertJsonPath('data.status', 'published');
    $this->assertMatchesOpenApi($pub, 'post', '/platform/legal-parameter-publications/{id}/publish', 200);
    parConsole($reviewer, 'POST', "/legal-parameter-publications/$id/publish")->assertStatus(409);

    expect(parCall($this->payroll, 'GET', '/fifth-category-scale?as_of=2026-06-30')->json('data.scale.value'))->toEqual($scale)
        ->and(parCall($this->payroll, 'GET', '/fifth-category-scale?as_of=2027-02-01')->json('data.scale.value'))->toEqual($newScale);
    // El valor anterior queda cerrado, no modificado (RN-PAR-02).
    $old = DB::table('legal_parameters')->where('code', 'quinta_escala')->where('valid_from', '<', '2027-01-01')->orderByDesc('valid_from')->first();
    expect(substr((string) $old->valid_to, 0, 10))->toBe('2026-12-31')->and(json_decode((string) $old->value_json, true))->toEqual($scale);
    // RN-PAR-06 · Un aviso por organización del país.
    foreach ([PAR_TENANT, PAR_OTHER] as $t) {
        expect(TenancyScope::runAs($t, fn () => DB::table('outbox_events')->where('event_type', 'legal_parameters.published.v1')->where('aggregate_id', $id)->count()))->toBe(1);
    }

    // Una vigencia que no es posterior a la última no se publica (nada se aplica).
    $bad = parConsole($owner, 'POST', '/legal-parameter-publications', ['country' => 'PE', 'title' => 'Choque', 'legal_source' => 'Norma',
        'changes' => [['code' => 'rmv', 'value' => '1200', 'valid_from' => '2027-01-01'], ['code' => 'quinta_escala', 'value_json' => $scale, 'valid_from' => '2026-06-01']]])->json('data.id');
    parConsole($owner, 'POST', "/legal-parameter-publications/$bad/submit")->assertOk();
    parConsole($reviewer, 'POST', "/legal-parameter-publications/$bad/publish")->assertStatus(422);
    expect(DB::table('legal_parameters')->where('code', 'rmv')->where('valid_from', '2027-01-01')->exists())->toBeFalse();
    $readonly = parConsoleUser('par-lectura@gestia.test');
    parConsole($readonly, 'POST', '/legal-parameter-publications', ['country' => 'PE', 'title' => 'x', 'legal_source' => 'x', 'changes' => [['code' => 'rmv', 'value' => 1, 'valid_from' => '2030-01-01']]])->assertForbidden();
});

test('PAR-5 · el régimen informa sus parámetros y cuál falta; sin un requerido no se calcula', function (): void {
    // Una vigencia futura sin valor de un parámetro requerido del régimen deja el cálculo «con error» con el nombre.
    $cts = DB::table('legal_parameters')->where('code', 'cts_dias_por_anio')->whereNull('labor_regime_code')->orderByDesc('valid_from')->first();
    DB::table('legal_parameters')->where('id', $cts->id)->update(['valid_to' => '2026-12-31']);
    DB::table('legal_parameters')->insert(['country_code' => 'PE', 'code' => 'cts_dias_por_anio', 'name' => (string) $cts->name, 'unit' => 'days', 'valid_from' => '2027-01-01', 'verified' => false]);

    $r = parCall($this->payroll, 'GET', '/payroll-regimes/pe_general/parameters?as_of=2026-06-01')->assertOk();
    $this->assertMatchesOpenApi($r, 'get', '/payroll-regimes/{id}/parameters', 200);
    expect($r->json('data.can_calculate'))->toBeTrue()->and($r->json('data.missing'))->toBe([])
        ->and(collect($r->json('data.parameters'))->firstWhere('code', 'vacaciones_dias')['required'])->toBeTrue();

    $missing = parCall($this->payroll, 'GET', '/payroll-regimes/pe_general/parameters?as_of=2027-02-01')->assertOk();
    expect($missing->json('data.can_calculate'))->toBeFalse()->and($missing->json('data.missing'))->toBe(['cts_dias_por_anio']);

    $employment = TenancyScope::runAs(PAR_TENANT, fn () => PayrollFixtures::employment($this->entity, '2025-01-01', [['amount' => '3000.00', 'valid_from' => '2025-01-01']]));
    $calc = TenancyScope::runAs(PAR_TENANT, fn () => app(PayrollEngine::class)->calculate($employment, '2027-02-01', '2027-02-28'));
    expect($calc['result']['status'])->toBe('error')->and(json_encode($calc['result']['errors']))->toContain('cts_dias_por_anio');
    parCall($this->payroll, 'GET', '/payroll-regimes/no_existe/parameters')->assertNotFound();
});

test('PAR-6 · la tabla salarial por convenio es un lote del paquete: se verifica una vez y vencida se advierte, no se bloquea', function (): void {
    $list = parCall($this->payroll, 'GET', '/wage-tables?as_of=2026-06-01')->assertOk();
    $this->assertMatchesOpenApi($list, 'get', '/wage-tables', 200);
    $table = collect($list->json('data'))->firstWhere('code', 'pe_construccion_civil');
    expect($table)->not->toBeNull()->and($table['expired'])->toBeFalse()->and($table['verified'])->toBeFalse()
        ->and(collect($table['values'])->pluck('code'))->toContain('cc_jornal_basico');

    $late = parCall($this->payroll, 'GET', '/wage-tables/'.$table['id'].'?as_of=2027-03-01')->assertOk();
    $this->assertMatchesOpenApi($late, 'get', '/wage-tables/{id}', 200);
    expect($late->json('data.expired'))->toBeTrue()->and($late->json('data.retroactive_impact'))->toBe([]);

    parCall($this->payroll, 'POST', '/wage-tables/'.$table['id'].'/verify', [])->assertStatus(422);
    $v = parCall($this->payroll, 'POST', '/wage-tables/'.$table['id'].'/verify', ['acta_reference' => 'Acta 2025-2026 FTCCP-CAPECO'])->assertOk();
    $this->assertMatchesOpenApi($v, 'post', '/wage-tables/{id}/verify', 200);
    expect($v->json('data.verified'))->toBeTrue();
    parCall($this->payroll, 'POST', '/wage-tables/'.$table['id'].'/verify', ['acta_reference' => 'Otra'])->assertStatus(409);
    expect(parCall($this->other, 'GET', '/wage-tables/'.$table['id'])->json('data.verified'))->toBeFalse();
    parCall($this->employee, 'POST', '/wage-tables/'.$table['id'].'/verify', ['acta_reference' => 'x'])->assertForbidden();
    // Los plazos legales distinguen días hábiles de calendario en su unidad (RN-PAR-09).
    expect(DB::table('legal_parameters')->where('code', 'like', '%dias_habiles')->where('unit', '!=', 'business_days')->count())->toBe(0);
});

test('PAR-7 · las novedades muestran valor anterior y nuevo, norma y los periodos que tocan; se filtran por régimen', function (): void {
    [$open, $closed] = TenancyScope::runAs(PAR_TENANT, function (): array {
        $group = (string) Str::uuid();
        DB::table('payroll_groups')->insert(['id' => $group, 'tenant_id' => PAR_TENANT, 'code' => 'MENSUAL', 'name' => 'Mensual', 'legal_entity_id' => $this->entity, 'currency' => 'PEN']);
        $open = (string) Str::uuid();
        $closed = (string) Str::uuid();
        DB::table('payroll_periods')->insert([
            ['id' => $open, 'tenant_id' => PAR_TENANT, 'payroll_group_id' => $group, 'period_year' => 2027, 'period_number' => 2, 'starts_on' => '2027-02-01', 'ends_on' => '2027-02-28', 'status' => 'open'],
            ['id' => $closed, 'tenant_id' => PAR_TENANT, 'payroll_group_id' => $group, 'period_year' => 2027, 'period_number' => 1, 'starts_on' => '2027-01-01', 'ends_on' => '2027-01-31', 'status' => 'closed'],
        ]);

        return [$open, $closed];
    });
    $owner = parConsoleUser('par-novedad@gestia.test');
    $reviewer = parConsoleUser('par-novedad2@gestia.test');
    $id = parConsole($owner, 'POST', '/legal-parameter-publications', ['country' => 'PE', 'title' => 'Nueva RMV', 'legal_source' => 'DS de prueba 2027',
        'changes' => [['code' => 'rmv', 'value' => '1200', 'valid_from' => '2027-01-01']]])->json('data.id');
    parConsole($owner, 'POST', "/legal-parameter-publications/$id/submit")->assertOk();
    parConsole($reviewer, 'POST', "/legal-parameter-publications/$id/publish")->assertOk();

    $r = parCall($this->payroll, 'GET', '/legal-updates')->assertOk();
    $this->assertMatchesOpenApi($r, 'get', '/legal-updates', 200);
    $rmv = collect($r->json('data'))->firstWhere('code', 'rmv');
    expect($rmv['new_value'])->toBe('1200')->and($rmv['previous_value'])->not->toBeNull()->and($rmv['legal_source'])->toBe('DS de prueba 2027')
        ->and($rmv['valid_from'])->toBe('2027-01-01')->and(collect($rmv['open_periods'])->pluck('id')->all())->toBe([$open])
        ->and(collect($rmv['closed_periods'])->pluck('id')->all())->toBe([$closed])
        ->and($rmv['suggests_simulation'])->toBeTrue()->and($rmv['suggests_complementary'])->toBeTrue();
    // El periodo cerrado no se tocó.
    expect(DB::table('payroll_periods')->where('id', $closed)->value('status'))->toBe('closed');
    // Otra organización ve la novedad, pero no los periodos de esta.
    $theirs = collect(parCall($this->other, 'GET', '/legal-updates')->json('data'))->firstWhere('code', 'rmv');
    expect($theirs['open_periods'])->toBe([])->and($theirs['closed_periods'])->toBe([]);
    expect(collect(parCall($this->payroll, 'GET', '/legal-updates?filter[regime]=pe_construccion&from=2020-01-01')->json('data'))->pluck('code'))->toContain('rmv');
    parCall($this->payroll, 'GET', '/legal-updates?from=ayer')->assertStatus(422);

    // RN-PAR-06 · El responsable de planilla recibe el aviso (entregado por el outbox al publicar); con un periodo
    // abierto, el de simular antes de cerrar.
    expect(TenancyScope::runAs(PAR_TENANT, fn () => DB::table('notifications')->where('recipient_user_id', $this->payroll->id)->where('category', 'payroll.legal_update_open_period')->count()))->toBe(1)
        ->and(TenancyScope::runAs(PAR_TENANT, fn () => DB::table('notifications')->where('recipient_user_id', $this->employee->id)->count()))->toBe(0);
});
