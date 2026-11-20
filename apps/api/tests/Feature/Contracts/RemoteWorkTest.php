<?php

/**
 * CON-6 · Teletrabajo (Ley 31572): la solicitud nace con su plazo legal de respuesta en días hábiles (parámetro legal)
 * y avisa a RRHH antes de vencer; el acuerdo no se emite si falta su contenido mínimo (RN-CON-10) ni con una
 * desconexión menor a la legal; emitido genera su documento, se registra firmado y queda vigente hasta terminarse.
 */

use App\Modules\Contracts\Database\Factories\ContractFactory;
use App\Modules\Contracts\Domain\RemoteWorkStateMachine;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Shared\LegalParameters\LegalParameters;
use App\Shared\Permissions\ModuleGate;
use App\Shared\Permissions\PermissionResolver;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\IdentityFixtures;

uses(DatabaseTransactions::class);

const RWK_TENANT = 'aaaaaaaa-0c06-4000-8000-00000000000a';
const RWK_OTHER = 'bbbbbbbb-0c06-4000-8000-00000000000b';

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requiere PostgreSQL.');
    }
    Storage::fake('s3');
    TenancyScope::clear();
    app(LegalParameters::class)->sync('PE');
    foreach ([RWK_TENANT => 'rwk-org', RWK_OTHER => 'rwk-otra'] as $id => $slug) {
        DB::table('tenants')->insertOrIgnore(['id' => $id, 'slug' => $slug, 'name' => 'Grupo '.$slug, 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN']);
        DB::select('SELECT seed_tenant_defaults(?)', [$id]);
        TenancyScope::runAs($id, fn () => IdentityFixtures::enableAllModules());
    }
    $this->hr = rwkUser(RWK_TENANT, 'rrhh');
    $this->employee = rwkUser(RWK_TENANT, 'empleado');
    $this->other = rwkUser(RWK_OTHER, 'admin_empresa');
    $this->employment = TenancyScope::runAs(RWK_TENANT, fn (): string => ContractFactory::employment());
});

afterEach(fn () => TenancyScope::clear());

function rwkUser(string $tenant, string $role): User
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
function rwkCall(User $user, string $method, string $path, array $body = [], bool $multipart = false): TestResponse
{
    TenancyScope::clear();
    app('auth')->forgetGuards();
    app(PermissionResolver::class)->forget();
    app(ModuleGate::class)->forget();
    test()->flushHeaders();
    $t = test()->actingAs($user, 'ayni')->withHeaders(['X-Tenant-Id' => $user->tenant_id, 'Idempotency-Key' => (string) Str::uuid(), 'Accept' => 'application/json']);

    return $multipart ? $t->post('/api/v1'.$path, $body) : $t->json($method, '/api/v1'.$path, $body);
}

test('CON-6 · solicitud con plazo legal, aviso antes de vencer, contenido mínimo, emisión, firma y término', function (): void {
    // Lunes 2 de noviembre de 2026: diez días hábiles (sin sábados ni domingos) vencen el lunes 16.
    $created = rwkCall($this->hr, 'POST', '/remote-work-agreements', [
        'employment_id' => $this->employment, 'modality' => 'partial', 'starts_on' => '2026-12-01', 'requested_on' => '2026-11-02',
        'request_reason' => 'Cuidado de un familiar',
    ]);
    $this->assertMatchesOpenApi($created, 'post', '/remote-work-agreements', 201);
    $id = $created->json('data.id');
    expect($created->json('data.status'))->toBe('requested')
        ->and($created->json('data.response_due_on'))->toBe('2026-11-16')
        ->and($created->json('data.missing_content'))->toContain('schedule_description', 'disconnection_hours', 'expense_compensation');

    // Una sola solicitud en curso por persona.
    rwkCall($this->hr, 'POST', '/remote-work-agreements', ['employment_id' => $this->employment, 'modality' => 'total', 'starts_on' => '2026-12-01'])->assertStatus(409);

    // Aviso a quienes deciden dos días antes del plazo (parámetro), una sola vez.
    Artisan::call('remote-work:remind-responses', ['--date' => '2026-11-10']);
    expect(trim(Artisan::output()))->toBe('0');
    Artisan::call('remote-work:remind-responses', ['--date' => '2026-11-14']);
    expect(trim(Artisan::output()))->toBe('1');
    Artisan::call('remote-work:remind-responses', ['--date' => '2026-11-15']);
    expect(trim(Artisan::output()))->toBe('0')
        ->and(TenancyScope::runAs(RWK_TENANT, fn () => DB::table('notifications')->where('category', 'remote_work.response_due')->where('recipient_user_id', $this->hr->id)->count()))->toBe(1);

    $early = rwkCall($this->hr, 'POST', "/remote-work-agreements/{$id}/issue");
    $this->assertMatchesOpenApi($early, 'post', '/remote-work-agreements/{id}/issue', 409);
    $early->assertJsonPath('error.code', 'invalid_transition');

    $approved = rwkCall($this->hr, 'POST', "/remote-work-agreements/{$id}/approve");
    $this->assertMatchesOpenApi($approved, 'post', '/remote-work-agreements/{id}/approve', 200);
    expect($approved->json('data.responded_on'))->not->toBeNull();

    // RN-CON-10: sin el contenido mínimo no se emite.
    $incomplete = rwkCall($this->hr, 'POST', "/remote-work-agreements/{$id}/issue");
    $this->assertMatchesOpenApi($incomplete, 'post', '/remote-work-agreements/{id}/issue', 422);

    // La desconexión no puede ser menor a la legal (parámetro teletrabajo_desconexion_horas).
    $short = rwkCall($this->hr, 'PATCH', "/remote-work-agreements/{$id}", ['disconnection_hours' => 4]);
    $this->assertMatchesOpenApi($short, 'patch', '/remote-work-agreements/{id}', 422);

    $complete = rwkCall($this->hr, 'PATCH', "/remote-work-agreements/{$id}", [
        'work_place' => 'Domicilio del trabajador', 'schedule_description' => 'Lunes a viernes de 9:00 a 18:00', 'disconnection_hours' => 14,
        'supervision_mechanism' => 'Reunión diaria por videollamada', 'equipment_provided_by' => 'employer',
        'expense_compensation' => ['amount' => '0.00', 'currency' => 'PEN'],
    ]);
    $this->assertMatchesOpenApi($complete, 'patch', '/remote-work-agreements/{id}', 200);
    expect($complete->json('data.missing_content'))->toBe([]);

    $issued = rwkCall($this->hr, 'POST', "/remote-work-agreements/{$id}/issue");
    $issued->assertOk();
    expect($issued->json('data.status'))->toBe('pending_signature')->and($issued->json('data.has_document'))->toBeTrue();
    rwkCall($this->hr, 'GET', "/remote-work-agreements/{$id}/document")->assertOk();

    $signed = rwkCall($this->hr, 'POST', "/remote-work-agreements/{$id}/register-signature", [
        'file' => UploadedFile::fake()->create('acuerdo.pdf', 20, 'application/pdf'), 'signed_on' => '2026-11-20',
    ], true);
    $this->assertMatchesOpenApi($signed, 'post', '/remote-work-agreements/{id}/register-signature', 200);
    expect($signed->json('data.status'))->toBe('active');

    $ended = rwkCall($this->hr, 'POST', "/remote-work-agreements/{$id}/end", ['ends_on' => '2027-06-30', 'reason' => 'Vuelve a la modalidad presencial']);
    $this->assertMatchesOpenApi($ended, 'post', '/remote-work-agreements/{id}/end', 200);
    expect($ended->json('data.status'))->toBe('ended')
        ->and(TenancyScope::runAs(RWK_TENANT, fn () => DB::table('audit_events')->where('entity_id', $id)->where('action', 'remote_work.issue')->exists()))->toBeTrue();
});

test('CON-6 · el rechazo exige motivo; sin permiso, 403; otra organización, 404', function (): void {
    $id = rwkCall($this->hr, 'POST', '/remote-work-agreements', ['employment_id' => $this->employment, 'modality' => 'total', 'starts_on' => '2026-12-01'])->json('data.id');
    $this->assertMatchesOpenApi(rwkCall($this->hr, 'POST', "/remote-work-agreements/{$id}/reject"), 'post', '/remote-work-agreements/{id}/reject', 422);
    $rejected = rwkCall($this->hr, 'POST', "/remote-work-agreements/{$id}/reject", ['reason' => 'El puesto exige atención presencial']);
    expect($rejected->json('data.status'))->toBe('rejected');

    $denied = rwkCall($this->employee, 'POST', '/remote-work-agreements', ['employment_id' => $this->employment, 'modality' => 'total', 'starts_on' => '2026-12-01']);
    $this->assertMatchesOpenApi($denied, 'post', '/remote-work-agreements', 403);
    $this->assertMatchesOpenApi(rwkCall($this->other, 'GET', "/remote-work-agreements/{$id}"), 'get', '/remote-work-agreements/{id}', 404);
    $list = rwkCall($this->hr, 'GET', '/remote-work-agreements?filter[status]=rejected');
    $this->assertMatchesOpenApi($list, 'get', '/remote-work-agreements', 200);
    expect($list->json('meta.total'))->toBe(1);
});

test('CON-6 · matriz de transiciones del teletrabajo', function (): void {
    $machine = new RemoteWorkStateMachine;
    $allowed = ['requested' => ['approve', 'reject'], 'approved' => ['issue'], 'pending_signature' => ['issue', 'register-signature'], 'active' => ['end'], 'rejected' => [], 'ended' => []];
    foreach ($allowed as $state => $ok) {
        foreach (['approve', 'reject', 'issue', 'register-signature', 'end'] as $action) {
            expect($machine->can($state, $action))->toBe(in_array($action, $ok, true), "{$state} × {$action}");
        }
    }
});
