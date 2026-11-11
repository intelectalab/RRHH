<?php

/**
 * AUD-3 · Actividad de usuario y logs técnicos separados · AUD-5 · Integridad y retención de la auditoría.
 */

use App\Modules\Audit\Application\AuditArchiver;
use App\Modules\Audit\Application\AuditChainVerifier;
use App\Modules\Audit\Application\RetentionPolicies;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Shared\Audit\AuditLogger;
use App\Shared\Logging\SensitiveDataScrubber;
use App\Shared\Settings\ParameterResolver;
use App\Shared\Tenancy\TenancyScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Monolog\Level;
use Monolog\LogRecord;
use Tests\Support\IdentityFixtures;

uses(DatabaseTransactions::class);

const AUS_TENANT = 'aaaaaaaa-a0d5-4000-8000-00000000000a';
const AUS_OTHER = 'bbbbbbbb-a0d5-4000-8000-00000000000b';

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requiere PostgreSQL.');
    }

    TenancyScope::clear();
    DB::table('tenants')->insertOrIgnore([
        ['id' => AUS_TENANT, 'slug' => 'aus-org-a', 'name' => 'Aus A', 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN'],
        ['id' => AUS_OTHER, 'slug' => 'aus-org-b', 'name' => 'Aus B', 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN'],
    ]);
    DB::select('SELECT seed_tenant_defaults(?)', [AUS_TENANT]);
    DB::select('SELECT seed_tenant_defaults(?)', [AUS_OTHER]);
    Storage::fake('audit-cold');
    config(['audit.archive_disk' => 'audit-cold', 'audit.hot_months' => 12]);
});

afterEach(function (): void {
    TenancyScope::clear();
});

function ausUser(string $systemRole, string $tenant = AUS_TENANT): User
{
    return TenancyScope::runAs($tenant, function () use ($systemRole): User {
        $user = User::factory()->create(['display_name' => ucfirst($systemRole)]);
        $user->roleAssignments()->create(['role_id' => Role::query()->where('system_role_code', $systemRole)->value('id')]);

        return $user;
    });
}

function ausGet(User $user, string $path)
{
    TenancyScope::clear();
    app('auth')->forgetGuards();

    return test()->actingAs($user, 'ayni')->withHeaders(['X-Tenant-Id' => $user->tenant_id])->getJson('/api/v1'.$path);
}

/** Ejecuta SQL saltándose los triggers (como lo haría quien altera la base por fuera de la aplicación). */
function ausTamper(string $sql, array $bindings = []): void
{
    TenancyScope::clear();
    DB::statement('SET session_replication_role = replica');
    try {
        DB::statement($sql, $bindings);
    } finally {
        DB::statement('SET session_replication_role = origin');
    }
}

function ausSeedAudit(int $n, string $tenant = AUS_TENANT): array
{
    return TenancyScope::runAs($tenant, function () use ($n): array {
        $ids = [];
        foreach (range(1, $n) as $i) {
            $id = (string) Str::uuid();
            app(AuditLogger::class)->record('demo.changed', 'demo', $id, ['n' => $i - 1], ['n' => $i]);
            $ids[] = $id;
        }

        return $ids;
    });
}

function ausVerify(string $tenant = AUS_TENANT): array
{
    return TenancyScope::runAs($tenant, fn (): array => app(AuditChainVerifier::class)->verify());
}

// ---------------------------------------------------------------- AUD-3

test('AUD-3: la línea de actividad de una ficha no mezcla auditoría ni entradas técnicas', function (): void {
    $auditor = ausUser('auditor');
    $subject = ausUser('empleado');

    // Ver la ficha deja actividad (no auditoría).
    $before = TenancyScope::runAs(AUS_TENANT, fn () => DB::table('audit_events')->count());
    $admin = ausUser('admin_empresa');
    ausGet($admin, '/users/'.$subject->id)->assertOk();
    // Un cambio de negocio sobre la misma ficha deja auditoría (no actividad).
    TenancyScope::runAs(AUS_TENANT, fn () => app(AuditLogger::class)->record('user.updated', 'user', $subject->id, ['display_name' => 'A'], ['display_name' => 'B']));
    expect(TenancyScope::runAs(AUS_TENANT, fn () => DB::table('audit_events')->count()))->toBe($before + 1);

    $line = ausGet($auditor, '/activity-events?filter[subject_type]=user&filter[subject_id]='.$subject->id)->assertOk();
    $this->assertMatchesOpenApi($line, 'get', '/activity-events', 200);
    expect(collect($line->json('data'))->pluck('activity')->all())->toBe(['record.viewed'])
        ->and($line->json('data.0.user_id'))->toBe($admin->id)
        ->and($line->json('data.0.subject_id'))->toBe($subject->id);
    // Las acciones de auditoría no aparecen en la actividad.
    expect(collect($line->json('data'))->pluck('activity')->contains('user.updated'))->toBeFalse();
});

test('AUD-3: la búsqueda queda en la actividad sin el texto buscado', function (): void {
    $admin = ausUser('admin_empresa');
    ausGet($admin, '/search?q=Juan%20Perez')->assertOk();

    $row = TenancyScope::runAs(AUS_TENANT, fn () => DB::table('activity_events')->where('activity', 'search.performed')->where('user_id', $admin->id)->sole());
    expect((string) $row->metadata)->not->toContain('Juan')->and(json_decode((string) $row->metadata, true))->toHaveKey('results');
});

test('AUD-3: permisos, filtros inválidos y aislamiento de la actividad', function (): void {
    TenancyScope::runAs(AUS_OTHER, fn () => DB::table('activity_events')->insert(['tenant_id' => AUS_OTHER, 'activity' => 'auth.login', 'subject_type' => 'user', 'subject_id' => (string) Str::uuid(), 'occurred_at' => now()]));

    $forbidden = ausGet(ausUser('empleado'), '/activity-events');
    $forbidden->assertStatus(403);
    $this->assertMatchesOpenApi($forbidden, 'get', '/activity-events', 403);

    $auditor = ausUser('auditor');
    $invalid = ausGet($auditor, '/activity-events?filter[from]=ayer');
    $invalid->assertStatus(400)->assertJsonPath('error.code', 'invalid_filter');
    $this->assertMatchesOpenApi($invalid, 'get', '/activity-events', 400);
    ausGet($auditor, '/activity-events?filter[ip]=1.1.1.1')->assertStatus(400);

    expect(ausGet($auditor, '/activity-events?filter[activity]=auth.login')->json('data'))->toBe([]);
});

test('AUD-3: los tres registros tienen políticas de retención configurables por separado', function (): void {
    $auditor = ausUser('auditor');
    TenancyScope::runAs(AUS_TENANT, fn () => app(ParameterResolver::class)->set('privacidad.retencion_actividad_dias', 30, 'organization', null, null, 'Prueba'));

    $response = ausGet($auditor, '/audit-retention')->assertOk();
    $this->assertMatchesOpenApi($response, 'get', '/audit-retention', 200);
    expect($response->json('data.activity.retention_days'))->toBe(30)
        ->and($response->json('data.audit.retention_years'))->toBe(5)
        ->and($response->json('data.audit.online_months'))->toBe(12)
        ->and($response->json('data.technical.retention_days'))->toBe((int) config('logging.channels.daily.days'))
        ->and($response->json('data.technical.configured_by'))->toBe('platform');

    // La retención de la actividad no toca la auditoría de la misma fecha.
    TenancyScope::runAs(AUS_TENANT, function (): void {
        DB::table('activity_events')->insert([
            ['tenant_id' => AUS_TENANT, 'activity' => 'auth.login', 'occurred_at' => now()->subDays(40)],
            ['tenant_id' => AUS_TENANT, 'activity' => 'auth.login', 'occurred_at' => now()->subDays(5)],
        ]);
        DB::table('audit_events')->insert(['tenant_id' => AUS_TENANT, 'action' => 'demo.old', 'entity_type' => 'demo', 'occurred_at' => now()->subDays(40)]);
    });
    $this->artisan('activity:prune')->assertSuccessful();

    TenancyScope::runAs(AUS_TENANT, function (): void {
        expect(DB::table('activity_events')->where('activity', 'auth.login')->count())->toBe(1)
            ->and(DB::table('audit_events')->where('action', 'demo.old')->count())->toBe(1);
    });

    ausGet(ausUser('empleado'), '/audit-retention')->assertStatus(403);
});

test('AUD-3: los logs técnicos rotan con su propio plazo y no llevan datos personales', function (): void {
    $daily = config('logging.channels.daily');
    expect($daily['days'])->toBeInt()->toBeGreaterThan(0)
        ->and($daily['processors'])->toContain(SensitiveDataScrubber::class);

    $record = new LogRecord(new DateTimeImmutable, 'app', Level::Info, 'login', ['password' => 'x', 'national_id' => '12345678', 'user' => ['salary' => 1]]);
    $scrubbed = (new SensitiveDataScrubber)($record);
    expect($scrubbed->context['password'])->toBe('[REDACTED]')
        ->and($scrubbed->context['national_id'])->toBe('[REDACTED]')
        ->and($scrubbed->context['user']['salary'])->toBe('[REDACTED]');
});

// ---------------------------------------------------------------- AUD-5

test('AUD-5: cada entrada queda encadenada y la verificación sin cambios resulta correcta', function (): void {
    ausSeedAudit(4);
    $rows = TenancyScope::runAs(AUS_TENANT, fn () => DB::table('audit_events')->orderBy('chain_seq')->get(['chain_seq', 'prev_hash', 'row_hash']));
    expect($rows->pluck('chain_seq')->map(fn ($s) => (int) $s)->all())->toBe([1, 2, 3, 4])
        ->and($rows[0]->prev_hash)->toBeNull()
        ->and($rows[1]->prev_hash)->toBe($rows[0]->row_hash);

    $result = ausVerify();
    expect($result['status'])->toBe('ok')->and($result['checked_rows'])->toBe(4)->and($result['issues'])->toBe([]);

    // La cadena es por organización.
    ausSeedAudit(1, AUS_OTHER);
    expect((int) TenancyScope::runAs(AUS_OTHER, fn () => DB::table('audit_events')->value('chain_seq')))->toBe(1);

    $response = ausGet(ausUser('auditor'), '/audit-integrity-checks')->assertOk();
    $this->assertMatchesOpenApi($response, 'get', '/audit-integrity-checks', 200);
    expect($response->json('data'))->toHaveCount(1)->and($response->json('data.0.status'))->toBe('ok');
});

test('AUD-5: la verificación detecta una entrada alterada y avisa por el outbox', function (): void {
    $ids = ausSeedAudit(3);
    ausTamper("UPDATE audit_events SET after_data = '{\"n\": 99}'::jsonb WHERE entity_id = ?", [$ids[1]]);

    $result = ausVerify();
    expect($result['status'])->toBe('failed')
        ->and(collect($result['issues'])->pluck('type')->all())->toContain('altered')
        ->and(collect($result['issues'])->firstWhere('type', 'altered')['seq'])->toBe(2);

    $event = TenancyScope::runAs(AUS_TENANT, fn () => DB::table('outbox_events')->where('event_type', 'audit.integrity_failed.v1')->first());
    expect($event)->not->toBeNull();

    // Reescribir también el hash rompe el enlace con la entrada siguiente.
    ausTamper('UPDATE audit_events SET row_hash = audit_event_hash(tenant_id, chain_seq, prev_hash, id, occurred_at, actor_user_id, actor_kind, action, entity_type, entity_id, before_data, after_data, changed_fields, reason, ip, user_agent, request_id) WHERE entity_id = ?', [$ids[1]]);
    expect(collect(ausVerify()['issues'])->pluck('type')->all())->toBe(['broken_link']);
});

test('AUD-5: la verificación detecta entradas faltantes, al medio y al final', function (): void {
    $ids = ausSeedAudit(4);
    ausTamper('DELETE FROM audit_events WHERE entity_id = ?', [$ids[1]]);
    $middle = ausVerify();
    expect($middle['status'])->toBe('failed')->and($middle['issues'][0])->toMatchArray(['type' => 'missing', 'from_seq' => 2, 'to_seq' => 2]);

    ausTamper('DELETE FROM audit_events WHERE entity_id = ?', [$ids[3]]);
    $tail = collect(ausVerify()['issues']);
    expect($tail->where('type', 'missing')->pluck('from_seq')->all())->toBe([2, 4]);

    // Una entrada insertada saltándose el trigger queda sin sellar.
    ausTamper("INSERT INTO audit_events (tenant_id, action, entity_type, occurred_at) VALUES (?, 'forged', 'demo', now())", [AUS_TENANT]);
    expect(collect(ausVerify()['issues'])->pluck('type')->all())->toContain('unchained');
});

test('AUD-5: el comando programado verifica y deja el resultado', function (): void {
    ausSeedAudit(2);
    $this->artisan('audit:verify-integrity', ['--tenant' => AUS_TENANT])->assertSuccessful();
    expect(TenancyScope::runAs(AUS_TENANT, fn () => DB::table('audit_integrity_checks')->where('status', 'ok')->count()))->toBe(1);

    ausTamper('DELETE FROM audit_events WHERE tenant_id = ? AND chain_seq = 1', [AUS_TENANT]);
    $this->artisan('audit:verify-integrity', ['--tenant' => AUS_TENANT])->assertFailed();
});

test('AUD-5: el archivado en frío conserva la consulta durante el plazo y la cadena sigue íntegra', function (): void {
    $month = CarbonImmutable::now('UTC')->startOfMonth()->subMonths(24);
    TenancyScope::clear();
    DB::select('SELECT ensure_monthly_partitions(?::regclass, ?::date, 1)', ['audit_events', $month->toDateString()]);
    $partition = 'audit_events_p'.$month->format('Ym');

    $employmentId = (string) Str::uuid();
    TenancyScope::runAs(AUS_TENANT, function () use ($month, $employmentId): void {
        foreach (range(1, 3) as $i) {
            DB::table('audit_events')->insert(['tenant_id' => AUS_TENANT, 'action' => 'old.changed', 'entity_type' => 'demo', 'occurred_at' => $month->addDays($i)->toIso8601String()]);
        }
        app(AuditLogger::class)->record('employment.updated', 'employment', $employmentId, ['base_salary' => ['amount' => '1000.00', 'currency' => 'PEN']], ['base_salary' => ['amount' => '1200.00', 'currency' => 'PEN']]);
        DB::table('audit_events')->insert(['tenant_id' => AUS_TENANT, 'action' => 'old.changed', 'entity_type' => 'employment', 'entity_id' => $employmentId, 'occurred_at' => $month->addDays(5)->toIso8601String()]);
    });
    // La entrada del sueldo es de hoy: queda en línea. Se mueve al mes viejo solo para la prueba.
    ausTamper("UPDATE audit_events SET occurred_at = ?::timestamptz WHERE entity_id = ? AND action = 'employment.updated'", [$month->addDays(4)->toIso8601String(), $employmentId]);
    ausTamper('UPDATE audit_events SET row_hash = audit_event_hash(tenant_id, chain_seq, prev_hash, id, occurred_at, actor_user_id, actor_kind, action, entity_type, entity_id, before_data, after_data, changed_fields, reason, ip, user_agent, request_id) WHERE entity_id = ?', [$employmentId]);
    ausSeedAudit(2); // entradas recientes, en línea

    expect(TenancyScope::runAs(AUS_TENANT, fn () => app(AuditArchiver::class)->archiveDue()))->toBe(1)
        ->and(TenancyScope::runAs(AUS_TENANT, fn () => app(AuditArchiver::class)->archiveDue()))->toBe(0);
    $archive = TenancyScope::runAs(AUS_TENANT, fn () => DB::table('audit_archives')->sole());
    expect((int) $archive->row_count)->toBe(5)
        ->and(substr((string) $archive->purge_after, 0, 10))->toBe($month->addMonth()->addYears(5)->toDateString());
    Storage::disk('audit-cold')->assertExists((string) $archive->storage_key);

    // Retirar la partición solo se hace cuando todas las organizaciones la archivaron.
    $dropped = TenancyScope::runWithoutTenant(fn () => app(AuditArchiver::class)->dropArchivedPartitions());
    expect($dropped)->toBe([$partition])
        ->and(TenancyScope::runAs(AUS_TENANT, fn () => DB::table('audit_events')->count()))->toBe(2)
        ->and(ausVerify()['status'])->toBe('ok');

    // Consulta del archivo: lista, detalle y entradas (con los sensibles enmascarados).
    $auditor = ausUser('auditor');
    $list = ausGet($auditor, '/audit-archives')->assertOk();
    $this->assertMatchesOpenApi($list, 'get', '/audit-archives', 200);
    expect($list->json('data.0.available'))->toBeTrue();
    $this->assertMatchesOpenApi(ausGet($auditor, '/audit-archives/'.$archive->id)->assertOk(), 'get', '/audit-archives/{id}', 200);
    $entries = ausGet($auditor, '/audit-archives/'.$archive->id.'/entries')->assertOk();
    $this->assertMatchesOpenApi($entries, 'get', '/audit-archives/{id}/entries', 200);
    expect($entries->json('meta.total'))->toBe(5);
    // Quien no tiene el permiso del campo ve el sueldo enmascarado también en el archivo.
    $viewer = IdentityFixtures::userWithPermissions(AUS_TENANT, ['audit.logs.view']);
    $masked = collect(ausGet($viewer, '/audit-archives/'.$archive->id.'/entries')->assertOk()->json('data'))->firstWhere('action', 'employment.updated');
    expect($masked['after']['base_salary'])->toBe('••••');
    $payroll = IdentityFixtures::userWithPermissions(AUS_TENANT, ['audit.logs.view', 'people.employments.salary.view']);
    $visible = collect(ausGet($payroll, '/audit-archives/'.$archive->id.'/entries')->json('data'))->firstWhere('action', 'employment.updated');
    expect($visible['after']['base_salary']['amount'])->toBe('1200.00');

    // Un archivo alterado se detecta.
    Storage::disk('audit-cold')->put((string) $archive->storage_key, 'alterado');
    expect(collect(ausVerify()['issues'])->pluck('type')->all())->toBe(['archive_altered']);

    // Vencido el plazo, el archivo se borra y deja de consultarse; la cadena sigue cuadrando.
    ausTamper('UPDATE audit_archives SET purge_after = current_date - 1 WHERE id = ?', [$archive->id]);
    expect(TenancyScope::runAs(AUS_TENANT, fn () => app(AuditArchiver::class)->purgeExpired()))->toBe(1);
    Storage::disk('audit-cold')->assertMissing((string) $archive->storage_key);
    $gone = ausGet($auditor, '/audit-archives/'.$archive->id.'/entries');
    $gone->assertNotFound();
    $this->assertMatchesOpenApi($gone, 'get', '/audit-archives/{id}/entries', 404);
    expect(ausGet($auditor, '/audit-archives/'.$archive->id)->json('data.available'))->toBeFalse()
        ->and(ausVerify()['status'])->toBe('ok');
});

test('AUD-5: no se retira una partición que otra organización aún no archivó', function (): void {
    $month = CarbonImmutable::now('UTC')->startOfMonth()->subMonths(30);
    TenancyScope::clear();
    DB::select('SELECT ensure_monthly_partitions(?::regclass, ?::date, 1)', ['audit_events', $month->toDateString()]);
    foreach ([AUS_TENANT, AUS_OTHER] as $tenant) {
        TenancyScope::runAs($tenant, fn () => DB::table('audit_events')->insert(['tenant_id' => $tenant, 'action' => 'old', 'entity_type' => 'demo', 'occurred_at' => $month->addDay()->toIso8601String()]));
    }
    TenancyScope::runAs(AUS_TENANT, fn () => app(AuditArchiver::class)->archiveDue());

    expect(TenancyScope::runWithoutTenant(fn () => app(AuditArchiver::class)->dropArchivedPartitions()))->toBe([])
        ->and(TenancyScope::runAs(AUS_OTHER, fn () => DB::table('audit_events')->count()))->toBe(1);
});

test('AUD-5: permisos, filtros inválidos y aislamiento de integridad y archivo', function (): void {
    $foreign = TenancyScope::runAs(AUS_OTHER, fn () => (string) DB::table('audit_archives')->insertGetId([
        'tenant_id' => AUS_OTHER, 'period_month' => '2023-01-01', 'row_count' => 0, 'storage_disk' => 'audit-cold',
        'storage_key' => 'x.jsonl', 'sha256' => hash('sha256', ''), 'purge_after' => '2030-01-01',
    ]));

    $employee = ausUser('empleado');
    foreach (['/audit-integrity-checks', '/audit-archives'] as $path) {
        $forbidden = ausGet($employee, $path);
        $forbidden->assertStatus(403);
        $this->assertMatchesOpenApi($forbidden, 'get', $path, 403);
    }

    $auditor = ausUser('auditor');
    $invalid = ausGet($auditor, '/audit-integrity-checks?filter[status]=roto');
    $invalid->assertStatus(400);
    $this->assertMatchesOpenApi($invalid, 'get', '/audit-integrity-checks', 400);

    $notFound = ausGet($auditor, '/audit-archives/'.$foreign);
    $notFound->assertNotFound();
    $this->assertMatchesOpenApi($notFound, 'get', '/audit-archives/{id}', 404);
    ausGet($auditor, '/audit-archives/'.$foreign.'/entries')->assertNotFound();
    expect(ausGet($auditor, '/audit-archives')->json('data'))->toBe([]);
});

test('AUD-5: el rol de la aplicación no mueve la cabeza de la cadena ni reescribe manifiestos', function (): void {
    ausSeedAudit(1);
    TenancyScope::runAs(AUS_TENANT, function (): void {
        expect(fn () => DB::transaction(fn () => DB::table('audit_chain_heads')->update(['last_seq' => 0])))->toThrow(QueryException::class);
        expect(fn () => DB::transaction(fn () => DB::table('audit_integrity_checks')->delete()))->toThrow(QueryException::class);
        expect(fn () => DB::transaction(fn () => DB::table('audit_archives')->update(['sha256' => 'x'])))->toThrow(QueryException::class);
    });
    expect(app(RetentionPolicies::class))->toBeInstanceOf(RetentionPolicies::class);
});
