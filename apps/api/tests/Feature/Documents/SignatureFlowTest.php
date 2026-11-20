<?php

/**
 * DOC-6 · DOC-7 · DOC-8 · CON-5 · Flujo de firma común: orden de firmantes, código de un solo uso, evidencia
 * encadenada e inalterable, firma con certificado por tipo documental (ADR 0019), rechazo con motivo, integración
 * con contratos y teletrabajo, y renovación masiva con firma y control del tope acumulado.
 */

use App\Modules\Contracts\Database\Factories\ContractFactory;
use App\Modules\Documents\Domain\SignatureRequestStateMachine;
use App\Modules\Documents\Infrastructure\Models\SignatureRequest;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Organization\Infrastructure\Models\JobProfile;
use App\Modules\Organization\Infrastructure\Models\LegalEntity;
use App\Modules\Organization\Infrastructure\Models\OrgUnit;
use App\Modules\Organization\Infrastructure\Models\Site;
use App\Shared\LegalParameters\LegalParameters;
use App\Shared\Permissions\ModuleGate;
use App\Shared\Permissions\PermissionResolver;
use App\Shared\Security\StepUpCodes;
use App\Shared\Tenancy\TenancyScope;
use App\Shared\Vigencia\EffectiveDated;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\IdentityFixtures;

uses(DatabaseTransactions::class);

const SIG_TENANT = 'aaaaaaaa-0d06-4000-8000-00000000000a';
const SIG_OTHER = 'bbbbbbbb-0d06-4000-8000-00000000000b';

/** Códigos de firma de prueba: se guardan en memoria en vez de enviarse. */
final class SigTestCodes implements StepUpCodes
{
    /** @var array<string, string> */
    public static array $codes = [];

    public function send(string $userId, string $purpose): ?array
    {
        self::$codes[$userId] = (string) random_int(100000, 999999);

        return ['channel' => 'email', 'reference' => 'ref-'.$userId, 'expires_at' => now()->addMinutes(5)->toIso8601String()];
    }

    public function verify(string $userId, string $purpose, string $code): bool
    {
        return isset(self::$codes[$userId]) && hash_equals(self::$codes[$userId], $code);
    }
}

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requiere PostgreSQL.');
    }
    Storage::fake('s3');
    TenancyScope::clear();
    app(LegalParameters::class)->sync('PE');
    app()->instance(StepUpCodes::class, new SigTestCodes);
    SigTestCodes::$codes = [];
    foreach ([SIG_TENANT => 'sig-org', SIG_OTHER => 'sig-otra'] as $id => $slug) {
        DB::table('tenants')->insertOrIgnore(['id' => $id, 'slug' => $slug, 'name' => 'Grupo '.$slug, 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN']);
        DB::select('SELECT seed_tenant_defaults(?)', [$id]);
        TenancyScope::runAs($id, fn () => IdentityFixtures::enableAllModules());
    }
    $this->hr = sigUser(SIG_TENANT, 'rrhh');
    $this->employee = sigUser(SIG_TENANT, 'empleado');
    $this->other = sigUser(SIG_OTHER, 'admin_empresa');
});

afterEach(fn () => TenancyScope::clear());

function sigUser(string $tenant, string $role): User
{
    return TenancyScope::runAs($tenant, function () use ($role): User {
        $u = User::factory()->create();
        $u->roleAssignments()->create(['role_id' => Role::query()->where('system_role_code', $role)->value('id')]);

        return $u;
    });
}

/** @param array<string, mixed> $body */
function sigCall(User $user, string $method, string $path, array $body = [], bool $multipart = false): TestResponse
{
    TenancyScope::clear();
    app('auth')->forgetGuards();
    app(PermissionResolver::class)->forget();
    app(ModuleGate::class)->forget();
    test()->flushHeaders();
    $t = test()->actingAs($user, 'ayni')->withHeaders(['X-Tenant-Id' => $user->tenant_id, 'Idempotency-Key' => (string) Str::uuid(), 'Accept' => 'application/json']);

    return $multipart ? $t->post('/api/v1'.$path, $body) : $t->json($method, '/api/v1'.$path, $body);
}

/** Documento nuevo del legajo (organización de prueba) sobre el que se arma una solicitud de firma. */
function sigDocument(): string
{
    return TenancyScope::runAs(SIG_TENANT, function (): string {
        $document = SignatureRequest::factory()->create()->document_id;
        // El archivo de la versión se escribe en el almacenamiento falso con su huella.
        $file = DB::table('documents as d')->join('document_versions as v', 'v.id', '=', 'd.current_version_id')
            ->where('d.id', $document)->first(['v.id as version_id', 'v.stored_file_id']);
        $contents = "%PDF-1.4\n% documento para firmar ".Str::random(8)."\n";
        $key = (string) DB::table('stored_files')->where('id', $file->stored_file_id)->value('storage_key');
        Storage::disk((string) config('files.disk', 's3'))->put($key, $contents);
        DB::table('stored_files')->where('id', $file->stored_file_id)->update(['sha256' => hash('sha256', $contents), 'size_bytes' => strlen($contents)]);
        DB::table('document_versions')->where('id', $file->version_id)->update(['content_hash' => hash('sha256', $contents)]);

        return $document;
    });
}

function sigSign(User $user, string $id): TestResponse
{
    sigCall($user, 'POST', "/me/signature-requests/{$id}/code")->assertOk();

    return sigCall($user, 'POST', "/me/signature-requests/{$id}/sign", ['code' => SigTestCodes::$codes[$user->id]]);
}

test('DOC-6 · DOC-7 · firma secuencial con código, evidencia encadenada, constancia y alteración detectable', function (): void {
    $document = sigDocument();
    // La fábrica dejó una solicitud en borrador sobre el documento: se cancela y se envía una nueva.
    $draft = TenancyScope::runAs(SIG_TENANT, fn () => SignatureRequest::query()->where('document_id', $document)->value('id'));
    sigCall($this->hr, 'POST', "/signature-requests/{$draft}/cancel", ['reason' => 'Se reemplaza'])->assertOk();

    $created = sigCall($this->hr, 'POST', '/signature-requests', [
        'document_id' => $document, 'signers' => [['user_id' => $this->employee->id, 'role' => 'Colaborador'], ['user_id' => $this->hr->id, 'role' => 'Empleador']], 'send' => true,
    ]);
    $this->assertMatchesOpenApi($created, 'post', '/signature-requests', 201);
    $id = $created->json('data.id');
    expect($created->json('data.status'))->toBe('sent')->and($created->json('data.signature_kind'))->toBe('electronic')
        ->and($created->json('data.signers.0.is_turn'))->toBeTrue()->and($created->json('data.signers.1.is_turn'))->toBeFalse();

    // Otro envío del mismo documento mientras hay uno en curso: 409.
    sigCall($this->hr, 'POST', '/signature-requests', ['document_id' => $document, 'signers' => [['user_id' => $this->hr->id]]])->assertStatus(409);

    // El empleador no firma antes que el colaborador.
    $early = sigCall($this->hr, 'POST', "/me/signature-requests/{$id}/code");
    $this->assertMatchesOpenApi($early, 'post', '/me/signature-requests/{id}/code', 409);
    $early->assertJsonPath('error.code', 'not_your_turn');

    $mine = sigCall($this->employee, 'GET', '/me/signature-requests');
    $this->assertMatchesOpenApi($mine, 'get', '/me/signature-requests', 200);
    expect($mine->json('data.0.my_turn'))->toBeTrue();
    expect(sigCall($this->employee, 'GET', '/inbox')->json('data'))->toBeArray();
    $viewed = sigCall($this->employee, 'POST', "/me/signature-requests/{$id}/view");
    $this->assertMatchesOpenApi($viewed, 'post', '/me/signature-requests/{id}/view', 200);
    expect($viewed->json('data.status'))->toBe('viewed');
    sigCall($this->employee, 'GET', "/me/signature-requests/{$id}/document")->assertOk();

    sigCall($this->employee, 'POST', "/me/signature-requests/{$id}/code")->assertOk();
    $wrong = sigCall($this->employee, 'POST', "/me/signature-requests/{$id}/sign", ['code' => '000000']);
    $this->assertMatchesOpenApi($wrong, 'post', '/me/signature-requests/{id}/sign', 422);
    $first = sigSign($this->employee, $id);
    $this->assertMatchesOpenApi($first, 'post', '/me/signature-requests/{id}/sign', 200);
    expect($first->json('data.status'))->toBe('in_progress');

    $done = sigSign($this->hr, $id);
    expect($done->json('data.status'))->toBe('completed')->and($done->json('data.has_certificate'))->toBeTrue();

    $evidence = sigCall($this->hr, 'GET', "/signature-requests/{$id}/evidence");
    $this->assertMatchesOpenApi($evidence, 'get', '/signature-requests/{id}/evidence', 200);
    $events = collect($evidence->json('data.events'))->pluck('event')->all();
    expect($evidence->json('data.chain_valid'))->toBeTrue()->and($evidence->json('data.document_intact'))->toBeTrue()
        ->and($events)->toContain('created', 'sent', 'viewed', 'otp_sent', 'otp_verified', 'signed', 'completed');
    sigCall($this->hr, 'GET', "/signature-requests/{$id}/certificate")->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect(TenancyScope::runAs(SIG_TENANT, fn () => DB::table('documents')->where('id', $document)->value('source')))->toBe('signature');

    // La evidencia es de solo inserción: la base rechaza cambiarla.
    expect(fn () => TenancyScope::runAs(SIG_TENANT, fn () => DB::transaction(fn () => DB::table('signature_events')->where('signature_request_id', $id)->update(['ip' => '10.0.0.1']))))
        ->toThrow(Exception::class);

    // Alterar el archivo firmado se detecta con la huella.
    TenancyScope::runAs(SIG_TENANT, function () use ($id): void {
        $key = DB::table('signature_requests as r')->join('document_versions as v', 'v.id', '=', 'r.document_version_id')
            ->join('stored_files as f', 'f.id', '=', 'v.stored_file_id')->where('r.id', $id)->value('f.storage_key');
        Storage::disk((string) config('files.disk', 's3'))->put((string) $key, 'alterado');
    });
    expect(sigCall($this->hr, 'GET', "/signature-requests/{$id}/evidence")->json('data.document_intact'))->toBeFalse();

    $list = sigCall($this->hr, 'GET', '/signature-requests?filter[status]=completed');
    $this->assertMatchesOpenApi($list, 'get', '/signature-requests', 200);
    expect($list->json('meta.total'))->toBe(1);
});

test('DOC-8 · el tipo documental marcado firma con certificado del proveedor; mismo flujo', function (): void {
    $document = sigDocument();
    TenancyScope::runAs(SIG_TENANT, function () use ($document): void {
        DB::table('signature_requests')->where('document_id', $document)->update(['status' => 'cancelled']);
        DB::table('tenant_settings')->insert(['tenant_id' => SIG_TENANT, 'parameter_code' => 'firma.tipos_con_certificado',
            'scope_kind' => 'organization', 'value' => json_encode(['signature_factory']), 'valid_from' => '2020-01-01']);
    });
    $created = sigCall($this->hr, 'POST', '/signature-requests', ['document_id' => $document, 'signers' => [['user_id' => $this->hr->id]], 'send' => true])->assertCreated();
    expect($created->json('data.signature_kind'))->toBe('certificate')->and($created->json('data.signature_level'))->toBe('digital_certificate');
    $id = $created->json('data.id');
    expect(sigSign($this->hr, $id)->json('data.status'))->toBe('completed');
    $signed = collect(sigCall($this->hr, 'GET', "/signature-requests/{$id}/evidence")->json('data.events'))->firstWhere('event', 'signed');
    expect($signed['provider_evidence']['certificate_serial'] ?? null)->not->toBeNull();
});

test('DOC-6 · rechazo con motivo, permisos y aislamiento', function (): void {
    $document = sigDocument();
    $id = TenancyScope::runAs(SIG_TENANT, fn () => SignatureRequest::query()->where('document_id', $document)->value('id'));
    TenancyScope::runAs(SIG_TENANT, fn () => DB::table('signature_request_signers')->insert([
        'tenant_id' => SIG_TENANT, 'signature_request_id' => $id, 'user_id' => $this->employee->id, 'signer_name' => 'Colaborador', 'sort_order' => 1,
    ]));
    $sent = sigCall($this->hr, 'POST', "/signature-requests/{$id}/send");
    $this->assertMatchesOpenApi($sent, 'post', '/signature-requests/{id}/send', 200);
    $this->assertMatchesOpenApi(sigCall($this->hr, 'POST', "/signature-requests/{$id}/send"), 'post', '/signature-requests/{id}/send', 409);
    $this->assertMatchesOpenApi(sigCall($this->hr, 'POST', "/signature-requests/{$id}/remind"), 'post', '/signature-requests/{id}/remind', 200);

    $this->assertMatchesOpenApi(sigCall($this->employee, 'POST', "/me/signature-requests/{$id}/decline"), 'post', '/me/signature-requests/{id}/decline', 422);
    $declined = sigCall($this->employee, 'POST', "/me/signature-requests/{$id}/decline", ['reason' => 'El documento tiene un error en mi cargo']);
    $this->assertMatchesOpenApi($declined, 'post', '/me/signature-requests/{id}/decline', 200);
    expect($declined->json('data.status'))->toBe('declined')->and($declined->json('data.signers.0.declined_reason'))->toContain('cargo');

    $this->assertMatchesOpenApi(sigCall($this->employee, 'POST', '/signature-requests', ['document_id' => $document, 'signers' => [['user_id' => $this->employee->id]]]), 'post', '/signature-requests', 403);
    $this->assertMatchesOpenApi(sigCall($this->other, 'GET', "/signature-requests/{$id}"), 'get', '/signature-requests/{id}', 404);
    $this->assertMatchesOpenApi(sigCall($this->other, 'GET', "/me/signature-requests/{$id}"), 'get', '/me/signature-requests/{id}', 404);
});

test('DOC-6 · matriz de transiciones del flujo de firma', function (): void {
    $machine = new SignatureRequestStateMachine;
    $open = ['sign', 'complete', 'decline', 'expire', 'cancel', 'remind', 'view'];
    $allowed = ['draft' => ['send', 'cancel'], 'sent' => $open, 'viewed' => $open, 'in_progress' => $open,
        'completed' => [], 'declined' => [], 'expired' => [], 'cancelled' => []];
    foreach ($allowed as $state => $ok) {
        foreach (['send', 'view', 'sign', 'complete', 'decline', 'expire', 'cancel', 'remind'] as $action) {
            expect($machine->can($state, $action))->toBe(in_array($action, $ok, true), "{$state} × {$action}");
        }
    }
});

/** Ingreso con un contrato a modalidad, registrado firmado. */
function sigFixedTerm(User $admin, array $ctx, string $from, string $to): string
{
    $employment = (string) sigCall($admin, 'post', '/employments', [
        'person' => ['first_names' => 'Rosa', 'paternal_surname' => 'Quispe', 'document_type' => 'dni', 'document_number' => (string) random_int(10000000, 89999999)],
        'legal_entity_id' => $ctx['entity'], 'hire_date' => $from, 'job_profile_id' => $ctx['profile'], 'org_unit_id' => $ctx['unit'], 'site_id' => $ctx['site'],
        'contract_type_id' => $ctx['type'], 'contract_end_date' => $to, 'contract_objective_cause' => 'Reconversión de la línea de envasado',
        'base_salary' => ['amount' => '2800.00', 'currency' => 'PEN'],
    ])->assertCreated()->json('data.id');
    $contract = (string) sigCall($admin, 'get', '/employments/'.$employment.'/contracts')->assertOk()->json('data.0.id');
    sigCall($admin, 'post', '/contracts/'.$contract.'/register-signature', [
        'file' => UploadedFile::fake()->createWithContent('firmado.pdf', "%PDF-1.4\n% firmado\n"), 'signed_on' => $from,
    ], true)->assertOk();

    return $contract;
}

test('CON-5 · lote de renovación: excluye lo que supera el tope, genera las renovaciones y las firma en lote', function (): void {
    $admin = sigUser(SIG_TENANT, 'admin_empresa');
    $ctx = TenancyScope::runAs(SIG_TENANT, function (): array {
        $entity = LegalEntity::factory()->create();
        DB::table('legal_entity_regimes')->where('legal_entity_id', $entity->id)->update(['valid_from' => '2020-01-01']);

        return ['entity' => $entity->id, 'unit' => OrgUnit::factory()->create(['legal_entity_id' => $entity->id])->id,
            'site' => Site::factory()->create(['legal_entity_id' => $entity->id])->id, 'profile' => JobProfile::factory()->create()->id];
    });
    $ctx['type'] = collect(sigCall($admin, 'get', '/employment-options')->json('data.contract_types'))->firstWhere('code', 'reconversion_empresarial')['id'];
    // Tope verificado de un año para la modalidad: la renovación larga lo supera.
    TenancyScope::clear();
    $current = DB::table('legal_parameters')->where('country_code', 'PE')->where('code', 'contrato_modalidad_tope_acumulado_anios')->whereNull('valid_to')->first();
    app(EffectiveDated::class)->change('legal_parameters', ['country_code' => 'PE', 'labor_regime_code' => $current->labor_regime_code, 'code' => 'contrato_modalidad_tope_acumulado_anios'], [
        'name' => $current->name, 'unit' => 'years', 'value_numeric' => '1', 'verified' => true, 'legal_source' => 'Prueba',
    ], (string) $current->valid_from, EffectiveDated::SAME_DAY_REPLACE);

    $short = sigFixedTerm($admin, $ctx, '2026-06-01', '2026-08-31');
    $long = sigFixedTerm($admin, $ctx, '2026-01-05', '2026-08-31');

    $created = sigCall($admin, 'POST', '/contract-renewal-batches', ['name' => 'Renovaciones de septiembre', 'new_end_date' => '2027-02-28', 'contract_ids' => [$short, $long]]);
    $this->assertMatchesOpenApi($created, 'post', '/contract-renewal-batches', 201);
    $items = collect($created->json('data.items'))->keyBy('contract_id');
    expect($items[$short]['decision'])->toBe('renew')->and($items[$long]['decision'])->toBe('not_renew')->and($items[$long]['exceeds_limit'])->toBeTrue()
        ->and($created->json('data.excluded'))->toBe(1);
    $batch = $created->json('data.id');

    $sent = sigCall($admin, 'POST', "/contract-renewal-batches/{$batch}/send");
    $this->assertMatchesOpenApi($sent, 'post', '/contract-renewal-batches/{id}/send', 200);
    $row = collect($sent->json('data.items'))->firstWhere('contract_id', $short);
    expect($sent->json('data.status'))->toBe('sent_to_signature')->and($row['new_contract_status'])->toBe('pending_signature')
        ->and($row['signature']['signed'])->toBe(0);
    $this->assertMatchesOpenApi(sigCall($admin, 'POST', "/contract-renewal-batches/{$batch}/send"), 'post', '/contract-renewal-batches/{id}/send', 409);

    // La persona contratada no tiene usuario: firma el representante de la empresa, desde su bandeja.
    $request = collect(sigCall($admin, 'GET', '/me/signature-requests')->json('data'))->firstWhere('my_turn', true);
    expect(sigSign($admin, $request['id'])->json('data.status'))->toBe('completed');

    $shown = sigCall($admin, 'GET', "/contract-renewal-batches/{$batch}");
    $this->assertMatchesOpenApi($shown, 'get', '/contract-renewal-batches/{id}', 200);
    $done = collect($shown->json('data.items'))->firstWhere('contract_id', $short);
    expect($done['new_contract_status'])->toBeIn(['signed', 'active'])->and($done['signature']['signed'])->toBe(1)
        ->and($shown->json('data.status'))->toBe('completed');
    $contract = sigCall($admin, 'GET', '/contracts/'.$done['new_contract_id'])->json('data');
    expect($contract['signature_origin'])->toBe('electronic');

    $this->assertMatchesOpenApi(sigCall($this->other, 'GET', "/contract-renewal-batches/{$batch}"), 'get', '/contract-renewal-batches/{id}', 404);
    $this->assertMatchesOpenApi(sigCall($this->employee, 'POST', '/contract-renewal-batches', ['new_end_date' => '2027-02-28', 'contract_ids' => [$short]]), 'post', '/contract-renewal-batches', 403);
    $this->assertMatchesOpenApi(sigCall($admin, 'GET', '/contract-renewal-batches'), 'get', '/contract-renewal-batches', 200);
});

test('CON-6 · DOC-6 · el acuerdo de teletrabajo se firma con el flujo común y queda vigente', function (): void {
    $employment = TenancyScope::runAs(SIG_TENANT, fn (): string => ContractFactory::employment());
    $id = sigCall($this->hr, 'POST', '/remote-work-agreements', [
        'employment_id' => $employment, 'modality' => 'partial', 'starts_on' => '2026-12-01', 'work_place' => 'Domicilio', 'schedule_description' => 'L-V 9 a 18',
        'disconnection_hours' => 14, 'supervision_mechanism' => 'Reunión diaria', 'equipment_provided_by' => 'employer',
        'expense_compensation' => ['amount' => '0.00', 'currency' => 'PEN'],
    ])->assertCreated()->json('data.id');
    sigCall($this->hr, 'POST', "/remote-work-agreements/{$id}/approve")->assertOk();
    // Sin emitir no se envía a firma.
    $documentId = fn () => TenancyScope::runAs(SIG_TENANT, fn () => DB::table('remote_work_agreements')->where('id', $id)->value('document_id'));
    sigCall($this->hr, 'POST', "/remote-work-agreements/{$id}/issue")->assertOk();
    $request = sigCall($this->hr, 'POST', '/signature-requests', ['document_id' => $documentId(), 'signers' => [['user_id' => $this->hr->id]], 'send' => true])->assertCreated();
    expect($request->json('data.signature_kind'))->toBe('certificate');
    expect(sigSign($this->hr, $request->json('data.id'))->json('data.status'))->toBe('completed');
    $agreement = sigCall($this->hr, 'GET', "/remote-work-agreements/{$id}")->json('data');
    expect($agreement['status'])->toBe('active')->and($agreement['signature_origin'])->toBe('electronic');
});
