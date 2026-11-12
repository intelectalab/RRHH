<?php

/**
 * NOT-3 · Canal de correo: rebotes que marcan la dirección y avisan a RRHH, remitente propio verificado.
 * NOT-6 · RN-NOT-07: quien no tiene correo válido recibe el aviso por SMS/WhatsApp (título y enlace).
 */

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Identity\Infrastructure\Models\UserIdentity;
use App\Modules\Notifications\Infrastructure\Models\NotificationDelivery;
use App\Shared\Integrations\Email\SignedFeedback;
use App\Shared\Integrations\Fake\FakeEmailAdapter;
use App\Shared\Integrations\Fake\FakeMessagingAdapter;
use App\Shared\Integrations\IntegrationManager;
use App\Shared\Notifications\Notifier;
use App\Shared\Outbox\DomainEventTypes;
use App\Shared\Outbox\Models\OutboxEvent;
use App\Shared\Outbox\OutboxService;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(DatabaseTransactions::class);

const MAIL_TENANT = 'aaaaaaaa-9292-4000-8000-00000000000c';
const MAIL_SECRET = 'secreto-de-prueba-del-proveedor';

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requiere PostgreSQL.');
    }
    TenancyScope::clear();
    DB::table('tenants')->insertOrIgnore(['id' => MAIL_TENANT, 'slug' => 'mail-org', 'name' => 'Andina Correo', 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN']);
    DB::select('SELECT seed_tenant_defaults(?)', [MAIL_TENANT]);
    config(['integrations.email_webhook_secret' => MAIL_SECRET]);

    $this->email = new FakeEmailAdapter;
    $this->sms = new FakeMessagingAdapter;
    app(IntegrationManager::class)->setEmailAdapter($this->email);
    app(IntegrationManager::class)->setMessagingAdapter($this->sms);

    $this->admin = mailUser('admin_empresa', 'Ana Admin', 'ana@andina.pe');
    $this->hr = mailUser('rrhh', 'Rosa RRHH', 'rosa@andina.pe');
    $this->worker = mailUser('empleado', 'Juan Quispe', 'juan@andina.pe', '+51 987 654 321');
    $this->noEmail = mailUser('empleado', 'Pedro Sin Correo', null, '+51 912 345 678');
});

afterEach(fn () => TenancyScope::clear());

function mailUser(string $role, string $name, ?string $email, ?string $phone = null): User
{
    return TenancyScope::runAs(MAIL_TENANT, function () use ($role, $name, $email, $phone): User {
        DB::table('roles')->where('system_role_code', $role)->update(['mfa_required' => false]);
        $u = User::factory()->create(['display_name' => $name, 'locale' => 'es']);
        $u->roleAssignments()->create(['role_id' => Role::query()->where('system_role_code', $role)->value('id')]);
        if ($email !== null) {
            $u->identities()->create(UserIdentity::attributesFor('email', $email) + ['is_primary' => true]);
        }
        if ($phone !== null) {
            $u->identities()->create(UserIdentity::attributesFor('phone', $phone));
        }

        return $u;
    });
}

function mailCall(User $user, string $method, string $path, array $body = [])
{
    TenancyScope::clear();
    app('auth')->forgetGuards();

    return test()->actingAs($user, 'ayni')->withHeaders(['X-Tenant-Id' => $user->tenant_id, 'Idempotency-Key' => (string) Str::uuid()])->json($method, '/api/v1'.$path, $body);
}

/** El proveedor llama al webhook de la organización (sin sesión), con su firma. */
function mailWebhook(array $events, ?string $signature = null)
{
    TenancyScope::clear();
    app('auth')->forgetGuards();
    $payload = (string) json_encode(['events' => $events]);

    return test()->call('POST', '/api/v1/webhooks/email', [], [], [], [
        'HTTP_X_TENANT_ID' => MAIL_TENANT,
        'HTTP_X_GESTIA_SIGNATURE' => $signature ?? SignedFeedback::sign($payload),
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ], $payload);
}

function mailDispatch(): void
{
    $outbox = app(OutboxService::class);
    $events = TenancyScope::runAs(MAIL_TENANT, fn () => OutboxEvent::query()
        ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))->orderBy('occurred_at')->get());
    foreach ($events as $event) {
        foreach ($outbox->getSubscribersFor($event->event_type) as $subscriber) {
            try {
                $outbox->getTracker()->executeIdempotently($event->tenant_id, $subscriber->consumerName(), $event->id, fn () => $subscriber->handle($event));
            } catch (Throwable) {
                // Queda para el reintento del outbox.
            }
        }
    }
}

function mailNotify(User $to): void
{
    TenancyScope::runAs(MAIL_TENANT, fn () => app(Notifier::class)->notify('task.assigned', [$to->id], ['title' => 'Revisar contrato', 'due_on' => '2026-11-20', 'task_id' => (string) Str::uuid()]));
}

test('un rebote permanente marca la dirección, avisa a RRHH sin mostrarla y no se repite al reprocesarse', function (): void {
    mailNotify($this->worker);
    mailDispatch();
    $sent = $this->email->getSent();
    expect($sent)->toHaveCount(1)->and($sent[0]['to'])->toBe('juan@andina.pe');

    $r = mailWebhook([['message_id' => $sent[0]['id'], 'type' => 'bounce', 'reason' => '550 mailbox not found']]);
    $r->assertOk()->assertJsonPath('data.applied', 1);
    $this->assertMatchesOpenApi($r, 'post', '/webhooks/email', 200);

    TenancyScope::runAs(MAIL_TENANT, function (): void {
        $delivery = NotificationDelivery::query()->where('channel', 'email')->sole();
        expect($delivery->status)->toBe('bounced')->and($delivery->error)->toContain('550');
        $identity = DB::table('user_identities')->where('user_id', $this->worker->id)->where('kind', 'email')->sole();
        expect($identity->bounced_at)->not->toBeNull()->and($identity->bounce_reason)->toBe('bounce');
        $audit = DB::table('audit_events')->where('action', 'user_identity.email_bounced')->where('entity_id', $this->worker->id)->sole();
        expect(json_encode($audit))->not->toContain('juan@andina.pe');
    });

    // Reprocesar el mismo aviso no cambia nada.
    mailWebhook([['message_id' => $sent[0]['id'], 'type' => 'bounce']])->assertOk()->assertJsonPath('data.applied', 0);

    // RRHH recibe el aviso (sin la dirección); el propio afectado no.
    mailDispatch();
    $hrList = mailCall($this->hr, 'GET', '/notifications');
    $hrList->assertOk()->assertJsonPath('data.0.category', 'notifications.email_bounced');
    expect($hrList->json('data.0.body'))->toContain('Juan Quispe')->not->toContain('juan@andina.pe');
    expect(collect(mailCall($this->worker, 'GET', '/notifications')->json('data'))->pluck('category'))->not->toContain('notifications.email_bounced');
    expect(TenancyScope::runAs(MAIL_TENANT, fn () => DB::table('outbox_events')->where('event_type', DomainEventTypes::EMAIL_ADDRESS_BOUNCED)->count()))->toBe(1);
});

test('sin la firma del proveedor el webhook responde 401 y no toca nada', function (): void {
    mailNotify($this->worker);
    mailDispatch();
    $id = $this->email->getSent()[0]['id'];

    $r = mailWebhook([['message_id' => $id, 'type' => 'bounce']], 'firma-falsa');
    $r->assertStatus(401)->assertJsonPath('error.code', 'invalid_signature');
    $this->assertMatchesOpenApi($r, 'post', '/webhooks/email', 401);
    expect(TenancyScope::runAs(MAIL_TENANT, fn () => NotificationDelivery::query()->where('channel', 'email')->value('status')))->toBe('sent');
});

test('un rebote temporal solo se anota y la entrega confirmada pasa a entregada', function (): void {
    mailNotify($this->worker);
    mailNotify($this->admin);
    mailDispatch();
    [$a, $b] = $this->email->getSent();
    mailWebhook([
        ['message_id' => $a['id'], 'type' => 'soft_bounce', 'reason' => 'mailbox full'],
        ['message_id' => $b['id'], 'type' => 'delivered'],
    ])->assertOk()->assertJsonPath('data.applied', 2);

    TenancyScope::runAs(MAIL_TENANT, function () use ($a, $b): void {
        expect(NotificationDelivery::query()->where('provider_message_id', $a['id'])->value('status'))->toBe('sent')
            ->and(NotificationDelivery::query()->where('provider_message_id', $b['id'])->value('status'))->toBe('delivered')
            ->and(DB::table('user_identities')->whereNotNull('bounced_at')->count())->toBe(0);
    });
});

test('después del rebote los avisos salen por SMS con título y enlace (RN-NOT-07) y nunca al correo rebotado', function (): void {
    mailNotify($this->worker);
    mailDispatch();
    mailWebhook([['message_id' => $this->email->getSent()[0]['id'], 'type' => 'complaint']])->assertOk();

    mailNotify($this->worker);
    mailDispatch();
    expect($this->email->getSent())->toHaveCount(1);
    $sms = $this->sms->getMessages();
    expect($sms)->toHaveCount(1)
        ->and($sms[0]['phone'])->toBe('+51987654321')
        ->and($sms[0]['message'])->toContain('Andina Correo')->toContain('Nueva tarea asignada')->toContain('http://mail-org.localhost:3000/tareas')
        ->not->toContain('Revisar contrato');
    TenancyScope::runAs(MAIL_TENANT, fn () => expect(NotificationDelivery::query()->where('channel', 'sms')->sole()->purpose)->toBe('notification:task.assigned'));
});

test('quien nunca tuvo correo recibe el aviso por el canal elegido, respetando el silencio; con "none" solo en la aplicación', function (): void {
    TenancyScope::runAs(MAIL_TENANT, fn () => DB::table('tenant_settings')->insert([
        ['tenant_id' => MAIL_TENANT, 'parameter_code' => 'notificaciones.silencio_desde', 'value' => json_encode('20:00')],
        ['tenant_id' => MAIL_TENANT, 'parameter_code' => 'notificaciones.silencio_hasta', 'value' => json_encode('07:00')],
    ]));
    $this->travelTo(Carbon::parse('2026-11-10 22:30', 'America/Lima'));
    mailNotify($this->noEmail);
    mailDispatch();
    expect($this->sms->getMessages())->toHaveCount(0);
    $this->travelTo(Carbon::parse('2026-11-11 07:05', 'America/Lima'));
    mailDispatch();
    expect($this->sms->getMessages())->toHaveCount(1);

    TenancyScope::runAs(MAIL_TENANT, fn () => DB::table('tenant_settings')->insert(['tenant_id' => MAIL_TENANT, 'parameter_code' => 'notificaciones.canal_sin_correo', 'value' => json_encode('none')]));
    mailNotify($this->noEmail);
    mailDispatch();
    expect($this->sms->getMessages())->toHaveCount(1);
    mailCall($this->noEmail, 'GET', '/notifications')->assertJsonPath('meta.unread', 2);
});

test('un correo en cola a una dirección que rebota mientras espera queda suprimido', function (): void {
    mailNotify($this->worker);
    mailDispatch();
    $first = $this->email->getSent()[0]['id'];

    TenancyScope::runAs(MAIL_TENANT, fn () => DB::table('tenant_settings')->insert([
        ['tenant_id' => MAIL_TENANT, 'parameter_code' => 'notificaciones.silencio_desde', 'value' => json_encode('00:00')],
        ['tenant_id' => MAIL_TENANT, 'parameter_code' => 'notificaciones.silencio_hasta', 'value' => json_encode('23:59')],
    ]));
    mailNotify($this->worker); // queda en cola por el silencio
    mailWebhook([['message_id' => $first, 'type' => 'bounce']])->assertOk();
    $this->travel(1)->days();
    mailDispatch();

    expect($this->email->getSent())->toHaveCount(1);
    // (Los avisos del rebote a RRHH siguen esperando el silencio; no cuentan aquí.)
    $statuses = TenancyScope::runAs(MAIL_TENANT, fn () => NotificationDelivery::query()->where('channel', 'email')->pluck('status')->countBy()->all());
    expect($statuses['bounced'] ?? 0)->toBe(1)->and($statuses['suppressed'] ?? 0)->toBe(1);
});

test('RRHH reactiva la dirección corregida; repetirlo es una transición inválida; sin permiso es 403', function (): void {
    mailNotify($this->worker);
    mailDispatch();
    mailWebhook([['message_id' => $this->email->getSent()[0]['id'], 'type' => 'bounce']])->assertOk();
    $deliveryId = TenancyScope::runAs(MAIL_TENANT, fn () => NotificationDelivery::query()->where('channel', 'email')->value('id'));

    $denied = mailCall($this->worker, 'POST', "/notification-deliveries/{$deliveryId}/clear-bounce");
    $denied->assertForbidden();

    $r = mailCall($this->admin, 'POST', "/notification-deliveries/{$deliveryId}/clear-bounce");
    $r->assertOk()->assertJsonPath('data.address_active', true);
    $this->assertMatchesOpenApi($r, 'post', '/notification-deliveries/{id}/clear-bounce', 200);
    expect(TenancyScope::runAs(MAIL_TENANT, fn () => DB::table('user_identities')->whereNotNull('bounced_at')->count()))->toBe(0);

    $again = mailCall($this->admin, 'POST', "/notification-deliveries/{$deliveryId}/clear-bounce");
    $again->assertStatus(409)->assertJsonPath('error.code', 'invalid_transition');
    $this->assertMatchesOpenApi($again, 'post', '/notification-deliveries/{id}/clear-bounce', 409);

    mailNotify($this->worker);
    mailDispatch();
    expect($this->email->getSent())->toHaveCount(2);
});

test('el remitente propio se usa solo cuando el proveedor lo verifica', function (): void {
    $show = mailCall($this->admin, 'GET', '/message-channels/email');
    $show->assertOk()->assertJsonPath('data.mode', 'platform')->assertJsonPath('data.verification_status', 'not_required')
        ->assertJsonPath('data.webhook_url', 'http://mail-org.localhost:3000/api/v1/webhooks/email');
    $this->assertMatchesOpenApi($show, 'get', '/message-channels/email', 200);

    $platformVerify = mailCall($this->admin, 'POST', '/message-channels/email/verify');
    $platformVerify->assertStatus(409);
    $this->assertMatchesOpenApi($platformVerify, 'post', '/message-channels/email/verify', 409);

    $bad = mailCall($this->admin, 'PUT', '/message-channels/email', ['mode' => 'own', 'address' => 'no-es-correo']);
    $bad->assertStatus(422);
    $this->assertMatchesOpenApi($bad, 'put', '/message-channels/email', 422);

    $this->email->setSenderStatus('rrhh@andina.pe', 'pending');
    $put = mailCall($this->admin, 'PUT', '/message-channels/email', ['mode' => 'own', 'address' => 'RRHH@andina.pe']);
    $put->assertOk()->assertJsonPath('data.mode', 'own')->assertJsonPath('data.address', 'rrhh@andina.pe')
        ->assertJsonPath('data.verification_status', 'pending');
    $this->assertMatchesOpenApi($put, 'put', '/message-channels/email', 200);

    // Mientras está pendiente, los correos salen con el remitente de la plataforma.
    mailDispatch();
    mailNotify($this->worker);
    mailDispatch();
    expect(collect($this->email->getSent())->last()['from'])->toBeNull();

    $this->email->setSenderStatus('rrhh@andina.pe', 'verified');
    $verify = mailCall($this->admin, 'POST', '/message-channels/email/verify');
    $verify->assertOk();
    $this->assertMatchesOpenApi($verify, 'post', '/message-channels/email/verify', 200);
    mailDispatch();
    mailCall($this->admin, 'GET', '/message-channels/email')->assertJsonPath('data.verification_status', 'verified')->assertJsonPath('data.in_use', 'rrhh@andina.pe');

    mailNotify($this->worker);
    mailDispatch();
    expect(collect($this->email->getSent())->last()['from'])->toBe('rrhh@andina.pe');
    expect(TenancyScope::runAs(MAIL_TENANT, fn () => DB::table('audit_events')->where('action', 'message_channel.sender_changed')->count()))->toBe(1);
});

test('un remitente rechazado por el proveedor queda fallido y los correos siguen saliendo con el de la plataforma', function (): void {
    $this->email->setSenderStatus('rrhh@otro.pe', 'failed');
    mailCall($this->admin, 'PUT', '/message-channels/email', ['mode' => 'own', 'address' => 'rrhh@otro.pe'])->assertOk();
    mailDispatch();
    mailCall($this->admin, 'GET', '/message-channels/email')->assertJsonPath('data.verification_status', 'failed');

    mailNotify($this->worker);
    mailDispatch();
    expect(collect($this->email->getSent())->last()['from'])->toBeNull();

    // Volver al de la plataforma.
    mailCall($this->admin, 'PUT', '/message-channels/email', ['mode' => 'platform'])->assertOk()->assertJsonPath('data.mode', 'platform');
});

test('configurar el remitente exige su permiso', function (): void {
    $r = mailCall($this->worker, 'GET', '/message-channels/email');
    $r->assertForbidden();
    $this->assertMatchesOpenApi($r, 'get', '/message-channels/email', 403);
    mailCall($this->hr, 'PUT', '/message-channels/email', ['mode' => 'platform'])->assertForbidden();
});
