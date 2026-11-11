<?php

/**
 * NOT-5 · Capa de mensajería SMS/WhatsApp: puerto + adaptador falso, salida por outbox,
 * idempotencia, plantillas aprobables y cambio de proveedor sin tocar a quien envía.
 */

use App\Modules\Identity\Domain\LoginDocument;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Identity\Infrastructure\Models\UserIdentity;
use App\Modules\Notifications\Application\Messaging\MessageService;
use App\Modules\Notifications\Application\TemplateService;
use App\Modules\Notifications\Infrastructure\Models\NotificationDelivery;
use App\Modules\Notifications\Infrastructure\Models\NotificationTemplate;
use App\Modules\Notifications\Infrastructure\Subscribers\DeliverMessage;
use App\Shared\Integrations\Fake\FakeMessagingAdapter;
use App\Shared\Integrations\IntegrationManager;
use App\Shared\Messaging\MessageSender;
use App\Shared\Outbox\DomainEventTypes;
use App\Shared\Outbox\Models\OutboxEvent;
use App\Shared\Outbox\OutboxService;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(DatabaseTransactions::class);

const MSG_TENANT = 'aaaaaaaa-9191-4000-8000-00000000000b';
const MSG_TENANT_B = 'bbbbbbbb-9191-4000-8000-00000000000b';

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requiere PostgreSQL.');
    }
    TenancyScope::clear();
    foreach ([[MSG_TENANT, 'msg-org'], [MSG_TENANT_B, 'msg-org-b']] as [$id, $slug]) {
        DB::table('tenants')->insertOrIgnore(['id' => $id, 'slug' => $slug, 'name' => 'Org '.$slug, 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN']);
        DB::select('SELECT seed_tenant_defaults(?)', [$id]);
    }
    $this->fake = new FakeMessagingAdapter;
    app(IntegrationManager::class)->setMessagingAdapter($this->fake);
    $this->admin = msgUser(MSG_TENANT, 'admin_empresa', 'Ana Admin');
    $this->worker = msgUser(MSG_TENANT, 'empleado', 'Juan Quispe');
    $this->outsider = msgUser(MSG_TENANT_B, 'admin_empresa', 'Otra Admin');
});

afterEach(fn () => TenancyScope::clear());

function msgUser(string $tenant, string $role, string $name): User
{
    return TenancyScope::runAs($tenant, function () use ($role, $name): User {
        DB::table('roles')->where('system_role_code', $role)->update(['mfa_required' => false]);
        $u = User::factory()->create(['display_name' => $name, 'locale' => 'es']);
        $u->roleAssignments()->create(['role_id' => Role::query()->where('system_role_code', $role)->value('id')]);

        return $u;
    });
}

function msgCall(?User $user, string $method, string $path, array $body = [], string $tenant = MSG_TENANT)
{
    TenancyScope::clear();
    app('auth')->forgetGuards();
    $req = $user === null ? test() : test()->actingAs($user, 'ayni');

    return $req->withHeaders(['X-Tenant-Id' => $user->tenant_id ?? $tenant, 'Idempotency-Key' => (string) Str::uuid()])->json($method, '/api/v1'.$path, $body);
}

/** Procesa el outbox como outbox:dispatch (consumo idempotente por evento). */
function msgDispatch(string $tenant = MSG_TENANT): void
{
    $outbox = app(OutboxService::class);
    $events = TenancyScope::runAs($tenant, fn () => OutboxEvent::query()->whereIn('event_type', [DomainEventTypes::MESSAGE_SEND_REQUESTED, DomainEventTypes::MESSAGE_TEMPLATE_APPROVAL_REQUESTED])->orderBy('occurred_at')->get());
    foreach ($events as $event) {
        foreach ($outbox->getSubscribersFor($event->event_type) as $subscriber) {
            try {
                $outbox->getTracker()->executeIdempotently($event->tenant_id, $subscriber->consumerName(), $event->id, fn () => $subscriber->handle($event));
            } catch (Throwable) {
                // El despacho real lo deja para reintento; la prueba revisa el estado de la entrega.
            }
        }
    }
}

function msgSend(string $channel = 'sms', string $key = 'k-1', string $to = '+51987654321'): string
{
    return TenancyScope::runAs(MSG_TENANT, fn () => DB::transaction(fn () => app(MessageSender::class)
        ->send($channel, $to, 'identity.login_code', ['code' => '482913', 'minutes' => 10], 'es', 'otp_login', $key)));
}

test('el código de ingreso con documento se envía por la capa de mensajería y queda registrado sin el código', function (): void {
    TenancyScope::runAs(MSG_TENANT, function (): void {
        $this->worker->identities()->create(UserIdentity::attributesFor('national_id', LoginDocument::identifier('dni', '45678912')));
        $this->worker->identities()->create(UserIdentity::attributesFor('phone', '+51 987 654 321'));
    });

    $r = msgCall(null, 'POST', '/auth/otp/request', ['document_type' => 'dni', 'document_number' => '45678912']);
    $r->assertSuccessful();
    $this->assertMatchesOpenApi($r, 'post', '/auth/otp/request', $r->status());

    $sent = $this->fake->getMessages();
    expect($sent)->toHaveCount(1)
        ->and($sent[0]['phone'])->toBe('+51987654321')
        ->and($sent[0]['channel'])->toBe('sms');
    preg_match('/\b(\d{6})\b/', $sent[0]['message'], $m);
    expect($m[1] ?? null)->not->toBeNull();

    $delivery = TenancyScope::runAs(MSG_TENANT, fn () => NotificationDelivery::query()->where('purpose', 'otp_login')->sole());
    expect($delivery->status)->toBe('sent')
        ->and($delivery->template_code)->toBe('identity.login_code')
        ->and($delivery->provider)->toBe('fake_messaging')
        ->and($delivery->provider_message_id)->toBe($sent[0]['id'])
        ->and($delivery->idempotency_key)->toStartWith('message_code:');
    // Ni la entrega ni el evento guardan el código ni el número en claro.
    $row = (array) DB::table('notification_deliveries')->where('id', $delivery->id)->first();
    $event = (string) json_encode(DB::table('outbox_events')->where('aggregate_id', $delivery->id)->value('payload'));
    expect(json_encode($row))->not->toContain($m[1])->not->toContain('987654321')
        ->and($event)->not->toContain($m[1]);
});

test('la misma clave de idempotencia devuelve la misma entrega y no envía dos veces', function (): void {
    $first = msgSend('sms', 'same-key');
    $second = msgSend('sms', 'same-key');
    expect($second)->toBe($first);

    msgDispatch();
    msgDispatch();
    expect($this->fake->getMessages())->toHaveCount(1)
        ->and(TenancyScope::runAs(MSG_TENANT, fn () => NotificationDelivery::query()->where('idempotency_key', 'same-key')->count()))->toBe(1);
});

test('un fallo del proveedor deja el envío pendiente para el reintento del outbox, que envía una sola vez', function (): void {
    $this->fake->failNext(new RuntimeException('proveedor caído'));
    $id = msgSend('sms', 'retry-key');
    $event = TenancyScope::runAs(MSG_TENANT, fn () => OutboxEvent::query()->where('aggregate_id', $id)->sole());
    $tracker = app(OutboxService::class)->getTracker();
    $subscriber = app(DeliverMessage::class);

    // El fallo se propaga para que el outbox reintente con espera creciente; nada quedó enviado.
    expect(fn () => $tracker->executeIdempotently(MSG_TENANT, $subscriber->consumerName(), $event->id, fn () => $subscriber->handle($event)))
        ->toThrow(RuntimeException::class, 'proveedor caído');
    expect($this->fake->getMessages())->toHaveCount(0)
        ->and(TenancyScope::runAs(MSG_TENANT, fn () => NotificationDelivery::query()->findOrFail($id)->status))->not->toBe('sent');

    // Reintento y reproceso del mismo evento: un solo mensaje.
    expect($tracker->executeIdempotently(MSG_TENANT, $subscriber->consumerName(), $event->id, fn () => $subscriber->handle($event)))->toBeTrue()
        ->and($tracker->executeIdempotently(MSG_TENANT, $subscriber->consumerName(), $event->id, fn () => $subscriber->handle($event)))->toBeFalse();
    TenancyScope::runAs(MSG_TENANT, fn () => $subscriber->handle($event));

    $delivery = TenancyScope::runAs(MSG_TENANT, fn () => NotificationDelivery::query()->findOrFail($id));
    expect($delivery->status)->toBe('sent')->and($this->fake->getMessages())->toHaveCount(1);
});

test('WhatsApp sin plantilla aprobada sale por SMS; con plantilla aprobada sale por WhatsApp', function (): void {
    $first = msgSend('whatsapp', 'wa-1');
    msgDispatch();
    $d = TenancyScope::runAs(MSG_TENANT, fn () => NotificationDelivery::query()->findOrFail($first));
    expect($d->channel)->toBe('sms')->and($d->fallback_from)->toBe('whatsapp')->and($this->fake->getMessages()[0]['channel'])->toBe('sms');

    // RRHH envía la plantilla a aprobación; el proveedor (falso) la aprueba.
    $tpl = msgCall($this->admin, 'GET', '/notification-templates?filter[channel]=whatsapp&filter[locale]=es')->json('data');
    $login = collect($tpl)->firstWhere('key', 'identity.login_code');
    expect($login['approval_status'])->toBe('draft');
    $req = msgCall($this->admin, 'POST', '/notification-templates/'.$login['id'].'/request-approval');
    $req->assertOk();
    $this->assertMatchesOpenApi($req, 'post', '/notification-templates/{id}/request-approval', 200);
    expect($req->json('data.approval_status'))->toBe('pending');
    msgDispatch();
    expect(msgCall($this->admin, 'GET', '/notification-templates/'.$login['id'])->json('data.approval_status'))->toBe('approved');

    $second = msgSend('whatsapp', 'wa-2');
    msgDispatch();
    $d2 = TenancyScope::runAs(MSG_TENANT, fn () => NotificationDelivery::query()->findOrFail($second));
    expect($d2->channel)->toBe('whatsapp')->and($d2->fallback_from)->toBeNull()
        ->and($this->fake->getMessages()[1]['channel'])->toBe('whatsapp')
        ->and($this->fake->getMessages()[1]['template'])->toStartWith('fake_tpl_');
});

test('una plantilla rechazada guarda el motivo; editar una aprobada la devuelve a borrador', function (): void {
    $tpl = collect(msgCall($this->admin, 'GET', '/notification-templates?filter[channel]=whatsapp&filter[locale]=es')->json('data'))->firstWhere('key', 'identity.activation_code');

    $this->fake->rejectNextTemplate('Contenido no permitido');
    msgCall($this->admin, 'POST', '/notification-templates/'.$tpl['id'].'/request-approval')->assertOk();
    msgDispatch();
    $after = msgCall($this->admin, 'GET', '/notification-templates/'.$tpl['id'])->json('data');
    expect($after['approval_status'])->toBe('rejected')->and($after['rejection_reason'])->toBe('Contenido no permitido');

    msgCall($this->admin, 'POST', '/notification-templates/'.$tpl['id'].'/request-approval')->assertOk();
    msgDispatch();
    expect(msgCall($this->admin, 'GET', '/notification-templates/'.$tpl['id'])->json('data.approval_status'))->toBe('approved');

    $edit = msgCall($this->admin, 'PATCH', '/notification-templates/'.$tpl['id'], ['body' => 'GestiaHR: activa tu cuenta con el código {code} antes de {hours} horas.']);
    $edit->assertOk();
    expect($edit->json('data.approval_status'))->toBe('draft');

    $audit = TenancyScope::runAs(MSG_TENANT, fn () => DB::table('audit_events')->where('entity_id', $tpl['id'])->pluck('action')->all());
    expect($audit)->toContain('notification_template.approval_requested', 'notification_template.approval_rejected', 'notification_template.approval_approved');
});

test('pedir aprobación es una transición: 409 si ya está pendiente y regla de negocio si no es WhatsApp', function (): void {
    $wa = collect(msgCall($this->admin, 'GET', '/notification-templates?filter[channel]=whatsapp&filter[locale]=es')->json('data'))->firstWhere('key', 'identity.login_code');
    msgCall($this->admin, 'POST', '/notification-templates/'.$wa['id'].'/request-approval')->assertOk();
    $again = msgCall($this->admin, 'POST', '/notification-templates/'.$wa['id'].'/request-approval');
    $again->assertStatus(409)->assertJsonPath('error.code', 'invalid_transition');
    $this->assertMatchesOpenApi($again, 'post', '/notification-templates/{id}/request-approval', 409);

    $sms = collect(msgCall($this->admin, 'GET', '/notification-templates?filter[channel]=sms&filter[locale]=es')->json('data'))->firstWhere('key', 'identity.login_code');
    expect($sms['approval_status'])->toBe('not_required');
    msgCall($this->admin, 'POST', '/notification-templates/'.$sms['id'].'/request-approval')->assertStatus(422);
});

test('pedir aprobación exige permiso y no alcanza plantillas de otra organización', function (): void {
    $wa = collect(msgCall($this->admin, 'GET', '/notification-templates?filter[channel]=whatsapp&filter[locale]=es')->json('data'))->firstWhere('key', 'identity.login_code');

    $denied = msgCall($this->worker, 'POST', '/notification-templates/'.$wa['id'].'/request-approval');
    $denied->assertStatus(403);
    $this->assertMatchesOpenApi($denied, 'post', '/notification-templates/{id}/request-approval', 403);

    msgCall($this->outsider, 'POST', '/notification-templates/'.$wa['id'].'/request-approval')->assertStatus(404);
});

test('cambiar de proveedor no modifica a quien envía: la organización elige el adaptador en message_channels', function (): void {
    $acme = new class extends FakeMessagingAdapter
    {
        public function providerName(): string
        {
            return 'acme_sms';
        }
    };
    config(['integrations.messaging_providers' => ['acme_sms' => $acme::class]]);
    app()->instance($acme::class, $acme);
    TenancyScope::runAs(MSG_TENANT, fn () => DB::table('message_channels')->insert(['id' => (string) Str::uuid(), 'tenant_id' => MSG_TENANT, 'channel' => 'sms', 'provider' => 'acme_sms', 'status' => 'active']));

    $id = msgSend('sms', 'provider-key');
    msgDispatch();

    expect($this->fake->getMessages())->toHaveCount(0)
        ->and($acme->getMessages())->toHaveCount(1)
        ->and(TenancyScope::runAs(MSG_TENANT, fn () => NotificationDelivery::query()->findOrFail($id)->provider))->toBe('acme_sms');
});

test('el registro de entregas muestra el número enmascarado, el propósito y el canal de respaldo', function (): void {
    msgSend('whatsapp', 'log-key');
    msgDispatch();

    $log = msgCall($this->admin, 'GET', '/notification-deliveries?filter[channel]=sms');
    $log->assertOk();
    $this->assertMatchesOpenApi($log, 'get', '/notification-deliveries', 200);
    $row = $log->json('data.0');
    expect($row['recipient'])->toEndWith('321')->not->toContain('987654')
        ->and($row['purpose'])->toBe('otp_login')
        ->and($row['fallback_from'])->toBe('whatsapp');
});

test('la capa rechaza canales, plantillas y números inválidos', function (): void {
    expect(fn () => msgSend('telegram', 'x1'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => msgSend('sms', 'x2', '987654321'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => TenancyScope::runAs(MSG_TENANT, fn () => DB::transaction(fn () => app(MessageSender::class)->send('sms', '+51987654321', 'approval.pending', [], 'es', 'x', 'x3'))))->toThrow(InvalidArgumentException::class);
});

test('un mensaje entrante se normaliza por el adaptador y queda como evento cifrado', function (): void {
    $inbound = $this->fake->parseInbound(['from' => '+51987654321', 'body' => 'SALDO', 'channel' => 'whatsapp', 'id' => 'wamid.1']);
    expect($inbound)->not->toBeNull()->and($this->fake->parseInbound(['nada' => 1]))->toBeNull();

    TenancyScope::runAs(MSG_TENANT, fn () => app(MessageService::class)->receive($inbound, 'fake_messaging'));
    $payload = TenancyScope::runAs(MSG_TENANT, fn () => OutboxEvent::query()->where('event_type', DomainEventTypes::MESSAGE_RECEIVED)->sole()->payload);
    expect($payload['provider_message_id'])->toBe('wamid.1')
        ->and(json_encode($payload))->not->toContain('SALDO')->not->toContain('987654321');
});

test('las plantillas de mensajes directos existen en los tres idiomas para SMS y WhatsApp', function (): void {
    TenancyScope::runAs(MSG_TENANT, fn () => app(TemplateService::class)->ensureDefaults());
    $rows = TenancyScope::runAs(MSG_TENANT, fn () => NotificationTemplate::query()->whereIn('code', ['identity.login_code', 'identity.activation_code'])->get());
    expect($rows)->toHaveCount(12)
        ->and($rows->where('channel', 'whatsapp')->pluck('approval_status')->unique()->values()->all())->toBe(['draft'])
        ->and($rows->firstWhere(fn ($t) => $t->locale === 'en' && $t->channel === 'sms')->body)->toContain('{code}');
});
