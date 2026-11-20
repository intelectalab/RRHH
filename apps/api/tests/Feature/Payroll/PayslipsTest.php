<?php

/**
 * BOL-1 · Boletas desde la corrida del periodo cerrado, PDF al legajo, idempotentes y con versión al corregir.
 * BOL-2 · Publicación en el portal, primera vista, descarga, acuse con evidencia y entrega en físico.
 * BOL-3 · Historial propio y de Payroll con alcance, descarga masiva por enlace temporal.
 * BOL-4 · Conservación: la boleta no se borra ni cambia su contenido; el parámetro legal por validar no vence.
 * BOL-5 · Explicación del cambio de neto frente a la boleta anterior desde la traza.
 */

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Payroll\Application\PayrollRuns;
use App\Shared\Permissions\ModuleGate;
use App\Shared\Permissions\PermissionResolver;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\IdentityFixtures;
use Tests\Support\PayrollFixtures;

uses(DatabaseTransactions::class);

const BOL_TENANT = 'aaaaaaaa-0b01-4000-8000-00000000000a';
const BOL_OTHER = 'bbbbbbbb-0b01-4000-8000-00000000000b';

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requiere PostgreSQL.');
    }
    TenancyScope::clear();
    Storage::fake('s3');
    foreach ([BOL_TENANT => 'bol-org', BOL_OTHER => 'bol-otra'] as $id => $slug) {
        DB::table('tenants')->insertOrIgnore(['id' => $id, 'slug' => $slug, 'name' => 'Grupo '.$slug, 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN']);
        DB::select('SELECT seed_tenant_defaults(?)', [$id]);
        TenancyScope::runAs($id, fn () => IdentityFixtures::enableAllModules());
    }
    $this->payroll = bolUser(BOL_TENANT, 'payroll');
    $this->admin = bolUser(BOL_TENANT, 'admin_empresa');
    $this->employee = bolUser(BOL_TENANT, 'empleado');
    $this->other = bolUser(BOL_OTHER, 'admin_empresa');
    $this->noSalary = IdentityFixtures::userWithPermissions(BOL_TENANT, ['payroll.payslips.view']);
    [$this->entity, $this->a, $this->b] = TenancyScope::runAs(BOL_TENANT, function (): array {
        $entity = PayrollFixtures::entity();
        $a = PayrollFixtures::employment($entity, '2025-01-01', [['amount' => '6000.00', 'valid_from' => '2025-01-01']]);
        $b = PayrollFixtures::employment($entity, '2025-01-01', [['amount' => '2000.00', 'valid_from' => '2025-01-01', 'valid_to' => '2026-01-31'], ['amount' => '3000.00', 'valid_from' => '2026-02-01']]);
        PayrollFixtures::concept(['code' => 'BASICO', 'kind' => 'earning', 'formula' => 'sueldo_tramos', 'sort_order' => 1, 'affects' => []]);
        PayrollFixtures::parameter('dias_base_mes', '30');
        // El empleado del portal es el titular de B.
        DB::table('users')->where('id', test()->employee->id)->update(['person_id' => DB::table('employments')->where('id', $b)->value('person_id')]);

        return [$entity, $a, $b];
    });
});

afterEach(fn () => TenancyScope::clear());

function bolUser(string $tenant, string $role): User
{
    return TenancyScope::runAs($tenant, function () use ($role): User {
        $u = User::factory()->create();
        $u->roleAssignments()->create(['role_id' => Role::query()->where('system_role_code', $role)->value('id')]);

        return $u;
    });
}

/** @param array<string, mixed> $body */
function bolCall(User $user, string $method, string $path, array $body = []): TestResponse
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

/** Abre, calcula, envía, aprueba y cierra el mes; devuelve el periodo. */
function bolClosedPeriod(object $t, int $month): string
{
    $group = TenancyScope::runAs(BOL_TENANT, fn () => DB::table('payroll_groups')->where('code', 'MENS')->value('id'));
    if ($group === null) {
        $group = (string) bolCall($t->admin, 'POST', '/payroll-groups', ['code' => 'MENS', 'name' => 'Mensual', 'legal_entity_id' => $t->entity, 'payment_day' => 28, 'cutoff_day' => 25])
            ->assertCreated()->json('data.id');
    }
    $period = (string) bolCall($t->admin, 'POST', '/payroll-periods', ['payroll_group_id' => $group, 'period_year' => 2026, 'period_number' => $month])->assertCreated()->json('data.id');
    $run = (string) bolCall($t->payroll, 'POST', "/payroll-periods/{$period}/calculate")->assertOk()->json('data.last_run.id');
    TenancyScope::runAs(BOL_TENANT, fn () => app(PayrollRuns::class)->process($run));
    bolCall($t->payroll, 'POST', "/payroll-periods/{$period}/submit", ['confirm_anomalies' => true])->assertOk();
    bolCall($t->admin, 'POST', "/payroll-periods/{$period}/approve")->assertOk();
    bolCall($t->payroll, 'POST', "/payroll-periods/{$period}/close")->assertOk();

    return $period;
}

/** @return array<string, string> payslip id por relación (vigentes) */
function bolPayslips(string $period): array
{
    return TenancyScope::runAs(BOL_TENANT, fn () => DB::table('payslips')->where('payroll_period_id', $period)->where('status', '<>', 'voided')
        ->pluck('id', 'employment_id')->map(fn ($v) => (string) $v)->all());
}

test('BOL-1 · BOL-2 · BOL-4 · genera al cerrar, PDF al legajo, publica, constancia de vista, descarga y acuse; contenido inmutable', function (): void {
    $jan = bolClosedPeriod($this, 1);
    $gen = bolCall($this->payroll, 'POST', "/payroll-periods/{$jan}/payslips")->assertOk();
    $this->assertMatchesOpenApi($gen, 'post', '/payroll-periods/{id}/payslips', 200);
    $ids = bolPayslips($jan);
    expect($ids)->toHaveCount(2);
    // Idempotente: generar otra vez no crea ni anula nada.
    $again = bolCall($this->payroll, 'POST', "/payroll-periods/{$jan}/payslips")->assertOk();
    expect($again->json('data.generated'))->toBe(0)->and($again->json('data.unchanged'))->toBe(2)->and(bolPayslips($jan))->toBe($ids);

    $mine = $ids[$this->b];
    TenancyScope::runAs(BOL_TENANT, function () use ($mine): void {
        $p = DB::table('payslips')->where('id', $mine)->first();
        expect($p->status)->toBe('published')->and($p->net_amount)->toBe('2000.00')->and($p->document_id)->not->toBeNull()->and($p->file_id)->not->toBeNull()
            ->and(DB::table('documents')->where('id', $p->document_id)->value('subject_type'))->toBe('payslip')
            ->and((bool) DB::table('documents')->where('id', $p->document_id)->value('visible_to_employee'))->toBeTrue()
            ->and(DB::table('payslip_deliveries')->where('payslip_id', $mine)->value('status'))->toBe('published')
            ->and(DB::table('notifications')->where('category', 'payslips.published')->where('subject_id', $mine)->exists())->toBeTrue();
        // BOL-4 · RN-BOL-04 · El contenido de una boleta emitida no cambia y no se borra.
        expect(fn () => DB::transaction(fn () => DB::table('payslips')->where('id', $mine)->update(['net_amount' => '1.00'])))->toThrow(Exception::class);
        expect(fn () => DB::transaction(fn () => DB::table('payslips')->where('id', $mine)->delete()))->toThrow(Exception::class);
    });

    // Portal: historial propio, detalle (primera vista) y descarga como constancia.
    $list = bolCall($this->employee, 'GET', '/me/payslips')->assertOk();
    $this->assertMatchesOpenApi($list, 'get', '/me/payslips', 200);
    expect($list->json('data'))->toHaveCount(1)->and($list->json('data.0.net.amount'))->toBe('2000.00');
    $show = bolCall($this->employee, 'GET', "/me/payslips/{$mine}")->assertOk();
    $this->assertMatchesOpenApi($show, 'get', '/me/payslips/{id}', 200);
    expect($show->json('data.status'))->toBe('viewed')->and($show->json('data.lines.0.concept_code'))->toBe('BASICO')->and($show->json('data.retention_until'))->toBeNull();
    $file = bolCall($this->employee, 'GET', "/me/payslips/{$mine}/file")->assertOk();
    $this->assertMatchesOpenApi($file, 'get', '/me/payslips/{id}/file', 200);
    expect($file->headers->get('Content-Type'))->toContain('application/pdf');
    // Solo el titular: la boleta de A responde 404.
    $this->assertMatchesOpenApi(bolCall($this->employee, 'GET', "/me/payslips/{$ids[$this->a]}")->assertNotFound(), 'get', '/me/payslips/{id}', 404);

    $ack = bolCall($this->employee, 'POST', "/me/payslips/{$mine}/acknowledge")->assertOk();
    $this->assertMatchesOpenApi($ack, 'post', '/me/payslips/{id}/acknowledge', 200);
    expect($ack->json('data.status'))->toBe('acknowledged')->and($ack->json('data.acknowledged_at'))->toBeString();
    $this->assertMatchesOpenApi(bolCall($this->employee, 'POST', "/me/payslips/{$mine}/acknowledge")->assertStatus(409), 'post', '/me/payslips/{id}/acknowledge', 409);
    TenancyScope::runAs(BOL_TENANT, function () use ($mine): void {
        $d = DB::table('payslip_deliveries')->where('payslip_id', $mine)->first();
        expect($d->first_viewed_at)->not->toBeNull()->and($d->downloaded_at)->not->toBeNull()->and($d->acknowledged_ip)->not->toBeNull()->and($d->acknowledged_user_agent)->not->toBeNull();
    });

    // Constancias y resumen para Payroll.
    $deliveries = bolCall($this->payroll, 'GET', "/payslips/{$mine}/deliveries")->assertOk();
    $this->assertMatchesOpenApi($deliveries, 'get', '/payslips/{id}/deliveries', 200);
    $summary = bolCall($this->payroll, 'GET', "/payroll-periods/{$jan}/payslip-summary")->assertOk();
    $this->assertMatchesOpenApi($summary, 'get', '/payroll-periods/{id}/payslip-summary', 200);
    expect($summary->json('data.acknowledged'))->toBe(1)->and($summary->json('data.published'))->toBe(1)->and($summary->json('data.total'))->toBe(2);
});

test('BOL-2 · BOL-3 · RN-BOL-06 · historial con permisos por campo, PDF con registro de accesos, entrega en físico, anulación con nueva versión y descarga masiva', function (): void {
    $jan = bolClosedPeriod($this, 1);
    bolCall($this->payroll, 'POST', "/payroll-periods/{$jan}/payslips", ['publish' => false])->assertOk();
    $ids = bolPayslips($jan);
    $a = $ids[$this->a];

    $list = bolCall($this->payroll, 'GET', "/payslips?filter[payroll_period_id]={$jan}")->assertOk();
    $this->assertMatchesOpenApi($list, 'get', '/payslips', 200);
    expect($list->json('data'))->toHaveCount(2)->and($list->json('data.0.net'))->not->toBeNull();
    // Sin payroll.results.view: la boleta se ve sin montos ni líneas y el PDF se rechaza.
    $plain = bolCall($this->noSalary, 'GET', "/payslips/{$a}")->assertOk();
    $this->assertMatchesOpenApi($plain, 'get', '/payslips/{id}', 200);
    expect($plain->json('data'))->not->toHaveKey('net')->not->toHaveKey('lines')->and($plain->json('data.status'))->toBe('generated');
    $this->assertMatchesOpenApi(bolCall($this->noSalary, 'GET', "/payslips/{$a}/file")->assertForbidden(), 'get', '/payslips/{id}/file', 403);
    $this->assertMatchesOpenApi(bolCall($this->noSalary, 'POST', "/payslips/{$a}/publish")->assertForbidden(), 'post', '/payslips/{id}/publish', 403);
    // Una boleta aún no publicada no aparece en el portal.
    expect(bolCall($this->employee, 'GET', '/me/payslips')->assertOk()->json('data'))->toHaveCount(0);

    $pdf = bolCall($this->payroll, 'GET', "/payslips/{$a}/file")->assertOk();
    $this->assertMatchesOpenApi($pdf, 'get', '/payslips/{id}/file', 200);
    TenancyScope::runAs(BOL_TENANT, fn () => expect(DB::table('data_access_logs')->where('entity_id', $a)->where('access_kind', 'download')->where('user_id', $this->payroll->id)->exists())->toBeTrue());

    $pub = bolCall($this->payroll, 'POST', "/payslips/{$a}/publish")->assertOk();
    $this->assertMatchesOpenApi($pub, 'post', '/payslips/{id}/publish', 200);
    expect($pub->json('data.status'))->toBe('published');
    $this->assertMatchesOpenApi(bolCall($this->payroll, 'POST', "/payslips/{$a}/publish")->assertStatus(409), 'post', '/payslips/{id}/publish', 409);
    $period = bolCall($this->payroll, 'POST', "/payroll-periods/{$jan}/payslips/publish")->assertOk();
    $this->assertMatchesOpenApi($period, 'post', '/payroll-periods/{id}/payslips/publish', 200);
    expect($period->json('data.published'))->toBe(1);

    // RN-BOL-07 · Entrega en físico con cargo: vale como acuse.
    $printed = bolCall($this->payroll, 'POST', "/payslips/{$ids[$this->b]}/deliver-printed", ['delivered_on' => now()->toDateString(), 'note' => 'Sin acceso al portal'])->assertOk();
    $this->assertMatchesOpenApi($printed, 'post', '/payslips/{id}/deliver-printed', 200);
    expect($printed->json('data.status'))->toBe('acknowledged');
    $this->assertMatchesOpenApi(bolCall($this->payroll, 'POST', "/payslips/{$ids[$this->b]}/deliver-printed", ['delivered_on' => '2999-01-01'])->assertStatus(422), 'post', '/payslips/{id}/deliver-printed', 422);

    // RN-BOL-04 · Anular exige motivo y permiso; regenerar emite otra versión que referencia a la anulada.
    $this->assertMatchesOpenApi(bolCall($this->payroll, 'POST', "/payslips/{$a}/void", ['reason' => 'x'])->assertForbidden(), 'post', '/payslips/{id}/void', 403);
    $this->assertMatchesOpenApi(bolCall($this->admin, 'POST', "/payslips/{$a}/void")->assertStatus(422), 'post', '/payslips/{id}/void', 422);
    $void = bolCall($this->admin, 'POST', "/payslips/{$a}/void", ['reason' => 'Concepto mal configurado'])->assertOk();
    $this->assertMatchesOpenApi($void, 'post', '/payslips/{id}/void', 200);
    expect($void->json('data.status'))->toBe('voided')->and($void->json('data.void_reason'))->toBe('Concepto mal configurado');
    bolCall($this->payroll, 'POST', "/payroll-periods/{$jan}/payslips", ['publish' => false])->assertOk();
    $new = bolPayslips($jan)[$this->a];
    $v2 = bolCall($this->payroll, 'GET', "/payslips/{$new}")->assertOk();
    expect($v2->json('data.version'))->toBe(2)->and($v2->json('data.kind'))->toBe('rectification')->and($v2->json('data.rectifies_payslip_id'))->toBe($a);

    // RN-BOL-08 · Descarga masiva por enlace temporal, registrada.
    $bulk = bolCall($this->payroll, 'POST', '/payslips/bulk-download', ['payroll_period_id' => $jan])->assertOk();
    $this->assertMatchesOpenApi($bulk, 'post', '/payslips/bulk-download', 200);
    expect($bulk->json('data.count'))->toBe(2)->and($bulk->json('data.url'))->toBeString();
    $this->assertMatchesOpenApi(bolCall($this->payroll, 'POST', '/payslips/bulk-download', [])->assertStatus(422), 'post', '/payslips/bulk-download', 422);
    $this->assertMatchesOpenApi(bolCall($this->employee, 'POST', '/payslips/bulk-download', ['payroll_period_id' => $jan])->assertForbidden(), 'post', '/payslips/bulk-download', 403);
    TenancyScope::runAs(BOL_TENANT, fn () => expect(DB::table('audit_events')->where('action', 'payslips.bulk_downloaded')->exists())->toBeTrue());

    // Aislamiento: otra organización no ve la boleta ni el periodo.
    $this->assertMatchesOpenApi(bolCall($this->other, 'GET', "/payslips/{$a}")->assertNotFound(), 'get', '/payslips/{id}', 404);
    $this->assertMatchesOpenApi(bolCall($this->other, 'POST', "/payroll-periods/{$jan}/payslips")->assertNotFound(), 'post', '/payroll-periods/{id}/payslips', 404);
});

test('BOL-5 · explica el cambio de neto frente a la boleta anterior citando conceptos y montos', function (): void {
    $jan = bolClosedPeriod($this, 1);
    bolCall($this->payroll, 'POST', "/payroll-periods/{$jan}/payslips")->assertOk();
    $first = bolCall($this->employee, 'GET', '/me/payslips/'.bolPayslips($jan)[$this->b].'/explanation')->assertOk();
    expect($first->json('data.previous_payslip_id'))->toBeNull();

    $feb = bolClosedPeriod($this, 2);
    bolCall($this->payroll, 'POST', "/payroll-periods/{$feb}/payslips")->assertOk();
    $mine = bolPayslips($feb)[$this->b];
    $exp = bolCall($this->employee, 'GET', "/me/payslips/{$mine}/explanation")->assertOk();
    $this->assertMatchesOpenApi($exp, 'get', '/me/payslips/{id}/explanation', 200);
    expect($exp->json('data.difference.amount'))->toBe('1000.00')->and($exp->json('data.within_tolerance'))->toBeFalse()
        ->and($exp->json('data.items.0.concept_code'))->toBe('BASICO')->and($exp->json('data.items.0.change'))->toBe('changed')
        ->and($exp->json('data.items.0.effect_on_net.amount'))->toBe('1000.00')->and($exp->json('data.items.0.text'))->toContain('3,000.00')
        ->and($exp->json('data.summary'))->not->toBe('');
    $admin = bolCall($this->payroll, 'GET', "/payslips/{$mine}/explanation")->assertOk();
    $this->assertMatchesOpenApi($admin, 'get', '/payslips/{id}/explanation', 200);
    $this->assertMatchesOpenApi(bolCall($this->noSalary, 'GET', "/payslips/{$mine}/explanation")->assertForbidden(), 'get', '/payslips/{id}/explanation', 403);
    // A no cambió: sin motivos y dentro de la tolerancia.
    $same = bolCall($this->payroll, 'GET', '/payslips/'.bolPayslips($feb)[$this->a].'/explanation')->assertOk();
    expect($same->json('data.items'))->toBe([])->and($same->json('data.within_tolerance'))->toBeTrue();
});

test('BOL · sin el módulo de planilla las boletas responden module_not_enabled', function (): void {
    IdentityFixtures::disableModule(BOL_TENANT, 'payroll');
    $off = bolCall($this->employee, 'GET', '/me/payslips')->assertForbidden();
    $this->assertMatchesOpenApi($off, 'get', '/me/payslips', 403);
    expect($off->json('error.code'))->toBe('module_not_enabled');
    expect(bolCall($this->payroll, 'GET', '/payslips')->assertForbidden()->json('error.code'))->toBe('module_not_enabled');
});
