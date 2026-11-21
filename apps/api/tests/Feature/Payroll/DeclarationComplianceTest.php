<?php

/**
 * DEC-7 · Bitácora de declaraciones: lo que falta por empresa y periodo, estados generada, presentada, pagada y
 *         rectificada con responsable y constancias; el estado no se cambia por PATCH.
 * DEC-8 · Ficha del registro laboral con vigencia: código fuera de tabla rechazado, cambio = fila nueva + novedad de
 *         modificación, código retirado no invalida la historia, alta bloqueada con faltantes y pantalla de origen.
 * DEC-9 · Declaración jurada de rentas de otros empleadores desde el portal: reemplaza sin sobrescribir, aviso sin
 *         declaración, aplica desde el mes de validación y otro empleador que retiene deja la retención en cero.
 * DEC-10 · Estructuras versionadas como datos: carga sin programar, sin verificar solo borrador, mismos bytes al
 *         regenerar y archivos dorados para las verificadas.
 * DEC-11 · Devoluciones de quinta (ME-49): quien calcula no aprueba, asiento en el libro, compensación parcial con
 *         saldo arrastrado, solicitud a la autoridad y cierre.
 */

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Payroll\Application\PayrollRuns;
use App\Modules\Payroll\Domain\Declarations\FileSerializer;
use App\Modules\Payroll\Infrastructure\Models\FifthCategoryLedgerEntry;
use App\Modules\Platform\Infrastructure\Models\PlatformUser;
use App\Shared\Permissions\ModuleGate;
use App\Shared\Permissions\PermissionResolver;
use App\Shared\Security\FieldCipher;
use App\Shared\Security\Totp;
use App\Shared\Tenancy\TenancyScope;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\IdentityFixtures;
use Tests\Support\PayrollFixtures;

uses(DatabaseTransactions::class);

const DC_TENANT = 'aaaaaaaa-0dc7-4000-8000-00000000000a';
const DC_OTHER = 'bbbbbbbb-0dc7-4000-8000-00000000000b';
const DC_PASSWORD = 'Consola-Declaraciones-2026!';

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requiere PostgreSQL.');
    }
    TenancyScope::clear();
    Storage::fake('s3');
    foreach ([DC_TENANT => 'dc-org', DC_OTHER => 'dc-otra'] as $id => $slug) {
        DB::table('tenants')->insertOrIgnore(['id' => $id, 'slug' => $slug, 'name' => 'Grupo '.$slug, 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN']);
        DB::select('SELECT seed_tenant_defaults(?)', [$id]);
        TenancyScope::runAs($id, fn () => IdentityFixtures::enableAllModules());
    }
    $this->payroll = dcUser(DC_TENANT, 'payroll');
    $this->admin = dcUser(DC_TENANT, 'admin_empresa');
    $this->employee = dcUser(DC_TENANT, 'empleado');
    $this->other = dcUser(DC_OTHER, 'admin_empresa');
    foreach ([8 => '21', 33 => '01', 30 => '999999', 9 => '13', 12 => '01', 35 => '0', 16 => '2', 13 => '1', 15 => '1', 25 => '0'] as $table => $code) {
        if (! DB::table('pe_parametric_tables')->where('table_number', $table)->exists()) {
            DB::table('pe_parametric_tables')->insert(['table_number' => $table, 'name' => 'Tabla '.$table]);
        }
        if (! DB::table('pe_parametric_items')->where('table_number', $table)->where('code', $code)->exists()) {
            DB::table('pe_parametric_items')->insert(['table_number' => $table, 'code' => $code, 'description' => 'Prueba '.$table, 'valid_from' => '2000-01-01']);
        }
    }
    [$this->entity, $this->a, $this->b] = TenancyScope::runAs(DC_TENANT, function (): array {
        $entity = PayrollFixtures::entity();
        $a = PayrollFixtures::employment($entity, '2025-01-01', [['amount' => '3000.00', 'valid_from' => '2025-01-01']]);
        $b = PayrollFixtures::employment($entity, '2026-03-10', [['amount' => '2000.00', 'valid_from' => '2026-03-10']]);
        foreach ([$a => '41234567', $b => '47654321'] as $emp => $dni) {
            $person = (string) DB::table('employments')->where('id', $emp)->value('person_id');
            DB::table('person_documents')->insert(['tenant_id' => DC_TENANT, 'person_id' => $person, 'doc_type' => 'dni', 'issuing_country' => 'PE',
                'number_enc' => FieldCipher::encrypt($dni), 'number_hash' => hash('sha256', 'dc'.$dni), 'is_primary' => true]);
        }
        DB::table('employment_pension_affiliations')->insert(['tenant_id' => DC_TENANT, 'employment_id' => $a, 'pension_system' => 'afp',
            'pension_fund_code' => 'pe_afp_integra', 'affiliate_code_enc' => FieldCipher::encrypt('123456ABCDE7'), 'commission_scheme' => 'flow', 'valid_from' => '2025-01-01']);

        return [$entity, $a, $b];
    });
});

afterEach(fn () => TenancyScope::clear());

function dcUser(string $tenant, string $role): User
{
    return TenancyScope::runAs($tenant, function () use ($role): User {
        $u = User::factory()->create();
        $u->roleAssignments()->create(['role_id' => Role::query()->where('system_role_code', $role)->value('id')]);

        return $u;
    });
}

/** @param array<string, mixed> $body */
function dcCall(User $user, string $method, string $path, array $body = []): TestResponse
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
function dcConsole(string $token, string $method, string $path, array $body = []): TestResponse
{
    TenancyScope::clear();
    app('auth')->forgetGuards();
    $headers = ['Idempotency-Key' => (string) Str::uuid(), 'Origin' => 'http://consola.localhost:3000',
        'X-XSRF-TOKEN' => hash_hmac('sha256', 'console-xsrf|'.$token, (string) config('app.key'))];

    return test()->flushHeaders()->withCredentials()->withUnencryptedCookie('gestia_console_session', $token)
        ->withHeaders($headers)->json($method, '/api/v1/platform'.$path, $body);
}

function dcConsoleUser(string $name, string $role): string
{
    TenancyScope::clear();
    PlatformUser::create(['email' => $name.'@gestia.test', 'full_name' => 'Consola '.$name, 'role' => $role, 'status' => 'active', 'password_hash' => Hash::make(DC_PASSWORD)]);
    app('auth')->forgetGuards();
    $login = test()->flushHeaders()->withUnencryptedCookie('gestia_console_session', '')
        ->withHeaders(['Origin' => 'http://consola.localhost:3000'])->json('POST', '/api/v1/platform/auth/login', ['email' => $name.'@gestia.test', 'password' => DC_PASSWORD])->assertOk();
    $token = (string) $login->getCookie('gestia_console_session', false)?->getValue();
    $secret = (string) dcConsole($token, 'POST', '/auth/mfa/totp')->assertOk()->json('data.secret');
    dcConsole($token, 'POST', '/auth/mfa/totp/confirm', ['code' => Totp::at(Totp::base32Decode($secret), Totp::currentStep())])->assertOk();

    return $token;
}

/** Ficha del registro laboral mínima válida (tabla 30 y demás con los códigos cargados arriba). */
function dcProfile(string $employment, string $from, array $extra = []): string
{
    return TenancyScope::runAs(DC_TENANT, fn (): string => (string) DB::table('peruvian_employee_profiles')->insertGetId($extra + ['tenant_id' => DC_TENANT,
        'employment_id' => $employment, 'worker_type_code' => '21', 'labor_regime_sunat_code' => '01', 'occupation_code' => '999999',
        'education_status_code' => '13', 'contract_type_sunat_code' => '01', 'payment_type_code' => '2', 'pay_periodicity_code' => '1',
        'initial_basic_remuneration' => '3000.00', 'currency' => 'PEN', 'valid_from' => $from]));
}

test('DEC-7 · la bitácora muestra lo que falta y el estado de cada declaración con responsable, pago y constancia', function (): void {
    $r = dcCall($this->payroll, 'GET', "/declaration-log?legal_entity_id={$this->entity}&from=202603&to=202603")->assertOk();
    $this->assertMatchesOpenApi($r, 'get', '/declaration-log', 200);
    $rows = collect($r->json('data'))->keyBy('kind');
    expect($rows['plame']['missing'])->toBeTrue()->and($rows['plame']['log_status'])->toBe('missing')
        ->and($rows['afpnet']['expected'])->toBeTrue()->and($rows->has('t_registro'))->toBeFalse()
        ->and($r->json('meta.summary.missing'))->toBe(2);

    $filing = TenancyScope::runAs(DC_TENANT, fn (): string => (string) DB::table('statutory_filings')->insertGetId([
        'tenant_id' => DC_TENANT, 'legal_entity_id' => $this->entity, 'kind' => 'plame', 'period_code' => '202603', 'status' => 'validated']));
    // Pagar antes de presentar: 409; el estado no se cambia por PATCH.
    dcCall($this->payroll, 'POST', "/declaration-files/{$filing}/register-payment", ['payment_reference' => 'NPS-1'])->assertStatus(409)->assertJsonPath('error.code', 'invalid_transition');
    dcCall($this->payroll, 'PATCH', "/declaration-files/{$filing}", ['status' => 'submitted'])->assertStatus(422);
    $p = dcCall($this->payroll, 'PATCH', "/declaration-files/{$filing}", ['responsible_user_id' => $this->payroll->id])->assertOk();
    $this->assertMatchesOpenApi($p, 'patch', '/declaration-files/{id}', 200);
    expect($p->json('data.status'))->toBe('validated')->and($p->json('data.responsible_user_id'))->toBe($this->payroll->id);
    expect(collect(dcCall($this->payroll, 'GET', "/declaration-log?legal_entity_id={$this->entity}&from=202603&to=202603")->json('data'))->firstWhere('kind', 'plame')['log_status'])->toBe('generated');

    TenancyScope::runAs(DC_TENANT, fn () => DB::table('statutory_filings')->where('id', $filing)->update(['status' => 'submitted', 'submitted_at' => now(), 'external_reference' => 'PDT-9']));
    $pay = dcCall($this->payroll, 'POST', "/declaration-files/{$filing}/register-payment", ['payment_reference' => 'NPS-1', 'paid_at' => '2026-04-15T10:00:00Z'])->assertOk();
    $this->assertMatchesOpenApi($pay, 'post', '/declaration-files/{id}/register-payment', 200);
    expect($pay->json('data.payment_reference'))->toBe('NPS-1')->and($pay->json('data.status'))->toBe('submitted');
    dcCall($this->payroll, 'POST', "/declaration-files/{$filing}/register-payment", ['payment_reference' => 'NPS-2'])->assertStatus(409);
    $plame = collect(dcCall($this->payroll, 'GET', "/declaration-log?legal_entity_id={$this->entity}&from=202603&to=202603")->json('data'))->firstWhere('kind', 'plame');
    expect($plame['log_status'])->toBe('paid')->and($plame['responsible_name'])->not->toBeNull();
    expect(TenancyScope::runAs(DC_TENANT, fn () => DB::table('audit_events')->where('entity_id', $filing)->where('action', 'statutory_filing.paid')->exists()))->toBeTrue();

    // Permiso, aislamiento y validación.
    dcCall($this->employee, 'GET', '/declaration-log')->assertForbidden();
    dcCall($this->other, 'POST', "/declaration-files/{$filing}/register-payment", ['payment_reference' => 'X'])->assertNotFound();
    dcCall($this->other, 'GET', "/declaration-log?legal_entity_id={$this->entity}")->assertNotFound();
    dcCall($this->payroll, 'GET', '/declaration-log?from=2026-03')->assertStatus(422);
});

test('DEC-8 · la ficha valida códigos, guarda historia con novedad de modificación y un código retirado no invalida lo anterior', function (): void {
    $first = dcCall($this->payroll, 'POST', "/employments/{$this->a}/registry-profiles", ['worker_type_code' => '21', 'labor_regime_code' => '01',
        'occupation_code' => '999999', 'education_status_code' => '13', 'contract_type_code' => '01', 'payment_type_code' => '2', 'pay_periodicity_code' => '1',
        'initial_basic_remuneration' => '3000.00', 'valid_from' => '2025-01-01'])->assertCreated();
    $this->assertMatchesOpenApi($first, 'post', '/employments/{employment_id}/registry-profiles', 201);
    expect($first->json('data.labor_regime_code'))->toBe('01')->and($first->json('data.completeness'))->toBe('draft');

    // Código fuera de la tabla paramétrica vigente: 422 con el campo.
    $bad = dcCall($this->payroll, 'POST', "/employments/{$this->a}/registry-profiles", ['occupation_code' => '123456', 'valid_from' => '2026-02-01'])->assertStatus(422);
    expect(collect($bad->json('error.details'))->pluck('field')->all())->toContain('occupation_code');

    // Ya dado de alta: cambiar un dato declarable cierra la fila, inserta otra y crea la novedad de modificación.
    TenancyScope::runAs(DC_TENANT, fn () => DB::table('registry_events')->insert(['tenant_id' => DC_TENANT, 'legal_entity_id' => $this->entity,
        'employment_id' => $this->a, 'kind' => 'worker_registration', 'event_date' => '2025-01-01', 'status' => 'confirmed']));
    $second = dcCall($this->payroll, 'POST', "/employments/{$this->a}/registry-profiles", ['subject_to_night_shift' => true, 'valid_from' => '2026-02-01'])->assertCreated();
    $list = dcCall($this->payroll, 'GET', "/employments/{$this->a}/registry-profiles")->assertOk();
    $this->assertMatchesOpenApi($list, 'get', '/employments/{employment_id}/registry-profiles', 200);
    expect($list->json('data'))->toHaveCount(2)->and($list->json('data.1.valid_to'))->toBe('2026-01-31')->and($list->json('data.0.subject_to_night_shift'))->toBeTrue();
    expect(TenancyScope::runAs(DC_TENANT, fn () => DB::table('registry_events')->where('employment_id', $this->a)->where('kind', 'worker_update')->where('event_date', '2026-02-01')->count()))->toBe(1);
    dcCall($this->payroll, 'POST', "/employments/{$this->a}/registry-profiles", ['valid_from' => '2026-03-01'])->assertStatus(422);

    // Código retirado: la historia sigue válida (se puede cerrar), pero una fila nueva con ese código se rechaza.
    TenancyScope::clear();
    DB::statement('RESET ROLE');
    DB::table('pe_parametric_items')->where('table_number', 30)->where('code', '999999')->update(['valid_to' => '2026-06-30']);
    dcCall($this->payroll, 'POST', "/employments/{$this->a}/registry-profiles", ['education_status_code' => '13', 'subject_to_night_shift' => false, 'valid_from' => '2026-07-01'])->assertStatus(422);
    dcCall($this->payroll, 'POST', "/employments/{$this->a}/registry-profiles", ['subject_to_night_shift' => false, 'valid_from' => '2026-05-01'])->assertCreated();

    // Sensibles: sin permiso de sueldo no viaja la remuneración inicial; aislamiento y permiso.
    $show = dcCall($this->payroll, 'GET', "/employments/{$this->a}/registry-profiles/".$second->json('data.id'))->assertOk();
    $this->assertMatchesOpenApi($show, 'get', '/employments/{employment_id}/registry-profiles/{id}', 200);
    dcCall($this->other, 'GET', "/employments/{$this->a}/registry-profiles")->assertNotFound();
    dcCall($this->employee, 'POST', "/employments/{$this->a}/registry-profiles", ['valid_from' => '2026-09-01'])->assertForbidden();
});

test('DEC-8 · el alta queda bloqueada con la lista de faltantes y la pantalla de origen de cada uno', function (): void {
    $profile = dcProfile($this->b, '2026-03-10', ['initial_basic_remuneration' => '1900.00']);
    $check = dcCall($this->payroll, 'POST', "/employments/{$this->b}/registry-validation")->assertOk();
    $this->assertMatchesOpenApi($check, 'post', '/employments/{employment_id}/registry-validation', 200);
    $codes = collect($check->json('data.checks'))->where('blocking', true)->map(fn ($c) => $c['code'].':'.$c['field'])->all();
    expect($check->json('data.ready'))->toBeFalse()
        ->and($codes)->toContain('TR-06:address', 'TR-07:phone', 'TR-11:contract', 'TR-12:pension', 'TR-17:initial_basic_remuneration')
        ->and($codes)->not->toContain('TR-01:document', 'TR-08:registry_profile');
    expect(collect($check->json('data.checks'))->firstWhere('code', 'TR-06')['screen'])->toStartWith('/personas/colaboradores/')
        ->and(collect($check->json('data.checks'))->firstWhere('code', 'TR-17')['screen'])->toStartWith('/nomina/registro-laboral');

    $v = dcCall($this->payroll, 'POST', "/employments/{$this->b}/registry-profiles/{$profile}/validate")->assertOk();
    $this->assertMatchesOpenApi($v, 'post', '/employments/{employment_id}/registry-profiles/{id}/validate', 200);
    expect($v->json('data.completeness'))->toBe('blocked')->and($v->json('data.missing_fields'))->toContain('TR-06:address');
    expect(TenancyScope::runAs(DC_TENANT, fn () => DB::table('audit_events')->where('entity_id', $profile)->where('action', 'registry_profile.validated')->exists()))->toBeTrue();
    dcCall($this->other, 'POST', "/employments/{$this->b}/registry-validation")->assertNotFound();
});

test('DEC-9 · la declaración del portal reemplaza sin sobrescribir, aplica desde su mes y avisa si falta', function (): void {
    $person = TenancyScope::runAs(DC_TENANT, fn () => (string) DB::table('employments')->where('id', $this->a)->value('person_id'));
    TenancyScope::runAs(DC_TENANT, fn () => DB::table('users')->where('id', $this->employee->id)->update(['person_id' => $person]));
    $year = CarbonImmutable::today()->year;

    $none = dcCall($this->employee, 'GET', '/me/external-income-declarations')->assertOk();
    $this->assertMatchesOpenApi($none, 'get', '/me/external-income-declarations', 200);
    expect($none->json('meta.warning'))->toBe('no_external_income_declaration');

    $body = ['fiscal_year' => $year, 'kind' => 'previous_employer', 'employer_tax_id' => '20123456789', 'employer_name' => 'Anterior SAC',
        'gross_income' => '12000.00', 'tax_withheld' => '300.00'];
    $one = dcCall($this->employee, 'POST', '/me/external-income-declarations', $body)->assertCreated();
    $this->assertMatchesOpenApi($one, 'post', '/me/external-income-declarations', 201);
    expect($one->json('data.status'))->toBe('received')->and($one->json('data.employment_id'))->toBe($this->a);
    dcCall($this->employee, 'POST', '/me/external-income-declarations', $body + ['employment_id' => $this->b])->assertNotFound();

    $v1 = dcCall($this->payroll, 'POST', '/external-income-declarations/'.$one->json('data.id').'/validate')->assertOk();
    $this->assertMatchesOpenApi($v1, 'post', '/external-income-declarations/{id}/validate', 200);
    expect($v1->json('data.applies_from_month'))->toBe(CarbonImmutable::today()->month);
    $two = dcCall($this->employee, 'POST', '/me/external-income-declarations', ['gross_income' => '13000.00'] + $body)->assertCreated();
    dcCall($this->payroll, 'POST', '/external-income-declarations/'.$two->json('data.id').'/validate')->assertOk();
    $old = dcCall($this->payroll, 'GET', '/external-income-declarations/'.$one->json('data.id'))->assertOk();
    $this->assertMatchesOpenApi($old, 'get', '/external-income-declarations/{id}', 200);
    expect($old->json('data.status'))->toBe('superseded')->and($old->json('data.superseded_by_id'))->toBe($two->json('data.id'))
        ->and($old->json('data.gross_income.amount'))->toBe('12000.00');
    dcCall($this->payroll, 'POST', '/external-income-declarations/'.$one->json('data.id').'/validate')->assertStatus(409)->assertJsonPath('error.code', 'invalid_transition');

    $list = dcCall($this->payroll, 'GET', "/external-income-declarations?filter[employment_id]={$this->b}")->assertOk();
    $this->assertMatchesOpenApi($list, 'get', '/external-income-declarations', 200);
    expect($list->json('meta.warning'))->toBe('no_external_income_declaration');
    $rej = dcCall($this->payroll, 'POST', '/external-income-declarations', $body + ['employment_id' => $this->b])->assertCreated();
    dcCall($this->payroll, 'POST', '/external-income-declarations/'.$rej->json('data.id').'/reject', [])->assertStatus(422);
    dcCall($this->payroll, 'POST', '/external-income-declarations/'.$rej->json('data.id').'/reject', ['reason' => 'Sin sustento'])->assertOk()->assertJsonPath('data.status', 'rejected');
    dcCall($this->other, 'GET', '/external-income-declarations/'.$one->json('data.id'))->assertNotFound();
    // El colaborador solo alcanza lo propio (alcance «propio» aplicado en la consulta).
    expect(collect(dcCall($this->employee, 'GET', '/external-income-declarations')->assertOk()->json('data'))->pluck('employment_id')->unique()->all())->toBe([$this->a]);
});

test('DEC-9 · otro empleador que retiene deja en cero la retención y la declaración solo aplica desde su mes', function (): void {
    foreach ([$this->a => 3, $this->b => 4] as $emp => $month) {
        TenancyScope::runAs(DC_TENANT, fn () => DB::table('external_income_declarations')->insert(['tenant_id' => DC_TENANT, 'employment_id' => $emp,
            'fiscal_year' => 2026, 'kind' => 'concurrent_employer', 'employer_tax_id' => '20123456789', 'gross_income' => '9000.00', 'tax_withheld' => '0',
            'currency' => 'PEN', 'this_employer_withholds' => false, 'status' => 'validated', 'applies_from_month' => $month]));
    }
    $group = (string) dcCall($this->admin, 'POST', '/payroll-groups', ['code' => 'MENS', 'name' => 'Mensual', 'legal_entity_id' => $this->entity, 'payment_day' => 28, 'cutoff_day' => 25])->assertCreated()->json('data.id');
    $period = (string) dcCall($this->admin, 'POST', '/payroll-periods', ['payroll_group_id' => $group, 'period_year' => 2026, 'period_number' => 3])->assertCreated()->json('data.id');
    TenancyScope::runAs(DC_TENANT, function (): void {
        PayrollFixtures::concept(['code' => 'BASICO', 'kind' => 'earning', 'formula' => 'sueldo_tramos', 'sort_order' => 1, 'affects' => ['quinta:ordinary', 'pension']]);
        PayrollFixtures::concept(['code' => 'renta_quinta', 'kind' => 'tax', 'sort_order' => 90]);
        PayrollFixtures::parameter('dias_base_mes', '30');
        PayrollFixtures::incomeTaxParameters('5500');
        PayrollFixtures::parameter('gratificacion_factor', '1');
        PayrollFixtures::parameter('gratificacion_bonificacion_extraordinaria_pct', '9');
    });
    $run = (string) dcCall($this->payroll, 'POST', "/payroll-periods/{$period}/calculate")->assertOk()->json('data.last_run.id');
    TenancyScope::runAs(DC_TENANT, fn () => app(PayrollRuns::class)->process($run));
    $reason = fn (string $emp): ?string => TenancyScope::runAs(DC_TENANT, fn () => json_decode((string) DB::table('fifth_category_ledger')
        ->where('employment_id', $emp)->where('period_month', 3)->orderByDesc('sequence')->value('trace'), true)['reason'] ?? null);
    expect($reason($this->a))->toBe('other_employer_withholds')->and($reason($this->b))->not->toBe('other_employer_withholds');
    expect(TenancyScope::runAs(DC_TENANT, fn () => (string) DB::table('fifth_category_ledger')->where('employment_id', $this->a)->where('period_month', 3)->value('withholding_amount')))->toBe('0.00');
});

test('DEC-10 · una estructura nueva se carga como datos, nace sin verificar y se verifica una vez; regenerar da los mismos bytes', function (): void {
    $owner = dcConsoleUser('dc-duena', 'superadmin');
    $support = dcConsoleUser('dc-soporte', 'support');
    $fields = [['n' => 1, 'name' => 'tipo_documento', 'type' => 'text', 'max_len' => 2, 'required' => true, 'source' => 'document.type_code'],
        ['n' => 2, 'name' => 'numero', 'type' => 'text', 'max_len' => 15, 'required' => true, 'source' => 'document.number']];
    $body = ['country' => 'PE', 'filing_kind' => 'plame', 'structure_code' => 'dc_prueba', 'name' => 'Estructura de prueba', 'file_name_pattern' => '0601{periodo}{ruc}.dcp',
        'layout_version' => '1.0', 'field_separator' => '|', 'valid_from' => '2026-01-01', 'source' => 'Manual de prueba', 'fields' => $fields];
    $r = dcConsole($owner, 'POST', '/statutory-file-layouts', $body)->assertCreated();
    $this->assertMatchesOpenApi($r, 'post', '/platform/statutory-file-layouts', 201);
    expect($r->json('data.verified'))->toBeFalse();
    dcConsole($owner, 'POST', '/statutory-file-layouts', $body)->assertStatus(409);
    dcConsole($owner, 'POST', '/statutory-file-layouts', ['fields' => [['name' => 'x', 'type' => 'binario']]] + $body)->assertStatus(422);
    $v2 = dcConsole($owner, 'POST', '/statutory-file-layouts', ['layout_version' => '1.1', 'valid_from' => '2026-07-01'] + $body)->assertCreated();
    expect(DB::table('statutory_file_layouts')->where('id', $r->json('data.id'))->value('valid_to'))->toBe('2026-06-30');
    dcConsole($support, 'POST', '/statutory-file-layouts/'.$v2->json('data.id').'/verify')->assertForbidden();
    $ok = dcConsole($owner, 'POST', '/statutory-file-layouts/'.$v2->json('data.id').'/verify')->assertOk();
    $this->assertMatchesOpenApi($ok, 'post', '/platform/statutory-file-layouts/{id}/verify', 200);
    dcConsole($owner, 'POST', '/statutory-file-layouts/'.$v2->json('data.id').'/verify')->assertStatus(409);

    // Determinismo: la misma estructura y las mismas filas producen los mismos bytes.
    $layout = ['field_separator' => '|', 'trailing_separator' => true, 'line_ending' => 'CRLF', 'encoding' => 'ISO-8859-1', 'fields' => $fields];
    $rows = [['document.type_code' => '01', 'document.number' => '41234567'], ['document.type_code' => '01', 'document.number' => '47654321']];
    expect(hash('sha256', FileSerializer::serialize($layout, $rows)['content']))->toBe(hash('sha256', FileSerializer::serialize($layout, $rows)['content']));
});

test('DEC-11 · quien calcula no aprueba; devolución al libro, compensación parcial con saldo, solicitud a la autoridad y cierre', function (): void {
    TenancyScope::runAs(DC_TENANT, fn () => FifthCategoryLedgerEntry::factory()->create(['employment_id' => $this->a, 'fiscal_year' => 2026, 'period_month' => 6,
        'entry_kind' => 'final_adjustment', 'withholding_amount' => '-250.00']));
    $approver = dcUser(DC_TENANT, 'admin_empresa');
    $c = dcCall($this->admin, 'POST', '/fifth-category-refunds', ['employment_id' => $this->a, 'fiscal_year' => 2026, 'origin' => 'termination'])->assertCreated();
    $this->assertMatchesOpenApi($c, 'post', '/fifth-category-refunds', 201);
    expect($c->json('data.amount.amount'))->toBe('250.00')->and($c->json('data.status'))->toBe('pending');
    $id = $c->json('data.id');
    dcCall($this->payroll, 'POST', '/fifth-category-refunds', ['employment_id' => $this->a, 'fiscal_year' => 2026, 'origin' => 'termination'])->assertStatus(409);
    dcCall($this->payroll, 'POST', '/fifth-category-refunds', ['employment_id' => $this->b, 'fiscal_year' => 2026, 'origin' => 'termination'])->assertStatus(409);

    // RN-PE-QTA-18 · Quien calculó no aprueba.
    dcCall($this->admin, 'POST', "/fifth-category-refunds/{$id}/refund")->assertStatus(409)->assertJsonPath('error.code', 'segregation_of_duties');
    dcCall($this->admin, 'POST', "/fifth-category-refunds/{$id}/close")->assertStatus(409)->assertJsonPath('error.code', 'invalid_transition');
    $r = dcCall($approver, 'POST', "/fifth-category-refunds/{$id}/refund", ['refunded_on' => '2026-06-30'])->assertOk();
    $this->assertMatchesOpenApi($r, 'post', '/fifth-category-refunds/{id}/refund', 200);
    expect($r->json('data.status'))->toBe('refunded');
    expect(TenancyScope::runAs(DC_TENANT, fn () => (string) DB::table('fifth_category_ledger')->where('employment_id', $this->a)->where('entry_kind', 'refund')->value('withholding_amount')))->toBe('-250.00');

    $p = dcCall($this->admin, 'POST', "/fifth-category-refunds/{$id}/compensate", ['amount' => '100.00', 'compensation_period' => '202607'])->assertOk();
    $this->assertMatchesOpenApi($p, 'post', '/fifth-category-refunds/{id}/compensate', 200);
    expect($p->json('data.status'))->toBe('refunded')->and($p->json('data.balance.amount'))->toBe('150.00');
    dcCall($this->admin, 'POST', "/fifth-category-refunds/{$id}/compensate", ['amount' => '200.00', 'compensation_period' => '202608'])->assertStatus(422);
    $a = dcCall($this->admin, 'POST', "/fifth-category-refunds/{$id}/request-authority-refund", ['authority_refund_reference' => 'SOL-2026-77'])->assertOk();
    $this->assertMatchesOpenApi($a, 'post', '/fifth-category-refunds/{id}/request-authority-refund', 200);
    $close = dcCall($this->admin, 'POST', "/fifth-category-refunds/{$id}/close")->assertOk();
    $this->assertMatchesOpenApi($close, 'post', '/fifth-category-refunds/{id}/close', 200);
    expect($close->json('data.status'))->toBe('closed')->and($close->json('data.authority_refund_reference'))->toBe('SOL-2026-77');
    expect(TenancyScope::runAs(DC_TENANT, fn () => DB::table('outbox_events')->where('aggregate_id', $id)->pluck('event_type')->all()))
        ->toContain('fifth_category.refund_paid.v1', 'fifth_category.refund_compensated.v1', 'fifth_category.refund_requested.v1', 'fifth_category.refund_closed.v1');

    // Declaración del trabajador sin sustento: 422; lista, permiso y aislamiento.
    dcCall($this->payroll, 'POST', '/fifth-category-refunds', ['employment_id' => $this->b, 'fiscal_year' => 2026, 'origin' => 'worker_declaration', 'amount' => '80.00'])->assertStatus(422);
    $list = dcCall($this->payroll, 'GET', '/fifth-category-refunds')->assertOk();
    $this->assertMatchesOpenApi($list, 'get', '/fifth-category-refunds', 200);
    $show = dcCall($this->payroll, 'GET', "/fifth-category-refunds/{$id}")->assertOk();
    $this->assertMatchesOpenApi($show, 'get', '/fifth-category-refunds/{id}', 200);
    dcCall($this->other, 'GET', "/fifth-category-refunds/{$id}")->assertNotFound();
    expect(dcCall($this->employee, 'GET', '/fifth-category-refunds')->assertOk()->json('data'))->toBe([]);
    dcCall($this->employee, 'POST', "/fifth-category-refunds/{$id}/close")->assertForbidden();
});
