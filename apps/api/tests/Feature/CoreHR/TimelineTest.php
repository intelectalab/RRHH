<?php

/**
 * HIS-5 · Línea de tiempo laboral: une el ingreso, los movimientos (con el antes y el después de cada campo),
 * los cambios de sueldo, los contratos y el cese de una relación, más reciente primero. Cada tipo exige el
 * permiso de su recurso; los cambios de sueldo solo se ven con people.employments.salary.view y su lectura
 * queda en el registro de accesos (reglas 6 y 7).
 */

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Organization\Infrastructure\Models\JobProfile;
use App\Modules\Organization\Infrastructure\Models\LegalEntity;
use App\Modules\Organization\Infrastructure\Models\OrgUnit;
use App\Modules\Organization\Infrastructure\Models\Site;
use App\Shared\LegalParameters\LegalParameters;
use App\Shared\Permissions\ModuleGate;
use App\Shared\Permissions\PermissionResolver;
use App\Shared\Settings\ParameterResolver;
use App\Shared\Tenancy\TenancyScope;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\IdentityFixtures;

uses(DatabaseTransactions::class);

const TL_TENANT = 'aaaaaaaa-0a05-4000-8000-00000000000a';
const TL_OTHER = 'bbbbbbbb-0a05-4000-8000-00000000000b';

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requiere PostgreSQL.');
    }
    Storage::fake('s3');
    TenancyScope::clear();
    app(LegalParameters::class)->sync('PE');
    foreach ([TL_TENANT => 'tl-org', TL_OTHER => 'tl-otra'] as $id => $slug) {
        DB::table('tenants')->insertOrIgnore(['id' => $id, 'slug' => $slug, 'name' => 'Grupo '.$slug, 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN']);
        DB::select('SELECT seed_tenant_defaults(?)', [$id]);
        TenancyScope::runAs($id, fn () => IdentityFixtures::enableAllModules());
    }
    $this->admin = tlUser(TL_TENANT, 'admin_empresa');
    TenancyScope::runAs(TL_TENANT, function (): void {
        $entity = LegalEntity::factory()->create();
        DB::table('legal_entity_regimes')->where('legal_entity_id', $entity->id)->update(['valid_from' => '2020-01-01']);
        $this->entity = $entity->id;
        $this->unit = OrgUnit::factory()->create(['legal_entity_id' => $entity->id, 'name' => 'Operaciones'])->id;
        $this->unit2 = OrgUnit::factory()->create(['legal_entity_id' => $entity->id, 'name' => 'Logística'])->id;
        $this->site = Site::factory()->create(['legal_entity_id' => $entity->id])->id;
        $this->profile = JobProfile::factory()->create()->id;
        app(ParameterResolver::class)->set('compensacion.cambio_requiere_aprobacion', false, 'organization', null, null, 'Pruebas');
        app(ParameterResolver::class)->set('movimientos.tipos_con_aprobacion', [], 'organization', null, null, 'Pruebas');
    });
    $types = collect(tlCall($this->admin, 'get', '/employment-options')->assertOk()->json('data.contract_types'));
    $hire = CarbonImmutable::today()->subMonths(6)->toDateString();
    $this->hireDate = $hire;
    $this->emp = (string) tlCall($this->admin, 'post', '/employments', [
        'person' => ['first_names' => 'Elena', 'paternal_surname' => 'Mamani', 'document_type' => 'dni', 'document_number' => (string) random_int(10000000, 89999999)],
        'legal_entity_id' => $this->entity, 'hire_date' => $hire, 'job_profile_id' => $this->profile, 'org_unit_id' => $this->unit, 'site_id' => $this->site,
        'contract_type_id' => $types->firstWhere('code', 'indeterminado')['id'], 'base_salary' => ['amount' => '3000.00', 'currency' => 'PEN'],
    ])->assertCreated()->json('data.id');
});

afterEach(fn () => TenancyScope::clear());

function tlUser(string $tenant, string $role): User
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
function tlCall(User $user, string $method, string $path, array $body = [], bool $multipart = false): TestResponse
{
    TenancyScope::clear();
    app('auth')->forgetGuards();
    app(PermissionResolver::class)->forget();
    app(ModuleGate::class)->forget();
    test()->flushHeaders();
    $test = test()->actingAs($user, 'ayni')->withHeaders(['X-Tenant-Id' => $user->tenant_id, 'Idempotency-Key' => (string) Str::uuid(), 'Accept' => 'application/json']);

    return $multipart ? $test->post('/api/v1'.$path, $body) : $test->json($method, '/api/v1'.$path, $body);
}

/** Traslado, aumento y contrato firmado sobre la relación de la prueba. */
function tlHistory(): void
{
    $t = test();
    $today = CarbonImmutable::today();
    tlCall($t->admin, 'post', '/employments/'.$t->emp.'/personnel-actions', [
        'effective_on' => $today->subMonths(2)->toDateString(), 'reason' => 'Refuerzo de logística', 'changes' => ['org_unit_id' => $t->unit2],
    ])->assertCreated();
    tlCall($t->admin, 'post', '/employments/'.$t->emp.'/compensation-changes', [
        'base_salary' => ['amount' => '3500.00', 'currency' => 'PEN'], 'effective_on' => $today->subMonth()->toDateString(), 'reason' => 'merit',
    ])->assertCreated();
    $contract = (string) tlCall($t->admin, 'get', '/employments/'.$t->emp.'/contracts')->assertOk()->json('data.0.id');
    tlCall($t->admin, 'post', '/contracts/'.$contract.'/register-signature', [
        'file' => UploadedFile::fake()->createWithContent('firmado.pdf', "%PDF-1.4\n% firmado\n"), 'signed_on' => $t->hireDate,
    ], true)->assertOk();
}

test('HIS-5 · la línea de tiempo muestra ingreso, movimiento con su antes y después, sueldo y contrato, más reciente primero', function (): void {
    tlHistory();
    $res = tlCall($this->admin, 'get', '/employments/'.$this->emp.'/timeline')->assertOk();
    $this->assertMatchesOpenApi($res, 'get', '/employments/{id}/timeline', 200);
    $events = collect($res->json('data'));
    expect($res->json('meta.types'))->toBe(['hire', 'movement', 'compensation', 'contract', 'status', 'termination'])
        ->and($events->pluck('type')->unique()->values()->all())->toContain('hire', 'movement', 'compensation', 'contract');

    // Orden: lo más reciente primero; el ingreso, al final.
    $dates = $events->pluck('date')->all();
    $sorted = $dates;
    rsort($sorted);
    expect($dates)->toBe($sorted)->and($events->last()['type'])->toBe('hire');

    $move = $events->firstWhere('type', 'movement');
    $change = collect($move['changes'])->firstWhere('field', 'org_unit_id');
    expect($move['kind'])->toBe('transfer')->and($move['reason'])->toBe('Refuerzo de logística')
        ->and($change['before']['label'])->toBe('Operaciones')->and($change['after']['label'])->toBe('Logística');

    $raise = $events->where('type', 'compensation')->firstWhere('kind', 'merit');
    expect($raise['salary']['before']['amount'])->toBe('3000.00')->and($raise['salary']['after']['amount'])->toBe('3500.00');
    // RN-07 · leer el sueldo deja una fila en el registro de accesos.
    TenancyScope::runAs(TL_TENANT, function (): void {
        expect(DB::table('data_access_logs')->where('entity_type', 'TimelineEvent')->where('data_category', 'salary')->exists())->toBeTrue();
    });

    expect($events->firstWhere('kind', 'contract_start')['args']['contract_type_name'] ?? null)->not->toBeNull();

    $only = tlCall($this->admin, 'get', '/employments/'.$this->emp.'/timeline?types=movement,hire')->assertOk();
    expect(collect($only->json('data'))->pluck('type')->unique()->sort()->values()->all())->toBe(['hire', 'movement']);
    $bad = tlCall($this->admin, 'get', '/employments/'.$this->emp.'/timeline?types=sueldos')->assertStatus(400);
    $this->assertMatchesOpenApi($bad, 'get', '/employments/{id}/timeline', 400);
});

test('HIS-5 · los cambios de sueldo solo se ven con el permiso de campo; cada tipo exige el permiso de su recurso', function (): void {
    tlHistory();
    // Un reclutador ve la ficha, pero no sueldos, movimientos ni contratos.
    $recruiter = tlUser(TL_TENANT, 'recruiter');
    $res = tlCall($recruiter, 'get', '/employments/'.$this->emp.'/timeline')->assertOk();
    $types = collect($res->json('data'))->pluck('type')->unique()->values()->all();
    expect($res->json('meta.types'))->toBe(['hire', 'status', 'termination'])
        ->and($types)->not->toContain('compensation', 'movement', 'contract')
        ->and(collect($res->json('data'))->pluck('salary')->filter()->all())->toBe([]);
    // Pedir un tipo sin permiso no lo trae.
    expect(tlCall($recruiter, 'get', '/employments/'.$this->emp.'/timeline?types=compensation')->assertOk()->json('data'))->toBe([]);
});

test('HIS-5 · el cese registrado aparece en la línea de tiempo como programado hasta su fecha', function (): void {
    $reasons = collect(tlCall($this->admin, 'get', '/termination-reasons')->assertOk()->json('data'));
    $date = CarbonImmutable::today()->addDays(20)->toDateString();
    tlCall($this->admin, 'post', '/employments/'.$this->emp.'/terminate', [
        'termination_date' => $date, 'last_working_day' => $date, 'termination_reason_id' => $reasons->first()['id'], 'initiated_by' => 'employee',
    ])->assertOk();
    $events = collect(tlCall($this->admin, 'get', '/employments/'.$this->emp.'/timeline')->assertOk()->json('data'));
    $end = $events->firstWhere('type', 'termination');
    expect($end)->not->toBeNull()->and($end['date'])->toBe($date)->and($end['status'])->toBe('scheduled')
        ->and($events->first()['type'])->toBe('termination');
});

test('HIS-5 · sin permiso 403 y otra organización no ve la línea de tiempo', function (): void {
    $other = tlUser(TL_OTHER, 'admin_empresa');
    $res = tlCall($other, 'get', '/employments/'.$this->emp.'/timeline')->assertStatus(404);
    $this->assertMatchesOpenApi($res, 'get', '/employments/{id}/timeline', 404);
    $nobody = tlUser(TL_TENANT, 'investigador_denuncias');
    $forbidden = tlCall($nobody, 'get', '/employments/'.$this->emp.'/timeline')->assertStatus(403);
    $this->assertMatchesOpenApi($forbidden, 'get', '/employments/{id}/timeline', 403);
});
