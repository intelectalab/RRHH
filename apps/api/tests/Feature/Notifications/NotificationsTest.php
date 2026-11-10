<?php

/**
 * NOT-1 · NOT-2 · NOT-3 · NOT-6 · Motor de avisos, centro de notificaciones, correo y preferencias.
 */

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Identity\Infrastructure\Models\UserIdentity;
use App\Modules\Workflow\Application\ApprovalEngine;
use App\Shared\Integrations\Fake\FakeEmailAdapter;
use App\Shared\Integrations\IntegrationManager;
use App\Shared\Notifications\Notifier;
use App\Shared\Outbox\Models\OutboxEvent;
use App\Shared\Outbox\OutboxService;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(DatabaseTransactions::class);

const NOT_TENANT = 'aaaaaaaa-9090-4000-8000-00000000000a';

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requiere PostgreSQL.');
    }
    TenancyScope::clear();
    DB::table('tenants')->insertOrIgnore(['id' => NOT_TENANT, 'slug' => 'not-org', 'name' => 'Andina Corp', 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN']);
    DB::select('SELECT seed_tenant_defaults(?)', [NOT_TENANT]);

    $this->admin = notUser('admin_empresa', 'Ana Admin', 'ana@andina.pe');
    $this->boss = notUser('manager', 'Beto Jefe', 'beto@andina.pe');
    $this->requester = notUser('empleado', 'Rita Solicitante', 'rita@andina.pe');
    $this->noEmail = notUser('rrhh', 'Hugo Sin Correo', null);
    $this->fake = new FakeEmailAdapter;
    app(IntegrationManager::class)->setEmailAdapter($this->fake);
});

afterEach(fn () => TenancyScope::clear());

function notUser(string $role, string $name, ?string $email, string $locale = 'es'): User
{
    return TenancyScope::runAs(NOT_TENANT, function () use ($role, $name, $email, $locale): User {
        $u = User::factory()->create(['display_name' => $name, 'locale' => $locale]);
        $u->roleAssignments()->create(['role_id' => Role::query()->where('system_role_code', $role)->value('id')]);
        if ($email !== null) {
            $u->identities()->create(UserIdentity::attributesFor('email', $email) + ['is_primary' => true]);
        }

        return $u;
    });
}

function notCall(User $user, string $method, string $path, array $body = [])
{
    TenancyScope::clear();
    app('auth')->forgetGuards();

    return test()->actingAs($user, 'ayni')->withHeaders(['X-Tenant-Id' => $user->tenant_id, 'Idempotency-Key' => (string) Str::uuid()])->json($method, '/api/v1'.$path, $body);
}

/** Procesa el outbox de la organización como lo hace outbox:dispatch (consumo idempotente por evento). */
function notDispatch(?string $type = null): void
{
    $outbox = app(OutboxService::class);
    $events = TenancyScope::runAs(NOT_TENANT, fn () => OutboxEvent::query()->when($type, fn ($q) => $q->where('event_type', $type))
        ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))->orderBy('occurred_at')->get());
    foreach ($events as $event) {
        foreach ($outbox->getSubscribersFor($event->event_type) as $subscriber) {
            $outbox->getTracker()->executeIdempotently($event->tenant_id, $subscriber->consumerName(), $event->id, fn () => $subscriber->handle($event));
        }
    }
}

function notFlow(User $admin, User $approver): void
{
    $id = notCall($admin, 'POST', '/approval-flows', ['name' => 'Permisos', 'subject_type' => 'permission_request', 'steps' => ['levels' => [['mode' => 'sequential', 'approvers' => [['kind' => 'user', 'user_id' => $approver->id]]]]]])->json('data.id');
    notCall($admin, 'POST', "/approval-flows/{$id}/publish", [])->assertOk();
}

test('una solicitud avisa al aprobador en la aplicación y por correo, una sola vez aunque se reprocese', function (): void {
    notFlow($this->admin, $this->boss);
    $req = TenancyScope::runAs(NOT_TENANT, fn () => app(ApprovalEngine::class)->start('permission_request', (string) Str::uuid(), $this->requester->id));

    notDispatch();
    notDispatch(); // reprocesar no duplica

    $list = notCall($this->boss, 'GET', '/notifications');
    $list->assertOk()->assertJsonPath('meta.unread', 1)->assertJsonPath('data.0.category', 'approval.pending')
        ->assertJsonPath('data.0.title', 'Solicitud por aprobar')
        ->assertJsonPath('data.0.action_url', '/aprobaciones?id='.$req->id);
    expect($list->json('data.0.body'))->toContain('Rita Solicitante')->toContain('permiso');
    $this->assertMatchesOpenApi($list, 'get', '/notifications', 200);

    // Correo: solo el aviso y el enlace a la organización (RN-NOT-02), en el idioma del destinatario.
    expect($this->fake->getSent())->toHaveCount(1)
        ->and($this->fake->getSent()[0]['to'])->toBe('beto@andina.pe')
        ->and($this->fake->getSent()[0]['subject'])->toBe('Tienes una solicitud por aprobar')
        ->and($this->fake->getSent()[0]['body'])->toContain('http://not-org.localhost:3000/aprobaciones?id='.$req->id)->not->toContain('Rita');

    TenancyScope::runAs(NOT_TENANT, function (): void {
        $d = DB::table('notification_deliveries')->sole();
        expect($d->status)->toBe('sent')->and($d->provider)->toBe('fake_email')->and($d->attempts)->toBe(1)
            ->and($d->recipient_address_enc)->not->toContain('beto');
    });

    // Al decidir, el solicitante recibe el resultado.
    notCall($this->boss, 'POST', "/approval-requests/{$req->id}/reject", ['comment' => 'Cierre de mes'])->assertOk();
    notDispatch();
    $mine = notCall($this->requester, 'GET', '/notifications');
    expect($mine->json('data.0.category'))->toBe('approval.rejected')->and($mine->json('data.0.body'))->toContain('Cierre de mes');
    expect(collect($this->fake->getSent())->last()['body'])->not->toContain('Cierre de mes');
});

test('una entrega fallida guarda el error y se reintenta sin duplicar', function (): void {
    $this->fake->failNext(new RuntimeException('SMTP no disponible'));
    TenancyScope::runAs(NOT_TENANT, fn () => app(Notifier::class)->notify('task.assigned', [$this->boss->id], ['title' => 'Firmar contrato', 'due_on' => '2026-11-20', 'task_id' => (string) Str::uuid()]));

    $outbox = app(OutboxService::class);
    $event = TenancyScope::runAs(NOT_TENANT, fn () => OutboxEvent::query()->where('event_type', 'notification.delivery_requested.v1')->sole());
    $subscriber = $outbox->getSubscribersFor($event->event_type)[0];
    expect(fn () => $subscriber->handle($event))->toThrow(RuntimeException::class);
    TenancyScope::runAs(NOT_TENANT, fn () => expect(DB::table('notification_deliveries')->value('error'))->toBe('SMTP no disponible'));

    $subscriber->handle($event);
    $subscriber->handle($event);
    expect($this->fake->getSent())->toHaveCount(1);
    TenancyScope::runAs(NOT_TENANT, fn () => expect(DB::table('notification_deliveries')->first(['status', 'attempts']))->toEqual((object) ['status' => 'sent', 'attempts' => 2]));

    $log = notCall($this->admin, 'GET', '/notification-deliveries');
    $log->assertOk()->assertJsonPath('data.0.status', 'sent')->assertJsonPath('data.0.recipient', 'be••@andina.pe');
    $this->assertMatchesOpenApi($log, 'get', '/notification-deliveries', 200);
    notCall($this->requester, 'GET', '/notification-deliveries')->assertStatus(403);
});

test('sin correo el aviso queda en la aplicación (RN-NOT-07) y a un rol le llega a quienes lo tienen hoy', function (): void {
    $rrhh = TenancyScope::runAs(NOT_TENANT, fn () => Role::query()->where('system_role_code', 'rrhh')->value('id'));
    notCall($this->admin, 'POST', '/tasks', ['title' => 'Validar legajos', 'assignee_role_id' => $rrhh])->assertCreated();
    notDispatch();

    notCall($this->noEmail, 'GET', '/notifications')->assertJsonPath('meta.unread', 1)->assertJsonPath('data.0.title', 'Nueva tarea asignada');
    expect($this->fake->getSent())->toHaveCount(0);
});

test('marcar leídas una o todas persiste y baja el contador', function (): void {
    TenancyScope::runAs(NOT_TENANT, function (): void {
        foreach (['A', 'B', 'C'] as $t) {
            app(Notifier::class)->notify('task.assigned', [$this->boss->id], ['title' => "Tarea {$t}", 'due_on' => '2026-11-20', 'task_id' => (string) Str::uuid()]);
        }
    });
    $first = notCall($this->boss, 'GET', '/notifications')->json('data.0.id');

    $one = notCall($this->boss, 'POST', '/notifications/read', ['ids' => [$first]]);
    $one->assertOk()->assertJsonPath('data.unread', 2);
    $this->assertMatchesOpenApi($one, 'post', '/notifications/read', 200);

    notCall($this->boss, 'POST', '/notifications/read', ['all' => true])->assertJsonPath('data.unread', 0);
    expect(notCall($this->boss, 'GET', '/notifications?filter[unread]=1')->json('data'))->toBe([]);
    // Los de otro usuario no se tocan.
    notCall($this->requester, 'POST', '/notifications/read', ['ids' => [$first]])->assertJsonPath('data.updated', 0);
});

test('preferencias: apagar el correo de un tipo lo respeta; un aviso obligatorio no se apaga en la aplicación', function (): void {
    $prefs = notCall($this->boss, 'GET', '/notification-preferences');
    $prefs->assertOk();
    $this->assertMatchesOpenApi($prefs, 'get', '/notification-preferences', 200);
    $security = collect($prefs->json('data.categories'))->firstWhere('category', 'account.security');
    expect($security['mandatory'])->toBeTrue()->and($security['channels']['in_app'])->toBe(['enabled' => true, 'locked' => true]);

    $save = notCall($this->boss, 'PUT', '/notification-preferences', ['preferences' => [['category' => 'task.assigned', 'channel' => 'email', 'enabled' => false]]]);
    $save->assertOk();
    $this->assertMatchesOpenApi($save, 'put', '/notification-preferences', 200);

    $denied = notCall($this->boss, 'PUT', '/notification-preferences', ['preferences' => [['category' => 'account.security', 'channel' => 'in_app', 'enabled' => false]]]);
    $denied->assertStatus(422);
    $this->assertMatchesOpenApi($denied, 'put', '/notification-preferences', 422);

    TenancyScope::runAs(NOT_TENANT, fn () => app(Notifier::class)->notify('task.assigned', [$this->boss->id], ['title' => 'X', 'due_on' => '2026-11-20', 'task_id' => (string) Str::uuid()]));
    TenancyScope::runAs(NOT_TENANT, fn () => expect(DB::table('notification_deliveries')->count())->toBe(0)->and(DB::table('notifications')->count())->toBe(1));

    // La organización declara obligatorio un tipo: el usuario ya no puede apagarlo en la aplicación.
    TenancyScope::runAs(NOT_TENANT, fn () => DB::table('tenant_settings')->insert(['tenant_id' => NOT_TENANT, 'parameter_code' => 'notificaciones.obligatorias', 'value' => json_encode(['task.overdue'])]));
    notCall($this->boss, 'PUT', '/notification-preferences', ['preferences' => [['category' => 'task.overdue', 'channel' => 'in_app', 'enabled' => false]]])->assertStatus(422);
});

test('en el horario de silencio el correo no urgente se difiere hasta que termina (RN-NOT-03)', function (): void {
    TenancyScope::runAs(NOT_TENANT, fn () => DB::table('tenant_settings')->insert([
        ['tenant_id' => NOT_TENANT, 'parameter_code' => 'notificaciones.silencio_desde', 'value' => json_encode('20:00')],
        ['tenant_id' => NOT_TENANT, 'parameter_code' => 'notificaciones.silencio_hasta', 'value' => json_encode('07:00')],
    ]));
    $this->travelTo(Carbon::parse('2026-11-10 22:30', 'America/Lima'));

    TenancyScope::runAs(NOT_TENANT, fn () => app(Notifier::class)->notify('task.assigned', [$this->boss->id], ['title' => 'Revisar', 'due_on' => '2026-11-20', 'task_id' => (string) Str::uuid()]));
    $event = TenancyScope::runAs(NOT_TENANT, fn () => OutboxEvent::query()->where('event_type', 'notification.delivery_requested.v1')->sole());
    expect($event->next_attempt_at->toIso8601String())->toBe('2026-11-11T12:00:00+00:00'); // 07:00 en Lima

    notDispatch();
    expect($this->fake->getSent())->toHaveCount(0);
    // El aviso en la aplicación sí llega de inmediato.
    notCall($this->boss, 'GET', '/notifications')->assertJsonPath('meta.unread', 1);

    $this->travelTo(Carbon::parse('2026-11-11 07:05', 'America/Lima'));
    notDispatch();
    expect($this->fake->getSent())->toHaveCount(1);

    // Lo urgente no espera.
    $this->travelTo(Carbon::parse('2026-11-11 23:00', 'America/Lima'));
    TenancyScope::runAs(NOT_TENANT, fn () => app(Notifier::class)->notify('account.security', [$this->boss->id], ['event' => 'Se cambió tu clave.']));
    notDispatch();
    expect($this->fake->getSent())->toHaveCount(2);
});

test('plantillas por organización: es, en y pt, variables validadas y se aplican al enviar', function (): void {
    $list = notCall($this->admin, 'GET', '/notification-templates?filter[channel]=in_app&filter[locale]=en');
    $list->assertOk();
    $this->assertMatchesOpenApi($list, 'get', '/notification-templates', 200);
    $tpl = collect($list->json('data'))->firstWhere('key', 'task.assigned');
    expect($tpl['subject'])->toBe('New task assigned')->and($tpl['variables'])->toContain('title', 'due_on', 'name');

    $bad = notCall($this->admin, 'PATCH', '/notification-templates/'.$tpl['id'], ['body' => 'Hi {name}, salary {salary}']);
    $bad->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    $this->assertMatchesOpenApi($bad, 'patch', '/notification-templates/{id}', 422);

    $ok = notCall($this->admin, 'PATCH', '/notification-templates/'.$tpl['id'], ['subject' => 'Task for {name}', 'body' => '{title} is due {due_on}.']);
    $ok->assertOk()->assertJsonPath('data.customized', true);
    $this->assertMatchesOpenApi($ok, 'patch', '/notification-templates/{id}', 200);

    $english = notUser('manager', 'John Smith', null, 'en');
    TenancyScope::runAs(NOT_TENANT, fn () => app(Notifier::class)->notify('task.assigned', [$english->id], ['title' => 'Sign policy', 'due_on' => '2026-11-20', 'task_id' => (string) Str::uuid()]));
    notCall($english, 'GET', '/notifications')->assertJsonPath('data.0.title', 'Task for John Smith')->assertJsonPath('data.0.body', 'Sign policy is due November 20, 2026.');

    notCall($this->admin, 'POST', '/notification-templates', ['key' => 'task.assigned', 'channel' => 'in_app', 'locale' => 'en', 'body' => 'x'])->assertStatus(409);
    notCall($this->requester, 'GET', '/notification-templates')->assertStatus(403);
    TenancyScope::runAs(NOT_TENANT, fn () => expect(DB::table('audit_events')->where('action', 'notification_template.updated')->count())->toBe(1));
});
