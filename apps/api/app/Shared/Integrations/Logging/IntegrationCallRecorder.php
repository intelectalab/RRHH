<?php

namespace App\Shared\Integrations\Logging;

use App\Shared\Tenancy\TenancyScope;
use Illuminate\Support\Facades\Log;

final class IntegrationCallRecorder
{
    /**
     * In-memory store of recent calls for observability and testing.
     *
     * @var array<int, array{provider: string, operation: string, duration_ms: float, status: string, error: ?string, metadata: array<string, mixed>, recorded_at: string}>
     */
    private static array $recordedCalls = [];

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        string $provider,
        string $operation,
        float $durationMs,
        string $status,
        ?string $error = null,
        array $metadata = [],
    ): void {
        $entry = [
            'provider' => $provider,
            'operation' => $operation,
            'duration_ms' => $durationMs,
            'status' => $status,
            'error' => $error,
            'metadata' => $metadata,
            'recorded_at' => now()->toIso8601String(),
        ];

        self::$recordedCalls[] = $entry;

        $logData = [
            'provider' => $provider,
            'operation' => $operation,
            'duration_ms' => $durationMs,
            'status' => $status,
            'tenant_id' => TenancyScope::current(),
        ];

        if ($error !== null) {
            $logData['error'] = $error;
        }

        if ($status === 'failed') {
            Log::warning('Outgoing integration call failed', $logData);
        } else {
            Log::info('Outgoing integration call completed', $logData);
        }
    }

    /**
     * @return array<int, array{provider: string, operation: string, duration_ms: float, status: string, error: ?string, metadata: array<string, mixed>, recorded_at: string}>
     */
    public function getRecentCalls(): array
    {
        return self::$recordedCalls;
    }

    public function clear(): void
    {
        self::$recordedCalls = [];
    }
}
