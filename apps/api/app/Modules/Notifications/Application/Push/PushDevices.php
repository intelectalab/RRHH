<?php

namespace App\Modules\Notifications\Application\Push;

use App\Shared\Audit\AuditLogger;
use App\Shared\Exceptions\AyniException;
use App\Shared\Integrations\IntegrationManager;
use App\Shared\Integrations\Ports\PushPort;
use App\Shared\Security\FieldCipher;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Support\Facades\DB;

/**
 * NOT-4 · Dispositivos push del usuario. Registrar un dispositivo es aceptar push; quitarlo (o que el
 * navegador lo dé por caducado) es la baja. La suscripción viaja cifrada y se busca por su huella.
 */
final class PushDevices
{
    public function __construct(
        private readonly IntegrationManager $integrations,
        private readonly AuditLogger $audit,
    ) {}

    public function publicKey(): ?string
    {
        return $this->integrations->push()->publicKey();
    }

    /**
     * @return list<array{id: string, platform: string, label: string|null, last_seen_at: string|null, created_at: string}>
     */
    public function mine(string $userId): array
    {
        return array_values(DB::table('push_devices')->where('user_id', $userId)->whereNull('revoked_at')->orderByDesc('created_at')
            ->get(['id', 'platform', 'label', 'last_seen_at', 'created_at'])
            ->map(fn ($d) => [
                'id' => (string) $d->id, 'platform' => (string) $d->platform, 'label' => $d->label === null ? null : (string) $d->label,
                'last_seen_at' => $d->last_seen_at === null ? null : (string) $d->last_seen_at, 'created_at' => (string) $d->created_at,
            ])->all());
    }

    /**
     * @param  array{endpoint: string, keys: array{p256dh: string, auth: string}}  $subscription
     */
    public function register(string $userId, array $subscription, ?string $label): string
    {
        $hash = self::fingerprint($subscription['endpoint']);
        $enc = FieldCipher::encrypt((string) json_encode($subscription));

        return DB::transaction(function () use ($userId, $hash, $enc, $label): string {
            $existing = DB::table('push_devices')->where('token_hash', $hash)->first(['id', 'user_id', 'revoked_at']);
            if ($existing !== null) {
                // El mismo navegador vuelve a suscribirse (o lo usa otra persona): queda de quien lo registra ahora.
                DB::table('push_devices')->where('id', $existing->id)->update([
                    'user_id' => $userId, 'token_enc' => $enc, 'label' => $label, 'revoked_at' => null, 'last_seen_at' => now(), 'updated_at' => now(),
                ]);
                $id = (string) $existing->id;
            } else {
                $id = (string) DB::table('push_devices')->insertGetId([
                    'tenant_id' => TenancyScope::requireTenant(), 'user_id' => $userId, 'platform' => 'web', 'token_enc' => $enc,
                    'token_hash' => $hash, 'label' => $label, 'last_seen_at' => now(),
                ]);
            }
            $this->audit->record('push_device.registered', 'push_device', $id, null, ['label' => $label]);

            return $id;
        });
    }

    public function revoke(string $userId, string $id): void
    {
        $device = DB::table('push_devices')->where('id', $id)->where('user_id', $userId)->first(['id', 'revoked_at']);
        if ($device === null) {
            throw new AyniException('not_found', 404);
        }
        if ($device->revoked_at !== null) {
            throw new AyniException('invalid_transition', 409);
        }
        DB::table('push_devices')->where('id', $id)->update(['revoked_at' => now(), 'updated_at' => now()]);
        $this->audit->record('push_device.revoked', 'push_device', $id, ['revoked_at' => null], ['revoked_at' => now()->toIso8601String()]);
    }

    /**
     * @return list<string>
     */
    public function activeIds(string $userId): array
    {
        return array_values(DB::table('push_devices')->where('user_id', $userId)->whereNull('revoked_at')->pluck('id')->map(fn ($id) => (string) $id)->all());
    }

    public function hasActive(string $userId): bool
    {
        return DB::table('push_devices')->where('user_id', $userId)->whereNull('revoked_at')->exists();
    }

    /**
     * Envía el aviso a un dispositivo. Devuelve el estado de la entrega: sent, suppressed (el
     * dispositivo ya no existe o la suscripción caducó; se da de baja sola).
     *
     * @param  array{title: string, body: string, url: string, tag: string}  $payload
     * @return array{status: string, provider: string, error: string|null}
     */
    public function deliver(string $deviceId, array $payload): array
    {
        $port = $this->integrations->push();
        $device = DB::table('push_devices')->where('id', $deviceId)->first(['id', 'token_enc', 'revoked_at']);
        if ($device === null || $device->revoked_at !== null) {
            return ['status' => 'suppressed', 'provider' => $port->providerName(), 'error' => 'device_revoked'];
        }
        /** @var array{endpoint: string, keys: array{p256dh: string, auth: string}} $subscription */
        $subscription = (array) json_decode(FieldCipher::decrypt((string) $device->token_enc), true);
        $result = $port->send($subscription, $payload);
        if ($result === PushPort::GONE) {
            DB::table('push_devices')->where('id', $deviceId)->update(['revoked_at' => now(), 'updated_at' => now()]);

            return ['status' => 'suppressed', 'provider' => $port->providerName(), 'error' => 'subscription_expired'];
        }
        DB::table('push_devices')->where('id', $deviceId)->update(['last_seen_at' => now()]);

        return ['status' => 'sent', 'provider' => $port->providerName(), 'error' => null];
    }

    public static function fingerprint(string $endpoint): string
    {
        return hash_hmac('sha256', 'push|'.$endpoint, (string) config('app.key'));
    }
}
