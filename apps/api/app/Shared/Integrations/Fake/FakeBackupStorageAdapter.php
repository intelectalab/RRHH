<?php

namespace App\Shared\Integrations\Fake;

use App\Shared\Integrations\Logging\IntegrationCallRecorder;
use App\Shared\Integrations\Ports\BackupStoragePort;
use Illuminate\Support\Str;
use Throwable;

/**
 * Copias ficticias para desarrollo y pruebas: no copia datos reales ni toca ninguna base. Devuelve una
 * clave, un tamaño y una suma de verificación deterministas, y una verificación que pasa salvo que la prueba
 * pida un fallo.
 */
final class FakeBackupStorageAdapter implements BackupStoragePort
{
    /** @var array<string, true> */
    private array $purged = [];

    private ?Throwable $nextException = null;

    private bool $failVerification = false;

    public function __construct(private readonly ?IntegrationCallRecorder $recorder = null) {}

    public function providerName(): string
    {
        return 'fake_backup_storage';
    }

    public function isFake(): bool
    {
        return true;
    }

    public function failNext(Throwable $e): void
    {
        $this->nextException = $e;
    }

    public function failNextVerification(): void
    {
        $this->failVerification = true;
    }

    /** @return list<string> */
    public function purgedKeys(): array
    {
        return array_keys($this->purged);
    }

    public function create(string $coverage, ?string $tenantId): array
    {
        $this->throwIfAsked('create');
        $key = 'fake-backups/'.$coverage.'/'.($tenantId ?? 'platform').'/'.Str::uuid().'.dump';
        $this->recorder?->record($this->providerName(), 'create', 0.0, 'success', null, ['coverage' => $coverage]);

        return ['storage_key' => $key, 'size_bytes' => 1048576 + (crc32($key) % 1048576), 'checksum' => hash('sha256', $key)];
    }

    public function restore(string $storageKey, string $checksum, string $target): array
    {
        $this->throwIfAsked('restore');
        $ok = ! $this->failVerification && hash_equals(hash('sha256', $storageKey), $checksum);
        $this->failVerification = false;
        $this->recorder?->record($this->providerName(), 'restore', 0.0, $ok ? 'success' : 'failed', $ok ? null : 'verification_failed', ['target' => $target]);

        return [
            'verified' => $ok,
            'environment' => $target === 'production' ? 'production' : 'sandbox-'.substr(hash('sha256', $storageKey), 0, 8),
            'checks' => [
                ['name' => 'checksum', 'ok' => $ok, 'detail' => $ok ? 'La suma de verificación coincide.' : 'La suma de verificación no coincide.'],
                ['name' => 'schema', 'ok' => $ok, 'detail' => 'Esquema restaurado y migraciones al día.'],
                ['name' => 'row_counts', 'ok' => $ok, 'detail' => 'Conteos por tabla iguales a los de la copia.'],
            ],
        ];
    }

    public function purge(string $storageKey): void
    {
        $this->purged[$storageKey] = true;
    }

    private function throwIfAsked(string $operation): void
    {
        if ($this->nextException !== null) {
            $e = $this->nextException;
            $this->nextException = null;
            $this->recorder?->record($this->providerName(), $operation, 0.0, 'failed', $e->getMessage());

            throw $e;
        }
    }
}
