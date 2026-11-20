<?php

/**
 * AYU-4 · Artículos de la organización: audiencia (RN-AYU-03), versión, responsable y revisión (RN-AYU-04).
 * AYU-5 · Búsqueda sin tildes ni mayúsculas en ambos contenidos, valoración y uso de la ayuda.
 * AYU-6 · Derivar la consulta a RRHH como solicitud; solo el administrador escribe al soporte (RN-AYU-06).
 * AYU-7 · Recorridos por rol y novedades de los módulos contratados.
 */

use App\Modules\HelpCenter\Application\OrgArticles;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Organization\Infrastructure\Models\JobProfile;
use App\Modules\Organization\Infrastructure\Models\LegalEntity;
use App\Modules\Organization\Infrastructure\Models\OrgUnit;
use App\Modules\Organization\Infrastructure\Models\Site;
use App\Modules\Platform\Application\Help\ProductHelpArticles;
use App\Modules\Platform\Application\Help\ProductHelpInsights;
use App\Shared\Permissions\DataScope;
use App\Shared\Permissions\ModuleGate;
use App\Shared\Permissions\PermissionResolver;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\IdentityFixtures;

uses(DatabaseTransactions::class);

const HO_TENANT = 'aaaaaaaa-a4a4-4000-8000-00000000000a';
const HO_OTHER = 'bbbbbbbb-a4a4-4000-8000-00000000000b';

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requiere PostgreSQL.');
    }
    TenancyScope::clear();
    foreach ([HO_TENANT => 'ho-org', HO_OTHER => 'ho-otra'] as $id => $slug) {
        DB::table('tenants')->insertOrIgnore(['id' => $id, 'slug' => $slug, 'name' => 'Grupo '.$slug, 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN']);
        DB::select('SELECT seed_tenant_defaults(?)', [$id]);
        TenancyScope::runAs($id, fn () => IdentityFixtures::enableAllModules());
    }
    app(ProductHelpArticles::class)->syncBundled();
    $this->admin = hoUser(HO_TENANT, 'admin_empresa');
    $this->hr = hoUser(HO_TENANT, 'rrhh');
    $this->manager = hoUser(HO_TENANT, 'manager');
    $this->stranger = hoUser(HO_OTHER, 'admin_empresa');
    TenancyScope::runAs(HO_TENANT, function (): void {
        $this->entity = LegalEntity::factory()->create(['legal_name' => 'Comercial Andina S.A.C.', 'tax_id' => '20999999931'])->id;
        DB::table('legal_entity_regimes')->where('legal_entity_id', $this->entity)->update(['valid_from' => '2020-01-01']);
        $this->otherEntity = LegalEntity::factory()->create(['legal_name' => 'Transportes Sur S.A.C.', 'tax_id' => '20999999932'])->id;
        $this->profile = JobProfile::factory()->create(['name' => 'Analista'])->id;
        $this->unit = OrgUnit::factory()->create(['legal_entity_id' => $this->entity])->id;
        $this->site = Site::factory()->create(['legal_entity_id' => $this->entity])->id;
    });
    $options = hoCall($this->admin, 'get', '/employment-options')->assertOk();
    $hire = hoCall($this->admin, 'post', '/employments', [
        'person' => ['first_names' => 'Rosa', 'paternal_surname' => 'Quispe', 'document_type' => 'dni', 'document_number' => (string) random_int(10000000, 89999999), 'birth_date' => '1990-05-04', 'personal_email' => 'rosa@correo.pe'],
        'legal_entity_id' => $this->entity, 'hire_date' => '2025-03-10', 'job_profile_id' => $this->profile,
        'org_unit_id' => $this->unit, 'site_id' => $this->site, 'contract_type_id' => collect($options->json('data.contract_types'))->firstWhere('code', 'indeterminado')['id'],
    ])->assertCreated();
    $this->employee = hoUser(HO_TENANT, 'empleado');
    TenancyScope::runAs(HO_TENANT, fn () => DB::table('users')->where('id', $this->employee->id)->update(['person_id' => (string) $hire->json('data.person_id')]));
    $this->employee->refresh();
});

afterEach(fn () => TenancyScope::clear());

function hoUser(string $tenant, string $role): User
{
    return TenancyScope::runAs($tenant, function () use ($role): User {
        $u = User::factory()->create(['locale' => 'es']);
        $u->roleAssignments()->create(['role_id' => Role::query()->where('system_role_code', $role)->value('id')]);

        return $u;
    });
}

/**
 * @param  array<string, mixed>  $body
 * @param  array<string, string>  $headers
 */
function hoCall(User $user, string $method, string $path, array $body = [], array $headers = []): TestResponse
{
    TenancyScope::clear();
    app('auth')->forgetGuards();
    app(PermissionResolver::class)->forget();
    app(ModuleGate::class)->forget();
    app(DataScope::class)->forget();

    return test()->actingAs($user, 'ayni')->withHeaders(['X-Tenant-Id' => $user->tenant_id, 'Idempotency-Key' => (string) Str::uuid(), 'Accept' => 'application/json'] + $headers)
        ->json(strtoupper($method), '/api/v1'.$path, $body);
}

/** @param array<string, mixed> $extra */
function hoPublished(object $ctx, string $title, string $body, array $extra = []): string
{
    $created = hoCall($ctx->hr, 'post', '/help/org-articles', ['title' => $title, 'body' => $body, 'owner_user_id' => $ctx->hr->id] + $extra)->assertCreated();
    $id = (string) $created->json('data.id');
    hoCall($ctx->hr, 'post', "/help/org-articles/{$id}/publish")->assertOk();

    return $id;
}

test('AYU-4 · RRHH publica una política versionada con responsable; el borrador no cambia lo publicado', function (): void {
    $created = hoCall($this->hr, 'post', '/help/org-articles', [
        'title' => 'Reglamento interno de trabajo', 'body' => 'Horario de oficina de 9 a 18.', 'owner_user_id' => $this->hr->id, 'review_on' => now()->addMonths(6)->toDateString(),
    ])->assertCreated();
    test()->assertMatchesOpenApi($created, 'post', '/help/org-articles', 201);
    expect($created->json('data.status'))->toBe('draft')->and($created->json('data.version'))->toBe(0)->and($created->json('data.owner_name'))->not->toBeNull();
    $id = (string) $created->json('data.id');
    expect(hoCall($this->employee, 'get', '/help/articles?filter[kind]=policy')->json('data'))->toBe([]);

    $published = hoCall($this->hr, 'post', "/help/org-articles/{$id}/publish")->assertOk();
    test()->assertMatchesOpenApi($published, 'post', '/help/org-articles/{id}/publish', 200);
    expect($published->json('data.version'))->toBe(1)->and($published->json('data.status'))->toBe('published');

    // Editar no cambia lo que se ve hasta volver a publicar; publicar crea la versión 2 y conserva la 1.
    $edited = hoCall($this->hr, 'patch', "/help/org-articles/{$id}", ['body' => 'Horario de oficina de 8 a 17.'])->assertOk();
    test()->assertMatchesOpenApi($edited, 'patch', '/help/org-articles/{id}', 200);
    expect($edited->json('data.has_unpublished_changes'))->toBeTrue();
    $seen = hoCall($this->employee, 'get', "/help/articles/{$id}")->assertOk();
    expect($seen->json('data.body'))->toBe('Horario de oficina de 9 a 18.')->and($seen->json('data.source'))->toBe('organization');
    hoCall($this->hr, 'post', "/help/org-articles/{$id}/publish")->assertOk();
    $show = hoCall($this->hr, 'get', "/help/org-articles/{$id}")->assertOk();
    test()->assertMatchesOpenApi($show, 'get', '/help/org-articles/{id}', 200);
    expect($show->json('data.versions'))->toHaveCount(2)->and(hoCall($this->employee, 'get', "/help/articles/{$id}")->json('data.body'))->toBe('Horario de oficina de 8 a 17.');

    // El estado no se edita por PATCH (regla 3) y archivar dos veces es una transición inválida.
    hoCall($this->hr, 'patch', "/help/org-articles/{$id}", ['status' => 'archived'])->assertOk();
    expect(hoCall($this->hr, 'get', "/help/org-articles/{$id}")->json('data.status'))->toBe('published');
    hoCall($this->hr, 'post', "/help/org-articles/{$id}/archive")->assertOk();
    $again = hoCall($this->hr, 'post', "/help/org-articles/{$id}/archive")->assertStatus(409);
    expect($again->json('error.code'))->toBe('invalid_transition');
    hoCall($this->employee, 'get', "/help/articles/{$id}")->assertNotFound();
    expect(DB::table('audit_events')->where('entity_type', 'kb_article')->where('entity_id', $id)->count())->toBeGreaterThanOrEqual(5);
});

test('AYU-4 · RN-AYU-03 · un artículo propio solo lo ve la audiencia elegida (rol, empresa, sede)', function (): void {
    $forManagers = hoPublished($this, 'Política para jefes', 'Solo jefes.', ['audience' => ['roles' => ['manager']]]);
    $forEntity = hoPublished($this, 'Política de Comercial Andina', 'Solo esta empresa.', ['audience' => ['legal_entity_ids' => [$this->entity]]]);
    $forOther = hoPublished($this, 'Política de Transportes Sur', 'Otra empresa.', ['audience' => ['legal_entity_ids' => [$this->otherEntity]]]);
    $forAll = hoPublished($this, 'Código de ética', 'Para todos.');

    $employeeSees = collect(hoCall($this->employee, 'get', '/help/articles?filter[kind]=policy')->assertOk()->json('data'))->pluck('id')->all();
    expect($employeeSees)->toContain($forEntity, $forAll)->not->toContain($forManagers, $forOther);
    $managerSees = collect(hoCall($this->manager, 'get', '/help/articles?filter[kind]=policy')->json('data'))->pluck('id')->all();
    expect($managerSees)->toContain($forManagers, $forAll)->not->toContain($forEntity);
    hoCall($this->employee, 'get', "/help/articles/{$forManagers}")->assertNotFound();
    hoCall($this->hr, 'post', '/help/org-articles', ['title' => 'X', 'body' => 'Texto', 'owner_user_id' => $this->hr->id, 'audience' => ['roles' => ['no_existe']]])->assertStatus(422);
});

test('AYU-4 · RN-AYU-04 · al acercarse la revisión se marca «por revisar», sigue visible y avisa al responsable una vez', function (): void {
    $id = hoPublished($this, 'Política de vacaciones', 'Se piden con 15 días de anticipación.', ['review_on' => now()->addDays(3)->toDateString()]);
    $marked = TenancyScope::runAs(HO_TENANT, fn (): int => app(OrgArticles::class)->markReviewDue(now()->toDateString()));
    expect($marked)->toBe(1);
    expect(TenancyScope::runAs(HO_TENANT, fn (): int => app(OrgArticles::class)->markReviewDue(now()->toDateString())))->toBe(0);
    expect(hoCall($this->hr, 'get', "/help/org-articles/{$id}")->json('data.status'))->toBe('in_review');
    expect(hoCall($this->employee, 'get', "/help/articles/{$id}")->assertOk()->json('data.review_due'))->toBeTrue();
    expect(TenancyScope::runAs(HO_TENANT, fn () => DB::table('notifications')->where('recipient_user_id', $this->hr->id)->where('category', OrgArticles::NOTIFY_REVIEW)->count()))->toBe(1);
    hoCall($this->hr, 'patch', "/help/org-articles/{$id}", ['review_on' => now()->addYear()->toDateString()])->assertOk();
    expect(hoCall($this->hr, 'post', "/help/org-articles/{$id}/publish")->json('data.status'))->toBe('published');
});

test('AYU-5 · la búsqueda tolera tildes, mayúsculas y plurales en la ayuda del producto y de la organización', function (): void {
    $id = hoPublished($this, 'Política de préstamos al personal', 'Los préstamos se descuentan en cuotas.');
    $search = hoCall($this->employee, 'get', '/help/search?q=PRESTAMO')->assertOk();
    test()->assertMatchesOpenApi($search, 'get', '/help/search', 200);
    expect(collect($search->json('data'))->pluck('id')->all())->toContain($id);
    $product = hoCall($this->employee, 'get', '/help/search?q=VACACIÓN')->assertOk();
    expect($product->json('data'))->not->toBe([]);
    hoCall($this->employee, 'get', '/help/search?q=zzqqxx')->assertOk();
    expect(TenancyScope::runAs(HO_TENANT, fn () => DB::table('help_search_queries')->where('query_text', 'zzqqxx')->value('role_code')))->toBe('empleado');
});

test('AYU-5 · «¿te sirvió?» guarda una valoración por persona y el responsable y la consola la ven', function (): void {
    $org = hoPublished($this, 'Política de teletrabajo', 'Dos días por semana.');
    $product = (string) hoCall($this->employee, 'get', '/help/articles?filter[kind]=guide')->json('data.0.id');
    $vote = hoCall($this->employee, 'post', "/help/articles/{$org}/feedback", ['is_helpful' => true])->assertOk();
    test()->assertMatchesOpenApi($vote, 'post', '/help/articles/{id}/feedback', 200);
    hoCall($this->employee, 'post', "/help/articles/{$org}/feedback", ['is_helpful' => false])->assertOk();
    hoCall($this->manager, 'post', "/help/articles/{$org}/feedback", ['is_helpful' => false])->assertOk();
    hoCall($this->employee, 'post', "/help/articles/{$product}/feedback", ['is_helpful' => false, 'screen_key' => 'portal.home'])->assertOk();
    $shown = hoCall($this->hr, 'get', "/help/org-articles/{$org}")->json('data');
    expect($shown['helpful_count'])->toBe(0)->and($shown['not_helpful_count'])->toBe(2);

    hoCall($this->employee, 'get', '/help/search?q=palabrainexistente')->assertOk();
    $insights = hoCall($this->admin, 'get', '/help/insights')->assertOk();
    test()->assertMatchesOpenApi($insights, 'get', '/help/insights', 200);
    expect(collect($insights->json('data.searches_without_result'))->pluck('query_text')->all())->toContain('palabrainexistente')
        ->and(collect($insights->json('data.feedback'))->pluck('article_id')->all())->toContain($org, $product);
    hoCall($this->employee, 'get', '/help/insights')->assertForbidden();
    hoCall($this->employee, 'post', '/help/org-articles', ['title' => 'X', 'body' => 'Y', 'owner_user_id' => $this->hr->id])->assertForbidden();
});

test('AYU-6 · la derivación crea una solicitud a RRHH con la pregunta y los artículos consultados y sigue su estado', function (): void {
    $org = hoPublished($this, 'Política de uniformes', 'Se entregan dos al año.');
    $made = hoCall($this->employee, 'post', '/help/escalations', [
        'question' => '¿Cuántos uniformes me corresponden si entré a mitad de año?', 'screen_key' => 'portal.home', 'consulted_articles' => [$org, 'ajeno'],
    ])->assertCreated();
    test()->assertMatchesOpenApi($made, 'post', '/help/escalations', 201);
    expect($made->json('data.status'))->toBe('open')->and($made->json('data.request_number'))->toStartWith('SOL-')
        ->and(collect($made->json('data.consulted_articles'))->pluck('id')->all())->toBe([$org]);
    $ticket = TenancyScope::runAs(HO_TENANT, fn () => DB::table('tickets')->where('id', $made->json('data.request_id'))->first());
    expect($ticket->channel)->toBe('help_center')->and($ticket->description)->toContain('Política de uniformes');

    TenancyScope::runAs(HO_TENANT, fn () => DB::table('tickets')->where('id', $ticket->id)->update(['status' => 'resolved']));
    $mine = hoCall($this->employee, 'get', '/help/escalations')->assertOk();
    test()->assertMatchesOpenApi($mine, 'get', '/help/escalations', 200);
    expect($mine->json('data.0.status'))->toBe('answered');
    expect(hoCall($this->manager, 'get', '/help/escalations')->json('data'))->toBe([]);
    // Sin relación laboral no se deriva a RRHH (auditor externo, contador de un estudio).
    hoCall($this->manager, 'post', '/help/escalations', ['question' => 'No tengo relación laboral'])->assertStatus(422);
});

test('AYU-6 · solo el administrador escribe al soporte de GestiaHR y la consulta llega a la consola', function (): void {
    hoCall($this->hr, 'post', '/help/escalations', ['question' => 'Quiero hablar con soporte', 'target' => 'platform_support'])->assertForbidden();
    $made = hoCall($this->admin, 'post', '/help/escalations', ['question' => 'La importación de colaboradores no reconoce mi archivo', 'target' => 'platform_support'])->assertCreated();
    expect($made->json('data.target'))->toBe('platform_support')->and($made->json('data.status'))->toBe('open');
    TenancyScope::clear();
    $ticket = DB::table('support_tickets')->where('tenant_id', HO_TENANT)->first();
    expect($ticket)->not->toBeNull()->and($ticket->subject)->toContain('importación');
    $rows = app(ProductHelpInsights::class)->escalations();
    expect(collect($rows)->pluck('tenant_id')->all())->toContain(HO_TENANT);
});

test('AYU-7 · cada rol de sistema tiene su recorrido; se salta y se vuelve a abrir; novedades solo de módulos contratados', function (): void {
    foreach (['admin_empresa', 'rrhh', 'payroll', 'recruiter', 'manager', 'empleado', 'auditor', 'sst', 'investigador_denuncias', 'contador'] as $role) {
        $tours = hoCall(hoUser(HO_TENANT, $role), 'get', '/help/tours')->assertOk();
        expect(collect($tours->json('data'))->pluck('slug')->all())->toBe(["recorrido.{$role}"]);
    }
    $tours = hoCall($this->employee, 'get', '/help/tours')->assertOk();
    test()->assertMatchesOpenApi($tours, 'get', '/help/tours', 200);
    expect($tours->json('data.0.status'))->toBe('pending');
    $done = hoCall($this->employee, 'post', '/help/tours/recorrido.empleado/complete', ['status' => 'skipped'])->assertOk();
    test()->assertMatchesOpenApi($done, 'post', '/help/tours/{tour_key}/complete', 200);
    expect(hoCall($this->employee, 'get', '/help/tours')->json('data.0.status'))->toBe('skipped');
    hoCall($this->employee, 'post', '/help/tours/recorrido.empleado/complete')->assertOk();
    expect(hoCall($this->employee, 'get', '/help/tours')->json('data.0.status'))->toBe('completed');
    hoCall($this->employee, 'post', '/help/tours/recorrido.admin_empresa/complete')->assertNotFound();

    $news = hoCall($this->employee, 'get', '/help/whats-new')->assertOk();
    test()->assertMatchesOpenApi($news, 'get', '/help/whats-new', 200);
    expect(collect($news->json('data'))->pluck('slug')->all())->toContain('novedades.1-0.time_off');
    TenancyScope::runAs(HO_TENANT, fn () => DB::table('tenant_modules')->where('module_code', 'time_off')->update(['status' => 'inactive']));
    expect(collect(hoCall($this->employee, 'get', '/help/whats-new')->json('data'))->pluck('slug')->all())->not->toContain('novedades.1-0.time_off');
});

test('aislamiento · otra organización no ve, valora ni edita los artículos propios', function (): void {
    $id = hoPublished($this, 'Política interna', 'Solo de esta organización.');
    hoCall($this->stranger, 'get', "/help/org-articles/{$id}")->assertNotFound();
    hoCall($this->stranger, 'patch', "/help/org-articles/{$id}", ['title' => 'Cambio'])->assertNotFound();
    hoCall($this->stranger, 'get', "/help/articles/{$id}")->assertNotFound();
    hoCall($this->stranger, 'post', "/help/articles/{$id}/feedback", ['is_helpful' => true])->assertNotFound();
    expect(collect(hoCall($this->stranger, 'get', '/help/search?q=interna')->json('data'))->pluck('id')->all())->not->toContain($id);
});
