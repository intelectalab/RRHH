<?php

/**
 * NOT-4 · Canal push: el usuario acepta registrando un dispositivo y rechaza quitándolo o apagando el
 * canal; el aviso no lleva datos y abre la pantalla del aviso; una suscripción caducada se da de baja.
 */

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Shared\Integrations\Fake\FakePushAdapter;
use App\Shared\Integrations\IntegrationManager;
use App\Shared\Notifications\Notifier;
use App\Shared\Outbox\DomainEventTypes;
use App\Shared\Outbox\Models\OutboxEvent;
use App\Shared\Outbox\OutboxService;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(DatabaseTransactions::class);

const PSH_TENANT = 'aaaaaaaa-4040-4000-8000-00000000004a';
const PSH_TENANT_B = 'bbbbbbbb-4040-4000-8000-00000000004b';

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requiere PostgreSQL.');
    }
    TenancyScope::clear();
    foreach ([[PSH_TENANT, 'psh-org'], [PSH_TENANT_B, 'psh-org-b']] as [$id, $slug]) {
        DB::table('tenants')->insertOrIgnore(['id' => $id, 'slug' => $slug, 'name' => 'Org '.$slug, 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN']);
        DB::select('SELECT seed_tenant_defaults(?)', [$id]);
    }
    $this->fake = new FakePushAdapter;
    app(IntegrationManager::class)->setPushAdapter($this->fake);
    $this->worker = pshUser(PSH_TENANT, 'Juan Quispe');
    $this->outsider = pshUser(PSH_TENANT_B, 'Otra Persona');
});

afterEach(fn () => TenancyScope::clear());

function pshUser(string $tenant, string $name): User
{
    return TenancyScope::runAs($tenant, function () use ($name): User {
        $u = User::factory()->create(['display_name' => $name, 'locale' => 'es']);
        $u->roleAssignments()->create(['role_id' => Role::query()->where('system_role_code', 'empleado')->value('id')]);

        return $u;
    });
}

function pshCall(User $user, string $method, string $path, array $body = [])
{
    TenancyScope::clear();
    app('auth')->forgetGuards();

    return test()->actingAs($user, 'ayni')->withHeaders(['X-Tenant-Id' => $user->tenant_id, 'Idempotency-Key' => (string) Str::uuid()])->json($method, '/api/v1'.$path, $body);
}

function pshDispatch(): void
{
    $outbox = app(OutboxService::class);
    $events = TenancyScope::runAs(PSH_TENANT, fn () => OutboxEvent::query()->where('event_type', DomainEventTypes::NOTIFICATION_DELIVERY_REQUESTED)->orderBy('occurred_at')->get());
    foreach ($events as $event) {
        foreach ($outbox->getSubscribersFor($event->event_type) as $subscriber) {
            try {
                $outbox->getTracker()->executeIdempotently($event->tenant_id, $subscriber->consumerName(), $event->id, fn () => $subscriber->handle($event));
            } catch (Throwable) {
            }
        }
    }
}

function pshSubscription(string $endpoint): array
{
    return ['subscription' => ['endpoint' => $endpoint, 'keys' => ['p256dh' => 'BPublicKeyDeEjemplo', 'auth' => 'secretoAuth']], 'label' => 'Chrome · Android'];
}

test('registrar un dispositivo acepta push: el aviso sale sin datos y abre la pantalla del aviso', function (): void {
    $config = pshCall($this->worker, 'GET', '/push/config');
    $config->assertOk();
    $this->assertMatchesOpenApi($config, 'get', '/push/config', 200);

    $created = pshCall($this->worker, 'POST', '/push-devices', pshSubscription('https://push.example.com/abc'));
    $created->assertCreated()->assertJsonPath('data.label', 'Chrome · Android');
    $this->assertMatchesOpenApi($created, 'post', '/push-devices', 201);
    // Volver a suscribir el mismo navegador no duplica el dispositivo.
    pshCall($this->worker, 'POST', '/push-devices', pshSubscription('https://push.example.com/abc'))->assertCreated();
    $list = pshCall($this->worker, 'GET', '/push-devices');
    expect($list->json('data'))->toHaveCount(1);
    $this->assertMatchesOpenApi($list, 'get', '/push-devices', 200);

    $prefs = pshCall($this->worker, 'GET', '/notification-preferences');
    $task = collect($prefs->json('data.categories'))->firstWhere('category', 'task.assigned');
    expect($task['channels']['push']['enabled'])->toBeTrue();

    TenancyScope::runAs(PSH_TENANT, fn () => app(Notifier::class)->notify('task.assigned', [$this->worker->id], ['title' => 'Firmar el contrato de Rosa', 'due_on' => '2026-12-01', 'task_id' => 'abc']));
    pshDispatch();

    expect($this->fake->sent)->toHaveCount(1);
    $payload = $this->fake->sent[0]['payload'];
    expect($payload['url'])->toBe('/tareas?id=abc')
        ->and($payload['title'].$payload['body'])->not->toContain('Rosa')->not->toContain('Firmar');
    $delivery = TenancyScope::runAs(PSH_TENANT, fn () => DB::table('notification_deliveries')->where('channel', 'push')->first());
    expect($delivery->status)->toBe('sent');

    // El token se guarda cifrado, nunca en claro.
    $stored = TenancyScope::runAs(PSH_TENANT, fn () => DB::table('push_devices')->value('token_enc'));
    expect((string) $stored)->not->toContain('push.example.com');
});

test('rechazar push: apagar el canal o quitar el dispositivo corta los avisos; quitarlo dos veces es 409', function (): void {
    $id = pshCall($this->worker, 'POST', '/push-devices', pshSubscription('https://push.example.com/xyz'))->json('data.id');
    pshCall($this->worker, 'PUT', '/notification-preferences', ['preferences' => [['category' => 'task.assigned', 'channel' => 'push', 'enabled' => false]]])->assertOk();
    TenancyScope::runAs(PSH_TENANT, fn () => app(Notifier::class)->notify('task.assigned', [$this->worker->id], ['title' => 'Uno', 'due_on' => null, 'task_id' => '1']));
    pshDispatch();
    expect($this->fake->sent)->toBe([]);

    $revoked = pshCall($this->worker, 'POST', "/push-devices/{$id}/revoke");
    $revoked->assertNoContent();
    pshCall($this->worker, 'POST', "/push-devices/{$id}/revoke")->assertStatus(409);
    // De otra organización el dispositivo no existe.
    pshCall($this->outsider, 'POST', "/push-devices/{$id}/revoke")->assertNotFound();
    $prefs = pshCall($this->worker, 'GET', '/notification-preferences');
    expect(collect($prefs->json('data.categories'))->firstWhere('category', 'approval.pending')['channels']['push']['enabled'])->toBeFalse();
});

test('una suscripción caducada se da de baja sola y la entrega queda suprimida', function (): void {
    pshCall($this->worker, 'POST', '/push-devices', pshSubscription('https://push.example.com/expired-1'))->assertCreated();
    TenancyScope::runAs(PSH_TENANT, fn () => app(Notifier::class)->notify('task.assigned', [$this->worker->id], ['title' => 'Dos', 'due_on' => null, 'task_id' => '2']));
    pshDispatch();

    $device = TenancyScope::runAs(PSH_TENANT, fn () => DB::table('push_devices')->first());
    $delivery = TenancyScope::runAs(PSH_TENANT, fn () => DB::table('notification_deliveries')->where('channel', 'push')->first());
    expect($device->revoked_at)->not->toBeNull()
        ->and($delivery->status)->toBe('suppressed')->and($delivery->error)->toBe('subscription_expired');
    expect(pshCall($this->worker, 'GET', '/push-devices')->json('data'))->toBe([]);
});

test('una suscripción sin https o sin claves es 422', function (): void {
    pshCall($this->worker, 'POST', '/push-devices', ['subscription' => ['endpoint' => 'http://inseguro.test/x', 'keys' => ['p256dh' => 'a', 'auth' => 'b']]])->assertStatus(422);
    pshCall($this->worker, 'POST', '/push-devices', ['subscription' => ['endpoint' => 'https://push.example.com/1']])->assertStatus(422);
});
