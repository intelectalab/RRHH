<?php

/**
 * IAR-1 · Puerto del proveedor de IA con adaptador falso determinista (A-09 abierta).
 * IAR-2 · Bitácora de uso de IA: cada uso con usuario, propósito y tokens; solo inserción; retención; consulta.
 * IAR-9 · Asistente de ayuda: solo artículos visibles para el usuario, con citas; sin fuente lo dice y ofrece
 *         derivar a RRHH; no consulta datos de colaboradores.
 * IAR-10 · Activación por la organización: plan (bandera), encendido, roles, límite de preguntas; auditada.
 * IAR-11 · Calidad: preguntas sin respuesta y respuestas mal valoradas.
 */

use App\Modules\Ai\Application\AiUsageLog;
use App\Modules\Ai\Domain\AiCompletion;
use App\Modules\Ai\Domain\AiPrompt;
use App\Modules\Ai\Infrastructure\FakeAiProvider;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Platform\Application\Help\ProductHelpArticles;
use App\Shared\Cache\CacheVersions;
use App\Shared\Permissions\DataScope;
use App\Shared\Permissions\ModuleGate;
use App\Shared\Permissions\PermissionResolver;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\IdentityFixtures;

uses(DatabaseTransactions::class);

const IA_TENANT = 'aaaaaaaa-1a1a-4000-8000-00000000000a';
const IA_OTHER = 'bbbbbbbb-1a1a-4000-8000-00000000000b';

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requiere PostgreSQL.');
    }
    TenancyScope::clear();
    DB::table('feature_flags')->insertOrIgnore(['code' => 'ai_help_assistant', 'name' => 'Asistente de ayuda', 'default_enabled' => false, 'is_beta' => false, 'status' => 'active']);
    foreach ([IA_TENANT => 'ia-org', IA_OTHER => 'ia-otra'] as $id => $slug) {
        DB::table('tenants')->insertOrIgnore(['id' => $id, 'slug' => $slug, 'name' => 'Grupo '.$slug, 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN']);
        DB::select('SELECT seed_tenant_defaults(?)', [$id]);
        TenancyScope::runAs($id, fn () => IdentityFixtures::enableAllModules());
    }
    iaPlan(IA_TENANT, true);
    iaPlan(IA_OTHER, true);
    app(ProductHelpArticles::class)->syncBundled();
    $this->admin = iaUser(IA_TENANT, 'admin_empresa');
    $this->hr = iaUser(IA_TENANT, 'rrhh');
    $this->employee = iaUser(IA_TENANT, 'empleado');
    $this->auditor = iaUser(IA_TENANT, 'auditor');
    $this->stranger = iaUser(IA_OTHER, 'admin_empresa');
});

afterEach(function (): void {
    app(FakeAiProvider::class)->respondWith(null);
    TenancyScope::clear();
});

function iaPlan(string $tenant, bool $enabled): void
{
    DB::table('tenant_feature_flags')->where('tenant_id', $tenant)->where('feature_flag_code', 'ai_help_assistant')->delete();
    DB::table('tenant_feature_flags')->insert(['tenant_id' => $tenant, 'feature_flag_code' => 'ai_help_assistant', 'enabled' => $enabled]);
    app(CacheVersions::class)->bump('feature_flags');
}

function iaUser(string $tenant, string $role): User
{
    return TenancyScope::runAs($tenant, function () use ($role): User {
        $u = User::factory()->create(['locale' => 'es']);
        $u->roleAssignments()->create(['role_id' => iaRole($role)]);

        return $u;
    });
}

function iaRole(string $code): string
{
    return (string) Role::query()->where('system_role_code', $code)->value('id');
}

/**
 * @param  array<string, mixed>  $body
 */
function iaCall(User $user, string $method, string $path, array $body = []): TestResponse
{
    TenancyScope::clear();
    app('auth')->forgetGuards();
    app(PermissionResolver::class)->forget();
    app(ModuleGate::class)->forget();
    app(DataScope::class)->forget();

    return test()->actingAs($user, 'ayni')->withHeaders(['X-Tenant-Id' => $user->tenant_id, 'Idempotency-Key' => (string) Str::uuid(), 'Accept' => 'application/json'])
        ->json(strtoupper($method), '/api/v1'.$path, $body);
}

/**
 * Enciende el asistente para los roles dados (por código de rol de sistema).
 *
 * @param  list<string>  $roles
 * @param  array<string, mixed>  $extra
 */
function iaEnable(User $admin, array $roles, array $extra = []): TestResponse
{
    $roleIds = TenancyScope::runAs((string) $admin->tenant_id, fn (): array => array_map('iaRole', $roles));

    return iaCall($admin, 'patch', '/ai/use-case-settings/help_assistant', ['enabled' => true, 'role_ids' => $roleIds] + $extra);
}

test('IAR-10 · sin activar, el asistente es invisible y la API lo rechaza', function (): void {
    $avail = iaCall($this->employee, 'get', '/ai/help-assistant/availability')->assertOk();
    test()->assertMatchesOpenApi($avail, 'get', '/ai/help-assistant/availability', 200);
    expect($avail->json('data.available'))->toBeFalse();

    $ask = iaCall($this->employee, 'post', '/ai/help-assistant/ask', ['question' => '¿Cómo solicito vacaciones?'])->assertStatus(403);
    test()->assertMatchesOpenApi($ask, 'post', '/ai/help-assistant/ask', 403);
    expect($ask->json('error.code') ?? $ask->json('code'))->toBe('feature_not_enabled');
});

test('IAR-10 · el administrador lo enciende por roles con límite; queda auditado con antes, después y autor', function (): void {
    $list = iaCall($this->admin, 'get', '/ai/use-case-settings')->assertOk();
    test()->assertMatchesOpenApi($list, 'get', '/ai/use-case-settings', 200);
    expect($list->json('data.0.use_case'))->toBe('help_assistant')->and($list->json('data.0.enabled'))->toBeFalse()->and($list->json('data.0.plan_allows'))->toBeTrue();

    $res = iaEnable($this->admin, ['empleado'], ['question_limit' => 2, 'limit_period' => 'day', 'notice' => 'Responde con la ayuda publicada.'])->assertOk();
    test()->assertMatchesOpenApi($res, 'patch', '/ai/use-case-settings/{id}', 200);
    expect($res->json('data.enabled'))->toBeTrue()->and($res->json('data.question_limit'))->toBe(2);

    $audit = TenancyScope::runAs(IA_TENANT, fn () => DB::table('audit_events')->where('action', 'ai.use_case_settings.update')->latest('occurred_at')->first());
    expect($audit)->not->toBeNull()->and($audit->actor_user_id)->toBe($this->admin->id);
    expect(json_decode((string) $audit->before_data, true)['enabled'] ?? null)->toBeFalse();
    expect(json_decode((string) $audit->after_data, true)['enabled'] ?? null)->toBeTrue();

    // Disponible para el rol habilitado; no para RRHH, que no está en la lista.
    expect(iaCall($this->employee, 'get', '/ai/help-assistant/availability')->json('data'))->toMatchArray(['available' => true, 'remaining' => 2]);
    expect(iaCall($this->hr, 'get', '/ai/help-assistant/availability')->json('data.available'))->toBeFalse();
    iaCall($this->hr, 'post', '/ai/help-assistant/ask', ['question' => '¿Cómo solicito vacaciones?'])->assertStatus(403);

    // Límite por usuario: la tercera pregunta del día se rechaza.
    iaCall($this->employee, 'post', '/ai/help-assistant/ask', ['question' => '¿Cómo solicito vacaciones?'])->assertOk();
    iaCall($this->employee, 'post', '/ai/help-assistant/ask', ['question' => '¿Dónde veo mis pendientes?'])->assertOk();
    $limited = iaCall($this->employee, 'post', '/ai/help-assistant/ask', ['question' => '¿Cómo cambio mi contraseña?'])->assertStatus(429);
    test()->assertMatchesOpenApi($limited, 'post', '/ai/help-assistant/ask', 429);
});

test('IAR-10 · sin el plan no se puede encender y la pantalla lo sabe (plan_allows)', function (): void {
    iaPlan(IA_TENANT, false);
    expect(iaCall($this->admin, 'get', '/ai/use-case-settings/help_assistant')->json('data.plan_allows'))->toBeFalse();
    $res = iaEnable($this->admin, ['empleado'])->assertStatus(403);
    test()->assertMatchesOpenApi($res, 'patch', '/ai/use-case-settings/{id}', 403);
    // Encendido antes y luego quitado del plan: queda apagado para todos.
    iaPlan(IA_TENANT, true);
    iaEnable($this->admin, ['empleado'])->assertOk();
    iaPlan(IA_TENANT, false);
    expect(iaCall($this->employee, 'get', '/ai/help-assistant/availability')->json('data.available'))->toBeFalse();
});

test('IAR-10 · permisos y validación: sin permiso 403, datos inválidos 422, otra organización no ve lo propio', function (): void {
    iaCall($this->employee, 'patch', '/ai/use-case-settings/help_assistant', ['enabled' => true])->assertStatus(403);
    iaCall($this->hr, 'get', '/ai/use-case-settings')->assertStatus(403);
    $bad = iaCall($this->admin, 'patch', '/ai/use-case-settings/help_assistant', ['question_limit' => 0, 'limit_period' => 'year'])->assertStatus(422);
    test()->assertMatchesOpenApi($bad, 'patch', '/ai/use-case-settings/{id}', 422);
    iaCall($this->admin, 'patch', '/ai/use-case-settings/help_assistant', ['role_ids' => [TenancyScope::runAs(IA_OTHER, fn () => iaRole('empleado'))]])->assertStatus(422);
    iaCall($this->admin, 'get', '/ai/use-case-settings/otro_caso')->assertNotFound();

    iaEnable($this->admin, ['empleado'])->assertOk();
    expect(iaCall($this->stranger, 'get', '/ai/use-case-settings/help_assistant')->json('data.enabled'))->toBeFalse();
});

test('IAR-9 · responde con artículos visibles y los cita; deja la bitácora «ayuda» sin consultar datos de colaboradores', function (): void {
    iaEnable($this->admin, ['empleado', 'rrhh'])->assertOk();
    $queries = [];
    DB::listen(function (QueryExecuted $q) use (&$queries): void {
        $queries[] = $q->sql;
    });
    $res = iaCall($this->employee, 'post', '/ai/help-assistant/ask', ['question' => '¿Cómo solicito mis vacaciones?', 'screen_key' => 'portal.home'])->assertOk();
    test()->assertMatchesOpenApi($res, 'post', '/ai/help-assistant/ask', 200);
    expect($res->json('data.answered'))->toBeTrue()
        ->and($res->json('data.answer'))->not->toBe('')
        ->and($res->json('data.explanation'))->not->toBe('')
        ->and($res->json('data.citations'))->not->toBeEmpty()
        ->and($res->json('data.provider_is_fake'))->toBeTrue();
    foreach ($res->json('data.citations') as $c) {
        iaCall($this->employee, 'get', '/help/articles/'.$c['id'])->assertOk();
    }

    // Ni una consulta a tablas de colaboradores, personas, contratos, compensación o planilla.
    $personal = '/\b(from|join)\s+"?(persons|people|employments|employment_\w+|compensations?|compensation_\w+|contracts|payroll_\w+|payslips|assignments|vacation_\w+|absence_\w+)"?\b/i';
    expect(array_values(array_filter($queries, fn (string $sql): bool => preg_match($personal, $sql) === 1)))->toBe([]);

    $row = TenancyScope::runAs(IA_TENANT, fn () => DB::table('ai_interactions')->where('id', $res->json('data.id'))->first());
    expect($row->use_case)->toBe('ayuda')->and($row->user_id)->toBe($this->employee->id)
        ->and($row->data_scopes)->toBe('{help_articles}')->and((int) $row->input_tokens)->toBeGreaterThan(0)
        ->and($row->prompt_enc)->not->toContain('vacaciones')->and($row->provider)->toBe('fake_ai');
});

test('IAR-9 · sin fuente lo dice, no llama al proveedor y ofrece derivar a RRHH; la cita fuera de lo visible se descarta', function (): void {
    iaEnable($this->admin, ['empleado'])->assertOk();
    $calls = 0;
    app(FakeAiProvider::class)->respondWith(function (AiPrompt $p) use (&$calls): AiCompletion {
        $calls++;

        return new AiCompletion('Texto', 'Explicación', ['00000000-0000-4000-8000-000000000999'], 1, 1, 'fake');
    });

    $none = iaCall($this->employee, 'post', '/ai/help-assistant/ask', ['question' => 'zzqx wwyy kkrr'])->assertOk();
    expect($none->json('data.answered'))->toBeFalse()->and($none->json('data.citations'))->toBe([])
        ->and($none->json('data.escalation.offered'))->toBeTrue()->and($none->json('data.explanation'))->not->toBe('')
        ->and($calls)->toBe(0);

    // El proveedor cita algo que no se le entregó: no se muestra y la pregunta cuenta como sin respuesta.
    $out = iaCall($this->employee, 'post', '/ai/help-assistant/ask', ['question' => '¿Cómo solicito vacaciones?'])->assertOk();
    expect($calls)->toBe(1)->and($out->json('data.answered'))->toBeFalse()->and($out->json('data.citations'))->toBe([]);

    $unanswered = TenancyScope::runAs(IA_TENANT, fn () => DB::table('help_search_queries')->where('source', 'assistant')->count());
    expect($unanswered)->toBe(2);
});

test('IAR-2 · la bitácora es de solo inserción; la retención vacía solo el contenido; la consultan el administrador y el auditor', function (): void {
    iaEnable($this->admin, ['empleado'])->assertOk();
    $id = (string) iaCall($this->employee, 'post', '/ai/help-assistant/ask', ['question' => '¿Cómo solicito vacaciones?'])->json('data.id');

    TenancyScope::runAs(IA_TENANT, function () use ($id): void {
        foreach (["UPDATE ai_interactions SET use_case = 'otro' WHERE id = ?", 'DELETE FROM ai_interactions WHERE id = ?'] as $sql) {
            try {
                DB::transaction(fn () => DB::update($sql, [$id]));
                test()->fail('La bitácora de IA debía rechazar: '.$sql);
            } catch (QueryException $e) {
                expect($e->getMessage())->toMatch('/solo inserción|permission denied/');
            }
        }
        // Retención: vencido el plazo, se vacía el contenido y se conserva la fila.
        DB::table('tenant_settings')->insert(['tenant_id' => IA_TENANT, 'parameter_code' => 'ia.retencion_bitacora_dias', 'value' => '30']);
        expect(app(AiUsageLog::class)->purge())->toBe(0);
        $this->travel(31)->days();
        expect(app(AiUsageLog::class)->purge())->toBe(1);
        $this->travelBack();
    });

    $list = iaCall($this->auditor, 'get', '/ai/usage-logs?filter[use_case]=ayuda')->assertOk();
    test()->assertMatchesOpenApi($list, 'get', '/ai/usage-logs', 200);
    expect($list->json('meta.total'))->toBe(1)->and($list->json('data.0.content_purged'))->toBeTrue()
        ->and($list->json('data.0'))->not->toHaveKey('prompt_enc');
    $one = iaCall($this->admin, 'get', '/ai/usage-logs/'.$id)->assertOk();
    test()->assertMatchesOpenApi($one, 'get', '/ai/usage-logs/{id}', 200);
    iaCall($this->employee, 'get', '/ai/usage-logs')->assertStatus(403);
    iaCall($this->stranger, 'get', '/ai/usage-logs/'.$id)->assertNotFound();
});

test('IAR-11 · valoración y tablero de calidad: preguntas sin respuesta y respuestas mal valoradas', function (): void {
    iaEnable($this->admin, ['empleado'])->assertOk();
    $answer = iaCall($this->employee, 'post', '/ai/help-assistant/ask', ['question' => '¿Cómo solicito vacaciones?'])->assertOk();
    iaCall($this->employee, 'post', '/ai/help-assistant/ask', ['question' => 'zzqx wwyy kkrr'])->assertOk();

    $id = (string) $answer->json('data.id');
    $fb = iaCall($this->employee, 'post', "/ai/help-assistant/answers/{$id}/feedback", ['rating' => 'down', 'comment' => 'No explica los días'])->assertOk();
    test()->assertMatchesOpenApi($fb, 'post', '/ai/help-assistant/answers/{id}/feedback', 200);
    iaCall($this->employee, 'post', "/ai/help-assistant/answers/{$id}/feedback", ['rating' => 'meh'])->assertStatus(422);
    iaCall($this->hr, 'post', "/ai/help-assistant/answers/{$id}/feedback", ['rating' => 'up'])->assertNotFound();
    iaCall($this->admin, 'post', "/ai/help-assistant/answers/{$id}/feedback", ['rating' => 'up'])->assertNotFound();

    $q = iaCall($this->admin, 'get', '/ai/help-assistant/quality')->assertOk();
    test()->assertMatchesOpenApi($q, 'get', '/ai/help-assistant/quality', 200);
    expect($q->json('data'))->toMatchArray(['questions' => 2, 'answered' => 1, 'unanswered' => 1, 'rated_down' => 1]);
    expect($q->json('data.unanswered_questions.0.query_text'))->toBe('zzqx wwyy kkrr');
    expect($q->json('data.badly_rated.0.comment'))->toBe('No explica los días')->and($q->json('data.badly_rated.0.cited_articles'))->not->toBeEmpty();
    iaCall($this->employee, 'get', '/ai/help-assistant/quality')->assertStatus(403);
    expect(iaCall($this->stranger, 'get', '/ai/help-assistant/quality')->json('data.questions'))->toBe(0);
});

test('Regla 13 · el asistente de ayuda no expone acciones: solo pregunta, valora y consulta disponibilidad', function (): void {
    $routes = collect(app('router')->getRoutes()->getRoutes())->map(fn ($r): string => implode('|', $r->methods()).' '.$r->uri())
        ->filter(fn (string $r): bool => str_contains($r, 'api/v1/ai/help-assistant'))->values()->all();
    expect($routes)->toEqualCanonicalizing([
        'GET|HEAD api/v1/ai/help-assistant/availability',
        'POST api/v1/ai/help-assistant/ask',
        'POST api/v1/ai/help-assistant/answers/{id}/feedback',
        'GET|HEAD api/v1/ai/help-assistant/quality',
    ]);
});
