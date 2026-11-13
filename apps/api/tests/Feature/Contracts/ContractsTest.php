<?php

/**
 * CON-2 · Registro del contrato (ME-11): el ingreso deja el contrato en borrador; se completa, se aprueba,
 * se genera su documento y se registra firmado (RN-CON-06); RN-CON-01 (modalidad con fin y causa) y
 * RN-CON-07 (periodo de prueba) contra parámetros legales; vencido sin renovar → «Vencido sin regularizar»
 * (RN-CON-05); aviso del fin del periodo de prueba al jefe y a RRHH.
 * CON-3 · Renovaciones y adendas: la renovación crea el sucesor enlazado y valida los topes acumulados
 * (RN-CON-02, RN-CON-03); la cadena se ve en orden; una adenda firmada aplica sus cambios con vigencia
 * (RN-CON-08): la de sueldo crea la compensación y la de puesto, el movimiento de la asignación.
 */

use App\Modules\Contracts\Infrastructure\Models\Contract;
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
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\IdentityFixtures;

uses(DatabaseTransactions::class);

const CON_TENANT = 'aaaaaaaa-0c02-4000-8000-00000000000a';
const CON_OTHER = 'bbbbbbbb-0c02-4000-8000-00000000000b';

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requiere PostgreSQL.');
    }
    Storage::fake('s3');
    TenancyScope::clear();
    app(LegalParameters::class)->sync('PE');
    foreach ([CON_TENANT => 'con-org', CON_OTHER => 'con-otra'] as $id => $slug) {
        DB::table('tenants')->insertOrIgnore(['id' => $id, 'slug' => $slug, 'name' => 'Grupo '.$slug, 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN']);
        DB::select('SELECT seed_tenant_defaults(?)', [$id]);
        TenancyScope::runAs($id, fn () => IdentityFixtures::enableAllModules());
    }
    $this->admin = conUser(CON_TENANT, 'admin_empresa');
    $this->rrhh = conUser(CON_TENANT, 'rrhh');
    $this->other = conUser(CON_OTHER, 'admin_empresa');
    TenancyScope::runAs(CON_TENANT, function (): void {
        $entity = LegalEntity::factory()->create();
        DB::table('legal_entity_regimes')->where('legal_entity_id', $entity->id)->update(['valid_from' => '2020-01-01']);
        $this->entity = $entity->id;
        $this->unit = OrgUnit::factory()->create(['legal_entity_id' => $entity->id])->id;
        $this->unit2 = OrgUnit::factory()->create(['legal_entity_id' => $entity->id])->id;
        $this->site = Site::factory()->create(['legal_entity_id' => $entity->id])->id;
        $this->profile = JobProfile::factory()->create()->id;
    });
    $types = collect(conCall($this->admin, 'get', '/employment-options')->assertOk()->json('data.contract_types'));
    $this->indefinite = $types->firstWhere('code', 'indeterminado')['id'];
    $this->reconversion = $types->firstWhere('code', 'reconversion_empresarial')['id'];
});

afterEach(fn () => TenancyScope::clear());

function conUser(string $tenant, string $role, ?string $scope = null): User
{
    return TenancyScope::runAs($tenant, function () use ($role, $scope): User {
        $u = User::factory()->create();
        $u->roleAssignments()->create(['role_id' => Role::query()->where('system_role_code', $role)->value('id'), 'scope_override' => $scope]);

        return $u;
    });
}

/**
 * @param  array<string, mixed>  $body
 * @param  array<string, string>  $headers
 */
function conCall(User $user, string $method, string $path, array $body = [], bool $multipart = false, array $headers = []): TestResponse
{
    TenancyScope::clear();
    app('auth')->forgetGuards();
    app(PermissionResolver::class)->forget();
    app(ModuleGate::class)->forget();
    test()->flushHeaders();
    $test = test()->actingAs($user, 'ayni')->withHeaders($headers + ['X-Tenant-Id' => $user->tenant_id, 'Idempotency-Key' => (string) Str::uuid(), 'Accept' => 'application/json']);

    return $multipart ? $test->post('/api/v1'.$path, $body) : $test->json($method, '/api/v1'.$path, $body);
}

/**
 * Ingreso (COL-2) y su contrato en borrador.
 *
 * @param  array<string, mixed>  $extra
 * @return array{employment: string, contract: string}
 */
function conHire(array $extra = []): array
{
    $t = test();
    $hire = conCall($t->admin, 'post', '/employments', array_replace([
        'person' => ['first_names' => 'Rosa', 'paternal_surname' => 'Quispe', 'document_type' => 'dni', 'document_number' => (string) random_int(10000000, 89999999)],
        'legal_entity_id' => $t->entity, 'hire_date' => '2026-01-05', 'job_profile_id' => $t->profile, 'org_unit_id' => $t->unit, 'site_id' => $t->site,
        'contract_type_id' => $t->indefinite, 'base_salary' => ['amount' => '3000.00', 'currency' => 'PEN'],
    ], $extra))->assertCreated();
    $employment = (string) $hire->json('data.id');
    $chain = conCall($t->admin, 'get', '/employments/'.$employment.'/contracts')->assertOk();
    $t->assertMatchesOpenApi($chain, 'get', '/employments/{employment_id}/contracts', 200);

    return ['employment' => $employment, 'contract' => (string) $chain->json('data.0.id')];
}

function conSigned(string $name = 'contrato-firmado.pdf'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n% firmado\n");
}

/** Fija un parámetro legal (valor y unidad verificados) en la vigencia actual. */
function conLegal(string $code, int $value, string $unit): void
{
    TenancyScope::clear();
    $current = DB::table('legal_parameters')->where('country_code', 'PE')->where('code', $code)->whereNull('valid_to')->first();
    expect($current)->not->toBeNull();
    // Fija el valor desde el inicio de la vigencia actual del parámetro (solo para la prueba).
    app(EffectiveDated::class)->change('legal_parameters', ['country_code' => 'PE', 'labor_regime_code' => $current->labor_regime_code, 'code' => $code], [
        'name' => $current->name, 'unit' => $unit, 'value_numeric' => (string) $value, 'verified' => true, 'legal_source' => 'Prueba',
    ], (string) $current->valid_from, EffectiveDated::SAME_DAY_REPLACE);
}

function conEvents(string $type, string $aggregate): int
{
    return TenancyScope::runAs(CON_TENANT, fn () => DB::table('outbox_events')->where('event_type', $type)->where('aggregate_id', $aggregate)->count());
}

test('CON-2 · el contrato del ingreso se completa, se aprueba con su documento y rige al registrarse firmado', function (): void {
    ['contract' => $id] = conHire();
    $show = conCall($this->admin, 'get', '/contracts/'.$id)->assertOk();
    $this->assertMatchesOpenApi($show, 'get', '/contracts/{id}', 200);
    expect($show->json('data.status'))->toBe('draft')->and($show->json('data.allowed_actions'))->toContain('submit', 'register-signature', 'void');

    $patch = conCall($this->admin, 'patch', '/contracts/'.$id, ['trial_period_ends_on' => '2026-04-04', 'weekly_hours' => 48, 'work_modality' => 'hybrid'],
        false, ['If-Match' => (string) $show->json('data.version')])->assertOk();
    $this->assertMatchesOpenApi($patch, 'patch', '/contracts/{id}', 200);
    expect($patch->json('data.weekly_hours'))->toEqual(48)->and($patch->json('data.work_modality'))->toBe('hybrid');

    $submit = conCall($this->rrhh, 'post', '/contracts/'.$id.'/submit')->assertOk();
    $this->assertMatchesOpenApi($submit, 'post', '/contracts/{id}/submit', 200);
    expect($submit->json('data.status'))->toBe('pending_approval')->and($submit->json('data.number'))->not->toBeNull()
        ->and(collect($submit->json('data.warnings'))->pluck('code'))->toContain('trial_period_pending_validation');

    $approve = conCall($this->admin, 'post', '/contracts/'.$id.'/approve')->assertOk();
    $this->assertMatchesOpenApi($approve, 'post', '/contracts/{id}/approve', 200);
    expect($approve->json('data.status'))->toBe('pending_signature')->and($approve->json('data.has_document'))->toBeTrue()
        ->and($approve->json('data.document_version'))->toBe(1);

    $pdf = conCall($this->admin, 'get', '/contracts/'.$id.'/document')->assertOk();
    $this->assertMatchesOpenApi($pdf, 'get', '/contracts/{id}/document', 200);
    expect(substr((string) $pdf->getContent(), 0, 5))->toBe('%PDF-')->and($pdf->headers->get('Content-Type'))->toBe('application/pdf');

    $signed = conCall($this->rrhh, 'post', '/contracts/'.$id.'/register-signature', ['file' => conSigned(), 'signed_on' => '2026-01-05'], true)->assertOk();
    $this->assertMatchesOpenApi($signed, 'post', '/contracts/{id}/register-signature', 200);
    expect($signed->json('data.status'))->toBe('active')->and($signed->json('data.signature_origin'))->toBe('physical_scan')
        ->and($signed->json('data.document_version'))->toBe(2)
        ->and(conEvents(DomainEventTypes::CONTRACT_SIGNED, $id))->toBe(1)
        ->and(conEvents(DomainEventTypes::CONTRACT_STARTED, $id))->toBe(1)
        ->and(conEvents(DomainEventTypes::CONTRACT_ISSUED, $id))->toBe(1);
    TenancyScope::runAs(CON_TENANT, function () use ($id): void {
        expect(DB::table('audit_events')->where('entity_type', 'contract')->where('entity_id', $id)->pluck('action')->all())
            ->toContain('contract.updated', 'contract.submitted', 'contract.approved', 'contract.signed');
    });

    // Vigente: no se edita ni se vuelve a aprobar (regla 3).
    conCall($this->admin, 'patch', '/contracts/'.$id, ['weekly_hours' => 40])->assertStatus(409)->assertJsonPath('error.code', 'invalid_transition');
    $bad = conCall($this->admin, 'post', '/contracts/'.$id.'/approve')->assertStatus(409);
    $this->assertMatchesOpenApi($bad, 'post', '/contracts/{id}/approve', 409);
});

test('CON-2 · RN-CON-01 una modalidad exige fecha de fin y causa objetiva; RN-CON-07 el periodo de prueba no supera el máximo', function (): void {
    ['contract' => $id] = conHire();
    $res = conCall($this->admin, 'patch', '/contracts/'.$id, ['contract_type_id' => $this->reconversion, 'ends_on' => null])->assertStatus(422);
    $this->assertMatchesOpenApi($res, 'patch', '/contracts/{id}', 422);
    expect(collect($res->json('error.details'))->pluck('field')->all())->toContain('ends_on', 'objective_cause');

    conLegal('periodo_prueba_meses', 3, 'months');
    conCall($this->admin, 'patch', '/contracts/'.$id, ['trial_period_ends_on' => '2026-06-30'])->assertStatus(422)
        ->assertJsonPath('error.details.0.field', 'trial_period_ends_on');
    conCall($this->admin, 'patch', '/contracts/'.$id, ['trial_period_ends_on' => '2026-04-04'])->assertOk();
});

test('CON-2 · sin aprobación previa el envío deja el contrato en firma con su documento; un contrato físico firmado se registra desde borrador', function (): void {
    TenancyScope::runAs(CON_TENANT, fn () => app(ParameterResolver::class)->set('contratos.requiere_aprobacion', false, 'organization', null, null, 'Prueba'));
    ['contract' => $id] = conHire();
    $res = conCall($this->rrhh, 'post', '/contracts/'.$id.'/submit')->assertOk();
    expect($res->json('data.status'))->toBe('pending_signature')->and($res->json('data.has_document'))->toBeTrue();
    $back = conCall($this->rrhh, 'post', '/contracts/'.$id.'/decline-signature', ['reason' => 'El trabajador pidió corregir la jornada'])->assertOk();
    $this->assertMatchesOpenApi($back, 'post', '/contracts/{id}/decline-signature', 200);
    expect($back->json('data.status'))->toBe('draft');

    // Desde borrador solo el escaneo del físico firmado (RN-CON-06); la marca electrónica exige pasar por la firma.
    conCall($this->rrhh, 'post', '/contracts/'.$id.'/register-signature', ['file' => conSigned(), 'signed_on' => '2026-01-05', 'origin' => 'electronic'], true)
        ->assertStatus(422);
    conCall($this->rrhh, 'post', '/contracts/'.$id.'/register-signature', ['file' => UploadedFile::fake()->create('x.gif', 3, 'image/gif'), 'signed_on' => '2026-01-05'], true)
        ->assertStatus(422);
    $signed = conCall($this->rrhh, 'post', '/contracts/'.$id.'/register-signature', ['file' => conSigned(), 'signed_on' => '2026-01-05'], true)->assertOk();
    expect($signed->json('data.status'))->toBe('active');
});

test('CON-3 · la renovación crea el sucesor enlazado, valida el tope de la modalidad y la cadena se ve en orden; el vencido sin renovar queda por regularizar', function (): void {
    ['employment' => $emp, 'contract' => $first] = conHire([
        'hire_date' => '2026-01-01', 'contract_type_id' => $this->reconversion, 'contract_end_date' => '2026-06-30',
        'contract_objective_cause' => 'Reconversión de la línea de envasado',
    ]);
    // Contrato físico cargado ya firmado: rigió y, como venció sin renovarse, queda por regularizar (RN-CON-05).
    $signed = conCall($this->admin, 'post', '/contracts/'.$first.'/register-signature', ['file' => conSigned(), 'signed_on' => '2026-01-01'], true)->assertOk();
    expect($signed->json('data.status'))->toBe('expired')->and($signed->json('data.accumulated_days'))->toBe(181)
        ->and(conEvents(DomainEventTypes::CONTRACT_EXPIRED, $first))->toBe(1);

    $renew = conCall($this->admin, 'post', '/contracts/'.$first.'/renew', ['ends_on' => '2027-06-30'])->assertCreated();
    $this->assertMatchesOpenApi($renew, 'post', '/contracts/{id}/renew', 201);
    $second = (string) $renew->json('data.id');
    expect($renew->json('data.starts_on'))->toBe('2026-07-01')->and($renew->json('data.renewal_of_contract_id'))->toBe($first)
        ->and($renew->json('data.status'))->toBe('draft')->and($renew->json('data.accumulation.modality_days'))->toBe(546)
        ->and($renew->json('data.accumulation.modality_max_days'))->toBe(730);
    conCall($this->admin, 'post', '/contracts/'.$first.'/renew', ['ends_on' => '2027-03-31'])->assertStatus(409)->assertJsonPath('error.code', 'renewal_exists');

    conCall($this->admin, 'post', '/contracts/'.$second.'/register-signature', ['file' => conSigned('prorroga.pdf'), 'signed_on' => '2026-06-25'], true)->assertOk()
        ->assertJsonPath('data.status', 'active');
    expect(conCall($this->admin, 'get', '/contracts/'.$first)->json('data.status'))->toBe('terminated')
        ->and(conEvents(DomainEventTypes::CONTRACT_RENEWED, $second))->toBe(1);

    $chain = conCall($this->admin, 'get', '/employments/'.$emp.'/contracts')->assertOk();
    expect(collect($chain->json('data'))->pluck('id')->all())->toBe([$first, $second]);

    // RN-CON-02: reconversión empresarial con tope verificado de 2 años; dos años y medio acumulados no se renuevan.
    $over = conCall($this->admin, 'post', '/contracts/'.$second.'/renew', ['ends_on' => '2028-06-30'])->assertStatus(422);
    $this->assertMatchesOpenApi($over, 'post', '/contracts/{id}/renew', 422);
    expect($over->json('error.code'))->toBe('contract_limit_exceeded')->and($over->json('error.meta.modality_max_days'))->toBe(730);
    // Convertir a plazo indeterminado sí procede.
    conCall($this->admin, 'post', '/contracts/'.$second.'/renew', ['contract_type_id' => $this->indefinite, 'ends_on' => null])->assertCreated()
        ->assertJsonPath('data.starts_on', '2027-07-01');
});

test('CON-3 · RN-CON-03 el tope total de contratos a modalidad se valida cuando el parámetro está verificado', function (): void {
    conLegal('contrato_modalidad_tope_acumulado_anios', 1, 'years');
    ['contract' => $first] = conHire([
        'hire_date' => '2026-01-05', 'contract_type_id' => $this->reconversion, 'contract_end_date' => '2026-06-30', 'contract_objective_cause' => 'Reconversión',
    ]);
    conCall($this->admin, 'post', '/contracts/'.$first.'/register-signature', ['file' => conSigned(), 'signed_on' => '2026-01-05'], true)->assertOk();
    $res = conCall($this->admin, 'post', '/contracts/'.$first.'/renew', ['ends_on' => '2027-03-31'])->assertStatus(422);
    expect($res->json('error.meta.total_max_days'))->toBe(365)->and($res->json('error.message'))->toContain('indeterminado');
});

test('CON-3 · una adenda de sueldo firmada crea la compensación con vigencia; el sueldo solo viaja con permiso de campo', function (): void {
    ['employment' => $emp, 'contract' => $id] = conHire();
    conCall($this->admin, 'post', '/contracts/'.$id.'/register-signature', ['file' => conSigned(), 'signed_on' => '2026-01-05'], true)->assertOk();

    // RRHH crea adendas pero no pacta sueldos (no tiene people.employments.salary.edit).
    conCall($this->rrhh, 'post', '/contract-addenda', ['contract_id' => $id, 'kind' => 'salary', 'effective_on' => '2026-07-01', 'description' => 'Aumento',
        'changes' => ['base_salary' => ['amount' => '3500.00', 'currency' => 'PEN']]])->assertStatus(403);
    $create = conCall($this->admin, 'post', '/contract-addenda', ['contract_id' => $id, 'kind' => 'salary', 'effective_on' => '2026-07-01',
        'description' => 'Aumento por desempeño', 'changes' => ['base_salary' => ['amount' => '3500.00', 'currency' => 'PEN']]])->assertCreated();
    $this->assertMatchesOpenApi($create, 'post', '/contract-addenda', 201);
    $add = (string) $create->json('data.id');
    expect($create->json('data.salary_change.amount'))->toBe('3500.00');

    $issue = conCall($this->admin, 'post', '/contract-addenda/'.$add.'/issue')->assertOk();
    $this->assertMatchesOpenApi($issue, 'post', '/contract-addenda/{id}/issue', 200);
    expect($issue->json('data.status'))->toBe('pending_signature')->and($issue->json('data.has_document'))->toBeTrue();
    $doc = conCall($this->admin, 'get', '/contract-addenda/'.$add.'/document')->assertOk();
    $this->assertMatchesOpenApi($doc, 'get', '/contract-addenda/{id}/document', 200);

    $signed = conCall($this->admin, 'post', '/contract-addenda/'.$add.'/register-signature', ['file' => conSigned('adenda.pdf'), 'signed_on' => '2026-06-20'], true)->assertOk();
    $this->assertMatchesOpenApi($signed, 'post', '/contract-addenda/{id}/register-signature', 200);
    $compensation = (string) $signed->json('data.applied_refs.employment_compensation_id');
    TenancyScope::runAs(CON_TENANT, function () use ($emp, $compensation): void {
        $rows = DB::table('employment_compensations')->where('employment_id', $emp)->orderBy('valid_from')->get();
        expect($rows)->toHaveCount(2)->and((string) $rows[0]->valid_to)->toBe('2026-06-30')
            ->and((string) $rows[1]->id)->toBe($compensation)->and((string) $rows[1]->base_salary)->toBe('3500.00');
    });
    expect(conEvents(DomainEventTypes::CONTRACT_AMENDED, $id))->toBe(1);

    // Un jefe con alcance de organización ve la adenda sin el sueldo pactado (regla 6).
    $manager = conUser(CON_TENANT, 'manager', 'organization');
    $seen = conCall($manager, 'get', '/contract-addenda/'.$add)->assertOk();
    $this->assertMatchesOpenApi($seen, 'get', '/contract-addenda/{id}', 200);
    expect($seen->json('data'))->not->toHaveKey('salary_change');
    $chain = conCall($manager, 'get', '/employments/'.$emp.'/contracts')->assertOk();
    expect($chain->json('data.0.addenda.0'))->not->toHaveKey('salary_change')->and($chain->json('data.0.addenda.0.kind'))->toBe('salary');
});

test('CON-3 · una adenda de puesto firmada registra el movimiento de la asignación; una adenda se cancela con motivo', function (): void {
    ['employment' => $emp, 'contract' => $id] = conHire();
    conCall($this->admin, 'post', '/contracts/'.$id.'/register-signature', ['file' => conSigned(), 'signed_on' => '2026-01-05'], true)->assertOk();
    $add = (string) conCall($this->rrhh, 'post', '/contract-addenda', ['contract_id' => $id, 'kind' => 'position', 'effective_on' => '2026-08-01',
        'description' => 'Traslado al área de Calidad', 'changes' => ['org_unit_id' => $this->unit2]])->assertCreated()->json('data.id');
    conCall($this->rrhh, 'post', '/contract-addenda/'.$add.'/register-signature', ['file' => conSigned('adenda.pdf'), 'signed_on' => '2026-07-20'], true)->assertOk();
    TenancyScope::runAs(CON_TENANT, function () use ($emp): void {
        $current = DB::table('employment_assignments')->where('employment_id', $emp)->where('valid_from', '2026-08-01')->first();
        expect($current)->not->toBeNull()->and((string) $current->org_unit_id)->toBe($this->unit2)->and($current->personnel_action_id)->not->toBeNull();
    });

    $other = (string) conCall($this->rrhh, 'post', '/contract-addenda', ['contract_id' => $id, 'kind' => 'schedule', 'effective_on' => '2026-09-01',
        'description' => 'Jornada reducida', 'changes' => ['weekly_hours' => 30]])->assertCreated()->json('data.id');
    conCall($this->rrhh, 'post', '/contract-addenda/'.$other.'/cancel')->assertStatus(422);
    $cancel = conCall($this->rrhh, 'post', '/contract-addenda/'.$other.'/cancel', ['reason' => 'Se desistió'])->assertOk();
    $this->assertMatchesOpenApi($cancel, 'post', '/contract-addenda/{id}/cancel', 200);
    conCall($this->rrhh, 'post', '/contract-addenda/'.$other.'/issue')->assertStatus(409);
    $list = conCall($this->rrhh, 'get', '/contract-addenda?filter[contract_id]='.$id)->assertOk();
    $this->assertMatchesOpenApi($list, 'get', '/contract-addenda', 200);
    expect($list->json('meta.total'))->toBe(2);
});

test('CON-2 · la tarea diaria inicia el firmado futuro y avisa del fin del periodo de prueba al jefe y a RRHH', function (): void {
    ['contract' => $id] = conHire(['hire_date' => '2026-12-01']);
    conCall($this->admin, 'patch', '/contracts/'.$id, ['trial_period_ends_on' => '2027-02-28'])->assertOk();
    conCall($this->admin, 'post', '/contracts/'.$id.'/register-signature', ['file' => conSigned(), 'signed_on' => '2026-11-10'], true)->assertOk()
        ->assertJsonPath('data.status', 'signed');

    Artisan::call('contracts:apply-due', ['--date' => '2026-12-01']);
    expect(conCall($this->admin, 'get', '/contracts/'.$id)->json('data.status'))->toBe('active');
    Artisan::call('contracts:apply-due', ['--date' => '2027-02-22']);
    expect(conEvents(DomainEventTypes::CONTRACT_PROBATION_ENDING, $id))->toBe(1);
    Artisan::call('contracts:apply-due', ['--date' => '2027-02-23']);
    expect(conEvents(DomainEventTypes::CONTRACT_PROBATION_ENDING, $id))->toBe(1);
});

test('CON-2 · permisos, módulo y aislamiento: sin permiso 403, con el módulo apagado 403 y otra organización no ve el contrato', function (): void {
    ['contract' => $id] = conHire();
    $employee = conUser(CON_TENANT, 'empleado');
    $res = conCall($employee, 'post', '/contracts/'.$id.'/approve')->assertStatus(403);
    $this->assertMatchesOpenApi($res, 'post', '/contracts/{id}/approve', 403);
    conCall($this->rrhh, 'post', '/contracts/'.$id.'/terminate', ['reason' => 'x'])->assertStatus(409);

    $iso = conCall($this->other, 'get', '/contracts/'.$id)->assertStatus(404);
    $this->assertMatchesOpenApi($iso, 'get', '/contracts/{id}', 404);
    conCall($this->other, 'post', '/contracts/'.$id.'/submit')->assertStatus(404);
    expect(conCall($this->other, 'get', '/contracts')->assertOk()->json('meta.total'))->toBe(0);

    IdentityFixtures::disableModule(CON_TENANT, 'core');
    conCall($this->admin, 'get', '/contracts/'.$id)->assertStatus(403)->assertJsonPath('error.code', 'module_not_enabled');
});

test('CON-2 · un contrato nuevo solo se crea si la relación no tiene otro en curso', function (): void {
    ['employment' => $emp, 'contract' => $id] = conHire();
    conCall($this->admin, 'post', '/contracts', ['employment_id' => $emp, 'contract_type_id' => $this->indefinite, 'starts_on' => '2026-01-05'])
        ->assertStatus(409)->assertJsonPath('error.code', 'contract_exists');
    $void = conCall($this->admin, 'post', '/contracts/'.$id.'/void', ['reason' => 'Se registró mal el tipo'])->assertOk();
    $this->assertMatchesOpenApi($void, 'post', '/contracts/{id}/void', 200);
    $created = conCall($this->admin, 'post', '/contracts', ['employment_id' => $emp, 'contract_type_id' => $this->indefinite, 'starts_on' => '2026-01-05'])->assertCreated();
    $this->assertMatchesOpenApi($created, 'post', '/contracts', 201);
    $list = conCall($this->admin, 'get', '/contracts?filter[employment_id]='.$emp)->assertOk();
    $this->assertMatchesOpenApi($list, 'get', '/contracts', 200);
    expect($list->json('meta.total'))->toBe(2);
    TenancyScope::runAs(CON_TENANT, fn () => expect(Contract::query()->where('employment_id', $emp)->where('status', 'cancelled')->count())->toBe(1));
});
