<?php

namespace App\Shared\Integrations\Fake;

use App\Shared\Integrations\Logging\IntegrationCallRecorder;
use App\Shared\Integrations\Ports\PushPort;
use Throwable;

/**
 * Push falso para desarrollo y pruebas: guarda lo que se habría enviado. Un endpoint que contiene
 * «expired» responde como una suscripción caducada (el navegador la quitó).
 */
final class FakePushAdapter implements PushPort
{
    /** @var list<array{endpoint: string, payload: array<string, string>}> */
    public array $sent = [];

    private ?Throwable $nextException = null;

    public function __construct(private readonly ?IntegrationCallRecorder $recorder = null) {}

    public function failNext(Throwable $e): void
    {
        $this->nextException = $e;
    }

    public function send(array $subscription, array $payload): string
    {
        $start = hrtime(true);
        if ($this->nextException !== null) {
            $e = $this->nextException;
            $this->nextException = null;
            $this->recorder?->record('fake_push', 'send', (hrtime(true) - $start) / 1e6, 'failed', $e->getMessage(), ['tag' => $payload['tag']]);

            throw $e;
        }
        $gone = str_contains($subscription['endpoint'], 'expired');
        if (! $gone) {
            $this->sent[] = ['endpoint' => $subscription['endpoint'], 'payload' => $payload];
        }
        $this->recorder?->record('fake_push', 'send', (hrtime(true) - $start) / 1e6, 'success', null, ['tag' => $payload['tag'], 'gone' => $gone]);

        return $gone ? self::GONE : self::SENT;
    }

    public function publicKey(): ?string
    {
        $key = config('notifications.push.vapid_public_key');

        return is_string($key) && $key !== '' ? $key : null;
    }

    public function providerName(): string
    {
        return 'fake_push';
    }

    public function isFake(): bool
    {
        return true;
    }
}
