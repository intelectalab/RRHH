<?php

/**
 * CPT-6 · Importación de conceptos propios y de incidencias del periodo con el motor de IMP-1.
 * CPT-7 · Códigos de la Tabla 22 cotejados y naturaleza en la proyección de quinta.
 */

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Imports\Application\ImportRunner;
use App\Shared\Permissions\ModuleGate;
use App\Shared\Permissions\PermissionResolver;
use App\Shared\Security\BlindIndex;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\IdentityFixtures;
use Tests\Support\PayrollFixtures;

uses(DatabaseTransactions::class);

const CPT6_TENANT = 'aaaaaaaa-c6c6-4000-8000-00000000000a';
const CPT6_OTHER = 'bbbbbbbb-c6c6-4000-8000-00000000000b';

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requiere PostgreSQL.');
    }
    Storage::fake('s3');
    config(['files.disk' => 's3']);
    TenancyScope::clear();
    foreach ([CPT6_TENANT => 'cpt6-org', CPT6_OTHER => 'cpt6-otra'] as $id => $slug) {
        DB::table('tenants')->insertOrIgnore(['id' => $id, 'slug' => $slug, 'name' => 'Org '.$slug, 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN']);
        DB::select('SELECT seed_tenant_defaults(?)', [$id]);
        TenancyScope::runAs($id, fn () => IdentityFixtures::enableAllModules());
    }
    $this->admin = cpt6User(CPT6_TENANT, 'admin_empresa');
    $this->employee = cpt6User(CPT6_TENANT, 'empleado');
    [$this->ruc, $this->entity, $this->a] = TenancyScope::runAs(CPT6_TENANT, function (): array {
        $entity = PayrollFixtures::entity();
        $a = PayrollFixtures::employment($entity, '2025-01-01', [['amount' => '3000.00', 'valid_from' => '2025-01-01']]);
        $person = DB::table('employments')->where('id', $a)->value('person_id');
        DB::table('person_documents')->insert(['tenant_id' => CPT6_TENANT, 'person_id' => $person, 'doc_type' => 'dni', 'issuing_country' => 'PE',
            'number_enc' => Crypt::encryptString('45678912'), 'number_hash' => BlindIndex::for('national_id', 'dni:45678912'), 'is_primary' => true]);
        PayrollFixtures::concept(['code' => 'BONO', 'kind' => 'earning']);

        return [(string) DB::table('legal_entities')->where('id', $entity)->value('tax_id'), $entity, $a];
    });
});

afterEach(fn () => TenancyScope::clear());

function cpt6User(string $tenant, string $role): User
{
    return TenancyScope::runAs($tenant, function () use ($role): User {
        $u = User::factory()->create();
        $u->roleAssignments()->create(['role_id' => Role::query()->where('system_role_code', $role)->value('id')]);

        return $u;
    });
}

/** @param array<string, mixed> $body */
function cpt6Call(User $user, string $method, string $path, array $body = []): TestResponse
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

/**
 * @param  list<list<string>>  $lines
 * @return array{job: array<string, mixed>, validated: array<string, mixed>}
 */
function cpt6Import(User $user, string $entity, array $lines, bool $execute = true): array
{
    $csv = implode("\n", array_map(fn ($cells) => implode(';', $cells), $lines))."\n";
    $up = cpt6Call($user, 'POST', '/imports', ['entity' => $entity, 'file_name' => "{$entity}.csv", 'content_base64' => base64_encode($csv)])->assertCreated();
    $id = (string) $up->json('data.id');
    cpt6Call($user, 'POST', "/imports/{$id}/map", ['mapping' => $up->json('data.suggested_mapping')])->assertOk();
    $validated = (array) cpt6Call($user, 'POST', "/imports/{$id}/validate")->assertOk()->json('data');
    if ($execute) {
        cpt6Call($user, 'POST', "/imports/{$id}/execute", ['confirm' => true])->assertOk();
        TenancyScope::runAs((string) $user->tenant_id, fn () => app(ImportRunner::class)->run($id));
    }

    return ['job' => (array) cpt6Call($user, 'GET', "/imports/{$id}")->json('data'), 'validated' => $validated];
}

/** @return list<array<string, mixed>> */
function cpt6Rows(User $user, string $jobId): array
{
    return (array) cpt6Call($user, 'GET', "/imports/{$jobId}/rows")->json('data');
}

test('CPT-6 · importa incidencias del periodo: valida trabajador, concepto y periodo, y marca su origen', function (): void {
    $g = cpt6Call($this->admin, 'POST', '/payroll-groups', ['code' => 'MENS', 'name' => 'Mensual', 'legal_entity_id' => $this->entity, 'payment_day' => 28, 'cutoff_day' => 25])->assertCreated();
    $p = (string) cpt6Call($this->admin, 'POST', '/payroll-periods', ['payroll_group_id' => $g->json('data.id'), 'period_year' => 2026, 'period_number' => 10])->assertCreated()->json('data.id');

    $r = cpt6Import($this->admin, 'payroll_incidents', [
        ['Grupo de planilla', 'Periodo', 'Número de documento', 'Código del concepto', 'Monto'],
        ['MENS', '202610', '45678912', 'BONO', '250.00'],
        ['MENS', '202610', '99999999', 'BONO', '10'],        // trabajador inexistente
        ['MENS', '202610', '45678912', 'NOEXISTE', '10'],     // concepto inexistente
        ['MENS', '202612', '45678912', 'BONO', '10'],         // periodo inexistente
    ]);
    $rows = collect(cpt6Rows($this->admin, (string) $r['job']['id']))->keyBy('row_number');
    expect($r['job']['status'])->toBe('completed')
        ->and(collect($rows->get(3)['errors'] ?? [])->pluck('column')->all())->toContain('document_number')
        ->and(collect($rows->get(4)['errors'] ?? [])->pluck('column')->all())->toContain('concept_code')
        ->and(collect($rows->get(5)['errors'] ?? [])->pluck('column')->all())->toContain('period');

    $incident = TenancyScope::runAs(CPT6_TENANT, fn () => DB::table('payroll_incidents')->where('payroll_period_id', $p)->whereNull('deleted_at')->first());
    expect($incident)->not->toBeNull()
        ->and($incident->source)->toBe('import')
        ->and($incident->source_type)->toBe('import_job')
        ->and($incident->source_id)->toBe($r['job']['id'])
        ->and((string) $incident->amount)->toBe('250.00');
    $list = cpt6Call($this->admin, 'GET', "/payroll-incidents?filter[payroll_period_id]={$p}")->assertOk();
    expect(collect($list->json('data'))->firstWhere('id', $incident->id)['source'] ?? null)->toBe('import');

    // Deshacer: la incidencia va a la papelera mientras el periodo sigue abierto.
    cpt6Call($this->admin, 'POST', "/imports/{$r['job']['id']}/revert")->assertOk();
    expect(TenancyScope::runAs(CPT6_TENANT, fn () => DB::table('payroll_incidents')->where('id', $incident->id)->value('deleted_at')))->not->toBeNull();

    // Con el periodo enviado a aprobación ya no recibe incidencias.
    TenancyScope::runAs(CPT6_TENANT, fn () => DB::table('payroll_periods')->where('id', $p)->update(['status' => 'in_review']));
    $locked = cpt6Import($this->admin, 'payroll_incidents', [['Grupo de planilla', 'Periodo', 'Número de documento', 'Código del concepto', 'Monto'], ['MENS', '2026-10', '45678912', 'BONO', '5']], false);
    expect($locked['validated']['valid_rows'] ?? $locked['validated']['summary']['valid'] ?? 0)->toBe(0);
});

test('CPT-6 · importa conceptos propios en borrador; código, naturaleza, Tabla 22 y fórmula se validan', function (): void {
    $r = cpt6Import($this->admin, 'concepts', [
        ['Código', 'Nombre', 'Naturaleza', 'Código Tabla 22', 'Fórmula'],
        ['bono_campana', 'Bono de campaña', 'earning', '0902', ''],
        ['BONO', 'Repetido', 'earning', '', ''],                         // formato y código ya existente
        ['otro_bono', 'Otro', 'ingreso', '', ''],                        // naturaleza inválida
        ['bono_falso', 'Con código falso', 'earning', '9999', ''],        // fuera de la Tabla 22
        ['bono_roto', 'Fórmula rota', 'earning', '', 'sueldo * ('],      // fórmula inválida
    ]);
    $rows = collect(cpt6Rows($this->admin, (string) $r['job']['id']))->keyBy('row_number');
    expect(collect($rows->get(3)['errors'] ?? [])->pluck('column')->all())->toContain('code')
        ->and(collect($rows->get(4)['errors'] ?? [])->pluck('column')->all())->toContain('kind')
        ->and(collect($rows->get(5)['errors'] ?? [])->pluck('column')->all())->toContain('statutory_code')
        ->and(collect($rows->get(6)['errors'] ?? [])->pluck('column')->all())->toContain('formula');

    $c = TenancyScope::runAs(CPT6_TENANT, fn () => DB::table('payroll_concepts')->where('code', 'bono_campana')->first());
    expect($c)->not->toBeNull()->and($c->status)->toBe('draft')->and($c->statutory_code)->toBe('0902');

    cpt6Call($this->admin, 'POST', "/imports/{$r['job']['id']}/revert")->assertOk();
    expect(TenancyScope::runAs(CPT6_TENANT, fn () => DB::table('payroll_concepts')->where('code', 'bono_campana')->value('deleted_at')))->not->toBeNull();

    // Sin permiso de crear conceptos no se puede importarlos.
    $csv = base64_encode("Código;Nombre;Naturaleza\nx_y;X;earning\n");
    cpt6Call($this->employee, 'POST', '/imports', ['entity' => 'concepts', 'file_name' => 'c.csv', 'content_base64' => $csv])->assertForbidden();
});

test('CPT-7 · la plantilla solo usa códigos de la Tabla 22 vigente y todo lo que afecta a quinta declara su naturaleza', function (): void {
    $table = json_decode((string) file_get_contents(base_path('packages/country-pe/data/tabla22_pe.json')), true);
    $codes = array_column($table['codigos'], 'codigo');
    $pack = json_decode((string) file_get_contents(base_path('packages/country-pe/data/conceptos_pe.json')), true);
    foreach ($pack['conceptos'] as $c) {
        if ($c['codigo_plame'] !== null) {
            expect($codes)->toContain($c['codigo_plame']);
        }
        if ($c['afectaciones']['quinta']) {
            expect($c['proyeccion_quinta'])->toBeIn(['ordinary', 'gratification', 'extraordinary']);
        }
    }
    expect($codes)->toContain('0103'); // comisiones o destajo
    $comisiones = collect($pack['conceptos'])->firstWhere('codigo', 'comisiones');
    expect($comisiones['codigo_plame'])->toBe('0103');

    // La tabla cargada en la base valida los códigos a la fecha.
    expect((bool) DB::selectOne("SELECT pe_param_ok(22::smallint, '0103', DATE '2026-11-30') AS ok")->ok)->toBeTrue()
        ->and((bool) DB::selectOne("SELECT pe_param_ok(22::smallint, '9999', DATE '2026-11-30') AS ok")->ok)->toBeFalse();
});

test('CPT-7 · un código fuera de la Tabla 22 se rechaza y un concepto que afecta a quinta sin naturaleza no se publica', function (): void {
    $bad = cpt6Call($this->admin, 'POST', '/payroll-concepts', ['code' => 'bono_x', 'name' => 'Bono X', 'kind' => 'earning', 'statutory_code' => '9999'])->assertStatus(422);
    $this->assertMatchesOpenApi($bad, 'post', '/payroll-concepts', 422);
    expect(collect($bad->json('error.details'))->pluck('field')->all())->toContain('statutory_code');

    $ok = cpt6Call($this->admin, 'POST', '/payroll-concepts', ['code' => 'bono_y', 'name' => 'Bono Y', 'kind' => 'earning', 'statutory_code' => '0902',
        'affectations' => [['base_code' => 'quinta', 'affects' => true]]])->assertCreated();
    $id = (string) $ok->json('data.id');
    $check = cpt6Call($this->admin, 'POST', "/payroll-concepts/{$id}/validate")->assertOk();
    expect(collect($check->json('data.errors'))->pluck('code')->all())->toContain('projection_required');
    expect(cpt6Call($this->admin, 'POST', "/payroll-concepts/{$id}/publish")->status())->toBeGreaterThanOrEqual(400);

    cpt6Call($this->admin, 'PATCH', "/payroll-concepts/{$id}", ['statutory_code' => '0000'])->assertStatus(422);
});
