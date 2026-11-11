<?php

namespace App\Modules\Audit\Console;

use App\Modules\Audit\Application\AuditChainVerifier;
use App\Shared\Tenancy\Resolution\TenantDirectory;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Console\Command;

/**
 * AUD-5 · Verificación programada de la cadena de hash de la auditoría de cada organización.
 * El resultado queda en audit_integrity_checks (se ve en Configuración › Auditoría › Integridad) y,
 * si falla, sale el evento audit.integrity_failed por el outbox.
 */
final class VerifyAuditIntegrity extends Command
{
    protected $signature = 'audit:verify-integrity {--tenant= : Solo esta organización}';

    protected $description = 'Verifica la cadena de hash de la auditoría (entradas alteradas o faltantes)';

    public function handle(TenantDirectory $tenants, AuditChainVerifier $verifier): int
    {
        $only = $this->option('tenant');
        $ids = is_string($only) && $only !== '' ? [$only] : $tenants->operableTenantIds();
        $failed = 0;
        foreach ($ids as $tenantId) {
            $result = TenancyScope::runAs($tenantId, fn (): array => $verifier->verify());
            if (($result['status'] ?? 'ok') !== 'ok') {
                $failed++;
            }
        }
        $this->info(sprintf('Organizaciones verificadas: %d · con fallas: %d', count($ids), $failed));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
