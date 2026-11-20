<?php

/**
 * DEC-1 · Conceptos usados sin código de la Tabla 22 vigente impiden generar e informan cuál; el mapeo tiene vigencia.
 * DEC-2 · PLAME: validación previa por trabajador, totales que cuadran con la planilla cerrada, archivo conservado con
 *         huella y autor, borrador mientras la estructura no esté verificada, presentación, observación y rectificatoria.
 * DEC-3 · T-Registro: pendientes con su plazo de los parámetros treg_*, datos faltantes antes de generar y conciliación.
 * DEC-4 · AFPnet: una línea de 141 posiciones por trabajador, sin código de afiliado reportado, vencimiento en días
 *         hábiles del mes siguiente y conciliación contra la liquidación.
 * DEC-5 · Plantillas de archivo bancario como datos, con validación de cuentas.
 * DEC-6 · Certificado de quinta desde el libro (incluidas devoluciones), con motivo y huella.
 */

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Payroll\Application\PayrollRuns;
use App\Modules\Payroll\Domain\Declarations\FileSerializer;
use App\Modules\Payroll\Infrastructure\Models\FifthCategoryLedgerEntry;
use App\Modules\Payroll\Infrastructure\Payments\TemplateBankFile;
use App\Shared\Permissions\ModuleGate;
use App\Shared\Permissions\PermissionResolver;
use App\Shared\Security\FieldCipher;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\IdentityFixtures;
use Tests\Support\PayrollFixtures;

uses(DatabaseTransactions::class);

const DEC_TENANT = 'aaaaaaaa-0dec-4000-8000-00000000000a';
const DEC_OTHER = 'bbbbbbbb-0dec-4000-8000-00000000000b';

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requiere PostgreSQL.');
    }
    TenancyScope::clear();
    Storage::fake('s3');
    foreach ([DEC_TENANT => 'dec-org', DEC_OTHER => 'dec-otra'] as $id => $slug) {
        DB::table('tenants')->insertOrIgnore(['id' => $id, 'slug' => $slug, 'name' => 'Grupo '.$slug, 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN']);
        DB::select('SELECT seed_tenant_defaults(?)', [$id]);
        TenancyScope::runAs($id, fn () => IdentityFixtures::enableAllModules());
    }
    $this->payroll = decUser(DEC_TENANT, 'payroll');
    $this->admin = decUser(DEC_TENANT, 'admin_empresa');
    $this->employee = decUser(DEC_TENANT, 'empleado');
    $this->other = decUser(DEC_OTHER, 'admin_empresa');
    // Códigos de las tablas paramétricas que valida el perfil del registro laboral (COL-6).
    foreach ([8 => '21', 33 => '01', 30 => '999999', 9 => '13', 12 => '01', 35 => '0', 16 => '2', 13 => '1', 15 => '1', 25 => '0'] as $table => $code) {
        if (! DB::table('pe_parametric_tables')->where('table_number', $table)->exists()) {
            DB::table('pe_parametric_tables')->insert(['table_number' => $table, 'name' => 'Tabla '.$table]);
        }
        if (! DB::table('pe_parametric_items')->where('table_number', $table)->where('code', $code)->exists()) {
            DB::table('pe_parametric_items')->insert(['table_number' => $table, 'code' => $code, 'description' => 'Prueba '.$table, 'valid_from' => '2000-01-01']);
        }
    }
    [$this->entity, $this->a, $this->b, $this->concept] = TenancyScope::runAs(DEC_TENANT, function (): array {
        $entity = PayrollFixtures::entity();
        $a = PayrollFixtures::employment($entity, '2025-01-01', [['amount' => '3000.00', 'valid_from' => '2025-01-01']]);
        $b = PayrollFixtures::employment($entity, '2026-03-10', [['amount' => '2000.00', 'valid_from' => '2026-03-10']]);
        $concept = PayrollFixtures::concept(['code' => 'BASICO', 'kind' => 'earning', 'formula' => 'sueldo_tramos', 'sort_order' => 1, 'affects' => ['pension']]);
        PayrollFixtures::parameter('dias_base_mes', '30');
        foreach ([$a => '41234567', $b => '47654321'] as $emp => $dni) {
            $person = (string) DB::table('employments')->where('id', $emp)->value('person_id');
            DB::table('person_documents')->insert(['tenant_id' => DEC_TENANT, 'person_id' => $person, 'doc_type' => 'dni', 'issuing_country' => 'PE',
                'number_enc' => FieldCipher::encrypt($dni), 'number_hash' => hash('sha256', 'dec'.$dni), 'is_primary' => true]);
            DB::table('peruvian_employee_profiles')->insert(['tenant_id' => DEC_TENANT, 'employment_id' => $emp, 'worker_type_code' => '21',
                'labor_regime_sunat_code' => '01', 'occupation_code' => '999999', 'education_status_code' => '13', 'contract_type_sunat_code' => '01',
                'payment_type_code' => '2', 'pay_periodicity_code' => '1', 'initial_basic_remuneration' => '2000.00', 'currency' => 'PEN',
                'completeness_status' => 'ready', 'valid_from' => '2025-01-01']);
        }
        // A con CUSPP; B en AFP sin código de afiliado (se reporta).
        DB::table('employment_pension_affiliations')->insert(['tenant_id' => DEC_TENANT, 'employment_id' => $a, 'pension_system' => 'afp',
            'pension_fund_code' => 'pe_afp_integra', 'affiliate_code_enc' => FieldCipher::encrypt('123456ABCDE7'), 'commission_scheme' => 'flow', 'valid_from' => '2025-01-01']);
        DB::table('employment_pension_affiliations')->insert(['tenant_id' => DEC_TENANT, 'employment_id' => $b, 'pension_system' => 'afp',
            'pension_fund_code' => 'pe_afp_prima', 'commission_scheme' => 'flow', 'valid_from' => '2026-03-10']);

        return [$entity, $a, $b, $concept];
    });
});

afterEach(fn () => TenancyScope::clear());

function decUser(string $tenant, string $role): User
{
    return TenancyScope::runAs($tenant, function () use ($role): User {
        $u = User::factory()->create();
        $u->roleAssignments()->create(['role_id' => Role::query()->where('system_role_code', $role)->value('id')]);

        return $u;
    });
}

/** @param array<string, mixed> $body */
function decCall(User $user, string $method, string $path, array $body = []): TestResponse
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

/** Periodo de marzo de 2026 calculado, aprobado y cerrado. */
function decClosedPeriod(object $t): string
{
    $group = (string) decCall($t->admin, 'POST', '/payroll-groups', ['code' => 'MENS', 'name' => 'Mensual', 'legal_entity_id' => $t->entity, 'payment_day' => 28, 'cutoff_day' => 25])
        ->assertCreated()->json('data.id');
    $period = (string) decCall($t->admin, 'POST', '/payroll-periods', ['payroll_group_id' => $group, 'period_year' => 2026, 'period_number' => 3])->assertCreated()->json('data.id');
    $run = (string) decCall($t->payroll, 'POST', "/payroll-periods/{$period}/calculate")->assertOk()->json('data.last_run.id');
    TenancyScope::runAs(DEC_TENANT, fn () => app(PayrollRuns::class)->process($run));
    decCall($t->payroll, 'POST', "/payroll-periods/{$period}/submit", ['confirm_anomalies' => true])->assertOk();
    decCall($t->admin, 'POST', "/payroll-periods/{$period}/approve")->assertOk();
    decCall($t->payroll, 'POST', "/payroll-periods/{$period}/close")->assertOk();

    return $period;
}

/** Plazos de T-Registro y AFPnet (parámetros legales con vigencia, PAR-1); se cargan después de calcular. */
function decDeadlines(): void
{
    TenancyScope::clear();
    DB::statement('RESET ROLE');
    foreach ([['treg_alta_trabajador_dias', '0', 'days'], ['treg_modificacion_dias_habiles', '5', 'business_days'], ['afpnet_plazo_dias_habiles', '5', 'business_days']] as [$code, $value, $unit]) {
        if (! DB::table('legal_parameters')->where('country_code', 'PE')->where('code', $code)->exists()) {
            DB::table('legal_parameters')->insert(['country_code' => 'PE', 'code' => $code, 'name' => $code, 'value_numeric' => $value, 'unit' => $unit, 'valid_from' => '2000-01-01', 'verified' => true]);
        }
    }
}

function decVerifyLayouts(string $kind): void
{
    TenancyScope::clear();
    DB::statement('RESET ROLE');
    DB::table('statutory_file_layouts')->where('filing_kind', $kind)->update(['verified' => true]);
}

test('DEC-1 · DEC-2 · concepto sin código impide generar, PLAME borrador hasta verificar la estructura, presentación y rectificatoria', function (): void {
    $period = decClosedPeriod($this);
    TenancyScope::runAs(DEC_TENANT, fn () => DB::table('concept_statutory_mappings')->where('payroll_concept_id', $this->concept)->delete());

    $check = decCall($this->payroll, 'GET', "/statutory-mapping-check?payroll_period_id={$period}")->assertOk();
    $this->assertMatchesOpenApi($check, 'get', '/statutory-mapping-check', 200);
    expect($check->json('data.complete'))->toBeFalse()->and($check->json('data.unmapped.0.code'))->toBe('BASICO');
    decCall($this->payroll, 'GET', '/statutory-mapping-check')->assertStatus(422);

    $created = decCall($this->payroll, 'POST', '/declaration-files', ['legal_entity_id' => $this->entity, 'kind' => 'plame', 'payroll_period_id' => $period])->assertCreated();
    $this->assertMatchesOpenApi($created, 'post', '/declaration-files', 201);
    $id = (string) $created->json('data.id');
    expect($created->json('data.period_code'))->toBe('202603')->and($created->json('data.status'))->toBe('draft');
    $this->assertMatchesOpenApi(decCall($this->payroll, 'POST', '/declaration-files', ['legal_entity_id' => $this->entity, 'kind' => 'plame', 'payroll_period_id' => $period])
        ->assertStatus(409), 'post', '/declaration-files', 409);
    $this->assertMatchesOpenApi(decCall($this->payroll, 'POST', '/declaration-files', ['legal_entity_id' => $this->entity, 'kind' => 'otro'])->assertStatus(422), 'post', '/declaration-files', 422);

    // Transición inválida: no se acepta un borrador.
    $this->assertMatchesOpenApi(decCall($this->payroll, 'POST', "/declaration-files/{$id}/accept")->assertStatus(409), 'post', '/declaration-files/{id}/accept', 409);

    $withErrors = decCall($this->payroll, 'POST', "/declaration-files/{$id}/validate")->assertOk();
    $this->assertMatchesOpenApi($withErrors, 'post', '/declaration-files/{id}/validate', 200);
    expect($withErrors->json('data.status'))->toBe('with_errors')
        ->and(collect($withErrors->json('data.validation_report.errors'))->pluck('rule')->all())->toContain('unmapped');

    // DEC-1 · El mapeo tiene vigencia: se agrega desde la fecha del periodo.
    TenancyScope::runAs(DEC_TENANT, fn () => DB::table('concept_statutory_mappings')->insert(['tenant_id' => DEC_TENANT, 'payroll_concept_id' => $this->concept,
        'filing_kind' => 'plame', 'statutory_code' => '0121', 'valid_from' => '2026-01-01']));
    $history = decCall($this->payroll, 'GET', "/payroll-concepts/{$this->concept}/statutory-mappings")->assertOk();
    $this->assertMatchesOpenApi($history, 'get', '/payroll-concepts/{id}/statutory-mappings', 200);
    expect($history->json('data.0.valid_from'))->toBe('2026-01-01');

    $valid = decCall($this->payroll, 'POST', "/declaration-files/{$id}/validate")->assertOk();
    expect($valid->json('data.status'))->toBe('validated')->and($valid->json('data.draft_only'))->toBeTrue()
        ->and($valid->json('data.validation_report.totals.matches_payroll'))->toBeTrue()
        ->and($valid->json('data.validation_report.totals.file.earnings'))->toBe($valid->json('data.validation_report.totals.payroll.earnings'));
    // RN-DEC-11 · Estructura sin verificar: solo borrador, no se marca presentado.
    $this->assertMatchesOpenApi(decCall($this->payroll, 'POST', "/declaration-files/{$id}/mark-submitted", ['external_reference' => '123'])->assertStatus(409),
        'post', '/declaration-files/{id}/mark-submitted', 409);

    decVerifyLayouts('plame');
    $again = decCall($this->payroll, 'POST', "/declaration-files/{$id}/validate")->assertOk();
    expect($again->json('data.draft_only'))->toBeFalse();
    $files = decCall($this->payroll, 'GET', "/declaration-files/{$id}/files")->assertOk();
    $this->assertMatchesOpenApi($files, 'get', '/declaration-files/{id}/files', 200);
    $rem = collect($files->json('data'))->firstWhere(fn ($f) => $f['file_kind'] === 'rem' && $f['superseded'] === false);
    expect($rem['generated_by_user_id'])->toBe((string) $this->payroll->id)->and($rem['sha256'])->toHaveLength(64)
        ->and(collect($files->json('data'))->where('file_kind', 'rem')->count())->toBe(2);
    $download = decCall($this->payroll, 'GET', "/declaration-files/{$id}/files/{$rem['id']}/file")->assertOk();
    expect($download->getContent())->toContain('01|41234567|0121|3000.00|3000.00|')->and(hash('sha256', (string) $download->getContent()))->toBe($rem['sha256']);
    expect(TenancyScope::runAs(DEC_TENANT, fn () => DB::table('audit_events')->where('entity_id', $id)->where('action', 'statutory_filing_file.downloaded')->exists()))->toBeTrue();

    $submitted = decCall($this->payroll, 'POST', "/declaration-files/{$id}/mark-submitted", ['external_reference' => 'ORD-778899'])->assertOk();
    $this->assertMatchesOpenApi($submitted, 'post', '/declaration-files/{id}/mark-submitted', 200);
    $observed = decCall($this->payroll, 'POST', "/declaration-files/{$id}/observe", ['observation' => 'Falta un trabajador'])->assertOk();
    $this->assertMatchesOpenApi($observed, 'post', '/declaration-files/{id}/observe', 200);
    $rect = decCall($this->payroll, 'POST', "/declaration-files/{$id}/rectify", ['reason' => 'Se corrige la remuneración declarada'])->assertOk();
    $this->assertMatchesOpenApi($rect, 'post', '/declaration-files/{id}/rectify', 200);
    expect($rect->json('data.is_rectification'))->toBeTrue()->and($rect->json('data.rectifies_filing_id'))->toBe($id)->and($rect->json('data.status'))->toBe('draft');
    $show = decCall($this->payroll, 'GET', "/declaration-files/{$id}")->assertOk();
    $this->assertMatchesOpenApi($show, 'get', '/declaration-files/{id}', 200);
    expect($show->json('data.status'))->toBe('rectified');
    expect(TenancyScope::runAs(DEC_TENANT, fn () => DB::table('outbox_events')->where('event_type', 'filing.status_changed.v1')->count()))->toBeGreaterThanOrEqual(5);

    $list = decCall($this->payroll, 'GET', '/declaration-files?filter[kind]=plame')->assertOk();
    $this->assertMatchesOpenApi($list, 'get', '/declaration-files', 200);
    expect($list->json('data'))->toHaveCount(2);

    // Aislamiento y permisos.
    $this->assertMatchesOpenApi(decCall($this->other, 'GET', "/declaration-files/{$id}")->assertNotFound(), 'get', '/declaration-files/{id}', 404);
    expect(decCall($this->other, 'GET', '/declaration-files')->assertOk()->json('data'))->toBe([]);
    $this->assertMatchesOpenApi(decCall($this->employee, 'GET', '/declaration-files')->assertForbidden(), 'get', '/declaration-files', 403);
    decCall($this->employee, 'POST', "/declaration-files/{$id}/validate")->assertForbidden();
});

test('DEC-4 · AFPnet: líneas de ancho fijo, sin código de afiliado reportado, vencimiento y conciliación', function (): void {
    $period = decClosedPeriod($this);
    TenancyScope::runAs(DEC_TENANT, fn () => DB::table('concept_statutory_mappings')->where('payroll_concept_id', $this->concept)->delete());
    decVerifyLayouts('afpnet');
    decDeadlines();
    $preview = decCall($this->payroll, 'POST', '/declaration-files/preview', ['legal_entity_id' => $this->entity, 'kind' => 'afpnet', 'payroll_period_id' => $period])->assertOk();
    $this->assertMatchesOpenApi($preview, 'post', '/declaration-files/preview', 200);
    expect($preview->json('data.valid'))->toBeTrue()->and($preview->json('data.files.0.record_count'))->toBe(2)
        ->and(collect($preview->json('data.warnings'))->where('code', 'missing_affiliate_code')->pluck('ref')->all())->toBe([$this->b]);

    $id = (string) decCall($this->payroll, 'POST', '/declaration-files', ['legal_entity_id' => $this->entity, 'kind' => 'afpnet', 'payroll_period_id' => $period])
        ->assertCreated()->json('data.id');
    $filing = decCall($this->payroll, 'POST', "/declaration-files/{$id}/validate")->assertOk();
    expect($filing->json('data.status'))->toBe('validated')->and($filing->json('data.due_on'))->toStartWith('2026-04-');
    $fileId = (string) $filing->json('data.files.0.id');
    $content = (string) decCall($this->payroll, 'GET', "/declaration-files/{$id}/files/{$fileId}/file")->assertOk()->getContent();
    $lines = array_values(array_filter(explode("\r\n", $content)));
    expect($lines)->toHaveCount(2)->and(strlen($lines[0]))->toBe(141)->and(strlen($lines[1]))->toBe(141)
        ->and(substr($lines[0], 0, 17))->toBe('00001123456ABCDE7')
        // A: relación S, sin inicio ni cese en el mes; B ingresó en marzo: inicio S.
        ->and(substr($lines[0], 98, 3))->toBe('SNN')->and(substr($lines[1], 98, 3))->toBe('SSN')
        ->and(substr($lines[0], 102, 9))->toBe('000300000')->and(substr($lines[0], -3))->toBe('NIN');

    decCall($this->payroll, 'POST', "/declaration-files/{$id}/mark-submitted", ['external_reference' => 'AFPNET-1'])->assertOk();
    $accepted = decCall($this->payroll, 'POST', "/declaration-files/{$id}/accept", ['liquidation' => ['contribution' => '0', 'commission' => '0', 'premium' => '0']])->assertOk();
    $this->assertMatchesOpenApi($accepted, 'post', '/declaration-files/{id}/accept', 200);
    expect($accepted->json('data.status'))->toBe('accepted')->and($accepted->json('data.validation_report.reconciliation.matches'))->toBeTrue();
});

test('DEC-3 · T-Registro: pendientes con plazo, datos faltantes antes de generar y conciliación con la historia', function (): void {
    decDeadlines();
    $sync = decCall($this->payroll, 'POST', '/registry-events/sync', ['legal_entity_id' => $this->entity])->assertOk();
    $this->assertMatchesOpenApi($sync, 'post', '/registry-events/sync', 200);
    expect($sync->json('data.created'))->toBe(2);
    expect(decCall($this->payroll, 'POST', '/registry-events/sync', ['legal_entity_id' => $this->entity])->json('data.created'))->toBe(0);
    $events = decCall($this->payroll, 'GET', "/registry-events?legal_entity_id={$this->entity}")->assertOk();
    $this->assertMatchesOpenApi($events, 'get', '/registry-events', 200);
    $a = collect($events->json('data'))->firstWhere('employment_id', $this->a);
    expect($a['kind'])->toBe('worker_registration')->and($a['due_on'])->toBe('2025-01-01')->and($a['status'])->toBe('overdue')
        ->and($events->json('meta.deadlines'))->toHaveKeys(['treg_alta_trabajador_dias', 'treg_modificacion_dias_habiles']);

    $id = (string) decCall($this->payroll, 'POST', '/declaration-files', ['legal_entity_id' => $this->entity, 'kind' => 't_registro'])->assertCreated()->json('data.id');
    $v = decCall($this->payroll, 'POST', "/declaration-files/{$id}/validate")->assertOk();
    // La ficha de prueba no tiene fecha de nacimiento, sexo ni nacionalidad: se listan por trabajador antes de presentar.
    $missing = collect($v->json('data.validation_report.errors'))->where('ref', $this->a)->pluck('field')->all();
    expect($v->json('data.status'))->toBe('with_errors')->and($missing)->toContain('fecha_nacimiento', 'sexo');

    $rec = decCall($this->payroll, 'GET', "/registry-reconciliation?legal_entity_id={$this->entity}")->assertOk();
    $this->assertMatchesOpenApi($rec, 'get', '/registry-reconciliation', 200);
    expect(collect($rec->json('data'))->firstWhere('employment_id', $this->a)['issues'])->toBe(['not_declared']);
    decCall($this->other, 'GET', "/registry-reconciliation?legal_entity_id={$this->entity}")->assertNotFound();
    decCall($this->other, 'POST', '/registry-events/sync', ['legal_entity_id' => $this->entity])->assertNotFound();
});

test('DEC-5 · plantilla bancaria como datos y validación de cuentas', function (): void {
    $list = decCall($this->payroll, 'GET', '/bank-file-templates')->assertOk();
    $this->assertMatchesOpenApi($list, 'get', '/bank-file-templates', 200);
    expect(collect($list->json('data'))->pluck('code')->all())->toContain('pe_generico_delimitado', 'pe_generico_ancho_fijo');

    $this->assertMatchesOpenApi(decCall($this->admin, 'POST', '/bank-file-templates', ['code' => 'mal', 'bank_code' => 'X', 'name' => 'Mal', 'definition' => ['separator' => ';', 'detail' => [['source' => 'nada']]]])
        ->assertStatus(422), 'post', '/bank-file-templates', 422);
    $definition = ['separator' => null, 'detail' => [['source' => 'item.account_number', 'width' => 14], ['source' => 'item.amount', 'decimals' => 2, 'implied_decimals' => true, 'width' => 10, 'pad' => 'left', 'pad_char' => '0']],
        'trailer' => [['value' => 'T'], ['source' => 'header.count', 'width' => 3, 'pad' => 'left', 'pad_char' => '0']], 'account_rules' => ['account_number_pattern' => '/^\d{14}$/']];
    $created = decCall($this->admin, 'POST', '/bank-file-templates', ['code' => 'banco_nuevo', 'bank_code' => 'NUEVO', 'name' => 'Banco nuevo', 'definition' => $definition])->assertCreated();
    $this->assertMatchesOpenApi($created, 'post', '/bank-file-templates', 201);
    $updated = decCall($this->admin, 'PATCH', '/bank-file-templates/'.$created->json('data.id'), ['status' => 'inactive', 'lock_version' => $created->json('data.lock_version')])->assertOk();
    $this->assertMatchesOpenApi($updated, 'patch', '/bank-file-templates/{id}', 200);
    decCall($this->other, 'PATCH', '/bank-file-templates/'.$created->json('data.id'), ['status' => 'active'])->assertNotFound();
    decCall($this->employee, 'POST', '/bank-file-templates', ['code' => 'x', 'bank_code' => 'X', 'name' => 'X', 'definition' => $definition])->assertForbidden();

    $writer = new TemplateBankFile('banco_nuevo', $definition);
    $item = ['employee_code' => 'P1', 'document_number' => null, 'name' => 'Ana', 'account_type' => 'savings', 'account_number' => '19300123456789', 'interbank_code' => null, 'amount' => '1500.50', 'currency' => 'PEN', 'reference' => 'r1'];
    expect($writer->render(['batch_id' => 'b', 'legal_entity_tax_id' => '20123456789', 'legal_entity_name' => 'X', 'bank_code' => 'NUEVO', 'payment_date' => '2026-03-28', 'currency' => 'PEN', 'total' => '1500.50'], [$item]))
        ->toBe("193001234567890000150050\r\nT001\r\n")
        ->and($writer->invalidAccounts([['account_number' => '123'] + $item]))->toHaveCount(1);
});

test('DEC-6 · certificado de quinta desde el libro con devoluciones, motivo y huella', function (): void {
    TenancyScope::runAs(DEC_TENANT, function (): void {
        FifthCategoryLedgerEntry::factory()->create(['employment_id' => $this->a, 'fiscal_year' => 2026, 'period_month' => 1, 'withholding_amount' => '120.00', 'gross_annual_income' => '42000.00', 'annual_tax' => '1400.00', 'deduction_amount' => '37450.00']);
        FifthCategoryLedgerEntry::factory()->create(['employment_id' => $this->a, 'fiscal_year' => 2026, 'period_month' => 2, 'withholding_amount' => '130.00', 'gross_annual_income' => '43000.00', 'annual_tax' => '1500.00', 'deduction_amount' => '37450.00']);
        DB::table('fifth_category_refunds')->insert(['tenant_id' => DEC_TENANT, 'employment_id' => $this->a, 'fiscal_year' => 2026, 'origin' => 'termination', 'amount' => '50.00', 'currency' => 'PEN', 'status' => 'refunded']);
    });
    $issued = decCall($this->payroll, 'POST', '/withholding-certificates', ['employment_id' => $this->a, 'fiscal_year' => 2026, 'reason' => 'termination'])->assertCreated();
    $this->assertMatchesOpenApi($issued, 'post', '/withholding-certificates', 201);
    expect($issued->json('data.total_withheld.amount'))->toBe('250.00')->and($issued->json('data.refunded_amount.amount'))->toBe('50.00')
        ->and($issued->json('data.total_income.amount'))->toBe('43000.00')->and($issued->json('data.reason'))->toBe('termination')
        ->and($issued->json('data.content_hash'))->toHaveLength(64);
    // Puede haber más de uno por ejercicio (a solicitud).
    decCall($this->payroll, 'POST', '/withholding-certificates', ['employment_id' => $this->a, 'fiscal_year' => 2026, 'reason' => 'on_request'])->assertCreated();
    $this->assertMatchesOpenApi(decCall($this->payroll, 'POST', '/withholding-certificates', ['employment_id' => $this->b, 'fiscal_year' => 2026, 'reason' => 'annual'])->assertStatus(409),
        'post', '/withholding-certificates', 409);
    $list = decCall($this->payroll, 'GET', "/withholding-certificates?filter[employment_id]={$this->a}")->assertOk();
    $this->assertMatchesOpenApi($list, 'get', '/withholding-certificates', 200);
    expect($list->json('data'))->toHaveCount(2);
    $pdf = decCall($this->payroll, 'GET', '/withholding-certificates/'.$issued->json('data.id').'/certificate')->assertOk();
    expect(substr((string) $pdf->getContent(), 0, 5))->toBe('%PDF-');
    expect(TenancyScope::runAs(DEC_TENANT, fn () => DB::table('documents')->where('id', $issued->json('data.document_id'))->value('visible_to_employee')))->toBeTrue();
    decCall($this->other, 'GET', '/withholding-certificates/'.$issued->json('data.id').'/certificate')->assertNotFound();
    decCall($this->other, 'POST', '/withholding-certificates', ['employment_id' => $this->a, 'fiscal_year' => 2026, 'reason' => 'annual'])->assertNotFound();
});

test('DEC-10 · el serializador escribe delimitado y ancho fijo de forma determinista y reporta lo que no cumple', function (): void {
    $layout = ['field_separator' => '|', 'trailing_separator' => true, 'line_ending' => 'CRLF', 'encoding' => 'ISO-8859-1', 'fields' => [
        ['name' => 'doc', 'type' => 'text', 'max_len' => 2, 'source' => 'd'], ['name' => 'monto', 'type' => 'number', 'decimals' => 2, 'source' => 'm'],
        ['name' => 'fecha', 'type' => 'date', 'required' => false, 'source' => 'f'],
    ]];
    $out = FileSerializer::serialize($layout, [['d' => '01', 'm' => '10.5', 'f' => '2026-03-01'], ['_ref' => 'x', 'd' => '012', 'm' => null]]);
    expect($out['content'])->toBe("01|10.50|01/03/2026|\r\n01|||\r\n")
        ->and($out['errors'])->toBe([['row' => 2, 'ref' => 'x', 'field' => 'doc', 'rule' => 'max_len'], ['row' => 2, 'ref' => 'x', 'field' => 'monto', 'rule' => 'required']]);
    $fixed = FileSerializer::serialize(['field_separator' => null, 'trailing_separator' => false, 'line_ending' => 'LF', 'encoding' => 'UTF-8', 'fields' => [
        ['name' => 'n', 'type' => 'fixed_number', 'width' => 9, 'decimals' => 2, 'source' => 'a'], ['name' => 't', 'type' => 'text', 'width' => 5, 'truncate' => true, 'source' => 'b'],
    ]], [['a' => '600.20', 'b' => 'Muñozz']]);
    expect($fixed['content'])->toBe("000060020Muñoz\n")->and($fixed['errors'])->toBe([]);
    expect(FileSerializer::fileName('0601{yyyy}{mm}{ruc}.{ext}', '20123456789', '202603', 'rem'))->toBe('060120260320123456789.rem');
});
