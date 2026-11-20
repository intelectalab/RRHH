<?php

/**
 * VAC-5 · Rol anual (campaña → propuesta → confirmación → solicitud aprobada) y alertas de acumulación.
 * VAC-6 · Acuerdos de venta y fraccionamiento: límites de la política y legales, PDF al legajo, firma, «sold» y
 * concepto de planilla.
 * VAC-7 · Importación de saldos iniciales por periodo como movimiento de apertura.
 *
 * Hoy (congelado): viernes 13 de noviembre de 2026. Régimen general: 30 días (parámetro legal aprobado).
 */

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Imports\Application\ImportRunner;
use App\Modules\Organization\Infrastructure\Models\LegalEntity;
use App\Modules\Vacations\Application\VacationScheduling;
use App\Shared\LegalParameters\LegalParameters;
use App\Shared\Permissions\ModuleGate;
use App\Shared\Permissions\PermissionResolver;
use App\Shared\Security\BlindIndex;
use App\Shared\Tenancy\TenancyScope;
use App\Shared\Time\VacationBalances;
use App\Shared\Time\VacationPayrollIncidents;
use App\Shared\Vigencia\EffectiveDated;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\IdentityFixtures;

uses(DatabaseTransactions::class);

const VPL_TENANT = 'aaaaaaaa-0ac5-4000-8000-00000000000a';

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requiere PostgreSQL.');
    }
    Carbon::setTestNow('2026-11-13 12:00:00');
    Storage::fake('s3');
    TenancyScope::clear();
    DB::table('tenants')->insertOrIgnore(['id' => VPL_TENANT, 'slug' => 'vpl-org', 'name' => 'Grupo VPL', 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN']);
    DB::select('SELECT seed_tenant_defaults(?)', [VPL_TENANT]);
    TenancyScope::runAs(VPL_TENANT, fn () => IdentityFixtures::enableAllModules());
    app(LegalParameters::class)->sync('PE');

    $this->hr = vplUser('rrhh');
    $org = TenancyScope::runAs(VPL_TENANT, function (): array {
        $entity = (string) LegalEntity::factory()->create(['timezone' => 'America/Lima', 'tax_id' => '20555555551'])->id;
        $site = (string) Str::uuid();
        DB::table('sites')->insert(['id' => $site, 'tenant_id' => VPL_TENANT, 'legal_entity_id' => $entity, 'code' => 'LIM', 'name' => 'Lima', 'timezone' => 'America/Lima']);
        $unit = (string) Str::uuid();
        DB::table('org_units')->insert(['id' => $unit, 'tenant_id' => VPL_TENANT, 'code' => 'OPE', 'name' => 'Operaciones']);
        $profile = (string) Str::uuid();
        DB::table('job_profiles')->insert(['id' => $profile, 'tenant_id' => VPL_TENANT, 'code' => 'ANA', 'name' => 'Analista']);
        $mk = function (string $name, string $hire, string $doc, ?string $manager = null) use ($entity, $site, $unit, $profile): array {
            $person = (string) Str::uuid();
            $employment = (string) Str::uuid();
            DB::table('people')->insert(['id' => $person, 'tenant_id' => VPL_TENANT, 'first_names' => $name, 'paternal_surname' => 'Prueba']);
            DB::table('employments')->insert(['id' => $employment, 'tenant_id' => VPL_TENANT, 'person_id' => $person, 'legal_entity_id' => $entity, 'employee_code' => strtoupper($name), 'hire_date' => $hire, 'status' => 'active']);
            DB::table('employment_labor_regimes')->insert(['tenant_id' => VPL_TENANT, 'employment_id' => $employment, 'labor_regime_code' => 'pe_general', 'valid_from' => $hire]);
            DB::table('employment_assignments')->insert(['tenant_id' => VPL_TENANT, 'employment_id' => $employment, 'job_profile_id' => $profile, 'org_unit_id' => $unit,
                'site_id' => $site, 'manager_employment_id' => $manager, 'valid_from' => $hire]);
            DB::table('person_documents')->insert(['tenant_id' => VPL_TENANT, 'person_id' => $person, 'doc_type' => 'dni', 'issuing_country' => 'PE',
                'number_enc' => 'x', 'number_hash' => BlindIndex::for('national_id', 'dni:'.$doc), 'is_primary' => true]);

            return [$person, $employment];
        };
        [$bossPerson, $boss] = $mk('Marta', '2022-01-01', '40000001');
        [$person, $employment] = $mk('Juan', '2025-01-01', '40000002', $boss);
        [, $veteran] = $mk('Rosa', '2023-01-01', '40000003', $boss);
        // Política del régimen general: permite vender hasta 10 días y exige bloques de 7 días.
        $policy = (string) Str::uuid();
        DB::table('vacation_policies')->insert(['id' => $policy, 'tenant_id' => VPL_TENANT, 'code' => 'GEN', 'name' => 'General', 'labor_regime_code' => 'pe_general']);
        DB::table('vacation_policy_rules')->insert(['tenant_id' => VPL_TENANT, 'vacation_policy_id' => $policy, 'allow_sale' => true, 'max_sale_days' => 10,
            'min_days_per_request' => 7, 'valid_from' => '2020-01-01']);

        return compact('entity', 'bossPerson', 'boss', 'person', 'employment', 'veteran');
    });
    $this->org = $org;
    $this->employee = vplUser('empleado', $org['person']);
    $this->emp = $org['employment'];
});

afterEach(function (): void {
    TenancyScope::clear();
    Carbon::setTestNow();
});

function vplUser(string $role, ?string $personId = null): User
{
    return TenancyScope::runAs(VPL_TENANT, function () use ($role, $personId): User {
        $u = User::factory()->create(['person_id' => $personId]);
        $u->roleAssignments()->create(['role_id' => Role::query()->where('system_role_code', $role)->value('id')]);

        return $u;
    });
}

/**
 * @param  array<string, mixed>  $body
 */
function vplCall(User $user, string $method, string $path, array $body = []): TestResponse
{
    TenancyScope::clear();
    app('auth')->forgetGuards();
    app(PermissionResolver::class)->forget();
    app(ModuleGate::class)->forget();

    return test()->actingAs($user, 'ayni')->withHeaders(['X-Tenant-Id' => VPL_TENANT, 'Idempotency-Key' => (string) Str::uuid()])->json($method, '/api/v1'.$path, $body);
}

function vplBalance(string $employment): array
{
    return TenancyScope::runAs(VPL_TENANT, fn (): array => app(VacationBalances::class)->balance($employment));
}

test('VAC-5 · RRHH abre el rol anual, propone fechas, el trabajador confirma y se convierten en solicitud aprobada', function (): void {
    expect(vplBalance($this->emp)['totals']['available'])->toBe('30.00');

    $open = vplCall($this->hr, 'POST', '/vacation-schedules', ['legal_entity_id' => $this->org['entity'], 'year' => 2027]);
    $this->assertMatchesOpenApi($open, 'post', '/vacation-schedules', 201);
    $id = (string) $open->json('data.id');
    $this->assertMatchesOpenApi(vplCall($this->hr, 'POST', '/vacation-schedules', ['legal_entity_id' => $this->org['entity'], 'year' => 2027]), 'post', '/vacation-schedules', 409);
    // El trabajador no programa.
    vplCall($this->employee, 'POST', '/vacation-schedules', ['legal_entity_id' => $this->org['entity'], 'year' => 2028])->assertForbidden();

    $line = vplCall($this->hr, 'POST', "/vacation-schedules/{$id}/lines", ['employment_id' => $this->emp, 'starts_on' => '2027-02-01', 'ends_on' => '2027-02-15']);
    $this->assertMatchesOpenApi($line, 'post', '/vacation-schedules/{id}/lines', 201);
    expect($line->json('data.days'))->toBe('15.00');
    $lineId = (string) $line->json('data.id');

    // El trabajador ve su línea y la confirma; convertir en una línea ya convertida es transición inválida.
    $mine = vplCall($this->employee, 'GET', "/vacation-schedules/{$id}/lines");
    $this->assertMatchesOpenApi($mine, 'get', '/vacation-schedules/{id}/lines', 200);
    expect($mine->json('data'))->toHaveCount(1);
    $confirmed = vplCall($this->employee, 'POST', "/vacation-schedule-lines/{$lineId}/confirm");
    $this->assertMatchesOpenApi($confirmed, 'post', '/vacation-schedule-lines/{id}/confirm', 200);
    $this->assertMatchesOpenApi(vplCall($this->employee, 'POST', "/vacation-schedule-lines/{$lineId}/decline", ['note' => 'Ya no']), 'post', '/vacation-schedule-lines/{id}/decline', 409);

    $converted = vplCall($this->hr, 'POST', "/vacation-schedules/{$id}/convert");
    $this->assertMatchesOpenApi($converted, 'post', '/vacation-schedules/{id}/convert', 200);
    expect($converted->json('data.converted'))->toBe(1);
    $requestId = TenancyScope::runAs(VPL_TENANT, fn () => DB::table('vacation_schedule_lines')->where('id', $lineId)->value('vacation_request_id'));
    expect(TenancyScope::runAs(VPL_TENANT, fn () => DB::table('vacation_requests')->where('id', $requestId)->value('status')))->toBe('approved');

    // Cerrada, no recibe propuestas.
    $this->assertMatchesOpenApi(vplCall($this->hr, 'POST', "/vacation-schedules/{$id}/close"), 'post', '/vacation-schedules/{id}/close', 200);
    $this->assertMatchesOpenApi(vplCall($this->hr, 'POST', "/vacation-schedules/{$id}/lines", ['employment_id' => $this->emp, 'starts_on' => '2027-07-01', 'ends_on' => '2027-07-10']), 'post', '/vacation-schedules/{id}/lines', 409);
    $this->assertMatchesOpenApi(vplCall($this->hr, 'GET', '/vacation-schedules'), 'get', '/vacation-schedules', 200);
});

test('VAC-5 · la alerta explica los periodos acumulados y avisa una sola vez por periodo', function (): void {
    // Los periodos se ponen al día con la tarea diaria (vacations:accrue).
    vplBalance($this->org['veteran']);
    vplBalance($this->emp);
    $alerts = vplCall($this->hr, 'GET', '/vacation-accumulation-alerts');
    $this->assertMatchesOpenApi($alerts, 'get', '/vacation-accumulation-alerts', 200);
    $rosa = collect($alerts->json('data'))->firstWhere('employment_id', $this->org['veteran']);
    // Los periodos anteriores a la vigencia del parámetro legal siguen sin días: solo el de 2025 tiene saldo, y vence
    // el 31/12/2026 (dentro de los 60 días de aviso) con 30 días (umbral de acumulación de la organización).
    expect($rosa)->not->toBeNull()
        ->and($rosa['periods_with_balance'])->toBe(1)
        ->and($rosa['expiring_periods'])->toBe(1)
        ->and($rosa['available_days'])->toBe('30.00')
        ->and($rosa['explanation'])->not->toBe('');
    // El empleado solo ve lo propio.
    $own = vplCall($this->employee, 'GET', '/vacation-accumulation-alerts')->json('data');
    expect(collect($own)->pluck('employment_id')->all())->toBe([$this->emp]);

    $sent = TenancyScope::runAs(VPL_TENANT, fn (): int => app(VacationScheduling::class)->notifyAlerts('2026-11-13'));
    expect($sent)->toBeGreaterThan(0);
    expect(TenancyScope::runAs(VPL_TENANT, fn (): int => app(VacationScheduling::class)->notifyAlerts('2026-11-14')))->toBe(0);
});

test('VAC-6 · la venta exige el tope legal validado, se firma, descuenta el saldo y da el concepto de planilla', function (): void {
    $blocked = vplCall($this->hr, 'POST', '/vacation-agreements', ['employment_id' => $this->emp, 'kind' => 'sale', 'days' => '5', 'effective_on' => '2026-11-20']);
    $this->assertMatchesOpenApi($blocked, 'post', '/vacation-agreements', 422);
    expect($blocked->json('error.code'))->toBe('legal_parameter_pending');

    // El responsable valida el tope legal (D-15): 15 días; la política lo baja a 10.
    TenancyScope::clear();
    DB::statement('RESET ROLE');
    $current = DB::table('legal_parameters')->where('code', 'vacaciones_venta_max_dias')->whereNull('labor_regime_code')->first();
    app(EffectiveDated::class)->change('legal_parameters', ['country_code' => 'PE', 'labor_regime_code' => null, 'code' => 'vacaciones_venta_max_dias'], [
        'name' => $current->name, 'unit' => 'days', 'value_numeric' => '15', 'verified' => true, 'legal_source' => 'Prueba: validado por el responsable',
    ], '2026-11-01');
    vplCall($this->employee, 'POST', '/vacation-agreements', ['employment_id' => $this->emp, 'kind' => 'sale', 'days' => '5', 'effective_on' => '2026-11-20'])->assertForbidden();
    $created = vplCall($this->hr, 'POST', '/vacation-agreements', ['employment_id' => $this->emp, 'kind' => 'sale', 'days' => '6', 'effective_on' => '2026-11-13']);
    $this->assertMatchesOpenApi($created, 'post', '/vacation-agreements', 201);
    $id = (string) $created->json('data.id');
    $over = vplCall($this->hr, 'POST', '/vacation-agreements', ['employment_id' => $this->emp, 'kind' => 'sale', 'days' => '5', 'effective_on' => '2026-11-25']);
    // Borrador no cuenta todavía; emitido sí.
    $over->assertCreated();

    $issued = vplCall($this->hr, 'POST', "/vacation-agreements/{$id}/issue");
    $this->assertMatchesOpenApi($issued, 'post', '/vacation-agreements/{id}/issue', 200);
    expect($issued->json('data.status'))->toBe('issued')->and($issued->json('data.document_id'))->not->toBeNull();
    $this->assertMatchesOpenApi(vplCall($this->hr, 'POST', '/vacation-agreements/'.$over->json('data.id').'/issue'), 'post', '/vacation-agreements/{id}/issue', 422);

    TenancyScope::clear();
    app('auth')->forgetGuards();
    $signed = $this->actingAs($this->hr, 'ayni')->withHeaders(['X-Tenant-Id' => VPL_TENANT, 'Idempotency-Key' => (string) Str::uuid()])
        ->post('/api/v1/vacation-agreements/'.$id.'/register-signature', ['file' => UploadedFile::fake()->create('firmado.pdf', 10, 'application/pdf'), 'signed_on' => '2026-11-14'], ['Accept' => 'application/json']);
    $this->assertMatchesOpenApi($signed, 'post', '/vacation-agreements/{id}/register-signature', 200);
    expect($signed->json('data.status'))->toBe('active')->and($signed->json('data.payroll_concept_code'))->toBe('vacaciones_compra');
    expect(vplBalance($this->emp)['totals']['sold'])->toBe('6.00')
        ->and(vplBalance($this->emp)['totals']['available'])->toBe('24.00');
    $sales = TenancyScope::runAs(VPL_TENANT, fn (): array => app(VacationPayrollIncidents::class)->sales($this->emp, '2026-11-01', '2026-11-30'));
    expect($sales)->toHaveCount(1)->and($sales[0]['days'])->toBe('6.00');

    $this->assertMatchesOpenApi(vplCall($this->hr, 'POST', "/vacation-agreements/{$id}/cancel", ['reason' => 'Error']), 'post', '/vacation-agreements/{id}/cancel', 409);
    $this->assertMatchesOpenApi(vplCall($this->employee, 'GET', '/vacation-agreements'), 'get', '/vacation-agreements', 200);
    expect(vplCall($this->employee, 'GET', '/vacation-agreements')->json('data'))->toHaveCount(2);
    vplCall($this->hr, 'GET', "/vacation-agreements/{$id}/document")->assertOk();
});

test('VAC-6 · con acuerdo de fraccionamiento firmado se admite un bloque menor que el de la política', function (): void {
    $short = vplCall($this->employee, 'POST', '/vacation-requests/simulate', ['employment_id' => $this->emp, 'starts_on' => '2026-12-01', 'ends_on' => '2026-12-03']);
    expect(collect($short->json('data.issues'))->pluck('code')->all())->toContain('below_min_fraction');

    $a = vplCall($this->hr, 'POST', '/vacation-agreements', ['employment_id' => $this->emp, 'kind' => 'fractionation', 'effective_on' => '2026-11-13']);
    $a->assertCreated();
    $id = (string) $a->json('data.id');
    vplCall($this->hr, 'POST', "/vacation-agreements/{$id}/issue")->assertOk();
    TenancyScope::clear();
    app('auth')->forgetGuards();
    $this->actingAs($this->hr, 'ayni')->withHeaders(['X-Tenant-Id' => VPL_TENANT, 'Idempotency-Key' => (string) Str::uuid()])
        ->post('/api/v1/vacation-agreements/'.$id.'/register-signature', ['file' => UploadedFile::fake()->create('f.pdf', 5, 'application/pdf'), 'signed_on' => '2026-11-13'], ['Accept' => 'application/json'])->assertOk();

    $after = vplCall($this->employee, 'POST', '/vacation-requests/simulate', ['employment_id' => $this->emp, 'starts_on' => '2026-12-01', 'ends_on' => '2026-12-03']);
    expect(collect($after->json('data.issues'))->pluck('code')->all())->not->toContain('below_min_fraction');
});

test('VAC-7 · el saldo importado entra como movimiento de apertura y valida trabajador y periodo', function (): void {
    $csv = implode("\n", [
        'document_number;legal_entity;period_start;available_days;effective_on',
        '40000002;20555555551;2025-01-01;12;2025-12-31',
        '40000002;20555555551;2026-01-01;5;2025-12-31',
        '99999999;20555555551;2025-01-01;5;2025-12-31',
    ])."\n";
    $up = vplCall($this->hr, 'POST', '/imports', ['entity' => 'vacation_balances', 'file_name' => 'saldos.csv', 'content_base64' => base64_encode($csv)]);
    $up->assertCreated();
    $id = (string) $up->json('data.id');
    vplCall($this->hr, 'POST', "/imports/{$id}/map", ['mapping' => $up->json('data.suggested_mapping')])->assertOk();
    $validated = vplCall($this->hr, 'POST', "/imports/{$id}/validate")->assertOk();
    expect($validated->json('data.error_rows'))->toBe(2);
    vplCall($this->hr, 'POST', "/imports/{$id}/execute", ['confirm' => true])->assertOk();
    TenancyScope::runAs(VPL_TENANT, fn () => app(ImportRunner::class)->run($id));

    expect(vplBalance($this->emp)['totals']['available'])->toBe('12.00');
    $opening = TenancyScope::runAs(VPL_TENANT, fn () => DB::table('vacation_ledger_entries')->where('employment_id', $this->emp)->where('entry_type', 'opening_balance')->first());
    expect((string) $opening->days)->toBe('-18.00')->and(substr((string) $opening->effective_on, 0, 10))->toBe('2025-12-31');

    // Deshacer agrega el contraasiento: el libro no se borra.
    vplCall(vplUser('admin_empresa'), 'POST', "/imports/{$id}/revert")->assertOk();
    expect(vplBalance($this->emp)['totals']['available'])->toBe('30.00');
    expect(TenancyScope::runAs(VPL_TENANT, fn () => DB::table('vacation_ledger_entries')->where('employment_id', $this->emp)->where('entry_type', 'opening_balance')->count()))->toBe(1);
});
