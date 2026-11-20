<?php

namespace App\Shared\Integrations\Ports;

/**
 * SAS-12 · Almacenamiento de copias de seguridad y entorno aislado de restauración (proveedor de
 * infraestructura). La consola nunca restaura sobre producción por su cuenta (RN-SAS-09): pide al proveedor
 * restaurar en un entorno aislado, verificar y, solo con doble aprobación, promover.
 * En desarrollo y pruebas responde la implementación falsa.
 */
interface BackupStoragePort
{
    /**
     * Genera la copia de toda la plataforma ($tenantId null) o de una organización (su exportación completa).
     *
     * @return array{storage_key: string, size_bytes: int, checksum: string}
     */
    public function create(string $coverage, ?string $tenantId): array;

    /**
     * Restaura la copia en el destino («sandbox» o «production») y devuelve la evidencia de verificación.
     *
     * @return array{verified: bool, checks: list<array{name: string, ok: bool, detail: string}>, environment: string}
     */
    public function restore(string $storageKey, string $checksum, string $target): array;

    /**
     * Borra del almacenamiento una copia vencida por retención.
     */
    public function purge(string $storageKey): void;

    public function providerName(): string;

    public function isFake(): bool;
}
