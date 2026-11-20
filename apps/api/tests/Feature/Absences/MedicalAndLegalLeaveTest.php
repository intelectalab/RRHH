<?php

/**
 * AUS-3 · Descansos médicos y subsidios: certificado (CITT o particular) con diagnóstico confidencial por
 * permiso de campo, días a cargo del empleador frente a subsidio con el umbral como parámetro legal, acumulado
 * anual por trabajador, aviso al alcanzar el umbral y seguimiento del reembolso por acciones.
 * AUS-4 · Licencias de ley: duración desde parámetros con vigencia, ampliaciones por causal, documentos que se
 * piden, fecha de fin propuesta y fecha de retorno que ve el jefe.
 *
 * Hoy (congelado): lunes 2 de marzo de 2026. Los números de los parámetros son de prueba, no de la norma.
 */

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Notifications\Infrastructure\Subscribers\NotifyAbsenceSubsidy;
use App\Modules\Organization\Infrastructure\Models\LegalEntity;
use App\Shared\LegalParameters\LegalParameters;
use App\Shared\Outbox\DomainEventTypes;
use App\Shared\Outbox\Models\OutboxEvent;
use App\Shared\Permissions\ModuleGate;
use App\Shared\Permissions\PermissionResolver;
use App\Shared\Tenancy\TenancyScope;
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

const MLV_TENANT = 'aaaaaaaa-a053-4000-8000-00000000000a';
const MLV_OTHER = 'bbbbbbbb-a053-4000-8000-00000000000b';

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requiere PostgreSQL.');
    }
    Carbon::setTestNow('2026-03-02 15:00:00');
    Storage::fake('s3');
    config(['files.disk' => 's3']);
    TenancyScope::clear();
    app(LegalParameters::class)->sync('PE');
    foreach ([MLV_TENANT => 'mlv-org', MLV_OTHER => 'mlv-otra'] as $id => $slug) {
        DB::table('tenants')->insertOrIgnore(['id' => $id, 'slug' => $slug, 'name' => 'Grupo '.$slug, 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN']);
        DB::select('SELECT seed_tenant_defaults(?)', [$id]);
        TenancyScope::runAs($id, fn () => IdentityFixtures::enableAllModules());
    }

    $this->admin = mlvUser(MLV_TENANT, 'admin_empresa');
    $this->hr = mlvUser(MLV_TENANT, 'rrhh');
    $this->otherHr = mlvUser(MLV_OTHER, 'rrhh');

    $org = TenancyScope::runAs(MLV_TENANT, function (): array {
        $entity = LegalEntity::factory()->create(['timezone' => 'America/Lima'])->id;
        $site = (string) Str::uuid();
        DB::table('sites')->insert(['id' => $site, 'tenant_id' => MLV_TENANT, 'legal_entity_id' => $entity, 'code' => 'LIM', 'name' => 'Lima', 'timezone' => 'America/Lima']);
        $unit = (string) Str::uuid();
        DB::table('org_units')->insert(['id' => $unit, 'tenant_id' => MLV_TENANT, 'code' => 'OPE', 'name' => 'Operaciones']);
        $profile = (string) Str::uuid();
        DB::table('job_profiles')->insert(['id' => $profile, 'tenant_id' => MLV_TENANT, 'code' => 'ANA', 'name' => 'Analista']);

        $mk = function (string $name, ?string $manager) use ($entity, $site, $unit, $profile): array {
            $person = (string) Str::uuid();
            $employment = (string) Str::uuid();
            DB::table('people')->insert(['id' => $person, 'tenant_id' => MLV_TENANT, 'first_names' => $name, 'paternal_surname' => 'Prueba']);
            DB::table('employments')->insert(['id' => $employment, 'tenant_id' => MLV_TENANT, 'person_id' => $person, 'legal_entity_id' => $entity, 'employee_code' => strtoupper($name), 'hire_date' => '2024-01-01', 'status' => 'active']);
            DB::table('employment_assignments')->insert([
                'tenant_id' => MLV_TENANT, 'employment_id' => $employment, 'job_profile_id' => $profile, 'org_unit_id' => $unit,
                'site_id' => $site, 'manager_employment_id' => $manager, 'valid_from' => '2024-01-01',
            ]);

            return [$person, $employment];
        };
        [$bossPerson, $bossEmployment] = $mk('Marta', null);
        [$person, $employment] = $mk('Rosa', $bossEmployment);

        return compact('bossPerson', 'person', 'employment');
    });
    $this->boss = mlvUser(MLV_TENANT, 'manager', $org['bossPerson']);
    $this->employee = mlvUser(MLV_TENANT, 'empleado', $org['person']);
    $this->emp = $org['employment'];

    $flow = mlvCall($this->admin, 'POST', '/approval-flows', ['name' => 'Ausencias', 'subject_type' => 'absence_request',
        'steps' => ['levels' => [['mode' => 'sequential', 'approvers' => [['kind' => 'manager']]]]]]);
    $flow->assertCreated();
    mlvCall($this->admin, 'POST', '/approval-flows/'.$flow->json('data.id').'/publish')->assertOk();

    // Tipos del paquete Perú (se precargan al listarlos).
    $types = mlvCall($this->admin, 'GET', '/absence-types?per_page=100')->assertOk()->json('data');
    $this->types = collect($types)->pluck('id', 'code')->all();
});

afterEach(function (): void {
    TenancyScope::clear();
    Carbon::setTestNow();
});

function mlvUser(string $tenant, string $role, ?string $personId = null): User
{
    return TenancyScope::runAs($tenant, function () use ($role, $personId): User {
        $u = User::factory()->create(['person_id' => $personId]);
        $u->roleAssignments()->create(['role_id' => Role::query()->where('system_role_code', $role)->value('id')]);

        return $u;
    });
}

/**
 * @param  array<string, mixed>  $body
 */
function mlvCall(User $user, string $method, string $path, array $body = []): TestResponse
{
    TenancyScope::clear();
    app('auth')->forgetGuards();
    app(PermissionResolver::class)->forget();
    app(ModuleGate::class)->forget();

    return test()->actingAs($user, 'ayni')->withHeaders(['X-Tenant-Id' => $user->tenant_id, 'Idempotency-Key' => (string) Str::uuid()])->json($method, '/api/v1'.$path, $body);
}

/** Valor de prueba de un parámetro legal (la fila vigente se reemplaza dentro de la transacción de la prueba). */
function mlvLegal(string $code, string $value, bool $verified = true): void
{
    TenancyScope::clear();
    $current = DB::table('legal_parameters')->where('country_code', 'PE')->where('code', $code)->whereNull('valid_to')->first();
    expect($current)->not->toBeNull();
    app(EffectiveDated::class)->change('legal_parameters', ['country_code' => 'PE', 'labor_regime_code' => $current->labor_regime_code, 'code' => $code], [
        'name' => $current->name, 'unit' => $current->unit, 'value_numeric' => $value, 'verified' => $verified, 'legal_source' => 'Prueba',
    ], (string) $current->valid_from, EffectiveDated::SAME_DAY_REPLACE);
}

/** RRHH registra un descanso con su sustento (borrador → adjunto → registrar). */
function mlvRegister(string $typeCode, string $from, ?string $to, array $extra = []): TestResponse
{
    $t = test();
    $draft = mlvCall($t->hr, 'POST', '/absences', $extra + ['employment_id' => $t->emp, 'absence_type_id' => $t->types[$typeCode], 'starts_on' => $from] + ($to === null ? [] : ['ends_on' => $to]));
    expect($draft->status())->toBe(201, (string) $draft->getContent());
    $id = (string) $draft->json('data.id');
    TenancyScope::clear();
    app('auth')->forgetGuards();
    $t->actingAs($t->hr, 'ayni')->withHeaders(['X-Tenant-Id' => MLV_TENANT, 'Idempotency-Key' => (string) Str::uuid()])
        ->post("/api/v1/absences/{$id}/attachments", ['file' => UploadedFile::fake()->createWithContent('certificado.pdf', "%PDF-1.4\n%prueba\n")], ['Accept' => 'application/json'])
        ->assertCreated();

    return mlvCall($t->hr, 'POST', "/absences/{$id}/register");
}

test('AUS-3 · el acumulado del año reparte empleador y subsidio con el umbral legal, avisa al alcanzarlo y abre el expediente', function (): void {
    mlvLegal('descanso_medico_dias_cargo_empleador', '20');
    mlvLegal('subsidio_reembolso_plazo_meses', '6');

    $first = mlvRegister('descanso_medico', '2026-03-02', '2026-03-16');
    $first->assertOk();
    $this->assertMatchesOpenApi($first, 'post', '/absences/{id}/register', 200);
    expect($first->json('data'))->toMatchArray(['status' => 'in_progress', 'employer_paid_days' => '15', 'subsidized_days' => '0', 'subsidy_status' => null]);

    // Simulación del segundo: con 15 días previos, 5 los paga el empleador y 5 pasan a subsidio; avisa el umbral.
    $sim = mlvCall($this->hr, 'POST', '/absences/simulate', ['employment_id' => $this->emp, 'absence_type_id' => $this->types['descanso_medico'], 'starts_on' => '2026-03-20', 'ends_on' => '2026-03-29']);
    $sim->assertOk();
    $this->assertMatchesOpenApi($sim, 'post', '/absences/simulate', 200);
    expect($sim->json('data.subsidy'))->toMatchArray(['kind' => 'illness', 'threshold_days' => '20', 'prior_days' => '15', 'accumulated_days' => '25', 'employer_paid_days' => '5', 'subsidized_days' => '5', 'reaches_threshold' => true])
        ->and(collect($sim->json('data.warnings'))->pluck('code')->all())->toContain('subsidy_threshold')
        ->and(collect($sim->json('data.required_documents'))->pluck('code')->all())->toBe(['medical_certificate']);

    $second = mlvRegister('descanso_medico', '2026-03-20', '2026-03-29');
    $second->assertOk();
    $absenceId = (string) $second->json('data.id');
    expect($second->json('data'))->toMatchArray(['employer_paid_days' => '5', 'subsidized_days' => '5', 'subsidy_status' => 'pending_filing']);

    // El expediente: desde el sexto día, con plazo de presentación desde el parámetro.
    $list = mlvCall($this->hr, 'GET', '/absence-subsidies?filter[status]=pending_filing');
    $list->assertOk();
    $this->assertMatchesOpenApi($list, 'get', '/absence-subsidies', 200);
    expect($list->json('data'))->toHaveCount(1)
        ->and($list->json('data.0'))->toMatchArray(['absence_request_id' => $absenceId, 'kind' => 'illness', 'status' => 'pending_filing', 'subsidized_days' => '5',
            'starts_on' => '2026-03-25', 'ends_on' => '2026-03-29', 'year' => 2026, 'filing_due_on' => '2026-09-29', 'is_overdue' => false, 'allowed_actions' => ['file']]);
    $caseId = (string) $list->json('data.0.id');

    // Evento y aviso a quien gestiona subsidios, sin datos de salud.
    $event = TenancyScope::runAs(MLV_TENANT, fn () => OutboxEvent::query()->where('event_type', DomainEventTypes::ABSENCE_SUBSIDY_STARTED)->where('aggregate_id', $caseId)->sole());
    expect($event->payload)->toMatchArray(['absence_request_id' => $absenceId, 'kind' => 'illness', 'subsidized_days' => '5']);
    app(NotifyAbsenceSubsidy::class)->handle($event);
    TenancyScope::runAs(MLV_TENANT, function () use ($caseId): void {
        $rows = DB::table('notifications')->where('subject_id', $caseId)->get();
        expect($rows->pluck('recipient_user_id')->map(fn ($u) => (string) $u)->all())->toContain((string) $this->hr->id)
            ->and($rows->pluck('category')->unique()->all())->toBe(['absences.subsidy_started'])
            ->and($rows->pluck('body')->implode(' '))->not->toContain('médico');
    });

    // Acumulado anual del trabajador.
    $summary = mlvCall($this->hr, 'GET', "/employments/{$this->emp}/medical-leave-summary?year=2026");
    $summary->assertOk();
    $this->assertMatchesOpenApi($summary, 'get', '/employments/{employment_id}/medical-leave-summary', 200);
    expect($summary->json('data'))->toMatchArray(['threshold_days' => '20', 'pending_validation' => false, 'accumulated_days' => '25', 'employer_paid_days' => '20',
        'subsidized_days' => '5', 'remaining_employer_days' => '0', 'threshold_reached' => true])
        ->and($summary->json('data.items'))->toHaveCount(2);

    // El jefe no ve el reparto (revela un descanso médico) ni el tipo.
    $boss = mlvCall($this->boss, 'GET', "/absences/{$absenceId}");
    $boss->assertOk();
    $this->assertMatchesOpenApi($boss, 'get', '/absences/{id}', 200);
    expect($boss->json('data'))->not->toHaveKey('subsidized_days')->not->toHaveKey('subsidy_status')
        ->and($boss->json('data.absence_type_name'))->toBe('Ausencia justificada');
});

test('AUS-3 · el reembolso se sigue por acciones: presentar, observar, volver a presentar, aprobar y reembolsar; fuera de orden 409', function (): void {
    mlvLegal('descanso_medico_dias_cargo_empleador', '20');
    mlvRegister('descanso_medico', '2026-03-02', '2026-04-05')->assertOk(); // 35 días: 15 de subsidio.
    $caseId = (string) mlvCall($this->hr, 'GET', '/absence-subsidies')->assertOk()->json('data.0.id');

    $bad = mlvCall($this->hr, 'POST', "/absence-subsidies/{$caseId}/approve");
    $bad->assertStatus(409)->assertJsonPath('error.code', 'invalid_transition');
    $this->assertMatchesOpenApi($bad, 'post', '/absence-subsidies/{id}/approve', 409);

    mlvCall($this->hr, 'POST', "/absence-subsidies/{$caseId}/file", ['filed_on' => '2026-03-05'])->assertStatus(422);
    $filed = mlvCall($this->hr, 'POST', "/absence-subsidies/{$caseId}/file", ['filed_on' => '2026-03-01', 'filing_reference' => 'EXP-001', 'amount_requested' => ['amount' => '1250.50', 'currency' => 'PEN']]);
    $filed->assertOk();
    $this->assertMatchesOpenApi($filed, 'post', '/absence-subsidies/{id}/file', 200);
    expect($filed->json('data'))->toMatchArray(['status' => 'filed', 'filing_reference' => 'EXP-001', 'amount_requested' => ['amount' => '1250.50', 'currency' => 'PEN']])
        ->and($filed->json('data.allowed_actions'))->toBe(['observe', 'approve', 'reject']);

    mlvCall($this->hr, 'POST', "/absence-subsidies/{$caseId}/observe", [])->assertStatus(422);
    $observed = mlvCall($this->hr, 'POST', "/absence-subsidies/{$caseId}/observe", ['reason' => 'Falta el CITT firmado']);
    $observed->assertOk()->assertJsonPath('data.status', 'observed')->assertJsonPath('data.observation', 'Falta el CITT firmado');
    $this->assertMatchesOpenApi($observed, 'post', '/absence-subsidies/{id}/observe', 200);
    mlvCall($this->hr, 'POST', "/absence-subsidies/{$caseId}/file", ['filed_on' => '2026-03-02'])->assertOk()->assertJsonPath('data.observation', null);

    mlvCall($this->hr, 'POST', "/absence-subsidies/{$caseId}/approve", ['amount_approved' => ['amount' => '1200', 'currency' => 'USD']])->assertStatus(422);
    $approved = mlvCall($this->hr, 'POST', "/absence-subsidies/{$caseId}/approve", ['amount_approved' => ['amount' => '1200']]);
    $approved->assertOk()->assertJsonPath('data.amount_approved', ['amount' => '1200.00', 'currency' => 'PEN']);
    $this->assertMatchesOpenApi($approved, 'post', '/absence-subsidies/{id}/approve', 200);

    $paid = mlvCall($this->hr, 'POST', "/absence-subsidies/{$caseId}/reimburse", ['reimbursed_on' => '2026-03-02', 'amount_reimbursed' => ['amount' => '1200.00', 'currency' => 'PEN']]);
    $paid->assertOk();
    $this->assertMatchesOpenApi($paid, 'post', '/absence-subsidies/{id}/reimburse', 200);
    expect($paid->json('data'))->toMatchArray(['status' => 'reimbursed', 'reimbursed_on' => '2026-03-02', 'allowed_actions' => []]);

    $show = mlvCall($this->hr, 'GET', "/absence-subsidies/{$caseId}");
    $show->assertOk();
    $this->assertMatchesOpenApi($show, 'get', '/absence-subsidies/{id}', 200);
    mlvCall($this->hr, 'POST', "/absence-subsidies/{$caseId}/reject", ['reason' => 'x'])->assertStatus(409);

    TenancyScope::runAs(MLV_TENANT, function () use ($caseId): void {
        expect(DB::table('audit_events')->where('entity_id', $caseId)->pluck('action')->all())
            ->toContain('absence_subsidy.opened', 'absence_subsidy.file', 'absence_subsidy.observe', 'absence_subsidy.approve', 'absence_subsidy.reimburse');
    });

    // Permisos y aislamiento: el colaborador no gestiona subsidios (403); otra organización no ve el expediente (404).
    mlvCall($this->employee, 'GET', '/absence-subsidies')->assertStatus(403);
    mlvCall($this->employee, 'POST', "/absence-subsidies/{$caseId}/file", ['filed_on' => '2026-03-01'])->assertStatus(403);
    mlvCall($this->otherHr, 'GET', "/absence-subsidies/{$caseId}")->assertStatus(404);
    mlvCall($this->otherHr, 'POST', "/absence-subsidies/{$caseId}/observe", ['reason' => 'x'])->assertStatus(404);
    mlvCall($this->otherHr, 'GET', "/employments/{$this->emp}/medical-leave-summary")->assertStatus(404);
    expect(mlvCall($this->otherHr, 'GET', '/absence-subsidies')->assertOk()->json('data'))->toBe([]);
});

test('AUS-3 · sin umbral validado todo queda a cargo del empleador y se avisa; la maternidad es subsidio completo', function (): void {
    // El umbral se aprobó (20 días, ADR 0021); aquí se prueba el caso en que vuelve a quedar «por validar».
    TenancyScope::clear();
    $current = DB::table('legal_parameters')->where('country_code', 'PE')->where('code', 'descanso_medico_dias_cargo_empleador')->whereNull('valid_to')->first();
    app(EffectiveDated::class)->change('legal_parameters', ['country_code' => 'PE', 'labor_regime_code' => null, 'code' => 'descanso_medico_dias_cargo_empleador'], [
        'name' => $current->name, 'unit' => $current->unit, 'value_numeric' => null, 'verified' => false, 'legal_source' => 'Prueba',
    ], (string) $current->valid_from, EffectiveDated::SAME_DAY_REPLACE);
    $sim = mlvCall($this->hr, 'POST', '/absences/simulate', ['employment_id' => $this->emp, 'absence_type_id' => $this->types['descanso_medico'], 'starts_on' => '2026-03-02', 'ends_on' => '2026-03-29']);
    $sim->assertOk();
    expect($sim->json('data.subsidy'))->toMatchArray(['threshold_days' => null, 'pending_validation' => true, 'employer_paid_days' => '28', 'subsidized_days' => '0'])
        ->and(collect($sim->json('data.warnings'))->pluck('code')->all())->toContain('subsidy_threshold_pending');
    mlvRegister('descanso_medico', '2026-03-02', '2026-03-29')->assertOk()->assertJsonPath('data.subsidized_days', '0');
    expect(mlvCall($this->hr, 'GET', '/absence-subsidies')->json('data'))->toBe([]);

    // Maternidad: el parámetro sin validar no bloquea; todo el descanso pasa a subsidio.
    $mat = mlvRegister('licencia_maternidad', '2026-04-01', '2026-04-30');
    $mat->assertOk();
    expect($mat->json('data'))->toMatchArray(['employer_paid_days' => '0', 'subsidized_days' => '30', 'subsidy_status' => 'pending_filing'])
        ->and(collect($mat->json('meta.warnings'))->pluck('code')->all())->toContain('legal_duration_pending');
});

test('AUS-3 · el certificado médico: diagnóstico cifrado y solo con permiso de campo, lectura registrada, 403 y 422', function (): void {
    $absence = (string) mlvRegister('descanso_medico', '2026-03-02', '2026-03-11')->assertOk()->json('data.id');

    $saved = mlvCall($this->hr, 'PUT', "/absences/{$absence}/medical-certificate", [
        'kind' => 'citt', 'certificate_number' => 'CITT-000123', 'issuer' => 'Hospital de prueba', 'diagnosis' => 'Influenza estacional',
        'issued_on' => '2026-03-02', 'days_granted' => 8, 'is_work_related' => false, 'validated' => true,
    ]);
    $saved->assertOk();
    $this->assertMatchesOpenApi($saved, 'put', '/absences/{id}/medical-certificate', 200);
    expect($saved->json('data'))->toMatchArray(['kind' => 'citt', 'certificate_number' => 'CITT-000123', 'diagnosis' => 'Influenza estacional',
        'days_granted' => 8, 'absence_calendar_days' => 10, 'days_mismatch' => true])
        ->and($saved->json('data.validated_at'))->not->toBeNull();

    TenancyScope::runAs(MLV_TENANT, function () use ($absence): void {
        $raw = DB::table('absence_medical_certificates')->where('absence_request_id', $absence)->first();
        expect((string) $raw->diagnosis_enc)->not->toContain('Influenza')->and((string) $raw->certificate_number_enc)->not->toContain('000123');
        $audit = DB::table('audit_events')->where('entity_id', $absence)->where('action', 'absence.medical_certificate_created')->sole();
        expect(json_encode($audit))->not->toContain('Influenza')->not->toContain('000123');
        expect(DB::table('data_access_logs')->where('entity_type', 'AbsenceMedicalCertificate')->where('user_id', $this->hr->id)->value('fields'))->toBe('{certificate_number,diagnosis}');
    });

    $read = mlvCall($this->hr, 'GET', "/absences/{$absence}/medical-certificate");
    $read->assertOk()->assertJsonPath('data.diagnosis', 'Influenza estacional');
    $this->assertMatchesOpenApi($read, 'get', '/absences/{id}/medical-certificate', 200);

    // El jefe y el administrador (sin permiso de datos de salud) no leen ni escriben el certificado.
    mlvCall($this->boss, 'GET', "/absences/{$absence}/medical-certificate")->assertStatus(403);
    mlvCall($this->admin, 'GET', "/absences/{$absence}/medical-certificate")->assertStatus(403);
    $forbidden = mlvCall($this->admin, 'PUT', "/absences/{$absence}/medical-certificate", ['diagnosis' => 'x']);
    $forbidden->assertStatus(403);
    $this->assertMatchesOpenApi($forbidden, 'put', '/absences/{id}/medical-certificate', 403);
    mlvCall($this->otherHr, 'GET', "/absences/{$absence}/medical-certificate")->assertStatus(404);

    // Datos inválidos y un tipo que no es médico: 422.
    mlvCall($this->hr, 'PUT', "/absences/{$absence}/medical-certificate", ['kind' => 'otro'])->assertStatus(422);
    $own = mlvCall($this->admin, 'POST', '/absence-types', ['code' => 'tramite', 'name' => 'Trámite', 'category' => 'personal', 'effective_from' => '2025-01-01',
        'rules' => ['unit' => 'days', 'requires_document' => false, 'requestable_by' => 'employee']])->assertCreated()->json('data.id');
    $plain = mlvCall($this->hr, 'POST', '/absences', ['employment_id' => $this->emp, 'absence_type_id' => $own, 'starts_on' => '2026-03-10'])->assertCreated()->json('data.id');
    $notMedical = mlvCall($this->hr, 'PUT', "/absences/{$plain}/medical-certificate", ['diagnosis' => 'x']);
    $notMedical->assertStatus(422)->assertJsonPath('error.code', 'not_medical_absence');
    $this->assertMatchesOpenApi($notMedical, 'put', '/absences/{id}/medical-certificate', 422);
});

test('AUS-4 · la licencia de paternidad toma su duración del parámetro, se amplía por causal, propone el fin y el jefe ve el retorno', function (): void {
    mlvLegal('licencia_paternidad_dias', '10');
    mlvLegal('licencia_paternidad_dias_prematuro_multiple', '20');
    $type = $this->types['licencia_paternidad'];

    $sim = mlvCall($this->employee, 'POST', '/absences/simulate', ['employment_id' => $this->emp, 'absence_type_id' => $type, 'starts_on' => '2026-03-10']);
    $sim->assertOk();
    $this->assertMatchesOpenApi($sim, 'post', '/absences/simulate', 200);
    expect($sim->json('data'))->toMatchArray(['ends_on' => '2026-03-19', 'return_on' => '2026-03-20', 'calendar_days' => 10])
        ->and($sim->json('data.legal_leave'))->toMatchArray(['base_parameter' => 'licencia_paternidad_dias', 'base_days' => '10', 'total_days' => '10', 'verified' => true])
        ->and(collect($sim->json('data.legal_leave.extensions'))->pluck('code')->all())->toBe(['premature_or_multiple', 'serious_condition'])
        ->and(collect($sim->json('data.required_documents'))->pluck('code')->all())->toBe(['birth_certificate']);

    // Con la causal de parto prematuro o múltiple la duración es la del parámetro de la causal; el retorno salta el fin de semana.
    $ext = mlvCall($this->employee, 'POST', '/absences/simulate', ['employment_id' => $this->emp, 'absence_type_id' => $type, 'starts_on' => '2026-03-10', 'legal_extensions' => ['premature_or_multiple']]);
    expect($ext->json('data'))->toMatchArray(['ends_on' => '2026-03-29', 'return_on' => '2026-03-30', 'calendar_days' => 20])
        ->and($ext->json('data.legal_leave.total_days'))->toBe('20');

    // Una causal que el tipo no tiene: 422.
    mlvCall($this->employee, 'POST', '/absences/simulate', ['employment_id' => $this->emp, 'absence_type_id' => $type, 'starts_on' => '2026-03-10', 'legal_extensions' => ['inventada']])
        ->assertStatus(422)->assertJsonPath('error.details.0.field', 'legal_extensions');

    // Menos que la duración de ley validada: no se envía.
    $short = mlvCall($this->employee, 'POST', '/absences', ['employment_id' => $this->emp, 'absence_type_id' => $type, 'starts_on' => '2026-03-10', 'ends_on' => '2026-03-12']);
    $short->assertCreated();
    mlvCall($this->employee, 'POST', '/absences/'.$short->json('data.id').'/submit')->assertStatus(422)->assertJsonPath('error.code', 'below_legal_duration');

    // Sin fecha de fin, se guarda la propuesta con la causal y el jefe ve la fecha de retorno al aprobar.
    $created = mlvCall($this->employee, 'POST', '/absences', ['employment_id' => $this->emp, 'absence_type_id' => $type, 'starts_on' => '2026-04-06', 'legal_extensions' => ['premature_or_multiple']]);
    $created->assertCreated();
    $this->assertMatchesOpenApi($created, 'post', '/absences', 201);
    expect($created->json('data'))->toMatchArray(['ends_on' => '2026-04-25', 'return_on' => '2026-04-27', 'legal_extensions' => ['premature_or_multiple']])
        ->and(collect($created->json('data.required_documents'))->pluck('code')->all())->toBe(['birth_certificate']);
    $id = (string) $created->json('data.id');
    TenancyScope::clear();
    app('auth')->forgetGuards();
    $this->actingAs($this->employee, 'ayni')->withHeaders(['X-Tenant-Id' => MLV_TENANT, 'Idempotency-Key' => (string) Str::uuid()])
        ->post("/api/v1/absences/{$id}/attachments", ['file' => UploadedFile::fake()->createWithContent('partida.pdf', "%PDF-1.4\n%prueba\n")], ['Accept' => 'application/json'])
        ->assertCreated();
    $sent = mlvCall($this->employee, 'POST', "/absences/{$id}/submit");
    $sent->assertOk()->assertJsonPath('data.status', 'pending');
    $approval = (string) $sent->json('data.approval_request_id');
    mlvCall($this->boss, 'POST', "/approval-requests/{$approval}/approve")->assertOk();
    $shown = mlvCall($this->boss, 'GET', "/absences/{$id}");
    $shown->assertOk();
    $this->assertMatchesOpenApi($shown, 'get', '/absences/{id}', 200);
    expect($shown->json('data'))->toMatchArray(['status' => 'approved', 'return_on' => '2026-04-27', 'ends_on' => '2026-04-25']);
});

test('AUS-4 · maternidad con causal: la duración por validar se propone y no bloquea', function (): void {
    mlvLegal('licencia_maternidad_dias', '90', false);
    mlvLegal('licencia_maternidad_dias_adicionales_parto_multiple', '30', false);
    $sim = mlvCall($this->hr, 'POST', '/absences/simulate', ['employment_id' => $this->emp, 'absence_type_id' => $this->types['licencia_maternidad'], 'starts_on' => '2026-03-02', 'legal_extensions' => ['multiple_birth']]);
    $sim->assertOk();
    expect($sim->json('data'))->toMatchArray(['ends_on' => '2026-06-29', 'calendar_days' => 120, 'issues' => []])
        ->and($sim->json('data.legal_leave'))->toMatchArray(['total_days' => '120', 'pending_validation' => true, 'verified' => false])
        ->and(collect($sim->json('data.warnings'))->pluck('code')->all())->toContain('legal_duration_pending', 'legal_documents')
        ->and(collect($sim->json('data.required_documents'))->pluck('code')->all())->toBe(['maternity_certificate', 'birth_certificate']);

    // Una licencia más corta que la propuesta por validar solo avisa.
    $short = mlvCall($this->hr, 'POST', '/absences/simulate', ['employment_id' => $this->emp, 'absence_type_id' => $this->types['licencia_maternidad'], 'starts_on' => '2026-03-02', 'ends_on' => '2026-03-31']);
    expect($short->json('data.issues'))->toBe([])->and(collect($short->json('data.warnings'))->pluck('code')->all())->toContain('legal_duration_pending');
});

test('AUS-3 · al cancelar un descanso aprobado su expediente sin presentar se anula', function (): void {
    mlvLegal('descanso_medico_dias_cargo_empleador', '5');
    $absence = mlvRegister('descanso_medico', '2026-04-01', '2026-04-10');
    $absence->assertOk()->assertJsonPath('data.subsidy_status', 'pending_filing')->assertJsonPath('data.subsidized_days', '5');
    $id = (string) $absence->json('data.id');

    $cancelled = mlvCall($this->hr, 'POST', "/absences/{$id}/cancel", ['reason' => 'Alta anticipada']);
    $cancelled->assertOk()->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.subsidy_status', 'cancelled');
    $this->assertMatchesOpenApi($cancelled, 'post', '/absences/{id}/cancel', 200);
    expect(mlvCall($this->hr, 'GET', '/absence-subsidies?filter[status]=cancelled')->assertOk()->json('data.0.allowed_actions'))->toBe([]);
});
